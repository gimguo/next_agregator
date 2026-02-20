<?php

namespace common\services;

use common\models\SalesChannel;
use yii\base\Component;
use yii\db\JsonExpression;
use Yii;

/**
 * Сервис записи событий в Transactional Outbox (Multi-Channel Fan-out).
 *
 * Вызывается ВНУТРИ транзакции CatalogPersisterService / GoldenRecordService.
 * Гарантия: INSERT в outbox — часть той же транзакции, что и основная запись.
 *
 * Fan-out логика:
 *   При каждом событии (modelUpdated, priceChanged, ...) сервис получает
 *   список ВСЕХ активных каналов из sales_channels и создаёт запись
 *   в marketplace_outbox для КАЖДОГО канала.
 *   Т.е. одно изменение цены → N задач (по одной на каждый канал).
 *
 * Дедупликация: если для entity + channel уже есть pending-запись
 * с тем же event_type, новая НЕ создаётся.
 *
 * Использование:
 *   $outbox = Yii::$app->get('outbox');
 *   $outbox->modelCreated($modelId, $sessionId);
 *   $outbox->priceChanged($modelId, $variantId, $offerId, ['old' => 5000, 'new' => 4500]);
 */
class OutboxService extends Component
{
    /** @var bool Включить дедупликацию (не создавать дубли pending для одной сущности) */
    public bool $deduplication = true;

    /** @var array Счётчик событий текущей сессии */
    private array $stats = [
        'total'        => 0,
        'created'      => 0,
        'deduplicated' => 0,
    ];

    /** @var SalesChannel[]|null Кэш активных каналов (на время запроса) */
    private ?array $activeChannelsCache = null;

    // ═══════════════════════════════════════════
    // HIGH-LEVEL API (вызывается из Persister/GoldenRecord)
    // ═══════════════════════════════════════════

    /**
     * Новая модель создана.
     */
    public function modelCreated(int $modelId, ?string $sessionId = null, array $payload = []): void
    {
        $this->fanOut('model', $modelId, $modelId, 'created', $payload, 'catalog_persister', $sessionId);
    }

    /**
     * Модель обновлена (атрибуты, описание).
     */
    public function modelUpdated(int $modelId, ?string $sessionId = null, array $payload = []): void
    {
        $this->fanOut('model', $modelId, $modelId, 'updated', $payload, 'golden_record', $sessionId);
    }

    /**
     * Новый вариант создан.
     */
    public function variantCreated(int $modelId, int $variantId, ?string $sessionId = null, array $payload = []): void
    {
        $this->fanOut('variant', $variantId, $modelId, 'created', $payload, 'catalog_persister', $sessionId);
    }

    /**
     * Вариант обновлён (атрибуты, цена).
     */
    public function variantUpdated(int $modelId, int $variantId, ?string $sessionId = null, array $payload = []): void
    {
        $this->fanOut('variant', $variantId, $modelId, 'updated', $payload, 'golden_record', $sessionId);
    }

    /**
     * Новый оффер создан.
     */
    public function offerCreated(int $modelId, int $variantId, int $offerId, ?string $sessionId = null, array $payload = []): void
    {
        $this->fanOut('offer', $offerId, $modelId, 'created', $payload, 'catalog_persister', $sessionId);
    }

    /**
     * Оффер обновлён (цена, остатки).
     */
    public function offerUpdated(int $modelId, int $variantId, int $offerId, ?string $sessionId = null, array $payload = []): void
    {
        $this->fanOut('offer', $offerId, $modelId, 'updated', $payload, 'catalog_persister', $sessionId);
    }

    /**
     * Цена изменилась.
     */
    public function priceChanged(int $modelId, int $variantId, int $offerId, array $priceDelta = [], ?string $sessionId = null): void
    {
        $this->fanOut('offer', $offerId, $modelId, 'price_changed', $priceDelta, 'catalog_persister', $sessionId);
    }

