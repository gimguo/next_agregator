<?php

namespace common\jobs;

use common\components\parsers\ParserRegistry;
use common\services\ImportStagingService;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Фаза 1: Парсинг прайс-листа → Redis staging.
 *
 * Быстрый парсинг файла без обращения к PostgreSQL.
 * Все товары складываются в Redis Hash для дальнейшей обработки.
 *
 * После завершения ставит в очередь AnalyzePriceJob (фаза 2).
 *
 * Использование:
 *   Yii::$app->queue->push(new StagePriceJob([
 *       'supplierCode' => 'ormatek',
 *       'filePath' => '/app/storage/prices/ormatek/All.xml',
 *       'taskId' => 'ormatek:20260220_123456:abc123', // из ImportStagingService
 *   ]));
 */
class StagePriceJob extends BaseObject implements JobInterface
{
    /** @var string Код поставщика */
    public string $supplierCode;

    /** @var string Путь к файлу прайса */
    public string $filePath;

    /** @var string ID задачи staging */
    public string $taskId;

    /** @var array Дополнительные опции парсинга */
    public array $options = [];

    /** @var bool Запускать ли AI-анализ после парсинга */
    public bool $analyzeWithAI = true;

    /**
     * @param Queue $queue
     */
    public function execute($queue): void
    {
        Yii::info("StagePriceJob: старт taskId={$this->taskId} supplier={$this->supplierCode}", 'import');

        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');

        /** @var ParserRegistry $parserRegistry */
        $parserRegistry = Yii::$app->get('parserRegistry');

        $parser = $parserRegistry->get($this->supplierCode);
        if (!$parser) {
            $staging->setStatus($this->taskId, 'failed');
            $staging->updateMeta($this->taskId, ['error' => "Парсер для '{$this->supplierCode}' не найден"]);
            Yii::error("StagePriceJob: парсер не найден для {$this->supplierCode}", 'import');
            return;
        }

        $staging->setStatus($this->taskId, 'parsing');
        $startTime = microtime(true);
        $maxProducts = (int)($this->options['max_products'] ?? 0);

        $totalProducts = 0;
        $batchBuffer = [];
        $batchSize = 200; // Размер пакета для пакетного HSET
        $errors = 0;

        try {
            // Потоковый парсинг → пакетная запись в Redis
            foreach ($parser->parse($this->filePath, $this->options) as $productDTO) {
                $batchBuffer[] = $productDTO;
                $totalProducts++;

                // Пакетная запись в Redis
                if (count($batchBuffer) >= $batchSize) {
                    $staging->stageBatch($this->taskId, $batchBuffer);
                    $batchBuffer = [];

                    // Прогресс
                    if ($totalProducts % 1000 === 0) {
                        $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
                        $elapsed = round(microtime(true) - $startTime, 1);
                        Yii::info("StagePriceJob: прогресс products={$totalProducts} mem={$mem}MB time={$elapsed}s", 'import');
                        $staging->updateMeta($this->taskId, [
                            'total_items' => $totalProducts,
                            'parse_progress' => $totalProducts,
                        ]);
                    }
                }

                // Лимит
                if ($maxProducts > 0 && $totalProducts >= $maxProducts) {
                    Yii::info("StagePriceJob: лимит {$maxProducts} товаров", 'import');
                    break;
                }
            }

            // Остаток
            if (!empty($batchBuffer)) {
                $staging->stageBatch($this->taskId, $batchBuffer);
            }

            $duration = round(microtime(true) - $startTime, 1);
            $parserStats = $parser->getStats();

            $staging->updateMeta($this->taskId, [
                'status' => 'parsed',
                'total_items' => $totalProducts,
                'parsed_at' => date('Y-m-d H:i:s'),
                'parse_duration_sec' => $duration,
                'parser_stats' => $parserStats,
                'errors' => $parserStats['errors'] ?? 0,
            ]);

            $brandCount = count($staging->getBrands($this->taskId));
            $categoryCount = count($staging->getCategories($this->taskId));

            Yii::info(
                "StagePriceJob: завершён taskId={$this->taskId} " .
                "products={$totalProducts} brands={$brandCount} categories={$categoryCount} " .
                "time={$duration}s",
                'import'
            );

            // Фаза 2: AI-анализ или сразу нормализация
            if ($this->analyzeWithAI && $totalProducts > 0) {
                Yii::$app->queue->push(new AnalyzePriceJob([
                    'taskId' => $this->taskId,
                    'supplierCode' => $this->supplierCode,
                ]));
                Yii::info("StagePriceJob: поставлен AnalyzePriceJob в очередь", 'import');
            } elseif ($totalProducts > 0) {
                // Пропускаем AI, сразу нормализация + persist
                Yii::$app->queue->push(new NormalizeStagedJob([
                    'taskId' => $this->taskId,
                    'supplierCode' => $this->supplierCode,
                ]));
            }

        } catch (\Throwable $e) {
            $staging->updateMeta($this->taskId, [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'total_items' => $totalProducts,
            ]);
            Yii::error("StagePriceJob: ошибка — {$e->getMessage()}", 'import');
            throw $e;
        }
    }
}
