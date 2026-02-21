<?php

namespace console\controllers;

use common\dto\ReadinessReportDTO;
use common\models\ChannelRequirement;
use common\models\ModelChannelReadiness;
use common\models\SalesChannel;
use common\services\ReadinessScoringService;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use Yii;

/**
 * Sprint 12 — Data Completeness & Channel Readiness.
 *
 * Инструменты скоринга качества карточек товаров для маркетплейсов.
 *
 * Команды:
 *   php yii quality/scan --channel=rosmatras     # Полный скоринг всех моделей
 *   php yii quality/scan --channel=1             # По ID канала
 *   php yii quality/report                       # Красивый отчёт по всем каналам
 *   php yii quality/report --channel=rosmatras   # Отчёт по одному каналу
 *   php yii quality/check --model=123            # Проверить конкретную модель
 *   php yii quality/requirements                 # Показать требования каналов
 */
class QualityController extends Controller
{
    /** @var string Драйвер канала или ID (для scan/report) */
    public string $channel = '';

    /** @var int ID модели (для check) */
    public int $model = 0;

    /** @var int Топ N проблем в отчёте */
    public int $top = 15;

    /** @var ReadinessScoringService */
    private ReadinessScoringService $readinessService;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'channel', 'model', 'top',
        ]);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'channel',
            'm' => 'model',
            't' => 'top',
        ]);
    }

    public function init(): void
    {
        parent::init();
        $this->readinessService = Yii::$app->get('readinessService');
    }

    // ═══════════════════════════════════════════
    // SCAN — Полный скоринг
    // ═══════════════════════════════════════════

    /**
     * Полный пересчёт скоринга для всех моделей.
     *
     * php yii quality/scan --channel=rosmatras
     * php yii quality/scan --channel=1
     */
    public function actionScan(): int
    {
        $channel = $this->resolveChannel();
        if (!$channel) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n  ╔══════════════════════════════════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("  ║  QUALITY SCAN — Скоринг полноты данных                              ║\n", Console::FG_CYAN);
        $this->stdout("  ╚══════════════════════════════════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $this->stdout("  Канал: {$channel->name} (driver: {$channel->driver})\n\n");

        $this->readinessService->resetCache();

        $result = $this->readinessService->evaluateAll($channel, function ($processed, $total) {
            Console::updateProgress($processed, $total, '  Скоринг: ');
        });

        Console::endProgress();

        $this->printSummary($result, $channel);

        return ExitCode::OK;
    }

    // ═══════════════════════════════════════════
    // REPORT — Красивый отчёт
    // ═══════════════════════════════════════════

    /**
     * Отчёт готовности по каналам.
     *
     * php yii quality/report
     * php yii quality/report --channel=rosmatras
     */
    public function actionReport(): int
    {
        $this->stdout("\n  ╔══════════════════════════════════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("  ║  QUALITY REPORT — Отчёт готовности карточек                         ║\n", Console::FG_CYAN);
        $this->stdout("  ╚══════════════════════════════════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $db = Yii::$app->db;

        // Определяем каналы
        if ($this->channel) {
            $channel = $this->resolveChannel();
            if (!$channel) {
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $channels = [$channel];
        } else {
            $channels = SalesChannel::findActive();
        }

        if (empty($channels)) {
            $this->stdout("  Нет активных каналов.\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $totalModels = (int)$db->createCommand(
            "SELECT COUNT(*) FROM {{%product_models}} WHERE status = 'active'"
        )->queryScalar();
        $this->stdout("  Всего активных моделей: {$totalModels}\n\n");

        foreach ($channels as $channel) {
            $this->stdout("  ═══ Канал: {$channel->name} ({$channel->driver}) ═══\n\n", Console::BOLD);

            // Статистика из кэша
            $stats = $db->createCommand("
                SELECT
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE is_ready = true) AS ready,
                    COUNT(*) FILTER (WHERE is_ready = false) AS not_ready,
                    ROUND(AVG(score)::numeric, 1) AS avg_score
                FROM {{%model_channel_readiness}}
                WHERE channel_id = :cid
            ", [':cid' => $channel->id])->queryOne();

            $total = (int)($stats['total'] ?? 0);
            $ready = (int)($stats['ready'] ?? 0);
            $notReady = (int)($stats['not_ready'] ?? 0);
            $avgScore = (float)($stats['avg_score'] ?? 0);
            $notScanned = $totalModels - $total;

            if ($total === 0) {
                $this->stdout("    Кэш пуст. Запустите: php yii quality/scan --channel={$channel->driver}\n\n", Console::FG_YELLOW);
                continue;
            }

            // Основная статистика
            $readyPct = $total > 0 ? round($ready / $total * 100, 1) : 0;
            $readyColor = $readyPct >= 90 ? Console::FG_GREEN : ($readyPct >= 70 ? Console::FG_YELLOW : Console::FG_RED);

            $this->stdout("    Проверено:       {$total} из {$totalModels}");
            if ($notScanned > 0) {
                $this->stdout(" (не просканировано: {$notScanned})", Console::FG_YELLOW);
            }
            $this->stdout("\n");

            $this->stdout("    Готовы:          ");
            $this->stdout("{$ready} ({$readyPct}%)\n", $readyColor);

            $this->stdout("    Не готовы:       ");
            $this->stdout("{$notReady}\n", $notReady > 0 ? Console::FG_RED : Console::FG_GREEN);

            $this->stdout("    Средний скор:    ");
            $scoreColor = $avgScore >= 80 ? Console::FG_GREEN : ($avgScore >= 60 ? Console::FG_YELLOW : Console::FG_RED);
            $this->stdout("{$avgScore}%\n", $scoreColor);

            // Топ проблем
            $topMissing = $db->createCommand("
                SELECT
                    elem AS field,
                    COUNT(*) AS cnt
                FROM {{%model_channel_readiness}} mcr,
                     jsonb_array_elements_text(mcr.missing_fields) AS elem
                WHERE mcr.channel_id = :cid AND mcr.is_ready = false
                GROUP BY elem
                ORDER BY cnt DESC
                LIMIT :limit
            ", [':cid' => $channel->id, ':limit' => $this->top])->queryAll();

            if (!empty($topMissing)) {
                $this->stdout("\n    ── Топ проблем (не готовые модели) ──\n", Console::FG_RED);
                $this->stdout(sprintf("    %-45s  %s\n", 'Проблема', 'Кол-во'), Console::BOLD);
                $this->stdout("    " . str_repeat('─', 55) . "\n");

                foreach ($topMissing as $row) {
                    $label = ReadinessReportDTO::labelFor($row['field']);
                    $this->stdout(sprintf("    %-45s  %d\n",
                        mb_substr($label, 0, 45),
                        (int)$row['cnt']
                    ));
                }
            }

            // Распределение по скорам
            $distribution = $db->createCommand("
                SELECT
                    CASE
                        WHEN score = 100 THEN '100%'
                        WHEN score >= 80 THEN '80-99%'
                        WHEN score >= 60 THEN '60-79%'
                        WHEN score >= 40 THEN '40-59%'
                        ELSE '0-39%'
                    END AS bucket,
                    COUNT(*) AS cnt
                FROM {{%model_channel_readiness}}
                WHERE channel_id = :cid
                GROUP BY bucket
                ORDER BY bucket DESC
            ", [':cid' => $channel->id])->queryAll();

            if (!empty($distribution)) {
                $this->stdout("\n    ── Распределение скоров ──\n");
                foreach ($distribution as $row) {
                    $bar = str_repeat('█', (int)round((int)$row['cnt'] / max(1, $total) * 30));
                    $this->stdout(sprintf("    %-8s %4d  %s\n", $row['bucket'], (int)$row['cnt'], $bar));
                }
            }

            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    // ═══════════════════════════════════════════
    // CHECK — Проверить конкретную модель
    // ═══════════════════════════════════════════

    /**
     * Проверить готовность конкретной модели для всех каналов.
     *
     * php yii quality/check --model=123
     */
    public function actionCheck(): int
    {
        if (!$this->model) {
            $this->stderr("\n  Укажите --model=ID\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $db = Yii::$app->db;

        // Проверяем модель
        $modelRow = $db->createCommand("
            SELECT id, name, product_family, brand_id FROM {{%product_models}} WHERE id = :id
        ", [':id' => $this->model])->queryOne();

        if (!$modelRow) {
            $this->stderr("\n  Модель #{$this->model} не найдена.\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n  ╔══════════════════════════════════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("  ║  QUALITY CHECK — Проверка модели                                    ║\n", Console::FG_CYAN);
        $this->stdout("  ╚══════════════════════════════════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $this->stdout("  Модель:     #{$modelRow['id']} — {$modelRow['name']}\n");
        $this->stdout("  Семейство:  {$modelRow['product_family']}\n");
        $this->stdout("  Бренд ID:   " . ($modelRow['brand_id'] ?: 'N/A') . "\n\n");

        $channels = SalesChannel::findActive();

        foreach ($channels as $channel) {
            $report = $this->readinessService->evaluate($this->model, $channel, true);

            $statusIcon = $report->isReady ? '✅' : '❌';
            $statusColor = $report->isReady ? Console::FG_GREEN : Console::FG_RED;

            $this->stdout("  ── {$channel->name} ({$channel->driver}) ──\n", Console::BOLD);
            $this->stdout("    Статус: ");
            $this->stdout("{$statusIcon} " . ($report->isReady ? 'ГОТОВА' : 'НЕ ГОТОВА') . "\n", $statusColor);
            $this->stdout("    Скор:   {$report->score}%\n");

            if (!empty($report->missing)) {
                $this->stdout("    Пропущено:\n");
                foreach ($report->missing as $field) {
                    $label = ReadinessReportDTO::labelFor($field);
                    $isRequired = str_starts_with($field, 'required:');
                    $icon = $isRequired ? '🚫' : '⚠️';
                    $color = $isRequired ? Console::FG_RED : Console::FG_YELLOW;
                    $this->stdout("      {$icon} {$label}\n", $color);
                }
            }

            if (!empty($report->details)) {
                $this->stdout("    Детали:\n");
                foreach ($report->details as $check => $detail) {
                    $ok = ($detail['status'] ?? '') === 'ok';
                    $icon = $ok ? '✓' : '✗';
                    $color = $ok ? Console::FG_GREEN : Console::FG_RED;
                    $info = json_encode($detail, JSON_UNESCAPED_UNICODE);
                    $this->stdout("      {$icon} {$check}: {$info}\n", $color);
                }
            }

            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    // ═══════════════════════════════════════════
    // REQUIREMENTS — Показать требования
    // ═══════════════════════════════════════════

    /**
     * Показать требования каналов.
     *
     * php yii quality/requirements
     */
    public function actionRequirements(): int
    {
        $this->stdout("\n  ╔══════════════════════════════════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("  ║  CHANNEL REQUIREMENTS — Требования каналов                          ║\n", Console::FG_CYAN);
        $this->stdout("  ╚══════════════════════════════════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $channels = SalesChannel::findActive();

        foreach ($channels as $channel) {
            $this->stdout("  ═══ {$channel->name} ({$channel->driver}) ═══\n\n", Console::BOLD);

            $requirements = ChannelRequirement::findAllForChannel($channel->id);

            if (empty($requirements)) {
                $this->stdout("    Нет требований (все модели будут считаться готовыми).\n\n", Console::FG_YELLOW);
                continue;
            }

            foreach ($requirements as $family => $req) {
                $familyLabel = $family === '*' ? 'Все семейства (*)' : $family;
                $this->stdout("    ── {$familyLabel} ──\n");

                $checks = [];
                if ($req->require_image) $checks[] = "фото (мин. {$req->min_images})";
                if ($req->require_description) $checks[] = "описание (мин. {$req->min_description_length} симв.)";
                if ($req->require_barcode) $checks[] = "штрихкод";
                if ($req->require_brand) $checks[] = "бренд";
                if ($req->require_price) $checks[] = "цена > 0";

                $this->stdout("      Обязательно:      " . (empty($checks) ? '—' : implode(', ', $checks)) . "\n");

                $reqAttrs = $req->getRequiredAttrsList();
                $this->stdout("      Обяз. атрибуты:   " . (empty($reqAttrs) ? '—' : implode(', ', $reqAttrs)) . "\n");

                $recAttrs = $req->getRecommendedAttrsList();
                $this->stdout("      Рекомендуемые:    " . (empty($recAttrs) ? '—' : implode(', ', $recAttrs)) . "\n");

                $this->stdout("\n");
            }
        }

        return ExitCode::OK;
    }

    // ═══════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════

    /**
     * Резолвить канал по --channel (driver name или ID).
     */
    private function resolveChannel(): ?SalesChannel
    {
        if (!$this->channel) {
            $this->stderr("\n  Укажите --channel=DRIVER или --channel=ID\n\n", Console::FG_RED);
            return null;
        }

        // Пробуем по ID
        if (is_numeric($this->channel)) {
            $channel = SalesChannel::findOne((int)$this->channel);
        } else {
            $channel = SalesChannel::findByDriver($this->channel);
        }

        if (!$channel) {
            $this->stderr("\n  Канал '{$this->channel}' не найден.\n\n", Console::FG_RED);
            return null;
        }

        return $channel;
    }

    /**
     * Напечатать итоговую сводку.
     */
    private function printSummary(array $result, SalesChannel $channel): void
    {
        $this->stdout("\n  ╔══════════════════════════════════════════════════════════════╗\n", Console::FG_GREEN);
        $this->stdout("  ║  РЕЗУЛЬТАТЫ СКОРИНГА                                       ║\n", Console::FG_GREEN);
        $this->stdout("  ╚══════════════════════════════════════════════════════════════╝\n\n", Console::FG_GREEN);

        $this->stdout("  Канал:          {$channel->name}\n");
        $this->stdout("  Всего моделей:  {$result['total']}\n");

        $readyPct = $result['total'] > 0 ? round($result['ready'] / $result['total'] * 100, 1) : 0;
        $readyColor = $readyPct >= 90 ? Console::FG_GREEN : ($readyPct >= 70 ? Console::FG_YELLOW : Console::FG_RED);
        $this->stdout("  Готовы:         ");
        $this->stdout("{$result['ready']} ({$readyPct}%)\n", $readyColor);

        $this->stdout("  Не готовы:      ");
        $this->stdout("{$result['not_ready']}\n", $result['not_ready'] > 0 ? Console::FG_RED : Console::FG_GREEN);

        $this->stdout("  Средний скор:   {$result['avg_score']}%\n");

        // Топ пропусков
        if (!empty($result['top_missing'])) {
            $this->stdout("\n  ── Топ проблем ──\n", Console::FG_RED);
            $this->stdout(sprintf("  %-50s  %s\n", 'Проблема', 'Кол-во'), Console::BOLD);
            $this->stdout("  " . str_repeat('─', 60) . "\n");

            $i = 0;
            foreach ($result['top_missing'] as $field => $count) {
                if ($i >= $this->top) break;
                $label = ReadinessReportDTO::labelFor($field);
                $this->stdout(sprintf("  %-50s  %d\n", mb_substr($label, 0, 50), $count));
                $i++;
            }
        }

        $this->stdout("\n");
    }
}
