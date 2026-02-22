<?php

use yii\db\Migration;

/**
 * Sprint 22: Brand Master Data — справочник брендов с алиасами.
 *
 * Таблицы:
 *   - brands: эталонный справочник брендов
 *   - brand_aliases: синонимы и опечатки для авто-резолва
 */
class m260224_010000_create_brands_tables extends Migration
{
    public function safeUp()
    {
        // ═══ brands — эталонный справочник брендов ═══
        $this->createTable('{{%brands}}', [
            'id' => $this->primaryKey()->unsigned(),
            'name' => $this->string(255)->notNull()->comment('Эталонное название бренда'),
            'slug' => $this->string(255)->comment('URL-friendly slug'),
            'is_active' => $this->boolean()->defaultValue(true)->comment('Активен ли бренд'),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP')->comment('Дата создания'),
            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP')->comment('Дата обновления'),
        ]);

        $this->createIndex('idx-brands-name', '{{%brands}}', 'name', true); // unique
        $this->createIndex('idx-brands-slug', '{{%brands}}', 'slug', true); // unique
        $this->createIndex('idx-brands-is-active', '{{%brands}}', 'is_active');

        // ═══ brand_aliases — синонимы и опечатки ═══
        $this->createTable('{{%brand_aliases}}', [
            'id' => $this->primaryKey()->unsigned(),
            'brand_id' => $this->integer()->unsigned()->notNull()->comment('FK → brands'),
            'alias' => $this->string(255)->notNull()->comment('Синоним/опечатка (например, Tjyota для Toyota)'),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP')->comment('Дата создания'),
        ]);

        $this->addForeignKey(
            'fk-brand-aliases-brand-id',
            '{{%brand_aliases}}',
            'brand_id',
            '{{%brands}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->createIndex('idx-brand-aliases-alias', '{{%brand_aliases}}', 'alias', true); // unique
        $this->createIndex('idx-brand-aliases-brand-id', '{{%brand_aliases}}', 'brand_id');

        // ═══ Добавляем brand_id в product_models ═══
        $this->addColumn('{{%product_models}}', 'brand_id', $this->integer()->unsigned()->comment('FK → brands (эталонный бренд)'));
        $this->createIndex('idx-product-models-brand-id', '{{%product_models}}', 'brand_id');
        $this->addForeignKey(
            'fk-product-models-brand-id',
            '{{%product_models}}',
            'brand_id',
            '{{%brands}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk-product-models-brand-id', '{{%product_models}}');
        $this->dropIndex('idx-product-models-brand-id', '{{%product_models}}');
        $this->dropColumn('{{%product_models}}', 'brand_id');
        $this->dropTable('{{%brand_aliases}}');
        $this->dropTable('{{%brands}}');
    }
}
