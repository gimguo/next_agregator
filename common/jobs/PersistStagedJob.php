<?php

namespace common\jobs;

use common\services\CatalogPersisterService;
use common\services\ImportStagingService;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Фаза 4: Bulk-запись из PostgreSQL staging → MDM-каталог.
 *
 * Итерирует по staging_raw_offers (status='normalized'),
 * десериализует в ProductDTO и передаёт в CatalogPersisterService,
 * который через MatchingService → GoldenRecordService записывает
 * в трёхуровневую иерархию: product_models → reference_variants → supplier_offers.
 *
 * Cursor-based iteration, каждый товар в своей транзакции.
 * После persist — статус строки в staging = 'persisted'.
 *
 * После завершения ставит фоновые задачи:
 * - DownloadImagesJob (картинки)
 * - AI-обогащение (бренды, категории, описания)
 */
class PersistStagedJob extends BaseObject implements JobInterface
{
    public string $sessionId = '';
    public string $supplierCode = '';
    public int $supplierId = 0;

    /** @var bool Скачивать ли картинки */
    public bool $downloadImages = true;

    /** @var bool Запускать ли AI-обогащение */
    public bool $runAIEnrichment = true;

    /** @var int Размер батча для записи в БД */
    public int $batchSize = 50;

    /** @var bool Использовать ли новый MDM-каталог (product_models + reference_variants) */
    public bool $useMdmCatalog = true;

    // Обратная совместимость
    public string $taskId = '';

    public function init(): void
    {
        parent::init();
        if (!empty($this->taskId) && empty($this->sessionId)) {
            $this->sessionId = $this->taskId;
        }
    }

    public function execute($queue): void
    {
        Yii::info("PersistStagedJob: старт sessionId={$this->sessionId} mdm={$this->useMdmCatalog}", 'import');

        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');
        $staging->setStatus($this->sessionId, 'persisting');

        $startTime = microtime(true);
        $db = Yii::$app->db;

        // Получаем supplierId
        if ($this->supplierId <= 0) {
            $this->supplierId = $this->ensureSupplier($db);
        }

        $totalInStaging = $staging->getItemCount($this->sessionId, 'normalized');
        Yii::info("PersistStagedJob: items to persist = {$totalInStaging}", 'import');

        if ($this->useMdmCatalog) {
            $stats = $this->persistViaMdm($staging, $startTime, $totalInStaging);
        } else {
            $stats = $this->persistLegacy($staging, $startTime, $totalInStaging);
        }

        $duration = round(microtime(true) - $startTime, 1);
        $persisted = $stats['persisted'] ?? 0;
        $rate = $persisted > 0 ? round($persisted / max($duration, 0.1)) : 0;

        // Обновляем last_import_at для поставщика
        $db->createCommand()->update(
            '{{%suppliers}}',
            ['last_import_at' => new \yii\db\Expression('NOW()')],
            ['code' => $this->supplierCode]
        )->execute();

        $staging->updateStats($this->sessionId, [
            'persisted_items'       => $persisted,
            'persist_duration_sec'  => $duration,
            'persist_rate'          => $rate,
            'persist_stats'         => $stats,
        ]);
        $staging->setStatus($this->sessionId, 'completed');

        Yii::info("PersistStagedJob: завершён — " . json_encode($stats) . " rate={$rate}/s time={$duration}s", 'import');

        // Фоновые задачи
        $hasNewData = ($stats['models_created'] ?? $stats['cards_created'] ?? 0) > 0 ||
                      ($stats['offers_created'] ?? 0) > 0;

        if ($this->downloadImages && $hasNewData) {
            $this->enqueueImageDownload();
        }

        if ($this->runAIEnrichment && $hasNewData) {
            $this->enqueueAIProcessing();
        }
    }

    // ═══════════════════════════════════════════
    // MDM PERSIST — через CatalogPersisterService
    // ═══════════════════════════════════════════

