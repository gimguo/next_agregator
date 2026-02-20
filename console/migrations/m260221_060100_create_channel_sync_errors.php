<?php

use yii\db\Migration;

/**
 * Sprint 10 — Dead Letter Queue: таблица channel_sync_errors.
 *
 * Проблема:
 *   Если маркетплейс отвечает 4xx (ошибка валидации), бесконечный retry бесполезен:
 *   данные не поменяются, и результат будет тот же. Нужно:
 *     1. Остановить retry для этой задачи (status = 'failed')
 *     2. Сохранить ошибку + payload для отладки
 *     3. Показать менеджеру что пошло не так (export/errors)
 *
 * Решение:
 *   Таблица channel_sync_errors — DLQ (Dead Letter Queue).
 *   Хранит: канал, сущность, текст ошибки, дамп payload, время.
 *
 * Также добавляем статус 'failed' в outbox (помимо error/pending/success/processing):
 *   - error     = временная ошибка, будет retry
 *   - failed    = постоянная ошибка (4xx), НЕ будет retry
 */
class m260221_060100_create_channel_sync_errors extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%channel_sync_errors}}', [
            'id'            => $this->bigPrimaryKey(),
            'channel_id'    => $this->integer()->notNull(),
            'entity_type'   => $this->string(20)->notNull(),     // 'model', 'variant', 'offer'
            'entity_id'     => $this->integer()->notNull(),
            'model_id'      => $this->integer()->notNull(),       // Для удобства поиска
            'lane'          => $this->string(30)->notNull(),      // 'content_updated', 'price_updated', 'stock_updated'
            'error_code'    => $this->smallInteger(),             // HTTP status code (400, 422, ...)
            'error_message' => $this->text()->notNull(),
            'payload_dump'  => 'JSONB',                           // Дамп проекции для отладки
            'outbox_ids'    => 'INTEGER[]',                       // ID outbox-записей, которые привели к ошибке
            'resolved_at'   => $this->timestamp(),                // Когда ошибка была исправлена
            'created_at'    => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        // FK → sales_channels
        $this->addForeignKey(
            'fk-sync_errors-channel',
            '{{%channel_sync_errors}}',
            'channel_id',
            '{{%sales_channels}}',
            'id',
            'CASCADE'
        );

        // Индекс для вывода ошибок по каналу
        $this->createIndex(
            'idx-sync_errors-channel',
            '{{%channel_sync_errors}}',
            ['channel_id', 'created_at']
        );

        // Индекс для поиска по модели
        $this->createIndex(
            'idx-sync_errors-model',
            '{{%channel_sync_errors}}',
            'model_id'
        );

        // Индекс для фильтрации неразрешённых ошибок
        $this->createIndex(
            'idx-sync_errors-unresolved',
            '{{%channel_sync_errors}}',
            ['channel_id', 'resolved_at']
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%channel_sync_errors}}');
    }
}
