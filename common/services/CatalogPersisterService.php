<?php

namespace common\services;

use common\dto\MatchResult;
use common\dto\ProductDTO;
use common\enums\ProductFamily;
use common\services\matching\MatchingService;
use yii\base\Component;
use yii\db\Connection;
use yii\db\JsonExpression;
use Yii;

/**
 * Сервис материализации — записывает нормализованные товары в MDM-каталог.
 *
 * Для каждого нормализованного оффера:
 *   1. Прогоняет через MatchingService (GtinMatcher → MpnMatcher → CompositeAttributeMatcher)
 *   2. Если variant найден:
 *      - UPSERT в supplier_offers (цена, остатки → привязка к существующему варианту)
 *      - GoldenRecordService::recalculateAttributes() — обновить "лучшие" атрибуты
 *   3. Если variant НЕ найден:
 *      - Создать product_model (если нет)
 *      - Создать reference_variant с JSONB-атрибутами
 *      - Создать supplier_offer
 *
 * Все записи идут в транзакции (3 таблицы за раз).
 * Логирование: какой матчер сработал, confidence, details.
 *
 * Использование в PersistStagedJob:
 *   $persister = Yii::$app->get('catalogPersister');
 *   $result = $persister->persist($dto, $supplierId, $sessionId);
 */
class CatalogPersisterService extends Component
{
    /** @var MatchingService */
    private MatchingService $matcher;

    /** @var GoldenRecordService */
    private GoldenRecordService $goldenRecord;

    /** @var OutboxService */
    private OutboxService $outbox;

    /** @var MediaProcessingService */
    private MediaProcessingService $mediaService;

    /** @var PriceCalculationService */
    private PriceCalculationService $pricingService;

    /** @var array Статистика текущей сессии */
    private array $stats = [
        'models_created'   => 0,
        'models_matched'   => 0,
        'variants_created' => 0,
        'variants_matched' => 0,
        'offers_created'   => 0,
        'offers_updated'   => 0,
        'errors'           => 0,
        'total'            => 0,
    ];

    public function init(): void
    {
        parent::init();
        $this->matcher = Yii::$app->get('matchingService');
        $this->goldenRecord = Yii::$app->get('goldenRecord');
        $this->outbox = Yii::$app->get('outbox');
        $this->mediaService = Yii::$app->get('mediaService');
        $this->pricingService = Yii::$app->get('pricingService');
    }

