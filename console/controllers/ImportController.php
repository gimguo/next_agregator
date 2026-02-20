<?php

namespace console\controllers;

use common\jobs\DownloadImagesJob;
use common\jobs\ImportPriceJob;
use common\services\ImportService;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use Yii;

/**
 * Управление импортом прайс-листов.
 *
 * Примеры:
 *   yii import/run ormatek /app/storage/prices/ormatek/All.xml
 *   yii import/run ormatek /app/storage/prices/ormatek/All.xml --max=100
 *   yii import/queue ormatek /app/storage/prices/ormatek/All.xml
 *   yii import/images
 *   yii import/stats
 */
class ImportController extends Controller
{
    /** @var int Лимит товаров (0 = без лимита) */
    public int $max = 0;

    /** @var bool Пропустить скачивание картинок */
    public bool $skipImages = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['max', 'skipImages']);
    }

    /**
     * Синхронный импорт (блокирующий, для тестов).
     *
     * @param string $supplier Код поставщика: ormatek
     * @param string $file Путь к файлу прайса
     */
    public function actionRun(string $supplier, string $file): int
    {
        if (!file_exists($file)) {
            $this->stderr("Файл не найден: {$file}\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->stdout("Импорт: {$supplier} → {$file}\n", Console::FG_CYAN);

        $options = [];
        if ($this->max > 0) {
            $options['max_products'] = $this->max;
        }
        if ($this->skipImages) {
            $options['skip_images'] = true;
        }

        $service = new ImportService();
        $stats = $service->run($supplier, $file, $options, function ($count, $stats) {
            $this->stdout("  Обработано: {$count} товаров...\r");
        });

        $this->stdout("\n");
        $this->printStats($stats);

        return ExitCode::OK;
    }

    /**
     * Поставить импорт в очередь (асинхронный).
     *
     * @param string $supplier Код поставщика
     * @param string $file Путь к файлу прайса
     */
    public function actionQueue(string $supplier, string $file): int
    {
        if (!file_exists($file)) {
            $this->stderr("Файл не найден: {$file}\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $options = [];
        if ($this->max > 0) {
            $options['max_products'] = $this->max;
        }

        $jobId = Yii::$app->queue->push(new ImportPriceJob([
            'supplierCode' => $supplier,
            'filePath' => $file,
            'options' => $options,
            'downloadImages' => !$this->skipImages,
        ]));

        $this->stdout("Задание поставлено в очередь: ID={$jobId}\n", Console::FG_GREEN);
        $this->stdout("Отслеживание: yii queue/info {$jobId}\n");

        return ExitCode::OK;
    }

    /**
     * Поставить скачивание картинок в очередь.
     *
     * @param string $supplier Код поставщика (по умолчанию ormatek)
     */
    public function actionImages(string $supplier = 'ormatek'): int
    {
        $cardIds = Yii::$app->db->createCommand("
            SELECT DISTINCT card_id FROM {{%card_images}} 
            WHERE status = 'pending' 
            ORDER BY card_id 
            LIMIT 1000
        ")->queryColumn();

        if (empty($cardIds)) {
            $this->stdout("Нет pending-картинок\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $chunks = array_chunk($cardIds, 10);
        $total = 0;
        foreach ($chunks as $chunk) {
            Yii::$app->queue->push(new DownloadImagesJob([
                'cardIds' => $chunk,
                'supplierCode' => $supplier,
            ]));
            $total++;
        }

        $this->stdout("Поставлено {$total} заданий на скачку (" . count($cardIds) . " карточек)\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Статистика импорта.
     */
    public function actionStats(): int
    {
        $db = Yii::$app->db;

        $cards = $db->createCommand("SELECT COUNT(*) FROM {{%product_cards}}")->queryScalar();
        $offers = $db->createCommand("SELECT COUNT(*) FROM {{%supplier_offers}}")->queryScalar();
        $images = $db->createCommand("SELECT status, COUNT(*) as cnt FROM {{%card_images}} GROUP BY status ORDER BY status")->queryAll();
        $suppliers = $db->createCommand("SELECT code, name, last_import_at FROM {{%suppliers}} WHERE is_active = true")->queryAll();

        $this->stdout("\n=== Статистика агрегатора ===\n\n", Console::BOLD);

        $this->stdout("Карточки:         {$cards}\n");
        $this->stdout("Предложения:      {$offers}\n\n");

        $this->stdout("Поставщики:\n", Console::BOLD);
        foreach ($suppliers as $s) {
            $lastImport = $s['last_import_at'] ?? 'никогда';
            $this->stdout("  {$s['code']} ({$s['name']}) — последний импорт: {$lastImport}\n");
        }

        $this->stdout("\nКартинки:\n", Console::BOLD);
        foreach ($images as $row) {
            $this->stdout("  {$row['status']}: {$row['cnt']}\n");
        }

        $this->stdout("\n");
        return ExitCode::OK;
    }

    protected function printStats(array $stats): void
    {
        $this->stdout("=== Результат импорта ===\n", Console::BOLD);
        $this->stdout("Статус:           {$stats['status']}\n");
        $this->stdout("Всего товаров:    " . ($stats['products_total'] ?? 0) . "\n");
        $this->stdout("Карточки создано: {$stats['cards_created']}\n");
        $this->stdout("Карточки обновлено: {$stats['cards_updated']}\n");
        $this->stdout("Офферы создано:   {$stats['offers_created']}\n");
        $this->stdout("Офферы обновлено: {$stats['offers_updated']}\n");
        $this->stdout("Вариантов всего:  {$stats['variants_total']}\n");
        $this->stdout("Ошибок:           {$stats['errors']}\n");
        $this->stdout("Время:            {$stats['duration_seconds']}с\n");
    }
}
