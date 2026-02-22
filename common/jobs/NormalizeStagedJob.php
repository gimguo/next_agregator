<?php

namespace common\jobs;

use common\enums\ProductFamily;
use common\models\Brand;
use common\services\BrandResolverService;
use common\services\ImportStagingService;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Фаза 3: Нормализация данных в PostgreSQL staging.
 *
 * Читает пачки из staging_raw_offers со статусом 'pending',
 * применяет AI-рецепт (или базовые правила), записывает
 * normalized_data и меняет статус на 'normalized'.
 *
 * Cursor-based итерация через `WHERE id > :lastId` — стабильно и быстро.
 *
 * После завершения ставит в очередь PersistStagedJob (фаза 4).
 */
class NormalizeStagedJob extends BaseObject implements JobInterface
{
    public string $sessionId = '';
    public string $supplierCode = '';
    public int $supplierId = 0;

    /** @var array AI-рецепт (может быть передан напрямую) */
    public array $recipe = [];

    // Обратная совместимость
    public string $taskId = '';

    public function init(): void
    {
        parent::init();
        if (!empty($this->taskId) && empty($this->sessionId)) {
            $this->sessionId = $this->taskId;
        }
    }

    public function execute($queue): void
    {
        Yii::info("NormalizeStagedJob: старт sessionId={$this->sessionId}", 'import');

        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');
        $staging->setStatus($this->sessionId, 'normalizing');

        $startTime = microtime(true);

        // Рецепт может быть передан напрямую или загружен из session stats
        $recipe = $this->recipe;
        if (empty($recipe)) {
            $session = $staging->getSession($this->sessionId);
            $stats = json_decode($session['stats'] ?? '{}', true) ?: [];
            $recipe = $stats['recipe'] ?? [];
        }
        $hasRecipe = !empty($recipe);

        // Подготавливаем маппинги
        $brandMapping = $this->buildBrandMapping($recipe);
        $categoryMapping = $this->buildCategoryMapping($recipe);
        $nameRules = $recipe['name_rules'] ?? [];
        $nameTemplate = $recipe['name_template'] ?? '{brand} {model}';
        $productTypeRules = $recipe['product_type_rules'] ?? [];

        $normalized = 0;
        $errors = 0;
        $updateBatch = [];
        $batchFlushSize = 100;

        // Cursor-based итерация по pending записям
        foreach ($staging->iteratePending($this->sessionId, 500) as $rowId => $row) {
            try {
                $data = is_string($row['raw_data']) ? json_decode($row['raw_data'], true) : $row['raw_data'];
                $normalizedData = $this->normalizeItem(
                    $data, $brandMapping, $categoryMapping,
                    $nameRules, $nameTemplate, $productTypeRules
                );

                $updateBatch[] = [$rowId, $normalizedData];
                $normalized++;

                // Flush batch
                if (count($updateBatch) >= $batchFlushSize) {
                    $staging->markNormalizedBatch($updateBatch);
                    $updateBatch = [];
                }

                if ($normalized % 5000 === 0) {
                    Yii::info("NormalizeStagedJob: прогресс normalized={$normalized}", 'import');
                }
            } catch (\Throwable $e) {
                $errors++;
                $staging->markError($rowId, $e->getMessage());
                if ($errors <= 20) {
                    Yii::warning("NormalizeStagedJob: ошибка row={$rowId}: {$e->getMessage()}", 'import');
                }
            }
        }

        // Остаток
        if (!empty($updateBatch)) {
            $staging->markNormalizedBatch($updateBatch);
        }

        $duration = round(microtime(true) - $startTime, 1);
        $rate = $normalized > 0 ? round($normalized / max($duration, 0.1)) : 0;

        $staging->updateStats($this->sessionId, [
            'normalized_items'       => $normalized,
            'normalize_duration_sec' => $duration,
            'normalize_rate'         => $rate,
            'normalize_errors'       => $errors,
            'has_recipe'             => $hasRecipe,
        ]);
        $staging->setStatus($this->sessionId, 'normalized');

        Yii::info(
            "NormalizeStagedJob: завершён — normalized={$normalized} errors={$errors} " .
            "recipe=" . ($hasRecipe ? 'yes' : 'no') . " rate={$rate}/s time={$duration}s",
            'import'
        );

        // Фаза 4: запись в PostgreSQL (боевые таблицы)
        Yii::$app->queue->push(new PersistStagedJob([
            'sessionId'    => $this->sessionId,
            'supplierCode' => $this->supplierCode,
            'supplierId'   => $this->supplierId,
        ]));
        Yii::info("NormalizeStagedJob: поставлен PersistStagedJob в очередь", 'import');
    }

