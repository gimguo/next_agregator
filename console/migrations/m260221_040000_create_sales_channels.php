<?php

use yii\db\Migration;

/**
 * Sales Channels — каналы продаж для многоканальной синдикации.
 *
 * Каждый канал — это внешняя витрина/маркетплейс, куда агрегатор
 * может экспортировать товары: RosMatras, Ozon, Wildberries, Yandex и т.д.
 *
 * api_config (JSONB) хранит специфичные для канала настройки:
 *   - RosMatras: {"apiUrl": "http://...", "apiToken": "secret"}
 *   - Ozon:      {"clientId": "123", "apiKey": "xxx", "warehouseId": 456}
 *   - WB:        {"apiKey": "xxx", "supplierId": "abc"}
 *
 * driver определяет, какой Синдикатор + ApiClient использовать (Factory).
 */
class m260221_040000_create_sales_channels extends Migration
{
    public function safeUp()
    {
        // ═══ Тип ENUM для драйвера ═══
        $this->execute("CREATE TYPE sales_channel_driver AS ENUM ('rosmatras', 'ozon', 'wb', 'yandex')");

        $this->createTable('{{%sales_channels}}', [
            'id'         => $this->primaryKey(),
            'name'       => $this->string(100)->notNull()->comment('Название канала ("RosMatras", "Ozon ООО Ромашка")'),
            'driver'     => 'sales_channel_driver NOT NULL',
            'api_config' => "JSONB NOT NULL DEFAULT '{}'::jsonb",
            'is_active'  => $this->boolean()->notNull()->defaultValue(true),
            'created_at' => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at' => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        $this->createIndex('idx-sales_channels-driver', '{{%sales_channels}}', 'driver');
        $this->createIndex('idx-sales_channels-is_active', '{{%sales_channels}}', 'is_active');

        // ═══ Seed: первый канал — RosMatras ═══
        $rosmatrasConfig = json_encode([
            'apiUrl'   => getenv('ROSMATRAS_API_URL') ?: 'http://rosmatras-nginx/api/v1',
            'apiToken' => getenv('ROSMATRAS_API_TOKEN') ?: '',
        ], JSON_UNESCAPED_SLASHES);

        $this->insert('{{%sales_channels}}', [
            'name'       => 'RosMatras',
            'driver'     => 'rosmatras',
            'api_config' => $rosmatrasConfig,
            'is_active'  => true,
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('{{%sales_channels}}');
        $this->execute("DROP TYPE IF EXISTS sales_channel_driver");
    }
}
