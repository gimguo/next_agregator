<?php

namespace common\services;

use common\models\PricingRule;
use yii\base\Component;
use yii\db\Query;
use Yii;

/**
 * Сервис ценообразования — рассчитывает розничную цену (retail_price) на основе правил наценки.
 *
 * Алгоритм:
 *   1. Найти все активные PricingRule, которые подходят для данного оффера:
 *      - global      → подходит всегда
 *      - supplier    → target_id == supplier_id оффера
 *      - brand       → target_id == brand_id модели
 *      - family      → target_value == product_family модели
 *      - category    → target_id == category_id модели
 *   2. Отфильтровать по диапазону min_price / max_price (если указан)
 *   3. Отсортировать по priority DESC
 *   4. Взять ПЕРВОЕ (самое приоритетное) правило
 *   5. Рассчитать: percentage → base × (1 + markup/100), fixed → base + markup
 *   6. Округлить согласно rounding-стратегии
 *
 * Все вычисления через bcmath (scale=4) + финальное округление до 2 знаков.
 *
 * Использование:
 *   $pricingService = Yii::$app->get('pricingService');
 *   $retail = $pricingService->calculateRetailPrice($offerId);           // по ID оффера
 *   $retail = $pricingService->calculateFromContext(15000.00, $context); // по контексту
 */
class PriceCalculationService extends Component
{
    /** @var int Точность bcmath (internal scale, финальный результат = 2 знака) */
    private const BC_SCALE = 4;

    /** @var PricingRule[]|null Кэш правил (на время запроса) */
    private ?array $rulesCache = null;

    // ═══════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════

