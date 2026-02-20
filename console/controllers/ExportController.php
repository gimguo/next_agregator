<?php

namespace console\controllers;

use common\services\OutboxService;
use common\services\RosMatrasSyndicationService;
use common\services\marketplace\MarketplaceApiClientInterface;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use Yii;

/**
 * Экспорт данных на витрину (Syndication Worker).
 *
 * Обрабатывает очередь marketplace_outbox:
 *   1. Забирает pending-события (SELECT FOR UPDATE SKIP LOCKED)
 *   2. Группирует по model_id (чтобы не гонять одну модель 5 раз)
 *   3. Для каждой уникальной модели строит полную проекцию
 *   4. Отправляет через MarketplaceApiClientInterface
 *   5. Помечает success / error
 *
 * Запуск:
 *   php yii export/process-outbox          # Одна итерация
 *   php yii export/process-outbox --batch=200  # Больший батч
 *   php yii export/daemon                  # Бесконечный цикл (для крона/systemd)
 *   php yii export/status                  # Статистика очереди
 *   php yii export/retry-errors            # Повторить ошибки
 *   php yii export/preview --model=123     # Предпросмотр проекции
 */
class ExportController extends Controller
{
    /** @var int Размер батча (сколько outbox-записей забирать за раз) */
    public int $batch = 100;

    /** @var int ID модели для предпросмотра */
    public int $model = 0;

    /** @var int Интервал между итерациями daemon (секунды) */
    public int $interval = 10;

    /** @var int Максимальное кол-во ретраев */
    public int $maxRetries = 3;

    /** @var OutboxService */
    private OutboxService $outbox;

    /** @var RosMatrasSyndicationService */
    private RosMatrasSyndicationService $syndicator;

    /** @var MarketplaceApiClientInterface */
    private MarketplaceApiClientInterface $client;

