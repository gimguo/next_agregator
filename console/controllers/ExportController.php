<?php

namespace console\controllers;

use common\services\OutboxService;
use common\services\RosMatrasSyndicationService;
use common\services\marketplace\MarketplaceApiClientInterface;
use common\services\marketplace\MarketplaceUnavailableException;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use Yii;

/**
 * Экспорт данных на витрину RosMatras (Syndication Worker).
 *
 * Обрабатывает очередь marketplace_outbox:
 *   1. Забирает pending-события (SELECT FOR UPDATE SKIP LOCKED)
 *   2. Группирует по model_id
 *   3. Строит проекцию через RosMatrasSyndicationService
 *   4. Отправляет через RosMatrasApiClient
 *   5. Помечает success / error
 *
 * Устойчивость к сбоям:
 *   - MarketplaceUnavailableException → rollback записей в pending, экспоненциальная задержка
 *   - Обычные ошибки данных → error (не ретраить автоматически)
 *   - Daemon режим с graceful shutdown при недоступности API
 *
 * Команды:
 *   php yii export/process-outbox          # Одна итерация
 *   php yii export/daemon                  # Бесконечный цикл
 *   php yii export/push-all                # Полная синхронизация (Initial Seed)
 *   php yii export/sync-model --model=123  # Принудительная синхронизация модели
 *   php yii export/ping                    # Health-check API RosMatras
 *   php yii export/status                  # Статистика очереди
 *   php yii export/retry-errors            # Retry с exponential backoff
 *   php yii export/preview --model=123     # Предпросмотр проекции
 */
class ExportController extends Controller
{
    /** @var int Размер батча */
    public int $batch = 100;

    /** @var int ID модели (для preview/sync-model) */
    public int $model = 0;

    /** @var int Интервал daemon (секунды) */
    public int $interval = 10;

    /** @var int Максимальное кол-во ретраев */
    public int $maxRetries = 5;

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
     * При MarketplaceUnavailableException — записи возвращаются в pending,
     * команда завершается с ненулевым exit-кодом.
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
        $skippedModels = 0;

        // 2. Для каждой уникальной модели — строим проекцию и отправляем
        foreach ($grouped as $modelId => $events) {
            $outboxIds = array_column($events, 'id');
            $eventTypes = array_unique(array_column($events, 'event_type'));

            $this->stdout("  [model_id={$modelId}] ", Console::FG_CYAN);
            $this->stdout(count($events) . " событий (" . implode(', ', $eventTypes) . ") ");

            try {
                // Строим проекцию
                $projection = $this->syndicator->buildProductProjection($modelId);

                if (!$projection) {
                    $this->outbox->markSuccess($outboxIds);
                    $skippedModels++;
                    $this->stdout("→ SKIP (модель не найдена)\n", Console::FG_YELLOW);
                    continue;
                }

                // Добавляем контекст событий
                $projection['_outbox_events'] = array_map(fn($e) => [
                    'event_type'  => $e['event_type'],
                    'entity_type' => $e['entity_type'],
                ], $events);

                // Отправляем на витрину
                $result = $this->client->pushProduct($modelId, $projection);

                if ($result) {
                    $this->outbox->markSuccess($outboxIds);
                    $successModels++;
                    $this->stdout("→ OK", Console::FG_GREEN);
                    $this->stdout(" ({$projection['name']}, " .
                        "{$projection['variant_count']} вар., " .
                        ($projection['best_price'] ? number_format($projection['best_price'], 0, '.', ' ') . '₽' : 'N/A') .
                        ")\n");
                } else {
                    $this->outbox->markError($outboxIds, 'pushProduct returned false');
                    $errorModels++;
                    $this->stdout("→ ERROR (returned false)\n", Console::FG_RED);
                }

            } catch (MarketplaceUnavailableException $e) {
                // ═══ КРИТИЧНО: API недоступен ═══
                // Возвращаем ВСЕ оставшиеся записи (включая текущие) обратно в pending
                $this->rollbackToPending($outboxIds);

                // Возвращаем оставшиеся необработанные модели
                $this->rollbackRemainingModels($grouped, $modelId);

                $this->stdout("→ API UNAVAILABLE\n", Console::FG_RED);
                $this->stdout("\n  ⚠ API RosMatras недоступен: {$e->getMessage()}\n", Console::FG_RED);
                $this->stdout("  → Все незавершённые записи возвращены в pending.\n", Console::FG_YELLOW);
                $this->stdout("  → Worker завершает работу. Следующая попытка через cron.\n\n", Console::FG_YELLOW);

                Yii::error(
                    "Export worker: API unavailable, rolling back. Error: {$e->getMessage()}",
                    'marketplace.export'
                );

                return ExitCode::TEMPFAIL;

            } catch (\Throwable $e) {
                // Ошибка данных — помечаем error и продолжаем с остальными
                $this->outbox->markError($outboxIds, mb_substr($e->getMessage(), 0, 500));
                $errorModels++;
                $this->stdout("→ ERROR: {$e->getMessage()}\n", Console::FG_RED);

                Yii::error(
                    "Export error model_id={$modelId}: {$e->getMessage()}",
                    'marketplace.export'
                );
            }
        }

