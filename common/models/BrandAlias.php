<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * Синонимы и опечатки брендов для авто-резолва.
 *
 * @property int $id
 * @property int $brand_id FK → brands
 * @property string $alias Синоним/опечатка (например, 'Tjyota' для Toyota)
 * @property string $created_at
 *
 * @property Brand $brand
 */
class BrandAlias extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%brand_aliases}}';
    }

    public function rules(): array
    {
        return [
            [['brand_id', 'alias'], 'required'],
            [['brand_id'], 'integer'],
            [['alias'], 'string', 'max' => 255],
            [['alias'], 'unique'],
            [['brand_id'], 'exist', 'skipOnError' => true, 'targetClass' => Brand::class, 'targetAttribute' => ['brand_id' => 'id']],
        ];
    }

    public function getBrand()
    {
        return $this->hasOne(Brand::class, ['id' => 'brand_id']);
    }
}
