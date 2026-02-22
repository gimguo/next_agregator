<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * Предпросмотр каталога товаров.
 *
 * @property int $id
 * @property int $template_id
 * @property string|null $name
 * @property array $supplier_ids Массив ID поставщиков
 * @property array|null $preview_data JSON данные предпросмотра
 * @property int $product_count
 * @property int $category_count
 * @property int|null $created_by
 * @property string $created_at
 * @property string $updated_at
 *
 * @property CatalogTemplate $template
 * @property CatalogExport[] $exports
 */
class CatalogPreview extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%catalog_previews}}';
    }

    public function rules(): array
    {
        return [
            [['template_id', 'supplier_ids'], 'required'],
            [['template_id', 'product_count', 'category_count', 'created_by'], 'integer'],
            [['name'], 'string', 'max' => 255],
            [['supplier_ids', 'preview_data'], 'safe'], // JSON handled by behavior
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

    public function getTemplate()
    {
        return $this->hasOne(CatalogTemplate::class, ['id' => 'template_id']);
    }

    public function getExports()
    {
        return $this->hasMany(CatalogExport::class, ['preview_id' => 'id']);
    }

    /**
     * Получить массив ID поставщиков (PostgreSQL array).
     */
    public function getSupplierIdsArray(): array
    {
        if (empty($this->supplier_ids)) {
            return [];
        }
        // PostgreSQL возвращает массив как строку вида "{1,2,3}" или уже как массив
        if (is_string($this->supplier_ids)) {
            // Парсим PostgreSQL array format: "{1,2,3}"
            if (preg_match('/^{(.+)}$/', $this->supplier_ids, $matches)) {
                return array_map('intval', explode(',', $matches[1]));
            }
            return [];
        }
        return is_array($this->supplier_ids) ? array_map('intval', $this->supplier_ids) : [];
    }

    /**
     * Установить массив ID поставщиков (конвертирует в PostgreSQL array format).
     */
    public function setSupplierIdsArray(array $ids): void
    {
        // Yii2 автоматически конвертирует массив в PostgreSQL array format
        $this->supplier_ids = $ids;
    }

    /**
     * Получить данные предпросмотра как массив.
     */
    public function getPreviewDataArray(): array
    {
        if (empty($this->preview_data)) {
            return [];
        }
        if (is_string($this->preview_data)) {
            return json_decode($this->preview_data, true) ?: [];
        }
        return is_array($this->preview_data) ? $this->preview_data : [];
    }
}
