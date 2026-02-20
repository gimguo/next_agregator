<?php

use yii\db\Migration;

/**
 * Перестройка card_data_sources в полиморфную структуру.
 *
 * Старая таблица:
 *   card_id, offer_id (NOT NULL), provides_*, data_quality_score, attributes_taken, images_taken
 *   Проблема: offer_id NOT NULL не позволяет хранить AI/ручные источники.
 *
 * Новая таблица (полиморфная):
 *   card_id, source_type (supplier|ai_enrichment|manual_override), source_id (код поставщика или 'deepseek'),
 *   data (JSONB — любые данные от источника), priority, confidence
 *
 * source_type enum:
 *   - 'supplier'         — данные от поставщика (source_id = код поставщика, например 'ormatek')
 *   - 'ai_enrichment'    — общее AI-обогащение (описание, качество)
 *   - 'ai_attributes'    — AI-извлечённые атрибуты
 *   - 'ai_categorization' — AI-категоризация
 *   - 'manual_override'  — ручная правка менеджером
 */
class m260220_160000_rebuild_card_data_sources extends Migration
{
    public function safeUp()
    {
        // --- Удаляем старую таблицу ---
        $this->dropForeignKey('fk_datasrc_offer', '{{%card_data_sources}}');
        $this->dropForeignKey('fk_datasrc_card', '{{%card_data_sources}}');
        $this->dropIndex('idx_datasrc_unique', '{{%card_data_sources}}');
        $this->dropTable('{{%card_data_sources}}');

        // --- Создаём новую полиморфную таблицу ---
        $this->createTable('{{%card_data_sources}}', [
            'id'          => $this->primaryKey(),
            'card_id'     => $this->integer()->notNull(),

            // Тип источника: supplier, ai_enrichment, ai_attributes, ai_categorization, manual_override
            'source_type' => $this->string(30)->notNull(),

            // ID источника: код поставщика ('ormatek'), AI-модель ('deepseek'), user ID, и т.д.
            'source_id'   => $this->string(100),

            // Полезная нагрузка от источника (любые данные в JSON)
            'data'        => 'JSONB NOT NULL DEFAULT \'{}\'',

            // Приоритет: кто "главнее" при конфликте данных (выше = приоритетнее)
            // supplier=30, ai=50, manual=90
            'priority'    => $this->smallInteger()->notNull()->defaultValue(50),

            // Уверенность источника (0.00–1.00), nullable для ручных данных
            'confidence'  => $this->decimal(3, 2),

            'created_at'  => $this->timestamp()->notNull()->defaultExpression('NOW()'),
            'updated_at'  => $this->timestamp()->notNull()->defaultExpression('NOW()'),
        ]);

        // FK к карточке
        $this->addForeignKey(
            'fk_datasrc_card',
            '{{%card_data_sources}}', 'card_id',
            '{{%product_cards}}', 'id',
            'CASCADE'
        );

        // Уникальность: одна карточка + один тип + один источник
        $this->createIndex(
            'idx_datasrc_unique',
            '{{%card_data_sources}}',
            ['card_id', 'source_type', 'source_id'],
            true
        );

        // Быстрый поиск по типу источника
        $this->createIndex(
            'idx_datasrc_type',
            '{{%card_data_sources}}',
            'source_type'
        );

        // GIN-индекс на JSONB data для быстрых запросов вглубь
        $this->execute('CREATE INDEX idx_datasrc_data ON {{%card_data_sources}} USING gin(data)');
    }

    public function safeDown()
    {
        // --- Откат: восстанавливаем старую структуру ---
        $this->dropIndex('idx_datasrc_data', '{{%card_data_sources}}');
        $this->dropIndex('idx_datasrc_type', '{{%card_data_sources}}');
        $this->dropIndex('idx_datasrc_unique', '{{%card_data_sources}}');
        $this->dropForeignKey('fk_datasrc_card', '{{%card_data_sources}}');
        $this->dropTable('{{%card_data_sources}}');

        // Воссоздаём старую таблицу
        $this->createTable('{{%card_data_sources}}', [
            'id' => $this->primaryKey(),
            'card_id' => $this->integer()->notNull(),
            'offer_id' => $this->integer()->notNull(),
            'provides_description' => $this->boolean()->defaultValue(false),
            'provides_attributes' => $this->boolean()->defaultValue(false),
            'provides_images' => $this->boolean()->defaultValue(false),
            'provides_name' => $this->boolean()->defaultValue(false),
            'data_quality_score' => $this->smallInteger()->defaultValue(50),
            'attributes_taken' => 'JSONB DEFAULT \'[]\'',
            'images_taken' => 'JSONB DEFAULT \'[]\'',
            'created_at' => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at' => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        $this->addForeignKey('fk_datasrc_card', '{{%card_data_sources}}', 'card_id', '{{%product_cards}}', 'id', 'CASCADE');
        $this->addForeignKey('fk_datasrc_offer', '{{%card_data_sources}}', 'offer_id', '{{%supplier_offers}}', 'id', 'CASCADE');
        $this->createIndex('idx_datasrc_unique', '{{%card_data_sources}}', ['card_id', 'offer_id'], true);
    }
}
