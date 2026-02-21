<?php

use yii\db\Migration;

/**
 * Sprint 14: Создание/рефайнинг таблицы supplier_fetch_configs.
 *
 * Таблица могла быть потеряна при пересоздании БД.
 * Миграция создаёт таблицу если её нет, и добавляет новые колонки:
 *  - credentials (JSONB) — универсальное хранилище авторизации
 *  - next_run_at (timestamp) — предвычисленное время следующего запуска
 *  - last_duration_sec (int) — длительность последнего скачивания
 *  - notes (text) — заметки менеджера
 */
class m260222_040000_refine_supplier_fetch_configs extends Migration
{
    public function safeUp()
    {
        // ═══ Проверяем, существует ли таблица. Если нет — создаём заново ═══
        $tableExists = $this->db->createCommand(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'supplier_fetch_configs')"
        )->queryScalar();

        if (!$tableExists) {
            $this->createTable('{{%supplier_fetch_configs}}', [
                'id' => $this->primaryKey(),
                'supplier_id' => $this->integer()->notNull(),
                'fetch_method' => $this->string(20)->notNull()->defaultValue('manual'),
                // URL
                'url' => $this->string(1000)->null(),
                // FTP (legacy-поля, для обратной совместимости)
                'ftp_host' => $this->string(255)->null(),
                'ftp_port' => $this->integer()->defaultValue(21),
                'ftp_user' => $this->string(255)->null(),
                'ftp_password' => $this->string(255)->null(),
                'ftp_path' => $this->string(500)->null(),
                'ftp_passive' => $this->boolean()->defaultValue(true),
                // Email (IMAP)
                'email_host' => $this->string(255)->null(),
                'email_port' => $this->integer()->defaultValue(993),
                'email_user' => $this->string(255)->null(),
                'email_password' => $this->string(255)->null(),
                'email_folder' => $this->string(100)->defaultValue('INBOX'),
                'email_subject_filter' => $this->string(255)->null(),
                'email_from_filter' => $this->string(255)->null(),
                // API
                'api_url' => $this->string(1000)->null(),
                'api_key' => $this->string(500)->null(),
                'api_headers' => $this->json()->null(),
                'api_method' => $this->string(10)->defaultValue('GET'),
                // ═══ Новые поля Sprint 14 ═══
                'credentials' => $this->json()->null()->comment('JSONB: {login, password, token, api_key, headers}'),
                'next_run_at' => $this->timestamp()->null()->comment('Следующий запланированный запуск'),
                'last_duration_sec' => $this->integer()->null()->comment('Длительность последнего скачивания (сек)'),
                'notes' => $this->text()->null()->comment('Заметки менеджера'),
                // Расписание
                'schedule_cron' => $this->string(100)->null()->comment('Cron-выражение, например 0 3 * * *'),
                'schedule_interval_hours' => $this->integer()->null()->comment('Или интервал в часах'),
                'is_enabled' => $this->boolean()->defaultValue(true),
                // Обработка файла
                'file_format' => $this->string(20)->null()->comment('xml, csv, xlsx, json'),
                'file_encoding' => $this->string(20)->defaultValue('utf-8'),
                'archive_type' => $this->string(10)->null()->comment('zip, gz, rar'),
                'file_pattern' => $this->string(255)->null()->comment('Паттерн имени файла для FTP'),
                // Статистика
                'last_fetch_at' => $this->timestamp()->null(),
                'last_fetch_status' => $this->string(20)->null(),
                'last_fetch_error' => $this->text()->null(),
                'fetch_count' => $this->integer()->defaultValue(0),
                'created_at' => $this->timestamp()->defaultExpression('NOW()'),
                'updated_at' => $this->timestamp()->defaultExpression('NOW()'),
            ]);

            $this->addForeignKey(
                'fk_fetch_config_supplier',
                '{{%supplier_fetch_configs}}',
                'supplier_id',
                '{{%suppliers}}',
                'id',
                'CASCADE',
                'CASCADE'
            );

            $this->createIndex('idx_fetch_config_supplier', '{{%supplier_fetch_configs}}', 'supplier_id', true);
            $this->createIndex('idx_fetch_config_enabled', '{{%supplier_fetch_configs}}', ['is_enabled', 'fetch_method']);

            // Сидируем конфиг для Ormatek
            $ormatek = (new \yii\db\Query())
                ->from('{{%suppliers}}')
                ->where(['code' => 'ormatek'])
                ->one();

            if ($ormatek) {
                $this->insert('{{%supplier_fetch_configs}}', [
                    'supplier_id' => $ormatek['id'],
                    'fetch_method' => 'url',
                    'url' => 'https://static.ormatek.com/data/feeds/All.xml',
                    'file_format' => 'xml',
                    'file_encoding' => 'utf-8',
                    'schedule_cron' => '0 3 * * *',
                    'is_enabled' => true,
                    'notes' => 'Полный XML-каталог Ормтек. Обновляется ежедневно в 3:00.',
                ]);
            }

            Yii::info('Создана таблица supplier_fetch_configs (Sprint 14)', __METHOD__);
        } else {
            // ═══ Таблица существует — добавляем только новые колонки ═══
            $this->safeAddColumn('credentials', $this->json()->null()->comment('JSONB: {login, password, token, api_key, headers}'));
            $this->safeAddColumn('next_run_at', $this->timestamp()->null()->comment('Следующий запланированный запуск'));
            $this->safeAddColumn('last_duration_sec', $this->integer()->null()->comment('Длительность последнего скачивания (сек)'));
            $this->safeAddColumn('notes', $this->text()->null()->comment('Заметки менеджера'));

            Yii::info('Расширена таблица supplier_fetch_configs (Sprint 14)', __METHOD__);
        }

        // Индекс для быстрого поиска планировщиком
        $this->createIndex(
            'idx-fetch-config-schedule',
            '{{%supplier_fetch_configs}}',
            ['is_enabled', 'fetch_method', 'next_run_at']
        );
    }

    public function safeDown()
    {
        $this->dropIndex('idx-fetch-config-schedule', '{{%supplier_fetch_configs}}');
        // Не удаляем таблицу целиком, только новые колонки
        $this->safeDropColumn('notes');
        $this->safeDropColumn('last_duration_sec');
        $this->safeDropColumn('next_run_at');
        $this->safeDropColumn('credentials');
    }

    /**
     * Добавить колонку если её ещё нет.
     */
    private function safeAddColumn(string $column, $type): void
    {
        $exists = $this->db->createCommand(
            "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'supplier_fetch_configs' AND column_name = :col)",
            [':col' => $column]
        )->queryScalar();

        if (!$exists) {
            $this->addColumn('{{%supplier_fetch_configs}}', $column, $type);
        }
    }

    /**
     * Удалить колонку если она существует.
     */
    private function safeDropColumn(string $column): void
    {
        $exists = $this->db->createCommand(
            "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'supplier_fetch_configs' AND column_name = :col)",
            [':col' => $column]
        )->queryScalar();

        if ($exists) {
            $this->dropColumn('{{%supplier_fetch_configs}}', $column);
        }
    }
}