    /**
     * Материализовать один товар: DTO → MDM-каталог.
     *
     * @param ProductDTO $dto       Нормализованный товар
     * @param int        $supplierId ID поставщика
     * @param string     $sessionId  ID import-сессии (для логирования)
     * @param array      $context    Доп. контекст (brand_id, product_family и т.д.)
     *
     * @return array Результат: ['action' => 'created'|'matched', 'model_id' => int, 'variant_id' => int, 'offer_id' => int, 'matcher' => string]
     *
     * @throws \Throwable — при ошибке БД (вызывающий код управляет транзакцией)
     */
    public function persist(ProductDTO $dto, int $supplierId, string $sessionId = '', array $context = []): array
    {
        $this->stats['total']++;

        $db = Yii::$app->db;

        // Подготавливаем контекст для матчинга
        $matchContext = array_merge($context, [
            'supplier_id' => $supplierId,
            'session_id'  => $sessionId,
        ]);

        // Резолвим бренд если ещё нет
        if (!isset($matchContext['brand_id'])) {
            $matchContext['brand_id'] = $this->resolveBrandId($dto);
        }

        // Определяем ProductFamily
        if (!isset($matchContext['product_family'])) {
            $matchContext['product_family'] = ProductFamily::detect(
                $dto->name . ' ' . ($dto->categoryPath ?? '')
            )->value;
        }

        // ═══ МАТЧИНГ ═══
        $matchResult = $this->matcher->match($dto, $matchContext);

        $modelId = $matchResult->modelId;
        $variantId = $matchResult->variantId;
        $action = 'created';

        // ═══ МОДЕЛЬ ═══
        $isNewModel = false;
        if ($modelId) {
            // Модель найдена
            $this->stats['models_matched']++;
            $action = 'matched';
        } else {
            // Создаём новую модель
            $modelId = $this->createModel($db, $dto, $matchContext);
            $this->stats['models_created']++;
            $isNewModel = true;
        }

        // ═══ ВАРИАНТ ═══
        $isNewVariant = false;
        if ($variantId) {
            // Вариант найден
            $this->stats['variants_matched']++;
        } else {
            // Создаём новый вариант
            $variantId = $this->createVariant($db, $dto, $modelId, $matchContext);
            $this->stats['variants_created']++;
            $isNewVariant = true;
        }

        // ═══ ОФФЕР (UPSERT) ═══
        $offerResult = $this->upsertOffer($db, $dto, $modelId, $variantId, $supplierId);
        if ($offerResult['is_new']) {
            $this->stats['offers_created']++;
        } else {
            $this->stats['offers_updated']++;
        }

        // ═══ PRICING ENGINE — расчёт розничной цены (Sprint 11) ═══
        $offerId = $offerResult['offer_id'];
        $pricingResult = $this->pricingService->updateOfferRetailPrice(
            $offerId,
            $dto->getMinPrice(),
            [
                'supplier_id'    => $supplierId,
                'brand_id'       => $matchContext['brand_id'] ?? null,
                'category_id'    => null, // будет определён ниже по модели
                'product_family' => $matchContext['product_family'] ?? null,
            ]
        );
        $retailPriceChanged = $pricingResult['changed'] ?? false;

        // ═══ GOLDEN RECORD — пересчёт агрегатов ═══
        $this->goldenRecord->recalculateVariant($variantId);
        $this->goldenRecord->recalculateModel($modelId);

        // Обновляем атрибуты модели если нужно
        $attrsUpdated = $this->goldenRecord->updateAttributes($modelId, $supplierId, $dto->attributes);
        $descUpdated = $this->goldenRecord->updateDescription($modelId, $dto->description, $dto->shortDescription);

        // ═══ OUTBOX — запись событий В ТОЙ ЖЕ ТРАНЗАКЦИИ ═══
        if ($isNewModel) {
            $this->outbox->modelCreated($modelId, $sessionId, [
                'name'   => $dto->name,
                'brand'  => $dto->brand,
                'family' => $matchContext['product_family'] ?? null,
            ]);
        } elseif ($attrsUpdated || $descUpdated) {
            $this->outbox->modelUpdated($modelId, $sessionId);
        }

        if ($isNewVariant) {
            $this->outbox->variantCreated($modelId, $variantId, $sessionId, [
                'label' => $this->buildVariantLabel(
                    $this->extractVariantAttributes($dto, $matchContext['product_family'] ?? 'unknown'),
                    $matchContext['product_family'] ?? 'unknown'
                ),
            ]);
        }

        if ($offerResult['is_new']) {
            $this->outbox->offerCreated($modelId, $variantId, $offerId, $sessionId, [
                'price'        => $dto->getMinPrice(),
                'retail_price' => $pricingResult['new_retail'] ?? null,
                'supplier_id'  => $supplierId,
            ]);
        } else {
            // Оффер обновлён — проверяем изменение цены
            $supplierPriceChanged = $offerResult['price_changed'] ?? false;

            if ($supplierPriceChanged || $retailPriceChanged) {
                // Если изменилась retail_price → price_updated Fast-Lane
                $this->outbox->emitPriceUpdate($modelId, $variantId, $offerId, [
                    'old_supplier_price' => $offerResult['old_price'] ?? null,
                    'new_supplier_price' => $dto->getMinPrice(),
                    'old_retail_price'   => $pricingResult['old_retail'] ?? null,
                    'new_retail_price'   => $pricingResult['new_retail'] ?? null,
                ], $sessionId);
            } else {
                $this->outbox->offerUpdated($modelId, $variantId, $offerId, $sessionId);
            }
        }

        // ═══ MEDIA ASSETS — регистрация изображений для скачивания ═══
        if (!empty($dto->imageUrls)) {
            // Привязываем к модели (дедупликация: URL уже зарегистрированные — пропускаются)
            $this->mediaService->registerImages('model', $modelId, $dto->imageUrls);
        }

        return [
            'action'     => $action,
            'model_id'   => $modelId,
            'variant_id' => $variantId,
            'offer_id'   => $offerId,
            'matcher'    => $matchResult->matcherName,
            'confidence' => $matchResult->confidence,
        ];
    }

