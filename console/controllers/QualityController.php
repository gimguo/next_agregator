<?php

namespace console\controllers;

use common\dto\HealingResultDTO;
use common\dto\ReadinessReportDTO;
use common\jobs\HealModelJob;
use common\models\ChannelRequirement;
use common\models\ModelChannelReadiness;
use common\models\SalesChannel;
use common\services\AutoHealingService;
use common\services\ReadinessScoringService;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use Yii;

/**
 * Sprint 12+13 — Data Completeness, Channel Readiness & AI Auto-Healing.
 *
 * Инструменты скоринга качества карточек товаров для маркетплейсов
 * и AI-лечения неполных карточек.
 *
 * Команды:
 *   php yii quality/scan --channel=rosmatras     # Полный скоринг всех моделей
 *   php yii quality/scan --channel=1             # По ID канала
 *   php yii quality/report                       # Красивый отчёт по всем каналам
 *   php yii quality/report --channel=rosmatras   # Отчёт по одному каналу
 *   php yii quality/check --model=123            # Проверить конкретную модель
 *   php yii quality/requirements                 # Показать требования каналов
 *   php yii quality/heal --channel=rosmatras     # Fan-out: раздать лечение в очередь
 *   php yii quality/heal --limit=500             # Лимит моделей
 *   php yii quality/heal --dry-run               # Только показать, что будет лечиться
 *   php yii quality/heal --sync                  # Синхронный режим (старое поведение)
 */
class QualityController extends Controller
{
    /** @var string Драйвер канала или ID (для scan/report/heal) */
    public string $channel = '';

    /** @var int ID модели (для check) */
    public int $model = 0;

    /** @var int Топ N проблем в отчёте */
    public int $top = 15;

    /** @var int Лимит моделей для heal */
    public int $limit = 50;

    /** @var bool Dry-run — показать что будет лечиться, не лечить */
    public bool $dryRun = false;

    /** @var bool Синхронный режим (старое поведение: лечить в текущем процессе) */
    public bool $sync = false;

    /** @var ReadinessScoringService */
    private ReadinessScoringService $readinessService;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'channel', 'model', 'top', 'limit', 'dryRun', 'sync',
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
    // HEAL — AI Auto-Healing (Sprint 13)
    // ═══════════════════════════════════════════

