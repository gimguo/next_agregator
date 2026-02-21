<?php

use yii\db\Migration;

/**
 * Sprint 12 — Data Completeness & Channel Readiness.
 *
 * 1. channel_requirements — Требования каналов к карточкам товаров.
 *    Для каждой пары (канал + семейство товара) определяет:
 *    - Какие атрибуты обязательны
 *    - Нужны ли фото, штрихкод, описание
 *    - Минимальное количество фото
 *
 * 2. model_channel_readiness — Кэш готовности моделей для каналов.
 *    Материализованный скоринг, обновляется:
 *    - При создании/обновлении модели (через OutboxService)
 *    - Массово через quality/scan
 */
class m260222_020000_create_channel_requirements extends Migration
{
    public function safeUp()
    {
        // ═══ 1. ТАБЛИЦА channel_requirements ═══
        $this->createTable('{{%channel_requirements}}', [
            'id'                    => $this->primaryKey(),
            'channel_id'            => $this->integer()->notNull()->comment('FK → sales_channels'),
            'family'                => $this->string(30)->notNull()->comment('ProductFamily: mattress, pillow, bed, etc. Или "*" для all'),
            'required_attributes'   => 'JSONB NOT NULL DEFAULT \'[]\'::jsonb',
            'recommended_attributes'=> 'JSONB NOT NULL DEFAULT \'[]\'::jsonb',
            'require_image'         => $this->boolean()->notNull()->defaultValue(true)->comment('Обязательно фото?'),
            'min_images'            => $this->integer()->notNull()->defaultValue(1)->comment('Минимум изображений'),
            'require_barcode'       => $this->boolean()->notNull()->defaultValue(false)->comment('Обязателен GTIN/EAN?'),
            'require_description'   => $this->boolean()->notNull()->defaultValue(true)->comment('Обязательно описание?'),
            'min_description_length'=> $this->integer()->notNull()->defaultValue(50)->comment('Мин. длина описания (символов)'),
            'require_brand'         => $this->boolean()->notNull()->defaultValue(false)->comment('Обязателен бренд?'),
            'require_price'         => $this->boolean()->notNull()->defaultValue(true)->comment('Обязательна цена > 0?'),
            'is_active'             => $this->boolean()->notNull()->defaultValue(true),
            'created_at'            => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at'            => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        $this->addForeignKey('fk-ch_req-channel', '{{%channel_requirements}}', 'channel_id', '{{%sales_channels}}', 'id', 'CASCADE');
        $this->createIndex('idx-ch_req-channel-family', '{{%channel_requirements}}', ['channel_id', 'family'], true);
        $this->createIndex('idx-ch_req-active', '{{%channel_requirements}}', ['is_active']);

        // ═══ 2. ТАБЛИЦА model_channel_readiness ═══
        $this->createTable('{{%model_channel_readiness}}', [
            'id'             => $this->primaryKey(),
            'model_id'       => $this->integer()->notNull()->comment('FK → product_models'),
            'channel_id'     => $this->integer()->notNull()->comment('FK → sales_channels'),
            'is_ready'       => $this->boolean()->notNull()->defaultValue(false),
            'score'          => $this->smallInteger()->notNull()->defaultValue(0)->comment('0-100%'),
            'missing_fields' => 'JSONB NOT NULL DEFAULT \'[]\'::jsonb',
            'checked_at'     => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        $this->addForeignKey('fk-readiness-model', '{{%model_channel_readiness}}', 'model_id', '{{%product_models}}', 'id', 'CASCADE');
        $this->addForeignKey('fk-readiness-channel', '{{%model_channel_readiness}}', 'channel_id', '{{%sales_channels}}', 'id', 'CASCADE');
        $this->createIndex('idx-readiness-model-channel', '{{%model_channel_readiness}}', ['model_id', 'channel_id'], true);
        $this->createIndex('idx-readiness-channel-ready', '{{%model_channel_readiness}}', ['channel_id', 'is_ready']);
        $this->createIndex('idx-readiness-channel-score', '{{%model_channel_readiness}}', ['channel_id', 'score']);

        // ═══ 3. SEED: Требования RosMatras ═══
        // Находим channel_id RosMatras
        $channelId = $this->db->createCommand("SELECT id FROM {{%sales_channels}} WHERE driver = 'rosmatras' LIMIT 1")->queryScalar();
        if (!$channelId) {
            echo "    > WARNING: RosMatras channel not found, skipping seed\n";
            return;
        }

        // Общие требования для всех семейств (wildcard *)
        $this->insert('{{%channel_requirements}}', [
            'channel_id'             => $channelId,
            'family'                 => '*',
            'required_attributes'    => '[]',
            'recommended_attributes' => '[]',
            'require_image'          => true,
            'min_images'             => 1,
            'require_barcode'        => false,
            'require_description'    => true,
            'min_description_length' => 30,
            'require_brand'          => false,
            'require_price'          => true,
            'is_active'              => true,
        ]);

        // Требования для матрасов
        $this->insert('{{%channel_requirements}}', [
            'channel_id'             => $channelId,
            'family'                 => 'mattress',
            'required_attributes'    => '["height", "spring_block"]',
            'recommended_attributes' => '["stiffness_side_1", "max_load", "materials", "cover_material"]',
            'require_image'          => true,
            'min_images'             => 1,
            'require_barcode'        => false,
            'require_description'    => true,
            'min_description_length' => 50,
            'require_brand'          => true,
            'require_price'          => true,
            'is_active'              => true,
        ]);

        // Требования для кроватей
        $this->insert('{{%channel_requirements}}', [
            'channel_id'             => $channelId,
            'family'                 => 'bed',
            'required_attributes'    => '["frame_material"]',
            'recommended_attributes' => '["color", "has_storage", "has_lift_mechanism", "headboard_type"]',
            'require_image'          => true,
            'min_images'             => 1,
            'require_barcode'        => false,
            'require_description'    => true,
            'min_description_length' => 50,
            'require_brand'          => true,
            'require_price'          => true,
            'is_active'              => true,
        ]);

        // Требования для подушек
        $this->insert('{{%channel_requirements}}', [
            'channel_id'             => $channelId,
            'family'                 => 'pillow',
            'required_attributes'    => '["fill_type"]',
            'recommended_attributes' => '["height", "shape", "is_orthopedic"]',
            'require_image'          => true,
            'min_images'             => 1,
            'require_barcode'        => false,
            'require_description'    => true,
            'min_description_length' => 30,
            'require_brand'          => false,
            'require_price'          => true,
            'is_active'              => true,
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('{{%model_channel_readiness}}');
        $this->dropTable('{{%channel_requirements}}');
    }
}