    /**
     * Остатки изменились.
     */
    public function stockChanged(int $modelId, int $variantId, int $offerId, array $stockDelta = [], ?string $sessionId = null): void
    {
        $this->fanOut('offer', $offerId, $modelId, 'stock_changed', $stockDelta, 'catalog_persister', $sessionId);
    }

    // ═══════════════════════════════════════════
    // FAN-OUT: одно событие → N записей (по каналу)
    // ═══════════════════════════════════════════

    /**
     * Fan-out: создать outbox-запись для КАЖДОГО активного канала.
     */
    protected function fanOut(
        string  $entityType,
        int     $entityId,
        int     $modelId,
        string  $eventType,
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
            $this->emit($entityType, $entityId, $modelId, $eventType, $payload, $source, $sessionId, $channel->id);
        }
    }

    // ═══════════════════════════════════════════
    // CORE
    // ═══════════════════════════════════════════

    /**
     * Записать событие в Outbox для конкретного канала.
     *
     * ВАЖНО: Этот метод должен вызываться ВНУТРИ активной транзакции!
     */
    protected function emit(
        string  $entityType,
        int     $entityId,
        int     $modelId,
        string  $eventType,
        array   $payload = [],
        string  $source = 'catalog_persister',
        ?string $sessionId = null,
        int     $channelId = 0
    ): void {
        $this->stats['total']++;

        $db = Yii::$app->db;

        // Дедупликация: не создаём дубли для pending-записей той же сущности НА ТОМ ЖЕ КАНАЛЕ
        if ($this->deduplication) {
            $exists = $db->createCommand("
                SELECT 1 FROM {{%marketplace_outbox}}
                WHERE entity_type = :type AND entity_id = :eid AND status = 'pending' AND channel_id = :cid
                LIMIT 1
            ", [
                ':type' => $entityType,
                ':eid'  => $entityId,
                ':cid'  => $channelId,
            ])->queryScalar();

            if ($exists) {
                // Обновляем payload последней pending-записи (мержим дельту)
                $db->createCommand("
                    UPDATE {{%marketplace_outbox}}
                    SET payload = payload || :payload::jsonb,
                        event_type = :event,
                        created_at = NOW()
                    WHERE entity_type = :type AND entity_id = :eid AND status = 'pending' AND channel_id = :cid
                ", [
                    ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    ':event'   => $eventType,
                    ':type'    => $entityType,
                    ':eid'     => $entityId,
                    ':cid'     => $channelId,
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
            'event_type'        => $eventType,
            'payload'           => new JsonExpression($payload ?: new \stdClass()),
            'status'            => 'pending',
            'source'            => $source,
            'import_session_id' => $sessionId,
        ])->execute();

        $this->stats['created']++;
    }

    // ═══════════════════════════════════════════
    // READING (для Worker)
    // ═══════════════════════════════════════════

    /**
     * Забрать батч pending-событий, сгруппированных по model_id И channel_id.
     *
     * Использует SELECT ... FOR UPDATE SKIP LOCKED для безопасной конкурентной обработки.
     *
     * @return array<string, array> "model_id:channel_id" => [outbox_rows]
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
            RETURNING id, entity_type, entity_id, model_id, channel_id, event_type, payload, import_session_id
        ", [':limit' => $limit])->queryAll();

        // Группируем по "model_id:channel_id" — уникальный ключ для обработки
        $grouped = [];
        foreach ($rows as $row) {
            $key = (int)$row['model_id'] . ':' . (int)$row['channel_id'];
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
     * Пометить записи как error.
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

        $stats = ['pending' => 0, 'processing' => 0, 'success' => 0, 'error' => 0];
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
            SELECT sc.name as channel_name, mo.status, count(*) as cnt
            FROM {{%marketplace_outbox}} mo
            JOIN {{%sales_channels}} sc ON sc.id = mo.channel_id
            GROUP BY sc.name, mo.status
            ORDER BY sc.name, mo.status
        ")->queryAll();

        $stats = [];
        foreach ($rows as $row) {
            $stats[$row['channel_name']][$row['status']] = (int)$row['cnt'];
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
        $this->stats = ['total' => 0, 'created' => 0, 'deduplicated' => 0];
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
}
