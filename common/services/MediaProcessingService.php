<?php

namespace common\services;

use Aws\S3\S3Client;
use common\components\S3UrlGenerator;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use yii\base\Component;
use Yii;

/**
 * Сервис обработки медиа-ассетов (DAM Pipeline → MinIO/S3).
 *
 * Полный цикл жизни изображения:
 *   1. Регистрация: INSERT в media_assets со статусом 'pending'
 *   2. Скачивание: GET source_url → временный файл
 *   3. Дедупликация: MD5(содержимого) → проверка file_hash в БД
 *      - Если хеш совпадает → привязка к существующему s3_key, статус 'deduplicated'
 *      - Если хеш новый → конвертация + загрузка в S3
 *   4. Конвертация: WebP (качество 85) + thumb 300x300
 *   5. S3 Upload: products/ab/cd/abcdef1234.webp → MinIO бакет
 *   6. Обновление: s3_key, s3_bucket, status = 'processed'
 *
 * Ключи в S3:
 *   products/{hash[0:2]}/{hash[2:4]}/{hash}.webp
 *   products/{hash[0:2]}/{hash[2:4]}/{hash}_thumb.webp
 *
 * Использование:
 *   $media = Yii::$app->get('mediaService');
 *   $media->registerImages('model', 123, ['https://...jpg', 'https://...png']);
 *   // Позже — в ProcessMediaJob:
 *   $media->processAsset($assetId);
 */
class MediaProcessingService extends Component
{
    /** @var int Качество WebP (0-100) */
    public int $webpQuality = 85;

    /** @var int Максимальная ширина/высота оригинала */
    public int $maxDimension = 1600;

    /** @var int Размер миниатюры */
    public int $thumbSize = 300;

    /** @var int Таймаут скачивания (секунды) */
    public int $downloadTimeout = 30;

    /** @var int Таймаут соединения (секунды) */
    public int $connectTimeout = 10;

    /** @var int Максимальное количество попыток */
    public int $maxAttempts = 3;

    /** @var string Префикс для ключей в S3 */
    public string $s3Prefix = 'products';

    /** @var Filesystem|null Flysystem с S3-адаптером */
    private ?Filesystem $fs = null;

    /** @var string|null Имя бакета */
    private ?string $bucket = null;

    /** @var array Статистика текущей сессии */
    private array $stats = [
        'registered'   => 0,
        'downloaded'   => 0,
        'deduplicated' => 0,
        'processed'    => 0,
        'errors'       => 0,
        'skipped'      => 0,
    ];

    // ═══════════════════════════════════════════
    // ИНИЦИАЛИЗАЦИЯ S3
    // ═══════════════════════════════════════════

    /**
     * Получить Flysystem Filesystem с S3-адаптером.
     */
    protected function getFilesystem(): Filesystem
    {
        if ($this->fs === null) {
            $params = Yii::$app->params['s3'] ?? [];

            $s3Client = new S3Client([
                'version'                 => 'latest',
                'region'                  => $params['region'] ?? 'us-east-1',
                'endpoint'                => $params['endpoint'] ?? 'http://minio:9000',
                'use_path_style_endpoint' => $params['usePathStyle'] ?? true,
                'credentials' => [
                    'key'    => $params['key'] ?? 'minioadmin',
                    'secret' => $params['secret'] ?? 'minioadmin',
                ],
                'http' => [
                    'verify'          => false,
                    'connect_timeout' => 5,
                    'timeout'         => 30,
                    'proxy'           => '',  // Отключаем прокси (Docker internal network)
                ],
            ]);

            $this->bucket = $params['bucket'] ?? 'media';
            $adapter = new AwsS3V3Adapter($s3Client, $this->bucket);
            $this->fs = new Filesystem($adapter);
        }

        return $this->fs;
    }

    /**
     * Получить имя бакета.
     */
    protected function getBucket(): string
    {
        $this->getFilesystem(); // Инициализирует $this->bucket
        return $this->bucket;
    }

    // ═══════════════════════════════════════════
    // РЕГИСТРАЦИЯ ИЗОБРАЖЕНИЙ
    // ═══════════════════════════════════════════

