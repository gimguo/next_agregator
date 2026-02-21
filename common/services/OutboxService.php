<?php

namespace common\services;

use common\models\MarketplaceOutbox;
use common\models\ModelChannelReadiness;
use common\models\SalesChannel;
use yii\base\Component;
use yii\db\JsonExpression;
use Yii;

/**
 * Сервис записи событий в Transactional Outbox (Multi-Channel Fan-out + Fast-Lane).
 *
 * Вызывается ВНУТРИ транзакции CatalogPersisterService / GoldenRecordService.
 * Гарантия: INSERT в outbox — часть той же транзакции, что и основная запись.
 *
 * === Fan-out ===
 *   При каждом событии сервис получает список ВСЕХ активных каналов
 *   и создаёт запись в marketplace_outbox для КАЖДОГО канала.
 *
 * === Fast-Lane (Sprint 10) ===
 *   Каждая outbox-запись имеет 'lane' — тип обновления:
 *     - content_updated → полная проекция (тяжёлая, атрибуты + картинки)
 *     - price_updated   → только SKU + Price (лёгкая)
 *     - stock_updated   → только SKU + Qty (лёгкая)
 *
 *   Дедупликация учитывает lane: одновременно могут существовать
 *   pending-задачи на контент И на цену для одной и той же модели.
 *
 * === DLQ (Sprint 10) ===
 *   Статус 'failed' — задача не будет retry (ошибка 4xx валидации).
 *   markFailed() сохраняет ошибку в channel_sync_errors.
 *
 * === Readiness Gate (Sprint 12) ===
 *   Перед созданием content_updated задачи проверяется ReadinessScoringService.
 *   Если модель не готова (is_ready === false), задача НЕ создаётся.
 *   Результат кэшируется в model_channel_readiness.
 *   Fast-Lane (price_updated, stock_updated) НЕ блокируется!
 *
 * Использование:
 *   $outbox = Yii::$app->get('outbox');
 *   $outbox->emitContentUpdate($modelId, $sessionId);
 *   $outbox->emitPriceUpdate($modelId, $variantId, $offerId, [...]);
 *   $outbox->emitStockUpdate($modelId, $variantId, $offerId, [...]);
 */
class OutboxService extends Component
{
    /** @var bool Включить дедупликацию (не создавать дубли pending для одной сущности + lane) */
    public bool $deduplication = true;

    /** @var bool Включить проверку готовности перед content_updated (Sprint 12) */
    public bool $readinessGate = true;

    /** @var array Счётчик событий текущей сессии */
    private array $stats = [
        'total'        => 0,
        'created'      => 0,
        'deduplicated' => 0,
        'blocked'      => 0,
    ];

    /** @var SalesChannel[]|null Кэш активных каналов (на время запроса) */
    private ?array $activeChannelsCache = null;

    // ═══════════════════════════════════════════
    // HIGH-LEVEL API: Lane-Aware Emit Methods
    // ═══════════════════════════════════════════

    /**
     * Контент обновлён (модель создана / обновлена).
     * Lane: content_updated → воркер пошлёт полную проекцию.
     */
    public function emitContentUpdate(int $modelId, ?string $sessionId = null, array $payload = [], string $sourceEvent = 'updated'): void
    {
        $this->fanOut('model', $modelId, $modelId, $sourceEvent, MarketplaceOutbox::LANE_CONTENT, $payload, 'catalog_persister', $sessionId);
    }

    /**
     * Цена изменилась.
     * Lane: price_updated → воркер пошлёт только SKU + цены.
     */
    public function emitPriceUpdate(int $modelId, int $variantId, int $offerId, array $priceDelta = [], ?string $sessionId = null): void
    {
        $this->fanOut('offer', $offerId, $modelId, 'price_changed', MarketplaceOutbox::LANE_PRICE, $priceDelta, 'catalog_persister', $sessionId);
    }

