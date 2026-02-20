<?php

namespace common\services;

use common\dto\ProductDTO;
use common\dto\VariantDTO;
use yii\base\Component;
use yii\db\Connection;
use Yii;

/**
 * Сервис staging-хранилища импорта в PostgreSQL UNLOGGED TABLE.
 *
 * Заменяет Redis-staging на PostgreSQL для:
 *   - Неограниченного объёма (Redis ≤ 256MB vs PostgreSQL ≤ диск)
 *   - Полноценных SQL-запросов (WHERE, ORDER BY, LIMIT, агрегации)
 *   - Cursor-based итерации без HSCAN-проблем
 *   - Сохранения данных между рестартами воркера (не теряется при Redis flush)
 *
 * UNLOGGED TABLE — без WAL, INSERT в 3-5x быстрее, данные живут до краша PostgreSQL.
 * Для staging это идеально: данные временные, потеря при краше = повторный парсинг.
 *
 * Структура:
 *   import_sessions     — метаданные сессий (статус, счётчики, время)
 *   staging_raw_offers  — UNLOGGED, сырые и нормализованные данные товаров
 *
 * Пример:
 *   $staging = Yii::$app->get('importStaging');
 *   $sessionId = $staging->createSession('ormatek', 1, '/app/prices/All.xml');
 *   $staging->insertBatch($sessionId, 1, $productDTOs);      // Фаза 1
 *   $sample = $staging->getSample($sessionId, 50);             // Фаза 2
 *   $staging->normalizeItem($id, $normalizedData);             // Фаза 3
 *   foreach ($staging->iteratePending($sessionId) as $row) {}  // Фаза 4
 */
class ImportStagingService extends Component
{
    /** @var int Авто-очистка сессий старше N часов */
    public int $cleanupHours = 48;

    private ?Connection $db = null;

    public function init(): void
    {
        parent::init();
        $this->db = Yii::$app->db;
    }

    // ═══════════════════════════════════════════
    // SESSION MANAGEMENT
    // ═══════════════════════════════════════════

    /**
     * Создать новую сессию импорта.
     *
     * @return string sessionId (уникальный идентификатор)
     */
    public function createSession(string $supplierCode, int $supplierId, string $filePath, array $options = []): string
    {
        $sessionId = $supplierCode . ':' . date('Ymd_His') . ':' . substr(uniqid(), -6);

        $this->db->createCommand()->insert('{{%import_sessions}}', [
            'session_id'    => $sessionId,
            'supplier_id'   => $supplierId,
            'supplier_code' => $supplierCode,
            'file_path'     => $filePath,
            'options'       => new \yii\db\JsonExpression($options),
            'status'        => 'created',
            'started_at'    => new \yii\db\Expression('NOW()'),
        ])->execute();

        return $sessionId;
    }

    /**
     * Получить метаданные сессии.
     */
    public function getSession(string $sessionId): ?array
    {
        return $this->db->createCommand(
            "SELECT * FROM {{%import_sessions}} WHERE session_id = :sid",
            [':sid' => $sessionId]
        )->queryOne() ?: null;
    }

    /**
     * Обновить статус сессии.
     */
    public function setStatus(string $sessionId, string $status): void
    {
        $updates = ['status' => $status, 'updated_at' => new \yii\db\Expression('NOW()')];

        // Автоматически проставляем временные метки по фазам
        $tsField = match ($status) {
            'parsed'     => 'parsed_at',
            'analyzed'   => 'analyzed_at',
            'normalized' => 'normalized_at',
            'completed'  => 'completed_at',
            default      => null,
        };
        if ($tsField) {
            $updates[$tsField] = new \yii\db\Expression('NOW()');
        }

        $this->db->createCommand()->update(
            '{{%import_sessions}}', $updates, ['session_id' => $sessionId]
        )->execute();
    }

