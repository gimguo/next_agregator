<?php

use yii\db\Migration;

/**
 * Трекинг скачивания картинок карточек.
 */
class m260220_080300_create_card_images extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%card_images}}', [
            'id' => $this->primaryKey(),
            'card_id' => $this->integer()->notNull(),
            'source_url' => $this->text()->notNull(),
            'local_path' => $this->string(500),
            'thumb_path' => $this->string(500),
            'medium_path' => $this->string(500),
            'large_path' => $this->string(500),
            'webp_path' => $this->string(500),
            'width' => $this->smallInteger(),
            'height' => $this->smallInteger(),
            'file_size' => $this->integer(),
            'mime_type' => $this->string(50),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'error_message' => $this->text(),
            'attempts' => $this->smallInteger()->notNull()->defaultValue(0),
            'sort_order' => $this->smallInteger()->notNull()->defaultValue(0),
            'is_main' => $this->boolean()->notNull()->defaultValue(false),
            'created_at' => $this->timestamp()->notNull()->defaultExpression('NOW()'),
            'downloaded_at' => $this->timestamp(),
        ]);

        $this->addForeignKey('fk_card_images_card', '{{%card_images}}', 'card_id', '{{%product_cards}}', 'id', 'CASCADE');
        $this->createIndex('idx_card_images_card', '{{%card_images}}', 'card_id');
        $this->createIndex('idx_card_images_status', '{{%card_images}}', 'status');
        $this->createIndex('idx_card_images_unique_url', '{{%card_images}}', ['card_id', 'source_url'], true);

        // === Ручное сопоставление (review queue) ===
        $this->createTable('{{%match_reviews}}', [
            'id' => $this->primaryKey(),
            'offer_id' => $this->integer()->notNull(),
            'suggested_card_id' => $this->integer(),
            'ai_confidence' => $this->decimal(3, 2),
            'ai_reason' => $this->text(),
            'status' => $this->string(20)->defaultValue('pending'),
            'reviewed_by' => $this->string(100),
            'reviewed_at' => $this->timestamp(),
            'final_card_id' => $this->integer(),
            'notes' => $this->text(),
            'created_at' => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        $this->addForeignKey('fk_reviews_offer', '{{%match_reviews}}', 'offer_id', '{{%supplier_offers}}', 'id', 'CASCADE');
        $this->addForeignKey('fk_reviews_suggested', '{{%match_reviews}}', 'suggested_card_id', '{{%product_cards}}', 'id', 'SET NULL');
        $this->addForeignKey('fk_reviews_final', '{{%match_reviews}}', 'final_card_id', '{{%product_cards}}', 'id', 'SET NULL');
        $this->createIndex('idx_reviews_status', '{{%match_reviews}}', 'status');

        // === Источники данных карточки ===
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

    public function safeDown()
    {
        $this->dropTable('{{%card_data_sources}}');
        $this->dropTable('{{%match_reviews}}');
        $this->dropTable('{{%card_images}}');
    }
}
