<?php

use yii\db\Migration;

/**
 * Ядро MDM-каталога: Трёхуровневая иерархия.
 *
 * product_models      → Базовая модель (Brand + Name = "Орматек Оптима")
 * reference_variants   → Эталонный вариант (модель + оси вариаций: 160×200)
 * supplier_offers      → Оффер поставщика (цена, остатки от конкретного поставщика)
 *
 * Почему 3 уровня:
 *   - Один матрас "Оптима" у 5 поставщиков в разных размерах = 1 model, N variants, M offers
 *   - GTIN/MPN на уровне variant (штрихкод уникален для размера)
 *   - Цена на уровне offer (у каждого поставщика своя)
 *
 * Стратегия миграции:
 *   - Создаём НОВЫЕ таблицы product_models и reference_variants
 *   - Добавляем variant_id и model_id в СУЩЕСТВУЮЩУЮ supplier_offers (nullable для backward compat)
 *   - product_cards остаётся как есть (admin panel, API) — связь через model_id
 *   - card_variants (пустая) — со временем заменяется на reference_variants
 */
class m260220_200000_create_mdm_catalog_core extends Migration
{
    public function safeUp()
    {
        // ═══════════════════════════════════════════
        // 1. PRODUCT MODELS — Базовая модель товара
        // ═══════════════════════════════════════════
        $this->createTable('{{%product_models}}', [
            'id'                   => $this->primaryKey(),

            // Идентификация
            'product_family'       => $this->string(30)->notNull(),     // ProductFamily enum: mattress, pillow, bed
            'brand_id'             => $this->integer(),                  // FK → brands
            'category_id'          => $this->integer(),                  // FK → categories
            'name'                 => $this->string(500)->notNull(),     // "Орматек Оптима"
            'slug'                 => $this->string(500)->notNull(),     // ormatek-optima
            'manufacturer'         => $this->string(255),                // Raw: "Орматек"
            'model_name'           => $this->string(255),                // Raw: "Оптима"

            // Golden Record — атрибуты и описание
            'description'          => $this->text(),
            'short_description'    => $this->text(),
            'canonical_attributes' => 'JSONB NOT NULL DEFAULT \'{}\'',   // Merged best attributes
            'canonical_images'     => 'JSONB NOT NULL DEFAULT \'[]\'',   // Best image set

            // SEO
            'meta_title'           => $this->string(500),
            'meta_description'     => $this->text(),

            // Агрегаты (денормализация для быстрых запросов)
            'best_price'           => $this->decimal(12, 2),
            'price_range_min'      => $this->decimal(12, 2),
            'price_range_max'      => $this->decimal(12, 2),
            'supplier_count'       => $this->smallInteger()->defaultValue(0),
            'variant_count'        => $this->integer()->defaultValue(0),
            'offer_count'          => $this->integer()->defaultValue(0),
            'is_in_stock'          => $this->boolean()->defaultValue(false),

            // Качество
            'quality_score'        => $this->smallInteger()->defaultValue(0),

            // Полнотекстовый поиск
            'search_vector'        => 'TSVECTOR',

            // Статус и публикация
            'status'               => $this->string(20)->defaultValue('active'),
            'is_published'         => $this->boolean()->defaultValue(true),

            // Связь с legacy product_cards (для миграции данных)
            'legacy_card_id'       => $this->integer(),

            'created_at'           => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at'           => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        // FK
        $this->addForeignKey('fk-product_models-brand', '{{%product_models}}', 'brand_id', '{{%brands}}', 'id', 'SET NULL');
        $this->addForeignKey('fk-product_models-category', '{{%product_models}}', 'category_id', '{{%categories}}', 'id', 'SET NULL');

        // Indexes
        $this->createIndex('idx-product_models-slug', '{{%product_models}}', 'slug', true);
        $this->createIndex('idx-product_models-family', '{{%product_models}}', 'product_family');
        $this->createIndex('idx-product_models-brand', '{{%product_models}}', 'brand_id');
        $this->createIndex('idx-product_models-category', '{{%product_models}}', 'category_id');
        $this->createIndex('idx-product_models-status', '{{%product_models}}', 'status');
        $this->createIndex('idx-product_models-brand_name', '{{%product_models}}', ['brand_id', 'name']);
        $this->createIndex('idx-product_models-manufacturer_model', '{{%product_models}}', ['manufacturer', 'model_name']);
        $this->createIndex('idx-product_models-legacy', '{{%product_models}}', 'legacy_card_id');

        // Trigram index for fuzzy search
        $this->execute("CREATE INDEX idx_product_models_name_trgm ON {{%product_models}} USING GIN (name gin_trgm_ops)");

        // Full-text search function
        $this->execute("
            CREATE OR REPLACE FUNCTION update_model_search_vector() RETURNS trigger AS \$\$
            BEGIN
                NEW.search_vector := to_tsvector('russian',
                    COALESCE(NEW.name, '') || ' ' ||
                    COALESCE(NEW.manufacturer, '') || ' ' ||
                    COALESCE(NEW.model_name, '') || ' ' ||
                    COALESCE(NEW.short_description, '')
                );
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql
        ");

        // Full-text search trigger
        $this->execute("
            CREATE TRIGGER trg_model_search_vector
            BEFORE INSERT OR UPDATE OF name, manufacturer, model_name, short_description
            ON {{%product_models}}
            FOR EACH ROW EXECUTE FUNCTION update_model_search_vector()
        ");

        // ═══════════════════════════════════════════
        // 2. REFERENCE VARIANTS — Эталонный вариант
        // ═══════════════════════════════════════════
        $this->createTable('{{%reference_variants}}', [
            'id'                  => $this->primaryKey(),
            'model_id'            => $this->integer()->notNull(),

            // Уникальные идентификаторы товара
            'gtin'                => $this->string(14),               // EAN-13/UPC barcode
            'mpn'                 => $this->string(100),              // Manufacturer Part Number

            // Оси вариаций (JSONB — гибко, не ломается при добавлении новых осей)
            'variant_attributes'  => 'JSONB NOT NULL DEFAULT \'{}\'', // {"width": 160, "length": 200}
            'variant_label'       => $this->string(100),              // "160×200" (человекочитаемый)

            // Агрегаты
            'best_price'          => $this->decimal(12, 2),
            'price_range_min'     => $this->decimal(12, 2),
            'price_range_max'     => $this->decimal(12, 2),
            'is_in_stock'         => $this->boolean()->defaultValue(false),
            'supplier_count'      => $this->smallInteger()->defaultValue(0),

            'sort_order'          => $this->integer()->defaultValue(0),
            'created_at'          => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at'          => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        // FK
        $this->addForeignKey('fk-ref_variants-model', '{{%reference_variants}}', 'model_id', '{{%product_models}}', 'id', 'CASCADE');

        // Indexes
        $this->createIndex('idx-ref_variants-model', '{{%reference_variants}}', 'model_id');
        $this->createIndex('idx-ref_variants-model_attrs', '{{%reference_variants}}', ['model_id', 'variant_label']);

        // GTIN — partial unique index (только не-NULL)
        $this->execute("CREATE UNIQUE INDEX idx_ref_variants_gtin ON {{%reference_variants}} (gtin) WHERE gtin IS NOT NULL");

        // MPN — partial index для поиска по связке brand + mpn (через JOIN с model)
        $this->execute("CREATE INDEX idx_ref_variants_mpn ON {{%reference_variants}} (mpn) WHERE mpn IS NOT NULL");

        // GIN index на JSONB для поиска по атрибутам
        $this->execute("CREATE INDEX idx_ref_variants_attrs ON {{%reference_variants}} USING GIN (variant_attributes)");

        // ═══════════════════════════════════════════
        // 3. РАСШИРЕНИЕ supplier_offers — добавляем MDM-связи
        // ═══════════════════════════════════════════
        //
        // Новые FK добавляются как nullable — обратная совместимость с 2587 существующими офферами.
        // Они будут заполнены при следующем импорте через MatchingEngine.
        // card_id остаётся для backward compat (admin panel, API v1).

        $this->addColumn('{{%supplier_offers}}', 'model_id', $this->integer()->after('card_id'));
        $this->addColumn('{{%supplier_offers}}', 'variant_id', $this->integer()->after('model_id'));

        $this->addForeignKey('fk-offers-model', '{{%supplier_offers}}', 'model_id', '{{%product_models}}', 'id', 'SET NULL');
        $this->addForeignKey('fk-offers-variant', '{{%supplier_offers}}', 'variant_id', '{{%reference_variants}}', 'id', 'SET NULL');

        $this->createIndex('idx-offers-model', '{{%supplier_offers}}', 'model_id');
        $this->createIndex('idx-offers-variant', '{{%supplier_offers}}', 'variant_id');

        // Составной уникальный индекс: один SKU поставщика = один оффер для варианта
        // (не создаём, т.к. уже есть idx_offers_sku на (supplier_id, supplier_sku))

        // ═══════════════════════════════════════════
        // 4. MATCHING LOG — для отладки сопоставлений
        // ═══════════════════════════════════════════
        $this->createTable('{{%matching_log}}', [
            'id'               => $this->primaryKey(),
            'import_session_id' => $this->string(100),
            'supplier_id'      => $this->integer()->notNull(),
            'supplier_sku'     => $this->string(255)->notNull(),
            'matched_model_id'  => $this->integer(),
            'matched_variant_id' => $this->integer(),
            'matcher_name'     => $this->string(50)->notNull(),     // 'gtin', 'mpn', 'composite', 'new'
            'confidence'       => $this->decimal(5, 4)->defaultValue(0),  // 0.0000 - 1.0000
            'match_details'    => 'JSONB DEFAULT \'{}\'',            // Подробности: что совпало
            'created_at'       => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        $this->createIndex('idx-matching_log-session', '{{%matching_log}}', 'import_session_id');
        $this->createIndex('idx-matching_log-model', '{{%matching_log}}', 'matched_model_id');
        $this->createIndex('idx-matching_log-matcher', '{{%matching_log}}', 'matcher_name');
        $this->createIndex('idx-matching_log-created', '{{%matching_log}}', 'created_at');
    }

    public function safeDown()
    {
        // Убираем новые FK и колонки из supplier_offers
        $this->dropForeignKey('fk-offers-variant', '{{%supplier_offers}}');
        $this->dropForeignKey('fk-offers-model', '{{%supplier_offers}}');
        $this->dropColumn('{{%supplier_offers}}', 'variant_id');
        $this->dropColumn('{{%supplier_offers}}', 'model_id');

        $this->dropTable('{{%matching_log}}');
        $this->dropTable('{{%reference_variants}}');

        $this->execute("DROP TRIGGER IF EXISTS trg_model_search_vector ON {{%product_models}}");
        $this->execute("DROP FUNCTION IF EXISTS update_model_search_vector()");
        $this->dropTable('{{%product_models}}');
    }
}
