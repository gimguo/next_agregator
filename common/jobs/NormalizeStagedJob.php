<?php

namespace common\jobs;

use common\services\ImportStagingService;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Фаза 3: Нормализация данных в Redis staging.
 *
 * Применяет AI-рецепт (или базовые правила) ко всем товарам:
 * - Маппинг брендов (грязное → каноническое)
 * - Маппинг категорий
 * - Нормализация названий
 * - Определение типа товара
 *
 * Работает целиком в Redis — без SQL-запросов.
 * После завершения ставит в очередь PersistStagedJob (фаза 4).
 */
class NormalizeStagedJob extends BaseObject implements JobInterface
{
    public string $taskId;
    public string $supplierCode;

    public function execute($queue): void
    {
        Yii::info("NormalizeStagedJob: старт taskId={$this->taskId}", 'import');

        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');
        $staging->setStatus($this->taskId, 'normalizing');

        $startTime = microtime(true);
        $recipe = $staging->getRecipe($this->taskId);
        $hasRecipe = !empty($recipe);

        // Подготавливаем маппинги из рецепта
        $brandMapping = $this->buildBrandMapping($recipe);
        $categoryMapping = $this->buildCategoryMapping($recipe);
        $nameRules = $recipe['name_rules'] ?? [];
        $nameTemplate = $recipe['name_template'] ?? '{brand} {model}';
        $productTypeRules = $recipe['product_type_rules'] ?? [];

        $normalized = 0;
        $errors = 0;

        // Проходим по всем товарам в Redis (HSCAN)
        foreach ($staging->iterateProducts($this->taskId, 300) as $sku => $data) {
            try {
                $data = $this->normalizeItem($data, $brandMapping, $categoryMapping, $nameRules, $nameTemplate, $productTypeRules);
                $data['_normalized'] = true;

                // Записываем обратно в Redis
                $staging->stageRaw($this->taskId, $sku, $data);
                $normalized++;

                if ($normalized % 5000 === 0) {
                    Yii::info("NormalizeStagedJob: прогресс normalized={$normalized}", 'import');
                }
            } catch (\Throwable $e) {
                $errors++;
                if ($errors <= 20) {
                    Yii::warning("NormalizeStagedJob: ошибка SKU={$sku}: {$e->getMessage()}", 'import');
                }
            }
        }

        $duration = round(microtime(true) - $startTime, 1);

        $staging->updateMeta($this->taskId, [
            'status' => 'normalized',
            'normalized_at' => date('Y-m-d H:i:s'),
            'normalize_duration_sec' => $duration,
            'normalized_count' => $normalized,
            'normalize_errors' => $errors,
            'has_recipe' => $hasRecipe,
        ]);

        Yii::info(
            "NormalizeStagedJob: завершён — normalized={$normalized} errors={$errors} " .
            "recipe=" . ($hasRecipe ? 'yes' : 'no') . " time={$duration}s",
            'import'
        );

        // Фаза 4: запись в PostgreSQL
        Yii::$app->queue->push(new PersistStagedJob([
            'taskId' => $this->taskId,
            'supplierCode' => $this->supplierCode,
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

        // 1. Нормализация бренда
        $canonicalBrand = $this->normalizeBrand($brand, $brandMapping);
        $data['_brand_canonical'] = $canonicalBrand;

        // 2. Маппинг категории
        $categoryMapped = $this->normalizeCategory($category, $categoryMapping);
        $data['_category_mapped'] = $categoryMapped;

        // 3. Нормализация имени
        $canonicalName = $this->normalizeName($model, $canonicalBrand, $nameRules, $nameTemplate);
        $data['_canonical_name'] = $canonicalName;

        // 4. Тип продукта
        $productType = $this->detectProductType($canonicalName, $category, $productTypeRules);
        $data['_product_type'] = $productType;

        return $data;
    }

    /**
     * Нормализация бренда по маппингу.
     */
    protected function normalizeBrand(string $raw, array $mapping): string
    {
        if (empty($raw)) return '';

        // Точное совпадение
        $key = mb_strtolower(trim($raw), 'UTF-8');
        if (isset($mapping[$key])) {
            return $mapping[$key];
        }

        // Базовая нормализация: capitalize, trim
        $normalized = mb_convert_case(trim($raw), MB_CASE_TITLE, 'UTF-8');

        // Известные паттерны
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

        // Возвращаем как есть, если маппинга нет
        return trim($raw);
    }

    /**
     * Нормализация названия.
     */
    protected function normalizeName(string $model, string $brand, array $rules, string $template): string
    {
        $name = trim($model);

        // Убираем бренд из начала названия (если дублируется)
        if (!empty($brand) && ($rules['remove_brand_prefix'] ?? true)) {
            $brandLower = mb_strtolower($brand, 'UTF-8');
            $nameLower = mb_strtolower($name, 'UTF-8');
            if (str_starts_with($nameLower, $brandLower)) {
                $name = trim(mb_substr($name, mb_strlen($brand)));
            }
        }

        // Убираем лишние пробелы
        if ($rules['trim_whitespace'] ?? true) {
            $name = preg_replace('/\s+/u', ' ', $name);
        }

        if (empty($name)) {
            $name = $model;
        }

        // Применяем шаблон
        $canonical = str_replace(
            ['{brand}', '{model}'],
            [$brand, $name],
            $template,
        );

        return trim($canonical);
    }

    /**
     * Определение типа товара по названию/категории.
     */
    protected function detectProductType(string $name, string $category, array $rules): ?string
    {
        $text = mb_strtolower($name . ' ' . $category, 'UTF-8');

        // Сначала по AI-правилам
        foreach ($rules as $rule) {
            $pattern = mb_strtolower($rule['pattern'] ?? '', 'UTF-8');
            if ($pattern && str_contains($text, $pattern)) {
                return $rule['type'];
            }
        }

        // Базовые правила
        $defaultRules = [
            'матрас' => 'mattress',
            'подушк' => 'pillow',
            'одеяло' => 'blanket',
            'кроват' => 'bed',
            'наматрасник' => 'protector',
            'основани' => 'base',
            'топпер' => 'topper',
            'чехол' => 'cover',
            'простын' => 'sheet',
            'покрывал' => 'bedspread',
        ];

        foreach ($defaultRules as $keyword => $type) {
            if (str_contains($text, $keyword)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Построить маппинг брендов из рецепта.
     * Ключи — lowercase, значения — канонические.
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
     * Ключи — lowercase, значения — целевое имя.
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
