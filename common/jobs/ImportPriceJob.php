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

        /** @var ImportService $importService */
        $importService = Yii::$app->has('importService')
            ? Yii::$app->get('importService')
            : Yii::createObject(ImportService::class);

        $stats = $importService->run($this->supplierCode, $this->filePath, $this->options);

        Yii::info("ImportPriceJob: завершён — " . json_encode($stats, JSON_UNESCAPED_UNICODE), 'queue');

        $hasNewCards = ($stats['cards_created'] > 0 || $stats['cards_updated'] > 0);

        // Скачивание картинок
        if ($this->downloadImages && $hasNewCards) {
            $this->enqueueImageDownload($stats);
        }

        // AI-обработка: бренды + категоризация
        if ($hasNewCards) {
            $this->enqueueAIProcessing();
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

    /**
     * Поставить AI-задачи: резолв брендов, категоризация, обогащение.
     */
    protected function enqueueAIProcessing(): void
    {
        $db = Yii::$app->db;

        // Карточки без бренда (brand_id IS NULL)
        $unbrandedIds = $db->createCommand("
            SELECT id FROM {{%product_cards}}
            WHERE brand_id IS NULL AND (brand IS NOT NULL OR manufacturer IS NOT NULL)
            ORDER BY created_at DESC
            LIMIT 200
        ")->queryColumn();

        if (!empty($unbrandedIds)) {
            $chunks = array_chunk($unbrandedIds, 20);
            foreach ($chunks as $chunk) {
                Yii::$app->queue->push(new ResolveBrandsJob(['cardIds' => $chunk]));
            }
            Yii::info("ImportPriceJob: поставлено " . count($chunks) . " заданий на резолв брендов", 'queue');
        }

        // Карточки без категории
        $uncategorizedIds = $db->createCommand("
            SELECT id FROM {{%product_cards}}
            WHERE category_id IS NULL
            ORDER BY created_at DESC
            LIMIT 200
        ")->queryColumn();

        if (!empty($uncategorizedIds)) {
            $chunks = array_chunk($uncategorizedIds, 10);
            foreach ($chunks as $chunk) {
                Yii::$app->queue->push(new CategorizeCardsJob(['cardIds' => $chunk]));
            }
            Yii::info("ImportPriceJob: поставлено " . count($chunks) . " заданий на категоризацию", 'queue');
        }

        // Обогащение карточек с низким качеством
        $lowQualityIds = $db->createCommand("
            SELECT id FROM {{%product_cards}}
            WHERE quality_score < 50
            ORDER BY quality_score ASC, created_at DESC
            LIMIT 50
        ")->queryColumn();

        foreach ($lowQualityIds as $cardId) {
            Yii::$app->queue->push(new EnrichCardJob(['cardId' => (int)$cardId]));
        }

        if (!empty($lowQualityIds)) {
            Yii::info("ImportPriceJob: поставлено " . count($lowQualityIds) . " заданий на AI-обогащение", 'queue');
        }
    }
}