        // 3. Итоговый отчёт
        $duration = round(microtime(true) - $startTime, 2);

        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   РЕЗУЛЬТАТ ЭКСПОРТА                     ║\n", Console::FG_CYAN);
        $this->stdout("╠══════════════════════════════════════════╣\n", Console::FG_CYAN);
        $this->stdout("║ Моделей:    " . str_pad($modelCount, 28) . "║\n");
        $this->stdout("║ Успешно:    " . str_pad($successModels, 28) . "║\n", Console::FG_GREEN);
        $this->stdout("║ Пропущено:  " . str_pad($skippedModels, 28) . "║\n");
        $this->stdout("║ Ошибки:     " . str_pad($errorModels, 28) . "║\n",
            $errorModels > 0 ? Console::FG_RED : Console::FG_GREEN);
        $this->stdout("║ Время:      " . str_pad($duration . 's', 28) . "║\n");
        $this->stdout("╚══════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        return $errorModels > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Daemon-режим: бесконечный цикл обработки outbox.
     *
     * При MarketplaceUnavailableException:
     *   - Все записи возвращаются в pending
     *   - Экспоненциальная задержка перед следующей попыткой
     *   - Daemon продолжает работать (не умирает)
     */
    public function actionDaemon(): int
    {
        $this->stdout("\n  EXPORT DAEMON STARTED (interval={$this->interval}s, batch={$this->batch})\n", Console::FG_GREEN);
        $this->stdout("  Press Ctrl+C to stop.\n\n");

        $iteration = 0;
        $consecutiveApiFailures = 0;

        while (true) {
            $iteration++;
            $startTime = microtime(true);

            // Забираем батч
            $grouped = $this->outbox->fetchPendingBatch($this->batch);

            if (!empty($grouped)) {
                $eventCount = array_sum(array_map('count', $grouped));
                $modelCount = count($grouped);

                $this->stdout(
                    "  [" . date('H:i:s') . "] #{$iteration}: " .
                    "{$eventCount} events, {$modelCount} models",
                    Console::FG_YELLOW
                );

                $success = 0;
                $errors = 0;
                $apiDown = false;

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

                    } catch (MarketplaceUnavailableException $e) {
                        // API упал — rollback всех оставшихся и backoff
                        $this->rollbackToPending($outboxIds);
                        $this->rollbackRemainingModels($grouped, $modelId);
                        $apiDown = true;
                        $consecutiveApiFailures++;

                        Yii::error(
                            "Daemon: API unavailable (failure #{$consecutiveApiFailures}): {$e->getMessage()}",
                            'marketplace.export'
                        );
                        break;

                    } catch (\Throwable $e) {
                        $this->outbox->markError($outboxIds, mb_substr($e->getMessage(), 0, 500));
                        $errors++;
                    }
                }

                $duration = round(microtime(true) - $startTime, 2);

                if ($apiDown) {
                    // Экспоненциальная задержка
                    $backoffDelay = MarketplaceUnavailableException::calculateBackoff(
                        $consecutiveApiFailures, 10, 600
                    );

                    $this->stdout(
                        " → API DOWN (fail #{$consecutiveApiFailures}), backoff {$backoffDelay}s\n",
                        Console::FG_RED
                    );

                    sleep($backoffDelay);
                    continue;
                }

                // API работает — сбрасываем счётчик неудач
                $consecutiveApiFailures = 0;

                $this->stdout(
                    " → OK:{$success} ERR:{$errors} ({$duration}s)\n",
                    $errors > 0 ? Console::FG_YELLOW : Console::FG_GREEN
                );
            } else {
                // Тишина
                if ($iteration % 6 === 0) {
                    $this->stdout(
                        "  [" . date('H:i:s') . "] Idle, queue empty.\n",
                        Console::FG_GREY
                    );
                }
                // Если были failures — постепенно сбрасываем
                if ($consecutiveApiFailures > 0) {
                    $consecutiveApiFailures = max(0, $consecutiveApiFailures - 1);
                }
            }

            sleep($this->interval);
        }
    }

    // ═══════════════════════════════════════════
    // FULL SYNC / INITIAL SEED
    // ═══════════════════════════════════════════

    /**
     * Полная синхронизация ВСЕХ активных моделей на витрину (Initial Seed / Mass Push).
     *
     * Логика:
     *   1. Забирает все active product_models из БД батчами по 100 (экономия памяти)
     *   2. Для каждого батча строит проекции через RosMatrasSyndicationService
     *   3. Группирует по 50 и отправляет через RosMatrasApiClient::pushBatch()
     *   4. Выводит красивый прогресс-бар
     *
     * Использование:
     *   php yii export/push-all                   # Все активные модели
     *   php yii export/push-all --batch=50        # Размер батча DB-запроса
     *
     * Сценарии:
     *   - Initial Seed: пустая витрина → заливаем весь эталонный каталог
     *   - Re-sync: после обновления схемы проекции
     *   - Recovery: после потери данных на витрине
     */
    public function actionPushAll(): int
    {
        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   FULL SYNC: Все модели → РосМатрас      ║\n", Console::FG_CYAN);
        $this->stdout("╚══════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        // 1. Проверяем доступность API
        $this->stdout("  1. Health check API... ", Console::FG_CYAN);
        try {
            $healthy = $this->client->healthCheck();
            if (!$healthy) {
                $this->stderr("FAIL — API не прошёл health check.\n\n", Console::FG_RED);
                return ExitCode::UNAVAILABLE;
            }
            $this->stdout("OK ✓\n\n", Console::FG_GREEN);
        } catch (\Throwable $e) {
            $this->stderr("FAIL ✗ ({$e->getMessage()})\n", Console::FG_RED);
            $this->stderr("  API недоступен. Сначала убедитесь, что витрина запущена.\n\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }

        // 2. Считаем общее кол-во моделей
        $db = Yii::$app->db;
        $totalModels = (int)$db->createCommand(
            "SELECT COUNT(*) FROM {{%product_models}} WHERE status = 'active'"
        )->queryScalar();

        if ($totalModels === 0) {
            $this->stdout("  ✓ Нет активных моделей для синхронизации.\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("  2. Найдено моделей: {$totalModels}\n\n", Console::FG_YELLOW);

        // 3. Настройки
        $dbBatchSize = $this->batch;    // Размер батча для DB-запроса (по умолчанию 100)
        $pushBatchSize = 50;            // Размер батча для API pushBatch

        $startTime = microtime(true);
        $processed = 0;
        $successCount = 0;
        $errorCount = 0;
        $skippedCount = 0;
        $apiFailures = 0;

        // 4. Начинаем прогресс-бар
        Console::startProgress(0, $totalModels, '  Синхронизация: ', 60);

        // 5. Основной цикл — батчи из БД
        $offset = 0;

        while (true) {
            $modelRows = $db->createCommand("
                SELECT id FROM {{%product_models}}
                WHERE status = 'active'
                ORDER BY id ASC
                LIMIT :limit OFFSET :offset
            ", [':limit' => $dbBatchSize, ':offset' => $offset])->queryColumn();

            if (empty($modelRows)) {
                break; // Все модели обработаны
            }

            $offset += count($modelRows);

            // Строим проекции для текущего DB-батча
            $projections = [];
            foreach ($modelRows as $modelId) {
                try {
                    $projection = $this->syndicator->buildProductProjection((int)$modelId);

                    if ($projection) {
                        $projections[(int)$modelId] = $projection;
                    } else {
                        $skippedCount++;
                    }
                } catch (\Throwable $e) {
                    $errorCount++;
                    Yii::error(
                        "PushAll: projection error model_id={$modelId}: {$e->getMessage()}",
                        'marketplace.export'
                    );
                }

                $processed++;
                Console::updateProgress($processed, $totalModels);

                // Отправляем, когда накопили pushBatchSize проекций
                if (count($projections) >= $pushBatchSize) {
                    $pushResult = $this->pushBatchSafe($projections, $apiFailures);
                    $successCount += $pushResult['success'];
                    $errorCount += $pushResult['errors'];
                    $apiFailures = $pushResult['apiFailures'];
                    $projections = [];

                    // Если API упал 3 раза подряд — прерываем
                    if ($apiFailures >= 3) {
                        Console::endProgress("ABORTED\n");
                        $this->stderr("\n  ⚠ API недоступен (3 последовательных ошибки). Прерываем.\n", Console::FG_RED);
                        $this->printPushAllReport($totalModels, $processed, $successCount, $errorCount, $skippedCount, $startTime);
                        return ExitCode::TEMPFAIL;
                    }
                }
            }

            // Отправляем остаток проекций после DB-батча
            if (!empty($projections)) {
                $pushResult = $this->pushBatchSafe($projections, $apiFailures);
                $successCount += $pushResult['success'];
                $errorCount += $pushResult['errors'];
                $apiFailures = $pushResult['apiFailures'];

                if ($apiFailures >= 3) {
                    Console::endProgress("ABORTED\n");
                    $this->stderr("\n  ⚠ API недоступен (3 последовательных ошибки). Прерываем.\n", Console::FG_RED);
                    $this->printPushAllReport($totalModels, $processed, $successCount, $errorCount, $skippedCount, $startTime);
                    return ExitCode::TEMPFAIL;
                }
            }

            // Освобождаем память
            gc_collect_cycles();
        }

        Console::endProgress("OK ✓\n");

        // 6. Итоговый отчёт
        $this->printPushAllReport($totalModels, $processed, $successCount, $errorCount, $skippedCount, $startTime);

        return $errorCount > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Безопасная отправка батча проекций через API.
     * При MarketplaceUnavailableException — подождёт и увеличит счётчик ошибок.
     */
    private function pushBatchSafe(array $projections, int $apiFailures): array
    {
        $success = 0;
        $errors = 0;

        try {
            $results = $this->client->pushBatch($projections);

            foreach ($results as $modelId => $ok) {
                if ($ok) {
                    $success++;
                } else {
                    $errors++;
                }
            }

            // Успешный запрос — сбрасываем счётчик неудач
            $apiFailures = 0;

        } catch (MarketplaceUnavailableException $e) {
            $apiFailures++;
            $errors += count($projections);

            // Ждём с экспоненциальной задержкой
            $backoff = MarketplaceUnavailableException::calculateBackoff($apiFailures, 5, 60);
            Yii::warning(
                "PushAll: API unavailable (fail #{$apiFailures}), backoff {$backoff}s: {$e->getMessage()}",
                'marketplace.export'
            );
            sleep($backoff);

        } catch (\Throwable $e) {
            $errors += count($projections);
            Yii::error(
                "PushAll: batch error: {$e->getMessage()}",
                'marketplace.export'
            );
        }

        return [
            'success' => $success,
            'errors' => $errors,
            'apiFailures' => $apiFailures,
        ];
    }

    /**
     * Красивый итоговый отчёт для push-all.
     */
    private function printPushAllReport(
        int $totalModels, int $processed, int $successCount,
        int $errorCount, int $skippedCount, float $startTime
    ): void {
        $duration = round(microtime(true) - $startTime, 1);
        $speed = $processed > 0 && $duration > 0 ? round($processed / $duration, 1) : 0;

        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   РЕЗУЛЬТАТ ПОЛНОЙ СИНХРОНИЗАЦИИ         ║\n", Console::FG_CYAN);
        $this->stdout("╠══════════════════════════════════════════╣\n", Console::FG_CYAN);
        $this->stdout("║ Всего моделей:      " . str_pad($totalModels, 20) . "║\n");
        $this->stdout("║ Обработано:         " . str_pad($processed, 20) . "║\n");
        $this->stdout("║ Отправлено успешно: " . str_pad($successCount, 20) . "║\n", Console::FG_GREEN);
        $this->stdout("║ Пропущено:          " . str_pad($skippedCount, 20) . "║\n");
        $this->stdout("║ Ошибки:             " . str_pad($errorCount, 20) . "║\n",
            $errorCount > 0 ? Console::FG_RED : Console::FG_GREEN);
        $this->stdout("║ Время:              " . str_pad("{$duration}s ({$speed} м/с)", 20) . "║\n");
        $this->stdout("╚══════════════════════════════════════════╝\n\n", Console::FG_CYAN);
    }

    // ═══════════════════════════════════════════
    // E2E TESTING / ОТЛАДКА
    // ═══════════════════════════════════════════

    /**
     * Принудительная синхронизация одной модели (мимо Outbox).
     *
     * Использование:
     *   php yii export/sync-model --model=123
     *
     * Строит проекцию и отправляет напрямую через RosMatrasApiClient.
     * Полезно для отладки одной карточки.
     */
    public function actionSyncModel(): int
    {
        if (!$this->model) {
            $this->stderr("  ✗ Укажите --model=ID\n\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   SYNC MODEL (E2E Test)                  ║\n", Console::FG_CYAN);
        $this->stdout("╚══════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        // 1. Проверяем доступность API
        $this->stdout("  1. Health check API... ", Console::FG_CYAN);
        try {
            $healthy = $this->client->healthCheck();
            if ($healthy) {
                $this->stdout("OK ✓\n", Console::FG_GREEN);
            } else {
                $this->stdout("WARN (health returned false, продолжаем...)\n", Console::FG_YELLOW);
            }
        } catch (\Throwable $e) {
            $this->stdout("FAIL ✗\n", Console::FG_RED);
            $this->stdout("     Ошибка: {$e->getMessage()}\n", Console::FG_RED);
            $this->stdout("     Продолжаем всё равно...\n\n", Console::FG_YELLOW);
        }

        // 2. Строим проекцию
        $this->stdout("  2. Строим проекцию model_id={$this->model}... ", Console::FG_CYAN);

        $projection = $this->syndicator->buildProductProjection($this->model);

        if (!$projection) {
            $this->stderr("FAIL — модель не найдена или неактивна.\n\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->stdout("OK ✓\n", Console::FG_GREEN);
        $this->stdout("     Название: {$projection['name']}\n");
        $this->stdout("     Вариантов: {$projection['variant_count']}\n");
        $this->stdout("     Цена: " . ($projection['best_price']
                ? number_format($projection['best_price'], 0, '.', ' ') . '₽'
                : 'N/A') . "\n");
        $this->stdout("     Изображений: " . count($projection['images']) . "\n");
        $this->stdout("     Размер JSON: " . $this->formatBytes(strlen(json_encode($projection))) . "\n\n");

        // 3. Отправляем
        $this->stdout("  3. Отправляем на витрину... ", Console::FG_CYAN);

        $startTime = microtime(true);

        try {
            $result = $this->client->pushProduct($this->model, $projection);
            $duration = round((microtime(true) - $startTime) * 1000);

            if ($result) {
                $this->stdout("OK ✓ ({$duration}ms)\n\n", Console::FG_GREEN);
                $this->stdout("  ═══ СИНХРОНИЗАЦИЯ УСПЕШНА ═══\n\n", Console::FG_GREEN);
                return ExitCode::OK;
            } else {
                $this->stdout("FAIL — клиент вернул false.\n\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

        } catch (MarketplaceUnavailableException $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            $this->stdout("API UNAVAILABLE ({$duration}ms)\n", Console::FG_RED);
            $this->stdout("     HTTP: " . ($e->getHttpCode() ?? 'N/A') . "\n", Console::FG_RED);
            $this->stdout("     Ошибка: {$e->getMessage()}\n\n", Console::FG_RED);
            return ExitCode::TEMPFAIL;

        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            $this->stdout("ERROR ({$duration}ms)\n", Console::FG_RED);
            $this->stdout("     Ошибка: {$e->getMessage()}\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Проверить доступность API RosMatras.
     *
     * Использование:
     *   php yii export/ping
     */
    public function actionPing(): int
    {
        $this->stdout("\n  Проверяем API RosMatras...\n\n", Console::FG_CYAN);

        // Показываем конфигурацию
        $params = Yii::$app->params['rosmatras'] ?? [];
        $apiUrl = $params['apiUrl'] ?? 'не задан';
        $hasToken = !empty($params['apiToken']);

        $this->stdout("  URL:   {$apiUrl}\n");
        $this->stdout("  Token: " . ($hasToken ? '✓ (установлен)' : '✗ (не установлен)') . "\n\n",
            $hasToken ? Console::FG_GREEN : Console::FG_YELLOW);

        if (empty($params['apiUrl'])) {
            $this->stderr("  ✗ API URL не настроен. Задайте ROSMATRAS_API_URL в .env\n\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        // Health check
        $this->stdout("  Health check... ", Console::FG_CYAN);
        $startTime = microtime(true);

        try {
            $healthy = $this->client->healthCheck();
            $duration = round((microtime(true) - $startTime) * 1000);

            if ($healthy) {
                $this->stdout("OK ✓ ({$duration}ms)\n\n", Console::FG_GREEN);
                $this->stdout("  ═══ API RosMatras ДОСТУПЕН ═══\n\n", Console::FG_GREEN);
                return ExitCode::OK;
            } else {
                $this->stdout("WARN ({$duration}ms) — health вернул false\n\n", Console::FG_YELLOW);
                return ExitCode::UNSPECIFIED_ERROR;
            }

        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            $this->stdout("FAIL ✗ ({$duration}ms)\n", Console::FG_RED);
            $this->stdout("  Ошибка: {$e->getMessage()}\n\n", Console::FG_RED);
            $this->stdout("  ═══ API RosMatras НЕДОСТУПЕН ═══\n\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }
    }

    // ═══════════════════════════════════════════
    // УТИЛИТЫ
    // ═══════════════════════════════════════════

    /**
     * Показать статистику Outbox.
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

        $this->stdout("  Уникальных моделей в pending: {$pendingModels}\n");

        // Error-записи с retry_count
        $errorStats = $db->createCommand("
            SELECT retry_count, COUNT(*) as cnt
            FROM {{%marketplace_outbox}}
            WHERE status = 'error'
            GROUP BY retry_count ORDER BY retry_count
        ")->queryAll();

        if (!empty($errorStats)) {
            $this->stdout("\n  Error-записи по кол-ву попыток:\n", Console::FG_RED);
            foreach ($errorStats as $row) {
                $this->stdout("    retry_count={$row['retry_count']}: {$row['cnt']}\n");
            }
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Повторить error-записи с экспоненциальной задержкой.
     *
     * Записи с большим retry_count будут ждать дольше:
     *   retry_count=1 → сразу в pending
     *   retry_count=2 → только если прошло 5 минут
     *   retry_count=3 → только если прошло 20 минут
     *   retry_count=4 → только если прошло 1 час
     *   retry_count>=5 → не ретраить (dead letter)
     */
    public function actionRetryErrors(): int
    {
        $this->stdout("\n  Retry error-записей (maxRetries={$this->maxRetries})...\n\n", Console::FG_CYAN);

        $db = Yii::$app->db;

        // Retry с учётом exponential backoff по retry_count
        $totalRetried = 0;

        // Разные уровни: чем больше retry_count, тем старше должна быть запись
        $levels = [
            1 => '0 minutes',    // retry_count=1: сразу
            2 => '5 minutes',    // retry_count=2: ждём 5 мин
            3 => '20 minutes',   // retry_count=3: ждём 20 мин
            4 => '60 minutes',   // retry_count=4: ждём 1 час
        ];

        foreach ($levels as $retryCount => $interval) {
            if ($retryCount > $this->maxRetries) break;

            $retried = $db->createCommand("
                UPDATE {{%marketplace_outbox}}
                SET status = 'pending', error_log = NULL
                WHERE status = 'error'
                  AND retry_count = :rc
                  AND processed_at < NOW() - INTERVAL '{$interval}'
            ", [':rc' => $retryCount])->execute();

            if ($retried > 0) {
                $this->stdout("  retry_count={$retryCount} (ожидание {$interval}): {$retried} записей → pending\n",
                    Console::FG_GREEN);
                $totalRetried += $retried;
            }
        }

        // Dead letters — показать, сколько не ретраим
        $deadLetters = $db->createCommand("
            SELECT COUNT(*) FROM {{%marketplace_outbox}}
            WHERE status = 'error' AND retry_count >= :max
        ", [':max' => $this->maxRetries])->queryScalar();

        if ($totalRetried > 0) {
            $this->stdout("\n  ✓ Итого возвращено в pending: {$totalRetried}\n", Console::FG_GREEN);
        } else {
            $this->stdout("  Нет записей для retry (или ещё не прошло достаточно времени).\n", Console::FG_YELLOW);
        }

        if ($deadLetters > 0) {
            $this->stdout("  ⚠ Dead letters (retry_count >= {$this->maxRetries}): {$deadLetters}\n", Console::FG_RED);
        }

        $this->stdout("\n");
        return ExitCode::OK;
    }

    /**
     * Предпросмотр проекции модели (не отправляет на витрину).
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
        $jsonStr = json_encode($projection, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $this->stdout("\n  Полный JSON ({$this->formatBytes(strlen($jsonStr))}): \n\n", Console::FG_GREY);
        $this->stdout($jsonStr . "\n\n");

        return ExitCode::OK;
    }

    /**
     * Очистить старые success-записи из outbox.
     */
    public function actionCleanup(): int
    {
        $deleted = $this->outbox->cleanupOld(7);
        $this->stdout("  ✓ Удалено {$deleted} старых success-записей.\n\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Сбросить stuck processing записи (если Worker упал).
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
    // PRIVATE: Устойчивость к сбоям
    // ═══════════════════════════════════════════

    /**
     * Вернуть outbox-записи обратно в pending.
     *
     * Используется при MarketplaceUnavailableException — не теряем данные.
     *
     * @param int[] $outboxIds
     */
    private function rollbackToPending(array $outboxIds): void
    {
        if (empty($outboxIds)) return;

        $ids = array_values($outboxIds);
        $placeholders = implode(',', array_map(fn($i) => ':id' . $i, array_keys($ids)));
        $params = [];
        foreach ($ids as $i => $id) {
            $params[':id' . $i] = $id;
        }

        Yii::$app->db->createCommand(
            "UPDATE {{%marketplace_outbox}} SET status = 'pending' WHERE id IN ({$placeholders})",
            $params
        )->execute();
    }

    /**
     * Вернуть оставшиеся (необработанные) модели из текущего батча в pending.
     *
     * @param array<int, array> $grouped   Все модели текущего батча
     * @param int               $failedModelId  ID модели, на которой случился сбой
     */
    private function rollbackRemainingModels(array $grouped, int $failedModelId): void
    {
        $allRemainingIds = [];
        $found = false;

        foreach ($grouped as $modelId => $events) {
            if ($modelId === $failedModelId) {
                $found = true;
                continue; // Текущая модель уже обработана в вызывающем коде
            }
            if ($found) {
                // Все модели ПОСЛЕ failedModelId — ещё не обработаны
                $allRemainingIds = array_merge($allRemainingIds, array_column($events, 'id'));
            }
        }

        if (!empty($allRemainingIds)) {
            $this->rollbackToPending($allRemainingIds);
            Yii::info(
                "Rolled back " . count($allRemainingIds) . " outbox records to pending",
                'marketplace.export'
            );
        }
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
