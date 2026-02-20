<?php

use yii\db\Migration;

/**
 * Перевод staging-слоя с Redis на PostgreSQL UNLOGGED TABLE.
 *
 * UNLOGGED TABLE:
 *   - Не пишет в WAL (Write-Ahead Log) → INSERT в 3-5 раз быстрее
 *   - Данные не переживают crash/restart PostgreSQL (идеально для staging)
 *   - Полностью поддерживает индексы, JSONB, UPSERT
 *
 * Таблицы:
 *   import_sessions       — метаданные сессий импорта (заменяет Redis import:{taskId}:meta)
 *   staging_raw_offers    — UNLOGGED, промежуточные данные парсинга (заменяет Redis import:{taskId}:items)
 */
class m260220_170000_create_staging_tables extends Migration
{
    public function safeUp()
    {
        // ═══════════════════════════════════════════
        // 1. IMPORT SESSIONS (обычная таблица — нужна устойчивость к крашам)
        // ═══════════════════════════════════════════
        $this->createTable('{{%import_sessions}}', [
            'id'             => $this->primaryKey(),
            'session_id'     => $this->string(100)->notNull()->unique(),
            'supplier_id'    => $this->integer()->notNull(),
            'supplier_code'  => $this->string(50)->notNull(),
            'file_path'      => $this->string(500),
            'options'        => 'JSONB DEFAULT \'{}\'',
            'status'         => $this->string(30)->notNull()->defaultValue('created'),
            'total_items'    => $this->integer()->defaultValue(0),
            'parsed_items'   => $this->integer()->defaultValue(0),
            'normalized_items' => $this->integer()->defaultValue(0),
            'persisted_items'  => $this->integer()->defaultValue(0),
            'error_count'    => $this->integer()->defaultValue(0),
            'stats'          => 'JSONB DEFAULT \'{}\'',
            'error_message'  => $this->text(),
            'started_at'     => $this->timestamp(),
            'parsed_at'      => $this->timestamp(),
            'analyzed_at'    => $this->timestamp(),
            'normalized_at'  => $this->timestamp(),
            'persisted_at'   => $this->timestamp(),
            'completed_at'   => $this->timestamp(),
            'created_at'     => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at'     => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        $this->addForeignKey(
            'fk-import_sessions-supplier_id',
            '{{%import_sessions}}', 'supplier_id',
            '{{%suppliers}}', 'id',
            'CASCADE', 'CASCADE'
        );

        $this->createIndex('idx-import_sessions-supplier_code', '{{%import_sessions}}', 'supplier_code');
        $this->createIndex('idx-import_sessions-status', '{{%import_sessions}}', 'status');
        $this->createIndex('idx-import_sessions-created_at', '{{%import_sessions}}', 'created_at');

        // ═══════════════════════════════════════════
        // 2. STAGING RAW OFFERS — UNLOGGED TABLE
        // ═══════════════════════════════════════════
        //
        // Yii2 createTable() не поддерживает UNLOGGED, используем raw SQL.
        //
        $this->execute("
            CREATE UNLOGGED TABLE {{%staging_raw_offers}} (
                id              BIGSERIAL PRIMARY KEY,
                import_session_id VARCHAR(100) NOT NULL,
                supplier_id     INTEGER NOT NULL,
                supplier_sku    VARCHAR(255),
                raw_hash        VARCHAR(32),
                raw_data        JSONB NOT NULL DEFAULT '{}',
                normalized_data JSONB,
                status          VARCHAR(20) NOT NULL DEFAULT 'pending',
                error_message   TEXT,
                created_at      TIMESTAMP DEFAULT NOW()
            )
        ");

        // B-Tree индексы для быстрого чтения пачками
        $this->createIndex(
            'idx-staging_raw-session_status',
            '{{%staging_raw_offers}}',
            ['import_session_id', 'status']
        );

        $this->createIndex(
            'idx-staging_raw-session_id',
            '{{%staging_raw_offers}}',
            'import_session_id'
        );

        // Для дедупликации по hash в рамках сессии
        $this->createIndex(
            'idx-staging_raw-session_hash',
            '{{%staging_raw_offers}}',
            ['import_session_id', 'raw_hash']
        );

        // Для cursor-based iteration (id > last_id)
        $this->createIndex(
            'idx-staging_raw-id_session',
            '{{%staging_raw_offers}}',
            ['import_session_id', 'id']
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%staging_raw_offers}}');
        $this->dropTable('{{%import_sessions}}');
    }
}
