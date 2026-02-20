<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * Базовая модель товара в MDM-каталоге.
 *
 * Один экземпляр = один уникальный товар (Brand + Model).
 * Например: "Орматек Оптима" — это одна модель с множеством вариантов (размеров).
 *
 * @property int    $id
 * @property string $product_family      ProductFamily enum value
 * @property int    $brand_id            FK → brands
 * @property int    $category_id         FK → categories
 * @property string $name                "Орматек Оптима"
 * @property string $slug
 * @property string $manufacturer
 * @property string $model_name
 * @property string $description
 * @property string $short_description
 * @property array  $canonical_attributes JSONB
 * @property array  $canonical_images    JSONB
 * @property string $meta_title
 * @property string $meta_description
 * @property float  $best_price
 * @property float  $price_range_min
 * @property float  $price_range_max
 * @property int    $supplier_count
 * @property int    $variant_count
 * @property int    $offer_count
 * @property bool   $is_in_stock
 * @property int    $quality_score
 * @property string $status
 * @property bool   $is_published
 * @property int    $legacy_card_id
 * @property string $created_at
 * @property string $updated_at
 */
class ProductModel extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%product_models}}';
    }

    public function rules(): array
    {
        return [
            [['product_family', 'name', 'slug'], 'required'],
            [['name', 'slug'], 'string', 'max' => 500],
            [['product_family', 'status'], 'string', 'max' => 30],
            [['manufacturer', 'model_name'], 'string', 'max' => 255],
            [['meta_title'], 'string', 'max' => 500],
            [['description', 'short_description', 'meta_description'], 'string'],
            [['brand_id', 'category_id', 'supplier_count', 'variant_count', 'offer_count', 'quality_score', 'legacy_card_id'], 'integer'],
            [['best_price', 'price_range_min', 'price_range_max'], 'number'],
            [['is_in_stock', 'is_published'], 'boolean'],
            [['slug'], 'unique'],
        ];
    }

    public function getBrand()
    {
        return $this->hasOne(Brand::class, ['id' => 'brand_id']);
    }

    public function getCategory()
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
    }

    public function getVariants()
    {
        return $this->hasMany(ReferenceVariant::class, ['model_id' => 'id']);
    }

    public function getOffers()
    {
        return $this->hasMany(SupplierOffer::class, ['model_id' => 'id']);
    }
}
