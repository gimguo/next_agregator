<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int $card_id
 * @property string $source_url
 * @property string|null $local_path
 * @property string|null $thumb_path
 * @property string|null $medium_path
 * @property string|null $large_path
 * @property string|null $webp_path
 * @property int|null $width
 * @property int|null $height
 * @property int|null $file_size
 * @property string|null $mime_type
 * @property string $status
 * @property string|null $error_message
 * @property int $attempts
 * @property int $sort_order
 * @property bool $is_main
 * @property string $created_at
 * @property string|null $downloaded_at
 *
 * @property ProductCard $card
 */
class CardImage extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%card_images}}';
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'card_id' => 'Карточка',
            'source_url' => 'URL источника',
            'status' => 'Статус',
            'is_main' => 'Главная',
            'file_size' => 'Размер',
        ];
    }

    public function getCard()
    {
        return $this->hasOne(ProductCard::class, ['id' => 'card_id']);
    }

    /**
     * URL для отображения (thumb → medium → large → original).
     */
    public function getDisplayUrl(string $size = 'medium'): ?string
    {
        $path = match ($size) {
            'thumb' => $this->thumb_path,
            'medium' => $this->medium_path,
            'large' => $this->large_path,
            'webp' => $this->webp_path,
            default => $this->local_path,
        };

        if ($path) {
            return '/storage/' . $path;
        }

        // fallback
        if ($this->local_path) {
            return '/storage/' . $this->local_path;
        }

        return null;
    }
}
