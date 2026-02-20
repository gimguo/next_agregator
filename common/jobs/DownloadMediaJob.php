<?php

namespace common\jobs;

use common\services\MediaProcessingService;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Фоновая задача: скачать и обработать пачку media_assets.
 *
 * Работает через MediaProcessingService:
 *   1. Забирает pending-ассеты (приоритет: is_primary → sort_order)
 *   2. Скачивает, дедуплицирует, конвертирует в WebP
 *   3. Сохраняет в uploads/media/ab/cd/hash.webp
 *   4. Обновляет статус: processed / deduplicated / error
 *
 * Может запускаться:
 *   - Из очереди: Yii::$app->queue->push(new DownloadMediaJob(['batchSize' => 50]))
 *   - Из консоли: php yii media/process-batch
 *   - Из крона (SchedulerController)
 *
 * Если в очереди есть ещё pending-записи — джоба ставит себя заново
 * (self-requeue), обеспечивая непрерывную обработку.
 */
class DownloadMediaJob extends BaseObject implements JobInterface
{
    /** @var int Сколько ассетов обработать за один запуск */
    public int $batchSize = 50;

    /** @var bool Ставить себя заново в очередь, если есть ещё pending */
    public bool $autoRequeue = true;

    /** @var string[]|null Обрабатывать только определённые entity_type */
    public ?array $entityTypes = null;

    /** @var int|null Обрабатывать только определённую сущность */
    public ?int $entityId = null;
    public ?string $entityType = null;

    /**
     * @param Queue $queue
     */
    public function execute($queue): void
    {
        /** @var MediaProcessingService $media */
        $media = Yii::$app->get('mediaService');

        $startTime = microtime(true);

        Yii::info(
            "DownloadMediaJob: start batch_size={$this->batchSize}",
            'media'
        );

        // Обрабатываем пачку
        $result = $media->processPendingBatch($this->batchSize);

        $duration = round(microtime(true) - $startTime, 2);

        Yii::info(
            "DownloadMediaJob: done in {$duration}s — " .
            "processed={$result['processed']}, deduplicated={$result['deduplicated']}, " .
            "errors={$result['errors']}, total={$result['total']}",
            'media'
        );

        // Self-requeue: если есть ещё pending — ставим себя заново
        if ($this->autoRequeue && $result['total'] >= $this->batchSize) {
            $remaining = Yii::$app->db->createCommand(
                "SELECT COUNT(*) FROM {{%media_assets}} WHERE status = 'pending'"
            )->queryScalar();

            if ($remaining > 0) {
                Yii::info(
                    "DownloadMediaJob: {$remaining} pending remaining, requeuing...",
                    'media'
                );

                $queue->push(new self([
                    'batchSize'    => $this->batchSize,
                    'autoRequeue'  => true,
                ]));
            }
        }
    }
}
