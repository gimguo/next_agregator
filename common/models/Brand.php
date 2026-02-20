<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property string $canonical_name
 * @property string $slug
 * @property string|null $country
 * @property int $product_count
 * @property bool $is_active
 */
class Brand extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%brands}}';
    }

    public function rules(): array
    {
        return [
            [['canonical_name', 'slug'], 'required'],
            [['canonical_name', 'slug'], 'string', 'max' => 255],
            [['country'], 'string', 'max' => 100],
            [['product_count', 'sort_order'], 'integer'],
            [['is_active'], 'boolean'],
            [['canonical_name'], 'unique'],
            [['slug'], 'unique'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'canonical_name' => 'Название',
            'slug' => 'Slug',
            'country' => 'Страна',
            'product_count' => 'Товаров',
            'is_active' => 'Активен',
        ];
    }
}
