<?php

namespace common\services;

use common\components\parsers\ParserRegistry;
use common\dto\ProductDTO;
use common\dto\VariantDTO;
use yii\base\Component;
use yii\db\Connection;
use Yii;

/**
 * Оркестратор импорта прайс-листов.
 *
 * Pipeline: parse → normalize → upsert product_cards + supplier_offers → enqueue images
 */
class ImportService extends Component
{
    public ?ParserRegistry $parserRegistry = null;

    private Connection $db;
    private array $supplierCache = [];
    private array $categoryCache = [];
    private array $brandCache = [];

    public function init(): void
    {
        parent::init();
        $this->db = Yii::$app->db;
        if ($this->parserRegistry === null) {
            $this->parserRegistry = Yii::$app->has('parserRegistry')
                ? Yii::$app->get('parserRegistry')
                : Yii::createObject(ParserRegistry::class);
        }
    }

    /**
     * Запустить импорт.
     */
    public function run(string $supplierCode, string $filePath, array $options = [], ?callable $progressCallback = null): array
    {
        $parser = $this->parserRegistry->get($supplierCode);
        if (!$parser) {
            throw new \RuntimeException("Парсер для поставщика '{$supplierCode}' не найден");
        }

        $supplierId = $this->ensureSupplier($supplierCode, $parser->getSupplierName());

        Yii::info("Импорт начат: supplier={$supplierCode} file={$filePath}", 'import');

        $stats = [
            'supplier' => $supplierCode,
            'supplier_id' => $supplierId,
            'status' => 'running',
            'cards_created' => 0,
            'cards_updated' => 0,
            'offers_created' => 0,
            'offers_updated' => 0,
            'variants_total' => 0,
            'skipped' => 0,
            'errors' => 0,
            'started_at' => microtime(true),
        ];

        $batchSize = (int)($options['batch_size'] ?? 100);
        $txBatchSize = (int)($options['tx_batch_size'] ?? 50); // товаров в одной транзакции
        $totalProducts = 0;
        $txBuffer = [];

        try {
            foreach ($parser->parse($filePath, $options) as $productDTO) {
                $txBuffer[] = $productDTO;

                if (count($txBuffer) >= $txBatchSize) {
                    $this->processBatch($txBuffer, $supplierId, $options, $stats);
                    $totalProducts += count($txBuffer);
                    $txBuffer = [];

                    if ($totalProducts % $batchSize === 0 && $progressCallback) {
                        $progressCallback($totalProducts, $stats);
                    }

                    // Периодическая очистка памяти
                    if ($totalProducts % 5000 === 0) {
                        gc_collect_cycles();
                    }
                }
            }

            // Остаток
            if (!empty($txBuffer)) {
                $this->processBatch($txBuffer, $supplierId, $options, $stats);
                $totalProducts += count($txBuffer);
            }

            $stats['status'] = $stats['errors'] > 0
                ? ($totalProducts > 0 ? 'partial_success' : 'failed')
                : 'completed';

        } catch (\Throwable $e) {
            $stats['status'] = 'failed';
            $stats['error'] = $e->getMessage();
            Yii::error("Импорт провалился: {$e->getMessage()}", 'import');
        }

        $stats['duration_seconds'] = round(microtime(true) - $stats['started_at'], 1);
        $stats['products_total'] = $totalProducts;
        unset($stats['started_at']);
        $stats['parser'] = $parser->getStats();

        // Обновляем last_import_at для поставщика
        $this->db->createCommand()->update(
            '{{%suppliers}}',
            ['last_import_at' => new \yii\db\Expression('NOW()')],
            ['code' => $supplierCode]
        )->execute();

        Yii::info("Импорт завершён: " . json_encode($stats, JSON_UNESCAPED_UNICODE), 'import');

        return $stats;
    }

