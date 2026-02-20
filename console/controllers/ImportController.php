<?php

namespace console\controllers;

use common\jobs\DownloadImagesJob;
use common\jobs\ImportPriceJob;
use common\jobs\StagePriceJob;
use common\services\ImportService;
use common\services\ImportStagingService;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use Yii;

/**
 * Управление импортом прайс-листов.
 *
 * Примеры:
 *   yii import/run ormatek /app/storage/prices/ormatek/All.xml         -- legacy (прямой)
 *   yii import/pipeline ormatek /app/storage/prices/ormatek/All.xml    -- pipeline (Redis staging)
 *   yii import/queue ormatek /app/storage/prices/ormatek/All.xml       -- pipeline в очередь
 *   yii import/staging-status                                           -- статус staging-задач
 *   yii import/images
 *   yii import/stats
 */
class ImportController extends Controller
{
    /** @var int Лимит товаров (0 = без лимита) */
    public int $max = 0;

    /** @var bool Пропустить скачивание картинок */
    public bool $skipImages = false;

    /** @var bool Использовать AI-анализ в pipeline */
    public bool $ai = true;

    /** @var string Режим: 'pipeline' или 'legacy' */
    public string $mode = 'pipeline';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['max', 'skipImages', 'ai', 'mode']);
    }

    /**
     * Синхронный импорт LEGACY (прямой SQL, без Redis).
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

        $this->stdout("Импорт (legacy): {$supplier} → {$file}\n", Console::FG_CYAN);

        $options = [];
        if ($this->max > 0) $options['max_products'] = $this->max;
        if ($this->skipImages) $options['skip_images'] = true;

        /** @var ImportService $service */
        $service = Yii::$app->get('importService');
        $stats = $service->run($supplier, $file, $options, function ($count, $stats) {
            $this->stdout("  Обработано: {$count} товаров...\r");
        });

        $this->stdout("\n");
        $this->printStats($stats);

        return ExitCode::OK;
    }

    /**
     * Pipeline-импорт: Parse → Redis → AI → Normalize → Persist.
     *
     * Синхронный (все фазы в одном процессе, для тестирования).
     *
     * @param string $supplier Код поставщика
     * @param string $file Путь к файлу прайса
     */
    public function actionPipeline(string $supplier, string $file): int
    {
        if (!file_exists($file)) {
            $this->stderr("Файл не найден: {$file}\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->stdout("\n", Console::BOLD);
        $this->stdout("╔═══════════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("║   PIPELINE IMPORT: {$supplier}                \n", Console::FG_CYAN);
        $this->stdout("╚═══════════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $options = [];
        if ($this->max > 0) $options['max_products'] = $this->max;
        if ($this->skipImages) $options['skip_images'] = true;

        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');

        // ═══ ФАЗА 1: ПАРСИНГ → REDIS ═══
        $this->stdout("═══ Фаза 1: Парсинг → Redis ═══\n", Console::FG_YELLOW);
        $taskId = $staging->createTask($supplier, $file, $options);
        $this->stdout("  Task ID: {$taskId}\n");

        $parserRegistry = Yii::$app->get('parserRegistry');
        $parser = $parserRegistry->get($supplier);
        if (!$parser) {
            $this->stderr("  Парсер не найден для '{$supplier}'\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $staging->setStatus($taskId, 'parsing');
        $startPhase = microtime(true);
        $totalProducts = 0;
        $batchBuffer = [];

        foreach ($parser->parse($file, $options) as $productDTO) {
            $batchBuffer[] = $productDTO;
            $totalProducts++;

            if (count($batchBuffer) >= 200) {
                $staging->stageBatch($taskId, $batchBuffer);
                $batchBuffer = [];
                $this->stdout("  Parsed: {$totalProducts} товаров...\r");
            }
        }
        if (!empty($batchBuffer)) {
            $staging->stageBatch($taskId, $batchBuffer);
        }

        $parseDuration = round(microtime(true) - $startPhase, 1);
        $brandCount = count($staging->getBrands($taskId));
        $catCount = count($staging->getCategories($taskId));

        $staging->updateMeta($taskId, [
            'status' => 'parsed',
            'total_items' => $totalProducts,
            'parsed_at' => date('Y-m-d H:i:s'),
            'parse_duration_sec' => $parseDuration,
        ]);

        $this->stdout("  Parsed: {$totalProducts} товаров                    \n");
        $this->stdout("  Брендов: {$brandCount} | Категорий: {$catCount}\n");
        $this->stdout("  Время: {$parseDuration}s\n", Console::FG_GREEN);
        $this->stdout("\n");

        // ═══ ФАЗА 2: AI-АНАЛИЗ ═══
        $aiDuration = 0;
        if ($this->ai && $totalProducts > 0) {
            $this->stdout("═══ Фаза 2: AI-анализ рецепта ═══\n", Console::FG_YELLOW);
            $ai = Yii::$app->get('aiService');

            if ($ai->isAvailable()) {
                $staging->setStatus($taskId, 'analyzing');
                $startPhase = microtime(true);

                $sample = $staging->getSample($taskId, 40);
                $this->stdout("  Сэмпл: " . count($sample) . " товаров\n");

                $existingBrands = Yii::$app->db->createCommand(
                    "SELECT canonical_name FROM {{%brands}} WHERE is_active = true"
                )->queryColumn();

                $existingCats = Yii::$app->db->createCommand(
                    "SELECT id, name FROM {{%categories}} WHERE is_active = true"
                )->queryAll();
                $catMap = [];
                foreach ($existingCats as $cat) $catMap[(int)$cat['id']] = $cat['name'];

                $recipe = $ai->generateImportRecipe(
                    $sample, $existingBrands, $catMap,
                    $staging->getBrands($taskId),
                    $staging->getCategories($taskId),
                );

                $aiDuration = round(microtime(true) - $startPhase, 1);

                if (!empty($recipe)) {
                    $staging->setRecipe($taskId, $recipe);
                    $this->stdout("  Brand mappings: " . count($recipe['brand_mapping'] ?? []) . "\n");
                    $this->stdout("  Category mappings: " . count($recipe['category_mapping'] ?? []) . "\n");
                    $this->stdout("  Data quality: " . ($recipe['insights']['data_quality'] ?? '?') . "\n");

                    if (!empty($recipe['insights']['notes'])) {
                        foreach ($recipe['insights']['notes'] as $note) {
                            $this->stdout("    → {$note}\n", Console::FG_GREY);
                        }
                    }
                } else {
                    $this->stdout("  AI вернул пустой рецепт, используем базовые правила\n", Console::FG_YELLOW);
                }

                $staging->updateMeta($taskId, [
                    'status' => 'analyzed',
                    'analyzed_at' => date('Y-m-d H:i:s'),
                    'ai_duration_sec' => $aiDuration,
                ]);

                $this->stdout("  Время: {$aiDuration}s\n", Console::FG_GREEN);
            } else {
                $this->stdout("  AI недоступен, пропускаем\n", Console::FG_YELLOW);
            }
            $this->stdout("\n");
        }

        // ═══ ФАЗА 3: НОРМАЛИЗАЦИЯ ═══
        $this->stdout("═══ Фаза 3: Нормализация в Redis ═══\n", Console::FG_YELLOW);
        $staging->setStatus($taskId, 'normalizing');
        $startPhase = microtime(true);

        $recipe = $staging->getRecipe($taskId);
        $normalizeJob = new \common\jobs\NormalizeStagedJob([
            'taskId' => $taskId,
            'supplierCode' => $supplier,
        ]);

        // Выполняем нормализацию синхронно (вместо очереди)
        $brandMapping = $normalizeJob->buildBrandMapping($recipe);
        $categoryMapping = $normalizeJob->buildCategoryMapping($recipe);
        $nameRules = $recipe['name_rules'] ?? [];
        $nameTemplate = $recipe['name_template'] ?? '{brand} {model}';
        $typeRules = $recipe['product_type_rules'] ?? [];

        $normalized = 0;
        foreach ($staging->iterateProducts($taskId, 300) as $sku => $data) {
            $data = $normalizeJob->normalizeItem($data, $brandMapping, $categoryMapping, $nameRules, $nameTemplate, $typeRules);
            $data['_normalized'] = true;
            $staging->stageRaw($taskId, $sku, $data);
            $normalized++;
            if ($normalized % 500 === 0) {
                $this->stdout("  Normalized: {$normalized}...\r");
            }
        }

        $normDuration = round(microtime(true) - $startPhase, 1);
        $this->stdout("  Normalized: {$normalized} товаров                    \n");
        $this->stdout("  Время: {$normDuration}s\n", Console::FG_GREEN);
        $staging->updateMeta($taskId, [
            'status' => 'normalized',
            'normalized_at' => date('Y-m-d H:i:s'),
            'normalize_duration_sec' => $normDuration,
        ]);
        $this->stdout("\n");

        // ═══ ФАЗА 4: ЗАПИСЬ В БД ═══
        $this->stdout("═══ Фаза 4: Bulk persist → PostgreSQL ═══\n", Console::FG_YELLOW);

        $itemsInRedis = $staging->getItemCount($taskId);
        $this->stdout("  Items in Redis: {$itemsInRedis}\n");

        $startPhase = microtime(true);
        $db = Yii::$app->db;

        // Inline persist — для синхронного режима не используем PersistStagedJob,
        // чтобы не зависеть от Redis meta
        $persistJob = new \common\jobs\PersistStagedJob([
            'taskId' => $taskId,
            'supplierCode' => $supplier,
        ]);
        $supplierId = $db->createCommand(
            "SELECT id FROM {{%suppliers}} WHERE code = :code",
            [':code' => $supplier]
        )->queryScalar();

        if (!$supplierId) {
            $this->stderr("  Поставщик '{$supplier}' не найден в БД\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $pStats = [
            'cards_created' => 0,
            'cards_updated' => 0,
            'offers_created' => 0,
            'offers_updated' => 0,
            'variants_total' => 0,
            'errors' => 0,
        ];

        $batchBuffer = [];
        $batchSize = 50;
        $persisted = 0;

        foreach ($staging->iterateProducts($taskId, 300) as $sku => $data) {
            try {
                $dto = $staging->deserializeToDTO($data);
                $batchBuffer[] = $dto;

                if (count($batchBuffer) >= $batchSize) {
                    foreach ($batchBuffer as $bDto) {
                        try {
                            $tx = $db->beginTransaction();
                            $result = $persistJob->persistProduct(null, $bDto, (int)$supplierId);
                            $tx->commit();
                            $pStats[$result['action']]++;
                            $pStats[$result['offer_action']]++;
                            $pStats['variants_total'] += $result['variants'];
                            $persisted++;
                        } catch (\Throwable $e) {
                            if (isset($tx) && $tx->getIsActive()) $tx->rollBack();
                            $pStats['errors']++;
                            if ($pStats['errors'] <= 10) {
                                $this->stderr("  Error: {$e->getMessage()}\n", Console::FG_RED);
                            }
                        }
                    }
                    $batchBuffer = [];
                    if ($persisted % 200 === 0) {
                        $this->stdout("  Persist: {$persisted}/{$itemsInRedis}...\r");
                    }
                }
            } catch (\Throwable $e) {
                $pStats['errors']++;
            }
        }

        // Остаток
        foreach ($batchBuffer as $bDto) {
            try {
                $tx = $db->beginTransaction();
                $result = $persistJob->persistProduct(null, $bDto, (int)$supplierId);
                $tx->commit();
                $pStats[$result['action']]++;
                $pStats[$result['offer_action']]++;
                $pStats['variants_total'] += $result['variants'];
                $persisted++;
            } catch (\Throwable $e) {
                if (isset($tx) && $tx->getIsActive()) $tx->rollBack();
                $pStats['errors']++;
            }
        }

        // Обновляем last_import_at
        $db->createCommand()->update(
            '{{%suppliers}}',
            ['last_import_at' => new \yii\db\Expression('NOW()')],
            ['code' => $supplier]
        )->execute();

        $persistDuration = round(microtime(true) - $startPhase, 1);

        $this->stdout("  Persist: {$persisted}/{$itemsInRedis} товаров                    \n");
        $this->stdout("  Created: {$pStats['cards_created']} карточек\n");
        $this->stdout("  Updated: {$pStats['cards_updated']} карточек\n");
        $this->stdout("  Offers:  {$pStats['offers_created']} new, {$pStats['offers_updated']} updated\n");
        $this->stdout("  Variants: {$pStats['variants_total']}\n");
        $this->stdout("  Errors: {$pStats['errors']}\n");
        $this->stdout("  Время: {$persistDuration}s\n", Console::FG_GREEN);
        $this->stdout("\n");

        // Итого
        $totalDuration = round($parseDuration + ($aiDuration ?? 0) + $normDuration + $persistDuration, 1);
        $this->stdout("═══════════════════════════════════════════\n", Console::FG_CYAN);
        $this->stdout("ИТОГО: {$totalProducts} товаров → {$persisted} persisted за {$totalDuration}s\n", Console::BOLD);
        $this->stdout("  Parse:     {$parseDuration}s\n");
        $this->stdout("  AI:        " . ($aiDuration ?? 0) . "s\n");
        $this->stdout("  Normalize: {$normDuration}s\n");
        $this->stdout("  Persist:   {$persistDuration}s\n");
        $this->stdout("═══════════════════════════════════════════\n", Console::FG_CYAN);

        // Очистка staging
        $staging->cleanup($taskId);
        $this->stdout("Redis staging очищен\n", Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * Поставить импорт в очередь (pipeline или legacy).
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
        if ($this->max > 0) $options['max_products'] = $this->max;

        $jobId = Yii::$app->queue->push(new ImportPriceJob([
            'supplierCode' => $supplier,
            'filePath' => $file,
            'options' => $options,
            'downloadImages' => !$this->skipImages,
            'mode' => $this->mode,
            'analyzeWithAI' => $this->ai,
        ]));

        $this->stdout("Задание поставлено в очередь (mode={$this->mode}): ID={$jobId}\n", Console::FG_GREEN);
        $this->stdout("Отслеживание: yii queue/info {$jobId}\n");

        return ExitCode::OK;
    }

    /**
     * Статус staging-задач в Redis.
     */
    public function actionStagingStatus(): int
    {
        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');

        $tasks = $staging->getActiveTasks();

        if (empty($tasks)) {
            $this->stdout("Нет активных staging-задач\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("\n=== Staging-задачи ===\n\n", Console::BOLD);

        foreach ($tasks as $task) {
            $taskId = $task['task_id'] ?? '?';
            $status = $task['status'] ?? '?';
            $supplier = $task['supplier_code'] ?? '?';
            $total = $task['total_items'] ?? 0;
            $created = $task['created_at'] ?? '?';

            $statusColor = match ($status) {
                'completed' => Console::FG_GREEN,
                'failed' => Console::FG_RED,
                'parsing', 'analyzing', 'normalizing', 'persisting' => Console::FG_YELLOW,
                default => Console::FG_GREY,
            };

            $this->stdout("  [{$taskId}]\n");
            $this->stdout("    Поставщик: {$supplier}\n");
            $this->stdout("    Статус: ", Console::BOLD);
            $this->stdout("{$status}\n", $statusColor);
            $this->stdout("    Товаров: {$total}\n");
            $this->stdout("    Создан: {$created}\n");

            if (!empty($task['parse_duration_sec'])) {
                $this->stdout("    Parse: {$task['parse_duration_sec']}s\n");
            }
            if (!empty($task['ai_duration_sec'])) {
                $this->stdout("    AI: {$task['ai_duration_sec']}s\n");
            }
            if (!empty($task['normalize_duration_sec'])) {
                $this->stdout("    Normalize: {$task['normalize_duration_sec']}s\n");
            }
            if (!empty($task['persist_duration_sec'])) {
                $this->stdout("    Persist: {$task['persist_duration_sec']}s\n");
            }

            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    /**
     * Очистить staging-данные задачи.
     *
     * @param string $taskId ID задачи
     */
    public function actionStagingCleanup(string $taskId): int
    {
        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');

        $meta = $staging->getMeta($taskId);
        if (!$meta) {
            $this->stderr("Задача не найдена: {$taskId}\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $itemCount = $staging->getItemCount($taskId);
        $staging->cleanup($taskId);

        $this->stdout("Очищено: {$taskId} ({$itemCount} товаров)\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Поставить скачивание картинок в очередь.
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
        foreach ($chunks as $chunk) {
            Yii::$app->queue->push(new DownloadImagesJob([
                'cardIds' => $chunk,
                'supplierCode' => $supplier,
            ]));
        }

        $this->stdout("Поставлено " . count($chunks) . " заданий на скачку (" . count($cardIds) . " карточек)\n", Console::FG_GREEN);

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

        // Staging stats
        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');
        $tasks = $staging->getActiveTasks();
        if (!empty($tasks)) {
            $this->stdout("\nStaging-задачи: " . count($tasks) . "\n", Console::BOLD);
            foreach ($tasks as $t) {
                $this->stdout("  {$t['task_id']} — {$t['status']} ({$t['total_items']} товаров)\n");
            }
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
