<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * Эталонный справочник брендов.
 *
 * @property int $id
 * @property string $name Эталонное название бренда
 * @property string|null $slug URL-friendly slug
 * @property bool $is_active
 * @property string $created_at
 * @property string $updated_at
 *
 * @property BrandAlias[] $aliases
 * @property ProductModel[] $productModels
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
            [['name'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['slug'], 'string', 'max' => 255],
            [['slug'], 'unique'],
            [['name'], 'unique'],
            [['is_active'], 'boolean'],
        ];
    }

    public function behaviors(): array
    {
        return [
            [
                'class' => \yii\behaviors\TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => 'updated_at',
                'value' => new \yii\db\Expression('CURRENT_TIMESTAMP'),
            ],
            [
                'class' => \yii\behaviors\SluggableBehavior::class,
                'attribute' => 'name',
                'slugAttribute' => 'slug',
                'ensureUnique' => true,
            ],
        ];
    }

    public function getAliases()
    {
        return $this->hasMany(BrandAlias::class, ['brand_id' => 'id']);
    }

    public function getProductModels()
    {
        return $this->hasMany(ProductModel::class, ['brand_id' => 'id']);
    }

    /**
     * Получить все алиасы как массив строк.
     */
    public function getAliasList(): array
    {
        return $this->getAliases()->select('alias')->column();
    }
}
