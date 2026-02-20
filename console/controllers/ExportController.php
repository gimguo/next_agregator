<?php

namespace console\controllers;

use common\models\SalesChannel;
use common\services\OutboxService;
use common\services\RosMatrasSyndicationService;
use common\services\channel\ChannelDriverFactory;
use common\services\marketplace\MarketplaceApiClientInterface;
use common\services\marketplace\MarketplaceUnavailableException;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use Yii;

/**
 * Multi-Channel Export Worker — синдикация MDM-каталога на витрины.
 *
 * Обрабатывает очередь marketplace_outbox:
 *   1. Забирает pending-события (SELECT FOR UPDATE SKIP LOCKED)
 *   2. Группирует по model_id + channel_id
 *   3. Через ChannelDriverFactory получает Синдикатор и ApiClient
 *   4. Строит проекцию, специфичную для канала
 *   5. Отправляет через API конкретного канала
 *   6. Помечает success / error
 *
 * Каналы: RosMatras, Ozon, WB, Yandex и т.д.
 * Токены берутся из SalesChannel->api_config (не из глобальных params).
 *
 * Команды:
 *   php yii export/process-outbox          # Одна итерация
 *   php yii export/daemon                  # Бесконечный цикл
 *   php yii export/push-all                # Полная синхронизация всех каналов
 *   php yii export/push-channel --channel=1  # Полная синхронизация одного канала
 *   php yii export/sync-model --model=123  # Принудительная синхронизация модели
 *   php yii export/ping                    # Health-check всех каналов
 *   php yii export/status                  # Статистика очереди
 *   php yii export/retry-errors            # Retry с exponential backoff
 *   php yii export/preview --model=123     # Предпросмотр проекции
 *   php yii export/channels                # Список каналов
 */
class ExportController extends Controller
{
    /** @var int Размер батча */
    public int $batch = 100;

    /** @var int ID модели (для preview/sync-model) */
    public int $model = 0;

    /** @var int ID канала (для push-channel) */
    public int $channel = 0;

    /** @var int Интервал daemon (секунды) */
    public int $interval = 10;

    /** @var int Максимальное кол-во ретраев */
    public int $maxRetries = 5;

    /** @var OutboxService */
    private OutboxService $outbox;

    /** @var ChannelDriverFactory */
    private ChannelDriverFactory $factory;

    /** @var RosMatrasSyndicationService Legacy-сервис для preview/sync-model */
    private RosMatrasSyndicationService $syndicator;

    /** @var MarketplaceApiClientInterface Legacy-клиент для sync-model */
    private MarketplaceApiClientInterface $legacyClient;