    // ═══════════════════════════════════════════
    // CREATE MODEL
    // ═══════════════════════════════════════════

    protected function createModel(Connection $db, ProductDTO $dto, array $context): int
    {
        $manufacturer = $dto->manufacturer ?? 'Unknown';
        $modelName = $dto->model ?? $dto->name;
        $family = $context['product_family'] ?? ProductFamily::UNKNOWN->value;
        $brandId = $context['brand_id'] ?? null;

        // Полное имя модели: "Орматек Оптима"
        $fullName = $manufacturer . ' ' . $modelName;
        if (mb_strtolower($manufacturer) === mb_strtolower($modelName)) {
            $fullName = $modelName;
        }
        // Убираем размеры из имени модели
        $fullName = preg_replace('/\s*\d{2,4}\s*[xхXХ×\*]\s*\d{2,4}\s*/', '', $fullName);
        $fullName = trim($fullName);

        $slug = $this->slugify($fullName);
        $slug = $this->ensureUniqueSlug($db, $slug, '{{%product_models}}');

        // Категория
        $categoryId = $this->resolveCategoryId($dto->categoryPath, $family);

        $db->createCommand()->insert('{{%product_models}}', [
            'product_family'       => $family,
            'brand_id'             => $brandId,
            'category_id'          => $categoryId,
            'name'                 => $fullName,
            'slug'                 => $slug,
            'manufacturer'         => $manufacturer,
            'model_name'           => $this->cleanModelName($modelName),
            'description'          => $dto->description,
            'short_description'    => $dto->shortDescription,
            'canonical_attributes' => new JsonExpression($dto->attributes ?: new \stdClass()),
            'canonical_images'     => new JsonExpression($dto->imageUrls),
            'best_price'           => $dto->getMinPrice(),
            'price_range_min'      => $dto->getMinPrice(),
            'price_range_max'      => $dto->getMaxPrice(),
            'is_in_stock'          => $dto->inStock,
            'supplier_count'       => 1,
            'variant_count'        => 1,
            'offer_count'          => 1,
            'status'               => 'active',
            'is_published'         => true,
        ])->execute();

        $modelId = (int)$db->getLastInsertID('product_models_id_seq');

        Yii::info(
            "CatalogPersister: NEW MODEL id={$modelId} name='{$fullName}' family={$family} brand_id={$brandId}",
            'catalog'
        );

        return $modelId;
    }

    // ═══════════════════════════════════════════
    // CREATE VARIANT
    // ═══════════════════════════════════════════

    protected function createVariant(Connection $db, ProductDTO $dto, int $modelId, array $context): int
    {
        $family = $context['product_family'] ?? ProductFamily::UNKNOWN->value;
        $variantAttrs = $this->extractVariantAttributes($dto, $family);
        $label = $this->buildVariantLabel($variantAttrs, $family);

        // Извлекаем GTIN и MPN
        $gtin = $this->extractGtin($dto);
        $mpn = $this->extractMpn($dto);

        $db->createCommand()->insert('{{%reference_variants}}', [
            'model_id'            => $modelId,
            'gtin'                => $gtin,
            'mpn'                 => $mpn,
            'variant_attributes'  => new JsonExpression($variantAttrs ?: new \stdClass()),
            'variant_label'       => $label,
            'best_price'          => $dto->getMinPrice(),
            'price_range_min'     => $dto->getMinPrice(),
            'price_range_max'     => $dto->getMaxPrice(),
            'is_in_stock'         => $dto->inStock,
            'supplier_count'      => 1,
        ])->execute();

        $variantId = (int)$db->getLastInsertID('reference_variants_id_seq');

        Yii::info(
            "CatalogPersister: NEW VARIANT id={$variantId} model_id={$modelId} label='{$label}' attrs=" .
            json_encode($variantAttrs),
            'catalog'
        );

        return $variantId;
    }

