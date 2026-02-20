<?php

use yii\db\Migration;

/**
 * Transactional Outbox — таблица очереди изменений для синдикации.
 *
 * Паттерн:
 *   Каждое изменение в MDM-каталоге (UPSERT оффера, обновление цены, создание модели/варианта)
 *   в рамках ТОЙ ЖЕ транзакции пишет запись в marketplace_outbox.
 *   Worker (крон каждую минуту) забирает pending-записи, группирует по model_id,
 *   строит полную проекцию и отправляет на витрину.
 *
 * Гарантии:
 *   - Atomicity: INSERT в outbox = часть транзакции Persister'а
 *   - At-least-once delivery: если Worker упал → записи остаются pending
 *   - Idempotency: витрина должна уметь принять ту же проекцию повторно
 *   - Grouping: если 5 вариантов модели изменились → одна отправка
 *
 * Порядок обработки:
 *   1. Worker SELECT ... WHERE status='pending' ORDER BY created_at LIMIT N FOR UPDATE SKIP LOCKED
 *   2. UPDATE status='processing'
 *   3. Build projection → Send to marketplace
 *   4. UPDATE status='success' / 'error'
 */
class m260221_010000_create_marketplace_outbox extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%marketplace_outbox}}', [
            'id'           => $this->bigPrimaryKey(),

            // Что изменилось
            'entity_type'  => $this->string(20)->notNull(),     // 'model', 'variant', 'offer'
            'entity_id'    => $this->integer()->notNull(),       // ID модели/варианта/оффера
            'model_id'     => $this->integer()->notNull(),       // Всегда заполнен — для группировки

            // Тип события
            'event_type'   => $this->string(30)->notNull(),     // 'created', 'updated', 'deleted', 'price_changed', 'stock_changed', 'attributes_updated'

            // Дельта (опционально — для отладки и оптимизации)
            'payload'      => 'JSONB DEFAULT \'{}\'',           // {"old_price": 5000, "new_price": 4500}

            // Обработка
            'status'       => $this->string(20)->notNull()->defaultValue('pending'), // 'pending', 'processing', 'success', 'error'
            'retry_count'  => $this->smallInteger()->notNull()->defaultValue(0),
            'error_log'    => $this->text(),
            'processed_at' => $this->timestamp(),

            // Метаданные
            'source'       => $this->string(50),                 // 'catalog_persister', 'golden_record', 'manual'
            'import_session_id' => $this->string(100),           // Привязка к сессии импорта

            'created_at'   => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        // ═══ ИНДЕКСЫ ═══

        // Главный рабочий индекс: Worker забирает pending → по времени
        $this->createIndex(
            'idx-outbox-status_created',
            '{{%marketplace_outbox}}',
            ['status', 'created_at']
        );

        // Группировка по модели (Worker группирует события одной модели)
        $this->createIndex(
            'idx-outbox-model',
            '{{%marketplace_outbox}}',
            'model_id'
        );

        // Для дедупликации: не создавать дубли для той же сущности
        $this->createIndex(
            'idx-outbox-entity',
            '{{%marketplace_outbox}}',
            ['entity_type', 'entity_id', 'status']
        );

        // Для аналитики: сколько событий за период
        $this->createIndex(
            'idx-outbox-created',
            '{{%marketplace_outbox}}',
            'created_at'
        );

        // FK к product_models (каскад — удалена модель = удалены события)
        $this->addForeignKey(
            'fk-outbox-model',
            '{{%marketplace_outbox}}',
            'model_id',
            '{{%product_models}}',
            'id',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%marketplace_outbox}}');
    }
}
