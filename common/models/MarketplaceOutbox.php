<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * Запись в Transactional Outbox (marketplace_outbox).
 *
 * @property int    $id
 * @property string $entity_type       'model', 'variant', 'offer'
 * @property int    $entity_id
 * @property int    $model_id
 * @property int    $channel_id        FK → sales_channels
 * @property string $source_event      Что произошло: 'created', 'updated', 'price_changed', 'stock_changed'
 * @property string $lane              Тип обновления: 'content_updated', 'price_updated', 'stock_updated'
 * @property array  $payload           JSONB
 * @property string $status            'pending', 'processing', 'success', 'error', 'failed'
 * @property int    $retry_count
 * @property string $error_log
 * @property string $processed_at
 * @property string $source
 * @property string $import_session_id
 * @property string $created_at
 *
 * @property-read ProductModel  $productModel
 * @property-read SalesChannel  $salesChannel
 */
class MarketplaceOutbox extends ActiveRecord
{
    // Lanes (типы обновлений для воркера)
    const LANE_CONTENT = 'content_updated';
    const LANE_PRICE   = 'price_updated';
    const LANE_STOCK   = 'stock_updated';

    // Статусы
    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SUCCESS    = 'success';
    const STATUS_ERROR      = 'error';
    const STATUS_FAILED     = 'failed'; // DLQ — не будет retry

    public static function tableName(): string
    {
        return '{{%marketplace_outbox}}';
    }

    public function attributeLabels(): array
    {
        return [
            'id'           => 'ID',
            'entity_type'  => 'Тип сущности',
            'entity_id'    => 'ID сущности',
            'model_id'     => 'ID модели',
            'channel_id'   => 'Канал продаж',
            'source_event' => 'Источник события',
            'lane'         => 'Тип обновления',
            'payload'      => 'Payload',
            'status'       => 'Статус',
            'retry_count'  => 'Ретраи',
            'error_log'    => 'Лог ошибки',
            'processed_at' => 'Обработано',
            'source'       => 'Источник',
            'import_session_id' => 'Сессия',
            'created_at'   => 'Создано',
        ];
    }

    public function getProductModel()
    {
        return $this->hasOne(ProductModel::class, ['id' => 'model_id']);
    }

    public function getSalesChannel()
    {
        return $this->hasOne(SalesChannel::class, ['id' => 'channel_id']);
    }

    /**
     * Получить список допустимых lanes.
     */
    public static function lanes(): array
    {
        return [
            self::LANE_CONTENT,
            self::LANE_PRICE,
            self::LANE_STOCK,
        ];
    }
}
