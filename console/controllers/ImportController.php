<?php

namespace console\controllers;

use common\jobs\DownloadImagesJob;
use common\jobs\ImportPriceJob;
use common\jobs\NormalizeStagedJob;
use common\jobs\PersistStagedJob;
use common\models\SupplierAiRecipe;
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
 *   yii import/pipeline ormatek /app/storage/prices/ormatek/All.xml    -- pipeline (PostgreSQL staging)
 *   yii import/queue ormatek /app/storage/prices/ormatek/All.xml       -- pipeline в очередь
 *   yii import/staging-status                                           -- статус staging-сессий
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
     * Синхронный импорт LEGACY (прямой SQL, без staging).
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
     * Pipeline-импорт: Parse → PostgreSQL staging → AI → Normalize → Persist.
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
        $db = Yii::$app->db;

        // Получаем/создаём supplier
        $supplierId = $db->createCommand(
            "SELECT id FROM {{%suppliers}} WHERE code = :code",
            [':code' => $supplier]
        )->queryScalar();

        if (!$supplierId) {
            $db->createCommand()->insert('{{%suppliers}}', [
                'name'      => ucfirst($supplier),
                'code'      => $supplier,
                'is_active' => true,
                'format'    => 'xml',
            ])->execute();
            $supplierId = (int)$db->getLastInsertID('suppliers_id_seq');
        }
        $supplierId = (int)$supplierId;

        // ═══ ФАЗА 1: ПАРСИНГ → PostgreSQL UNLOGGED TABLE ═══
        $this->stdout("═══ Фаза 1: Парсинг → PostgreSQL staging ═══\n", Console::FG_YELLOW);

        $sessionId = $staging->createSession($supplier, $supplierId, $file, $options);
        $this->stdout("  Session ID: {$sessionId}\n");

        $parserRegistry = Yii::$app->get('parserRegistry');
        $parser = $parserRegistry->get($supplier);
        if (!$parser) {
            $this->stderr("  Парсер не найден для '{$supplier}'\n", Console::FG_RED);
            $staging->setStatus($sessionId, 'failed');
            return ExitCode::DATAERR;
        }

        $staging->setStatus($sessionId, 'parsing');
        $startPhase = microtime(true);
        $totalProducts = 0;
        $batchBuffer = [];
        $batchSize = 500;

        foreach ($parser->parse($file, $options) as $productDTO) {
            $batchBuffer[] = $productDTO;
            $totalProducts++;

            if (count($batchBuffer) >= $batchSize) {
                $staging->insertBatch($sessionId, $supplierId, $batchBuffer);
                $batchBuffer = [];
                $this->stdout("  Parsed: {$totalProducts} товаров...\r");
            }

            if ($this->max > 0 && $totalProducts >= $this->max) {
                break;
            }
        }
        if (!empty($batchBuffer)) {
            $staging->insertBatch($sessionId, $supplierId, $batchBuffer);
        }

        $parseDuration = round(microtime(true) - $startPhase, 1);

        // Бренды/категории через SQL-агрегацию
        $uniqueBrands = $staging->getBrands($sessionId);
        $uniqueCategories = $staging->getCategories($sessionId);

        $staging->updateStats($sessionId, [
            'total_items'        => $totalProducts,
            'parsed_items'       => $totalProducts,
            'parse_duration_sec' => $parseDuration,
        ]);
        $staging->setStatus($sessionId, 'parsed');

        $parseRate = $totalProducts > 0 ? round($totalProducts / max($parseDuration, 0.1)) : 0;
        $this->stdout("  Parsed: {$totalProducts} товаров                    \n");
        $this->stdout("  Брендов: " . count($uniqueBrands) . " | Категорий: " . count($uniqueCategories) . "\n");
        $this->stdout("  Скорость: {$parseRate}/s, Время: {$parseDuration}s\n", Console::FG_GREEN);
        $this->stdout("\n");

        // ═══ ФАЗА 2: AI-АНАЛИЗ (с кэшированием рецептов) ═══
        $aiDuration = 0;
        $recipe = [];
        if ($this->ai && $totalProducts > 0) {
            $this->stdout("═══ Фаза 2: AI-анализ рецепта ═══\n", Console::FG_YELLOW);

            // Сначала проверяем кэш рецептов
            $cachedRecipe = SupplierAiRecipe::findActiveForSupplier($supplierId);

            if ($cachedRecipe) {
                $recipe = $cachedRecipe->toNormalizeRecipe();
                $this->stdout("  ✓ Рецепт найден в кэше (v{$cachedRecipe->recipe_version})\n", Console::FG_GREEN);
                $this->stdout("  Brand mappings: " . count($recipe['brand_mapping'] ?? []) . "\n");
                $this->stdout("  Category mappings: " . count($recipe['category_mapping'] ?? []) . "\n");
                $this->stdout("  Качество данных: " . ($cachedRecipe->data_quality ?? '?') . "\n");
                $this->stdout("  AI не вызывался — сэкономлено ~{$cachedRecipe->ai_duration_sec}s\n", Console::FG_GREEN);

                $staging->updateStats($sessionId, [
                    'recipe'            => $recipe,
                    'ai_cached'         => true,
                    'ai_recipe_version' => $cachedRecipe->recipe_version,
                    'ai_duration_sec'   => 0,
                ]);
                $staging->setStatus($sessionId, 'analyzed');
            } else {
                $ai = Yii::$app->get('aiService');

                if ($ai->isAvailable()) {
                    $staging->setStatus($sessionId, 'analyzing');
                    $startPhase = microtime(true);

                    // Случайная выборка из PostgreSQL staging
                    $sample = $staging->getSample($sessionId, 40);
                    $this->stdout("  Кэш рецепта не найден, генерируем новый...\n");
                    $this->stdout("  Сэмпл: " . count($sample) . " товаров\n");

                    $existingBrands = $db->createCommand(
                        "SELECT canonical_name FROM {{%brands}} WHERE is_active = true"
                    )->queryColumn();

                    $existingCats = $db->createCommand(
                        "SELECT id, name FROM {{%categories}} WHERE is_active = true"
                    )->queryAll();
                    $catMap = [];
                    foreach ($existingCats as $cat) $catMap[(int)$cat['id']] = $cat['name'];

                    $recipe = $ai->generateImportRecipe(
                        $sample, $existingBrands, $catMap,
                        $uniqueBrands, $uniqueCategories,
                    );

                    $aiDuration = round(microtime(true) - $startPhase, 1);

                    if (!empty($recipe)) {
                        // Кэшируем рецепт в supplier_ai_recipes
                        $saved = SupplierAiRecipe::saveFromAIResponse(
                            $supplierId,
                            $supplier,
                            $recipe,
                            [
                                'sample_size'     => count($sample),
                                'ai_model'        => 'deepseek-chat',
                                'ai_duration_sec' => $aiDuration,
                            ]
                        );

                        $staging->updateStats($sessionId, [
                            'recipe'            => $recipe,
                            'ai_cached'         => false,
                            'ai_recipe_version' => $saved->recipe_version,
                            'ai_duration_sec'   => $aiDuration,
                        ]);

                        $this->stdout("  Brand mappings: " . count($recipe['brand_mapping'] ?? []) . "\n");
                        $this->stdout("  Category mappings: " . count($recipe['category_mapping'] ?? []) . "\n");
                        $this->stdout("  Data quality: " . ($recipe['insights']['data_quality'] ?? '?') . "\n");
                        $this->stdout("  Рецепт закэширован (v{$saved->recipe_version})\n", Console::FG_GREEN);

                        if (!empty($recipe['insights']['notes'])) {
                            foreach ($recipe['insights']['notes'] as $note) {
                                $this->stdout("    → {$note}\n", Console::FG_GREY);
                            }
                        }
                    } else {
                        $this->stdout("  AI вернул пустой рецепт, используем базовые правила\n", Console::FG_YELLOW);
                    }

                    $staging->setStatus($sessionId, 'analyzed');
                    $this->stdout("  Время: {$aiDuration}s\n", Console::FG_GREEN);
                } else {
                    $this->stdout("  AI недоступен, пропускаем\n", Console::FG_YELLOW);
                }
            }
            $this->stdout("\n");
        }

        // ═══ ФАЗА 3: НОРМАЛИЗАЦИЯ в PostgreSQL staging ═══
        $this->stdout("═══ Фаза 3: Нормализация в PostgreSQL staging ═══\n", Console::FG_YELLOW);
        $staging->setStatus($sessionId, 'normalizing');
        $startPhase = microtime(true);

        $normalizeJob = new NormalizeStagedJob([
            'sessionId'    => $sessionId,
            'supplierCode' => $supplier,
            'supplierId'   => $supplierId,
            'recipe'       => $recipe,
        ]);

        // Подготавливаем маппинги
        $brandMapping = $normalizeJob->buildBrandMapping($recipe);
        $categoryMapping = $normalizeJob->buildCategoryMapping($recipe);
        $nameRules = $recipe['name_rules'] ?? [];
        $nameTemplate = $recipe['name_template'] ?? '{brand} {model}';
        $typeRules = $recipe['product_type_rules'] ?? [];

        $normalized = 0;
        $normErrors = 0;
        $updateBatch = [];

        // Cursor-based итерация по pending записям
        foreach ($staging->iteratePending($sessionId, 500) as $rowId => $row) {
            try {
                $data = $row['raw_data'];
                $normalizedData = $normalizeJob->normalizeItem(
                    $data, $brandMapping, $categoryMapping,
                    $nameRules, $nameTemplate, $typeRules
                );

                $updateBatch[] = [$rowId, $normalizedData];
                $normalized++;

                // Flush batch
                if (count($updateBatch) >= 100) {
                    $staging->markNormalizedBatch($updateBatch);
                    $updateBatch = [];
                }

                if ($normalized % 500 === 0) {
                    $this->stdout("  Normalized: {$normalized}...\r");
                }
            } catch (\Throwable $e) {
                $normErrors++;
                $staging->markError($rowId, $e->getMessage());
                if ($normErrors <= 10) {
                    $this->stderr("  Norm error: {$e->getMessage()}\n", Console::FG_RED);
                }
            }
        }

        // Остаток
        if (!empty($updateBatch)) {
            $staging->markNormalizedBatch($updateBatch);
        }

        $normDuration = round(microtime(true) - $startPhase, 1);
        $normRate = $normalized > 0 ? round($normalized / max($normDuration, 0.1)) : 0;

        $staging->updateStats($sessionId, [
            'normalized_items'       => $normalized,
            'normalize_duration_sec' => $normDuration,
            'normalize_errors'       => $normErrors,
        ]);
        $staging->setStatus($sessionId, 'normalized');

        $this->stdout("  Normalized: {$normalized} товаров (ошибок: {$normErrors})      \n");
        $this->stdout("  Скорость: {$normRate}/s, Время: {$normDuration}s\n", Console::FG_GREEN);
        $this->stdout("\n");

        // ═══ ФАЗА 4: ЗАПИСЬ В БД ═══
        $this->stdout("═══ Фаза 4: Bulk persist → PostgreSQL ═══\n", Console::FG_YELLOW);

        $itemsNormalized = $staging->getItemCount($sessionId, 'normalized');
        $this->stdout("  Items in staging (normalized): {$itemsNormalized}\n");

        $staging->setStatus($sessionId, 'persisting');
        $startPhase = microtime(true);

        $persistJob = new PersistStagedJob([
            'sessionId'    => $sessionId,
            'supplierCode' => $supplier,
            'supplierId'   => $supplierId,
        ]);

        $pStats = [
            'cards_created'  => 0,
            'cards_updated'  => 0,
            'offers_created' => 0,
            'offers_updated' => 0,
            'variants_total' => 0,
            'errors'         => 0,
            'persisted'      => 0,
        ];

        $persistedIds = [];

        foreach ($staging->iterateNormalized($sessionId, 500) as $rowId => $row) {
            try {
                $dto = $staging->deserializeToDTO(
                    $row['raw_data'],
                    $row['normalized_data']
                );

                $tx = $db->beginTransaction();
                $result = $persistJob->persistProduct($dto, $supplierId);
                $tx->commit();

                $pStats[$result['action']]++;
                $pStats[$result['offer_action']]++;
                $pStats['variants_total'] += $result['variants'];
                $pStats['persisted']++;

                $persistedIds[] = $rowId;

                // Batch-обновление статуса staging
                if (count($persistedIds) >= 100) {
                    $staging->markPersistedBatch($persistedIds);
                    $persistedIds = [];
                }

                if ($pStats['persisted'] % 200 === 0) {
                    $this->stdout("  Persist: {$pStats['persisted']}/{$itemsNormalized}...\r");
                }

            } catch (\Throwable $e) {
                if (isset($tx) && $tx->getIsActive()) $tx->rollBack();
                $pStats['errors']++;
                $staging->markError($rowId, $e->getMessage());
                if ($pStats['errors'] <= 10) {
                    $this->stderr("  Error: {$e->getMessage()}\n", Console::FG_RED);
                }
            }
        }

        // Остаток persisted ids
        if (!empty($persistedIds)) {
            $staging->markPersistedBatch($persistedIds);
        }

        // Обновляем last_import_at для поставщика
        $db->createCommand()->update(
            '{{%suppliers}}',
            ['last_import_at' => new \yii\db\Expression('NOW()')],
            ['code' => $supplier]
        )->execute();

        $persistDuration = round(microtime(true) - $startPhase, 1);
        $persistRate = $pStats['persisted'] > 0 ? round($pStats['persisted'] / max($persistDuration, 0.1)) : 0;

        $staging->updateStats($sessionId, [
            'persisted_items'      => $pStats['persisted'],
            'persist_duration_sec' => $persistDuration,
            'persist_stats'        => $pStats,
        ]);
        $staging->setStatus($sessionId, 'completed');

        $this->stdout("  Persist: {$pStats['persisted']}/{$itemsNormalized} товаров                    \n");
        $this->stdout("  Created: {$pStats['cards_created']} карточек\n");
        $this->stdout("  Updated: {$pStats['cards_updated']} карточек\n");
        $this->stdout("  Offers:  {$pStats['offers_created']} new, {$pStats['offers_updated']} updated\n");
        $this->stdout("  Variants: {$pStats['variants_total']}\n");
        $this->stdout("  Errors: {$pStats['errors']}\n");
        $this->stdout("  Скорость: {$persistRate}/s, Время: {$persistDuration}s\n", Console::FG_GREEN);
        $this->stdout("\n");

        // ═══ ИТОГО ═══
        $totalDuration = round($parseDuration + $aiDuration + $normDuration + $persistDuration, 1);
        $this->stdout("═══════════════════════════════════════════\n", Console::FG_CYAN);
        $this->stdout("ИТОГО: {$totalProducts} товаров → {$pStats['persisted']} persisted за {$totalDuration}s\n", Console::BOLD);
        $this->stdout("  Parse:     {$parseDuration}s ({$parseRate}/s)\n");
        $this->stdout("  AI:        {$aiDuration}s\n");
        $this->stdout("  Normalize: {$normDuration}s ({$normRate}/s)\n");
        $this->stdout("  Persist:   {$persistDuration}s ({$persistRate}/s)\n");
        $this->stdout("═══════════════════════════════════════════\n", Console::FG_CYAN);

        // Очистка staging (опционально)
        $stagingTotal = $staging->getItemCount($sessionId);
        $this->stdout("\nStaging данные ({$stagingTotal} записей) сохранены для анализа.\n", Console::FG_GREY);
        $this->stdout("Очистить: yii import/staging-cleanup {$sessionId}\n", Console::FG_GREY);

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
     * Статус staging-сессий в PostgreSQL.
     */
    public function actionStagingStatus(): int
    {
        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');

        $sessions = $staging->getActiveSessions();

        if (empty($sessions)) {
            $this->stdout("Нет активных staging-сессий\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("\n=== Staging-сессии (PostgreSQL) ===\n\n", Console::BOLD);

        foreach ($sessions as $s) {
            $sessionId = $s['session_id'];
            $status = $s['status'];
            $supplierCode = $s['supplier_code'];
            $totalItems = (int)$s['total_items'];
            $stagingCount = (int)($s['staging_count'] ?? 0);
            $createdAt = $s['created_at'];

            $statusColor = match ($status) {
                'completed' => Console::FG_GREEN,
                'failed'    => Console::FG_RED,
                'parsing', 'analyzing', 'normalizing', 'persisting' => Console::FG_YELLOW,
                'cleaned'   => Console::FG_GREY,
                default     => Console::FG_CYAN,
            };

            $this->stdout("  [{$sessionId}]\n");
            $this->stdout("    Поставщик: {$supplierCode}\n");
            $this->stdout("    Статус: ", Console::BOLD);
            $this->stdout("{$status}\n", $statusColor);
            $this->stdout("    Товаров: {$totalItems} (в staging: {$stagingCount})\n");
            $this->stdout("    Создан: {$createdAt}\n");

            // Извлекаем stats
            $stats = json_decode($s['stats'] ?? '{}', true) ?: [];
            if (!empty($stats['parse_duration_sec'])) {
                $this->stdout("    Parse: {$stats['parse_duration_sec']}s\n");
            }
            if (!empty($stats['ai_duration_sec'])) {
                $this->stdout("    AI: {$stats['ai_duration_sec']}s\n");
            }
            if (!empty($stats['normalize_duration_sec'])) {
                $this->stdout("    Normalize: {$stats['normalize_duration_sec']}s\n");
            }
            if (!empty($stats['persist_duration_sec'])) {
                $this->stdout("    Persist: {$stats['persist_duration_sec']}s\n");
            }

            // Статус-счётчики staging
            if ($status !== 'cleaned') {
                $statusCounts = $staging->getStatusCounts($sessionId);
                if (!empty($statusCounts)) {
                    $parts = [];
                    foreach ($statusCounts as $st => $cnt) {
                        $parts[] = "{$st}={$cnt}";
                    }
                    $this->stdout("    Staging: " . implode(', ', $parts) . "\n");
                }
            }

            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    /**
     * Очистить staging-данные сессии.
     *
     * @param string $sessionId ID сессии
     */
    public function actionStagingCleanup(string $sessionId): int
    {
        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');

        $session = $staging->getSession($sessionId);
        if (!$session) {
            $this->stderr("Сессия не найдена: {$sessionId}\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $itemCount = $staging->getItemCount($sessionId);
        $deleted = $staging->cleanupSession($sessionId);

        $this->stdout("Очищено: {$sessionId} ({$deleted} записей удалено)\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Очистить все старые staging-данные.
     *
     * @param int $hours Удалить сессии старше N часов (по умолчанию 48)
     */
    public function actionStagingCleanupOld(int $hours = 48): int
    {
        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');

        $deleted = $staging->cleanupOld($hours);
        $this->stdout("Очищено {$deleted} staging-записей старше {$hours}ч\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Детальная статистика конкретной сессии.
     *
     * @param string $sessionId ID сессии
     */
    public function actionSessionInfo(string $sessionId): int
    {
        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');

        $info = $staging->getSessionStats($sessionId);
        if (isset($info['error'])) {
            $this->stderr("Ошибка: {$info['error']}\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->stdout("\n=== Session: {$sessionId} ===\n\n", Console::BOLD);
        $this->stdout("  Поставщик:    {$info['supplier_code']}\n");
        $this->stdout("  Статус:       {$info['status']}\n");
        $this->stdout("  Товаров:      {$info['total_items']}\n");
        $this->stdout("  В staging:    {$info['staging_total']}\n");
        $this->stdout("  Уник. бренды: {$info['unique_brands']}\n");
        $this->stdout("  Уник. кат-ии: {$info['unique_categories']}\n");
        $this->stdout("  Создан:       {$info['created_at']}\n");

        $this->stdout("\n  Staging по статусам:\n");
        foreach ($info['staging_counts'] as $st => $cnt) {
            $this->stdout("    {$st}: {$cnt}\n");
        }

        $stats = $info['stats'] ?? [];
        if (!empty($stats)) {
            $this->stdout("\n  Статистика:\n");
            foreach ($stats as $key => $val) {
                if ($key === 'recipe' || $key === 'persist_stats') continue;
                $display = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : $val;
                $this->stdout("    {$key}: {$display}\n");
            }
        }

        $this->stdout("\n");
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
        $sessions = $staging->getActiveSessions(5);
        if (!empty($sessions)) {
            $this->stdout("\nПоследние staging-сессии:\n", Console::BOLD);
            foreach ($sessions as $s) {
                $sid = $s['session_id'];
                $status = $s['status'];
                $total = (int)$s['total_items'];
                $stagingCount = (int)($s['staging_count'] ?? 0);
                $this->stdout("  {$sid} — {$status} ({$total} товаров, staging: {$stagingCount})\n");
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