    /**
     * Обработать пачку товаров в одной транзакции.
     */
    protected function processBatch(array $products, int $supplierId, array $options, array &$stats): void
    {
        $transaction = $this->db->beginTransaction();
        try {
            foreach ($products as $dto) {
                try {
                    $result = $this->processProduct($dto, $supplierId, $options);
                    $stats[$result['action']]++;
                    $stats[$result['offer_action']]++;
                    $stats['variants_total'] += $result['variants'];
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    if ($stats['errors'] <= 50) {
                        Yii::warning("Ошибка обработки товара: sku={$dto->supplierSku} error={$e->getMessage()}", 'import');
                    }
                }
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            // При ошибке пачки — откатимся к поштучной обработке
            Yii::warning("Batch transaction failed, fallback to single: {$e->getMessage()}", 'import');
            foreach ($products as $dto) {
                try {
                    $tx = $this->db->beginTransaction();
                    $result = $this->processProduct($dto, $supplierId, $options);
                    $tx->commit();
                    $stats[$result['action']]++;
                    $stats[$result['offer_action']]++;
                    $stats['variants_total'] += $result['variants'];
                } catch (\Throwable $innerEx) {
                    if (isset($tx)) $tx->rollBack();
                    $stats['errors']++;
                }
            }
        }
    }

    protected function processProduct(ProductDTO $dto, int $supplierId, array $options): array
    {
        $variantCount = count($dto->variants);

        $cardResult = $this->findOrCreateCard($dto);
        $isNewCard = ($cardResult['created'] ?? false);

        $offerResult = $this->upsertSupplierOffer($dto, $cardResult['id'], $supplierId);

        if (!empty($dto->imageUrls)) {
            $this->enqueueImages($cardResult['id'], $dto->imageUrls);
        }

        return [
            'action' => $isNewCard ? 'cards_created' : 'cards_updated',
            'offer_action' => $offerResult,
            'variants' => $variantCount,
        ];
    }

    protected function findOrCreateCard(ProductDTO $dto): array
    {
        $manufacturer = $dto->manufacturer ?? 'Unknown';
        $modelName = $dto->model ?? $dto->name;

        $existing = $this->db->createCommand(
            "SELECT id FROM {{%product_cards}} WHERE manufacturer = :m AND model = :model LIMIT 1",
            [':m' => $manufacturer, ':model' => $modelName]
        )->queryScalar();

        if ($existing) {
            $this->updateCard((int)$existing, $dto);
            return ['id' => (int)$existing, 'created' => false];
        }

        $categoryId = $this->resolveCategory($dto->categoryPath);
        $brandId = $this->resolveBrand($dto->manufacturer);
        $slug = $this->generateSlug($modelName, $manufacturer);
        $minPrice = $dto->getMinPrice();
        $maxPrice = $dto->getMaxPrice();

        $id = $this->db->createCommand()->insert('{{%product_cards}}', [
            'canonical_name' => $modelName,
            'slug' => $slug,
            'manufacturer' => $manufacturer,
            'model' => $modelName,
            'category_id' => $categoryId,
            'brand_id' => $brandId,
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
            'source_supplier' => 'ormatek',
            'status' => 'active',
            'quality_score' => 50,
            'is_published' => true,
            'has_active_offers' => $dto->inStock,
        ])->execute();

        $cardId = (int)$this->db->getLastInsertID('product_cards_id_seq');

        return ['id' => $cardId, 'created' => true];
    }

    protected function updateCard(int $cardId, ProductDTO $dto): void
    {
        $minPrice = $dto->getMinPrice();
        $maxPrice = $dto->getMaxPrice();
        $variantCount = count($dto->variants);

        $sql = "UPDATE {{%product_cards}} SET 
            updated_at = NOW(), 
            has_active_offers = true,
            is_in_stock = true";
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
        $params[':vc'] = $variantCount;

        if ($dto->description) {
            $sql .= ", description = COALESCE(NULLIF(description, ''), :desc)";
            $params[':desc'] = $dto->description;
        }

        if (!empty($dto->imageUrls)) {
            $sql .= ", canonical_images = CASE WHEN canonical_images = '[]'::jsonb OR canonical_images IS NULL THEN :imgs::jsonb ELSE canonical_images END";
            $sql .= ", image_count = GREATEST(COALESCE(image_count, 0), :ic)";
            $params[':imgs'] = json_encode($dto->imageUrls, JSON_UNESCAPED_UNICODE);
            $params[':ic'] = count($dto->imageUrls);
        }

        $sql .= " WHERE id = :id";

        $this->db->createCommand($sql, $params)->execute();
    }

    protected function upsertSupplierOffer(ProductDTO $dto, int $cardId, int $supplierId): string
    {
        $checksum = $dto->getChecksum();

        $variantsJson = json_encode(array_map(function (VariantDTO $v) {
            return [
                'sku' => $v->sku,
                'price' => $v->price,
                'compare_price' => $v->comparePrice,
                'in_stock' => $v->inStock,
                'stock_status' => $v->stockStatus,
                'options' => $v->options,
            ];
        }, $dto->variants), JSON_UNESCAPED_UNICODE);

        $imagesJson = json_encode($dto->imageUrls, JSON_UNESCAPED_UNICODE);
        $attributesJson = json_encode($dto->attributes, JSON_UNESCAPED_UNICODE);

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
                in_stock, stock_status,
                description, attributes_json, images_json,
                variants_json, variant_count,
                match_confidence, match_method,
                checksum, is_active,
                raw_data, created_at, updated_at
            ) VALUES (
                :card_id, :supplier_id, :sku,
                :price_min, :price_max, :compare_price,
                :in_stock, :stock_status,
                :description, :attributes::jsonb, :images::jsonb,
                :variants::jsonb, :variant_count,
                1.0, 'manufacturer_model',
                :checksum, true,
                :raw_data::jsonb, NOW(), NOW()
            )
            ON CONFLICT (supplier_id, supplier_sku)
            DO UPDATE SET
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

        $row = $this->db->createCommand($sql, [
            ':card_id' => $cardId,
            ':supplier_id' => $supplierId,
            ':sku' => $dto->supplierSku,
            ':price_min' => $dto->getMinPrice(),
            ':price_max' => $dto->getMaxPrice(),
            ':compare_price' => $comparePrice,
            ':in_stock' => $dto->inStock ? 'true' : 'false',
            ':stock_status' => $dto->stockStatus,
            ':description' => $dto->description,
            ':attributes' => $attributesJson,
            ':images' => $imagesJson,
            ':variants' => $variantsJson,
            ':variant_count' => count($dto->variants),
            ':checksum' => $checksum,
            ':raw_data' => json_encode($dto->rawData, JSON_UNESCAPED_UNICODE),
        ])->queryOne();

        return ($row['is_insert'] ?? false) ? 'offers_created' : 'offers_updated';
    }