    /**
     * Нормализовать один товар, используя рецепт.
     */
    public function normalizeItem(
        array $data,
        array $brandMapping,
        array $categoryMapping,
        array $nameRules,
        string $nameTemplate,
        array $productTypeRules,
    ): array {
        $brand = $data['manufacturer'] ?? $data['brand'] ?? '';
        $model = $data['model'] ?? $data['name'] ?? '';
        $category = $data['category_path'] ?? '';

        // 1. Нормализация бренда через BrandResolverService (Sprint 22)
        /** @var BrandResolverService $brandResolver */
        $brandResolver = Yii::$app->get('brandResolver');
        $resolvedBrand = $brandResolver->resolve($brand);
        
        // Сохраняем эталонный brand_id и каноническое название
        $canonicalBrand = $resolvedBrand ? $resolvedBrand->name : $this->normalizeBrand($brand, $brandMapping);
        $brandId = $resolvedBrand ? $resolvedBrand->id : null;

        // 2. Маппинг категории
        $categoryMapped = $this->normalizeCategory($category, $categoryMapping);

        // 3. Нормализация имени
        $canonicalName = $this->normalizeName($model, $canonicalBrand, $nameRules, $nameTemplate);

        // 4. Тип продукта (через ProductFamily enum)
        $productType = $this->detectProductType($canonicalName, $category, $productTypeRules);

        return [
            '_canonical_name'  => $canonicalName,
            '_brand_canonical' => $canonicalBrand,
            '_brand_id'        => $brandId, // Эталонный brand_id (Sprint 22)
            '_category_mapped' => $categoryMapped,
            '_product_type'    => $productType,
            '_product_family'  => ProductFamily::detect($canonicalName . ' ' . $category)->value,
            '_normalized'      => true,
        ];
    }

    /**
     * Нормализация бренда по маппингу.
     */
    protected function normalizeBrand(string $raw, array $mapping): string
    {
        if (empty($raw)) return '';

        $key = mb_strtolower(trim($raw), 'UTF-8');
        if (isset($mapping[$key])) {
            return $mapping[$key];
        }

        $normalized = mb_convert_case(trim($raw), MB_CASE_TITLE, 'UTF-8');

        $fixes = [
            'ОРМАТЭК' => 'Орматек',
            'Орматэк' => 'Орматек',
            'ASCONA' => 'Askona',
            'АСКОНА' => 'Askona',
        ];

        return $fixes[$raw] ?? $normalized;
    }

    /**
     * Маппинг категории.
     */
    protected function normalizeCategory(string $raw, array $mapping): string
    {
        if (empty($raw)) return '';

        $key = mb_strtolower(trim($raw), 'UTF-8');
        if (isset($mapping[$key])) {
            return $mapping[$key];
        }

        return trim($raw);
    }

    /**
     * Нормализация названия.
     */
    protected function normalizeName(string $model, string $brand, array $rules, string $template): string
    {
        $name = trim($model);

        if (!empty($brand) && ($rules['remove_brand_prefix'] ?? true)) {
            $brandLower = mb_strtolower($brand, 'UTF-8');
            $nameLower = mb_strtolower($name, 'UTF-8');
            if (str_starts_with($nameLower, $brandLower)) {
                $name = trim(mb_substr($name, mb_strlen($brand)));
            }
        }

        if ($rules['trim_whitespace'] ?? true) {
            $name = preg_replace('/\s+/u', ' ', $name);
        }

        if (empty($name)) {
            $name = $model;
        }

        $canonical = str_replace(
            ['{brand}', '{model}'],
            [$brand, $name],
            $template,
        );

        return trim($canonical);
    }

    /**
     * Определение типа товара.
     */
    protected function detectProductType(string $name, string $category, array $rules): ?string
    {
        $text = mb_strtolower($name . ' ' . $category, 'UTF-8');

        foreach ($rules as $rule) {
            $pattern = mb_strtolower($rule['pattern'] ?? '', 'UTF-8');
            $family = $rule['family'] ?? $rule['type'] ?? null;
            if ($pattern && $family && str_contains($text, $pattern)) {
                return $family;
            }
        }

        // Через ProductFamily enum
        $detected = ProductFamily::detect($text);
        return $detected !== ProductFamily::UNKNOWN ? $detected->value : null;
    }

    /**
     * Построить маппинг брендов из рецепта.
     */
    public function buildBrandMapping(?array $recipe): array
    {
        $mapping = [];
        if (!$recipe || empty($recipe['brand_mapping'])) return $mapping;

        foreach ($recipe['brand_mapping'] as $raw => $info) {
            $key = mb_strtolower(trim($raw), 'UTF-8');
            $canonical = is_array($info) ? ($info['canonical'] ?? $raw) : $info;
            $action = is_array($info) ? ($info['action'] ?? 'alias') : 'alias';

            if ($action !== 'skip') {
                $mapping[$key] = $canonical;
            }
        }

        return $mapping;
    }

    /**
     * Построить маппинг категорий из рецепта.
     */
    public function buildCategoryMapping(?array $recipe): array
    {
        $mapping = [];
        if (!$recipe || empty($recipe['category_mapping'])) return $mapping;

        foreach ($recipe['category_mapping'] as $raw => $info) {
            $key = mb_strtolower(trim($raw), 'UTF-8');
            $targetName = is_array($info) ? ($info['target_name'] ?? $raw) : $info;
            $mapping[$key] = $targetName;
        }

        return $mapping;
    }
}
