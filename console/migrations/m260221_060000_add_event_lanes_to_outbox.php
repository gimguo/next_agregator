<?php

use yii\db\Migration;

/**
 * Sprint 10 — Fast-Lane: Event Lanes в Outbox.
 *
 * Проблема:
 *   Текущий event_type хранит ЧТО случилось ('created', 'updated', 'price_changed', 'stock_changed').
 *   Но воркеру нужно знать КАКОЙ ТИП ОБНОВЛЕНИЯ отправить на канал:
 *     - content_updated → полная проекция (тяжёлая, включает атрибуты, картинки)
 *     - price_updated   → только SKU + Price (лёгкая)
 *     - stock_updated   → только SKU + Qty (лёгкая)
 *
 * Решение:
 *   1. Переименовываем event_type → source_event (что произошло, для аудита)
 *   2. Добавляем lane (string) — тип обновления для воркера
 *   3. Обновляем индекс дедупликации: entity_type + entity_id + status + channel_id + lane
 *      Это позволяет одновременно держать pending-задачу на контент И на цену.
 *
 * Маппинг (OutboxService):
 *   modelCreated/Updated → lane = 'content_updated'
 *   priceChanged         → lane = 'price_updated'
 *   stockChanged         → lane = 'stock_updated'
 */
class m260221_060000_add_event_lanes_to_outbox extends Migration
{
    public function safeUp()
    {
        // 1. Переименовываем event_type → source_event (сохраняем для аудита)
        $this->renameColumn('{{%marketplace_outbox}}', 'event_type', 'source_event');

        // 2. Добавляем колонку lane (тип обновления для воркера)
        $this->addColumn(
            '{{%marketplace_outbox}}',
            'lane',
            $this->string(30)->notNull()->defaultValue('content_updated')->after('source_event')
        );

        // 3. Маппинг существующих записей на lanes
        //    price_changed → price_updated
        //    stock_changed → stock_updated
        //    всё остальное → content_updated (default)
        $this->execute("
            UPDATE {{%marketplace_outbox}}
            SET lane = CASE
                WHEN source_event = 'price_changed' THEN 'price_updated'
                WHEN source_event = 'stock_changed'  THEN 'stock_updated'
                ELSE 'content_updated'
            END
        ");

        // 4. Удаляем старый индекс дедупликации (entity_type + entity_id + status + channel_id)
        $this->dropIndex('idx-outbox-entity-channel', '{{%marketplace_outbox}}');

        // 5. Новый индекс дедупликации С lane
        //    Позволяет одновременно иметь:
        //      pending (model=1, channel=1, lane=content_updated)
        //      pending (model=1, channel=1, lane=price_updated)
        $this->createIndex(
            'idx-outbox-dedup',
            '{{%marketplace_outbox}}',
            ['entity_type', 'entity_id', 'status', 'channel_id', 'lane']
        );

        // 6. Индекс для воркера: быстрая выборка pending по lane
        $this->createIndex(
            'idx-outbox-lane',
            '{{%marketplace_outbox}}',
            ['status', 'lane', 'created_at']
        );
    }

    public function safeDown()
    {
        $this->dropIndex('idx-outbox-lane', '{{%marketplace_outbox}}');
        $this->dropIndex('idx-outbox-dedup', '{{%marketplace_outbox}}');

        // Восстанавливаем старый индекс
        $this->createIndex(
            'idx-outbox-entity-channel',
            '{{%marketplace_outbox}}',
            ['entity_type', 'entity_id', 'status', 'channel_id']
        );

        $this->dropColumn('{{%marketplace_outbox}}', 'lane');
        $this->renameColumn('{{%marketplace_outbox}}', 'source_event', 'event_type');
    }
}
