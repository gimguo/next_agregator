<?php

namespace common\services;

use common\dto\ProductDTO;
use common\dto\VariantDTO;
use yii\base\Component;
use yii\redis\Connection as RedisConnection;
use Yii;

/**
 * Сервис staging-хранилища импорта в Redis.
 *
 * Хранит распарсенные товары во временном Redis-хранилище,
 * позволяя обрабатывать, нормализовать и обогащать их перед
 * записью в PostgreSQL.
 *
 * Структура ключей:
 *   import:{taskId}:meta       → JSON: {supplier_code, file_path, status, ...}
 *   import:{taskId}:items      → Hash: { supplierSku → JSON(ProductDTO) }
 *   import:{taskId}:recipe     → JSON: AI-рецепт для нормализации
 *   import:{taskId}:brands     → Set: уникальные бренды
 *   import:{taskId}:categories → Set: уникальные категории
 */
class ImportStagingService extends Component
{
    /** @var int TTL в секундах (24 часа) */
    public int $ttl = 86400;

    /** @var string Префикс ключей Redis */
    public string $prefix = 'import';

    private ?RedisConnection $redis = null;

    public function init(): void
    {
        parent::init();
        $this->redis = Yii::$app->redis;
    }

    // ═══════════════════════════════════════════
    // TASK MANAGEMENT
    // ═══════════════════════════════════════════

    /**
     * Создать новую задачу импорта.
     *
     * @return string taskId
     */
    public function createTask(string $supplierCode, string $filePath, array $options = []): string
    {
        $taskId = $supplierCode . ':' . date('Ymd_His') . ':' . substr(uniqid(), -6);

        $meta = [
            'task_id' => $taskId,
            'supplier_code' => $supplierCode,
            'file_path' => $filePath,
            'options' => $options,
            'status' => 'created',
            'created_at' => date('Y-m-d H:i:s'),
            'parsed_at' => null,
            'analyzed_at' => null,
            'normalized_at' => null,
            'persisted_at' => null,
            'total_items' => 0,
            'errors' => 0,
        ];

        $this->redis->executeCommand('SET', [
            $this->key($taskId, 'meta'),
            json_encode($meta, JSON_UNESCAPED_UNICODE),
            'EX', $this->ttl,
        ]);

        return $taskId;
    }

    /**
     * Получить мета-данные задачи.
     */
    public function getMeta(string $taskId): ?array
    {
        $data = $this->redis->executeCommand('GET', [$this->key($taskId, 'meta')]);
        return $data ? json_decode($data, true) : null;
    }

    /**
     * Обновить мета-данные.
     * Если meta не существует — создаёт заново с базовыми полями.
     */
    public function updateMeta(string $taskId, array $updates): void
    {
        $meta = $this->getMeta($taskId);
        if (!$meta) {
            // Создаём минимальный meta, если потерялся (TTL expired)
            $meta = [
                'task_id' => $taskId,
                'status' => 'unknown',
                'created_at' => null,
            ];
        }

        $meta = array_merge($meta, $updates);
        $this->redis->executeCommand('SET', [
            $this->key($taskId, 'meta'),
            json_encode($meta, JSON_UNESCAPED_UNICODE),
            'EX', $this->ttl,
        ]);
    }

    /**
     * Установить статус задачи.
     */
    public function setStatus(string $taskId, string $status): void
    {
        $this->updateMeta($taskId, ['status' => $status]);
    }

    // ═══════════════════════════════════════════
    // STAGING ITEMS
    // ═══════════════════════════════════════════

    /**
     * Сохранить товар в Redis staging.
     */
    public function stageProduct(string $taskId, ProductDTO $dto): void
    {
        $serialized = $this->serializeProductDTO($dto);
        $key = $this->key($taskId, 'items');

        $this->redis->executeCommand('HSET', [
            $key,
            $dto->supplierSku,
            json_encode($serialized, JSON_UNESCAPED_UNICODE),
        ]);

        // Собираем уникальные бренды и категории
        if ($dto->manufacturer) {
            $this->redis->executeCommand('SADD', [$this->key($taskId, 'brands'), $dto->manufacturer]);
        }
        if ($dto->brand && $dto->brand !== $dto->manufacturer) {
            $this->redis->executeCommand('SADD', [$this->key($taskId, 'brands'), $dto->brand]);
        }
        if ($dto->categoryPath) {
            $this->redis->executeCommand('SADD', [$this->key($taskId, 'categories'), $dto->categoryPath]);
        }
    }

