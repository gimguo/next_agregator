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
 * @property string $event_type        'created', 'updated', 'deleted', 'price_changed', 'stock_changed'
 * @property array  $payload           JSONB
 * @property string $status            'pending', 'processing', 'success', 'error'
 * @property int    $retry_count
 * @property string $error_log
 * @property string $processed_at
 * @property string $source
 * @property string $import_session_id
 * @property string $created_at
 */
class MarketplaceOutbox extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%marketplace_outbox}}';
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'entity_type' => 'Тип сущности',
            'entity_id' => 'ID сущности',
            'model_id' => 'ID модели',
            'event_type' => 'Событие',
            'payload' => 'Payload',
            'status' => 'Статус',
            'retry_count' => 'Ретраи',
            'error_log' => 'Лог ошибки',
            'processed_at' => 'Обработано',
            'source' => 'Источник',
            'import_session_id' => 'Сессия',
            'created_at' => 'Создано',
        ];
    }

    public function getProductModel()
    {
        return $this->hasOne(ProductModel::class, ['id' => 'model_id']);
    }
}
