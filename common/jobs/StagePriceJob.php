<?php

namespace common\jobs;

use common\components\parsers\ParserRegistry;
use common\services\ImportStagingService;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Фаза 1: Парсинг прайс-листа → PostgreSQL UNLOGGED staging.
 *
 * Быстрый парсинг файла с batch-вставкой в staging_raw_offers.
 * Пачки по 500-1000 строк, без Redis — напрямую в PostgreSQL.
 *
 * После завершения ставит в очередь AnalyzePriceJob (фаза 2).
 *
 * Использование:
 *   Yii::$app->queue->push(new StagePriceJob([
 *       'supplierCode' => 'ormatek',
 *       'filePath' => '/app/storage/prices/ormatek/All.xml',
 *       'sessionId' => 'ormatek:20260220_123456:abc123',
 *       'supplierId' => 1,
 *   ]));
 */
class StagePriceJob extends BaseObject implements JobInterface
{
    /** @var string Код поставщика */
    public string $supplierCode;

    /** @var string Путь к файлу прайса */
    public string $filePath;

    /** @var string ID сессии импорта */
    public string $sessionId;

    /** @var int ID поставщика в БД */
    public int $supplierId;

    /** @var array Дополнительные опции парсинга */
    public array $options = [];

    /** @var bool Запускать ли AI-анализ после парсинга */
    public bool $analyzeWithAI = true;

    /** @var int Размер batch-вставки в PostgreSQL */
    public int $batchSize = 500;

    // Обратная совместимость со старым API (taskId)
    public string $taskId = '';

    public function init(): void
    {
        parent::init();
        // Поддержка старого имени поля
        if (!empty($this->taskId) && empty($this->sessionId)) {
            $this->sessionId = $this->taskId;
        }
    }

    /**
     * @param Queue $queue
     */
    public function execute($queue): void
    {
        Yii::info("StagePriceJob: старт sessionId={$this->sessionId} supplier={$this->supplierCode}", 'import');

        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');

        /** @var ParserRegistry $parserRegistry */
        $parserRegistry = Yii::$app->get('parserRegistry');

        $parser = $parserRegistry->get($this->supplierCode);
        if (!$parser) {
            $staging->setStatus($this->sessionId, 'failed');
            $staging->updateStats($this->sessionId, ['error_message' => "Парсер для '{$this->supplierCode}' не найден"]);
            Yii::error("StagePriceJob: парсер не найден для {$this->supplierCode}", 'import');
            return;
        }

        $staging->setStatus($this->sessionId, 'parsing');
        $startTime = microtime(true);
        $maxProducts = (int)($this->options['max_products'] ?? 0);

        $totalProducts = 0;
        $batchBuffer = [];

        try {
            // Потоковый парсинг → batch insert в PostgreSQL
            foreach ($parser->parse($this->filePath, $this->options) as $productDTO) {
                $batchBuffer[] = $productDTO;
                $totalProducts++;

                // Batch-вставка в PostgreSQL
                if (count($batchBuffer) >= $this->batchSize) {
                    $staging->insertBatch($this->sessionId, $this->supplierId, $batchBuffer);
                    $batchBuffer = [];

                    // Прогресс
                    if ($totalProducts % 2000 === 0) {
                        $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
                        $elapsed = round(microtime(true) - $startTime, 1);
                        $rate = $totalProducts > 0 ? round($totalProducts / $elapsed) : 0;
                        Yii::info(
                            "StagePriceJob: прогресс products={$totalProducts} rate={$rate}/s mem={$mem}MB",
                            'import'
                        );
                        $staging->updateStats($this->sessionId, [
                            'total_items'    => $totalProducts,
                            'parsed_items'   => $totalProducts,
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
                $staging->insertBatch($this->sessionId, $this->supplierId, $batchBuffer);
            }

            $duration = round(microtime(true) - $startTime, 1);
            $parserStats = $parser->getStats();
            $rate = $totalProducts > 0 ? round($totalProducts / max($duration, 0.1)) : 0;

            $staging->updateStats($this->sessionId, [
                'total_items'        => $totalProducts,
                'parsed_items'       => $totalProducts,
                'parse_duration_sec' => $duration,
                'parse_rate'         => $rate,
                'parser_stats'       => $parserStats,
            ]);
            $staging->setStatus($this->sessionId, 'parsed');

            // Подсчёт брендов/категорий через SQL
            $brandCount = count($staging->getBrands($this->sessionId));
            $categoryCount = count($staging->getCategories($this->sessionId));

            Yii::info(
                "StagePriceJob: завершён sessionId={$this->sessionId} " .
                "products={$totalProducts} brands={$brandCount} categories={$categoryCount} " .
                "rate={$rate}/s time={$duration}s",
                'import'
            );

            // Фаза 2: AI-анализ или сразу нормализация
            if ($this->analyzeWithAI && $totalProducts > 0) {
                Yii::$app->queue->push(new AnalyzePriceJob([
                    'sessionId'    => $this->sessionId,
                    'supplierCode' => $this->supplierCode,
                    'supplierId'   => $this->supplierId,
                ]));
                Yii::info("StagePriceJob: поставлен AnalyzePriceJob в очередь", 'import');
            } elseif ($totalProducts > 0) {
                Yii::$app->queue->push(new NormalizeStagedJob([
                    'sessionId'    => $this->sessionId,
                    'supplierCode' => $this->supplierCode,
                    'supplierId'   => $this->supplierId,
                ]));
            }

        } catch (\Throwable $e) {
            $staging->setStatus($this->sessionId, 'failed');
            $staging->updateStats($this->sessionId, [
                'error_message' => $e->getMessage(),
                'total_items'   => $totalProducts,
            ]);
            Yii::error("StagePriceJob: ошибка — {$e->getMessage()}", 'import');
            throw $e;
        }
    }
}