    /**
     * Пакетное сохранение товаров в Redis (эффективнее по штучке).
     *
     * @param ProductDTO[] $dtos
     */
    public function stageBatch(string $taskId, array $dtos): void
    {
        if (empty($dtos)) return;

        $itemsKey = $this->key($taskId, 'items');
        $brandsKey = $this->key($taskId, 'brands');
        $categoriesKey = $this->key($taskId, 'categories');

        // Формируем аргументы для HSET (multiple field-value pairs)
        $hsetArgs = [$itemsKey];
        $brands = [];
        $categories = [];

        foreach ($dtos as $dto) {
            $serialized = $this->serializeProductDTO($dto);
            $hsetArgs[] = $dto->supplierSku;
            $hsetArgs[] = json_encode($serialized, JSON_UNESCAPED_UNICODE);

            if ($dto->manufacturer) $brands[$dto->manufacturer] = true;
            if ($dto->brand && $dto->brand !== $dto->manufacturer) $brands[$dto->brand] = true;
            if ($dto->categoryPath) $categories[$dto->categoryPath] = true;
        }

        // Пакетный HSET
        $this->redis->executeCommand('HSET', $hsetArgs);

        // Устанавливаем TTL для Hash (только первый раз)
        $ttlCurrent = $this->redis->executeCommand('TTL', [$itemsKey]);
        if ($ttlCurrent < 0) {
            $this->redis->executeCommand('EXPIRE', [$itemsKey, $this->ttl]);
        }

        // Пакетный SADD для брендов
        if (!empty($brands)) {
            $brandArgs = [$brandsKey, ...array_keys($brands)];
            $this->redis->executeCommand('SADD', $brandArgs);
            $this->redis->executeCommand('EXPIRE', [$brandsKey, $this->ttl]);
        }

        // Пакетный SADD для категорий
        if (!empty($categories)) {
            $catArgs = [$categoriesKey, ...array_keys($categories)];
            $this->redis->executeCommand('SADD', $catArgs);
            $this->redis->executeCommand('EXPIRE', [$categoriesKey, $this->ttl]);
        }
    }