    protected function persistViaMdm(ImportStagingService $staging, float $startTime, int $total): array
    {
        /** @var CatalogPersisterService $persister */
        $persister = Yii::$app->get('catalogPersister');
        $persister->resetStats();

        $db = Yii::$app->db;
        $persistedIds = [];
        $persisted = 0;
        $errors = 0;

        foreach ($staging->iterateNormalized($this->sessionId, 500) as $rowId => $row) {
            try {
                $dto = $staging->deserializeToDTO(
                    $row['raw_data'],
                    $row['normalized_data']
                );

                $tx = $db->beginTransaction();

                $result = $persister->persist(
                    $dto,
                    $this->supplierId,
                    $this->sessionId,
                );

                $tx->commit();

                $persisted++;
                $persistedIds[] = $rowId;

                // Batch-обновление статуса staging
                if (count($persistedIds) >= 100) {
                    $staging->markPersistedBatch($persistedIds);
                    $persistedIds = [];
                }

                if ($persisted % 200 === 0) {
                    $elapsed = round(microtime(true) - $startTime, 1);
                    $rate = $persisted > 0 ? round($persisted / max($elapsed, 0.1)) : 0;
                    $s = $persister->getStats();
                    Yii::info(
                        "PersistStagedJob[MDM]: прогресс {$persisted}/{$total} rate={$rate}/s " .
                        "models_new={$s['models_created']} matched={$s['models_matched']} " .
                        "variants_new={$s['variants_created']} matched_v={$s['variants_matched']}",
                        'import'
                    );
                }

                if ($persisted % 2000 === 0) {
                    gc_collect_cycles();
                }

            } catch (\Throwable $e) {
                if (isset($tx) && $tx->getIsActive()) {
                    $tx->rollBack();
                }
                $errors++;
                $staging->markError($rowId, $e->getMessage());
                if ($errors <= 30) {
                    Yii::warning(
                        "PersistStagedJob[MDM]: ошибка row={$rowId}: {$e->getMessage()}",
                        'import'
                    );
                }
            }
        }

        // Остаток persisted ids
        if (!empty($persistedIds)) {
            $staging->markPersistedBatch($persistedIds);
        }

        $stats = $persister->getStats();
        $stats['persisted'] = $persisted;
        $stats['errors'] = $errors;

        return $stats;
    }

    // ═══════════════════════════════════════════
    // LEGACY PERSIST — прямая запись в product_cards (backward compat)
    // ═══════════════════════════════════════════

    protected function persistLegacy(ImportStagingService $staging, float $startTime, int $total): array
    {
        $db = Yii::$app->db;

        $stats = [
            'cards_created'  => 0,
            'cards_updated'  => 0,
            'offers_created' => 0,
            'offers_updated' => 0,
            'variants_total' => 0,
            'errors'         => 0,
            'persisted'      => 0,
        ];

        $persistedIds = [];

        foreach ($staging->iterateNormalized($this->sessionId, 500) as $rowId => $row) {
            try {
                $dto = $staging->deserializeToDTO(
                    $row['raw_data'],
                    $row['normalized_data']
                );

                $tx = $db->beginTransaction();
                $result = $this->persistProductLegacy($dto, $this->supplierId);
                $tx->commit();

                $stats[$result['action']]++;
                $stats[$result['offer_action']]++;
                $stats['variants_total'] += $result['variants'];
                $stats['persisted']++;

                $persistedIds[] = $rowId;

                if (count($persistedIds) >= 100) {
                    $staging->markPersistedBatch($persistedIds);
                    $persistedIds = [];
                }

                if ($stats['persisted'] % 200 === 0) {
                    $elapsed = round(microtime(true) - $startTime, 1);
                    $rate = $stats['persisted'] > 0 ? round($stats['persisted'] / max($elapsed, 0.1)) : 0;
                    Yii::info(
                        "PersistStagedJob[legacy]: прогресс {$stats['persisted']}/{$total} rate={$rate}/s",
                        'import'
                    );
                }

            } catch (\Throwable $e) {
                if (isset($tx) && $tx->getIsActive()) {
                    $tx->rollBack();
                }
                $stats['errors']++;
                $staging->markError($rowId, $e->getMessage());
                if ($stats['errors'] <= 30) {
                    Yii::warning("PersistStagedJob[legacy]: ошибка row={$rowId}: {$e->getMessage()}", 'import');
                }
            }
        }

        if (!empty($persistedIds)) {
            $staging->markPersistedBatch($persistedIds);
        }

        return $stats;
    }