    /**
     * Зарегистрировать массив URL изображений для сущности.
     *
     * Создаёт записи в media_assets со статусом 'pending'.
     * Дедупликация по source_url_hash: если URL уже зарегистрирован — пропускаем.
     *
     * @param string   $entityType 'model', 'variant', 'offer'
     * @param int      $entityId   ID сущности
     * @param string[] $urls       Массив URL изображений
     * @return int Количество вставленных записей
     */
    public function registerImages(string $entityType, int $entityId, array $urls): int
    {
        if (empty($urls)) return 0;

        $db = Yii::$app->db;
        $inserted = 0;

        foreach ($urls as $sortOrder => $url) {
            $url = trim($url);
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $sourceUrlHash = md5($url);

            try {
                $db->createCommand()->insert('{{%media_assets}}', [
                    'entity_type'     => $entityType,
                    'entity_id'       => $entityId,
                    'source_url'      => $url,
                    'source_url_hash' => $sourceUrlHash,
                    'status'          => 'pending',
                    'is_primary'      => ($sortOrder === 0),
                    'sort_order'      => $sortOrder,
                ])->execute();

                $inserted++;
                $this->stats['registered']++;
            } catch (\yii\db\IntegrityException $e) {
                // Дубль по source_url_hash — уже зарегистрирован, пропускаем
                $this->stats['skipped']++;
            }
        }

        return $inserted;
    }

    // ═══════════════════════════════════════════
    // ОБРАБОТКА ОДНОГО АССЕТА
    // ═══════════════════════════════════════════

