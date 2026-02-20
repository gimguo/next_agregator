<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * Эталонный вариант товара (размер/цвет/материал).
 *
 * Один вариант = уникальная комбинация осей вариаций для модели.
 * Например: "Орматек Оптима 160×200" и "Орматек Оптима 180×200" — два варианта одной модели.
 *
 * @property int    $id
 * @property int    $model_id            FK → product_models
 * @property string $gtin                EAN-13 штрихкод (nullable)
 * @property string $mpn                 Manufacturer Part Number (nullable)
 * @property array  $variant_attributes  JSONB: {"width": 160, "length": 200}
 * @property string $variant_label       "160×200" (человекочитаемый)
 * @property float  $best_price
 * @property float  $price_range_min
 * @property float  $price_range_max
 * @property bool   $is_in_stock
 * @property int    $supplier_count
 * @property int    $sort_order
 * @property string $created_at
 * @property string $updated_at
 */
class ReferenceVariant extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%reference_variants}}';
    }

    public function rules(): array
    {
        return [
            [['model_id'], 'required'],
            [['model_id', 'supplier_count', 'sort_order'], 'integer'],
            [['gtin'], 'string', 'max' => 14],
            [['mpn'], 'string', 'max' => 100],
            [['variant_label'], 'string', 'max' => 100],
            [['best_price', 'price_range_min', 'price_range_max'], 'number'],
            [['is_in_stock'], 'boolean'],
        ];
    }

    public function getModel()
    {
        return $this->hasOne(ProductModel::class, ['id' => 'model_id']);
    }

    public function getOffers()
    {
        return $this->hasMany(SupplierOffer::class, ['variant_id' => 'id']);
    }

    /**
     * Получить атрибуты вариации как массив.
     */
    public function getAttrs(): array
    {
        if (is_string($this->variant_attributes)) {
            return json_decode($this->variant_attributes, true) ?: [];
        }
        return $this->variant_attributes ?: [];
    }
}