    /**
     * Обновить статистику сессии (merge в stats JSONB).
     */
    public function updateStats(string $sessionId, array $data): void
    {
        $session = $this->getSession($sessionId);
        $rawStats = $session['stats'] ?? '{}';
        if (is_string($rawStats)) {
            $currentStats = json_decode($rawStats, true) ?: [];
        } else {
            $currentStats = (array)$rawStats;
        }
        $merged = array_merge($currentStats, $data);

        $updates = [
            'stats'      => new \yii\db\JsonExpression($merged),
            'updated_at' => new \yii\db\Expression('NOW()'),
        ];

        // Обновляем счётчики если переданы
        foreach (['total_items', 'parsed_items', 'normalized_items', 'persisted_items', 'error_count'] as $field) {
            if (isset($data[$field])) {
                $updates[$field] = $data[$field];
            }
        }

        if (isset($data['error_message'])) {
            $updates['error_message'] = $data['error_message'];
        }

        $this->db->createCommand()->update(
            '{{%import_sessions}}', $updates, ['session_id' => $sessionId]
        )->execute();
    }

    /**
     * Получить все активные/недавние сессии.
     */
    public function getActiveSessions(int $limit = 20): array
    {
        return $this->db->createCommand("
            SELECT s.*, 
                   (SELECT COUNT(*) FROM {{%staging_raw_offers}} WHERE import_session_id = s.session_id) as staging_count
            FROM {{%import_sessions}} s
            WHERE s.created_at > NOW() - INTERVAL '{$this->cleanupHours} hours'
            ORDER BY s.created_at DESC
            LIMIT :limit
        ", [':limit' => $limit])->queryAll();
    }

    // ═══════════════════════════════════════════
    // STAGING ITEMS — BATCH INSERT
    // ═══════════════════════════════════════════

    /**
     * Batch-вставка товаров в staging (пачками по 500-1000).
     *
     * @param ProductDTO[] $dtos
     * @return int Количество вставленных строк
     */
    public function insertBatch(string $sessionId, int $supplierId, array $dtos): int
    {
        if (empty($dtos)) return 0;

        // Используем raw SQL для корректной записи JSONB
        $values = [];
        $params = [];
        $idx = 0;

        foreach ($dtos as $dto) {
            $serialized = $this->serializeProductDTO($dto);
            $jsonData = json_encode($serialized, JSON_UNESCAPED_UNICODE);
            $rawHash = md5($jsonData);

            $values[] = "(:sid{$idx}, :sup{$idx}, :sku{$idx}, :hash{$idx}, :data{$idx}::jsonb, 'pending')";
            $params[":sid{$idx}"] = $sessionId;
            $params[":sup{$idx}"] = $supplierId;
            $params[":sku{$idx}"] = $dto->supplierSku;
            $params[":hash{$idx}"] = $rawHash;
            $params[":data{$idx}"] = $jsonData;

            $idx++;

            // Flush в пачках по 50 (меньше расход памяти на больших DTO)
            if ($idx >= 50) {
                $sql = "INSERT INTO {{%staging_raw_offers}} (import_session_id, supplier_id, supplier_sku, raw_hash, raw_data, status) VALUES " . implode(', ', $values);
                $this->db->createCommand($sql, $params)->execute();
                $values = [];
                $params = [];
                $idx = 0;
            }
        }

        // Остаток
        if (!empty($values)) {
            $sql = "INSERT INTO {{%staging_raw_offers}} (import_session_id, supplier_id, supplier_sku, raw_hash, raw_data, status) VALUES " . implode(', ', $values);
            $this->db->createCommand($sql, $params)->execute();
        }

        return count($dtos);
    }

    /**
     * Вставка одного товара в staging.
     */
    public function insertOne(string $sessionId, int $supplierId, ProductDTO $dto): int
    {
        $serialized = $this->serializeProductDTO($dto);
        $jsonData = json_encode($serialized, JSON_UNESCAPED_UNICODE);

        $this->db->createCommand(
            "INSERT INTO {{%staging_raw_offers}} (import_session_id, supplier_id, supplier_sku, raw_hash, raw_data, status)
             VALUES (:sid, :sup, :sku, :hash, :data::jsonb, 'pending')",
            [
                ':sid'  => $sessionId,
                ':sup'  => $supplierId,
                ':sku'  => $dto->supplierSku,
                ':hash' => md5($jsonData),
                ':data' => $jsonData,
            ]
        )->execute();

        return (int)$this->db->getLastInsertID('staging_raw_offers_id_seq');
    }

    // ═══════════════════════════════════════════
    // READING & ITERATION
    // ═══════════════════════════════════════════

    /**
     * Количество записей в staging для сессии.
     */
    public function getItemCount(string $sessionId, ?string $status = null): int
    {
        $sql = "SELECT COUNT(*) FROM {{%staging_raw_offers}} WHERE import_session_id = :sid";
        $params = [':sid' => $sessionId];

        if ($status !== null) {
            $sql .= " AND status = :status";
            $params[':status'] = $status;
        }

        return (int)$this->db->createCommand($sql, $params)->queryScalar();
    }

    /**
     * Получить сэмпл товаров для AI-анализа (случайная выборка).
     *
     * @return array Массив сериализованных товаров (raw_data)
     */
    public function getSample(string $sessionId, int $count = 50): array
    {
        $rows = $this->db->createCommand("
            SELECT raw_data FROM {{%staging_raw_offers}}
            WHERE import_session_id = :sid AND status = 'pending'
            ORDER BY RANDOM()
            LIMIT :limit
        ", [':sid' => $sessionId, ':limit' => $count])->queryAll();

        return array_map(fn($row) => json_decode($row['raw_data'], true), $rows);
    }

    /**
     * Cursor-based итерация по записям со статусом $status.
     *
     * Используем `WHERE id > :lastId ORDER BY id LIMIT :batch` —
     * стабильный cursor без проблем HSCAN.
     *
     * @return \Generator<int, array> id → row (id, raw_data, normalized_data, supplier_sku, status)
     */
    public function iterateByStatus(string $sessionId, string $status = 'pending', int $batchSize = 500): \Generator
    {
        $lastId = 0;

        while (true) {
            $rows = $this->db->createCommand("
                SELECT id, supplier_sku, raw_data, normalized_data, status
                FROM {{%staging_raw_offers}}
                WHERE import_session_id = :sid AND status = :status AND id > :lastId
                ORDER BY id
                LIMIT :limit
            ", [
                ':sid'    => $sessionId,
                ':status' => $status,
                ':lastId' => $lastId,
                ':limit'  => $batchSize,
            ])->queryAll();

            if (empty($rows)) break;

            foreach ($rows as $row) {
                $row['raw_data'] = json_decode($row['raw_data'], true);
                if ($row['normalized_data']) {
                    $row['normalized_data'] = json_decode($row['normalized_data'], true);
                }
                $lastId = (int)$row['id'];
                yield $lastId => $row;
            }
        }
    }

    /**
     * Итерация по нормализованным записям (для PersistStagedJob).
     *
     * @return \Generator<int, array>
     */
    public function iterateNormalized(string $sessionId, int $batchSize = 500): \Generator
    {
        yield from $this->iterateByStatus($sessionId, 'normalized', $batchSize);
    }

    /**
     * Итерация по pending записям (для NormalizeStagedJob).
     *
     * @return \Generator<int, array>
     */
    public function iteratePending(string $sessionId, int $batchSize = 500): \Generator
    {
        yield from $this->iterateByStatus($sessionId, 'pending', $batchSize);
    }

    // ═══════════════════════════════════════════
    // NORMALIZATION (Фаза 3)
    // ═══════════════════════════════════════════

    /**
     * Обновить одну запись после нормализации.
     */
    public function markNormalized(int $rowId, array $normalizedData): void
    {
        $json = json_encode($normalizedData, JSON_UNESCAPED_UNICODE);
        $this->db->createCommand(
            "UPDATE {{%staging_raw_offers}} SET normalized_data = :data::jsonb, status = 'normalized' WHERE id = :id",
            [':data' => $json, ':id' => $rowId]
        )->execute();
    }

    /**
     * Batch-обновление статуса после нормализации.
     *
     * @param array $updates [[rowId, normalizedData], ...]
     */
    public function markNormalizedBatch(array $updates): void
    {
        if (empty($updates)) return;

        // Используем транзакцию для batch-update
        $tx = $this->db->beginTransaction();
        try {
            foreach ($updates as [$rowId, $normalizedData]) {
                $json = json_encode($normalizedData, JSON_UNESCAPED_UNICODE);
                $this->db->createCommand(
                    "UPDATE {{%staging_raw_offers}} SET normalized_data = :data::jsonb, status = 'normalized' WHERE id = :id",
                    [':data' => $json, ':id' => $rowId]
                )->execute();
            }
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
    }

    /**
     * Пометить запись как ошибочную.
     */
    public function markError(int $rowId, string $errorMessage): void
    {
        $this->db->createCommand()->update('{{%staging_raw_offers}}', [
            'status'        => 'error',
            'error_message' => mb_substr($errorMessage, 0, 1000),
        ], ['id' => $rowId])->execute();
    }

    /**
     * Пометить запись как персистированную.
     */
    public function markPersisted(int $rowId): void
    {
        $this->db->createCommand()->update('{{%staging_raw_offers}}', [
            'status' => 'persisted',
        ], ['id' => $rowId])->execute();
    }

    /**
     * Batch-обновление статуса на persisted.
     *
     * @param int[] $rowIds
     */
    public function markPersistedBatch(array $rowIds): void
    {
        if (empty($rowIds)) return;

        $ids = array_values($rowIds);
        $placeholders = implode(',', array_map(fn($i) => ':id' . $i, array_keys($ids)));
        $params = [];
        foreach ($ids as $i => $id) {
            $params[':id' . $i] = $id;
        }

        $this->db->createCommand(
            "UPDATE {{%staging_raw_offers}} SET status = 'persisted' WHERE id IN ({$placeholders})",
            $params
        )->execute();
    }

    // ═══════════════════════════════════════════
    // BRANDS & CATEGORIES (из SQL вместо Redis SET)
    // ═══════════════════════════════════════════

    /**
     * Все уникальные бренды в staging (из raw_data->>'manufacturer').
     */
    public function getBrands(string $sessionId): array
    {
        return $this->db->createCommand("
            SELECT DISTINCT raw_data->>'manufacturer' AS brand
            FROM {{%staging_raw_offers}}
            WHERE import_session_id = :sid
              AND raw_data->>'manufacturer' IS NOT NULL
              AND raw_data->>'manufacturer' != ''
            ORDER BY brand
        ", [':sid' => $sessionId])->queryColumn();
    }

    /**
     * Все уникальные категории в staging (из raw_data->>'category_path').
     */
    public function getCategories(string $sessionId): array
    {
        return $this->db->createCommand("
            SELECT DISTINCT raw_data->>'category_path' AS category
            FROM {{%staging_raw_offers}}
            WHERE import_session_id = :sid
              AND raw_data->>'category_path' IS NOT NULL
              AND raw_data->>'category_path' != ''
            ORDER BY category
        ", [':sid' => $sessionId])->queryColumn();
    }

    // ═══════════════════════════════════════════
    // STATS & AGGREGATIONS
    // ═══════════════════════════════════════════

    /**
     * Статистика по статусам в staging.
     */
    public function getStatusCounts(string $sessionId): array
    {
        $rows = $this->db->createCommand("
            SELECT status, COUNT(*) as cnt
            FROM {{%staging_raw_offers}}
            WHERE import_session_id = :sid
            GROUP BY status
        ", [':sid' => $sessionId])->queryAll();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['status']] = (int)$row['cnt'];
        }
        return $result;
    }

    /**
     * Полная статистика сессии.
     */
    public function getSessionStats(string $sessionId): array
    {
        $session = $this->getSession($sessionId);
        if (!$session) {
            return ['error' => 'Session not found'];
        }

        $statusCounts = $this->getStatusCounts($sessionId);

        return [
            'session_id'      => $sessionId,
            'status'          => $session['status'],
            'supplier_code'   => $session['supplier_code'],
            'total_items'     => (int)$session['total_items'],
            'staging_counts'  => $statusCounts,
            'staging_total'   => array_sum($statusCounts),
            'unique_brands'   => count($this->getBrands($sessionId)),
            'unique_categories' => count($this->getCategories($sessionId)),
            'stats'           => json_decode($session['stats'] ?? '{}', true),
            'created_at'      => $session['created_at'],
        ];
    }

    // ═══════════════════════════════════════════
    // CLEANUP
    // ═══════════════════════════════════════════

    /**
     * Очистить staging-данные сессии.
     */
    public function cleanupSession(string $sessionId): int
    {
        $deleted = $this->db->createCommand(
            "DELETE FROM {{%staging_raw_offers}} WHERE import_session_id = :sid",
            [':sid' => $sessionId]
        )->execute();

        // Обновляем статус сессии
        $this->db->createCommand()->update('{{%import_sessions}}', [
            'status'     => 'cleaned',
            'updated_at' => new \yii\db\Expression('NOW()'),
        ], ['session_id' => $sessionId])->execute();

        return $deleted;
    }

    /**
     * Очистить все старые staging-данные.
     */
    public function cleanupOld(int $hoursOld = null): int
    {
        $hours = $hoursOld ?? $this->cleanupHours;

        // Удаляем staging данные старых сессий
        $deleted = $this->db->createCommand("
            DELETE FROM {{%staging_raw_offers}}
            WHERE import_session_id IN (
                SELECT session_id FROM {{%import_sessions}}
                WHERE created_at < NOW() - INTERVAL '{$hours} hours'
                  AND status IN ('completed', 'cleaned', 'failed')
            )
        ")->execute();

        return $deleted;
    }

    /**
     * TRUNCATE staging таблицы (быстрая полная очистка).
     */
    public function truncateStaging(): void
    {
        $this->db->createCommand("TRUNCATE TABLE {{%staging_raw_offers}}")->execute();
    }

    // ═══════════════════════════════════════════
    // SERIALIZATION
    // ═══════════════════════════════════════════

    /**
     * Сериализовать ProductDTO в массив для JSONB.
     */
    public function serializeProductDTO(ProductDTO $dto): array
    {
        return [
            'sku'               => $dto->supplierSku,
            'name'              => $dto->name,
            'category_path'     => $dto->categoryPath,
            'manufacturer'      => $dto->manufacturer,
            'brand'             => $dto->brand,
            'model'             => $dto->model,
            'description'       => $dto->description,
            'short_description' => $dto->shortDescription,
            'price'             => $dto->price,
            'compare_price'     => $dto->comparePrice,
            'in_stock'          => $dto->inStock,
            'stock_quantity'    => $dto->stockQuantity,
            'stock_status'      => $dto->stockStatus,
            'attributes'        => $dto->attributes,
            'image_urls'        => $dto->imageUrls,
            'variants'          => array_map(fn(VariantDTO $v) => [
                'sku'            => $v->sku,
                'price'          => $v->price,
                'compare_price'  => $v->comparePrice,
                'in_stock'       => $v->inStock,
                'stock_quantity' => $v->stockQuantity,
                'stock_status'   => $v->stockStatus,
                'options'        => $v->options,
                'image_urls'     => $v->imageUrls,
            ], $dto->variants),
            'raw_data' => $dto->rawData,
        ];
    }

    /**
     * Десериализовать raw_data обратно в ProductDTO.
     *
     * Использует normalized_data если есть, иначе raw_data.
     */
    public function deserializeToDTO(array|string $data, array|string|null $normalizedData = null): ProductDTO
    {
        // Автоматический json_decode из PostgreSQL JSONB
        if (is_string($data)) {
            $data = json_decode($data, true) ?: [];
        }
        if (is_string($normalizedData)) {
            $normalizedData = json_decode($normalizedData, true) ?: [];
        }

        // Если есть нормализованные данные — берём canonical имена оттуда
        $canonical = $normalizedData ?: [];

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
            supplierSku:      $data['sku'] ?? '',
            name:             $canonical['_canonical_name'] ?? $data['name'] ?? '',
            categoryPath:     $canonical['_category_mapped'] ?? $data['category_path'] ?? '',
            manufacturer:     $canonical['_brand_canonical'] ?? $data['manufacturer'] ?? null,
            brand:            $canonical['_brand_canonical'] ?? $data['brand'] ?? null,
            model:            $data['model'] ?? null,
            description:      $data['description'] ?? null,
            shortDescription: $data['short_description'] ?? null,
            price:            $data['price'] ?? null,
            comparePrice:     $data['compare_price'] ?? null,
            inStock:          (bool)($data['in_stock'] ?? true),
            stockQuantity:    $data['stock_quantity'] ?? null,
            stockStatus:      $data['stock_status'] ?? 'available',
            attributes:       $data['attributes'] ?? [],
            imageUrls:        $data['image_urls'] ?? [],
            variants:         $variants,
            rawData:          $data['raw_data'] ?? [],
        );
    }

    // ═══════════════════════════════════════════
    // BACKWARD COMPATIBILITY (для ImportController)
    // ═══════════════════════════════════════════

    /**
     * Создать задачу (обёртка для обратной совместимости с Redis API).
     * @deprecated Используйте createSession()
     */
    public function createTask(string $supplierCode, string $filePath, array $options = []): string
    {
        // Получаем supplier_id
        $supplierId = $this->db->createCommand(
            "SELECT id FROM {{%suppliers}} WHERE code = :code",
            [':code' => $supplierCode]
        )->queryScalar();

        if (!$supplierId) {
            // Создаём поставщика если нет
            $this->db->createCommand()->insert('{{%suppliers}}', [
                'name'      => ucfirst($supplierCode),
                'code'      => $supplierCode,
                'is_active' => true,
                'format'    => 'xml',
            ])->execute();
            $supplierId = (int)$this->db->getLastInsertID('suppliers_id_seq');
        }

        return $this->createSession($supplierCode, (int)$supplierId, $filePath, $options);
    }

    /**
     * @deprecated Используйте getSession()
     */
    public function getMeta(string $sessionId): ?array
    {
        $session = $this->getSession($sessionId);
        if (!$session) return null;

        // Конвертируем в формат, совместимый со старым Redis meta
        $stats = json_decode($session['stats'] ?? '{}', true) ?: [];
        return array_merge($stats, [
            'task_id'       => $session['session_id'],
            'supplier_code' => $session['supplier_code'],
            'file_path'     => $session['file_path'],
            'status'        => $session['status'],
            'created_at'    => $session['created_at'],
            'total_items'   => (int)$session['total_items'],
        ]);
    }

    /**
     * @deprecated Используйте updateStats()
     */
    public function updateMeta(string $sessionId, array $data): void
    {
        $this->updateStats($sessionId, $data);
    }

    /**
     * @deprecated Используйте cleanupSession()
     */
    public function cleanup(string $sessionId): void
    {
        $this->cleanupSession($sessionId);
    }

    /**
     * @deprecated Используйте getActiveSessions()
     */
    public function getActiveTasks(): array
    {
        $sessions = $this->getActiveSessions();
        // Конвертируем в совместимый формат
        return array_map(function ($s) {
            $stats = json_decode($s['stats'] ?? '{}', true) ?: [];
            return array_merge($stats, [
                'task_id'       => $s['session_id'],
                'supplier_code' => $s['supplier_code'],
                'status'        => $s['status'],
                'total_items'   => (int)$s['total_items'],
                'created_at'    => $s['created_at'],
            ]);
        }, $sessions);
    }
}
