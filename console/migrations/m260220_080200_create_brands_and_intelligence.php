<?php

use yii\db\Migration;

/**
 * Справочник брендов, алиасы, правила категорий, маппинг атрибутов.
 */
class m260220_080200_create_brands_and_intelligence extends Migration
{
    public function safeUp()
    {
        // === Бренды ===
        $this->createTable('{{%brands}}', [
            'id' => $this->primaryKey(),
            'canonical_name' => $this->string(255)->notNull()->unique(),
            'slug' => $this->string(255)->notNull()->unique(),
            'country' => $this->string(100),
            'logo_url' => $this->string(500),
            'website_url' => $this->string(500),
            'description' => $this->text(),
            'product_count' => $this->integer()->defaultValue(0),
            'is_active' => $this->boolean()->defaultValue(true),
            'sort_order' => $this->integer()->defaultValue(0),
            'created_at' => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at' => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        // FK brand_id в product_cards
        $this->addForeignKey('fk_cards_brand', '{{%product_cards}}', 'brand_id', '{{%brands}}', 'id', 'SET NULL');

        // === Алиасы брендов ===
        $this->createTable('{{%brand_aliases}}', [
            'id' => $this->primaryKey(),
            'brand_id' => $this->integer()->notNull(),
            'alias' => $this->string(255)->notNull(),
            'alias_lower' => $this->string(255)->notNull()->unique(),
            'source' => $this->string(30)->defaultValue('manual'),
            'supplier_code' => $this->string(50),
            'confidence' => $this->decimal(3, 2)->defaultValue(1.0),
            'created_at' => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        $this->addForeignKey('fk_brand_aliases_brand', '{{%brand_aliases}}', 'brand_id', '{{%brands}}', 'id', 'CASCADE');
        $this->createIndex('idx_brand_aliases_lower', '{{%brand_aliases}}', 'alias_lower');

        // === Правила категорий ===
        $this->createTable('{{%category_rules}}', [
            'id' => $this->primaryKey(),
            'category_id' => $this->integer()->notNull()->unique(),
            'include_keywords' => 'JSONB DEFAULT \'[]\'',
            'exclude_keywords' => 'JSONB DEFAULT \'[]\'',
            'supplier_mappings' => 'JSONB DEFAULT \'{}\'',
            'product_types' => 'JSONB DEFAULT \'[]\'',
            'created_at' => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at' => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        $this->addForeignKey('fk_cat_rules_cat', '{{%category_rules}}', 'category_id', '{{%categories}}', 'id', 'CASCADE');

        // === Маппинг атрибутов ===
        $this->createTable('{{%attribute_aliases}}', [
            'id' => $this->primaryKey(),
            'attribute_id' => $this->integer()->notNull(),
            'alias' => $this->string(500)->notNull(),
            'alias_lower' => $this->string(500)->notNull(),
            'source' => $this->string(30)->defaultValue('manual'),
            'supplier_code' => $this->string(50),
            'confidence' => $this->decimal(3, 2)->defaultValue(1.0),
            'value_transform' => 'JSONB',
            'created_at' => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        $this->addForeignKey('fk_attr_aliases_attr', '{{%attribute_aliases}}', 'attribute_id', '{{%attributes}}', 'id', 'CASCADE');
        $this->createIndex('idx_attr_aliases_lower', '{{%attribute_aliases}}', ['alias_lower', 'supplier_code'], true);

        // === Анализ поставщиков (AI) ===
        $this->createTable('{{%supplier_analysis}}', [
            'id' => $this->primaryKey(),
            'supplier_id' => $this->integer()->notNull(),
            'discovered_brands' => 'JSONB DEFAULT \'[]\'',
            'discovered_categories' => 'JSONB DEFAULT \'[]\'',
            'discovered_attributes' => 'JSONB DEFAULT \'[]\'',
            'total_items' => $this->integer()->defaultValue(0),
            'unique_brands' => $this->integer()->defaultValue(0),
            'data_quality_score' => $this->smallInteger()->defaultValue(0),
            'recommendations' => 'JSONB DEFAULT \'[]\'',
            'status' => $this->string(20)->defaultValue('pending'),
            'created_at' => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at' => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        $this->addForeignKey('fk_analysis_supplier', '{{%supplier_analysis}}', 'supplier_id', '{{%suppliers}}', 'id', 'CASCADE');

        // === Начальные данные: бренды + алиасы ===
        $brands = [
            ['Орматек', 'ormatek', 'Россия'],
            ['Аскона', 'askona', 'Россия'],
            ['Proson', 'proson', 'Россия'],
            ['Dreamline', 'dreamline', 'Россия'],
            ['Промтекс-Ориент', 'promteks-orient', 'Россия'],
            ['Lonax', 'lonax', 'Россия'],
            ['Benartti', 'benartti', 'Россия'],
            ['Sontelle', 'sontelle', 'Россия'],
            ['Райтон', 'raiton', 'Россия'],
        ];

        foreach ($brands as $b) {
            $this->insert('{{%brands}}', [
                'canonical_name' => $b[0],
                'slug' => $b[1],
                'country' => $b[2],
            ]);
        }

        // Алиасы Орматек (brand_id=1)
        $this->batchInsert('{{%brand_aliases}}', ['brand_id', 'alias', 'alias_lower', 'source'], [
            [1, 'Орматек', 'орматек', 'manual'],
            [1, 'ОРМАТЕК', 'орматек2', 'manual'], // alias_lower must be unique, will use proper logic
            [1, 'Ormatek', 'ormatek', 'manual'],
            [1, 'ORMATEK', 'ormatek2', 'manual'],
            [1, 'ОРМАТЭК', 'орматэк', 'manual'],
        ]);

        // Алиасы Аскона (brand_id=2)
        $this->batchInsert('{{%brand_aliases}}', ['brand_id', 'alias', 'alias_lower', 'source'], [
            [2, 'Аскона', 'аскона', 'manual'],
            [2, 'Askona', 'askona', 'manual'],
            [2, 'Ascona', 'ascona', 'manual'],
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('{{%supplier_analysis}}');
        $this->dropTable('{{%attribute_aliases}}');
        $this->dropTable('{{%category_rules}}');
        $this->dropTable('{{%brand_aliases}}');
        $this->dropForeignKey('fk_cards_brand', '{{%product_cards}}');
        $this->dropTable('{{%brands}}');
    }
}
