<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int $card_id
 * @property int $supplier_id
 * @property string $supplier_sku
 * @property float|null $price_min
 * @property float|null $price_max
 * @property float|null $retail_price  Розничная цена = price_min + наценка (Sprint 11)
 * @property bool $in_stock
 * @property string $stock_status
 * @property int $variant_count
 * @property float $match_confidence
 * @property string $match_method
 * @property bool $is_active
 * @property string $created_at
 * @property string $updated_at
 *
 * @property ProductCard $card
 * @property Supplier $supplier
 */
class SupplierOffer extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%supplier_offers}}';
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'card_id' => 'Карточка',
            'supplier_id' => 'Поставщик',
            'supplier_sku' => 'SKU',
            'price_min' => 'Цена от',
            'price_max' => 'Цена до',
            'retail_price' => 'Розничная цена',
            'in_stock' => 'В наличии',
            'variant_count' => 'Варианты',
            'match_confidence' => 'Уверенность',
            'is_active' => 'Активно',
        ];
    }

    public function getCard()
    {
        return $this->hasOne(ProductCard::class, ['id' => 'card_id']);
    }

    public function getSupplier()
    {
        return $this->hasOne(Supplier::class, ['id' => 'supplier_id']);
    }
}
