<?php

namespace common\components;

use Yii;

/**
 * Генератор публичных URL для S3/MinIO-ассетов.
 *
 * Использование:
 *   S3UrlGenerator::getPublicUrl('media', 'products/1a/2b/hash.webp')
 *   → http://localhost:9000/media/products/1a/2b/hash.webp
 *
 * Конфигурируется через ENV:
 *   S3_PUBLIC_URL=http://localhost:9000/media
 *
 * В продакшене это может быть CDN:
 *   S3_PUBLIC_URL=https://cdn.rosmatras.ru/media
 */
class S3UrlGenerator
{
    /**
     * Получить публичный URL для объекта в S3.
     *
     * @param string $bucket Имя бакета (например, 'media')
     * @param string $key    Ключ объекта (например, 'products/1a/2b/hash.webp')
     * @return string Полный публичный URL
     */
    public static function getPublicUrl(string $bucket, string $key): string
    {
        // Приоритет: S3_PUBLIC_URL из ENV (может быть CDN)
        $publicUrl = self::getPublicBaseUrl($bucket);

        return rtrim($publicUrl, '/') . '/' . ltrim($key, '/');
    }

    /**
     * Получить публичный URL для миниатюры.
     *
     * @param string $bucket   Имя бакета
     * @param string|null $thumbKey Ключ миниатюры (null если нет)
     * @return string|null URL миниатюры или null
     */
    public static function getThumbUrl(string $bucket, ?string $thumbKey): ?string
    {
        if (empty($thumbKey)) {
            return null;
        }
        return self::getPublicUrl($bucket, $thumbKey);
    }

    /**
     * Получить базовый публичный URL для бакета.
     *
     * Порядок приоритетов:
     *   1. S3_PUBLIC_URL из ENV
     *   2. Конфигурация приложения: params['s3']['publicUrl']
     *   3. Fallback: S3_ENDPOINT/bucket
     */
    private static function getPublicBaseUrl(string $bucket): string
    {
        // 1. ENV (лучший вариант — может быть CDN)
        $envUrl = getenv('S3_PUBLIC_URL');
        if (!empty($envUrl)) {
            return $envUrl;
        }

        // 2. Конфигурация приложения
        $params = Yii::$app->params['s3'] ?? [];
        if (!empty($params['publicUrl'])) {
            return $params['publicUrl'];
        }

        // 3. Fallback: endpoint + bucket
        $endpoint = $params['endpoint'] ?? (getenv('S3_ENDPOINT') ?: 'http://minio:9000');
        return rtrim($endpoint, '/') . '/' . $bucket;
    }
}
