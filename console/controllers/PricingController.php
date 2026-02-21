<?php

namespace console\controllers;

use common\models\PricingRule;
use common\services\OutboxService;
use common\services\PriceCalculationService;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use Yii;

/**
 * Sprint 11 — Pricing Engine: управление правилами наценки и массовый репрайсинг.
 *
 * Команды:
 *   php yii pricing/rules                  # Список правил наценки
 *   php yii pricing/recalculate --all      # Пересчёт ВСЕХ офферов
 *   php yii pricing/recalculate --brand=1  # Пересчёт по бренду
 *   php yii pricing/recalculate --supplier=1  # Пересчёт по поставщику
 *   php yii pricing/recalculate --family=mattress  # Пересчёт по семейству
 *   php yii pricing/recalculate --category=5   # Пересчёт по категории
 *   php yii pricing/explain --offer=123    # Показать какое правило применяется
 *   php yii pricing/stats                  # Статистика наценок
 */
class PricingController extends Controller
{
    /** @var int ID бренда для фильтрации */
    public int $brand = 0;

    /** @var int ID поставщика для фильтрации */
    public int $supplier = 0;

    /** @var string Семейство товаров (mattress, pillow, etc.) */
    public string $family = '';

    /** @var int ID категории для фильтрации */
    public int $category = 0;

    /** @var bool Пересчитать все */
    public bool $all = false;

    /** @var int ID оффера (для explain) */
    public int $offer = 0;

    /** @var int Размер батча */
    public int $batch = 500;

    /** @var bool Dry-run (не сохранять, только показать) */
    public bool $dryRun = false;

    /** @var PriceCalculationService */
    private PriceCalculationService $pricingService;

