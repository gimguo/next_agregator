<?php

namespace common\jobs;

use common\services\ImportService;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Задание: импорт прайс-листа поставщика через очередь.
 *
 * Использование:
 *   Yii::$app->queue->push(new ImportPriceJob([
 *       'supplierCode' => 'ormatek',
 *       'filePath' => '/app/storage/prices/ormatek/All.xml',
 *       'options' => ['max_products' => 100],
 *       'downloadImages' => true,
 *   ]));
 */
class ImportPriceJob extends BaseObject implements JobInterface
{
    public string $supplierCode;
    public string $filePath;
    public array $options = [];
    public bool $downloadImages = true;

    /**
     * @param Queue $queue
     */
    public function execute($queue): void
    {
        Yii::info("ImportPriceJob: старт supplier={$this->supplierCode} file={$this->filePath}", 'queue');

        $importService = new ImportService();

        $stats = $importService->run($this->supplierCode, $this->filePath, $this->options);

        Yii::info("ImportPriceJob: завершён — " . json_encode($stats, JSON_UNESCAPED_UNICODE), 'queue');

        // Если нужно скачать картинки и есть новые/обновлённые карточки
        if ($this->downloadImages && ($stats['cards_created'] > 0 || $stats['cards_updated'] > 0)) {
            $this->enqueueImageDownload($stats);
        }
    }

    protected function enqueueImageDownload(array $stats): void
    {
        // Находим карточки с pending-картинками
        $cardIds = Yii::$app->db->createCommand("
            SELECT DISTINCT card_id FROM {{%card_images}} 
            WHERE status = 'pending' 
            ORDER BY card_id 
            LIMIT 500
        ")->queryColumn();

        if (empty($cardIds)) return;

        // Группируем по 10 карточек на задание
        $chunks = array_chunk($cardIds, 10);
        foreach ($chunks as $chunk) {
            Yii::$app->queue->push(new DownloadImagesJob([
                'cardIds' => $chunk,
                'supplierCode' => $this->supplierCode,
            ]));
        }

        Yii::info("ImportPriceJob: поставлено " . count($chunks) . " заданий на скачку картинок", 'queue');
    }
}
