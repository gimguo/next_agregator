<?php

namespace common\models;

use common\components\S3UrlGenerator;
use yii\db\ActiveRecord;

/**
 * Медиа-ассет (изображение) в DAM-хранилище.
 *
 * @property int    $id
 * @property string $entity_type       'model', 'variant', 'offer'
 * @property int    $entity_id
 * @property string $source_url
 * @property string $source_url_hash
 * @property string $file_hash         MD5 содержимого
 * @property string $s3_bucket
 * @property string $s3_key
 * @property string $s3_thumb_key
 * @property string $mime_type
 * @property int    $size_bytes
 * @property int    $width
 * @property int    $height
 * @property string $status            'pending', 'downloading', 'processed', 'deduplicated', 'error'
 * @property bool   $is_primary
 * @property int    $sort_order
 * @property string $error_message
 * @property int    $attempts
 * @property int    $original_asset_id
 * @property string $created_at
 * @property string $updated_at
 * @property string $processed_at
 */
class MediaAsset extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%media_assets}}';
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'entity_type' => 'Тип сущности',
            'entity_id' => 'ID сущности',
            'source_url' => 'Источник URL',
            'file_hash' => 'Hash файла',
            's3_bucket' => 'S3 Bucket',
            's3_key' => 'S3 Key',
            's3_thumb_key' => 'Thumb Key',
            'mime_type' => 'MIME',
            'size_bytes' => 'Размер (байт)',
            'width' => 'Ширина',
            'height' => 'Высота',
            'status' => 'Статус',
            'is_primary' => 'Основное',
            'sort_order' => 'Порядок',
            'error_message' => 'Ошибка',
            'attempts' => 'Попытки',
            'created_at' => 'Создано',
            'updated_at' => 'Обновлено',
            'processed_at' => 'Обработано',
        ];
    }

    /**
     * Публичный URL полного изображения.
     */
    public function getPublicUrl(): ?string
    {
        if (empty($this->s3_key) || empty($this->s3_bucket)) {
            return null;
        }
        return S3UrlGenerator::getPublicUrl($this->s3_bucket, $this->s3_key);
    }

    /**
     * Публичный URL миниатюры.
     */
    public function getThumbUrl(): ?string
    {
        if (empty($this->s3_thumb_key) || empty($this->s3_bucket)) {
            return $this->getPublicUrl();
        }
        return S3UrlGenerator::getThumbUrl($this->s3_bucket, $this->s3_thumb_key);
    }
}
