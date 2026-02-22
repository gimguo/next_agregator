<?php

use yii\db\Migration;

/**
 * Sprint 17: Catalog Builder — таблицы для управления каталогом товаров для витрины.
 *
 * Таблицы:
 *   - catalog_templates: шаблоны структуры каталога
 *   - catalog_previews: сохранённые предпросмотры каталога
 *   - catalog_exports: история экспортов на витрину
 */
class m260223_010000_create_catalog_builder_tables extends Migration
{
    public function safeUp()
    {
        // ═══ catalog_templates ═══
        $this->createTable('{{%catalog_templates}}', [
            'id' => $this->primaryKey()->unsigned()->comment('ID шаблона'),
            'name' => $this->string(255)->notNull()->comment('Название шаблона'),
            'description' => $this->text()->comment('Описание'),
            'structure_json' => $this->db->getSchema()->createColumnSchemaBuilder('jsonb')->notNull()->comment('Структура категорий (JSONB)'),
            'merge_rules' => $this->db->getSchema()->createColumnSchemaBuilder('jsonb')->comment('Правила объединения категорий (JSONB)'),
            'is_system' => $this->boolean()->defaultValue(false)->comment('Системный (нельзя удалить)'),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP')->comment('Дата создания'),
            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP')->comment('Дата обновления'),
        ]);

        $this->createIndex('idx-catalog-templates-is-system', '{{%catalog_templates}}', 'is_system');

        // ═══ catalog_previews ═══
        // supplier_ids используем PostgreSQL массив integer[]
        $this->createTable('{{%catalog_previews}}', [
            'id' => $this->primaryKey()->unsigned()->comment('ID предпросмотра'),
            'template_id' => $this->integer()->unsigned()->notNull()->comment('ID шаблона'),
            'name' => $this->string(255)->comment('Название предпросмотра'),
            'supplier_ids' => $this->db->getSchema()->createColumnSchemaBuilder('integer[]')->notNull()->comment('Массив ID поставщиков (PostgreSQL array)'),
            'preview_data' => $this->db->getSchema()->createColumnSchemaBuilder('jsonb')->comment('Данные предпросмотра (JSONB)'),
            'product_count' => $this->integer()->defaultValue(0)->comment('Всего товаров'),
            'category_count' => $this->integer()->defaultValue(0)->comment('Всего категорий'),
            'created_by' => $this->integer()->unsigned()->comment('ID пользователя'),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP')->comment('Дата создания'),
            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP')->comment('Дата обновления'),
        ]);

        $this->addForeignKey(
            'fk-catalog-previews-template-id',
            '{{%catalog_previews}}',
            'template_id',
            '{{%catalog_templates}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->createIndex('idx-catalog-previews-template-id', '{{%catalog_previews}}', 'template_id');
        $this->createIndex('idx-catalog-previews-created-by', '{{%catalog_previews}}', 'created_by');

        // ═══ catalog_exports ═══
        $this->createTable('{{%catalog_exports}}', [
            'id' => $this->primaryKey()->unsigned()->comment('ID экспорта'),
            'preview_id' => $this->integer()->unsigned()->notNull()->comment('ID предпросмотра'),
            'status' => $this->string(20)->defaultValue('pending')->comment('Статус: pending, processing, completed, failed'),
            'stats_json' => $this->db->getSchema()->createColumnSchemaBuilder('jsonb')->comment('Статистика экспорта (JSONB)'),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP')->comment('Дата создания'),
        ]);

        $this->addForeignKey(
            'fk-catalog-exports-preview-id',
            '{{%catalog_exports}}',
            'preview_id',
            '{{%catalog_previews}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->createIndex('idx-catalog-exports-preview-id', '{{%catalog_exports}}', 'preview_id');
        $this->createIndex('idx-catalog-exports-status', '{{%catalog_exports}}', 'status');
    }

    public function safeDown()
    {
        $this->dropTable('{{%catalog_exports}}');
        $this->dropTable('{{%catalog_previews}}');
        $this->dropTable('{{%catalog_templates}}');
    }
}
