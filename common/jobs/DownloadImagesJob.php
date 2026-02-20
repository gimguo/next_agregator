<?php

namespace common\jobs;

use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Задание: скачать картинки для пакета карточек.
 *
 * Использование:
 *   Yii::$app->queue->push(new DownloadImagesJob([
 *       'cardIds' => [1, 2, 3],
 *       'supplierCode' => 'ormatek',
 *   ]));
 */
class DownloadImagesJob extends BaseObject implements JobInterface
{
    private const STORAGE_BASE = '/app/storage/images';

    private const SIZES = [
        'thumb'  => [300, 300],
        'medium' => [600, 600],
        'large'  => [1200, 1200],
    ];

    /** @var int[] */
    public array $cardIds = [];
    public string $supplierCode = 'unknown';
    public bool $doResize = true;

    /**
     * @param Queue $queue
     */
    public function execute($queue): void
    {
        if (empty($this->cardIds)) return;

        $db = Yii::$app->db;

        // Получаем pending-картинки
        $placeholders = implode(',', array_fill(0, count($this->cardIds), '?'));
        $images = $db->createCommand(
            "SELECT id, card_id, source_url, sort_order, is_main 
             FROM {{%card_images}} 
             WHERE card_id IN ({$placeholders}) AND status = 'pending'
             ORDER BY card_id, sort_order",
            $this->cardIds
        )->queryAll();

        if (empty($images)) {
            Yii::info("DownloadImagesJob: нет pending-картинок для card_ids=" . implode(',', $this->cardIds), 'queue');
            return;
        }

        $total = count($images);
        Yii::info("DownloadImagesJob: старт cards=" . count($this->cardIds) . " images={$total}", 'queue');

        $client = new \GuzzleHttp\Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'headers' => [
                'User-Agent' => 'Agregator/2.0 ImageDownloader',
                'Accept' => 'image/*',
            ],
            'verify' => false,
        ]);

        $downloaded = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($images as $img) {
            try {
                $result = $this->downloadOne($db, $client, $img);
                if ($result === 'downloaded') $downloaded++;
                elseif ($result === 'skipped') $skipped++;
            } catch (\Throwable $e) {
                $failed++;
                $this->markFailed($db, (int)$img['id'], $e->getMessage());
                Yii::warning("DownloadImagesJob: ошибка url={$img['source_url']} err={$e->getMessage()}", 'queue');
            }

            usleep(100_000); // 100ms пауза
        }

        // Обновить images_status у карточек
        $this->updateCardStatuses($db, $this->cardIds);

        Yii::info("DownloadImagesJob: завершён downloaded={$downloaded} failed={$failed} skipped={$skipped}", 'queue');
    }

    protected function downloadOne($db, \GuzzleHttp\Client $http, array $img): string
    {
        $imageId = (int)$img['id'];
        $cardId = (int)$img['card_id'];
        $url = $img['source_url'];

        // Помечаем downloading
        $db->createCommand("UPDATE {{%card_images}} SET status = 'downloading', attempts = attempts + 1 WHERE id = :id", [':id' => $imageId])->execute();

        $ext = $this->guessExtension($url);
        $hash = md5($url);
        $dir = self::STORAGE_BASE . "/{$this->supplierCode}/{$cardId}";
        $filename = "{$hash}.{$ext}";
        $localPath = "{$dir}/{$filename}";
        $relativePath = "images/{$this->supplierCode}/{$cardId}/{$filename}";

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Уже есть?
        if (file_exists($localPath) && filesize($localPath) > 0) {
            $this->markCompleted($db, $imageId, $relativePath, $localPath);
            return 'skipped';
        }

        // Скачиваем
        $response = $http->get($url, ['sink' => $localPath]);
        if ($response->getStatusCode() !== 200 || !file_exists($localPath) || filesize($localPath) < 100) {
            @unlink($localPath);
            throw new \RuntimeException("HTTP {$response->getStatusCode()}");
        }

        $imageInfo = @getimagesize($localPath);
        $width = $imageInfo ? $imageInfo[0] : null;
        $height = $imageInfo ? $imageInfo[1] : null;
        $mime = $imageInfo ? $imageInfo['mime'] : mime_content_type($localPath);
        $fileSize = filesize($localPath);

        // Ресайзы
        $resizes = [];
        if ($this->doResize && $imageInfo && function_exists('imagecreatetruecolor')) {
            $resizes = $this->createResizes($localPath, $dir, $hash, $imageInfo);
        }

        $db->createCommand("
            UPDATE {{%card_images}} SET
                status = 'completed',
                local_path = :local,
                thumb_path = :thumb,
                medium_path = :medium,
                large_path = :large,
                webp_path = :webp,
                width = :w, height = :h,
                file_size = :size, mime_type = :mime,
                downloaded_at = NOW()
            WHERE id = :id
        ", [
            ':local' => $relativePath,
            ':thumb' => $resizes['thumb'] ?? null,
            ':medium' => $resizes['medium'] ?? null,
            ':large' => $resizes['large'] ?? null,
            ':webp' => $resizes['webp'] ?? null,
            ':w' => $width,
            ':h' => $height,
            ':size' => $fileSize,
            ':mime' => $mime,
            ':id' => $imageId,
        ])->execute();

        return 'downloaded';
    }

    protected function createResizes(string $sourcePath, string $dir, string $hash, array $imageInfo): array
    {
        $result = [];
        $srcW = $imageInfo[0];
        $srcH = $imageInfo[1];
        $type = $imageInfo[2];

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sourcePath),
            IMAGETYPE_GIF => @imagecreatefromgif($sourcePath),
            default => null,
        };
        if (!$src) return $result;

        foreach (self::SIZES as $name => [$maxW, $maxH]) {
            if ($srcW <= $maxW && $srcH <= $maxH) continue;

            $ratio = min($maxW / $srcW, $maxH / $srcH);
            $newW = (int)round($srcW * $ratio);
            $newH = (int)round($srcH * $ratio);

            $dst = imagecreatetruecolor($newW, $newH);
            if ($type === IMAGETYPE_PNG) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

            $resizedPath = "{$dir}/{$hash}_{$name}.jpg";
            imagejpeg($dst, $resizedPath, 85);
            imagedestroy($dst);

            $parentDir = basename(dirname($resizedPath));
            $grandParent = basename(dirname(dirname($resizedPath)));
            $result[$name] = "images/{$grandParent}/{$parentDir}/{$hash}_{$name}.jpg";
        }

        if (function_exists('imagewebp')) {
            $webpPath = "{$dir}/{$hash}.webp";
            imagewebp($src, $webpPath, 82);
            $parentDir = basename(dirname($webpPath));
            $grandParent = basename(dirname(dirname($webpPath)));
            $result['webp'] = "images/{$grandParent}/{$parentDir}/{$hash}.webp";
        }

        imagedestroy($src);
        return $result;
    }

    protected function markCompleted($db, int $imageId, string $relativePath, string $localPath): void
    {
        $imageInfo = @getimagesize($localPath);
        $db->createCommand("
            UPDATE {{%card_images}} SET 
                status = 'completed', local_path = :local,
                width = :w, height = :h, file_size = :size, mime_type = :mime,
                downloaded_at = NOW()
            WHERE id = :id
        ", [
            ':local' => $relativePath,
            ':w' => $imageInfo ? $imageInfo[0] : null,
            ':h' => $imageInfo ? $imageInfo[1] : null,
            ':size' => filesize($localPath),
            ':mime' => $imageInfo ? $imageInfo['mime'] : null,
            ':id' => $imageId,
        ])->execute();
    }

    protected function markFailed($db, int $imageId, string $error): void
    {
        $db->createCommand("UPDATE {{%card_images}} SET status = 'failed', error_message = :err WHERE id = :id", [
            ':err' => mb_substr($error, 0, 500),
            ':id' => $imageId,
        ])->execute();
    }

    protected function updateCardStatuses($db, array $cardIds): void
    {
        if (empty($cardIds)) return;

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $db->createCommand("
            UPDATE {{%product_cards}} SET images_status = 
                CASE
                    WHEN (SELECT COUNT(*) FROM card_images WHERE card_id = product_cards.id AND status = 'pending') = 0
                     AND (SELECT COUNT(*) FROM card_images WHERE card_id = product_cards.id AND status = 'completed') > 0
                    THEN 'completed'
                    WHEN (SELECT COUNT(*) FROM card_images WHERE card_id = product_cards.id AND status = 'completed') > 0
                    THEN 'partial'
                    ELSE 'pending'
                END,
                image_count = (SELECT COUNT(*) FROM card_images WHERE card_id = product_cards.id AND status = 'completed')
            WHERE id IN ({$placeholders})
        ", $cardIds)->execute();
    }

    protected function guessExtension(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']) ? $ext : 'jpg';
    }
}