    /**
     * Остатки изменились.
     * Lane: stock_updated → воркер пошлёт только SKU + qty.
     */
    public function emitStockUpdate(int $modelId, int $variantId, int $offerId, array $stockDelta = [], ?string $sessionId = null): void
    {
        $this->fanOut('offer', $offerId, $modelId, 'stock_changed', MarketplaceOutbox::LANE_STOCK, $stockDelta, 'catalog_persister', $sessionId);
    }

    // ═══════════════════════════════════════════
    // LEGACY API (обратная совместимость)
    // Все legacy-методы маппят на lane = content_updated
    // ═══════════════════════════════════════════

    /**
     * @deprecated Используйте emitContentUpdate()
     */
    public function modelCreated(int $modelId, ?string $sessionId = null, array $payload = []): void
    {
        $this->fanOut('model', $modelId, $modelId, 'created', MarketplaceOutbox::LANE_CONTENT, $payload, 'catalog_persister', $sessionId);
    }

    /**
     * @deprecated Используйте emitContentUpdate()
     */
    public function modelUpdated(int $modelId, ?string $sessionId = null, array $payload = []): void
    {
        $this->fanOut('model', $modelId, $modelId, 'updated', MarketplaceOutbox::LANE_CONTENT, $payload, 'golden_record', $sessionId);
    }

    /**
     * @deprecated Используйте emitContentUpdate()
     */
    public function variantCreated(int $modelId, int $variantId, ?string $sessionId = null, array $payload = []): void
    {
        $this->fanOut('variant', $variantId, $modelId, 'created', MarketplaceOutbox::LANE_CONTENT, $payload, 'catalog_persister', $sessionId);
    }

    /**
     * @deprecated Используйте emitContentUpdate()
     */
    public function variantUpdated(int $modelId, int $variantId, ?string $sessionId = null, array $payload = []): void
    {
        $this->fanOut('variant', $variantId, $modelId, 'updated', MarketplaceOutbox::LANE_CONTENT, $payload, 'golden_record', $sessionId);
    }

    /**
     * @deprecated Используйте emitContentUpdate()
     */
    public function offerCreated(int $modelId, int $variantId, int $offerId, ?string $sessionId = null, array $payload = []): void
    {
        $this->fanOut('offer', $offerId, $modelId, 'created', MarketplaceOutbox::LANE_CONTENT, $payload, 'catalog_persister', $sessionId);
    }

    /**
     * @deprecated Используйте emitContentUpdate() или emitPriceUpdate()
     */
    public function offerUpdated(int $modelId, int $variantId, int $offerId, ?string $sessionId = null, array $payload = []): void
    {
        $this->fanOut('offer', $offerId, $modelId, 'updated', MarketplaceOutbox::LANE_CONTENT, $payload, 'catalog_persister', $sessionId);
    }

    /**
     * @deprecated Используйте emitPriceUpdate()
     */
    public function priceChanged(int $modelId, int $variantId, int $offerId, array $priceDelta = [], ?string $sessionId = null): void
    {
        $this->emitPriceUpdate($modelId, $variantId, $offerId, $priceDelta, $sessionId);
    }

    /**
     * @deprecated Используйте emitStockUpdate()
     */
    public function stockChanged(int $modelId, int $variantId, int $offerId, array $stockDelta = [], ?string $sessionId = null): void
    {
        $this->emitStockUpdate($modelId, $variantId, $offerId, $stockDelta, $sessionId);
    }

    // ═══════════════════════════════════════════
    // FAN-OUT: одно событие → N записей (по каналу)
    // ═══════════════════════════════════════════

