<?php

namespace common\jobs;

use common\services\ImportService;
use common\services\ImportStagingService;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Фаза 4: Bulk-запись из Redis staging → PostgreSQL.
 *
 * Итерирует по нормализованным товарам в Redis, конвертирует
 * обратно в ProductDTO и записывает через ImportService.
 *
 * Преимущества:
 * - Данные уже нормализованы (бренды, категории, названия)
 * - Bulk-запись батчами (50 за транзакцию)
 * - Redis staging очищается после завершения
 *
 * После завершения ставит в очередь:
 * - DownloadImagesJob (картинки)
 * - AI-обогащение (бренды, категории, описания)
 */
class PersistStagedJob extends BaseObject implements JobInterface
{
    public string $taskId;
    public string $supplierCode;

    /** @var bool Скачивать ли картинки */
    public bool $downloadImages = true;

    /** @var bool Запускать ли AI-обогащение */
    public bool $runAIEnrichment = true;

    /** @var int Размер батча для записи в БД */
    public int $batchSize = 50;

    public function execute($queue): void
    {
        Yii::info("PersistStagedJob: старт taskId={$this->taskId}", 'import');

        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');

        /** @var ImportService $importService */
        $importService = Yii::$app->get('importService');

        $staging->setStatus($this->taskId, 'persisting');
        $meta = $staging->getMeta($this->taskId);
        $startTime = microtime(true);

        $stats = [
            'cards_created' => 0,
            'cards_updated' => 0,
            'offers_created' => 0,
            'offers_updated' => 0,
            'variants_total' => 0,
            'errors' => 0,
            'persisted' => 0,
        ];

        $db = Yii::$app->db;
        $supplierId = $this->ensureSupplier($db);

        $batchBuffer = [];
        $totalProcessed = 0;

        $itemCount = $staging->getItemCount($this->taskId);
        Yii::info("PersistStagedJob: items in staging = {$itemCount}", 'import');

        try {
            $iterCount = 0;
            foreach ($staging->iterateProducts($this->taskId, 300) as $sku => $data) {
                $iterCount++;
                try {
                    $dto = $staging->deserializeToDTO($data);
                    $batchBuffer[] = $dto;

                    if (count($batchBuffer) >= $this->batchSize) {
                        $this->persistBatch($importService, $batchBuffer, $supplierId, $stats);
                        $totalProcessed += count($batchBuffer);
                        $batchBuffer = [];

                        if ($totalProcessed % 200 === 0) {
                            $elapsed = round(microtime(true) - $startTime, 1);
                            $ratePerSec = $totalProcessed > 0 ? round($totalProcessed / $elapsed) : 0;
                            Yii::info(
                                "PersistStagedJob: прогресс persisted={$totalProcessed}/{$itemCount} " .
                                "rate={$ratePerSec}/s elapsed={$elapsed}s " .
                                "cards_c={$stats['cards_created']} cards_u={$stats['cards_updated']}",
                                'import'
                            );
                        }

                        if ($totalProcessed % 2000 === 0) {
                            gc_collect_cycles();
                        }
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    if ($stats['errors'] <= 30) {
                        Yii::warning("PersistStagedJob: ошибка SKU={$sku}: {$e->getMessage()}", 'import');
                    }
                }
            }

            Yii::info("PersistStagedJob: iterator yielded {$iterCount} items, processed {$totalProcessed}", 'import');

            // Остаток
            if (!empty($batchBuffer)) {
                $this->persistBatch($importService, $batchBuffer, $supplierId, $stats);
                $totalProcessed += count($batchBuffer);
            }

        } catch (\Throwable $e) {
            Yii::error("PersistStagedJob: критическая ошибка — {$e->getMessage()}", 'import');
            $stats['errors']++;
        }

        $duration = round(microtime(true) - $startTime, 1);

        // Обновляем last_import_at для поставщика
        $db->createCommand()->update(
            '{{%suppliers}}',
            ['last_import_at' => new \yii\db\Expression('NOW()')],
            ['code' => $this->supplierCode]
        )->execute();

        $staging->updateMeta($this->taskId, [
            'status' => 'completed',
            'persisted_at' => date('Y-m-d H:i:s'),
            'persist_duration_sec' => $duration,
            'persist_stats' => $stats,
        ]);

        Yii::info(
            "PersistStagedJob: завершён — " .
            "created={$stats['cards_created']} updated={$stats['cards_updated']} " .
            "offers_new={$stats['offers_created']} offers_upd={$stats['offers_updated']} " .
            "variants={$stats['variants_total']} errors={$stats['errors']} " .
            "time={$duration}s",
            'import'
        );

        // Фоновые задачи
        $hasNewCards = ($stats['cards_created'] > 0 || $stats['cards_updated'] > 0);

        if ($this->downloadImages && $hasNewCards) {
            $this->enqueueImageDownload();
        }

        if ($this->runAIEnrichment && $hasNewCards) {
            $this->enqueueAIProcessing();
        }

        // Очистка Redis staging (через 1 час, на случай дебага)
        $staging->updateMeta($this->taskId, [
            'cleanup_scheduled_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        // Можно оставить staging на время, если нужна повторная обработка
        // $staging->cleanup($this->taskId);

        // Сокращаем TTL до 2 часов (данные уже в БД)
        $staging->refreshTtl($this->taskId);
    }

    /**
     * Записать батч товаров в PostgreSQL.
     * Каждый товар в своём SAVEPOINT, чтобы одна ошибка не убивала всю транзакцию.
     */
    protected function persistBatch(ImportService $importService, array $dtos, int $supplierId, array &$stats): void
    {
        $db = Yii::$app->db;

        // Каждый товар в своей транзакции — надёжнее при unique constraint violations
        foreach ($dtos as $dto) {
            try {
                $tx = $db->beginTransaction();
                $result = $this->persistProduct($importService, $dto, $supplierId);
                $tx->commit();
                $stats[$result['action']]++;
                $stats[$result['offer_action']]++;
                $stats['variants_total'] += $result['variants'];
                $stats['persisted']++;
            } catch (\Throwable $e) {
                if (isset($tx) && $tx->getIsActive()) {
                    $tx->rollBack();
                }
                $stats['errors']++;
                if ($stats['errors'] <= 30) {
                    Yii::warning(
                        "PersistStagedJob: ошибка persist sku={$dto->supplierSku}: {$e->getMessage()}",
                        'import'
                    );
                }
            }
        }
    }

    /**
     * Сохранить один товар в БД (карточка + оффер + картинки).
     */
    public function persistProduct(?ImportService $importService, $dto, int $supplierId): array
    {
        // Используем рефлексию, чтобы вызвать protected-метод ImportService
        // Или можно сделать processProduct публичным.
        // Пока используем прямой SQL для максимальной скорости.

        $variantCount = count($dto->variants);
        $db = Yii::$app->db;

        // Ищем карточку по manufacturer + model
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

        $offerAction = $this->upsertOffer($db, $dto, (int)$cardId, $supplierId);

        // Ставим картинки в очередь
        if (!empty($dto->imageUrls)) {
            $this->enqueueImages($db, (int)$cardId, $dto->imageUrls);
        }

        return [
            'action' => $isNewCard ? 'cards_created' : 'cards_updated',
            'offer_action' => $offerAction,
            'variants' => $variantCount,
        ];
    }

    public function createCard($db, $dto): int
    {
        $manufacturer = $dto->manufacturer ?? 'Unknown';
        $modelName = $dto->model ?? $dto->name;
        $baseSlug = $this->slugify($manufacturer . '-' . $modelName);

        // Уникальный slug: проверяем, если уже есть — добавляем суффикс
        $slug = $baseSlug;
        $suffix = 0;
        while ($db->createCommand("SELECT 1 FROM {{%product_cards}} WHERE slug = :slug", [':slug' => $slug])->queryScalar()) {
            $suffix++;
            $slug = $baseSlug . '-' . $suffix;
        }

        $minPrice = $dto->getMinPrice();
        $maxPrice = $dto->getMaxPrice();

        $db->createCommand()->insert('{{%product_cards}}', [
            'canonical_name' => $dto->name,
            'slug' => $slug,
            'manufacturer' => $manufacturer,
            'model' => $modelName,
            'description' => $dto->description,
            'canonical_attributes' => json_encode($dto->attributes, JSON_UNESCAPED_UNICODE),
            'canonical_images' => json_encode($dto->imageUrls, JSON_UNESCAPED_UNICODE),
            'price_range_min' => $minPrice,
            'price_range_max' => $maxPrice,
            'best_price' => $minPrice,
            'total_variants' => count($dto->variants),
            'image_count' => count($dto->imageUrls),
            'is_in_stock' => $dto->inStock,
            'supplier_count' => 1,
            'source_supplier' => $this->supplierCode,
            'status' => 'active',
            'quality_score' => 50,
            'is_published' => true,
            'has_active_offers' => $dto->inStock,
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

    public function upsertOffer($db, $dto, int $cardId, int $supplierId): string
    {
        $variantsJson = json_encode(array_map(fn($v) => [
            'sku' => $v->sku,
            'price' => $v->price,
            'compare_price' => $v->comparePrice,
            'in_stock' => $v->inStock,
            'stock_status' => $v->stockStatus,
            'options' => $v->options,
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
            ':card_id' => $cardId,
            ':supplier_id' => $supplierId,
            ':sku' => $dto->supplierSku,
            ':price_min' => $dto->getMinPrice(),
            ':price_max' => $dto->getMaxPrice(),
            ':compare_price' => $comparePrice,
            ':in_stock' => $dto->inStock ? 'true' : 'false',
            ':stock_status' => $dto->stockStatus,
            ':description' => $dto->description,
            ':attributes' => json_encode($dto->attributes, JSON_UNESCAPED_UNICODE),
            ':images' => json_encode($dto->imageUrls, JSON_UNESCAPED_UNICODE),
            ':variants' => $variantsJson,
            ':variant_count' => count($dto->variants),
            ':checksum' => $checksum,
            ':raw_data' => json_encode($dto->rawData, JSON_UNESCAPED_UNICODE),
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
                'name' => ucfirst($this->supplierCode),
                'code' => $this->supplierCode,
                'is_active' => true,
                'format' => 'xml',
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
                'cardIds' => $chunk,
                'supplierCode' => $this->supplierCode,
            ]));
        }
        Yii::info("PersistStagedJob: поставлено " . count($chunks) . " заданий на скачку картинок", 'import');
    }

    protected function enqueueAIProcessing(): void
    {
        $db = Yii::$app->db;

        // Бренды без brand_id
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

        // Без категории
        $uncategorizedIds = $db->createCommand("
            SELECT id FROM {{%product_cards}}
            WHERE category_id IS NULL ORDER BY created_at DESC LIMIT 200
        ")->queryColumn();

        if (!empty($uncategorizedIds)) {
            foreach (array_chunk($uncategorizedIds, 10) as $chunk) {
                Yii::$app->queue->push(new CategorizeCardsJob(['cardIds' => $chunk]));
            }
        }

        // Обогащение низкокачественных карточек
        $lowQualityIds = $db->createCommand("
            SELECT id FROM {{%product_cards}}
            WHERE quality_score < 50 ORDER BY quality_score ASC, created_at DESC LIMIT 50
        ")->queryColumn();

        foreach ($lowQualityIds as $cardId) {
            Yii::$app->queue->push(new EnrichCardJob(['cardId' => (int)$cardId]));
        }

        $totalJobs = count($unbrandedIds) + count($uncategorizedIds) + count($lowQualityIds);
        if ($totalJobs > 0) {
            Yii::info("PersistStagedJob: поставлено AI-заданий: бренды=" . count($unbrandedIds) .
                " категории=" . count($uncategorizedIds) . " обогащение=" . count($lowQualityIds), 'import');
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