    /**
     * AI-лечение неполных карточек товаров.
     *
     * По умолчанию — Fan-out: раздаёт HealModelJob в очередь для параллельного выполнения.
     * С --sync — старое поведение: лечит последовательно в текущем процессе.
     *
     * php yii quality/heal --channel=rosmatras              # Fan-out (мгновенно!)
     * php yii quality/heal --channel=rosmatras --limit=500  # 500 моделей в очередь
     * php yii quality/heal --channel=rosmatras --dry-run    # Показать, не лечить
     * php yii quality/heal --channel=rosmatras --sync       # Синхронный режим
     *
     * Для параллельного выполнения запустите несколько воркеров:
     *   php yii queue/listen --verbose & php yii queue/listen --verbose &
     *   Или через Supervisor: numprocs=5
     */
    public function actionHeal(): int
    {
        $channel = $this->resolveChannel();
        if (!$channel) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n  ╔══════════════════════════════════════════════════════════════════════╗\n", Console::FG_PURPLE);
        $this->stdout("  ║  🧬 AI AUTO-HEALING — Самовосстановление каталога                   ║\n", Console::FG_PURPLE);
        $this->stdout("  ╚══════════════════════════════════════════════════════════════════════╝\n\n", Console::FG_PURPLE);

        $this->stdout("  Канал:     {$channel->name} ({$channel->driver})\n");
        $this->stdout("  Лимит:     {$this->limit} моделей\n");
        $mode = $this->dryRun ? 'Dry-run (только анализ)' : ($this->sync ? 'Синхронный (в текущем процессе)' : 'Fan-out (через очередь)');
        $this->stdout("  Режим:     {$mode}\n\n");

        /** @var AutoHealingService $healer */
        $healer = Yii::$app->get('autoHealer');

        // Проверяем доступность AI
        /** @var \common\services\AIService $ai */
        $ai = Yii::$app->get('aiService');
        if (!$ai->isAvailable() && !$this->dryRun) {
            $this->stderr("  ❌ AI сервис недоступен (нет API ключа OpenRouter)\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("  AI модель: {$ai->model}\n\n");

        // ═══ Выбираем кандидатов ═══
        $healableCandidates = $this->findHealCandidates($channel, $healer);
        $totalCandidates = count($healableCandidates);

        if ($totalCandidates === 0) {
            $this->stdout("  ℹ️  Нет моделей для лечения (все уже лечились или нужны только фото/цены).\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("  Найдено кандидатов: {$totalCandidates}\n");

        // ═══ Dry-run ═══
        if ($this->dryRun) {
            return $this->printDryRun($healableCandidates);
        }

        // ═══ Синхронный режим (--sync) ═══
        if ($this->sync) {
            return $this->healSync($healableCandidates, $channel, $healer);
        }

        // ═══ Fan-out: раздаём HealModelJob в очередь ═══
        return $this->healFanOut($healableCandidates, $channel);
    }

    /**
     * Fan-out: раздать HealModelJob в очередь для параллельного выполнения.
     */
    private function healFanOut(array $candidates, SalesChannel $channel): int
    {
        $this->stdout("\n  Раздаём задачи в очередь...\n\n");

        $pushed = 0;
        foreach ($candidates as $row) {
            Yii::$app->queue->push(new HealModelJob([
                'modelId'       => (int)$row['model_id'],
                'channelId'     => $channel->id,
                'missingFields' => $row['missing'],
            ]));
            $pushed++;
        }

        $this->stdout("  ╔══════════════════════════════════════════════════════════════╗\n", Console::FG_GREEN);
        $this->stdout("  ║  FAN-OUT ЗАВЕРШЁН                                           ║\n", Console::FG_GREEN);
        $this->stdout("  ╚══════════════════════════════════════════════════════════════╝\n\n", Console::FG_GREEN);

        $this->stdout("  В очередь на лечение добавлено: ");
        $this->stdout("{$pushed} моделей\n\n", Console::FG_GREEN, Console::BOLD);

        $this->stdout("  Для параллельного выполнения запустите воркеры:\n\n", Console::BOLD);
        $this->stdout("    # Один воркер:\n");
        $this->stdout("    php yii queue/listen --verbose\n\n");
        $this->stdout("    # 5 параллельных воркеров:\n");
        $this->stdout("    for i in \$(seq 1 5); do php yii queue/listen --verbose & done\n\n");
        $this->stdout("    # Через Supervisor (рекомендуется):\n");
        $this->stdout("    [program:heal-worker]\n");
        $this->stdout("    command=php /app/yii queue/listen --verbose\n");
        $this->stdout("    numprocs=5\n");
        $this->stdout("    process_name=%(program_name)s_%(process_num)02d\n\n");

        $this->stdout("  Мониторинг:\n");
        $this->stdout("    php yii queue/info\n\n");

        return ExitCode::OK;
    }

    /**
     * Синхронный режим лечения (старое поведение).
     */
    private function healSync(array $candidates, SalesChannel $channel, AutoHealingService $healer): int
    {
        $totalCandidates = count($candidates);

        $this->stdout("\n");
        Console::startProgress(0, $totalCandidates, '  Лечим: ');

        $healed = 0;
        $pushed = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];

        foreach ($candidates as $i => $row) {
            $modelId = (int)$row['model_id'];

            try {
                $result = $healer->healModel($modelId, $row['missing'], $channel);

                if ($result->success) {
                    $healed++;
                    if ($result->isFullyHealed()) {
                        $pushed++;
                    }
                } else {
                    if (!empty($result->errors)) {
                        $failed++;
                        $errors[] = "#{$modelId}: " . implode('; ', $result->errors);
                    } else {
                        $skipped++;
                    }
                }
            } catch (\common\exceptions\AiRateLimitException $e) {
                // Rate Limit — ждём и продолжаем
                $this->stderr("\n  ⏳ Rate Limit (HTTP {$e->httpCode}), пауза {$e->retryAfterSec}s...\n", Console::FG_YELLOW);
                sleep($e->retryAfterSec);

                // Повторяем текущую модель
                try {
                    $result = $healer->healModel($modelId, $row['missing'], $channel);
                    if ($result->success) {
                        $healed++;
                        if ($result->isFullyHealed()) {
                            $pushed++;
                        }
                    } else {
                        $failed++;
                    }
                } catch (\Throwable $retryEx) {
                    $failed++;
                    $errors[] = "#{$modelId}: Retry failed — {$retryEx->getMessage()}";
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "#{$modelId}: Exception — {$e->getMessage()}";
                Yii::error("AutoHealing exception model_id={$modelId}: {$e->getMessage()}", 'ai.healing');

                // Если API упал — прерываем
                if (stripos($e->getMessage(), 'cURL error') !== false
                    || stripos($e->getMessage(), 'Connection') !== false) {
                    Console::endProgress();
                    $this->stderr("\n\n  ⚠️  API недоступен, прерываем лечение.\n", Console::FG_RED);
                    $this->stderr("  Ошибка: {$e->getMessage()}\n\n", Console::FG_RED);
                    break;
                }
            }

            Console::updateProgress($i + 1, $totalCandidates);
        }

        Console::endProgress();

        // ═══ ИТОГИ ═══
        $this->stdout("\n  ╔══════════════════════════════════════════════════════════════╗\n", Console::FG_GREEN);
        $this->stdout("  ║  РЕЗУЛЬТАТЫ ЛЕЧЕНИЯ (синхронный режим)                      ║\n", Console::FG_GREEN);
        $this->stdout("  ╚══════════════════════════════════════════════════════════════╝\n\n", Console::FG_GREEN);

        $this->stdout("  Обработано:       {$totalCandidates}\n");
        $this->stdout("  Исцелено:         ");
        $this->stdout("{$healed}\n", $healed > 0 ? Console::FG_GREEN : Console::FG_YELLOW);
        $this->stdout("  → Отправлено:     ");
        $this->stdout("{$pushed} (на витрину)\n", $pushed > 0 ? Console::FG_GREEN : Console::FG_YELLOW);
        $this->stdout("  Пропущено:        {$skipped}\n");
        $this->stdout("  Ошибки:           ");
        $this->stdout("{$failed}\n", $failed > 0 ? Console::FG_RED : Console::FG_GREEN);

        if (!empty($errors)) {
            $this->stdout("\n  ── Ошибки ──\n", Console::FG_RED);
            foreach (array_slice($errors, 0, 10) as $err) {
                $this->stdout("    • {$err}\n");
            }
            if (count($errors) > 10) {
                $this->stdout("    ... и ещё " . (count($errors) - 10) . " ошибок\n");
            }
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Вылечить одну конкретную модель.
     *
     * php yii quality/heal-one --model=123 --channel=rosmatras
     */
    public function actionHealOne(): int
    {
        if (!$this->model) {
            $this->stderr("\n  Укажите --model=ID и --channel=DRIVER\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $channel = $this->resolveChannel();
        if (!$channel) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n  ╔══════════════════════════════════════════════════════════════════════╗\n", Console::FG_PURPLE);
        $this->stdout("  ║  🧬 AI HEAL-ONE — Лечение конкретной модели                         ║\n", Console::FG_PURPLE);
        $this->stdout("  ╚══════════════════════════════════════════════════════════════════════╝\n\n", Console::FG_PURPLE);

        // Получаем текущий readiness
        $report = $this->readinessService->evaluate($this->model, $channel, true);

        $this->stdout("  Модель:     #{$this->model}\n");
        $this->stdout("  Канал:      {$channel->name}\n");
        $this->stdout("  Готовность: " . ($report->isReady ? '✅ ГОТОВА' : '❌ НЕ ГОТОВА') . " ({$report->score}%)\n");

        if ($report->isReady) {
            $this->stdout("\n  Модель уже готова, лечение не требуется.\n\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stdout("  Пропуски:\n");
        foreach ($report->missing as $field) {
            $label = ReadinessReportDTO::labelFor($field);
            $this->stdout("    • {$label}\n");
        }

        /** @var AutoHealingService $healer */
        $healer = Yii::$app->get('autoHealer');

        if (!$healer->hasHealableFields($report->missing)) {
            $this->stdout("\n  ⚠️  Нет лечимых полей (нужны фото/штрихкод/цена/бренд).\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("\n  Запускаем AI лечение...\n\n");

        $result = $healer->healModel($this->model, $report->missing, $channel);

        // Результат
        if ($result->success) {
            $this->stdout("  ✅ Лечение успешно!\n\n", Console::FG_GREEN);

            if (!empty($result->healedFields)) {
                $this->stdout("  Исцелённые поля:\n", Console::FG_GREEN);
                foreach ($result->healedFields as $field) {
                    $this->stdout("    ✓ {$field}\n", Console::FG_GREEN);
                }
            }

            if ($result->description) {
                $this->stdout("\n  Сгенерированное описание:\n", Console::BOLD);
                $this->stdout("  " . str_repeat('─', 60) . "\n");
                // Показываем первые 300 символов
                $preview = mb_substr($result->description, 0, 300);
                $this->stdout("  {$preview}...\n");
                $this->stdout("  " . str_repeat('─', 60) . "\n");
                $this->stdout("  Длина: " . mb_strlen($result->description) . " символов\n");
            }

            if (!empty($result->attributes)) {
                $this->stdout("\n  Определённые атрибуты:\n", Console::BOLD);
                foreach ($result->attributes as $key => $value) {
                    $this->stdout("    {$key}: {$value}\n");
                }
            }

            $this->stdout("\n  Новый скор: {$result->newScore}%\n");
            if ($result->isFullyHealed()) {
                $this->stdout("  🚀 Модель отправлена на витрину через Outbox!\n", Console::FG_GREEN);
            }
        } else {
            $this->stdout("  ❌ Лечение не удалось.\n\n", Console::FG_RED);
            foreach ($result->errors as $err) {
                $this->stdout("    • {$err}\n", Console::FG_RED);
            }
        }

        if (!empty($result->skippedFields)) {
            $this->stdout("\n  Пропущенные (нельзя лечить ИИ):\n", Console::FG_YELLOW);
            foreach ($result->skippedFields as $field) {
                $label = ReadinessReportDTO::labelFor($field);
                $this->stdout("    ⏭ {$label}\n", Console::FG_YELLOW);
            }
        }

        if (!empty($result->failedFields)) {
            $this->stdout("\n  Не удалось определить:\n", Console::FG_RED);
            foreach ($result->failedFields as $field) {
                $this->stdout("    ✗ {$field}\n", Console::FG_RED);
            }
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    // ═══════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════

    /**
     * Найти кандидатов для AI-лечения.
     *
     * @return array [['model_id', 'score', 'missing_fields', 'model_name', 'brand_name', 'missing' => [...]]]
     */
    private function findHealCandidates(SalesChannel $channel, AutoHealingService $healer): array
    {
        $db = Yii::$app->db;
        $cooldownInterval = $healer->healCooldownSeconds;

        $candidates = $db->createCommand("
            SELECT mcr.model_id, mcr.score, mcr.missing_fields,
                   pm.name AS model_name, pm.product_family,
                   b.canonical_name AS brand_name
            FROM {{%model_channel_readiness}} mcr
            JOIN {{%product_models}} pm ON pm.id = mcr.model_id
            LEFT JOIN {{%brands}} b ON b.id = pm.brand_id
            WHERE mcr.channel_id = :cid
              AND mcr.is_ready = false
              AND (mcr.last_heal_attempt_at IS NULL OR mcr.last_heal_attempt_at < NOW() - INTERVAL '{$cooldownInterval} seconds')
            ORDER BY mcr.score DESC, mcr.model_id
            LIMIT :limit
        ", [':cid' => $channel->id, ':limit' => $this->limit * 3])->queryAll();

        $healableCandidates = [];
        foreach ($candidates as $row) {
            $missing = $this->parseJson($row['missing_fields']);
            if ($healer->hasHealableFields($missing)) {
                $healableCandidates[] = array_merge($row, ['missing' => $missing]);
            }
            if (count($healableCandidates) >= $this->limit) {
                break;
            }
        }

        return $healableCandidates;
    }

    /**
     * Dry-run: показать кандидатов, не лечить.
     */
    private function printDryRun(array $candidates): int
    {
        $this->stdout("\n  ── Кандидаты для лечения (dry-run) ──\n\n", Console::FG_YELLOW);
        $this->stdout(sprintf("  %-6s %-40s %-12s %s\n", 'ID', 'Название', 'Скор', 'Чего не хватает'), Console::BOLD);
        $this->stdout("  " . str_repeat('─', 90) . "\n");

        foreach ($candidates as $row) {
            $healableFields = array_filter($row['missing'], fn($f) => !$this->isUnhealableField($f));
            $fieldsStr = implode(', ', array_map(fn($f) => ReadinessReportDTO::labelFor($f), array_slice($healableFields, 0, 3)));
            if (count($healableFields) > 3) {
                $fieldsStr .= ' +' . (count($healableFields) - 3);
            }

            $this->stdout(sprintf(
                "  %-6d %-40s %3d%%        %s\n",
                (int)$row['model_id'],
                mb_substr($row['model_name'], 0, 38),
                (int)$row['score'],
                $fieldsStr
            ));
        }

        $this->stdout("\n  Для запуска лечения уберите --dry-run\n\n");
        return ExitCode::OK;
    }

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
     * Безопасный парсинг JSON.
     */
    private function parseJson($value): array
    {
        if (empty($value)) return [];
        if (is_string($value)) return json_decode($value, true) ?: [];
        return is_array($value) ? $value : [];
    }

    /**
     * Проверка, что поле не лечится ИИ (для dry-run вывода).
     */
    private function isUnhealableField(string $field): bool
    {
        return in_array($field, [
            'required:image', 'required:barcode', 'required:price', 'required:brand',
        ]);
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
