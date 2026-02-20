<?php

use yii\db\Migration;

/**
 * Таблица конфигурации автоматического получения прайсов.
 *
 * Поддерживает: URL, FTP, Email (IMAP), API, ручную загрузку.
 */
class m260220_133125_add_supplier_fetch_configs extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%supplier_fetch_configs}}', [
            'id' => $this->primaryKey(),
            'supplier_id' => $this->integer()->notNull(),
            'fetch_method' => $this->string(20)->notNull()->defaultValue('manual'),
            // URL: прямая ссылка на скачивание
            'url' => $this->string(1000)->null(),
            // FTP
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
            // Расписание
            'schedule_cron' => $this->string(100)->null()->comment('Cron-выражение, например 0 3 * * *'),
            'schedule_interval_hours' => $this->integer()->null()->comment('Или интервал в часах'),
            'is_enabled' => $this->boolean()->defaultValue(true),
            // Обработка файла
            'file_format' => $this->string(20)->null()->comment('xml, csv, xlsx, json'),
            'file_encoding' => $this->string(20)->defaultValue('utf-8'),
            'archive_type' => $this->string(10)->null()->comment('zip, gz, rar'),
            'file_pattern' => $this->string(255)->null()->comment('Паттерн имени файла для Email/FTP'),
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

        // Добавим запись для Ormatek (manual)
        $ormatek = (new \yii\db\Query())
            ->from('{{%suppliers}}')
            ->where(['code' => 'ormatek'])
            ->one();

        if ($ormatek) {
            $this->insert('{{%supplier_fetch_configs}}', [
                'supplier_id' => $ormatek['id'],
                'fetch_method' => 'manual',
                'file_format' => 'xml',
                'file_encoding' => 'utf-8',
                'is_enabled' => true,
            ]);
        }
    }

    public function safeDown()
    {
        $this->dropTable('{{%supplier_fetch_configs}}');
    }
}