    /**
     * Сохранить уже сериализованный товар (после нормализации).
     */
    public function stageRaw(string $taskId, string $sku, array $data): void
    {
        $this->redis->executeCommand('HSET', [
            $this->key($taskId, 'items'),
            $sku,
            json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Получить один товар по SKU.
     */
    public function getProduct(string $taskId, string $sku): ?array
    {
        $data = $this->redis->executeCommand('HGET', [
            $this->key($taskId, 'items'),
            $sku,
        ]);
        return $data ? json_decode($data, true) : null;
    }

    /**
     * Количество товаров в staging.
     */
    public function getItemCount(string $taskId): int
    {
        return (int)$this->redis->executeCommand('HLEN', [$this->key($taskId, 'items')]);
    }

    /**
     * Получить сэмпл товаров для AI-анализа.
     *
     * @param int $count Количество товаров
     * @return array Массив сериализованных товаров
     */
    public function getSample(string $taskId, int $count = 50): array
    {
        $key = $this->key($taskId, 'items');
        $sample = [];
        $cursor = '0';
        $fetched = 0;

        do {
            $result = $this->redis->executeCommand('HSCAN', [$key, $cursor, 'COUNT', min($count * 2, 200)]);
            $cursor = (string)$result[0];
            $pairs = $result[1] ?? [];

            for ($i = 0; $i < count($pairs); $i += 2) {
                if ($fetched >= $count) break 2;
                $decoded = json_decode($pairs[$i + 1], true);
                if ($decoded !== null) {
                    $sample[] = $decoded;
                    $fetched++;
                }
            }
        } while ($cursor !== '0');

        return $sample;
    }

    /**
     * Итератор по всем товарам (HSCAN, memory-friendly).
     *
     * @return \Generator<string, array> sku → data
     */
    public function iterateProducts(string $taskId, int $batchSize = 200): \Generator
    {
        $key = $this->key($taskId, 'items');
        $cursor = '0';
        $first = true;

        do {
            $result = $this->redis->executeCommand('HSCAN', [$key, $cursor, 'COUNT', $batchSize]);
            $cursor = (string)$result[0]; // Redis может вернуть int или string
            $pairs = $result[1] ?? [];

            for ($i = 0; $i < count($pairs); $i += 2) {
                $sku = $pairs[$i];
                $data = json_decode($pairs[$i + 1], true);
                if ($data !== null) {
                    yield $sku => $data;
                }
            }
            $first = false;
        } while ($cursor !== '0');
    }

    /**
     * Получить батч товаров (для bulk persist).
     *
     * @return array [[sku => data], ...] + nextCursor
     */
    public function getBatch(string $taskId, string $cursor = '0', int $batchSize = 100): array
    {
        $key = $this->key($taskId, 'items');
        $result = $this->redis->executeCommand('HSCAN', [$key, $cursor, 'COUNT', $batchSize]);

        $nextCursor = (string)$result[0];
        $pairs = $result[1] ?? [];
        $items = [];

        for ($i = 0; $i < count($pairs); $i += 2) {
            $decoded = json_decode($pairs[$i + 1], true);
            if ($decoded !== null) {
                $items[$pairs[$i]] = $decoded;
            }
        }

        return [
            'items' => $items,
            'cursor' => $nextCursor,
            'done' => ($nextCursor === '0'),
        ];
    }

    // ═══════════════════════════════════════════
    // BRANDS & CATEGORIES
    // ═══════════════════════════════════════════

    /**
     * Все уникальные бренды.
     */
    public function getBrands(string $taskId): array
    {
        $result = $this->redis->executeCommand('SMEMBERS', [$this->key($taskId, 'brands')]);
        return $result ?: [];
    }

    /**
     * Все уникальные категории.
     */
    public function getCategories(string $taskId): array
    {
        $result = $this->redis->executeCommand('SMEMBERS', [$this->key($taskId, 'categories')]);
        return $result ?: [];
    }

    // ═══════════════════════════════════════════
    // AI RECIPE
    // ═══════════════════════════════════════════

    /**
     * Сохранить AI-рецепт нормализации.
     */
    public function setRecipe(string $taskId, array $recipe): void
    {
        $this->redis->executeCommand('SET', [
            $this->key($taskId, 'recipe'),
            json_encode($recipe, JSON_UNESCAPED_UNICODE),
            'EX', $this->ttl,
        ]);
    }

    /**
     * Получить AI-рецепт.
     */
    public function getRecipe(string $taskId): ?array
    {
        $data = $this->redis->executeCommand('GET', [$this->key($taskId, 'recipe')]);
        return $data ? json_decode($data, true) : null;
    }

    // ═══════════════════════════════════════════
    // STATS & CLEANUP
    // ═══════════════════════════════════════════

    /**
     * Полная статистика по задаче.
     */
    public function getTaskStats(string $taskId): array
    {
        $meta = $this->getMeta($taskId) ?? [];
        return [
            'task_id' => $taskId,
            'status' => $meta['status'] ?? 'unknown',
            'supplier_code' => $meta['supplier_code'] ?? '',
            'total_items' => $this->getItemCount($taskId),
            'unique_brands' => (int)$this->redis->executeCommand('SCARD', [$this->key($taskId, 'brands')]),
            'unique_categories' => (int)$this->redis->executeCommand('SCARD', [$this->key($taskId, 'categories')]),
            'has_recipe' => $this->redis->executeCommand('EXISTS', [$this->key($taskId, 'recipe')]) > 0,
            'created_at' => $meta['created_at'] ?? null,
        ];
    }

    /**
     * Обновить TTL всех ключей задачи.
     */
    public function refreshTtl(string $taskId): void
    {
        $suffixes = ['meta', 'items', 'recipe', 'brands', 'categories'];
        foreach ($suffixes as $suffix) {
            $key = $this->key($taskId, $suffix);
            if ($this->redis->executeCommand('EXISTS', [$key])) {
                $this->redis->executeCommand('EXPIRE', [$key, $this->ttl]);
            }
        }
    }

    /**
     * Удалить все данные задачи из Redis.
     */
    public function cleanup(string $taskId): void
    {
        $keys = [];
        $suffixes = ['meta', 'items', 'recipe', 'brands', 'categories'];
        foreach ($suffixes as $suffix) {
            $keys[] = $this->key($taskId, $suffix);
        }
        $this->redis->executeCommand('DEL', $keys);
    }

    /**
     * Найти все активные задачи импорта.
     */
    public function getActiveTasks(): array
    {
        $pattern = "{$this->prefix}:*:meta";
        $tasks = [];
        $cursor = '0';

        do {
            $result = $this->redis->executeCommand('SCAN', [$cursor, 'MATCH', $pattern, 'COUNT', 100]);
            $cursor = (string)$result[0];
            $keys = $result[1] ?? [];

            foreach ($keys as $key) {
                $data = $this->redis->executeCommand('GET', [$key]);
                if ($data) {
                    $meta = json_decode($data, true);
                    if ($meta) $tasks[] = $meta;
                }
            }
        } while ($cursor !== '0');

        return $tasks;
    }

    // ═══════════════════════════════════════════
    // SERIALIZATION
    // ═══════════════════════════════════════════

    /**
     * Сериализовать ProductDTO в массив для Redis.
     */
    public function serializeProductDTO(ProductDTO $dto): array
    {
        return [
            'sku' => $dto->supplierSku,
            'name' => $dto->name,
            'category_path' => $dto->categoryPath,
            'manufacturer' => $dto->manufacturer,
            'brand' => $dto->brand,
            'model' => $dto->model,
            'description' => $dto->description,
            'short_description' => $dto->shortDescription,
            'price' => $dto->price,
            'compare_price' => $dto->comparePrice,
            'in_stock' => $dto->inStock,
            'stock_quantity' => $dto->stockQuantity,
            'stock_status' => $dto->stockStatus,
            'attributes' => $dto->attributes,
            'image_urls' => $dto->imageUrls,
            'variants' => array_map(fn(VariantDTO $v) => [
                'sku' => $v->sku,
                'price' => $v->price,
                'compare_price' => $v->comparePrice,
                'in_stock' => $v->inStock,
                'stock_quantity' => $v->stockQuantity,
                'stock_status' => $v->stockStatus,
                'options' => $v->options,
                'image_urls' => $v->imageUrls,
            ], $dto->variants),
            'raw_data' => $dto->rawData,
            // Нормализация (заполняется AI/recipe)
            '_normalized' => false,
            '_canonical_name' => null,
            '_brand_canonical' => null,
            '_category_mapped' => null,
            '_product_type' => null,
        ];
    }

    /**
     * Десериализовать массив обратно в ProductDTO.
     */
    public function deserializeToDTO(array $data): ProductDTO
    {
        $variants = array_map(fn(array $v) => new VariantDTO(
            sku: $v['sku'] ?? null,
            price: (float)($v['price'] ?? 0),
            comparePrice: $v['compare_price'] ?? null,
            inStock: (bool)($v['in_stock'] ?? true),
            stockQuantity: $v['stock_quantity'] ?? null,
            stockStatus: $v['stock_status'] ?? 'available',
            options: $v['options'] ?? [],
            imageUrls: $v['image_urls'] ?? [],
        ), $data['variants'] ?? []);

        return new ProductDTO(
            supplierSku: $data['sku'] ?? '',
            name: $data['_canonical_name'] ?? $data['name'] ?? '',
            categoryPath: $data['_category_mapped'] ?? $data['category_path'] ?? '',
            manufacturer: $data['_brand_canonical'] ?? $data['manufacturer'] ?? null,
            brand: $data['_brand_canonical'] ?? $data['brand'] ?? null,
            model: $data['model'] ?? null,
            description: $data['description'] ?? null,
            shortDescription: $data['short_description'] ?? null,
            price: $data['price'] ?? null,
            comparePrice: $data['compare_price'] ?? null,
            inStock: (bool)($data['in_stock'] ?? true),
            stockQuantity: $data['stock_quantity'] ?? null,
            stockStatus: $data['stock_status'] ?? 'available',
            attributes: $data['attributes'] ?? [],
            imageUrls: $data['image_urls'] ?? [],
            variants: $variants,
            rawData: $data['raw_data'] ?? [],
        );
    }

    // ═══════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════

    private function key(string $taskId, string $suffix): string
    {
        return "{$this->prefix}:{$taskId}:{$suffix}";
    }
}
