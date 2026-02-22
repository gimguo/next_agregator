<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * Шаблон каталога товаров.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property array $structure_json JSON структура категорий
 * @property array|null $merge_rules JSONB правила объединения
 * @property bool $is_system
 * @property string $created_at
 * @property string $updated_at
 *
 * @property CatalogPreview[] $previews
 */
class CatalogTemplate extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%catalog_templates}}';
    }

    public function rules(): array
    {
        return [
            [['name', 'structure_json'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['description'], 'string'],
            [['structure_json', 'merge_rules'], 'safe'], // JSONB handled by behavior
            [['is_system'], 'boolean'],
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
        ];
    }

    public function getPreviews()
    {
        return $this->hasMany(CatalogPreview::class, ['template_id' => 'id']);
    }

    /**
     * Получить структуру категорий как массив.
     */
    public function getStructure(): array
    {
        if (empty($this->structure_json)) {
            return ['categories' => []];
        }
        if (is_string($this->structure_json)) {
            return json_decode($this->structure_json, true) ?: ['categories' => []];
        }
        return is_array($this->structure_json) ? $this->structure_json : ['categories' => []];
    }

    /**
     * Получить правила объединения как массив.
     */
    public function getMergeRules(): array
    {
        if (empty($this->merge_rules)) {
            return [];
        }
        if (is_string($this->merge_rules)) {
            return json_decode($this->merge_rules, true) ?: [];
        }
        return is_array($this->merge_rules) ? $this->merge_rules : [];
    }
}
