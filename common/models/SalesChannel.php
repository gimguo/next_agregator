<?php

namespace common\models;

use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * Канал продаж (Sales Channel) — внешняя витрина / маркетплейс.
 *
 * Каждый канал имеет driver (rosmatras, ozon, wb, yandex),
 * по которому ChannelDriverFactory определяет нужную пару
 * Синдикатор + ApiClient.
 *
 * @property int    $id
 * @property string $name         Название канала ("RosMatras", "Ozon ООО Ромашка")
 * @property string $driver       Тип драйвера: 'rosmatras', 'ozon', 'wb', 'yandex'
 * @property array  $api_config   JSONB — токены, URL, client_id и т.д.
 * @property bool   $is_active    Канал включён
 * @property string $created_at
 * @property string $updated_at
 */
class SalesChannel extends ActiveRecord
{
    // ═══ Константы драйверов ═══
    const DRIVER_ROSMATRAS = 'rosmatras';
    const DRIVER_OZON      = 'ozon';
    const DRIVER_WB        = 'wb';
    const DRIVER_YANDEX    = 'yandex';

    public static function tableName(): string
    {
        return '{{%sales_channels}}';
    }

    public function behaviors(): array
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'value' => new Expression('NOW()'),
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['name', 'driver'], 'required'],
            ['name', 'string', 'max' => 100],
            ['driver', 'in', 'range' => [
                self::DRIVER_ROSMATRAS,
                self::DRIVER_OZON,
                self::DRIVER_WB,
                self::DRIVER_YANDEX,
            ]],
            ['is_active', 'boolean'],
            ['api_config', 'safe'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id'         => 'ID',
            'name'       => 'Название канала',
            'driver'     => 'Драйвер',
            'api_config' => 'Конфигурация API',
            'is_active'  => 'Активен',
            'created_at' => 'Создано',
            'updated_at' => 'Обновлено',
        ];
    }

    /**
     * Получить значение из api_config по ключу.
     */
    public function getConfigValue(string $key, $default = null)
    {
        $config = $this->api_config;
        if (is_string($config)) {
            $config = json_decode($config, true) ?: [];
        }
        return $config[$key] ?? $default;
    }

    /**
     * Все активные каналы.
     *
     * @return static[]
     */
    public static function findActive(): array
    {
        return static::find()
            ->where(['is_active' => true])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    /**
     * Найти канал по драйверу (первый активный).
     */
    public static function findByDriver(string $driver): ?static
    {
        return static::find()
            ->where(['driver' => $driver, 'is_active' => true])
            ->one();
    }

    /**
     * Outbox-записи этого канала.
     */
    public function getOutboxRecords()
    {
        return $this->hasMany(MarketplaceOutbox::class, ['channel_id' => 'id']);
    }
}
