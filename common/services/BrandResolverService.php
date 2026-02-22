<?php

namespace common\services;

use common\models\Brand;
use common\models\BrandAlias;
use yii\base\Component;
use Yii;

/**
 * Sprint 22: Brand Resolver Service.
 *
 * Движок склейки брендов с поддержкой AI Entity Resolution.
 *
 * Логика разрешения:
 *   1. Точное совпадение (ILIKE) в brands.name
 *   2. Поиск в brand_aliases.alias
 *   3. AI Fallback: запрос к ИИ для разрешения неоднозначности
 */
class BrandResolverService extends Component
{
    /** @var AIService */
    protected $aiService;

    public function init(): void
    {
        parent::init();
        $this->aiService = Yii::$app->get('aiService');
    }

    /**
     * Разрешить сырое название бренда в эталонный Brand.
     *
     * @param string $rawBrand Сырое название от поставщика
     * @return Brand|null Эталонный бренд или null, если не удалось разрешить
     */
    public function resolve(string $rawBrand): ?Brand
    {
        if (empty(trim($rawBrand))) {
            return null;
        }

        $normalized = $this->normalize($rawBrand);

        // ═══ Шаг 1: Точное совпадение в brands.name ═══
        $exactMatch = Brand::find()
            ->where(['ILIKE', 'name', $normalized])
            ->andWhere(['is_active' => true])
            ->one();

        if ($exactMatch) {
            return $exactMatch;
        }

        // ═══ Шаг 2: Поиск в brand_aliases ═══
        $alias = BrandAlias::find()
            ->where(['ILIKE', 'alias', $normalized])
            ->with('brand')
            ->one();

        if ($alias && $alias->brand && $alias->brand->is_active) {
            return $alias->brand;
        }

        // ═══ Шаг 3: AI Fallback (с кэшированием) ═══
        return $this->resolveWithAI($normalized);
    }

    /**
     * Разрешить бренд через ИИ с кэшированием.
     *
     * @param string $normalized Нормализованное название
     * @return Brand|null
     */
    protected function resolveWithAI(string $normalized): ?Brand
    {
        $cacheKey = 'brand_resolve_ai:' . md5(strtolower($normalized));
        $cache = Yii::$app->cache;

        // Проверяем кэш
        $cachedResult = $cache->get($cacheKey);
        if ($cachedResult !== false) {
            if ($cachedResult === 'NEW') {
                // ИИ сказал, что это новый бренд — создаём его
                return $this->createNewBrand($normalized);
            }
            if (is_numeric($cachedResult)) {
                $brand = Brand::findOne((int)$cachedResult);
                if ($brand && $brand->is_active) {
                    return $brand;
                }
            }
            // Если в кэше null или невалидный результат — возвращаем null
            return null;
        }

        // ИИ не доступен — возвращаем null
        if (!$this->aiService->isAvailable()) {
            Yii::warning("BrandResolver: AI недоступен, не могу разрешить бренд '{$normalized}'", 'brand.resolver');
            return null;
        }

        // Получаем список всех активных брендов для промпта
        $existingBrands = Brand::find()
            ->select(['id', 'name'])
            ->where(['is_active' => true])
            ->orderBy(['name' => SORT_ASC])
            ->asArray()
            ->all();

        $brandsList = array_map(fn($b) => "ID {$b['id']}: {$b['name']}", $existingBrands);
        $brandsListStr = implode("\n", array_slice($brandsList, 0, 100)); // Ограничиваем до 100 для промпта

        $prompt = <<<PROMPT
Является ли '{$normalized}' опечаткой или синонимом одного из этих брендов?

Список существующих брендов:
{$brandsListStr}

Если '{$normalized}' — это опечатка/синоним существующего бренда, верни его ID (число).
Если это совершенно новый бренд, верни строку "NEW".
Если это не бренд (мусор, пустое значение), верни null.

Ответь СТРОГО в JSON:
{
  "result": 123
}
или
{
  "result": "NEW"
}
или
{
  "result": null
}
PROMPT;

        try {
            $response = $this->aiService->chat($prompt, 0.1, 500);
            $parsed = $this->aiService->parseJsonResponse($response);
            $result = $parsed['result'] ?? null;

            // Кэшируем результат на 7 дней
            $cache->set($cacheKey, $result, 7 * 24 * 3600);

            if ($result === 'NEW') {
                // Создаём новый бренд
                $brand = $this->createNewBrand($normalized);
                if ($brand) {
                    // Обновляем кэш с ID нового бренда
                    $cache->set($cacheKey, $brand->id, 7 * 24 * 3600);
                }
                return $brand;
            }

            if (is_numeric($result)) {
                $brand = Brand::findOne((int)$result);
                if ($brand && $brand->is_active) {
                    // Создаём алиас для этого бренда
                    $this->createAlias($brand->id, $normalized);
                    return $brand;
                }
            }

            // ИИ вернул null или невалидный результат
            return null;

        } catch (\Throwable $e) {
            Yii::error("BrandResolver AI error: {$e->getMessage()}", 'brand.resolver');
            // Кэшируем null на 1 день, чтобы не спамить ИИ
            $cache->set($cacheKey, null, 24 * 3600);
            return null;
        }
    }

    /**
     * Создать новый эталонный бренд.
     *
     * @param string $name
     * @return Brand|null
     */
    protected function createNewBrand(string $name): ?Brand
    {
        $db = Yii::$app->db;
        $tx = $db->beginTransaction();

        try {
            $brand = new Brand();
            $brand->name = $name;
            $brand->is_active = true;

            if (!$brand->save()) {
                $tx->rollBack();
                Yii::error("BrandResolver: ошибка создания бренда '{$name}': " . implode(', ', $brand->getFirstErrors()), 'brand.resolver');
                return null;
            }

            // Создаём алиас для нового бренда
            $this->createAlias($brand->id, $name);

            $tx->commit();
            Yii::info("BrandResolver: создан новый бренд '{$name}' (ID: {$brand->id})", 'brand.resolver');
            return $brand;

        } catch (\Throwable $e) {
            $tx->rollBack();
            Yii::error("BrandResolver: ошибка создания бренда '{$name}': {$e->getMessage()}", 'brand.resolver');
            return null;
        }
    }

    /**
     * Создать алиас для бренда.
     *
     * @param int $brandId
     * @param string $alias
     * @return bool
     */
    protected function createAlias(int $brandId, string $alias): bool
    {
        // Проверяем, не существует ли уже такой алиас
        $existing = BrandAlias::find()
            ->where(['ILIKE', 'alias', $alias])
            ->one();

        if ($existing) {
            // Алиас уже существует — ничего не делаем
            return true;
        }

        try {
            $brandAlias = new BrandAlias();
            $brandAlias->brand_id = $brandId;
            $brandAlias->alias = $alias;

            if (!$brandAlias->save()) {
                Yii::warning("BrandResolver: ошибка создания алиаса '{$alias}': " . implode(', ', $brandAlias->getFirstErrors()), 'brand.resolver');
                return false;
            }

            return true;

        } catch (\Throwable $e) {
            // Игнорируем ошибки уникальности (алиас уже существует)
            if (strpos($e->getMessage(), 'unique') === false) {
                Yii::warning("BrandResolver: ошибка создания алиаса '{$alias}': {$e->getMessage()}", 'brand.resolver');
            }
            return false;
        }
    }

    /**
     * Нормализовать название бренда (trim, убрать лишние пробелы).
     *
     * @param string $raw
     * @return string
     */
    protected function normalize(string $raw): string
    {
        return trim(preg_replace('/\s+/', ' ', $raw));
    }
}