    /** @var OutboxService */
    private OutboxService $outbox;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'brand', 'supplier', 'family', 'category',
            'all', 'offer', 'batch', 'dryRun',
        ]);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'b' => 'brand',
            's' => 'supplier',
            'f' => 'family',
            'c' => 'category',
            'a' => 'all',
            'o' => 'offer',
            'n' => 'batch',
            'd' => 'dryRun',
        ]);
    }

    public function init(): void
    {
        parent::init();
        $this->pricingService = Yii::$app->get('pricingService');
        $this->outbox = Yii::$app->get('outbox');
    }

    // ═══════════════════════════════════════════
    // RULES LIST
    // ═══════════════════════════════════════════

    /**
     * Показать список правил наценки.
     *
     * php yii pricing/rules
     */
    public function actionRules(): int
    {
        $rules = PricingRule::find()
            ->orderBy(['priority' => SORT_DESC, 'target_type' => SORT_ASC])
            ->all();

        if (empty($rules)) {
            $this->stdout("\n  Нет правил наценки.\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("\n  ╔══════════════════════════════════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("  ║  PRICING RULES — Правила наценки                                   ║\n", Console::FG_CYAN);
        $this->stdout("  ╚══════════════════════════════════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $this->stdout(sprintf(
            "  %-4s  %-30s  %-12s  %-8s  %-8s  %-8s  %-14s  %-6s\n",
            'ID', 'Название', 'Цель', 'Тип', 'Наценка', 'Приор.', 'Округление', 'Актив'
        ), Console::BOLD);
        $this->stdout("  " . str_repeat('─', 96) . "\n");

        foreach ($rules as $rule) {
            $targetLabel = $rule->target_type;
            if ($rule->target_id) {
                $targetLabel .= ' #' . $rule->target_id;
            } elseif ($rule->target_value) {
                $targetLabel .= ':' . $rule->target_value;
            }

            $markupLabel = $rule->markup_type === PricingRule::MARKUP_PERCENTAGE
                ? '+' . $rule->markup_value . '%'
                : '+' . $rule->markup_value . '₽';

            $activeLabel = $rule->is_active ? '✓' : '✗';
            $activeColor = $rule->is_active ? Console::FG_GREEN : Console::FG_RED;

            $this->stdout(sprintf("  %-4d  %-30s  %-12s  %-8s  %-8s  %-8d  %-14s  ",
                $rule->id,
                mb_substr($rule->name, 0, 30),
                $targetLabel,
                $rule->markup_type,
                $markupLabel,
                (int)$rule->priority,
                $rule->rounding
            ));
            $this->stdout($activeLabel . "\n", $activeColor);
        }

        $this->stdout("\n  Всего правил: " . count($rules) . "\n\n");

        return ExitCode::OK;
    }

    // ═══════════════════════════════════════════
    // MASS REPRICING
    // ═══════════════════════════════════════════

    /**
     * Массовый пересчёт retail_price.
     *
     * php yii pricing/recalculate --all
     * php yii pricing/recalculate --brand=1
     * php yii pricing/recalculate --supplier=1
     * php yii pricing/recalculate --family=mattress
     * php yii pricing/recalculate --category=5
     * php yii pricing/recalculate --brand=1 --dry-run
     */
    public function actionRecalculate(): int
    {
        // Проверяем что хотя бы один фильтр задан
        if (!$this->all && !$this->brand && !$this->supplier && !$this->family && !$this->category) {
            $this->stderr("\n  Укажите фильтр: --all, --brand=ID, --supplier=ID, --family=NAME, --category=ID\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $db = Yii::$app->db;

        // Собираем WHERE-условие для выборки офферов
        $where = 'so.is_active = true AND so.price_min > 0';
        $params = [];
        $filterLabel = '';

        if ($this->brand) {
            $where .= ' AND pm.brand_id = :brand_id';
            $params[':brand_id'] = $this->brand;
            $brandName = $db->createCommand(
                "SELECT canonical_name FROM {{%brands}} WHERE id = :id",
                [':id' => $this->brand]
            )->queryScalar();
            $filterLabel = "brand: {$brandName} (#{$this->brand})";
        }

        if ($this->supplier) {
            $where .= ' AND so.supplier_id = :supplier_id';
            $params[':supplier_id'] = $this->supplier;
            $supplierName = $db->createCommand(
                "SELECT name FROM {{%suppliers}} WHERE id = :id",
                [':id' => $this->supplier]
            )->queryScalar();
            $filterLabel .= ($filterLabel ? ', ' : '') . "supplier: {$supplierName} (#{$this->supplier})";
        }

        if ($this->family) {
            $where .= ' AND pm.product_family = :family';
            $params[':family'] = $this->family;
            $filterLabel .= ($filterLabel ? ', ' : '') . "family: {$this->family}";
        }

        if ($this->category) {
            $where .= ' AND pm.category_id = :category_id';
            $params[':category_id'] = $this->category;
            $filterLabel .= ($filterLabel ? ', ' : '') . "category: #{$this->category}";
        }

        if ($this->all) {
            $filterLabel = 'ВСЕ офферы';
        }

        // Считаем общее количество
        $totalCount = (int)$db->createCommand("
            SELECT COUNT(so.id)
            FROM {{%supplier_offers}} so
            JOIN {{%product_models}} pm ON pm.id = so.model_id
            WHERE {$where}
        ", $params)->queryScalar();

        $this->stdout("\n  ╔══════════════════════════════════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("  ║  MASS REPRICING — Массовый пересчёт цен                            ║\n", Console::FG_CYAN);
        $this->stdout("  ╚══════════════════════════════════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $this->stdout("  Фильтр: {$filterLabel}\n");
        $this->stdout("  Всего офферов: {$totalCount}\n");
        $this->stdout("  Размер батча: {$this->batch}\n");
        if ($this->dryRun) {
            $this->stdout("  РЕЖИМ: DRY-RUN (без сохранения)\n", Console::FG_YELLOW);
        }
        $this->stdout("\n");

        if ($totalCount === 0) {
            $this->stdout("  Нет офферов для обработки.\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        // Сбросить кэш правил для актуальных данных
        $this->pricingService->resetRulesCache();
        $this->pricingService->loadRules();

        $offset = 0;
        $processed = 0;
        $changed = 0;
        $outboxEmitted = 0;
        $errors = 0;

        // Множество model_id:variant_id для которых нужно пересчитать Golden Record
        $goldenRecordVariants = [];
        $goldenRecordModels = [];

        Console::startProgress(0, $totalCount, '  Пересчёт: ');

        while ($offset < $totalCount) {
            $offers = $db->createCommand("
                SELECT
                    so.id AS offer_id,
                    so.model_id,
                    so.variant_id,
                    so.price_min AS base_price,
                    so.retail_price AS old_retail,
                    so.supplier_id,
                    pm.brand_id,
                    pm.category_id,
                    pm.product_family
                FROM {{%supplier_offers}} so
                JOIN {{%product_models}} pm ON pm.id = so.model_id
                WHERE {$where}
                ORDER BY so.id
                LIMIT :limit OFFSET :offset
            ", array_merge($params, [':limit' => $this->batch, ':offset' => $offset]))->queryAll();

            if (empty($offers)) {
                break;
            }

            foreach ($offers as $offer) {
                try {
                    $offerId = (int)$offer['offer_id'];
                    $modelId = (int)$offer['model_id'];
                    $variantId = (int)$offer['variant_id'];
                    $basePrice = (float)$offer['base_price'];
                    $oldRetail = $offer['old_retail'] !== null ? (float)$offer['old_retail'] : null;

                    $context = [
                        'supplier_id'    => $offer['supplier_id'] ? (int)$offer['supplier_id'] : null,
                        'brand_id'       => $offer['brand_id'] ? (int)$offer['brand_id'] : null,
                        'category_id'    => $offer['category_id'] ? (int)$offer['category_id'] : null,
                        'product_family' => $offer['product_family'],
                    ];

                    $newRetail = $this->pricingService->calculateFromContext($basePrice, $context);

                    $isChanged = $oldRetail === null || bccomp((string)$oldRetail, (string)$newRetail, 2) !== 0;

                    if ($isChanged) {
                        if (!$this->dryRun) {
                            // Сохраняем retail_price
                            $db->createCommand("
                                UPDATE {{%supplier_offers}}
                                SET retail_price = :retail, updated_at = NOW()
                                WHERE id = :id
                            ", [':retail' => $newRetail, ':id' => $offerId])->execute();

                            // Запоминаем для GoldenRecord пересчёта
                            $goldenRecordVariants[$variantId] = $variantId;
                            $goldenRecordModels[$modelId] = $modelId;

                            // Эмитим price_updated в Outbox
                            $this->outbox->emitPriceUpdate($modelId, $variantId, $offerId, [
                                'source'           => 'mass_repricing',
                                'old_retail_price'  => $oldRetail,
                                'new_retail_price'  => $newRetail,
                            ]);
                            $outboxEmitted++;
                        }
                        $changed++;
                    }

                    $processed++;
                } catch (\Throwable $e) {
                    $errors++;
                    Yii::error("Pricing recalculate error offer_id={$offer['offer_id']}: {$e->getMessage()}", 'pricing');
                }

                Console::updateProgress($processed, $totalCount);
            }

            $offset += $this->batch;

            // Пересчитываем Golden Record для изменённых вариантов/моделей батчами
            if (!$this->dryRun && !empty($goldenRecordVariants)) {
                $goldenRecord = Yii::$app->get('goldenRecord');
                foreach ($goldenRecordVariants as $vid) {
                    $goldenRecord->recalculateVariant($vid);
                }
                foreach ($goldenRecordModels as $mid) {
                    $goldenRecord->recalculateModel($mid);
                }
                $goldenRecordVariants = [];
                $goldenRecordModels = [];
            }
        }

        Console::endProgress();

        // Финальный отчёт
        $this->stdout("\n  ╔══════════════════════════════════════════════════╗\n", Console::FG_GREEN);
        $this->stdout("  ║  РЕЗУЛЬТАТЫ РЕПРАЙСИНГА                         ║\n", Console::FG_GREEN);
        $this->stdout("  ╚══════════════════════════════════════════════════╝\n\n", Console::FG_GREEN);

        $this->stdout("  Обработано:     {$processed}\n");
        $this->stdout("  Изменено:       ", Console::FG_YELLOW);
        $this->stdout("{$changed}\n");
        $this->stdout("  Outbox задач:   {$outboxEmitted}\n");
        if ($errors > 0) {
            $this->stdout("  Ошибок:         {$errors}\n", Console::FG_RED);
        }
        if ($this->dryRun) {
            $this->stdout("\n  [DRY-RUN] Изменения НЕ сохранены.\n", Console::FG_YELLOW);
        }
        $this->stdout("\n");

        return ExitCode::OK;
    }

    // ═══════════════════════════════════════════
    // EXPLAIN PRICING
    // ═══════════════════════════════════════════

    /**
     * Объяснить какое правило применяется к конкретному офферу.
     *
     * php yii pricing/explain --offer=123
     */
    public function actionExplain(): int
    {
        if (!$this->offer) {
            $this->stderr("\n  Укажите --offer=ID\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $db = Yii::$app->db;

        $offer = $db->createCommand("
            SELECT
                so.id AS offer_id,
                so.supplier_sku,
                so.price_min,
                so.retail_price,
                so.supplier_id,
                s.name AS supplier_name,
                pm.id AS model_id,
                pm.name AS model_name,
                pm.brand_id,
                b.canonical_name AS brand_name,
                pm.category_id,
                pm.product_family,
                rv.id AS variant_id,
                rv.variant_label
            FROM {{%supplier_offers}} so
            JOIN {{%product_models}} pm ON pm.id = so.model_id
            LEFT JOIN {{%reference_variants}} rv ON rv.id = so.variant_id
            LEFT JOIN {{%suppliers}} s ON s.id = so.supplier_id
            LEFT JOIN {{%brands}} b ON b.id = pm.brand_id
            WHERE so.id = :id
        ", [':id' => $this->offer])->queryOne();

        if (!$offer) {
            $this->stderr("\n  Оффер #{$this->offer} не найден.\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $basePrice = (float)$offer['price_min'];
        $context = [
            'supplier_id'    => $offer['supplier_id'] ? (int)$offer['supplier_id'] : null,
            'brand_id'       => $offer['brand_id'] ? (int)$offer['brand_id'] : null,
            'category_id'    => $offer['category_id'] ? (int)$offer['category_id'] : null,
            'product_family' => $offer['product_family'],
        ];

        $this->pricingService->resetRulesCache();
        $explanation = $this->pricingService->explainPrice($basePrice, $context);

        $this->stdout("\n  ╔══════════════════════════════════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("  ║  PRICING EXPLAIN — Объяснение ценообразования                       ║\n", Console::FG_CYAN);
        $this->stdout("  ╚══════════════════════════════════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $this->stdout("  Оффер:          #{$offer['offer_id']} ({$offer['supplier_sku']})\n");
        $this->stdout("  Модель:         #{$offer['model_id']} — {$offer['model_name']}\n");
        $this->stdout("  Вариант:        #{$offer['variant_id']} — {$offer['variant_label']}\n");
        $this->stdout("  Поставщик:      #{$offer['supplier_id']} — {$offer['supplier_name']}\n");
        $this->stdout("  Бренд:          " . ($offer['brand_name'] ?? 'N/A') . " (#{$offer['brand_id']})\n");
        $this->stdout("  Семейство:      " . ($offer['product_family'] ?? 'N/A') . "\n");
        $this->stdout("  Категория:      #{$offer['category_id']}\n\n");

        $this->stdout("  ── Цены ──\n");
        $this->stdout("  Цена поставщика:  " . number_format($basePrice, 2, '.', ' ') . " ₽\n");

        $currentRetail = $offer['retail_price'] !== null ? (float)$offer['retail_price'] : null;
        $this->stdout("  Текущая розн.:    " . ($currentRetail !== null ? number_format($currentRetail, 2, '.', ' ') : '—') . " ₽\n");

        if ($explanation) {
            $this->stdout("\n  ── Применяемое правило ──\n", Console::FG_GREEN);
            $this->stdout("  Правило:          #{$explanation['rule_id']} — {$explanation['rule_name']}\n");
            $this->stdout("  Тип цели:         {$explanation['target_type']}\n");
            $this->stdout("  Наценка:          ");
            if ($explanation['markup_type'] === 'percentage') {
                $this->stdout("+{$explanation['markup_value']}%\n", Console::FG_YELLOW);
            } else {
                $this->stdout("+{$explanation['markup_value']} ₽\n", Console::FG_YELLOW);
            }
            $this->stdout("  Приоритет:        {$explanation['priority']}\n");
            $this->stdout("  Округление:       {$explanation['rounding']}\n");
            $this->stdout("\n  ── Расчёт ──\n", Console::FG_CYAN);
            $this->stdout("  Рассчитанная:     " . number_format($explanation['retail_price'], 2, '.', ' ') . " ₽\n", Console::BOLD);
        } else {
            $this->stdout("\n  Подходящее правило: НЕТ\n", Console::FG_YELLOW);
            $this->stdout("  Розничная цена = цена поставщика: " . number_format($basePrice, 2, '.', ' ') . " ₽\n");
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    // ═══════════════════════════════════════════
    // STATS
    // ═══════════════════════════════════════════

    /**
     * Статистика наценок.
     *
     * php yii pricing/stats
     */
    public function actionStats(): int
    {
        $db = Yii::$app->db;

        $this->stdout("\n  ╔══════════════════════════════════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("  ║  PRICING STATS — Статистика ценообразования                         ║\n", Console::FG_CYAN);
        $this->stdout("  ╚══════════════════════════════════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        // Количество правил
        $totalRules = (int)PricingRule::find()->count();
        $activeRules = (int)PricingRule::find()->where(['is_active' => true])->count();
        $this->stdout("  Правила:    {$activeRules} активных из {$totalRules}\n");

        // Офферы с retail_price
        $totalOffers = (int)$db->createCommand(
            "SELECT COUNT(*) FROM {{%supplier_offers}} WHERE is_active = true"
        )->queryScalar();
        $withRetail = (int)$db->createCommand(
            "SELECT COUNT(*) FROM {{%supplier_offers}} WHERE is_active = true AND retail_price IS NOT NULL"
        )->queryScalar();
        $withoutRetail = $totalOffers - $withRetail;

        $this->stdout("  Офферы:     {$totalOffers} всего\n");
        $this->stdout("    С наценкой:   {$withRetail}\n", Console::FG_GREEN);
        $this->stdout("    Без наценки:  {$withoutRetail}\n", $withoutRetail > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        // Средняя наценка (фильтруем офферы с ценой > 100₽ чтобы исключить мусорные данные)
        if ($withRetail > 0) {
            $avgMarkup = $db->createCommand("
                SELECT AVG(
                    CASE WHEN price_min > 100 THEN ((retail_price - price_min) / price_min * 100) ELSE NULL END
                )
                FROM {{%supplier_offers}}
                WHERE is_active = true AND retail_price IS NOT NULL AND price_min > 100
            ")->queryScalar();

            $this->stdout(sprintf("  Средняя наценка: %.1f%%\n", (float)$avgMarkup), Console::FG_CYAN);
        }

        // Разбивка по поставщикам
        $bySupplier = $db->createCommand("
            SELECT
                s.name AS supplier_name,
                COUNT(so.id) AS total,
                COUNT(so.retail_price) AS with_retail,
                AVG(CASE WHEN so.price_min > 100 AND so.retail_price IS NOT NULL
                    THEN ((so.retail_price - so.price_min) / so.price_min * 100) ELSE NULL END) AS avg_markup
            FROM {{%supplier_offers}} so
            JOIN {{%suppliers}} s ON s.id = so.supplier_id
            WHERE so.is_active = true
            GROUP BY s.name
            ORDER BY total DESC
        ")->queryAll();

        if (!empty($bySupplier)) {
            $this->stdout("\n  ── По поставщикам ──\n");
            $this->stdout(sprintf("  %-25s  %-8s  %-10s  %-12s\n", 'Поставщик', 'Всего', 'С наценкой', 'Ср. наценка'), Console::BOLD);
            $this->stdout("  " . str_repeat('─', 60) . "\n");

            foreach ($bySupplier as $row) {
                $avgLabel = $row['avg_markup'] !== null ? sprintf('%.1f%%', (float)$row['avg_markup']) : '—';
                $this->stdout(sprintf("  %-25s  %-8d  %-10d  %-12s\n",
                    mb_substr($row['supplier_name'], 0, 25),
                    (int)$row['total'],
                    (int)$row['with_retail'],
                    $avgLabel
                ));
            }
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }
}
