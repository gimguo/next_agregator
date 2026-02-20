<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property int $depth
 * @property int $sort_order
 * @property bool $is_active
 */
class Category extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%categories}}';
    }

    public function rules(): array
    {
        return [
            [['name', 'slug'], 'required'],
            [['name', 'slug'], 'string', 'max' => 255],
            [['parent_id', 'depth', 'sort_order'], 'integer'],
            [['is_active'], 'boolean'],
            [['slug'], 'unique'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'name' => 'Название',
            'slug' => 'Slug',
            'is_active' => 'Активна',
        ];
    }

    public function getParent()
    {
        return $this->hasOne(self::class, ['id' => 'parent_id']);
    }

    public function getChildren()
    {
        return $this->hasMany(self::class, ['parent_id' => 'id'])->orderBy(['sort_order' => SORT_ASC]);
    }
}