    public function init(): void
    {
        parent::init();
        $this->outbox = Yii::$app->get('outbox');
        $this->syndicator = Yii::$app->get('syndicationService');
        $this->client = Yii::$app->get('marketplaceClient');
    }

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'batch', 'model', 'interval', 'maxRetries',
        ]);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'b' => 'batch',
            'm' => 'model',
        ]);
    }

    // ═══════════════════════════════════════════
    // ОСНОВНЫЕ КОМАНДЫ
    // ═══════════════════════════════════════════

    /**
     * Обработать очередь outbox (одна итерация).
     *
     * Использование:
     *   php yii export/process-outbox
     *   php yii export/process-outbox --batch=200
     */
    public function actionProcessOutbox(): int
    {
        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   EXPORT: Обработка Outbox               ║\n", Console::FG_CYAN);
        $this->stdout("╚══════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $startTime = microtime(true);

        // 1. Забираем pending-события, сгруппированные по model_id
        $grouped = $this->outbox->fetchPendingBatch($this->batch);

        if (empty($grouped)) {
            $this->stdout("  ✓ Очередь пуста — нечего экспортировать.\n\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $modelCount = count($grouped);
        $eventCount = array_sum(array_map('count', $grouped));

        $this->stdout("  → Забрали {$eventCount} событий для {$modelCount} моделей\n\n", Console::FG_YELLOW);

        $successModels = 0;
        $errorModels = 0;
        $totalOutboxIds = [];

        // 2. Для каждой уникальной модели — строим проекцию и отправляем
        foreach ($grouped as $modelId => $events) {
            $outboxIds = array_column($events, 'id');
            $eventTypes = array_unique(array_column($events, 'event_type'));

            $this->stdout("  [model_id={$modelId}] ", Console::FG_CYAN);
            $this->stdout(count($events) . " событий (" . implode(', ', $eventTypes) . ") ", Console::FG_GREY);

            try {
                // Строим проекцию
                $projection = $this->syndicator->buildProductProjection($modelId);

                if (!$projection) {
                    // Модель не найдена или неактивна — помечаем success (нечего отправлять)
                    $this->outbox->markSuccess($outboxIds);
                    $this->stdout("→ SKIP (модель не найдена)\n", Console::FG_YELLOW);
                    $totalOutboxIds = array_merge($totalOutboxIds, $outboxIds);
                    continue;
                }

                // Добавляем контекст событий в проекцию
                $projection['_outbox_events'] = array_map(fn($e) => [
                    'event_type' => $e['event_type'],
                    'entity_type' => $e['entity_type'],
                ], $events);

                // Отправляем на витрину
                $result = $this->client->pushProduct($modelId, $projection);

                if ($result) {
                    $this->outbox->markSuccess($outboxIds);
                    $successModels++;
                    $this->stdout("→ OK", Console::FG_GREEN);
                    $this->stdout(" ({$projection['name']}, {$projection['variant_count']} вариантов, " .
                        ($projection['best_price'] ? number_format($projection['best_price'], 0, '.', ' ') . '₽' : 'N/A') . ")\n");
                } else {
                    $this->outbox->markError($outboxIds, 'pushProduct returned false');
                    $errorModels++;
                    $this->stdout("→ ERROR (client returned false)\n", Console::FG_RED);
                }

                $totalOutboxIds = array_merge($totalOutboxIds, $outboxIds);

            } catch (\Throwable $e) {
                $this->outbox->markError($outboxIds, $e->getMessage());
                $errorModels++;
                $totalOutboxIds = array_merge($totalOutboxIds, $outboxIds);
                $this->stdout("→ ERROR: " . $e->getMessage() . "\n", Console::FG_RED);
                Yii::error(
                    "Export error for model_id={$modelId}: " . $e->getMessage(),
                    'marketplace.export'
                );
            }
        }

        // 3. Итоговый отчёт
        $duration = round(microtime(true) - $startTime, 2);

        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   РЕЗУЛЬТАТ ЭКСПОРТА                     ║\n", Console::FG_CYAN);
        $this->stdout("╠══════════════════════════════════════════╣\n", Console::FG_CYAN);
        $this->stdout("║ Моделей обработано:  " . str_pad($modelCount, 19) . "║\n");
        $this->stdout("║ Событий обработано:  " . str_pad(count($totalOutboxIds), 19) . "║\n");
        $this->stdout("║ Успешно:             " . str_pad($successModels, 19) . "║\n", Console::FG_GREEN);
        $this->stdout("║ Ошибки:              " . str_pad($errorModels, 19) . "║\n", $errorModels > 0 ? Console::FG_RED : Console::FG_GREEN);
        $this->stdout("║ Время:               " . str_pad($duration . 's', 19) . "║\n");
        $this->stdout("╚══════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        return $errorModels > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Бесконечный цикл обработки outbox (daemon mode).
     *
     * Использование:
     *   php yii export/daemon
     *   php yii export/daemon --interval=30 --batch=200
     */
    public function actionDaemon(): int
    {
        $this->stdout("\n  EXPORT DAEMON STARTED (interval={$this->interval}s, batch={$this->batch})\n", Console::FG_GREEN);
        $this->stdout("  Press Ctrl+C to stop.\n\n");

        $iteration = 0;
        while (true) {
            $iteration++;
            $startTime = microtime(true);

            // Забираем батч
            $grouped = $this->outbox->fetchPendingBatch($this->batch);

            if (!empty($grouped)) {
                $eventCount = array_sum(array_map('count', $grouped));
                $modelCount = count($grouped);

                $this->stdout(
                    "  [" . date('H:i:s') . "] Iteration #{$iteration}: " .
                    "{$eventCount} events, {$modelCount} models",
                    Console::FG_YELLOW
                );

                $success = 0;
                $errors = 0;

                foreach ($grouped as $modelId => $events) {
                    $outboxIds = array_column($events, 'id');

                    try {
                        $projection = $this->syndicator->buildProductProjection($modelId);

                        if (!$projection) {
                            $this->outbox->markSuccess($outboxIds);
                            continue;
                        }

                        $result = $this->client->pushProduct($modelId, $projection);

                        if ($result) {
                            $this->outbox->markSuccess($outboxIds);
                            $success++;
                        } else {
                            $this->outbox->markError($outboxIds, 'pushProduct returned false');
                            $errors++;
                        }
                    } catch (\Throwable $e) {
                        $this->outbox->markError($outboxIds, $e->getMessage());
                        $errors++;
                    }
                }

                $duration = round(microtime(true) - $startTime, 2);
                $this->stdout(" → OK:{$success} ERR:{$errors} ({$duration}s)\n",
                    $errors > 0 ? Console::FG_RED : Console::FG_GREEN
                );
            } else {
                // Тишина — не спамим в лог
                if ($iteration % 6 === 0) { // Каждую минуту (при interval=10)
                    $this->stdout(
                        "  [" . date('H:i:s') . "] Idle, queue empty.\n",
                        Console::FG_GREY
                    );
                }
            }

            sleep($this->interval);
        }
    }

    // ═══════════════════════════════════════════
    // УТИЛИТЫ
    // ═══════════════════════════════════════════

    /**
     * Показать статистику Outbox.
     *
     * Использование:
     *   php yii export/status
     */
    public function actionStatus(): int
    {
        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   OUTBOX QUEUE STATUS                    ║\n", Console::FG_CYAN);
        $this->stdout("╚══════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $stats = $this->outbox->getQueueStats();

        $this->stdout("  Pending:    " . $stats['pending'] . "\n",
            $stats['pending'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN);
        $this->stdout("  Processing: " . $stats['processing'] . "\n",
            $stats['processing'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN);
        $this->stdout("  Success:    " . $stats['success'] . "\n", Console::FG_GREEN);
        $this->stdout("  Error:      " . $stats['error'] . "\n",
            $stats['error'] > 0 ? Console::FG_RED : Console::FG_GREEN);

        $total = array_sum($stats);
        $this->stdout("\n  Total: {$total}\n\n");

        // Последние события
        $db = Yii::$app->db;

        $recent = $db->createCommand("
            SELECT entity_type, event_type, count(*) as cnt
            FROM {{%marketplace_outbox}}
            WHERE created_at > NOW() - INTERVAL '1 hour'
            GROUP BY entity_type, event_type
            ORDER BY cnt DESC
        ")->queryAll();

        if (!empty($recent)) {
            $this->stdout("  События за последний час:\n", Console::FG_CYAN);
            foreach ($recent as $row) {
                $this->stdout("    {$row['entity_type']}.{$row['event_type']}: {$row['cnt']}\n");
            }
            $this->stdout("\n");
        }

        // Уникальные модели в pending
        $pendingModels = $db->createCommand("
            SELECT COUNT(DISTINCT model_id) FROM {{%marketplace_outbox}} WHERE status = 'pending'
        ")->queryScalar();

        $this->stdout("  Уникальных моделей в pending: {$pendingModels}\n\n");

        return ExitCode::OK;
    }

    /**
     * Повторить обработку error-записей.
     *
     * Использование:
     *   php yii export/retry-errors
     *   php yii export/retry-errors --maxRetries=5
     */
    public function actionRetryErrors(): int
    {
        $retried = $this->outbox->retryErrors($this->maxRetries);

        if ($retried > 0) {
            $this->stdout("  ✓ Вернули {$retried} записей в pending для повторной обработки.\n\n", Console::FG_GREEN);
        } else {
            $this->stdout("  ✓ Нет записей для повторной обработки (или все исчерпали лимит ретраев).\n\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Предпросмотр проекции модели (не отправляет на витрину).
     *
     * Использование:
     *   php yii export/preview --model=123
     */
    public function actionPreview(): int
    {
        if (!$this->model) {
            $this->stderr("  ✗ Укажите --model=ID\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $this->stdout("\n  Строим проекцию для model_id={$this->model}...\n\n", Console::FG_CYAN);

        $projection = $this->syndicator->buildProductProjection($this->model);

        if (!$projection) {
            $this->stderr("  ✗ Модель не найдена или неактивна.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        // Красивый вывод
        $this->stdout("  Название: {$projection['name']}\n", Console::BOLD);
        $this->stdout("  Бренд:    " . ($projection['brand']['name'] ?? 'N/A') . "\n");
        $this->stdout("  Семейство: {$projection['product_family']}\n");
        $this->stdout("  Slug:     {$projection['slug']}\n");
        $this->stdout("  Цена:     " . ($projection['best_price'] ? number_format($projection['best_price'], 0, '.', ' ') . '₽' : 'N/A') . "\n");
        $this->stdout("  Диапазон: " .
            ($projection['price_range_min'] ? number_format($projection['price_range_min'], 0, '.', ' ') : '?') . " - " .
            ($projection['price_range_max'] ? number_format($projection['price_range_max'], 0, '.', ' ') : '?') . "₽\n");
        $this->stdout("  В наличии: " . ($projection['is_in_stock'] ? 'Да' : 'Нет') . "\n");
        $this->stdout("  Вариантов: {$projection['variant_count']}\n");
        $this->stdout("  Офферов:   {$projection['offer_count']}\n");
        $this->stdout("  Изображений: " . count($projection['images']) . "\n");

        // Selector Axes
        $axes = $projection['selector_axes'] ?? [];
        if (!empty($axes)) {
            $this->stdout("\n  Selector Axes:\n", Console::FG_CYAN);
            foreach ($axes as $key => $values) {
                if ($key === 'axis_combinations') {
                    $this->stdout("    Комбинаций: " . count($values) . "\n");
                } else {
                    $valStr = is_array($values) ? implode(', ', $values) : (string)$values;
                    $this->stdout("    {$key}: [{$valStr}]\n");
                }
            }
        }

        // Варианты
        $this->stdout("\n  Варианты:\n", Console::FG_CYAN);
        foreach ($projection['variants'] as $v) {
            $price = $v['best_price'] ? number_format($v['best_price'], 0, '.', ' ') . '₽' : 'N/A';
            $stock = $v['is_in_stock'] ? '✓' : '✗';
            $offers = count($v['offers']);
            $this->stdout("    [{$v['id']}] {$v['label']} — {$price} {$stock} ({$offers} офферов)\n");
        }

        // Полный JSON
        $this->stdout("\n  Полный JSON ({$this->formatBytes(strlen(json_encode($projection)))}): \n\n", Console::FG_GREY);
        $this->stdout(json_encode($projection, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n");

        return ExitCode::OK;
    }

    /**
     * Очистить старые success-записи из outbox.
     *
     * Использование:
     *   php yii export/cleanup
     */
    public function actionCleanup(): int
    {
        $deleted = $this->outbox->cleanupOld(7);
        $this->stdout("  ✓ Удалено {$deleted} старых success-записей.\n\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Сбросить stuck processing записи (если Worker упал).
     *
     * Использование:
     *   php yii export/reset-stuck
     */
    public function actionResetStuck(): int
    {
        $db = Yii::$app->db;

        $reset = $db->createCommand("
            UPDATE {{%marketplace_outbox}}
            SET status = 'pending'
            WHERE status = 'processing'
            AND created_at < NOW() - INTERVAL '10 minutes'
        ")->execute();

        if ($reset > 0) {
            $this->stdout("  ✓ Сброшено {$reset} stuck processing записей.\n\n", Console::FG_GREEN);
        } else {
            $this->stdout("  ✓ Нет stuck записей.\n\n", Console::FG_GREEN);
        }

        return ExitCode::OK;
    }

    // ═══════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }
}