    /**
     * Fan-out: создать outbox-запись для КАЖДОГО активного канала.
     *
     * Sprint 12: Для lane = content_updated проверяется readiness.
     * Если модель не готова — задача не создаётся, результат кэшируется.
     */
    protected function fanOut(
        string  $entityType,
        int     $entityId,
        int     $modelId,
        string  $sourceEvent,
        string  $lane,
        array   $payload = [],
        string  $source = 'catalog_persister',
        ?string $sessionId = null
    ): void {
        $channels = $this->getActiveChannels();

        if (empty($channels)) {
            Yii::warning('OutboxService: no active sales channels, event skipped', 'marketplace.outbox');
            return;
        }

        foreach ($channels as $channel) {
            // ═══ READINESS GATE (Sprint 12) ═══
            // Только для content_updated — цены и остатки НЕ блокируем
            if ($this->readinessGate && $lane === MarketplaceOutbox::LANE_CONTENT) {
                if (!$this->checkReadiness($modelId, $channel)) {
                    $this->stats['blocked']++;
                    Yii::info(
                        "OutboxService: BLOCKED content for model_id={$modelId} channel={$channel->name} (not ready)",
                        'marketplace.outbox'
                    );
                    continue;
                }
            }

            $this->emit($entityType, $entityId, $modelId, $sourceEvent, $lane, $payload, $source, $sessionId, $channel->id);
        }
    }

    // ═══════════════════════════════════════════
    // CORE
    // ═══════════════════════════════════════════

