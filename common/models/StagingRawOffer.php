<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * Сырой оффер из staging (UNLOGGED TABLE).
 *
 * @property int    $id
 * @property string $import_session_id
 * @property int    $supplier_id
 * @property string $supplier_sku
 * @property string $raw_hash
 * @property array  $raw_data          JSONB
 * @property array  $normalized_data   JSONB
 * @property string $status            'pending', 'normalized', 'persisted', 'error'
 * @property string $error_message
 * @property string $created_at
 */
class StagingRawOffer extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%staging_raw_offers}}';
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'import_session_id' => 'Сессия',
            'supplier_id' => 'Поставщик',
            'supplier_sku' => 'SKU',
            'raw_hash' => 'Hash',
            'raw_data' => 'Сырые данные',
            'normalized_data' => 'Нормализовано',
            'status' => 'Статус',
            'error_message' => 'Ошибка',
            'created_at' => 'Создано',
        ];
    }

    public function getSupplier()
    {
        return $this->hasOne(Supplier::class, ['id' => 'supplier_id']);
    }
}
