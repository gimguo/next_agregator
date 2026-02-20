<?php

namespace common\services;

use yii\base\Component;
use yii\db\JsonExpression;
use Yii;

/**
 * Сервис записи событий в Transactional Outbox.
 *
 * Вызывается ВНУТРИ транзакции CatalogPersisterService / GoldenRecordService.
 * Гарантия: INSERT в outbox — часть той же транзакции, что и основная запись.
 *
 * Дедупликация: если для entity уже есть pending-запись с тем же event_type,
 * новая НЕ создаётся (чтобы Worker не обрабатывал одну модель 50 раз).
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
        'total'      => 0,
        'created'    => 0,
        'deduplicated' => 0,
    ];

    // ═══════════════════════════════════════════
    // HIGH-LEVEL API (вызывается из Persister/GoldenRecord)
    // ═══════════════════════════════════════════

    /**
     * Новая модель создана.
     */
    public function modelCreated(int $modelId, ?string $sessionId = null, array $payload = []): void
    {
        $this->emit('model', $modelId, $modelId, 'created', $payload, 'catalog_persister', $sessionId);
    }

    /**
     * Модель обновлена (атрибуты, описание).
     */
    public function modelUpdated(int $modelId, ?string $sessionId = null, array $payload = []): void
    {
        $this->emit('model', $modelId, $modelId, 'updated', $payload, 'golden_record', $sessionId);
    }

    /**
     * Новый вариант создан.
     */
    public function variantCreated(int $modelId, int $variantId, ?string $sessionId = null, array $payload = []): void
    {
        $this->emit('variant', $variantId, $modelId, 'created', $payload, 'catalog_persister', $sessionId);
    }

    /**
     * Вариант обновлён (атрибуты, цена).
     */
    public function variantUpdated(int $modelId, int $variantId, ?string $sessionId = null, array $payload = []): void
    {
        $this->emit('variant', $variantId, $modelId, 'updated', $payload, 'golden_record', $sessionId);
    }

    /**
     * Новый оффер создан.
     */
    public function offerCreated(int $modelId, int $variantId, int $offerId, ?string $sessionId = null, array $payload = []): void
    {
        $this->emit('offer', $offerId, $modelId, 'created', $payload, 'catalog_persister', $sessionId);
    }

    /**
     * Оффер обновлён (цена, остатки).
     */
    public function offerUpdated(int $modelId, int $variantId, int $offerId, ?string $sessionId = null, array $payload = []): void
    {
        $this->emit('offer', $offerId, $modelId, 'updated', $payload, 'catalog_persister', $sessionId);
    }

    /**
     * Цена изменилась.
     */
    public function priceChanged(int $modelId, int $variantId, int $offerId, array $priceDelta = [], ?string $sessionId = null): void
    {
        $this->emit('offer', $offerId, $modelId, 'price_changed', $priceDelta, 'catalog_persister', $sessionId);
    }

    /**
     * Остатки изменились.
     */
    public function stockChanged(int $modelId, int $variantId, int $offerId, array $stockDelta = [], ?string $sessionId = null): void
    {
        $this->emit('offer', $offerId, $modelId, 'stock_changed', $stockDelta, 'catalog_persister', $sessionId);
    }

    // ═══════════════════════════════════════════
    // CORE
    // ═══════════════════════════════════════════

    /**
     * Записать событие в Outbox.
     *
     * ВАЖНО: Этот метод должен вызываться ВНУТРИ активной транзакции!
     */
    protected function emit(
        string $entityType,
        int    $entityId,
        int    $modelId,
        string $eventType,
        array  $payload = [],
        string $source = 'catalog_persister',
        ?string $sessionId = null
    ): void {
        $this->stats['total']++;

        $db = Yii::$app->db;

        // Дедупликация: не создаём дубли для pending-записей той же сущности
        if ($this->deduplication) {
            $exists = $db->createCommand("
                SELECT 1 FROM {{%marketplace_outbox}}
                WHERE entity_type = :type AND entity_id = :eid AND status = 'pending'
                LIMIT 1
            ", [
                ':type' => $entityType,
                ':eid'  => $entityId,
            ])->queryScalar();

            if ($exists) {
                // Обновляем payload последней pending-записи (мержим дельту)
                $db->createCommand("
                    UPDATE {{%marketplace_outbox}}
                    SET payload = payload || :payload::jsonb,
                        event_type = :event,
                        created_at = NOW()
                    WHERE entity_type = :type AND entity_id = :eid AND status = 'pending'
                ", [
                    ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    ':event'   => $eventType,
                    ':type'    => $entityType,
                    ':eid'     => $entityId,
                ])->execute();

                $this->stats['deduplicated']++;
                return;
            }
        }

        $db->createCommand()->insert('{{%marketplace_outbox}}', [
            'entity_type'       => $entityType,
            'entity_id'         => $entityId,
            'model_id'          => $modelId,
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
     * Забрать батч pending-событий, сгруппированных по model_id.
     *
     * Использует SELECT ... FOR UPDATE SKIP LOCKED для безопасной конкурентной обработки.
     *
     * @return array<int, array> model_id => [outbox_rows]
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
            RETURNING id, entity_type, entity_id, model_id, event_type, payload, import_session_id
        ", [':limit' => $limit])->queryAll();

        // Группируем по model_id
        $grouped = [];
        foreach ($rows as $row) {
            $modelId = (int)$row['model_id'];
            $grouped[$modelId][] = $row;
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
}
