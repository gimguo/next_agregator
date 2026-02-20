<?php

namespace common\jobs;

use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Задание: автоматическое получение прайса от поставщика.
 *
 * Скачивает прайс через PriceFetcher (FTP/URL/Email/API),
 * затем ставит ImportPriceJob в очередь.
 *
 * Использование:
 *   Yii::$app->queue->push(new FetchPriceJob([
 *       'supplierId' => 1,
 *       'supplierCode' => 'ormatek',
 *   ]));
 */
class FetchPriceJob extends BaseObject implements JobInterface
{
    public int $supplierId = 0;
    public string $supplierCode = '';
    public bool $autoImport = true;

    public function execute($queue): void
    {
        if (empty($this->supplierCode)) {
            Yii::warning('FetchPriceJob: supplierCode пуст', 'queue');
            return;
        }

        Yii::info("FetchPriceJob: старт supplier={$this->supplierCode}", 'queue');

        $db = Yii::$app->db;

        // Получаем конфигурацию поставщика
        $supplier = $db->createCommand(
            "SELECT id, code, name, config FROM {{%suppliers}} WHERE code = :code AND is_active = true",
            [':code' => $this->supplierCode]
        )->queryOne();

        if (!$supplier) {
            Yii::warning("FetchPriceJob: поставщик '{$this->supplierCode}' не найден или неактивен", 'queue');
            return;
        }

        $config = json_decode($supplier['config'] ?? '{}', true);
        $fetchMethod = $config['fetch_method'] ?? 'file';

        if ($fetchMethod === 'file') {
            Yii::info("FetchPriceJob: метод=file, ожидаем ручную загрузку", 'queue');
            return;
        }

        /** @var \common\services\PriceFetcher $fetcher */
        $fetcher = Yii::$app->get('priceFetcher');

        try {
            $result = $fetcher->fetch($this->supplierCode, $config);

            if ($result && !empty($result['file_path'])) {
                Yii::info("FetchPriceJob: файл скачан → {$result['file_path']}", 'queue');

                // Обновляем last_import_at
                $db->createCommand()->update('{{%suppliers}}', [
                    'last_import_at' => new \yii\db\Expression('NOW()'),
                ], ['id' => $supplier['id']])->execute();

                // Ставим импорт в очередь
                if ($this->autoImport) {
                    Yii::$app->queue->push(new ImportPriceJob([
                        'supplierCode' => $this->supplierCode,
                        'filePath' => $result['file_path'],
                        'downloadImages' => true,
                    ]));
                    Yii::info("FetchPriceJob: ImportPriceJob поставлен в очередь", 'queue');
                }
            } else {
                Yii::warning("FetchPriceJob: не удалось скачать прайс для {$this->supplierCode}", 'queue');
            }
        } catch (\Throwable $e) {
            Yii::error("FetchPriceJob: ошибка {$this->supplierCode}: {$e->getMessage()}", 'queue');
        }
    }
}