    /**
     * Рассчитать retail_price для конкретного оффера.
     *
     * @param int $offerId ID supplier_offer
     * @return float|null retail_price или null если оффер не найден / цена = 0
     */
    public function calculateRetailPrice(int $offerId): ?float
    {
        $db = Yii::$app->db;

        // Получаем контекст оффера: supplier_id, brand_id, category_id, product_family, price_min
        $offerContext = $db->createCommand("
            SELECT
                so.id AS offer_id,
                so.price_min AS base_price,
                so.supplier_id,
                pm.brand_id,
                pm.category_id,
                pm.product_family
            FROM {{%supplier_offers}} so
            JOIN {{%product_models}} pm ON pm.id = so.model_id
            WHERE so.id = :id
        ", [':id' => $offerId])->queryOne();

        if (!$offerContext || empty($offerContext['base_price'])) {
            return null;
        }

        $basePrice = (float)$offerContext['base_price'];
        $context = [
            'supplier_id'    => $offerContext['supplier_id'] ? (int)$offerContext['supplier_id'] : null,
            'brand_id'       => $offerContext['brand_id'] ? (int)$offerContext['brand_id'] : null,
            'category_id'    => $offerContext['category_id'] ? (int)$offerContext['category_id'] : null,
            'product_family' => $offerContext['product_family'],
        ];

        return $this->calculateFromContext($basePrice, $context);
    }

    /**
     * Рассчитать retail_price из базовой цены и контекста.
     *
     * @param float $basePrice Базовая цена (price_min от поставщика)
     * @param array $context   [supplier_id, brand_id, category_id, product_family]
     * @return float Рассчитанная розничная цена
     */
    public function calculateFromContext(float $basePrice, array $context): float
    {
        if ($basePrice <= 0) {
            return 0.0;
        }

        $rule = $this->findBestRule($basePrice, $context);

        if (!$rule) {
            // Нет подходящего правила → возвращаем цену как есть
            return round($basePrice, 2);
        }

        return $this->applyRule($basePrice, $rule);
    }

    /**
     * Массовый расчёт retail_price для множества офферов.
     * Оптимизировано: правила загружаются 1 раз, контексты батчами.
     *
     * @param int[] $offerIds
     * @return array offerId => retail_price
     */
    public function calculateBatch(array $offerIds): array
    {
        if (empty($offerIds)) {
            return [];
        }

        $db = Yii::$app->db;

        // Получаем контексты всех офферов одним запросом
        $inParams = [];
        $inPlaceholders = [];
        foreach (array_values($offerIds) as $i => $id) {
            $key = ':oid' . $i;
            $inParams[$key] = $id;
            $inPlaceholders[] = $key;
        }
        $inSql = implode(',', $inPlaceholders);

        $rows = $db->createCommand("
            SELECT
                so.id AS offer_id,
                so.price_min AS base_price,
                so.supplier_id,
                pm.brand_id,
                pm.category_id,
                pm.product_family
            FROM {{%supplier_offers}} so
            JOIN {{%product_models}} pm ON pm.id = so.model_id
            WHERE so.id IN ({$inSql})
        ", $inParams)->queryAll();

        // Предзагружаем правила
        $this->loadRules();

        $results = [];
        foreach ($rows as $row) {
            $basePrice = (float)($row['base_price'] ?? 0);
            if ($basePrice <= 0) {
                $results[(int)$row['offer_id']] = null;
                continue;
            }

            $context = [
                'supplier_id'    => $row['supplier_id'] ? (int)$row['supplier_id'] : null,
                'brand_id'       => $row['brand_id'] ? (int)$row['brand_id'] : null,
                'category_id'    => $row['category_id'] ? (int)$row['category_id'] : null,
                'product_family' => $row['product_family'],
            ];

            $results[(int)$row['offer_id']] = $this->calculateFromContext($basePrice, $context);
        }

        return $results;
    }

    /**
     * Пересчитать retail_price для всех офферов варианта.
     * Возвращает массив offerId => [old_retail, new_retail, changed].
     *
     * @param int $variantId
     * @return array offerId => ['old' => float, 'new' => float, 'changed' => bool]
     */
    public function recalculateVariantOffers(int $variantId): array
    {
        $db = Yii::$app->db;

        $offers = $db->createCommand("
            SELECT
                so.id AS offer_id,
                so.price_min AS base_price,
                so.retail_price AS old_retail,
                so.supplier_id,
                pm.brand_id,
                pm.category_id,
                pm.product_family
            FROM {{%supplier_offers}} so
            JOIN {{%product_models}} pm ON pm.id = so.model_id
            WHERE so.variant_id = :vid AND so.is_active = true
        ", [':vid' => $variantId])->queryAll();

        $this->loadRules();

        $results = [];
        foreach ($offers as $offer) {
            $offerId = (int)$offer['offer_id'];
            $basePrice = (float)($offer['base_price'] ?? 0);
            $oldRetail = $offer['old_retail'] !== null ? (float)$offer['old_retail'] : null;

            if ($basePrice <= 0) {
                $results[$offerId] = ['old' => $oldRetail, 'new' => null, 'changed' => $oldRetail !== null];
                continue;
            }

            $context = [
                'supplier_id'    => $offer['supplier_id'] ? (int)$offer['supplier_id'] : null,
                'brand_id'       => $offer['brand_id'] ? (int)$offer['brand_id'] : null,
                'category_id'    => $offer['category_id'] ? (int)$offer['category_id'] : null,
                'product_family' => $offer['product_family'],
            ];

            $newRetail = $this->calculateFromContext($basePrice, $context);
            $changed = $oldRetail === null || bccomp((string)$oldRetail, (string)$newRetail, 2) !== 0;

            $results[$offerId] = ['old' => $oldRetail, 'new' => $newRetail, 'changed' => $changed];
        }

        return $results;
    }

    // ═══════════════════════════════════════════
    // RULE MATCHING
    // ═══════════════════════════════════════════

    /**
     * Найти наиболее приоритетное правило для данного контекста.
     *
     * @param float $basePrice  Базовая цена (для фильтрации min_price/max_price)
     * @param array $context    [supplier_id, brand_id, category_id, product_family]
     * @return PricingRule|null
     */
    public function findBestRule(float $basePrice, array $context): ?PricingRule
    {
        $rules = $this->loadRules();

        $supplierId   = $context['supplier_id'] ?? null;
        $brandId      = $context['brand_id'] ?? null;
        $categoryId   = $context['category_id'] ?? null;
        $productFamily = $context['product_family'] ?? null;

        $bestRule = null;
        $bestPriority = -PHP_INT_MAX;

        foreach ($rules as $rule) {
            // Проверяем матч по target_type
            $matches = false;

            switch ($rule->target_type) {
                case PricingRule::TARGET_GLOBAL:
                    $matches = true;
                    break;

                case PricingRule::TARGET_SUPPLIER:
                    $matches = ($supplierId !== null && (int)$rule->target_id === $supplierId);
                    break;

                case PricingRule::TARGET_BRAND:
                    $matches = ($brandId !== null && (int)$rule->target_id === $brandId);
                    break;

                case PricingRule::TARGET_FAMILY:
                    $matches = ($productFamily !== null && $rule->target_value === $productFamily);
                    break;

                case PricingRule::TARGET_CATEGORY:
                    $matches = ($categoryId !== null && (int)$rule->target_id === $categoryId);
                    break;
            }

            if (!$matches) {
                continue;
            }

            // Проверяем ценовой диапазон
            if (!$rule->matchesPrice($basePrice)) {
                continue;
            }

            // Берём правило с наибольшим приоритетом
            if ((int)$rule->priority > $bestPriority) {
                $bestRule = $rule;
                $bestPriority = (int)$rule->priority;
            }
        }

        return $bestRule;
    }

    // ═══════════════════════════════════════════
    // CALCULATION ENGINE (bcmath)
    // ═══════════════════════════════════════════

    /**
     * Применить правило наценки к базовой цене.
     *
     * @param float       $basePrice
     * @param PricingRule $rule
     * @return float Розничная цена (округлённая)
     */
    public function applyRule(float $basePrice, PricingRule $rule): float
    {
        $base = (string)$basePrice;
        $markupValue = (string)$rule->markup_value;

        if ($rule->markup_type === PricingRule::MARKUP_PERCENTAGE) {
            // retail = base × (1 + markup / 100)
            $multiplier = bcadd('1', bcdiv($markupValue, '100', self::BC_SCALE), self::BC_SCALE);
            $retail = bcmul($base, $multiplier, self::BC_SCALE);
        } elseif ($rule->markup_type === PricingRule::MARKUP_FIXED) {
            // retail = base + markup
            $retail = bcadd($base, $markupValue, self::BC_SCALE);
        } else {
            $retail = $base;
        }

        // Округление
        $retail = $this->applyRounding((float)$retail, $rule->rounding);

        // Финальное округление до копеек
        return round($retail, 2);
    }

    /**
     * Применить стратегию округления.
     */
    protected function applyRounding(float $price, string $strategy): float
    {
        switch ($strategy) {
            case PricingRule::ROUNDING_UP_100:
                // Вверх до 100₽: 12345.67 → 12400.00
                return ceil($price / 100) * 100;

            case PricingRule::ROUNDING_UP_10:
                // Вверх до 10₽: 12345.67 → 12350.00
                return ceil($price / 10) * 10;

            case PricingRule::ROUNDING_DOWN_100:
                // Вниз до 100₽: 12345.67 → 12300.00
                return floor($price / 100) * 100;

            case PricingRule::ROUNDING_NONE:
            default:
                return $price;
        }
    }

    // ═══════════════════════════════════════════
    // PERSISTENCE: Обновить retail_price в supplier_offers
    // ═══════════════════════════════════════════

    /**
     * Рассчитать и сохранить retail_price для одного оффера.
     *
     * @param int        $offerId
     * @param float|null $basePrice   Если не задана, берётся из БД (price_min)
     * @param array|null $context     Если не задан, определяется по БД
     * @return array ['old_retail' => float|null, 'new_retail' => float, 'changed' => bool]
     */
    public function updateOfferRetailPrice(int $offerId, ?float $basePrice = null, ?array $context = null): array
    {
        $db = Yii::$app->db;

        // Если контекст не задан — загружаем из БД
        if ($basePrice === null || $context === null) {
            $row = $db->createCommand("
                SELECT
                    so.price_min AS base_price,
                    so.retail_price AS old_retail,
                    so.supplier_id,
                    pm.brand_id,
                    pm.category_id,
                    pm.product_family
                FROM {{%supplier_offers}} so
                JOIN {{%product_models}} pm ON pm.id = so.model_id
                WHERE so.id = :id
            ", [':id' => $offerId])->queryOne();

            if (!$row) {
                return ['old_retail' => null, 'new_retail' => null, 'changed' => false];
            }

            $basePrice = $basePrice ?? (float)$row['base_price'];
            $oldRetail = $row['old_retail'] !== null ? (float)$row['old_retail'] : null;

            $context = $context ?? [
                'supplier_id'    => $row['supplier_id'] ? (int)$row['supplier_id'] : null,
                'brand_id'       => $row['brand_id'] ? (int)$row['brand_id'] : null,
                'category_id'    => $row['category_id'] ? (int)$row['category_id'] : null,
                'product_family' => $row['product_family'],
            ];
        } else {
            $oldRetail = $db->createCommand(
                "SELECT retail_price FROM {{%supplier_offers}} WHERE id = :id",
                [':id' => $offerId]
            )->queryScalar();
            $oldRetail = $oldRetail !== false && $oldRetail !== null ? (float)$oldRetail : null;
        }

        $newRetail = $this->calculateFromContext($basePrice, $context);

        $changed = $oldRetail === null || bccomp((string)$oldRetail, (string)$newRetail, 2) !== 0;

        if ($changed) {
            $db->createCommand("
                UPDATE {{%supplier_offers}}
                SET retail_price = :retail, updated_at = NOW()
                WHERE id = :id
            ", [':retail' => $newRetail, ':id' => $offerId])->execute();
        }

        return [
            'old_retail' => $oldRetail,
            'new_retail' => $newRetail,
            'changed'    => $changed,
        ];
    }

    // ═══════════════════════════════════════════
    // RULES CACHE
    // ═══════════════════════════════════════════

    /**
     * Загрузить все активные правила (кэшируются на время запроса).
     *
     * @return PricingRule[]
     */
    public function loadRules(): array
    {
        if ($this->rulesCache === null) {
            $this->rulesCache = PricingRule::find()
                ->where(['is_active' => true])
                ->orderBy(['priority' => SORT_DESC])
                ->all();
        }
        return $this->rulesCache;
    }

    /**
     * Сбросить кэш правил (после CRUD, для тестов, для длинных процессов).
     */
    public function resetRulesCache(): void
    {
        $this->rulesCache = null;
    }

    /**
     * Получить информацию о правиле, которое было применено к данному контексту.
     * Полезно для отладки.
     *
     * @return array|null ['rule_id', 'rule_name', 'target_type', 'markup_type', 'markup_value', 'priority']
     */
    public function explainPrice(float $basePrice, array $context): ?array
    {
        $rule = $this->findBestRule($basePrice, $context);
        if (!$rule) {
            return null;
        }

        return [
            'rule_id'      => $rule->id,
            'rule_name'    => $rule->name,
            'target_type'  => $rule->target_type,
            'markup_type'  => $rule->markup_type,
            'markup_value' => (float)$rule->markup_value,
            'priority'     => (int)$rule->priority,
            'rounding'     => $rule->rounding,
            'base_price'   => $basePrice,
            'retail_price' => $this->applyRule($basePrice, $rule),
        ];
    }
}
