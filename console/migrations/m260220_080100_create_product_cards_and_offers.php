<?php

use yii\db\Migration;

/**
 * Карточки товаров, предложения поставщиков, варианты, AI-лог.
 */
class m260220_080100_create_product_cards_and_offers extends Migration
{
    public function safeUp()
    {
        // === Эталонные карточки ===
        $this->createTable('{{%product_cards}}', [
            'id' => $this->primaryKey(),
            'canonical_name' => $this->string(500)->notNull(),
            'slug' => $this->string(500)->notNull()->unique(),
            'manufacturer' => $this->string(255),
            'brand' => $this->string(255),
            'model' => $this->string(255),
            'product_type' => $this->string(100)->defaultValue('unknown'),
            'category_id' => $this->integer(),
            'brand_id' => $this->integer(),
            'short_description' => $this->text(),
            'description' => $this->text(),
            'canonical_attributes' => 'JSONB DEFAULT \'{}\'',
            'canonical_images' => 'JSONB DEFAULT \'[]\'',
            // Агрегаты
            'best_price' => $this->decimal(12, 2),
            'best_price_supplier' => $this->string(50),
            'price_range_min' => $this->decimal(12, 2),
            'price_range_max' => $this->decimal(12, 2),
            'supplier_count' => $this->smallInteger()->defaultValue(0),
            'total_variants' => $this->integer()->defaultValue(0),
            'is_in_stock' => $this->boolean()->defaultValue(false),
            'has_active_offers' => $this->boolean()->defaultValue(false),
            'image_count' => $this->integer()->defaultValue(0),
            'images_status' => $this->string(20)->defaultValue('pending'),
            // SEO
            'meta_title' => $this->string(500),
            'meta_description' => $this->text(),
            // Качество
            'quality_score' => $this->smallInteger()->defaultValue(0),
            'quality_grade' => 'CHAR(1) DEFAULT \'F\'',
            'quality_issues' => 'JSONB DEFAULT \'[]\'',
            'quality_checked_at' => $this->timestamp(),
            // Полнотекстовый поиск
            'search_vector' => 'TSVECTOR',
            // Статус
            'status' => $this->string(20)->defaultValue('active'),
            'is_published' => $this->boolean()->defaultValue(true),
            'source_supplier' => $this->string(50),
            'created_at' => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at' => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        $this->addForeignKey('fk_cards_category', '{{%product_cards}}', 'category_id', '{{%categories}}', 'id', 'SET NULL');

        $this->createIndex('idx_cards_slug', '{{%product_cards}}', 'slug');
        $this->createIndex('idx_cards_category', '{{%product_cards}}', 'category_id');
        $this->createIndex('idx_cards_manufacturer', '{{%product_cards}}', 'manufacturer');
        $this->createIndex('idx_cards_brand_model', '{{%product_cards}}', ['manufacturer', 'model']);
        $this->createIndex('idx_cards_status', '{{%product_cards}}', 'status');
        $this->createIndex('idx_cards_updated', '{{%product_cards}}', 'updated_at');

        // Trigram + FTS индексы
        $this->execute('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->execute('CREATE INDEX idx_cards_name_trgm ON {{%product_cards}} USING gin(canonical_name gin_trgm_ops)');
        $this->execute('CREATE INDEX idx_cards_search ON {{%product_cards}} USING gin(search_vector)');

        // Триггер search_vector
        $this->execute("
            CREATE OR REPLACE FUNCTION update_card_search_vector() RETURNS trigger AS \$\$
            BEGIN
                NEW.search_vector :=
                    setweight(to_tsvector('russian', COALESCE(NEW.canonical_name, '')), 'A') ||
                    setweight(to_tsvector('russian', COALESCE(NEW.manufacturer, '')), 'B') ||
                    setweight(to_tsvector('russian', COALESCE(NEW.model, '')), 'B') ||
                    setweight(to_tsvector('russian', COALESCE(NEW.short_description, '')), 'C') ||
                    setweight(to_tsvector('russian', COALESCE(NEW.description, '')), 'D');
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");
        $this->execute("
            CREATE TRIGGER trg_card_search_vector
            BEFORE INSERT OR UPDATE OF canonical_name, manufacturer, model, short_description, description
            ON {{%product_cards}}
            FOR EACH ROW EXECUTE FUNCTION update_card_search_vector();
        ");

        // === Предложения поставщиков ===
        $this->createTable('{{%supplier_offers}}', [
            'id' => $this->primaryKey(),
            'card_id' => $this->integer()->notNull(),
            'supplier_id' => $this->integer()->notNull(),
            'supplier_sku' => $this->string(255)->notNull(),
            'supplier_url' => $this->text(),
            'price_min' => $this->decimal(12, 2),
            'price_max' => $this->decimal(12, 2),
            'compare_price' => $this->decimal(12, 2),
            'currency' => 'CHAR(3) DEFAULT \'RUB\'',
            'in_stock' => $this->boolean()->defaultValue(true),
            'stock_status' => $this->string(20)->defaultValue('available'),
            'stock_quantity' => $this->integer(),
            'description' => $this->text(),
            'attributes_json' => 'JSONB DEFAULT \'{}\'',
            'images_json' => 'JSONB DEFAULT \'[]\'',
            'variants_json' => 'JSONB DEFAULT \'[]\'',
            'variant_count' => $this->integer()->defaultValue(0),
            'match_confidence' => $this->decimal(3, 2)->defaultValue(1.0),
            'match_method' => $this->string(30)->defaultValue('sku'),
            'match_reviewed' => $this->boolean()->defaultValue(false),
            'checksum' => $this->string(64),
            'price_changed_at' => $this->timestamp(),
            'previous_price_min' => $this->decimal(12, 2),
            'raw_data' => 'JSONB',
            'import_task_id' => $this->integer(),
            'imported_at' => $this->timestamp()->defaultExpression('NOW()'),
            'is_active' => $this->boolean()->defaultValue(true),
            'created_at' => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at' => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        $this->addForeignKey('fk_offers_card', '{{%supplier_offers}}', 'card_id', '{{%product_cards}}', 'id', 'CASCADE');
        $this->addForeignKey('fk_offers_supplier', '{{%supplier_offers}}', 'supplier_id', '{{%suppliers}}', 'id', 'CASCADE');
        $this->createIndex('idx_offers_card', '{{%supplier_offers}}', 'card_id');
        $this->createIndex('idx_offers_supplier', '{{%supplier_offers}}', 'supplier_id');
        $this->createIndex('idx_offers_sku', '{{%supplier_offers}}', ['supplier_id', 'supplier_sku'], true);
        $this->createIndex('idx_offers_checksum', '{{%supplier_offers}}', 'checksum');

        // Триггер автообновления агрегатов карточки
        $this->execute("
            CREATE OR REPLACE FUNCTION refresh_card_aggregates(p_card_id INTEGER) RETURNS void AS \$\$
            BEGIN
                UPDATE product_cards SET
                    best_price = (SELECT MIN(price_min) FROM supplier_offers WHERE card_id = p_card_id AND is_active = true AND price_min > 0),
                    best_price_supplier = (
                        SELECT s.code FROM supplier_offers so JOIN suppliers s ON s.id = so.supplier_id
                        WHERE so.card_id = p_card_id AND so.is_active = true AND so.price_min > 0
                        ORDER BY so.price_min LIMIT 1
                    ),
                    price_range_min = (SELECT MIN(price_min) FROM supplier_offers WHERE card_id = p_card_id AND is_active = true AND price_min > 0),
                    price_range_max = (SELECT MAX(price_max) FROM supplier_offers WHERE card_id = p_card_id AND is_active = true),
                    supplier_count = (SELECT COUNT(DISTINCT supplier_id) FROM supplier_offers WHERE card_id = p_card_id AND is_active = true),
                    total_variants = (SELECT COALESCE(SUM(variant_count), 0) FROM supplier_offers WHERE card_id = p_card_id AND is_active = true),
                    is_in_stock = EXISTS(SELECT 1 FROM supplier_offers WHERE card_id = p_card_id AND is_active = true AND in_stock = true),
                    has_active_offers = EXISTS(SELECT 1 FROM supplier_offers WHERE card_id = p_card_id AND is_active = true),
                    updated_at = NOW()
                WHERE id = p_card_id;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        $this->execute("
            CREATE OR REPLACE FUNCTION trigger_refresh_card() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    PERFORM refresh_card_aggregates(OLD.card_id);
                    RETURN OLD;
                ELSE
                    PERFORM refresh_card_aggregates(NEW.card_id);
                    RETURN NEW;
                END IF;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        $this->execute("
            CREATE TRIGGER trg_offers_refresh_card
            AFTER INSERT OR UPDATE OR DELETE ON {{%supplier_offers}}
            FOR EACH ROW EXECUTE FUNCTION trigger_refresh_card();
        ");

        // === Варианты карточек ===
        $this->createTable('{{%card_variants}}', [
            'id' => $this->primaryKey(),
            'card_id' => $this->integer()->notNull(),
            'size_width' => $this->integer(),
            'size_length' => $this->integer(),
            'size_label' => $this->string(50),
            'color' => $this->string(100),
            'material' => $this->string(255),
            'best_price' => $this->decimal(12, 2),
            'best_price_supplier' => $this->string(50),
            'price_range_min' => $this->decimal(12, 2),
            'price_range_max' => $this->decimal(12, 2),
            'is_in_stock' => $this->boolean()->defaultValue(false),
            'sort_order' => $this->integer()->defaultValue(0),
            'created_at' => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at' => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        $this->addForeignKey('fk_variants_card', '{{%card_variants}}', 'card_id', '{{%product_cards}}', 'id', 'CASCADE');
        $this->createIndex('idx_variants_card', '{{%card_variants}}', 'card_id');

        // === AI лог ===
        $this->createTable('{{%ai_logs}}', [
            'id' => $this->bigPrimaryKey(),
            'operation' => $this->string(50)->notNull(),
            'model' => $this->string(100)->notNull(),
            'prompt_tokens' => $this->integer()->defaultValue(0),
            'completion_tokens' => $this->integer()->defaultValue(0),
            'total_tokens' => $this->integer()->defaultValue(0),
            'estimated_cost_usd' => $this->decimal(10, 6),
            'duration_ms' => $this->integer(),
            'input_summary' => $this->text(),
            'output_summary' => $this->text(),
            'success' => $this->boolean()->defaultValue(true),
            'error_message' => $this->text(),
            'context' => 'JSONB',
            'created_at' => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        $this->createIndex('idx_ai_logs_op', '{{%ai_logs}}', ['operation', 'created_at']);
    }

    public function safeDown()
    {
        $this->execute('DROP TRIGGER IF EXISTS trg_offers_refresh_card ON {{%supplier_offers}}');
        $this->execute('DROP TRIGGER IF EXISTS trg_card_search_vector ON {{%product_cards}}');
        $this->execute('DROP FUNCTION IF EXISTS trigger_refresh_card()');
        $this->execute('DROP FUNCTION IF EXISTS refresh_card_aggregates(INTEGER)');
        $this->execute('DROP FUNCTION IF EXISTS update_card_search_vector()');

        $this->dropTable('{{%ai_logs}}');
        $this->dropTable('{{%card_variants}}');
        $this->dropTable('{{%supplier_offers}}');
        $this->dropTable('{{%product_cards}}');
    }
}
