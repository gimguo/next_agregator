<?php

namespace console\controllers;

use common\jobs\ProcessMediaJob;
use common\services\MediaProcessingService;
use common\components\S3UrlGenerator;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use Yii;

/**
 * Управление медиа-ассетами (DAM / MinIO S3).
 *
 * Команды:
 *   php yii media/status                — Статистика media_assets + S3
 *   php yii media/process-batch         — Обработать пачку (синхронно, WebP → S3)
 *   php yii media/queue                 — Поставить ProcessMediaJob в очередь
 *   php yii media/retry-errors          — Повторить ошибки
 *   php yii media/register-existing     — Зарегистрировать изображения из supplier_offers
 *   php yii media/cleanup               — Удалить старые error-записи
 */
class MediaController extends Controller
{
    /** @var int Размер пачки для обработки */
    public int $batch = 50;

    /** @var int Количество пачек (для process-batch) */
    public int $rounds = 1;

    /** @var MediaProcessingService */
    private MediaProcessingService $media;

    public function init(): void
    {
        parent::init();
        $this->media = Yii::$app->get('mediaService');
    }

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['batch', 'rounds']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['b' => 'batch', 'r' => 'rounds']);
    }

    /**
     * Статистика media_assets + S3 info.
     */
    public function actionStatus(): int
    {
        $this->stdout("\n╔══════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   MEDIA ASSETS STATUS (S3/MinIO DAM)     ║\n", Console::FG_CYAN);
        $this->stdout("╚══════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $stats = $this->media->getGlobalStats();

        $statuses = ['pending', 'downloading', 'processed', 'deduplicated', 'error'];
        foreach ($statuses as $status) {
            $count = $stats[$status]['count'] ?? 0;
            $size = $stats[$status]['size'] ?? 0;
            $color = match ($status) {
                'pending'       => $count > 0 ? Console::FG_YELLOW : Console::FG_GREEN,
                'processed'     => Console::FG_GREEN,
                'deduplicated'  => Console::FG_CYAN,
                'error'         => $count > 0 ? Console::FG_RED : Console::FG_GREEN,
                default         => null,
            };
            $sizeStr = $size > 0 ? ' (' . $this->formatBytes($size) . ')' : '';
            $this->stdout("  " . str_pad($status . ':', 16) . str_pad((string)$count, 8) . $sizeStr . "\n", $color);
        }

        $total = $stats['_total'] ?? ['count' => 0, 'size' => 0];
        $this->stdout("\n  Total: {$total['count']} (" . $this->formatBytes($total['size']) . ")\n");

        // Дедупликация: экономия
        $dedupCount = $stats['deduplicated']['count'] ?? 0;
        if ($dedupCount > 0) {
            $this->stdout("\n  💾 Дедупликация сэкономила: {$dedupCount} загрузок\n", Console::FG_CYAN);
        }

        // По entity_type
        $byType = Yii::$app->db->createCommand("
            SELECT entity_type, COUNT(*) as cnt,
                   SUM(CASE WHEN status IN ('processed','deduplicated') THEN 1 ELSE 0 END) as ready
            FROM {{%media_assets}}
            GROUP BY entity_type
        ")->queryAll();

        if (!empty($byType)) {
            $this->stdout("\n  По типу сущности:\n", Console::FG_CYAN);
            foreach ($byType as $row) {
                $this->stdout("    {$row['entity_type']}: {$row['cnt']} (готово: {$row['ready']})\n");
            }
        }

        // S3 конфигурация
        $s3Params = Yii::$app->params['s3'] ?? [];
        $this->stdout("\n  S3 Config:\n", Console::FG_CYAN);
        $this->stdout("    Endpoint:  " . ($s3Params['endpoint'] ?? '?') . "\n");
        $this->stdout("    Bucket:    " . ($s3Params['bucket'] ?? '?') . "\n");
        $this->stdout("    PublicURL: " . ($s3Params['publicUrl'] ?? '?') . "\n");

        $this->stdout("\n");
        return ExitCode::OK;
    }

    /**
     * Обработать пачку pending-ассетов (синхронно, WebP → S3).
     */
    public function actionProcessBatch(): int
    {
        $this->stdout("\n  Обработка медиа → S3 (batch={$this->batch}, rounds={$this->rounds})...\n\n", Console::FG_CYAN);

        $totalProcessed = 0;
        $totalDedup = 0;
        $totalErrors = 0;

        for ($round = 1; $round <= $this->rounds; $round++) {
            $result = $this->media->processPendingBatch($this->batch);

            if ($result['total'] === 0) {
                $this->stdout("  Round {$round}: нет pending — останавливаемся.\n", Console::FG_GREEN);
                break;
            }

            $totalProcessed += $result['processed'];
            $totalDedup += $result['deduplicated'];
            $totalErrors += $result['errors'];

            $this->stdout(
                "  Round {$round}: processed={$result['processed']}, " .
                "dedup={$result['deduplicated']}, errors={$result['errors']}\n",
                $result['errors'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN
            );
        }

        $this->stdout("\n  Итого: processed={$totalProcessed}, dedup={$totalDedup}, errors={$totalErrors}\n\n",
            Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Поставить ProcessMediaJob в очередь.
     */
    public function actionQueue(): int
    {
        $pending = Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {{%media_assets}} WHERE status = 'pending'"
        )->queryScalar();

        if ($pending == 0) {
            $this->stdout("  ✓ Нет pending-ассетов.\n\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        Yii::$app->queue->push(new ProcessMediaJob([
            'batchSize'   => $this->batch,
            'autoRequeue' => true,
        ]));

        $this->stdout("  ✓ ProcessMediaJob в очереди (batch={$this->batch}, pending={$pending}).\n\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Повторить error-ассеты.
     */
    public function actionRetryErrors(): int
    {
        $retried = $this->media->retryErrors();
        $this->stdout("  ✓ Вернули {$retried} ассетов в pending.\n\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Зарегистрировать изображения из существующих supplier_offers.
     *
     * Проходит по supplier_offers, извлекает images_json и
     * регистрирует в media_assets со статусом pending.
     */
    public function actionRegisterExisting(): int
    {
        $this->stdout("\n  Регистрация изображений из supplier_offers...\n\n", Console::FG_CYAN);

        $db = Yii::$app->db;
        $lastId = 0;
        $totalRegistered = 0;
        $totalSkipped = 0;
        $batchNum = 0;

        while (true) {
            $offers = $db->createCommand("
                SELECT so.id, so.model_id, so.variant_id, so.images_json
                FROM {{%supplier_offers}} so
                WHERE so.id > :last AND so.is_active = true
                  AND so.images_json IS NOT NULL AND so.images_json != '[]'::jsonb
                ORDER BY so.id
                LIMIT 500
            ", [':last' => $lastId])->queryAll();

            if (empty($offers)) break;

            $batchNum++;
            $batchRegistered = 0;

            foreach ($offers as $offer) {
                $lastId = (int)$offer['id'];
                $images = is_string($offer['images_json'])
                    ? json_decode($offer['images_json'], true) ?: []
                    : ($offer['images_json'] ?? []);

                if (empty($images)) continue;

                // Привязываем к model (не к offer — так меньше дублей)
                $entityType = 'model';
                $entityId = (int)$offer['model_id'];
                if (!$entityId) continue;

                $registered = $this->media->registerImages($entityType, $entityId, $images);
                $totalRegistered += $registered;
                $totalSkipped += count($images) - $registered;
                $batchRegistered += $registered;
            }

            $this->stdout("  Batch #{$batchNum}: {$batchRegistered} зарегистрировано, last_id={$lastId}\n");
        }

        $this->stdout("\n  Итого: {$totalRegistered} зарегистрировано, {$totalSkipped} пропущено (дубли URL).\n\n",
            Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Удалить error-записи старше N дней.
     */
    public function actionCleanup(): int
    {
        $days = 30;
        $deleted = Yii::$app->db->createCommand("
            DELETE FROM {{%media_assets}}
            WHERE status = 'error' AND attempts >= 3 AND created_at < NOW() - INTERVAL '{$days} days'
        ")->execute();

        $this->stdout("  ✓ Удалено {$deleted} error-записей старше {$days} дней.\n\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1073741824, 2) . ' GB';
    }
}