    /**
     * Обработать один media_asset: скачать → дедупликация → WebP → S3.
     *
     * @param int $assetId ID записи в media_assets
     * @return string Результат: 'processed', 'deduplicated', 'error', 'skip'
     */
    public function processAsset(int $assetId): string
    {
        $db = Yii::$app->db;

        $asset = $db->createCommand(
            "SELECT * FROM {{%media_assets}} WHERE id = :id",
            [':id' => $assetId]
        )->queryOne();

        if (!$asset) {
            return 'skip';
        }

        // Уже обработан
        if (in_array($asset['status'], ['processed', 'deduplicated'])) {
            return 'skip';
        }

        // Превышены попытки
        if ((int)$asset['attempts'] >= $this->maxAttempts) {
            return 'skip';
        }

        // Помечаем как downloading
        $db->createCommand("
            UPDATE {{%media_assets}} SET status = 'downloading', attempts = attempts + 1, updated_at = NOW()
            WHERE id = :id
        ", [':id' => $assetId])->execute();

        $tmpFile = null;

        try {
            // 1. Скачиваем во временный файл
            $tmpFile = tempnam(sys_get_temp_dir(), 'media_');
            $this->downloadFile($asset['source_url'], $tmpFile);

            // Проверяем валидность
            $fileSize = filesize($tmpFile);
            if ($fileSize < 100) {
                throw new \RuntimeException("File too small ({$fileSize} bytes)");
            }

            // 2. Вычисляем MD5 содержимого
            $fileHash = md5_file($tmpFile);

            // 3. ДЕДУПЛИКАЦИЯ — ищем по file_hash
            $existing = $db->createCommand("
                SELECT id, s3_bucket, s3_key, s3_thumb_key, mime_type, size_bytes, width, height
                FROM {{%media_assets}}
                WHERE file_hash = :hash AND status IN ('processed', 'deduplicated') AND s3_key IS NOT NULL
                LIMIT 1
            ", [':hash' => $fileHash])->queryOne();

            if ($existing) {
                // Дедупликация! Привязываем к существующему S3-ключу
                $db->createCommand("
                    UPDATE {{%media_assets}} SET
                        file_hash = :hash,
                        s3_bucket = :bucket,
                        s3_key = :key,
                        s3_thumb_key = :thumb,
                        mime_type = :mime,
                        size_bytes = :size,
                        width = :w,
                        height = :h,
                        original_asset_id = :orig,
                        status = 'deduplicated',
                        processed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                ", [
                    ':hash'   => $fileHash,
                    ':bucket' => $existing['s3_bucket'],
                    ':key'    => $existing['s3_key'],
                    ':thumb'  => $existing['s3_thumb_key'],
                    ':mime'   => $existing['mime_type'],
                    ':size'   => $existing['size_bytes'],
                    ':w'      => $existing['width'],
                    ':h'      => $existing['height'],
                    ':orig'   => $existing['id'],
                    ':id'     => $assetId,
                ])->execute();

                @unlink($tmpFile);
                $this->stats['deduplicated']++;
                return 'deduplicated';
            }

            // 4. Новый файл — конвертируем в WebP
            $result = $this->convertToWebP($tmpFile);

            // 5. Загружаем в S3
            $s3Result = $this->uploadToS3($fileHash, $result['webpData'], $result['thumbData']);

            // 6. Обновляем запись
            $db->createCommand("
                UPDATE {{%media_assets}} SET
                    file_hash = :hash,
                    s3_bucket = :bucket,
                    s3_key = :key,
                    s3_thumb_key = :thumb,
                    mime_type = 'image/webp',
                    size_bytes = :size,
                    width = :w,
                    height = :h,
                    status = 'processed',
                    processed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ", [
                ':hash'   => $fileHash,
                ':bucket' => $s3Result['bucket'],
                ':key'    => $s3Result['key'],
                ':thumb'  => $s3Result['thumb_key'],
                ':size'   => $s3Result['size_bytes'],
                ':w'      => $result['width'],
                ':h'      => $result['height'],
                ':id'     => $assetId,
            ])->execute();

            @unlink($tmpFile);
            $this->stats['processed']++;
            return 'processed';

        } catch (\Throwable $e) {
            if ($tmpFile && file_exists($tmpFile)) {
                @unlink($tmpFile);
            }

            $db->createCommand("
                UPDATE {{%media_assets}} SET
                    status = 'error',
                    error_message = :err,
                    updated_at = NOW()
                WHERE id = :id
            ", [
                ':err' => mb_substr($e->getMessage(), 0, 1000),
                ':id'  => $assetId,
            ])->execute();

            $this->stats['errors']++;

            Yii::warning(
                "MediaService: error asset_id={$assetId}: {$e->getMessage()}",
                'media'
            );

            return 'error';
        }
    }

    // ═══════════════════════════════════════════
    // BATCH PROCESSING (FOR UPDATE SKIP LOCKED)
    // ═══════════════════════════════════════════

    /**
     * Атомарно забрать и обработать пачку pending-ассетов.
     *
     * Использует FOR UPDATE SKIP LOCKED для конкурентной безопасности
     * (несколько worker-ов могут параллельно обрабатывать разные ассеты).
     *
     * @param int $limit Сколько обработать за раз
     * @return array Статистика: ['processed', 'deduplicated', 'errors', 'total']
     */
    public function processPendingBatch(int $limit = 50): array
    {
        $db = Yii::$app->db;

        // Атомарно забираем пачку с блокировкой
        $assetIds = $db->createCommand("
            UPDATE {{%media_assets}}
            SET status = 'downloading', updated_at = NOW()
            WHERE id IN (
                SELECT id FROM {{%media_assets}}
                WHERE status = 'pending' AND attempts < :max
                ORDER BY is_primary DESC, sort_order ASC, created_at ASC
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
            )
            RETURNING id
        ", [':max' => $this->maxAttempts, ':limit' => $limit])->queryColumn();

        $batch = ['processed' => 0, 'deduplicated' => 0, 'errors' => 0, 'total' => count($assetIds)];

        if (empty($assetIds)) {
            return $batch;
        }

        // Возвращаем в pending перед processAsset (он сам пометит downloading)
        $inParams = [];
        $inPlaceholders = [];
        foreach ($assetIds as $i => $id) {
            $key = ':id' . $i;
            $inParams[$key] = $id;
            $inPlaceholders[] = $key;
        }
        $inSql = implode(',', $inPlaceholders);
        $db->createCommand(
            "UPDATE {{%media_assets}} SET status = 'pending' WHERE id IN ({$inSql})",
            $inParams
        )->execute();

        foreach ($assetIds as $assetId) {
            $result = $this->processAsset((int)$assetId);

            match ($result) {
                'processed'    => $batch['processed']++,
                'deduplicated' => $batch['deduplicated']++,
                'error'        => $batch['errors']++,
                default        => null,
            };

            // Пауза между скачиваниями (50мс)
            usleep(50_000);
        }

        return $batch;
    }

    /**
     * Вернуть error-ассеты в pending для retry.
     */
    public function retryErrors(): int
    {
        return Yii::$app->db->createCommand("
            UPDATE {{%media_assets}}
            SET status = 'pending', error_message = NULL, updated_at = NOW()
            WHERE status = 'error' AND attempts < :max
        ", [':max' => $this->maxAttempts])->execute();
    }

    // ═══════════════════════════════════════════
    // ЧТЕНИЕ (для Syndication)
    // ═══════════════════════════════════════════

    /**
     * Получить обработанные изображения для сущности (с публичными S3-URL).
     *
     * @return array Массив ['url' => ..., 'thumb_url' => ..., 'is_primary' => ...]
     */
    public function getProcessedImages(string $entityType, int $entityId): array
    {
        $rows = Yii::$app->db->createCommand("
            SELECT id, s3_bucket, s3_key, s3_thumb_key, width, height, size_bytes, is_primary, sort_order
            FROM {{%media_assets}}
            WHERE entity_type = :type AND entity_id = :eid
              AND status IN ('processed', 'deduplicated')
              AND s3_key IS NOT NULL
            ORDER BY is_primary DESC, sort_order ASC
        ", [':type' => $entityType, ':eid' => $entityId])->queryAll();

        return array_map(fn($row) => [
            'id'         => (int)$row['id'],
            'url'        => S3UrlGenerator::getPublicUrl($row['s3_bucket'], $row['s3_key']),
            'thumb_url'  => S3UrlGenerator::getThumbUrl($row['s3_bucket'], $row['s3_thumb_key']),
            'width'      => $row['width'] ? (int)$row['width'] : null,
            'height'     => $row['height'] ? (int)$row['height'] : null,
            'size_bytes' => $row['size_bytes'] ? (int)$row['size_bytes'] : null,
            'is_primary' => (bool)$row['is_primary'],
        ], $rows);
    }

    /**
     * Получить primary-изображение для сущности.
     */
    public function getPrimaryImage(string $entityType, int $entityId): ?array
    {
        $images = $this->getProcessedImages($entityType, $entityId);
        return $images[0] ?? null;
    }

    /**
     * Получить обработанные изображения для нескольких сущностей (батч).
     *
     * @param string $entityType
     * @param int[]  $entityIds
     * @return array<int, array> entity_id => [images]
     */
    public function getProcessedImagesBatch(string $entityType, array $entityIds): array
    {
        if (empty($entityIds)) return [];

        $inParams = [];
        $inPlaceholders = [];
        foreach ($entityIds as $i => $eid) {
            $key = ':eid' . $i;
            $inParams[$key] = $eid;
            $inPlaceholders[] = $key;
        }
        $inSql = implode(',', $inPlaceholders);

        $rows = Yii::$app->db->createCommand("
            SELECT id, entity_id, s3_bucket, s3_key, s3_thumb_key, width, height, size_bytes, is_primary, sort_order
            FROM {{%media_assets}}
            WHERE entity_type = :type AND entity_id IN ({$inSql})
              AND status IN ('processed', 'deduplicated')
              AND s3_key IS NOT NULL
            ORDER BY entity_id, is_primary DESC, sort_order ASC
        ", array_merge([':type' => $entityType], $inParams))->queryAll();

        $grouped = [];
        foreach ($rows as $row) {
            $eid = (int)$row['entity_id'];
            $grouped[$eid][] = [
                'id'         => (int)$row['id'],
                'url'        => S3UrlGenerator::getPublicUrl($row['s3_bucket'], $row['s3_key']),
                'thumb_url'  => S3UrlGenerator::getThumbUrl($row['s3_bucket'], $row['s3_thumb_key']),
                'width'      => $row['width'] ? (int)$row['width'] : null,
                'height'     => $row['height'] ? (int)$row['height'] : null,
                'size_bytes' => $row['size_bytes'] ? (int)$row['size_bytes'] : null,
                'is_primary' => (bool)$row['is_primary'],
            ];
        }

        return $grouped;
    }

    // ═══════════════════════════════════════════
    // СТАТИСТИКА
    // ═══════════════════════════════════════════

    /**
     * Общая статистика media_assets.
     */
    public function getGlobalStats(): array
    {
        $rows = Yii::$app->db->createCommand("
            SELECT status, COUNT(*) as cnt, SUM(size_bytes) as total_size
            FROM {{%media_assets}}
            GROUP BY status
        ")->queryAll();

        $stats = [];
        $totalCount = 0;
        $totalSize = 0;
        foreach ($rows as $row) {
            $stats[$row['status']] = [
                'count' => (int)$row['cnt'],
                'size'  => (int)($row['total_size'] ?? 0),
            ];
            $totalCount += (int)$row['cnt'];
            $totalSize += (int)($row['total_size'] ?? 0);
        }

        $stats['_total'] = ['count' => $totalCount, 'size' => $totalSize];

        return $stats;
    }

    /**
     * Статистика текущей сессии.
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    public function resetStats(): void
    {
        $this->stats = [
            'registered' => 0, 'downloaded' => 0, 'deduplicated' => 0,
            'processed' => 0, 'errors' => 0, 'skipped' => 0,
        ];
    }

    // ═══════════════════════════════════════════
    // PRIVATE: Скачивание
    // ═══════════════════════════════════════════

    /**
     * Скачать файл по URL в указанный путь.
     */
    protected function downloadFile(string $url, string $targetPath): void
    {
        $client = new \GuzzleHttp\Client([
            'timeout'         => $this->downloadTimeout,
            'connect_timeout' => $this->connectTimeout,
            'headers' => [
                'User-Agent' => 'Agregator/3.0 MediaDownloader',
                'Accept'     => 'image/*',
            ],
            'verify' => false,
        ]);

        $response = $client->get($url, ['sink' => $targetPath]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException("HTTP {$response->getStatusCode()} for {$url}");
        }

        $this->stats['downloaded']++;
    }

    // ═══════════════════════════════════════════
    // PRIVATE: Конвертация в WebP (GD)
    // ═══════════════════════════════════════════

    /**
     * Конвертировать изображение в WebP.
     *
     * @return array ['webpData' => string, 'thumbData' => string|null, 'width' => int, 'height' => int]
     */
    protected function convertToWebP(string $tmpFile): array
    {
        $imageInfo = @getimagesize($tmpFile);
        if (!$imageInfo) {
            throw new \RuntimeException("Not a valid image file");
        }

        $srcW = $imageInfo[0];
        $srcH = $imageInfo[1];
        $type = $imageInfo[2];

        // Загружаем исходник
        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpFile),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmpFile),
            IMAGETYPE_WEBP => @imagecreatefromwebp($tmpFile),
            IMAGETYPE_GIF  => @imagecreatefromgif($tmpFile),
            IMAGETYPE_BMP  => @imagecreatefrombmp($tmpFile),
            default        => null,
        };

        if (!$src) {
            throw new \RuntimeException("Cannot create image from type={$type}");
        }

        // Ресайз если слишком большой
        if ($srcW > $this->maxDimension || $srcH > $this->maxDimension) {
            $ratio = min($this->maxDimension / $srcW, $this->maxDimension / $srcH);
            $newW = (int)round($srcW * $ratio);
            $newH = (int)round($srcH * $ratio);

            $resized = imagecreatetruecolor($newW, $newH);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
            imagedestroy($src);
            $src = $resized;
            $srcW = $newW;
            $srcH = $newH;
        }

        // ═══ WebP в память ═══
        ob_start();
        imagewebp($src, null, $this->webpQuality);
        $webpData = ob_get_clean();

        // ═══ Миниатюра в память ═══
        $thumbData = null;
        if ($srcW > $this->thumbSize || $srcH > $this->thumbSize) {
            $thumbRatio = min($this->thumbSize / $srcW, $this->thumbSize / $srcH);
            $thumbW = (int)round($srcW * $thumbRatio);
            $thumbH = (int)round($srcH * $thumbRatio);

            $thumb = imagecreatetruecolor($thumbW, $thumbH);
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            imagecopyresampled($thumb, $src, 0, 0, 0, 0, $thumbW, $thumbH, $srcW, $srcH);

            ob_start();
            imagewebp($thumb, null, 80);
            $thumbData = ob_get_clean();
            imagedestroy($thumb);
        }

        imagedestroy($src);

        return [
            'webpData'  => $webpData,
            'thumbData' => $thumbData,
            'width'     => $srcW,
            'height'    => $srcH,
        ];
    }

    // ═══════════════════════════════════════════
    // PRIVATE: Загрузка в S3/MinIO
    // ═══════════════════════════════════════════

    /**
     * Загрузить WebP и thumb в S3.
     *
     * Ключи:
     *   products/1a/2b/1a2b3c4d5e6f7890abcdef1234567890.webp
     *   products/1a/2b/1a2b3c4d5e6f7890abcdef1234567890_thumb.webp
     *
     * @return array ['bucket', 'key', 'thumb_key', 'size_bytes']
     */
    protected function uploadToS3(string $fileHash, string $webpData, ?string $thumbData): array
    {
        $fs = $this->getFilesystem();
        $bucket = $this->getBucket();

        // Иерархический ключ
        $subDir1 = substr($fileHash, 0, 2);
        $subDir2 = substr($fileHash, 2, 2);

        $key = "{$this->s3Prefix}/{$subDir1}/{$subDir2}/{$fileHash}.webp";
        $thumbKey = null;

        // Загружаем основное изображение
        $fs->write($key, $webpData, [
            'ContentType' => 'image/webp',
            'CacheControl' => 'public, max-age=31536000',
        ]);

        // Загружаем миниатюру
        if ($thumbData !== null) {
            $thumbKey = "{$this->s3Prefix}/{$subDir1}/{$subDir2}/{$fileHash}_thumb.webp";
            $fs->write($thumbKey, $thumbData, [
                'ContentType' => 'image/webp',
                'CacheControl' => 'public, max-age=31536000',
            ]);
        }

        return [
            'bucket'     => $bucket,
            'key'        => $key,
            'thumb_key'  => $thumbKey,
            'size_bytes' => strlen($webpData),
        ];
    }
}
