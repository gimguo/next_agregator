<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * Лог сопоставления товаров (Matching Engine).
 *
 * Каждая запись = одна попытка матчинга DTO к каталогу.
 * Используется для отладки ложных склеек/расхождений.
 *
 * @property int    $id
 * @property string $import_session_id
 * @property int    $supplier_id
 * @property string $supplier_sku
 * @property int    $matched_model_id
 * @property int    $matched_variant_id
 * @property string $matcher_name         'gtin', 'mpn', 'composite', 'new'
 * @property float  $confidence           0.0–1.0
 * @property array  $match_details        JSONB
 * @property string $created_at
 */
class MatchingLog extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%matching_log}}';
    }

    public function rules(): array
    {
        return [
            [['supplier_id', 'supplier_sku', 'matcher_name'], 'required'],
            [['supplier_id', 'matched_model_id', 'matched_variant_id'], 'integer'],
            [['supplier_sku'], 'string', 'max' => 255],
            [['matcher_name'], 'string', 'max' => 50],
            [['import_session_id'], 'string', 'max' => 100],
            [['confidence'], 'number'],
        ];
    }

    public function getModel()
    {
        return $this->hasOne(ProductModel::class, ['id' => 'matched_model_id']);
    }

    public function getVariant()
    {
        return $this->hasOne(ReferenceVariant::class, ['id' => 'matched_variant_id']);
    }
}
