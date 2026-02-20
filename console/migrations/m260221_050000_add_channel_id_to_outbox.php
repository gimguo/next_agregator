<?php

use yii\db\Migration;

/**
 * Добавление channel_id в marketplace_outbox для многоканальной синдикации (Fan-out).
 *
 * Теперь каждая outbox-запись привязана к конкретному каналу продаж.
 * Одно изменение модели → N записей (по одной на каждый активный канал).
 *
 * Уникальность дедупликации: entity_type + entity_id + status + channel_id
 * (не создавать два pending для одной сущности на одном канале).
 */
class m260221_050000_add_channel_id_to_outbox extends Migration
{
    public function safeUp()
    {
        // 1. Добавляем колонку channel_id (nullable на время миграции)
        $this->addColumn('{{%marketplace_outbox}}', 'channel_id', $this->integer()->after('model_id'));

        // 2. Заполняем существующие записи первым каналом (RosMatras, id=1)
        $rosmatrasId = (new \yii\db\Query())
            ->select('id')
            ->from('{{%sales_channels}}')
            ->where(['driver' => 'rosmatras'])
            ->limit(1)
            ->scalar();

        if ($rosmatrasId) {
            $this->update('{{%marketplace_outbox}}', ['channel_id' => $rosmatrasId]);
        }

        // 3. Делаем NOT NULL
        $this->alterColumn('{{%marketplace_outbox}}', 'channel_id', $this->integer()->notNull());

        // 4. FK на sales_channels
        $this->addForeignKey(
            'fk-outbox-channel',
            '{{%marketplace_outbox}}',
            'channel_id',
            '{{%sales_channels}}',
            'id',
            'CASCADE'
        );

        // 5. Удаляем старый индекс дедупликации (entity_type + entity_id + status)
        $this->dropIndex('idx-outbox-entity', '{{%marketplace_outbox}}');

        // 6. Новый индекс дедупликации С channel_id
        $this->createIndex(
            'idx-outbox-entity-channel',
            '{{%marketplace_outbox}}',
            ['entity_type', 'entity_id', 'status', 'channel_id']
        );

        // 7. Индекс для Worker: fetch по channel_id
        $this->createIndex(
            'idx-outbox-channel',
            '{{%marketplace_outbox}}',
            'channel_id'
        );
    }

    public function safeDown()
    {
        $this->dropIndex('idx-outbox-channel', '{{%marketplace_outbox}}');
        $this->dropIndex('idx-outbox-entity-channel', '{{%marketplace_outbox}}');

        $this->dropForeignKey('fk-outbox-channel', '{{%marketplace_outbox}}');
        $this->dropColumn('{{%marketplace_outbox}}', 'channel_id');

        // Восстанавливаем старый индекс
        $this->createIndex(
            'idx-outbox-entity',
            '{{%marketplace_outbox}}',
            ['entity_type', 'entity_id', 'status']
        );
    }
}