    /**
     * Legacy: сохранить один товар в product_cards + supplier_offers.
     */
    public function persistProductLegacy($dto, int $supplierId): array
    {
        $variantCount = count($dto->variants);
        $db = Yii::$app->db;

        $manufacturer = $dto->manufacturer ?? 'Unknown';
        $modelName = $dto->model ?? $dto->name;

        $cardId = $db->createCommand(
            "SELECT id FROM {{%product_cards}} WHERE manufacturer = :m AND model = :model LIMIT 1",
            [':m' => $manufacturer, ':model' => $modelName]
        )->queryScalar();

        $isNewCard = false;
        if (!$cardId) {
            $isNewCard = true;
            $cardId = $this->createCard($db, $dto);
        } else {
            $this->updateCard($db, (int)$cardId, $dto);
        }

        $offerAction = $this->upsertOfferLegacy($db, $dto, (int)$cardId, $supplierId);

        if (!empty($dto->imageUrls)) {
            $this->enqueueImages($db, (int)$cardId, $dto->imageUrls);
        }

        return [
            'action'       => $isNewCard ? 'cards_created' : 'cards_updated',
            'offer_action' => $offerAction,
            'variants'     => $variantCount,
        ];
    }

    public function createCard($db, $dto): int
    {
        $manufacturer = $dto->manufacturer ?? 'Unknown';
        $modelName = $dto->model ?? $dto->name;
        $baseSlug = $this->slugify($manufacturer . '-' . $modelName);

        $slug = $baseSlug;
        $suffix = 0;
        while ($db->createCommand("SELECT 1 FROM {{%product_cards}} WHERE slug = :slug", [':slug' => $slug])->queryScalar()) {
            $suffix++;
            $slug = $baseSlug . '-' . $suffix;
        }

        $minPrice = $dto->getMinPrice();
        $maxPrice = $dto->getMaxPrice();

        $db->createCommand()->insert('{{%product_cards}}', [
            'canonical_name'       => $dto->name,
            'slug'                 => $slug,
            'manufacturer'         => $manufacturer,
            'model'                => $modelName,
            'description'          => $dto->description,
            'canonical_attributes' => json_encode($dto->attributes, JSON_UNESCAPED_UNICODE),
            'canonical_images'     => json_encode($dto->imageUrls, JSON_UNESCAPED_UNICODE),
            'price_range_min'      => $minPrice,
            'price_range_max'      => $maxPrice,
            'best_price'           => $minPrice,
            'total_variants'       => count($dto->variants),
            'image_count'          => count($dto->imageUrls),
            'is_in_stock'          => $dto->inStock,
            'supplier_count'       => 1,
            'source_supplier'      => $this->supplierCode,
            'status'               => 'active',
            'quality_score'        => 50,
            'is_published'         => true,
            'has_active_offers'    => $dto->inStock,
        ])->execute();

        return (int)$db->getLastInsertID('product_cards_id_seq');
    }

    public function updateCard($db, int $cardId, $dto): void
    {
        $minPrice = $dto->getMinPrice();
        $maxPrice = $dto->getMaxPrice();

        $sql = "UPDATE {{%product_cards}} SET updated_at = NOW(), has_active_offers = true, is_in_stock = true";
        $params = [':id' => $cardId];

        if ($minPrice !== null) {
            $sql .= ", price_range_min = LEAST(COALESCE(price_range_min, :pmin), :pmin)";
            $sql .= ", best_price = LEAST(COALESCE(best_price, :bp), :bp)";
            $params[':pmin'] = $minPrice;
            $params[':bp'] = $minPrice;
        }
        if ($maxPrice !== null) {
            $sql .= ", price_range_max = GREATEST(COALESCE(price_range_max, :pmax), :pmax)";
            $params[':pmax'] = $maxPrice;
        }
        $sql .= ", total_variants = GREATEST(COALESCE(total_variants, 0), :vc)";
        $params[':vc'] = count($dto->variants);

        if ($dto->description) {
            $sql .= ", description = COALESCE(NULLIF(description, ''), :desc)";
            $params[':desc'] = $dto->description;
        }

        $sql .= " WHERE id = :id";
        $db->createCommand($sql, $params)->execute();
    }

