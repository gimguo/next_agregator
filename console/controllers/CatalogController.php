<?php

namespace console\controllers;

use common\services\VariantExploderService;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use Yii;

/**
 * Управление MDM-каталогом.
 */
class CatalogController extends Controller
{
    /** @var int Лимит моделей (0 = все) */
    public int $limit = 0;

    /** @var bool Сухой прогон (только диагностика, без изменений) */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['limit', 'dryRun']);
    }

    /**
     * Sprint 16: «Взрыв вариантов» — разложить supplier_offers.variants_json
     * в полноценные reference_variants с размерами.
     *
     * Использование:
     *   php yii catalog/explode-variants                    # все модели
     *   php yii catalog/explode-variants --limit=50         # первые 50
     *   php yii catalog/explode-variants --dry-run          # только диагностика
     *
     * @return int
     */
    public function actionExplodeVariants(): int
    {
        $this->stdout("\n╔══════════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║  Sprint 16: Variant Explosion                ║\n", Console::FG_CYAN);
        $this->stdout("╚══════════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        // Диагностика
        $db = Yii::$app->db;

        $diagnostics = $db->createCommand("
            SELECT
                (SELECT COUNT(*) FROM reference_variants) as total_ref_variants,
                (SELECT COUNT(*) FROM reference_variants WHERE variant_attributes = '{}' OR variant_attributes IS NULL) as empty_attrs,
                (SELECT COUNT(*) FROM reference_variants WHERE variant_label = 'Основной' OR variant_label IS NULL) as basic_label,
                (SELECT SUM(jsonb_array_length(COALESCE(variants_json, '[]'::jsonb)))
                 FROM supplier_offers WHERE is_active = true AND jsonb_array_length(COALESCE(variants_json, '[]'::jsonb)) > 1) as total_sub_variants,
                (SELECT COUNT(DISTINCT model_id)
                 FROM supplier_offers WHERE is_active = true AND jsonb_array_length(COALESCE(variants_json, '[]'::jsonb)) > 1) as models_with_variants
        ")->queryOne();

        $this->stdout("📊 Диагностика:\n", Console::FG_YELLOW);
        $this->stdout("   reference_variants всего:       {$diagnostics['total_ref_variants']}\n");
        $this->stdout("   — с пустыми атрибутами:         {$diagnostics['empty_attrs']}\n");
        $this->stdout("   — с label «Основной»:           {$diagnostics['basic_label']}\n");
        $this->stdout("   Суб-вариантов в variants_json:  {$diagnostics['total_sub_variants']}\n");
        $this->stdout("   Моделей с вариантами:           {$diagnostics['models_with_variants']}\n\n");

        if ($this->dryRun) {
            $this->stdout("🔍 Режим dry-run — изменений не будет.\n\n", Console::FG_YELLOW);
            return $this->dryRunDiagnostics($db);
        }

        // Запуск
        /** @var VariantExploderService $exploder */
        $exploder = Yii::$app->get('variantExploder');

        $startTime = microtime(true);
        $this->stdout("🚀 Запуск Variant Explosion" .
            ($this->limit > 0 ? " (лимит: {$this->limit})" : " (все модели)") . "…\n\n", Console::FG_GREEN);

        $totals = $exploder->explodeAll(
            $this->limit,
            function (int $current, int $total, string $name, array $stats) {
                $sizesInfo = isset($stats['sizes_found'])
                    ? "+{$stats['created']} / ~{$stats['updated']} / -{$stats['deleted']} (размеров: {$stats['sizes_found']})"
                    : ($stats['error'] ?? 'ошибка');

                $pct = $total > 0 ? round($current / $total * 100) : 0;
                $bar = str_repeat('█', (int)($pct / 2.5)) . str_repeat('░', 40 - (int)($pct / 2.5));

                $nameShort = mb_substr($name, 0, 40);
                $this->stdout(
                    "\r  [{$bar}] {$pct}% ({$current}/{$total}) {$nameShort}  {$sizesInfo}      "
                );
            }
        );

        $duration = round(microtime(true) - $startTime, 1);

        $this->stdout("\n\n");
        $this->stdout("═══════════════════════════════════════════════\n", Console::FG_GREEN);
        $this->stdout("✅ Variant Explosion завершён за {$duration}s\n\n", Console::FG_GREEN);
        $this->stdout("   Моделей обработано:  {$totals['models_processed']}\n");
        $this->stdout("   Вариантов создано:   {$totals['total_created']}\n", Console::FG_GREEN);
        $this->stdout("   Вариантов обновлено: {$totals['total_updated']}\n", Console::FG_YELLOW);
        $this->stdout("   Заглушек удалено:    {$totals['total_deleted']}\n", Console::FG_RED);
        $this->stdout("   Уникальных размеров: {$totals['total_sizes']}\n");
        $this->stdout("   Пропущено (ошибки):  {$totals['models_skipped']}\n");
        $this->stdout("\n   📤 Все обработанные модели помечены в Outbox для ресинка на витрину.\n\n");

        // Итоговая статистика
        $finalStats = $db->createCommand("
            SELECT
                (SELECT COUNT(*) FROM reference_variants) as total_ref_variants,
                (SELECT COUNT(*) FROM reference_variants WHERE variant_attributes != '{}' AND variant_attributes IS NOT NULL) as with_attrs,
                (SELECT COUNT(DISTINCT 
                    (variant_attributes->>'width')::text || 'x' || (variant_attributes->>'length')::text
                ) FROM reference_variants WHERE variant_attributes != '{}') as unique_sizes
        ")->queryOne();

        $this->stdout("📊 Итоговая статистика:\n", Console::FG_CYAN);
        $this->stdout("   reference_variants:    {$finalStats['total_ref_variants']}\n");
        $this->stdout("   — с размерами:         {$finalStats['with_attrs']}\n");
        $this->stdout("   — уникальных размеров: {$finalStats['unique_sizes']}\n\n");

        return ExitCode::OK;
    }

    /**
     * Dry-run диагностика: показать что БУДЕТ, если запустить.
     */
    protected function dryRunDiagnostics($db): int
    {
        $this->stdout("📋 Примеры моделей для взрыва:\n\n", Console::FG_YELLOW);

        $examples = $db->createCommand("
            SELECT 
                pm.id,
                pm.name,
                rv.variant_label as current_label,
                jsonb_array_length(COALESCE(so.variants_json, '[]'::jsonb)) as sub_variants,
                (
                    SELECT COUNT(DISTINCT (v->>'options')::jsonb->>'Размер')
                    FROM jsonb_array_elements(so.variants_json) AS v
                    WHERE (v->>'options')::jsonb->>'Размер' IS NOT NULL
                ) as unique_sizes
            FROM product_models pm
            JOIN reference_variants rv ON rv.model_id = pm.id
            JOIN supplier_offers so ON so.model_id = pm.id AND so.is_active = true
            WHERE (rv.variant_attributes = '{}' OR rv.variant_label = 'Основной')
              AND jsonb_array_length(COALESCE(so.variants_json, '[]'::jsonb)) > 1
            ORDER BY jsonb_array_length(so.variants_json) DESC
            LIMIT 15
        ")->queryAll();

        $this->stdout(str_pad('ID', 6) . str_pad('Модель', 50) . str_pad('Сейчас', 12) . str_pad('В JSON', 10) . "Размеров\n", Console::BOLD);
        $this->stdout(str_repeat('─', 95) . "\n");

        foreach ($examples as $ex) {
            $this->stdout(
                str_pad($ex['id'], 6) .
                str_pad(mb_substr($ex['name'], 0, 48), 50) .
                str_pad($ex['current_label'], 12) .
                str_pad($ex['sub_variants'], 10) .
                $ex['unique_sizes'] . "\n"
            );
        }

        $this->stdout("\n");
        return ExitCode::OK;
    }
}