    public function init(): void
    {
        parent::init();
        $this->outbox = Yii::$app->get('outbox');
        $this->factory = Yii::$app->get('channelFactory');
        $this->syndicator = Yii::$app->get('syndicationService');
        $this->legacyClient = Yii::$app->get('marketplaceClient');
    }

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'batch', 'model', 'channel', 'interval', 'maxRetries',
        ]);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'b' => 'batch',
            'm' => 'model',
            'c' => 'channel',
        ]);
    }

    // ═══════════════════════════════════════════
    // ОСНОВНЫЕ КОМАНДЫ
    // ═══════════════════════════════════════════

    /**
     * Обработать очередь outbox (одна итерация) — Multi-Channel.
     *
     * Теперь группирует по model_id + channel_id и через фабрику
     * получает правильный Синдикатор + ApiClient для каждого канала.
     */
    public function actionProcessOutbox(): int
    {
        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   EXPORT: Обработка Outbox (Multi-Ch.)   ║\n", Console::FG_CYAN);
        $this->stdout("╚══════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $startTime = microtime(true);

        // 1. Забираем pending-события, сгруппированные по model_id:channel_id
        $grouped = $this->outbox->fetchPendingBatch($this->batch);

        if (empty($grouped)) {
            $this->stdout("  ✓ Очередь пуста — нечего экспортировать.\n\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $groupCount = count($grouped);
        $eventCount = array_sum(array_map('count', $grouped));

        $this->stdout("  → Забрали {$eventCount} событий для {$groupCount} задач (model:channel)\n\n", Console::FG_YELLOW);

        $successCount = 0;
        $errorCount = 0;
        $skippedCount = 0;

        // Кэш SalesChannel по ID
        $channelCache = [];

        // 2. Для каждого model_id:channel_id — строим проекцию и отправляем
        foreach ($grouped as $groupKey => $events) {
            [$modelId, $channelId] = array_map('intval', explode(':', $groupKey));
            $outboxIds = array_column($events, 'id');
            $eventTypes = array_unique(array_column($events, 'event_type'));

            // Получаем канал
            if (!isset($channelCache[$channelId])) {
                $channelCache[$channelId] = SalesChannel::findOne($channelId);
            }
            $channel = $channelCache[$channelId];

            if (!$channel || !$channel->is_active) {
                $this->outbox->markSuccess($outboxIds); // Канал удалён/выключен — пропускаем
                $skippedCount++;
                $this->stdout("  [model={$modelId} ch={$channelId}] ", Console::FG_GREY);
                $this->stdout("→ SKIP (канал не найден или неактивен)\n", Console::FG_YELLOW);
                continue;
            }

            $this->stdout("  [model={$modelId} → {$channel->name}] ", Console::FG_CYAN);
            $this->stdout(count($events) . " событий (" . implode(', ', $eventTypes) . ") ");

            try {
                // Через фабрику получаем синдикатор и клиент
                $syndicator = $this->factory->getSyndicator($channel);
                $apiClient = $this->factory->getApiClient($channel);

                // Строим проекцию
                $projection = $syndicator->buildProjection($modelId, $channel);

                if (!$projection) {
                    $this->outbox->markSuccess($outboxIds);
                    $skippedCount++;
                    $this->stdout("→ SKIP (модель не найдена)\n", Console::FG_YELLOW);
                    continue;
                }

                // Отправляем на канал
                $result = $apiClient->push($modelId, $projection, $channel);

                if ($result) {
                    $this->outbox->markSuccess($outboxIds);
                    $successCount++;
                    $name = $projection['name'] ?? '?';
                    $variants = $projection['variant_count'] ?? count($projection['variants'] ?? []);
                    $price = $projection['best_price'] ?? null;
                    $priceStr = $price ? number_format($price, 0, '.', ' ') . '₽' : 'N/A';
                    $this->stdout("→ OK", Console::FG_GREEN);
                    $this->stdout(" ({$name}, {$variants} вар., {$priceStr})\n");
                } else {
                    $this->outbox->markError($outboxIds, 'push returned false');
                    $errorCount++;
                    $this->stdout("→ ERROR (returned false)\n", Console::FG_RED);
                }

            } catch (MarketplaceUnavailableException $e) {
                // API канала недоступен — rollback
                $this->rollbackToPending($outboxIds);
                $this->rollbackRemainingGroups($grouped, $groupKey);

                $this->stdout("→ API UNAVAILABLE\n", Console::FG_RED);
                $this->stdout("\n  ⚠ API канала '{$channel->name}' недоступен: {$e->getMessage()}\n", Console::FG_RED);
                $this->stdout("  → Записи возвращены в pending.\n\n", Console::FG_YELLOW);

                Yii::error(
                    "Export worker: channel '{$channel->name}' unavailable: {$e->getMessage()}",
                    'marketplace.export'
                );

                return ExitCode::TEMPFAIL;

            } catch (\Throwable $e) {
                $this->outbox->markError($outboxIds, mb_substr($e->getMessage(), 0, 500));
                $errorCount++;
                $this->stdout("→ ERROR: {$e->getMessage()}\n", Console::FG_RED);

                Yii::error(
                    "Export error model={$modelId} channel={$channel->name}: {$e->getMessage()}",
                    'marketplace.export'
                );
            }
        }

        // 3. Итоговый отчёт
        $duration = round(microtime(true) - $startTime, 2);

        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   РЕЗУЛЬТАТ ЭКСПОРТА                     ║\n", Console::FG_CYAN);
        $this->stdout("╠══════════════════════════════════════════╣\n", Console::FG_CYAN);
        $this->stdout("║ Задач:      " . str_pad($groupCount, 28) . "║\n");
        $this->stdout("║ Успешно:    " . str_pad($successCount, 28) . "║\n", Console::FG_GREEN);
        $this->stdout("║ Пропущено:  " . str_pad($skippedCount, 28) . "║\n");
        $this->stdout("║ Ошибки:     " . str_pad($errorCount, 28) . "║\n",
            $errorCount > 0 ? Console::FG_RED : Console::FG_GREEN);
        $this->stdout("║ Время:      " . str_pad($duration . 's', 28) . "║\n");
        $this->stdout("╚══════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        return $errorCount > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Daemon-режим: бесконечный цикл обработки outbox (Multi-Channel).
     */
    public function actionDaemon(): int
    {
        $this->stdout("\n  EXPORT DAEMON STARTED (interval={$this->interval}s, batch={$this->batch}) [Multi-Channel]\n", Console::FG_GREEN);
        $this->stdout("  Press Ctrl+C to stop.\n\n");

        $iteration = 0;
        $consecutiveApiFailures = 0;

        // Кэш каналов
        $channelCache = [];

        while (true) {
            $iteration++;
            $startTime = microtime(true);

            $grouped = $this->outbox->fetchPendingBatch($this->batch);

            if (!empty($grouped)) {
                $eventCount = array_sum(array_map('count', $grouped));
                $groupCount = count($grouped);

                $this->stdout(
                    "  [" . date('H:i:s') . "] #{$iteration}: {$eventCount} events, {$groupCount} groups",
                    Console::FG_YELLOW
                );

                $success = 0;
                $errors = 0;
                $apiDown = false;

                foreach ($grouped as $groupKey => $events) {
                    [$modelId, $channelId] = array_map('intval', explode(':', $groupKey));
                    $outboxIds = array_column($events, 'id');

                    if (!isset($channelCache[$channelId])) {
                        $channelCache[$channelId] = SalesChannel::findOne($channelId);
                    }
                    $channel = $channelCache[$channelId];

                    if (!$channel || !$channel->is_active) {
                        $this->outbox->markSuccess($outboxIds);
                        continue;
                    }

                    try {
                        $syndicator = $this->factory->getSyndicator($channel);
                        $apiClient = $this->factory->getApiClient($channel);

                        $projection = $syndicator->buildProjection($modelId, $channel);

                        if (!$projection) {
                            $this->outbox->markSuccess($outboxIds);
                            continue;
                        }

                        $result = $apiClient->push($modelId, $projection, $channel);

                        if ($result) {
                            $this->outbox->markSuccess($outboxIds);
                            $success++;
                        } else {
                            $this->outbox->markError($outboxIds, 'push returned false');
                            $errors++;
                        }

                    } catch (MarketplaceUnavailableException $e) {
                        $this->rollbackToPending($outboxIds);
                        $this->rollbackRemainingGroups($grouped, $groupKey);
                        $apiDown = true;
                        $consecutiveApiFailures++;

                        Yii::error(
                            "Daemon: channel '{$channel->name}' unavailable (fail #{$consecutiveApiFailures}): {$e->getMessage()}",
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

                $consecutiveApiFailures = 0;

                $this->stdout(
                    " → OK:{$success} ERR:{$errors} ({$duration}s)\n",
                    $errors > 0 ? Console::FG_YELLOW : Console::FG_GREEN
                );
            } else {
                if ($iteration % 6 === 0) {
                    $this->stdout(
                        "  [" . date('H:i:s') . "] Idle, queue empty.\n",
                        Console::FG_GREY
                    );
                }
                if ($consecutiveApiFailures > 0) {
                    $consecutiveApiFailures = max(0, $consecutiveApiFailures - 1);
                }
            }

            // Периодически сбрасываем кэш каналов (чтобы подхватить изменения)
            if ($iteration % 30 === 0) {
                $channelCache = [];
                $this->outbox->resetChannelCache();
            }

            sleep($this->interval);
        }
    }

    // ═══════════════════════════════════════════
    // FULL SYNC / INITIAL SEED (Multi-Channel)
    // ═══════════════════════════════════════════

    /**
     * Полная синхронизация ВСЕХ активных моделей на ВСЕ активные каналы.
     *
     * Для каждого канала:
     *   1. Получает Синдикатор + ApiClient через фабрику
     *   2. Итерирует все active модели батчами
     *   3. Строит канало-специфичную проекцию
     *   4. Отправляет через pushBatch
     */
    public function actionPushAll(): int
    {
        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   FULL SYNC: Все модели → Все каналы     ║\n", Console::FG_CYAN);
        $this->stdout("╚══════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $channels = SalesChannel::findActive();

        if (empty($channels)) {
            $this->stderr("  ✗ Нет активных каналов продаж.\n\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $this->stdout("  Активные каналы: " . count($channels) . "\n", Console::FG_CYAN);
        foreach ($channels as $ch) {
            $hasDriver = $this->factory->hasDriver($ch->driver);
            $this->stdout("    • {$ch->name} (driver={$ch->driver}) " .
                ($hasDriver ? "✓" : "✗ (драйвер не зарегистрирован)") . "\n",
                $hasDriver ? Console::FG_GREEN : Console::FG_RED
            );
        }
        $this->stdout("\n");

        // Считаем модели
        $db = Yii::$app->db;
        $totalModels = (int)$db->createCommand(
            "SELECT COUNT(*) FROM {{%product_models}} WHERE status = 'active'"
        )->queryScalar();

        if ($totalModels === 0) {
            $this->stdout("  ✓ Нет активных моделей для синхронизации.\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("  Найдено моделей: {$totalModels}\n\n", Console::FG_YELLOW);

        // Синхронизируем каждый канал по очереди
        $allOk = true;
        foreach ($channels as $ch) {
            if (!$this->factory->hasDriver($ch->driver)) {
                $this->stdout("  ⤳ Пропуск канала '{$ch->name}': драйвер '{$ch->driver}' не зарегистрирован.\n\n", Console::FG_YELLOW);
                continue;
            }

            $result = $this->pushAllForChannel($ch, $totalModels);
            if ($result !== ExitCode::OK) {
                $allOk = false;
            }
        }

        return $allOk ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Полная синхронизация ВСЕХ активных моделей на ОДИН канал.
     *
     * Использование:
     *   php yii export/push-channel --channel=1
     */
    public function actionPushChannel(): int
    {
        if (!$this->channel) {
            $this->stderr("  ✗ Укажите --channel=ID\n\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $channel = SalesChannel::findOne($this->channel);
        if (!$channel) {
            $this->stderr("  ✗ Канал #{$this->channel} не найден.\n\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        if (!$this->factory->hasDriver($channel->driver)) {
            $this->stderr("  ✗ Драйвер '{$channel->driver}' не зарегистрирован.\n\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   FULL SYNC: Все модели → {$channel->name}" . str_repeat(' ', max(0, 14 - mb_strlen($channel->name))) . "║\n", Console::FG_CYAN);
        $this->stdout("╚══════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $db = Yii::$app->db;
        $totalModels = (int)$db->createCommand(
            "SELECT COUNT(*) FROM {{%product_models}} WHERE status = 'active'"
        )->queryScalar();

        if ($totalModels === 0) {
            $this->stdout("  ✓ Нет активных моделей для синхронизации.\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        return $this->pushAllForChannel($channel, $totalModels);
    }

    /**
     * Внутренний метод: полная синхронизация всех моделей для одного канала.
     */
    private function pushAllForChannel(SalesChannel $channel, int $totalModels): int
    {
        $this->stdout("  ═══ Канал: {$channel->name} (driver={$channel->driver}) ═══\n\n", Console::FG_CYAN);

        // Health check
        $this->stdout("  1. Health check... ", Console::FG_CYAN);
        try {
            $apiClient = $this->factory->getApiClient($channel);
            $healthy = $apiClient->healthCheck($channel);
            if (!$healthy) {
                $this->stderr("FAIL — API не прошёл health check.\n\n", Console::FG_RED);
                return ExitCode::UNAVAILABLE;
            }
            $this->stdout("OK ✓\n", Console::FG_GREEN);
        } catch (\Throwable $e) {
            $this->stderr("FAIL ✗ ({$e->getMessage()})\n\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }

        $this->stdout("  2. Моделей: {$totalModels}\n\n", Console::FG_YELLOW);

        $syndicator = $this->factory->getSyndicator($channel);
        $dbBatchSize = $this->batch;
        $pushBatchSize = 50;

        $startTime = microtime(true);
        $processed = 0;
        $successCount = 0;
        $errorCount = 0;
        $skippedCount = 0;
        $apiFailures = 0;

        Console::startProgress(0, $totalModels, "  [{$channel->name}] Синхронизация: ", 60);

        $db = Yii::$app->db;
        $offset = 0;

        while (true) {
            $modelRows = $db->createCommand("
                SELECT id FROM {{%product_models}}
                WHERE status = 'active'
                ORDER BY id ASC
                LIMIT :limit OFFSET :offset
            ", [':limit' => $dbBatchSize, ':offset' => $offset])->queryColumn();

            if (empty($modelRows)) {
                break;
            }

            $offset += count($modelRows);
            $projections = [];

            foreach ($modelRows as $modelId) {
                try {
                    $projection = $syndicator->buildProjection((int)$modelId, $channel);

                    if ($projection) {
                        $projections[(int)$modelId] = $projection;
                    } else {
                        $skippedCount++;
                    }
                } catch (\Throwable $e) {
                    $errorCount++;
                    Yii::error(
                        "PushAll [{$channel->name}]: projection error model_id={$modelId}: {$e->getMessage()}",
                        'marketplace.export'
                    );
                }

                $processed++;
                Console::updateProgress($processed, $totalModels);

                if (count($projections) >= $pushBatchSize) {
                    $pushResult = $this->pushBatchSafeChannel($projections, $apiFailures, $channel, $apiClient);
                    $successCount += $pushResult['success'];
                    $errorCount += $pushResult['errors'];
                    $apiFailures = $pushResult['apiFailures'];
                    $projections = [];

                    if ($apiFailures >= 3) {
                        Console::endProgress("ABORTED\n");
                        $this->stderr("\n  ⚠ API '{$channel->name}' недоступен (3 подряд). Прерываем.\n", Console::FG_RED);
                        $this->printPushAllReport($channel->name, $totalModels, $processed, $successCount, $errorCount, $skippedCount, $startTime);
                        return ExitCode::TEMPFAIL;
                    }
                }
            }

            if (!empty($projections)) {
                $pushResult = $this->pushBatchSafeChannel($projections, $apiFailures, $channel, $apiClient);
                $successCount += $pushResult['success'];
                $errorCount += $pushResult['errors'];
                $apiFailures = $pushResult['apiFailures'];

                if ($apiFailures >= 3) {
                    Console::endProgress("ABORTED\n");
                    $this->stderr("\n  ⚠ API '{$channel->name}' недоступен (3 подряд). Прерываем.\n", Console::FG_RED);
                    $this->printPushAllReport($channel->name, $totalModels, $processed, $successCount, $errorCount, $skippedCount, $startTime);
                    return ExitCode::TEMPFAIL;
                }
            }

            gc_collect_cycles();
        }

        Console::endProgress("OK ✓\n");

        $this->printPushAllReport($channel->name, $totalModels, $processed, $successCount, $errorCount, $skippedCount, $startTime);

        return $errorCount > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Безопасная отправка батча через канало-специфичный клиент.
     */
    private function pushBatchSafeChannel(
        array $projections,
        int $apiFailures,
        SalesChannel $channel,
        \common\services\channel\ApiClientInterface $apiClient
    ): array {
        $success = 0;
        $errors = 0;

        try {
            $results = $apiClient->pushBatch($projections, $channel);

            foreach ($results as $modelId => $ok) {
                if ($ok) {
                    $success++;
                } else {
                    $errors++;
                }
            }

            $apiFailures = 0;

        } catch (MarketplaceUnavailableException $e) {
            $apiFailures++;
            $errors += count($projections);

            $backoff = MarketplaceUnavailableException::calculateBackoff($apiFailures, 5, 60);
            Yii::warning(
                "PushAll [{$channel->name}]: API unavailable (fail #{$apiFailures}), backoff {$backoff}s: {$e->getMessage()}",
                'marketplace.export'
            );
            sleep($backoff);

        } catch (\Throwable $e) {
            $errors += count($projections);
            Yii::error(
                "PushAll [{$channel->name}]: batch error: {$e->getMessage()}",
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
     * Итоговый отчёт push-all для одного канала.
     */
    private function printPushAllReport(
        string $channelName, int $totalModels, int $processed,
        int $successCount, int $errorCount, int $skippedCount, float $startTime
    ): void {
        $duration = round(microtime(true) - $startTime, 1);
        $speed = $processed > 0 && $duration > 0 ? round($processed / $duration, 1) : 0;

        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   РЕЗУЛЬТАТ: {$channelName}" . str_repeat(' ', max(0, 27 - mb_strlen($channelName))) . "║\n", Console::FG_CYAN);
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
     * Использует legacy-клиент (RosMatras) для обратной совместимости.
     * В будущем можно добавить --channel для конкретного канала.
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

        // Если указан канал — используем новую архитектуру
        if ($this->channel) {
            return $this->syncModelToChannel($this->model, $this->channel);
        }

        // Legacy: RosMatras напрямую
        $this->stdout("  1. Health check API... ", Console::FG_CYAN);
        try {
            $healthy = $this->legacyClient->healthCheck();
            if ($healthy) {
                $this->stdout("OK ✓\n", Console::FG_GREEN);
            } else {
                $this->stdout("WARN (health returned false)\n", Console::FG_YELLOW);
            }
        } catch (\Throwable $e) {
            $this->stdout("FAIL ✗ ({$e->getMessage()})\n", Console::FG_RED);
        }

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

        $this->stdout("  3. Отправляем на витрину... ", Console::FG_CYAN);
        $startTime = microtime(true);

        try {
            $result = $this->legacyClient->pushProduct($this->model, $projection);
            $duration = round((microtime(true) - $startTime) * 1000);

            if ($result) {
                $this->stdout("OK ✓ ({$duration}ms)\n\n", Console::FG_GREEN);
                return ExitCode::OK;
            } else {
                $this->stdout("FAIL — клиент вернул false.\n\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

        } catch (MarketplaceUnavailableException $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            $this->stdout("API UNAVAILABLE ({$duration}ms)\n", Console::FG_RED);
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
     * Синхронизировать одну модель на конкретный канал.
     */
    private function syncModelToChannel(int $modelId, int $channelId): int
    {
        $channel = SalesChannel::findOne($channelId);
        if (!$channel) {
            $this->stderr("  ✗ Канал #{$channelId} не найден.\n\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->stdout("  Канал: {$channel->name} (driver={$channel->driver})\n\n", Console::FG_CYAN);

        $syndicator = $this->factory->getSyndicator($channel);
        $apiClient = $this->factory->getApiClient($channel);

        $this->stdout("  1. Health check... ", Console::FG_CYAN);
        try {
            $healthy = $apiClient->healthCheck($channel);
            $this->stdout($healthy ? "OK ✓\n" : "WARN\n", $healthy ? Console::FG_GREEN : Console::FG_YELLOW);
        } catch (\Throwable $e) {
            $this->stdout("FAIL ✗\n", Console::FG_RED);
        }

        $this->stdout("  2. Строим проекцию model_id={$modelId}... ", Console::FG_CYAN);
        $projection = $syndicator->buildProjection($modelId, $channel);
        if (!$projection) {
            $this->stderr("FAIL\n\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        $this->stdout("OK ✓\n", Console::FG_GREEN);

        $this->stdout("  3. Отправляем на '{$channel->name}'... ", Console::FG_CYAN);
        $startTime = microtime(true);

        try {
            $result = $apiClient->push($modelId, $projection, $channel);
            $duration = round((microtime(true) - $startTime) * 1000);

            if ($result) {
                $this->stdout("OK ✓ ({$duration}ms)\n\n", Console::FG_GREEN);
                return ExitCode::OK;
            } else {
                $this->stdout("FAIL ({$duration}ms)\n\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        } catch (\Throwable $e) {
            $this->stdout("ERROR: {$e->getMessage()}\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Проверить доступность API всех каналов.
     */
    public function actionPing(): int
    {
        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   HEALTH CHECK: Все каналы               ║\n", Console::FG_CYAN);
        $this->stdout("╚══════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $channels = SalesChannel::findActive();

        if (empty($channels)) {
            $this->stdout("  Нет активных каналов.\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $allOk = true;

        foreach ($channels as $ch) {
            $this->stdout("  {$ch->name} (driver={$ch->driver}): ", Console::FG_CYAN);

            if (!$this->factory->hasDriver($ch->driver)) {
                $this->stdout("SKIP (драйвер не зарегистрирован)\n", Console::FG_YELLOW);
                continue;
            }

            $startTime = microtime(true);
            try {
                $apiClient = $this->factory->getApiClient($ch);
                $healthy = $apiClient->healthCheck($ch);
                $duration = round((microtime(true) - $startTime) * 1000);

                if ($healthy) {
                    $this->stdout("OK ✓ ({$duration}ms)\n", Console::FG_GREEN);
                } else {
                    $this->stdout("FAIL ✗ ({$duration}ms)\n", Console::FG_RED);
                    $allOk = false;
                }
            } catch (\Throwable $e) {
                $duration = round((microtime(true) - $startTime) * 1000);
                $this->stdout("ERROR ({$duration}ms): {$e->getMessage()}\n", Console::FG_RED);
                $allOk = false;
            }
        }

        // Legacy-клиент
        $this->stdout("\n  Legacy (RosMatras params): ", Console::FG_GREY);
        $startTime = microtime(true);
        try {
            $healthy = $this->legacyClient->healthCheck();
            $duration = round((microtime(true) - $startTime) * 1000);
            $this->stdout($healthy ? "OK ✓ ({$duration}ms)\n" : "FAIL ({$duration}ms)\n",
                $healthy ? Console::FG_GREEN : Console::FG_RED);
        } catch (\Throwable $e) {
            $this->stdout("ERROR: {$e->getMessage()}\n", Console::FG_RED);
        }

        $this->stdout("\n");

        return $allOk ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Список зарегистрированных каналов продаж.
     */
    public function actionChannels(): int
    {
        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   КАНАЛЫ ПРОДАЖ                          ║\n", Console::FG_CYAN);
        $this->stdout("╚══════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $channels = SalesChannel::find()->orderBy(['id' => SORT_ASC])->all();

        if (empty($channels)) {
            $this->stdout("  Нет каналов. Запустите миграции.\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        foreach ($channels as $ch) {
            $status = $ch->is_active ? '✓ Active' : '✗ Inactive';
            $hasDriver = $this->factory->hasDriver($ch->driver);
            $driverStatus = $hasDriver ? '✓' : '✗ not registered';

            $this->stdout("  [{$ch->id}] ", Console::FG_CYAN);
            $this->stdout("{$ch->name}\n");
            $this->stdout("      Driver:  {$ch->driver} ({$driverStatus})\n",
                $hasDriver ? Console::FG_GREEN : Console::FG_RED);
            $this->stdout("      Status:  {$status}\n",
                $ch->is_active ? Console::FG_GREEN : Console::FG_GREY);

            $config = is_string($ch->api_config) ? json_decode($ch->api_config, true) : $ch->api_config;
            if (!empty($config)) {
                $this->stdout("      Config:  ");
                foreach ($config as $key => $value) {
                    $displayVal = (stripos($key, 'token') !== false || stripos($key, 'key') !== false || stripos($key, 'secret') !== false)
                        ? '***' . substr($value, -4)
                        : $value;
                    $this->stdout("{$key}={$displayVal} ");
                }
                $this->stdout("\n");
            }
            $this->stdout("\n");
        }

        $this->stdout("  Зарегистрированные драйверы: " . implode(', ', $this->factory->getRegisteredDrivers()) . "\n\n");

        return ExitCode::OK;
    }

    // ═══════════════════════════════════════════
    // УТИЛИТЫ
    // ═══════════════════════════════════════════

    /**
     * Показать статистику Outbox (Multi-Channel).
     */
    public function actionStatus(): int
    {
        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   OUTBOX QUEUE STATUS (Multi-Channel)    ║\n", Console::FG_CYAN);
        $this->stdout("╚══════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        // Общая статистика
        $stats = $this->outbox->getQueueStats();

        $this->stdout("  Общая:\n", Console::FG_CYAN);
        $this->stdout("    Pending:    " . $stats['pending'] . "\n",
            $stats['pending'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN);
        $this->stdout("    Processing: " . $stats['processing'] . "\n",
            $stats['processing'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN);
        $this->stdout("    Success:    " . $stats['success'] . "\n", Console::FG_GREEN);
        $this->stdout("    Error:      " . $stats['error'] . "\n",
            $stats['error'] > 0 ? Console::FG_RED : Console::FG_GREEN);

        // По каналам
        $channelStats = $this->outbox->getQueueStatsByChannel();
        if (!empty($channelStats)) {
            $this->stdout("\n  По каналам:\n", Console::FG_CYAN);
            foreach ($channelStats as $channelName => $statuses) {
                $pending = $statuses['pending'] ?? 0;
                $errors = $statuses['error'] ?? 0;
                $success = $statuses['success'] ?? 0;
                $this->stdout("    {$channelName}: P={$pending} S={$success} E={$errors}\n",
                    $errors > 0 ? Console::FG_YELLOW : Console::FG_GREEN);
            }
        }

        $db = Yii::$app->db;

        // Уникальные модели в pending
        $pendingModels = $db->createCommand("
            SELECT COUNT(DISTINCT model_id) FROM {{%marketplace_outbox}} WHERE status = 'pending'
        ")->queryScalar();

        $this->stdout("\n  Уникальных моделей в pending: {$pendingModels}\n");

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
     * Повторить error-записи.
     */
    public function actionRetryErrors(): int
    {
        $this->stdout("\n  Retry error-записей (maxRetries={$this->maxRetries})...\n\n", Console::FG_CYAN);

        $db = Yii::$app->db;
        $totalRetried = 0;

        $levels = [
            1 => '0 minutes',
            2 => '5 minutes',
            3 => '20 minutes',
            4 => '60 minutes',
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

        $deadLetters = $db->createCommand("
            SELECT COUNT(*) FROM {{%marketplace_outbox}}
            WHERE status = 'error' AND retry_count >= :max
        ", [':max' => $this->maxRetries])->queryScalar();

        if ($totalRetried > 0) {
            $this->stdout("\n  ✓ Итого возвращено в pending: {$totalRetried}\n", Console::FG_GREEN);
        } else {
            $this->stdout("  Нет записей для retry.\n", Console::FG_YELLOW);
        }

        if ($deadLetters > 0) {
            $this->stdout("  ⚠ Dead letters (retry_count >= {$this->maxRetries}): {$deadLetters}\n", Console::FG_RED);
        }

        $this->stdout("\n");
        return ExitCode::OK;
    }

    /**
     * Предпросмотр проекции модели (не отправляет).
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

        $this->stdout("  Название: {$projection['name']}\n", Console::BOLD);
        $this->stdout("  Бренд:    " . ($projection['brand']['name'] ?? 'N/A') . "\n");
        $this->stdout("  Семейство: {$projection['product_family']}\n");
        $this->stdout("  Slug:     {$projection['slug']}\n");
        $this->stdout("  Цена:     " . ($projection['best_price'] ? number_format($projection['best_price'], 0, '.', ' ') . '₽' : 'N/A') . "\n");
        $this->stdout("  В наличии: " . ($projection['is_in_stock'] ? 'Да' : 'Нет') . "\n");
        $this->stdout("  Активна:   " . ($projection['is_active'] ? 'Да' : 'Нет') . "\n");
        $this->stdout("  Вариантов: {$projection['variant_count']}\n");
        $this->stdout("  Офферов:   {$projection['offer_count']}\n");
        $this->stdout("  Изображений: " . count($projection['images']) . "\n");

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

        $this->stdout("\n  Варианты:\n", Console::FG_CYAN);
        foreach ($projection['variants'] as $v) {
            $price = $v['best_price'] ? number_format($v['best_price'], 0, '.', ' ') . '₽' : 'N/A';
            $stock = $v['is_in_stock'] ? '✓' : '✗';
            $offers = count($v['offers']);
            $this->stdout("    [{$v['id']}] {$v['label']} — {$price} {$stock} ({$offers} офферов)\n");
        }

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
     * Вернуть оставшиеся (необработанные) группы обратно в pending.
     *
     * @param array<string, array> $grouped       Все группы текущего батча
     * @param string               $failedKey     Ключ группы, на которой случился сбой
     */
    private function rollbackRemainingGroups(array $grouped, string $failedKey): void
    {
        $allRemainingIds = [];
        $found = false;

        foreach ($grouped as $key => $events) {
            if ($key === $failedKey) {
                $found = true;
                continue;
            }
            if ($found) {
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