    /**
     * Записать событие в Outbox для конкретного канала.
     *
     * ВАЖНО: Этот метод должен вызываться ВНУТРИ активной транзакции!
     *
     * Дедупликация теперь учитывает lane: одна сущность может иметь
     * pending на content_updated И pending на price_updated одновременно.
     */
    protected function emit(
        string  $entityType,
        int     $entityId,
        int     $modelId,
        string  $sourceEvent,
        string  $lane,
        array   $payload = [],
        string  $source = 'catalog_persister',
        ?string $sessionId = null,
        int     $channelId = 0
    ): void {
        $this->stats['total']++;

        $db = Yii::$app->db;

        // Дедупликация: не создаём дубли для pending-записей той же сущности + канала + lane
        if ($this->deduplication) {
            $exists = $db->createCommand("
                SELECT 1 FROM {{%marketplace_outbox}}
                WHERE entity_type = :type
                  AND entity_id = :eid
                  AND status = 'pending'
                  AND channel_id = :cid
                  AND lane = :lane
                LIMIT 1
            ", [
                ':type' => $entityType,
                ':eid'  => $entityId,
                ':cid'  => $channelId,
                ':lane' => $lane,
            ])->queryScalar();

            if ($exists) {
                // Обновляем payload последней pending-записи (мержим дельту)
                $db->createCommand("
                    UPDATE {{%marketplace_outbox}}
                    SET payload = payload || :payload::jsonb,
                        source_event = :event,
                        created_at = NOW()
                    WHERE entity_type = :type
                      AND entity_id = :eid
                      AND status = 'pending'
                      AND channel_id = :cid
                      AND lane = :lane
                ", [
                    ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    ':event'   => $sourceEvent,
                    ':type'    => $entityType,
                    ':eid'     => $entityId,
                    ':cid'     => $channelId,
                    ':lane'    => $lane,
                ])->execute();

                $this->stats['deduplicated']++;
                return;
            }
        }

        $db->createCommand()->insert('{{%marketplace_outbox}}', [
            'entity_type'       => $entityType,
            'entity_id'         => $entityId,
            'model_id'          => $modelId,
            'channel_id'        => $channelId,
            'source_event'      => $sourceEvent,
            'lane'              => $lane,
            'payload'           => new JsonExpression($payload ?: new \stdClass()),
            'status'            => MarketplaceOutbox::STATUS_PENDING,
            'source'            => $source,
            'import_session_id' => $sessionId,
        ])->execute();

        $this->stats['created']++;
    }

    // ═══════════════════════════════════════════
    // READING (для Worker)
    // ═══════════════════════════════════════════

    /**
     * Забрать батч pending-событий, сгруппированных по model_id + channel_id + lane.
     *
     * Использует UPDATE ... RETURNING с FOR UPDATE SKIP LOCKED.
     *
     * @return array<string, array> "model_id:channel_id:lane" => [outbox_rows]
     */
    public function fetchPendingBatch(int $limit = 100): array
    {
        $db = Yii::$app->db;

        // Атомарно забираем и помечаем как processing
        $rows = $db->createCommand("
            UPDATE {{%marketplace_outbox}}
            SET status = 'processing'
            WHERE id IN (
                SELECT id FROM {{%marketplace_outbox}}
                WHERE status = 'pending'
                ORDER BY created_at
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
            )
            RETURNING id, entity_type, entity_id, model_id, channel_id, source_event, lane, payload, import_session_id
        ", [':limit' => $limit])->queryAll();

        // Группируем по "model_id:channel_id:lane"
        $grouped = [];
        foreach ($rows as $row) {
            $key = (int)$row['model_id'] . ':' . (int)$row['channel_id'] . ':' . $row['lane'];
            $grouped[$key][] = $row;
        }

        return $grouped;
    }

    /**
     * Пометить записи как success.
     *
     * @param int[] $outboxIds
     */
    public function markSuccess(array $outboxIds): void
    {
        if (empty($outboxIds)) return;

        $ids = array_values($outboxIds);
        $placeholders = implode(',', array_map(fn($i) => ':id' . $i, array_keys($ids)));
        $params = [':time' => date('Y-m-d H:i:s')];
        foreach ($ids as $i => $id) {
            $params[':id' . $i] = $id;
        }

        Yii::$app->db->createCommand(
            "UPDATE {{%marketplace_outbox}} SET status = 'success', processed_at = :time WHERE id IN ({$placeholders})",
            $params
        )->execute();
    }

    /**
     * Пометить записи как error (временная ошибка, будет retry).
     *
     * @param int[] $outboxIds
     */
    public function markError(array $outboxIds, string $errorMessage): void
    {
        if (empty($outboxIds)) return;

        $ids = array_values($outboxIds);
        $placeholders = implode(',', array_map(fn($i) => ':id' . $i, array_keys($ids)));
        $params = [':err' => $errorMessage, ':time' => date('Y-m-d H:i:s')];
        foreach ($ids as $i => $id) {
            $params[':id' . $i] = $id;
        }

        Yii::$app->db->createCommand(
            "UPDATE {{%marketplace_outbox}} SET status = 'error', error_log = :err, processed_at = :time, retry_count = retry_count + 1 WHERE id IN ({$placeholders})",
            $params
        )->execute();
    }

    /**
     * Пометить записи как failed (постоянная ошибка 4xx, НЕ будет retry).
     * Сохраняет ошибку в DLQ (channel_sync_errors).
     *
     * @param int[]  $outboxIds
     * @param int    $channelId
     * @param int    $modelId
     * @param string $lane
     * @param string $errorMessage
     * @param int|null $errorCode   HTTP status code
     * @param array|null $payloadDump Дамп проекции
     */
    public function markFailed(
        array   $outboxIds,
        int     $channelId,
        int     $modelId,
        string  $lane,
        string  $errorMessage,
        ?int    $errorCode = null,
        ?array  $payloadDump = null
    ): void {
        if (empty($outboxIds)) return;

        // 1. Помечаем outbox-записи как failed
        $ids = array_values($outboxIds);
        $placeholders = implode(',', array_map(fn($i) => ':id' . $i, array_keys($ids)));
        $params = [':err' => $errorMessage, ':time' => date('Y-m-d H:i:s')];
        foreach ($ids as $i => $id) {
            $params[':id' . $i] = $id;
        }

        Yii::$app->db->createCommand(
            "UPDATE {{%marketplace_outbox}} SET status = 'failed', error_log = :err, processed_at = :time WHERE id IN ({$placeholders})",
            $params
        )->execute();

        // 2. Сохраняем ошибку в DLQ (channel_sync_errors)
        try {
            Yii::$app->db->createCommand()->insert('{{%channel_sync_errors}}', [
                'channel_id'    => $channelId,
                'entity_type'   => 'model',
                'entity_id'     => $modelId,
                'model_id'      => $modelId,
                'lane'          => $lane,
                'error_code'    => $errorCode,
                'error_message' => $errorMessage,
                'payload_dump'  => $payloadDump ? new JsonExpression($payloadDump) : null,
                'outbox_ids'    => '{' . implode(',', $ids) . '}', // PostgreSQL array literal
            ])->execute();
        } catch (\Throwable $e) {
            Yii::error("OutboxService: failed to write DLQ entry: {$e->getMessage()}", 'marketplace.outbox');
        }
    }

    /**
     * Вернуть error-записи обратно в pending (retry).
     */
    public function retryErrors(int $maxRetries = 3): int
    {
        return Yii::$app->db->createCommand("
            UPDATE {{%marketplace_outbox}}
            SET status = 'pending', error_log = NULL
            WHERE status = 'error' AND retry_count < :max
        ", [':max' => $maxRetries])->execute();
    }

    /**
     * Очистить старые success-записи.
     */
    public function cleanupOld(int $olderThanDays = 7): int
    {
        return Yii::$app->db->createCommand("
            DELETE FROM {{%marketplace_outbox}}
            WHERE status = 'success' AND processed_at < NOW() - INTERVAL ':days days'
        ", [':days' => $olderThanDays])->execute();
    }

    /**
     * Статистика Outbox.
     */
    public function getQueueStats(): array
    {
        $rows = Yii::$app->db->createCommand("
            SELECT status, count(*) as cnt FROM {{%marketplace_outbox}} GROUP BY status
        ")->queryAll();

        $stats = ['pending' => 0, 'processing' => 0, 'success' => 0, 'error' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $stats[$row['status']] = (int)$row['cnt'];
        }

        return $stats;
    }

    /**
     * Статистика по каналам.
     */
    public function getQueueStatsByChannel(): array
    {
        $rows = Yii::$app->db->createCommand("
            SELECT sc.name as channel_name, mo.status, mo.lane, count(*) as cnt
            FROM {{%marketplace_outbox}} mo
            JOIN {{%sales_channels}} sc ON sc.id = mo.channel_id
            GROUP BY sc.name, mo.status, mo.lane
            ORDER BY sc.name, mo.status, mo.lane
        ")->queryAll();

        $stats = [];
        foreach ($rows as $row) {
            $stats[$row['channel_name']][$row['lane']][$row['status']] = (int)$row['cnt'];
        }

        return $stats;
    }

    /**
     * Получить статистику текущей сессии.
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Сбросить счётчики.
     */
    public function resetStats(): void
    {
        $this->stats = ['total' => 0, 'created' => 0, 'deduplicated' => 0, 'blocked' => 0];
    }

    /**
     * Сбросить кэш активных каналов (для тестов / длинных процессов).
     */
    public function resetChannelCache(): void
    {
        $this->activeChannelsCache = null;
    }

    // ═══════════════════════════════════════════
    // PRIVATE
    // ═══════════════════════════════════════════

    /**
     * Получить список активных каналов (кэшируется на время запроса).
     *
     * @return SalesChannel[]
     */
    private function getActiveChannels(): array
    {
        if ($this->activeChannelsCache === null) {
            $this->activeChannelsCache = SalesChannel::findActive();
        }
        return $this->activeChannelsCache;
    }

    /**
     * Проверить готовность модели для канала (Sprint 12).
     *
     * Использует ReadinessScoringService с кэшированием результата.
     * Если сервис недоступен — пропускаем проверку (не блокируем).
     */
    private function checkReadiness(int $modelId, SalesChannel $channel): bool
    {
        try {
            /** @var ReadinessScoringService $readinessService */
            $readinessService = Yii::$app->get('readinessService');
            $report = $readinessService->evaluate($modelId, $channel, true);
            return $report->isReady;
        } catch (\Throwable $e) {
            // Если сервис недоступен или ошибка — не блокируем
            Yii::warning(
                "OutboxService: readiness check failed for model_id={$modelId}: {$e->getMessage()}",
                'marketplace.outbox'
            );
            return true;
        }
    }
}
