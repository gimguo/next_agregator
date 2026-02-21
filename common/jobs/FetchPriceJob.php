<?php

namespace common\jobs;

use common\models\SupplierFetchConfig;
use common\services\fetcher\FetcherFactory;
use common\services\fetcher\FetchResult;
use common\services\ImportStagingService;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Задание: автоматическое получение прайса от поставщика.
 *
 * Полный цикл:
 *   1. Загружает SupplierFetchConfig
 *   2. Через FetcherFactory скачивает файл (URL/FTP/API)
 *   3. Обновляет статус и статистику в конфиге
 *   4. Автоматически ставит ImportPriceJob (→ StagePriceJob → Pipeline)
 *
 * Замыкает цикл:
 *   Scheduler → FetchPriceJob → ImportPriceJob → StagePriceJob → AnalyzePriceJob
 *   → NormalizeStagedJob → PersistStagedJob → Golden Record → Outbox → Витрина
 *
 * Использование:
 *   Yii::$app->queue->push(new FetchPriceJob([
 *       'fetchConfigId' => 1,
 *       'supplierCode' => 'ormatek',  // fallback если fetchConfigId не указан
 *   ]));
 */
class FetchPriceJob extends BaseObject implements JobInterface
{
    /** @var int ID конфигурации из supplier_fetch_configs */
    public int $fetchConfigId = 0;

    /** @var string Код поставщика (fallback) */
    public string $supplierCode = '';

    /** @var int ID поставщика (legacy) */
    public int $supplierId = 0;

    /** @var bool Автоматически поставить ImportPriceJob в очередь */
    public bool $autoImport = true;

    /** @var bool Использовать AI-анализ в pipeline */
    public bool $analyzeWithAI = true;

    /**
     * @param Queue $queue
     */
    public function execute($queue): void
    {
        // ═══ Шаг 1: Загрузить конфигурацию ═══
        $config = $this->resolveConfig();
        if (!$config) {
            Yii::warning("FetchPriceJob: конфигурация не найдена (configId={$this->fetchConfigId}, supplier={$this->supplierCode})", 'fetcher');
            return;
        }

        $supplier = $config->supplier;
        if (!$supplier || !$supplier->is_active) {
            Yii::warning("FetchPriceJob: поставщик неактивен или не найден (configId={$config->id})", 'fetcher');
            return;
        }

        if ($config->fetch_method === 'manual') {
            Yii::info("FetchPriceJob: метод=manual для {$supplier->code}, пропуск", 'fetcher');
            return;
        }

        $supplierCode = $supplier->code;
        Yii::info("FetchPriceJob: старт [{$supplierCode}] метод={$config->fetch_method}", 'fetcher');

        // Отметим что начали
        $config->last_fetch_status = 'running';
        $config->save(false);

        // ═══ Шаг 2: Скачать через FetcherFactory ═══
        /** @var FetcherFactory $factory */
        $factory = Yii::$app->get('fetcherFactory');

        $result = $factory->fetch($config);

        // ═══ Шаг 3: Обновить статус и статистику ═══
        $config->recordFetchResult(
            $result->success,
            $result->error,
            $result->durationSec ? (int)$result->durationSec : null
        );

        // Рассчитать следующий запуск
        $config->calculateNextRun();
        $config->save(false);

        if (!$result->success) {
            Yii::error("FetchPriceJob: ошибка [{$supplierCode}] — {$result->error}", 'fetcher');
            return;
        }

        Yii::info(
            "FetchPriceJob: скачан [{$supplierCode}] → {$result->filePath} ({$result->getHumanSize()}, {$result->durationSec}s)",
            'fetcher'
        );

        // ═══ Шаг 4: Запустить Import Pipeline ═══
        if ($this->autoImport && $result->filePath) {
            $this->enqueueImport($config, $result);
        }
    }

    /**
     * Поставить ImportPriceJob в очередь для скачанного файла.
     */
    private function enqueueImport(SupplierFetchConfig $config, FetchResult $result): void
    {
        $supplierCode = $config->supplier->code;

        Yii::$app->queue->push(new ImportPriceJob([
            'supplierCode'  => $supplierCode,
            'filePath'      => $result->filePath,
            'mode'          => 'pipeline',
            'analyzeWithAI' => $this->analyzeWithAI,
            'downloadImages' => true,
        ]));

        Yii::info("FetchPriceJob: ImportPriceJob поставлен в очередь [{$supplierCode}] file={$result->filePath}", 'fetcher');
    }

    /**
     * Найти конфигурацию: по fetchConfigId или по supplierCode.
     */
    private function resolveConfig(): ?SupplierFetchConfig
    {
        // Приоритет: fetchConfigId
        if ($this->fetchConfigId > 0) {
            return SupplierFetchConfig::find()
                ->with('supplier')
                ->where(['id' => $this->fetchConfigId])
                ->one();
        }

        // Fallback: по supplierCode
        if (!empty($this->supplierCode)) {
            $supplierId = Yii::$app->db->createCommand(
                "SELECT id FROM {{%suppliers}} WHERE code = :code",
                [':code' => $this->supplierCode]
            )->queryScalar();

            if ($supplierId) {
                return SupplierFetchConfig::find()
                    ->with('supplier')
                    ->where(['supplier_id' => $supplierId])
                    ->one();
            }
        }

        // Legacy fallback: по supplierId
        if ($this->supplierId > 0) {
            return SupplierFetchConfig::find()
                ->with('supplier')
                ->where(['supplier_id' => $this->supplierId])
                ->one();
        }

        return null;
    }
}
