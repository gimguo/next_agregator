<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property string $canonical_name
 * @property string $slug
 * @property string|null $manufacturer
 * @property string|null $brand
 * @property string|null $model
 * @property string|null $product_type
 * @property int|null $category_id
 * @property int|null $brand_id
 * @property string|null $description
 * @property float|null $best_price
 * @property float|null $price_range_min
 * @property float|null $price_range_max
 * @property int $supplier_count
 * @property int $total_variants
 * @property bool $is_in_stock
 * @property bool $has_active_offers
 * @property int $image_count
 * @property string $images_status
 * @property string $status
 * @property bool $is_published
 * @property int $quality_score
 * @property string $created_at
 * @property string $updated_at
 *
 * @property SupplierOffer[] $offers
 * @property CardImage[] $images
 * @property Category $category
 * @property Brand $brandModel
 */
class ProductCard extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%product_cards}}';
    }

    public function rules(): array
    {
        return [
            [['canonical_name', 'slug'], 'required'],
            [['canonical_name', 'slug'], 'string', 'max' => 500],
            [['manufacturer', 'brand', 'model'], 'string', 'max' => 255],
            [['product_type', 'status', 'images_status'], 'string', 'max' => 100],
            [['category_id', 'brand_id', 'supplier_count', 'total_variants', 'image_count', 'quality_score'], 'integer'],
            [['best_price', 'price_range_min', 'price_range_max'], 'number'],
            [['is_in_stock', 'has_active_offers', 'is_published'], 'boolean'],
            [['description'], 'string'],
            [['slug'], 'unique'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'canonical_name' => 'Название',
            'manufacturer' => 'Производитель',
            'brand' => 'Бренд',
            'model' => 'Модель',
            'best_price' => 'Лучшая цена',
            'price_range_min' => 'Цена от',
            'price_range_max' => 'Цена до',
            'supplier_count' => 'Поставщики',
            'total_variants' => 'Варианты',
            'is_in_stock' => 'В наличии',
            'status' => 'Статус',
            'images_status' => 'Картинки',
            'image_count' => 'Кол-во фото',
            'quality_score' => 'Качество',
        ];
    }

    public function getOffers()
    {
        return $this->hasMany(SupplierOffer::class, ['card_id' => 'id']);
    }

    public function getImages()
    {
        return $this->hasMany(CardImage::class, ['card_id' => 'id'])->orderBy(['sort_order' => SORT_ASC]);
    }

    public function getCategory()
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
    }

    public function getBrandModel()
    {
        return $this->hasOne(Brand::class, ['id' => 'brand_id']);
    }

    public function getMainImage(): ?CardImage
    {
        return $this->getImages()->andWhere(['is_main' => true, 'status' => 'completed'])->one();
    }
}
