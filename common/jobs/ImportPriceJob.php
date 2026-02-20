<?php

namespace common\jobs;

use common\services\ImportService;
use common\services\ImportStagingService;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Оркестратор импорта прайс-листа.
 *
 * Поддерживает два режима:
 *
 * 1. **PIPELINE** (по умолчанию):
 *    Parse → PostgreSQL UNLOGGED staging → AI recipe → Normalize → Bulk persist
 *    Масштабируемый, с cursor-based итерацией, без ограничений Redis.
 *
 * 2. **LEGACY** (прямой):
 *    Parse → ImportService → PostgreSQL (потоково, без staging)
 *    Простой, без дополнительных зависимостей.
 *
 * Использование:
 *   Yii::$app->queue->push(new ImportPriceJob([
 *       'supplierCode' => 'ormatek',
 *       'filePath' => '/app/storage/prices/ormatek/All.xml',
 *       'mode' => 'pipeline',  // или 'legacy'
 *   ]));
 */
class ImportPriceJob extends BaseObject implements JobInterface
{
    /** @var string Код поставщика */
    public string $supplierCode;

    /** @var string Путь к файлу прайса */
    public string $filePath;

    /** @var array Опции парсинга */
    public array $options = [];

    /** @var bool Скачивать ли картинки */
    public bool $downloadImages = true;

    /** @var string Режим: 'pipeline' (PostgreSQL staging) или 'legacy' (прямой) */
    public string $mode = 'pipeline';

    /** @var bool Использовать ли AI-анализ в pipeline-режиме */
    public bool $analyzeWithAI = true;

    /**
     * @param Queue $queue
     */
    public function execute($queue): void
    {
        Yii::info(
            "ImportPriceJob: старт mode={$this->mode} supplier={$this->supplierCode} file={$this->filePath}",
            'queue'
        );

        if ($this->mode === 'pipeline') {
            $this->executePipeline($queue);
        } else {
            $this->executeLegacy($queue);
        }
    }

    // ═══════════════════════════════════════════
    // PIPELINE MODE (PostgreSQL UNLOGGED staging)
    // ═══════════════════════════════════════════

    protected function executePipeline(Queue $queue): void
    {
        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');

        // Получаем supplier_id
        $supplierId = Yii::$app->db->createCommand(
            "SELECT id FROM {{%suppliers}} WHERE code = :code",
            [':code' => $this->supplierCode]
        )->queryScalar();

        if (!$supplierId) {
            Yii::$app->db->createCommand()->insert('{{%suppliers}}', [
                'name'      => ucfirst($this->supplierCode),
                'code'      => $this->supplierCode,
                'is_active' => true,
                'format'    => 'xml',
            ])->execute();
            $supplierId = (int)Yii::$app->db->getLastInsertID('suppliers_id_seq');
        }

        // Создаём сессию
        $sessionId = $staging->createSession(
            $this->supplierCode,
            (int)$supplierId,
            $this->filePath,
            $this->options
        );

        Yii::info("ImportPriceJob: pipeline sessionId={$sessionId}", 'queue');

        // Ставим первую фазу в очередь
        Yii::$app->queue->push(new StagePriceJob([
            'supplierCode' => $this->supplierCode,
            'filePath'     => $this->filePath,
            'sessionId'    => $sessionId,
            'supplierId'   => (int)$supplierId,
            'options'      => $this->options,
            'analyzeWithAI' => $this->analyzeWithAI,
        ]));

        Yii::info("ImportPriceJob: StagePriceJob поставлен в очередь (sessionId={$sessionId})", 'queue');
    }

    // ═══════════════════════════════════════════
    // LEGACY MODE (прямой ImportService)
    // ═══════════════════════════════════════════

    protected function executeLegacy(Queue $queue): void
    {
        /** @var ImportService $importService */
        $importService = Yii::$app->has('importService')
            ? Yii::$app->get('importService')
            : Yii::createObject(ImportService::class);

        $stats = $importService->run($this->supplierCode, $this->filePath, $this->options);

        Yii::info("ImportPriceJob[legacy]: завершён — " . json_encode($stats, JSON_UNESCAPED_UNICODE), 'queue');

        $hasNewCards = ($stats['cards_created'] > 0 || $stats['cards_updated'] > 0);

        if ($this->downloadImages && $hasNewCards) {
            $this->enqueueImageDownload($stats);
        }

        if ($hasNewCards) {
            $this->enqueueAIProcessing();
        }
    }

    protected function enqueueImageDownload(array $stats): void
    {
        $cardIds = Yii::$app->db->createCommand("
            SELECT DISTINCT card_id FROM {{%card_images}} 
            WHERE status = 'pending' ORDER BY card_id LIMIT 500
        ")->queryColumn();

        if (empty($cardIds)) return;

        $chunks = array_chunk($cardIds, 10);
        foreach ($chunks as $chunk) {
            Yii::$app->queue->push(new DownloadImagesJob([
                'cardIds'      => $chunk,
                'supplierCode' => $this->supplierCode,
            ]));
        }
    }

    protected function enqueueAIProcessing(): void
    {
        $db = Yii::$app->db;

        $unbrandedIds = $db->createCommand("
            SELECT id FROM {{%product_cards}}
            WHERE brand_id IS NULL AND (brand IS NOT NULL OR manufacturer IS NOT NULL)
            ORDER BY created_at DESC LIMIT 200
        ")->queryColumn();

        if (!empty($unbrandedIds)) {
            foreach (array_chunk($unbrandedIds, 20) as $chunk) {
                Yii::$app->queue->push(new ResolveBrandsJob(['cardIds' => $chunk]));
            }
        }

        $uncategorizedIds = $db->createCommand("
            SELECT id FROM {{%product_cards}}
            WHERE category_id IS NULL ORDER BY created_at DESC LIMIT 200
        ")->queryColumn();

        if (!empty($uncategorizedIds)) {
            foreach (array_chunk($uncategorizedIds, 10) as $chunk) {
                Yii::$app->queue->push(new CategorizeCardsJob(['cardIds' => $chunk]));
            }
        }

        $lowQualityIds = $db->createCommand("
            SELECT id FROM {{%product_cards}}
            WHERE quality_score < 50 ORDER BY quality_score ASC, created_at DESC LIMIT 50
        ")->queryColumn();

        foreach ($lowQualityIds as $cardId) {
            Yii::$app->queue->push(new EnrichCardJob(['cardId' => (int)$cardId]));
        }
    }
}
