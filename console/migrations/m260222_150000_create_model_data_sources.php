<?php

use yii\db\Migration;

/**
 * Sprint 15: Таблица model_data_sources — полиморфное хранилище источников данных для MDM моделей.
 *
 * Аналогична card_data_sources, но привязана к product_models (Golden Record).
 * Используется для Manual Override: когда менеджер вручную правит карточку,
 * изменения записываются с source_type='manual_override' и priority=100,
 * что перекрывает данные от AI (50) и поставщиков (30).
 *
 * source_type:
 *   - 'supplier'         — данные от поставщика (priority=30)
 *   - 'ai_enrichment'    — AI-сгенерированный контент (priority=50)
 *   - 'ai_attributes'    — AI-определённые атрибуты (priority=50)
 *   - 'manual_override'  — ручная правка менеджером (priority=100)
 */
class m260222_150000_create_model_data_sources extends Migration
{
    public function safeUp()
    {
        // Проверяем, существует ли таблица
        $tableExists = $this->db->createCommand(
            "SELECT 1 FROM information_schema.tables WHERE table_name = 'model_data_sources' LIMIT 1"
        )->queryScalar();

        if ($tableExists) {
            echo "    > Таблица model_data_sources уже существует, пропускаем.\n";
            return;
        }

        $this->createTable('{{%model_data_sources}}', [
            'id'          => $this->primaryKey(),
            'model_id'    => $this->integer()->notNull(),

            // Тип источника
            'source_type' => $this->string(30)->notNull(),

            // ID источника: код поставщика ('ormatek'), AI-модель ('deepseek'), user_id, и т.д.
            'source_id'   => $this->string(100),

            // Полезная нагрузка (описание, атрибуты, любые данные)
            'data'        => 'JSONB NOT NULL DEFAULT \'{}\'',

            // Приоритет: кто «главнее» при конфликте (выше = приоритетнее)
            // supplier=30, ai=50, manual=100
            'priority'    => $this->smallInteger()->notNull()->defaultValue(50),

            // Уверенность источника (0.00–1.00), nullable для ручных данных
            'confidence'  => $this->decimal(3, 2),

            // Кто и когда отредактировал (для manual_override)
            'updated_by'  => $this->integer(),

            'created_at'  => $this->timestamp()->notNull()->defaultExpression('NOW()'),
            'updated_at'  => $this->timestamp()->notNull()->defaultExpression('NOW()'),
        ]);

        // FK к product_models
        $this->addForeignKey(
            'fk_mdatasrc_model',
            '{{%model_data_sources}}', 'model_id',
            '{{%product_models}}', 'id',
            'CASCADE'
        );

        // Уникальность: одна модель + один тип + один источник
        $this->createIndex(
            'idx_mdatasrc_unique',
            '{{%model_data_sources}}',
            ['model_id', 'source_type', 'source_id'],
            true
        );

        // Быстрый поиск по типу
        $this->createIndex(
            'idx_mdatasrc_type',
            '{{%model_data_sources}}',
            'source_type'
        );

        // GIN-индекс на JSONB для поиска вглубь
        $this->execute('CREATE INDEX idx_mdatasrc_data ON {{%model_data_sources}} USING gin(data)');

        echo "    > Таблица model_data_sources создана.\n";
    }

    public function safeDown()
    {
        $this->dropTable('{{%model_data_sources}}');
    }
}