    public function upsertOfferLegacy($db, $dto, int $cardId, int $supplierId): string
    {
        $variantsJson = json_encode(array_map(fn($v) => [
            'sku'          => $v->sku,
            'price'        => $v->price,
            'compare_price' => $v->comparePrice,
            'in_stock'     => $v->inStock,
            'stock_status' => $v->stockStatus,
            'options'      => $v->options,
        ], $dto->variants), JSON_UNESCAPED_UNICODE);

        $checksum = $dto->getChecksum();
        $comparePrice = null;
        foreach ($dto->variants as $v) {
            if ($v->comparePrice !== null) {
                $comparePrice = $v->comparePrice;
                break;
            }
        }

        $sql = "
            INSERT INTO {{%supplier_offers}} (
                card_id, supplier_id, supplier_sku,
                price_min, price_max, compare_price,
                in_stock, stock_status, description,
                attributes_json, images_json, variants_json, variant_count,
                match_confidence, match_method, checksum, is_active,
                raw_data, created_at, updated_at
            ) VALUES (
                :card_id, :supplier_id, :sku,
                :price_min, :price_max, :compare_price,
                :in_stock, :stock_status, :description,
                :attributes::jsonb, :images::jsonb, :variants::jsonb, :variant_count,
                1.0, 'manufacturer_model', :checksum, true,
                :raw_data::jsonb, NOW(), NOW()
            )
            ON CONFLICT (supplier_id, supplier_sku) DO UPDATE SET
                card_id = EXCLUDED.card_id,
                price_min = EXCLUDED.price_min,
                price_max = EXCLUDED.price_max,
                compare_price = EXCLUDED.compare_price,
                in_stock = EXCLUDED.in_stock,
                stock_status = EXCLUDED.stock_status,
                description = EXCLUDED.description,
                attributes_json = EXCLUDED.attributes_json,
                images_json = EXCLUDED.images_json,
                variants_json = EXCLUDED.variants_json,
                variant_count = EXCLUDED.variant_count,
                checksum = EXCLUDED.checksum,
                previous_price_min = supplier_offers.price_min,
                price_changed_at = CASE 
                    WHEN supplier_offers.price_min != EXCLUDED.price_min THEN NOW()
                    ELSE supplier_offers.price_changed_at
                END,
                is_active = true,
                updated_at = NOW()
            RETURNING (xmax = 0) AS is_insert
        ";

        $row = $db->createCommand($sql, [
            ':card_id'       => $cardId,
            ':supplier_id'   => $supplierId,
            ':sku'           => $dto->supplierSku,
            ':price_min'     => $dto->getMinPrice(),
            ':price_max'     => $dto->getMaxPrice(),
            ':compare_price' => $comparePrice,
            ':in_stock'      => $dto->inStock ? 'true' : 'false',
            ':stock_status'  => $dto->stockStatus,
            ':description'   => $dto->description,
            ':attributes'    => json_encode($dto->attributes, JSON_UNESCAPED_UNICODE),
            ':images'        => json_encode($dto->imageUrls, JSON_UNESCAPED_UNICODE),
            ':variants'      => $variantsJson,
            ':variant_count' => count($dto->variants),
            ':checksum'      => $checksum,
            ':raw_data'      => json_encode($dto->rawData, JSON_UNESCAPED_UNICODE),
        ])->queryOne();

        return ($row['is_insert'] ?? false) ? 'offers_created' : 'offers_updated';
    }

    public function enqueueImages($db, int $cardId, array $imageUrls): void
    {
        if (empty($imageUrls)) return;

        $values = [];
        $params = [];
        foreach ($imageUrls as $idx => $url) {
            $values[] = "(:cid{$idx}, :url{$idx}, :sort{$idx}, :main{$idx}, 'pending')";
            $params[":cid{$idx}"] = $cardId;
            $params[":url{$idx}"] = $url;
            $params[":sort{$idx}"] = $idx;
            $params[":main{$idx}"] = $idx === 0 ? 'true' : 'false';
        }

        $sql = "INSERT INTO {{%card_images}} (card_id, source_url, sort_order, is_main, status) VALUES "
            . implode(', ', $values)
            . " ON CONFLICT (card_id, source_url) DO NOTHING";
        $db->createCommand($sql, $params)->execute();
    }

    protected function ensureSupplier($db): int
    {
        $id = $db->createCommand(
            "SELECT id FROM {{%suppliers}} WHERE code = :code LIMIT 1",
            [':code' => $this->supplierCode]
        )->queryScalar();

        if (!$id) {
            $db->createCommand()->insert('{{%suppliers}}', [
                'name'      => ucfirst($this->supplierCode),
                'code'      => $this->supplierCode,
                'is_active' => true,
                'format'    => 'xml',
            ])->execute();
            $id = $db->getLastInsertID('suppliers_id_seq');
        }

        return (int)$id;
    }

    protected function enqueueImageDownload(): void
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
        Yii::info("PersistStagedJob: поставлено " . count($chunks) . " заданий на скачку картинок", 'import');
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

    protected function slugify(string $text): string
    {
        $translitMap = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'j', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, $translitMap);
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        $text = preg_replace('/[\s-]+/', '-', $text);
        $text = trim($text, '-');
        if (strlen($text) > 200) $text = substr($text, 0, 200);
        return $text ?: 'product-' . uniqid();
    }
}
