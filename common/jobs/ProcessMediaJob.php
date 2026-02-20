<?php

namespace common\jobs;

use common\services\MediaProcessingService;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Фоновая задача: скачать, обработать и загрузить в S3 пачку media_assets.
 *
 * Работает через MediaProcessingService:
 *   1. Атомарно забирает pending-ассеты (FOR UPDATE SKIP LOCKED)
 *   2. Скачивает → MD5 → дедупликация → WebP → S3 upload
 *   3. Обновляет статус: processed / deduplicated / error
 *
 * Self-requeue: если есть ещё pending — ставит себя заново в очередь.
 *
 * Запуск:
 *   - Из очереди: Yii::$app->queue->push(new ProcessMediaJob(['batchSize' => 50]))
 *   - Из консоли: php yii media/process-batch
 *   - Из крона (SchedulerController)
 */
class ProcessMediaJob extends BaseObject implements JobInterface
{
    /** @var int Сколько ассетов обработать за один запуск */
    public int $batchSize = 50;

    /** @var bool Ставить себя заново в очередь, если есть ещё pending */
    public bool $autoRequeue = true;

    /**
     * @param Queue $queue
     */
    public function execute($queue): void
    {
        /** @var MediaProcessingService $media */
        $media = Yii::$app->get('mediaService');

        $startTime = microtime(true);

        Yii::info(
            "ProcessMediaJob: start batch_size={$this->batchSize}",
            'media'
        );

        // Обрабатываем пачку (FOR UPDATE SKIP LOCKED внутри)
        $result = $media->processPendingBatch($this->batchSize);

        $duration = round(microtime(true) - $startTime, 2);

        Yii::info(
            "ProcessMediaJob: done in {$duration}s — " .
            "processed={$result['processed']}, deduplicated={$result['deduplicated']}, " .
            "errors={$result['errors']}, total={$result['total']}",
            'media'
        );

        // Self-requeue: если полностью заполнили батч — вероятно, есть ещё
        if ($this->autoRequeue && $result['total'] >= $this->batchSize) {
            $remaining = Yii::$app->db->createCommand(
                "SELECT COUNT(*) FROM {{%media_assets}} WHERE status = 'pending'"
            )->queryScalar();

            if ($remaining > 0) {
                Yii::info(
                    "ProcessMediaJob: {$remaining} pending remaining, requeuing...",
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
