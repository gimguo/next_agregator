<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $website
 * @property string $format
 * @property string|null $parser_class
 * @property array|null $config
 * @property bool $is_active
 * @property string|null $last_import_at
 * @property string $created_at
 * @property string $updated_at
 *
 * @property SupplierOffer[] $offers
 */
class Supplier extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%suppliers}}';
    }

    public function rules(): array
    {
        return [
            [['code', 'name'], 'required'],
            [['code'], 'string', 'max' => 50],
            [['name', 'parser_class'], 'string', 'max' => 255],
            [['website'], 'string', 'max' => 500],
            [['format'], 'string', 'max' => 20],
            [['is_active'], 'boolean'],
            [['code'], 'unique'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'code' => 'Код',
            'name' => 'Название',
            'website' => 'Сайт',
            'format' => 'Формат прайса',
            'parser_class' => 'Класс парсера',
            'is_active' => 'Активен',
            'last_import_at' => 'Последний импорт',
        ];
    }

    public function getOffers()
    {
        return $this->hasMany(SupplierOffer::class, ['supplier_id' => 'id']);
    }

    public function getOffersCount(): int
    {
        return (int)$this->getOffers()->count();
    }
}