    // ═══════════════════════════════════════════
    // UPSERT OFFER
    // ═══════════════════════════════════════════

    protected function upsertOffer(Connection $db, ProductDTO $dto, int $modelId, int $variantId, int $supplierId): array
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
                model_id, variant_id, supplier_id, supplier_sku,
                price_min, price_max, compare_price,
                in_stock, stock_status, description,
                attributes_json, images_json, variants_json, variant_count,
                match_confidence, match_method, checksum, is_active,
                raw_data, created_at, updated_at
            ) VALUES (
                :model_id, :variant_id, :supplier_id, :sku,
                :price_min, :price_max, :compare_price,
                :in_stock, :stock_status, :description,
                :attributes::jsonb, :images::jsonb, :variants::jsonb, :variant_count,
                1.0, 'mdm_matching', :checksum, true,
                :raw_data::jsonb, NOW(), NOW()
            )
            ON CONFLICT (supplier_id, supplier_sku) DO UPDATE SET
                model_id = EXCLUDED.model_id,
                variant_id = EXCLUDED.variant_id,
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
            RETURNING id, (xmax = 0) AS is_insert, previous_price_min, price_min
        ";

        $row = $db->createCommand($sql, [
            ':model_id'      => $modelId,
            ':variant_id'    => $variantId,
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

        $isNew = (bool)($row['is_insert'] ?? false);
        $priceChanged = !$isNew
            && $row['previous_price_min'] !== null
            && (float)$row['previous_price_min'] !== (float)$row['price_min'];

        return [
            'offer_id'      => (int)($row['id'] ?? 0),
            'is_new'        => $isNew,
            'price_changed' => $priceChanged,
            'old_price'     => $isNew ? null : ($row['previous_price_min'] ?? null),
            'new_price'     => $row['price_min'] ?? null,
        ];
    }

    // ═══════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════

    /**
     * Извлечь оси вариаций из DTO (width, length, color и т.д.)
     */
    protected function extractVariantAttributes(ProductDTO $dto, string $family): array
    {
        $familyEnum = ProductFamily::tryFrom($family);

        $variantKeys = [];
        if ($familyEnum) {
            $familySchema = ProductFamilySchema::getSchema($familyEnum);
            $variantKeys = $familySchema['variant_attributes'] ?? [];
        }

        // Дефолтные ключи если schema не определила
        if (empty($variantKeys)) {
            $variantKeys = ['width', 'length'];
        }

        $result = [];

        foreach ($variantKeys as $key) {
            // Ищем в атрибутах DTO
            $val = $dto->attributes[$key] ?? null;

            // Ищем в первом варианте
            if ($val === null && !empty($dto->variants)) {
                $val = $dto->variants[0]->options[$key] ?? null;
            }

            // Ищем в rawData
            if ($val === null) {
                $val = $dto->rawData[$key] ?? null;
            }

            if ($val !== null) {
                $result[$key] = is_numeric($val) ? (int)$val : (string)$val;
            }
        }

        // Парсим из названия если нет в атрибутах
        if (!isset($result['width']) && !isset($result['length'])) {
            $text = $dto->name . ' ' . ($dto->model ?? '');
            if (preg_match('/(\d{2,4})\s*[xхXХ×\*]\s*(\d{2,4})/', $text, $m)) {
                $w = (int)$m[1];
                $l = (int)$m[2];
                if ($w >= 30 && $w <= 400 && $l >= 30 && $l <= 400) {
                    $result['width'] = $w;
                    $result['length'] = $l;
                }
            }
        }

        return $result;
    }

    /**
     * Генерация человекочитаемой метки варианта.
     * "160×200" для матрасов, "50×70×15" для подушек, "Белый" для кроватей.
     */
    protected function buildVariantLabel(array $attrs, string $family): string
    {
        $parts = [];

        // Размеры
        if (isset($attrs['width']) && isset($attrs['length'])) {
            $label = $attrs['width'] . '×' . $attrs['length'];
            if (isset($attrs['height'])) {
                $label .= '×' . $attrs['height'];
            }
            $parts[] = $label;
        }

        // Цвет
        if (isset($attrs['color'])) {
            $parts[] = $attrs['color'];
        }

        // Материал
        if (isset($attrs['material'])) {
            $parts[] = $attrs['material'];
        }

        return implode(', ', $parts) ?: 'Основной';
    }

    protected function extractGtin(ProductDTO $dto): ?string
    {
        $candidates = [
            $dto->attributes['gtin'] ?? null,
            $dto->attributes['ean'] ?? null,
            $dto->attributes['barcode'] ?? null,
            $dto->rawData['gtin'] ?? null,
            $dto->rawData['ean'] ?? null,
        ];

        foreach ($candidates as $val) {
            if (empty($val)) continue;
            $clean = preg_replace('/[\s\-]/', '', trim((string)$val));
            if (preg_match('/^\d{8,14}$/', $clean)) {
                if (strlen($clean) === 12) $clean = '0' . $clean;
                if (in_array(strlen($clean), [8, 13, 14])) return $clean;
            }
        }

        return null;
    }

    protected function extractMpn(ProductDTO $dto): ?string
    {
        $candidates = [
            $dto->attributes['mpn'] ?? null,
            $dto->attributes['article'] ?? null,
            $dto->supplierSku,
        ];

        foreach ($candidates as $val) {
            if (!empty($val) && is_string($val) && strlen(trim($val)) >= 3) {
                return trim($val);
            }
        }

        return null;
    }

    protected function resolveBrandId(ProductDTO $dto): ?int
    {
        $brand = $dto->brand ?? $dto->manufacturer;
        if (empty($brand)) return null;

        $db = Yii::$app->db;

        $id = $db->createCommand(
            "SELECT id FROM {{%brands}} WHERE canonical_name = :name LIMIT 1",
            [':name' => $brand]
        )->queryScalar();

        if ($id) return (int)$id;

        $id = $db->createCommand(
            "SELECT brand_id FROM {{%brand_aliases}} WHERE alias ILIKE :name LIMIT 1",
            [':name' => $brand]
        )->queryScalar();

        return $id ? (int)$id : null;
    }

    protected function resolveCategoryId(?string $categoryPath, string $family): ?int
    {
        if (empty($categoryPath)) return null;

        $db = Yii::$app->db;

        // Пробуем найти последний сегмент пути в каталоге
        $parts = array_map('trim', preg_split('/[>\/]/', $categoryPath));
        $lastPart = end($parts);

        if (empty($lastPart)) return null;

        $id = $db->createCommand(
            "SELECT id FROM {{%categories}} WHERE LOWER(name) = LOWER(:name) LIMIT 1",
            [':name' => $lastPart]
        )->queryScalar();

        return $id ? (int)$id : null;
    }

    protected function cleanModelName(string $name): string
    {
        // Убираем размеры из имени модели
        $clean = preg_replace('/\s*\d{2,4}\s*[xхXХ×\*]\s*\d{2,4}\s*/', '', $name);
        $clean = preg_replace('/\s+\d{2,4}\s+\d{2,4}\s*$/', '', $clean);
        return trim($clean) ?: $name;
    }

    protected function ensureUniqueSlug(Connection $db, string $baseSlug, string $table): string
    {
        $slug = $baseSlug;
        $suffix = 0;

        while ($db->createCommand("SELECT 1 FROM {$table} WHERE slug = :slug", [':slug' => $slug])->queryScalar()) {
            $suffix++;
            $slug = $baseSlug . '-' . $suffix;
        }

        return $slug;
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

    /**
     * Получить статистику текущей сессии.
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Сбросить статистику.
     */
    public function resetStats(): void
    {
        $this->stats = [
            'models_created'   => 0,
            'models_matched'   => 0,
            'variants_created' => 0,
            'variants_matched' => 0,
            'offers_created'   => 0,
            'offers_updated'   => 0,
            'errors'           => 0,
            'total'            => 0,
        ];
    }
}
