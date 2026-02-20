<?php

use yii\db\Migration;

/**
 * Поставщики, категории, атрибуты — базовые справочники.
 */
class m260220_080000_create_suppliers_and_categories extends Migration
{
    public function safeUp()
    {
        // === Поставщики ===
        $this->createTable('{{%suppliers}}', [
            'id' => $this->primaryKey(),
            'code' => $this->string(50)->notNull()->unique(),
            'name' => $this->string(255)->notNull(),
            'website' => $this->string(500),
            'format' => $this->string(20)->notNull()->defaultValue('xml'), // xml, csv, xlsx, json, api
            'parser_class' => $this->string(255),
            'config' => 'JSONB DEFAULT \'{}\'',
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'last_import_at' => $this->timestamp(),
            'created_at' => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at' => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        // === Категории (дерево) ===
        $this->createTable('{{%categories}}', [
            'id' => $this->primaryKey(),
            'parent_id' => $this->integer(),
            'name' => $this->string(255)->notNull(),
            'slug' => $this->string(255)->notNull()->unique(),
            'depth' => $this->smallInteger()->notNull()->defaultValue(0),
            'sort_order' => $this->integer()->notNull()->defaultValue(0),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'meta_title' => $this->string(500),
            'meta_description' => $this->text(),
            'created_at' => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at' => $this->timestamp()->defaultExpression('NOW()'),
        ]);
        $this->addForeignKey('fk_categories_parent', '{{%categories}}', 'parent_id', '{{%categories}}', 'id', 'SET NULL');

        // === Атрибуты (эталонные) ===
        $this->createTable('{{%attributes}}', [
            'id' => $this->primaryKey(),
            'code' => $this->string(100)->notNull()->unique(),
            'name' => $this->string(255)->notNull(),
            'type' => $this->string(30)->notNull()->defaultValue('string'), // string, integer, decimal, boolean, enum
            'unit' => $this->string(30),
            'is_filterable' => $this->boolean()->notNull()->defaultValue(false),
            'is_variant' => $this->boolean()->notNull()->defaultValue(false),
            'sort_order' => $this->integer()->notNull()->defaultValue(0),
            'created_at' => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        // === Начальные данные ===
        $this->insert('{{%suppliers}}', [
            'code' => 'ormatek',
            'name' => 'Орматек',
            'website' => 'https://ormatek.com',
            'format' => 'xml',
            'parser_class' => 'common\components\parsers\OrmatekXmlParser',
        ]);

        $this->batchInsert('{{%categories}}', ['name', 'slug', 'depth', 'sort_order'], [
            ['Матрасы', 'matrasy', 0, 1],
            ['Подушки', 'podushki', 0, 2],
            ['Одеяла', 'odeyala', 0, 3],
            ['Кровати', 'krovati', 0, 4],
            ['Основания', 'osnovaniya', 0, 5],
            ['Наматрасники', 'namatrasniki', 0, 6],
            ['Топперы', 'toppery', 0, 7],
            ['Аксессуары', 'aksessuary', 0, 8],
        ]);

        $this->batchInsert('{{%attributes}}', ['code', 'name', 'type', 'unit', 'is_filterable', 'is_variant', 'sort_order'], [
            ['width', 'Ширина', 'integer', 'см', true, true, 1],
            ['length', 'Длина', 'integer', 'см', true, true, 2],
            ['height', 'Высота', 'integer', 'см', true, false, 3],
            ['stiffness', 'Жёсткость', 'enum', null, true, false, 4],
            ['max_load', 'Макс. нагрузка', 'integer', 'кг', true, false, 5],
            ['spring_type', 'Тип пружин', 'enum', null, true, false, 6],
            ['material', 'Материал', 'string', null, true, false, 7],
            ['color', 'Цвет', 'string', null, true, true, 8],
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('{{%attributes}}');
        $this->dropTable('{{%categories}}');
        $this->dropTable('{{%suppliers}}');
    }
}