    protected function resolveCategory(string $categoryPath): ?int
    {
        if (empty($categoryPath)) return null;

        if (isset($this->categoryCache[$categoryPath])) {
            return $this->categoryCache[$categoryPath];
        }

        $id = $this->db->createCommand(
            "SELECT id FROM {{%categories}} WHERE name = :name LIMIT 1",
            [':name' => $categoryPath]
        )->queryScalar();

        if (!$id) {
            $slug = $this->slugify($categoryPath);
            $id = $this->db->createCommand("
                INSERT INTO {{%categories}} (name, slug, is_active, sort_order) 
                VALUES (:name, :slug, true, 0)
                ON CONFLICT (slug) DO UPDATE SET name = EXCLUDED.name
                RETURNING id
            ", [':name' => $categoryPath, ':slug' => $slug])->queryScalar();
        }

        $this->categoryCache[$categoryPath] = (int)$id;
        return (int)$id;
    }

    protected function resolveBrand(?string $brandName): ?int
    {
        if (empty($brandName)) return null;

        if (isset($this->brandCache[$brandName])) {
            return $this->brandCache[$brandName];
        }

        $id = $this->db->createCommand(
            "SELECT id FROM {{%brands}} WHERE canonical_name = :name LIMIT 1",
            [':name' => $brandName]
        )->queryScalar();

        if (!$id) {
            $id = $this->db->createCommand(
                "SELECT brand_id FROM {{%brand_aliases}} WHERE alias_lower = :name LIMIT 1",
                [':name' => mb_strtolower($brandName, 'UTF-8')]
            )->queryScalar();
        }

        if (!$id) {
            $slug = $this->slugify($brandName);
            $id = $this->db->createCommand("
                INSERT INTO {{%brands}} (canonical_name, slug, is_active)
                VALUES (:name, :slug, true)
                ON CONFLICT (slug) DO UPDATE SET canonical_name = EXCLUDED.canonical_name
                RETURNING id
            ", [':name' => $brandName, ':slug' => $slug])->queryScalar();
        }

        $this->brandCache[$brandName] = (int)$id;
        return (int)$id;
    }

    protected function ensureSupplier(string $code, string $name): int
    {
        if (isset($this->supplierCache[$code])) {
            return $this->supplierCache[$code];
        }

        $id = $this->db->createCommand(
            "SELECT id FROM {{%suppliers}} WHERE code = :code LIMIT 1",
            [':code' => $code]
        )->queryScalar();

        if (!$id) {
            $this->db->createCommand()->insert('{{%suppliers}}', [
                'name' => $name,
                'code' => $code,
                'is_active' => true,
                'format' => 'xml',
            ])->execute();
            $id = $this->db->getLastInsertID('suppliers_id_seq');
        }

        $this->supplierCache[$code] = (int)$id;
        return (int)$id;
    }

    protected function enqueueImages(int $cardId, array $imageUrls): void
    {
        if (empty($imageUrls)) return;

        // Batch INSERT для картинок
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
        $this->db->createCommand($sql, $params)->execute();

        $this->db->createCommand("
            UPDATE {{%product_cards}} SET images_status = 'pending' 
            WHERE id = :id AND images_status IS DISTINCT FROM 'completed'
        ", [':id' => $cardId])->execute();
    }

    protected function generateSlug(string $name, string $manufacturer): string
    {
        return $this->slugify($manufacturer . '-' . $name);
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

        if (strlen($text) > 200) {
            $text = substr($text, 0, 200);
            $text = rtrim($text, '-');
        }

        return $text ?: 'product-' . uniqid();
    }
}
