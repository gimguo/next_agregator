<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * Экспорт каталога на витрину.
 *
 * @property int $id
 * @property int $preview_id
 * @property string $status pending|processing|completed|failed
 * @property array|null $stats_json JSONB статистика
 * @property string $created_at
 *
 * @property CatalogPreview $preview
 */
class CatalogExport extends ActiveRecord
{
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public static function tableName(): string
    {
        return '{{%catalog_exports}}';
    }

    public function rules(): array
    {
        return [
            [['preview_id'], 'required'],
            [['preview_id'], 'integer'],
            [['status'], 'string', 'max' => 20],
            [['status'], 'in', 'range' => [self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_COMPLETED, self::STATUS_FAILED]],
            [['stats_json'], 'safe'], // JSONB
        ];
    }

    public function getPreview()
    {
        return $this->hasOne(CatalogPreview::class, ['id' => 'preview_id']);
    }

    /**
     * Получить статистику как массив.
     */
    public function getStatsArray(): array
    {
        if (empty($this->stats_json)) {
            return [];
        }
        if (is_string($this->stats_json)) {
            return json_decode($this->stats_json, true) ?: [];
        }
        return is_array($this->stats_json) ? $this->stats_json : [];
    }
}
