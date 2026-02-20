<?php

namespace common\services;

use yii\base\Component;
use yii\db\JsonExpression;
use Yii;

/**
 * Сервис-трансформатор: MDM-каталог → плоская проекция для витрины.
 *
 * Задача — собрать сложную 3-уровневую MDM-структуру
 *   (product_models → reference_variants → supplier_offers)
 * в один плоский JSON, удобный для фронтенда RosMatras.
 *
 * Ключевая магия — selector_axes:
 *   Анализирует variant_attributes всех вариантов модели
 *   и генерирует блок selector_axes, например:
 *   {"available_widths": [80, 90, 120, 140, 160, 180, 200], "available_lengths": [190, 195, 200]}
 *   Это позволяет фронтенду легко нарисовать кнопки переключения размеров.
 *
 * Использование:
 *   $syndicator = Yii::$app->get('syndicationService');
 *   $projection = $syndicator->buildProductProjection($modelId);
 *   // $projection = полный JSON для API/витрины
 */
class RosMatrasSyndicationService extends Component
{
    /**
     * Собрать полную проекцию модели для витрины.
     *
     * @param int $modelId ID product_model
     * @return array|null Плоская проекция или null если модель не найдена
     */
    public function buildProductProjection(int $modelId): ?array
    {
        $db = Yii::$app->db;

        // ═══ 1. МОДЕЛЬ ═══
        // Загружаем модель ЛЮБОГО статуса (не только active),
        // чтобы можно было отправить is_active=false для деактивированных моделей.
        $model = $db->createCommand("
            SELECT
                pm.id,
                pm.product_family,
                pm.name,
                pm.slug,
                pm.manufacturer,
                pm.model_name,
                pm.description,
                pm.short_description,
                pm.canonical_attributes,
                pm.canonical_images,
                pm.meta_title,
                pm.meta_description,
                pm.best_price,
                pm.price_range_min,
                pm.price_range_max,
                pm.variant_count,
                pm.offer_count,
                pm.supplier_count,
                pm.is_in_stock,
                pm.status,
                pm.brand_id,
                pm.category_id,
                b.canonical_name AS brand_name,
                b.logo_url AS brand_logo,
                c.name AS category_name,
                c.slug AS category_slug
            FROM {{%product_models}} pm
            LEFT JOIN {{%brands}} b ON b.id = pm.brand_id
            LEFT JOIN {{%categories}} c ON c.id = pm.category_id
            WHERE pm.id = :id
        ", [':id' => $modelId])->queryOne();

        if (!$model) {
            return null;
        }

        // Декодируем JSONB-поля
        $canonicalAttrs = is_string($model['canonical_attributes'])
            ? json_decode($model['canonical_attributes'], true) ?: []
            : ($model['canonical_attributes'] ?? []);

        $canonicalImages = is_string($model['canonical_images'])
            ? json_decode($model['canonical_images'], true) ?: []
            : ($model['canonical_images'] ?? []);

        // ═══ 2. ВАРИАНТЫ ═══
        $variants = $db->createCommand("
            SELECT
                rv.id,
                rv.gtin,
                rv.mpn,
                rv.variant_attributes,
                rv.variant_label,
                rv.best_price,
                rv.price_range_min,
                rv.price_range_max,
                rv.is_in_stock,
                rv.supplier_count,
                rv.sort_order
            FROM {{%reference_variants}} rv
            WHERE rv.model_id = :model_id
            ORDER BY rv.sort_order, rv.best_price NULLS LAST, rv.id
        ", [':model_id' => $modelId])->queryAll();

        // ═══ 3. ЛУЧШИЕ ОФФЕРЫ ДЛЯ КАЖДОГО ВАРИАНТА ═══
        $variantIds = array_column($variants, 'id');
        $offersMap = [];

        if (!empty($variantIds)) {
            $inParams = [];
            $inPlaceholders = [];
            foreach ($variantIds as $i => $vid) {
                $key = ':vid' . $i;
                $inParams[$key] = $vid;
                $inPlaceholders[] = $key;
            }
            $inSql = implode(',', $inPlaceholders);

            $offers = $db->createCommand(
                "SELECT DISTINCT ON (so.variant_id)
                    so.variant_id,
                    so.id AS offer_id,
                    so.supplier_id,
                    so.supplier_sku,
                    so.price_min,
                    so.price_max,
                    so.compare_price,
                    so.in_stock,
                    so.stock_status,
                    so.images_json,
                    s.name AS supplier_name,
                    s.code AS supplier_code
                FROM {{%supplier_offers}} so
                JOIN {{%suppliers}} s ON s.id = so.supplier_id
                WHERE so.variant_id IN ({$inSql})
                    AND so.is_active = true
                ORDER BY so.variant_id, so.price_min ASC NULLS LAST",
                $inParams
            )->queryAll();

            foreach ($offers as $offer) {
                $offersMap[(int)$offer['variant_id']] = $offer;
            }
        }

        // ═══ 4. ВСЕ ОФФЕРЫ МОДЕЛИ (для полноты) ═══
        $allOffers = [];
        if (!empty($variantIds)) {
            $inParams = [];
            $inPlaceholders = [];
            foreach ($variantIds as $i => $vid) {
                $key = ':vid' . $i;
                $inParams[$key] = $vid;
                $inPlaceholders[] = $key;
            }
            $inSql = implode(',', $inPlaceholders);

            $allOffers = $db->createCommand(
                "SELECT
                    so.id AS offer_id,
                    so.variant_id,
                    so.supplier_id,
                    so.supplier_sku,
                    so.price_min,
                    so.price_max,
                    so.compare_price,
                    so.in_stock,
                    so.stock_status,
                    so.images_json,
                    s.name AS supplier_name,
                    s.code AS supplier_code
                FROM {{%supplier_offers}} so
                JOIN {{%suppliers}} s ON s.id = so.supplier_id
                WHERE so.variant_id IN ({$inSql})
                    AND so.is_active = true
                ORDER BY so.price_min ASC NULLS LAST",
                $inParams
            )->queryAll();
        }

        // ═══ 5. МАГИЯ: SELECTOR_AXES ═══
        $selectorAxes = $this->buildSelectorAxes($variants);

        // ═══ 6. СОБИРАЕМ ПРОЕКЦИЮ ═══
        $projectedVariants = [];
        foreach ($variants as $variant) {
            $varId = (int)$variant['id'];
            $varAttrs = is_string($variant['variant_attributes'])
                ? json_decode($variant['variant_attributes'], true) ?: []
                : ($variant['variant_attributes'] ?? []);

            $bestOffer = $offersMap[$varId] ?? null;

            $variantOffers = array_filter($allOffers, fn($o) => (int)$o['variant_id'] === $varId);

            // Вариант активен, если есть хотя бы один активный оффер с наличием
            $activeOffers = array_filter($variantOffers, fn($o) => (bool)$o['in_stock']);
            $variantIsActive = !empty($activeOffers) && (bool)$variant['is_in_stock'];

            $projectedVariants[] = [
                'id'               => $varId,
                'label'            => $variant['variant_label'] ?: 'Основной',
                'gtin'             => $variant['gtin'],
                'mpn'              => $variant['mpn'],
                'attributes'       => $varAttrs,
                'best_price'       => $variant['best_price'] ? (float)$variant['best_price'] : null,
                'price_range_min'  => $variant['price_range_min'] ? (float)$variant['price_range_min'] : null,
                'price_range_max'  => $variant['price_range_max'] ? (float)$variant['price_range_max'] : null,
                'compare_price'    => $bestOffer ? ($bestOffer['compare_price'] ? (float)$bestOffer['compare_price'] : null) : null,
                'is_in_stock'      => (bool)$variant['is_in_stock'],
                'is_active'        => $variantIsActive,
                'supplier_count'   => (int)$variant['supplier_count'],
                'offers'           => array_values(array_map(fn($o) => [
                    'offer_id'       => (int)$o['offer_id'],
                    'supplier_code'  => $o['supplier_code'],
                    'supplier_name'  => $o['supplier_name'],
                    'price'          => (float)$o['price_min'],
                    'compare_price'  => $o['compare_price'] ? (float)$o['compare_price'] : null,
                    'in_stock'       => (bool)$o['in_stock'],
                    'stock_status'   => $o['stock_status'],
                ], $variantOffers)),
            ];
        }

        // ═══ 6b. ИЗОБРАЖЕНИЯ ═══
        // Приоритет: DAM-обработанные (WebP) → сырые из офферов/канонических
        $images = $this->collectImagesFromDAM($modelId, $canonicalImages, $allOffers);

        // ═══ is_active: модель активна, если статус active И есть хотя бы один активный вариант ═══
        $modelIsActive = $model['status'] === 'active'
            && !empty($projectedVariants)
            && !empty(array_filter($projectedVariants, fn($v) => $v['is_active']));

        // ═══ ИТОГОВАЯ ПРОЕКЦИЯ ═══
        return [
            'model_id'          => (int)$model['id'],
            'product_family'    => $model['product_family'],
            'name'              => $model['name'],
            'slug'              => $model['slug'],
            'manufacturer'      => $model['manufacturer'],
            'model_name'        => $model['model_name'],

            // Бренд
            'brand' => $model['brand_id'] ? [
                'id'   => (int)$model['brand_id'],
                'name' => $model['brand_name'],
                'logo' => $model['brand_logo'],
            ] : null,

            // Категория
            'category' => $model['category_id'] ? [
                'id'   => (int)$model['category_id'],
                'name' => $model['category_name'],
                'slug' => $model['category_slug'],
            ] : null,

            // Тексты
            'description'       => $model['description'],
            'short_description' => $model['short_description'],

            // SEO
            'meta_title'        => $model['meta_title'] ?: $model['name'],
            'meta_description'  => $model['meta_description'] ?: mb_substr(strip_tags($model['description'] ?? ''), 0, 160),

            // Цены (агрегат по всем вариантам)
            'best_price'        => $model['best_price'] ? (float)$model['best_price'] : null,
            'price_range_min'   => $model['price_range_min'] ? (float)$model['price_range_min'] : null,
            'price_range_max'   => $model['price_range_max'] ? (float)$model['price_range_max'] : null,

            // Доступность
            'is_active'         => $modelIsActive,
            'is_in_stock'       => (bool)$model['is_in_stock'],
            'variant_count'     => (int)$model['variant_count'],
            'offer_count'       => (int)$model['offer_count'],
            'supplier_count'    => (int)$model['supplier_count'],

            // Канонические атрибуты (Golden Record)
            'attributes'        => $canonicalAttrs,

            // Изображения
            'images'            => $images,

            // ═══ МАГИЯ: selector_axes для фронтенда ═══
            'selector_axes'     => $selectorAxes,

            // Варианты (с вложенными офферами)
            'variants'          => $projectedVariants,

            // Метка времени
            'synced_at'         => date('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Построить проекции для нескольких моделей (батч).
     *
     * @param int[] $modelIds
     * @return array model_id => projection
     */
    public function buildBatch(array $modelIds): array
    {
        $result = [];
        foreach ($modelIds as $modelId) {
            $projection = $this->buildProductProjection($modelId);
            if ($projection) {
                $result[$modelId] = $projection;
            }
        }
        return $result;
    }

    // ═══════════════════════════════════════════
    // SELECTOR AXES — Главная магия для фронтенда
    // ═══════════════════════════════════════════

    /**
     * Анализирует variant_attributes всех вариантов и генерирует selector_axes.
     *
     * Пример входа: варианты с attributes:
     *   {"width": 80,  "length": 200}
     *   {"width": 90,  "length": 200}
     *   {"width": 120, "length": 200}
     *   {"width": 140, "length": 200}
     *   {"width": 160, "length": 200}
     *
     * Пример выхода:
     *   {
     *     "available_widths":  [80, 90, 120, 140, 160],
     *     "available_lengths": [200],
     *     "axis_combinations": [
     *       {"width": 80, "length": 200, "variant_id": 1, "price": 12500, "in_stock": true},
     *       ...
     *     ]
     *   }
     */
    protected function buildSelectorAxes(array $variants): array
    {
        if (empty($variants)) {
            return [];
        }

        // Собираем все уникальные атрибуты
        $axes = [];
        $combinations = [];

        foreach ($variants as $variant) {
            $attrs = is_string($variant['variant_attributes'])
                ? json_decode($variant['variant_attributes'], true) ?: []
                : ($variant['variant_attributes'] ?? []);

            if (empty($attrs)) continue;

            foreach ($attrs as $key => $value) {
                if (!isset($axes[$key])) {
                    $axes[$key] = [];
                }
                if (!in_array($value, $axes[$key], false)) {
                    $axes[$key][] = $value;
                }
            }

            // Сохраняем комбинацию для фронтенда
            $combo = $attrs;
            $combo['variant_id'] = (int)$variant['id'];
            $combo['label'] = $variant['variant_label'] ?: 'Основной';
            $combo['price'] = $variant['best_price'] ? (float)$variant['best_price'] : null;
            $combo['in_stock'] = (bool)$variant['is_in_stock'];
            $combinations[] = $combo;
        }

        // Убираем оси с одним значением (не нужны для селектора) — нет, оставляем!
        // Даже одно значение важно для отображения на карточке товара.

        // Сортируем значения в осях
        $result = [];
        foreach ($axes as $key => $values) {
            // Определяем тип оси: числовые сортируем числами
            $allNumeric = !empty($values) && array_reduce($values, fn($carry, $v) => $carry && is_numeric($v), true);
            if ($allNumeric) {
                sort($values, SORT_NUMERIC);
            } else {
                sort($values, SORT_STRING);
            }
            $result['available_' . $key . 's'] = $values;
        }

        // Добавляем комбинации
        $result['axis_combinations'] = $combinations;

        return $result;
    }

    /**
     * Собрать изображения с приоритетом из DAM (media_assets).
     *
     * Приоритет:
     *   1. Обработанные media_assets (WebP, готовые для витрины)
     *   2. Сырые URL из canonical_images / offers (fallback, пока DAM не обработал)
     */
    protected function collectImagesFromDAM(int $modelId, array $canonicalImages, array $allOffers): array
    {
        // Пытаемся получить обработанные из DAM
        try {
            /** @var MediaProcessingService $media */
            $media = Yii::$app->get('mediaService');
            $damImages = $media->getProcessedImages('model', $modelId);

            if (!empty($damImages)) {
                // DAM обработал — возвращаем готовые S3-URL (WebP)
                return $damImages;
            }
        } catch (\Throwable $e) {
            // Если mediaService не настроен — fallback
            Yii::debug("RosMatrasSyndication: mediaService unavailable: {$e->getMessage()}", 'syndication');
        }

        // Fallback: собираем сырые URL (старая логика)
        return $this->collectRawImages($canonicalImages, $allOffers);
    }

    /**
     * Собрать уникальные изображения из канонических и офферов (сырые URL).
     * Используется как fallback, пока DAM не обработал изображения.
     */
    protected function collectRawImages(array $canonicalImages, array $allOffers): array
    {
        $seen = [];
        $images = [];

        // Сначала канонические
        foreach ($canonicalImages as $url) {
            if (is_string($url) && !empty($url) && !isset($seen[$url])) {
                $seen[$url] = true;
                $images[] = [
                    'url'    => $url,
                    'source' => 'canonical',
                ];
            }
        }

        // Затем из офферов
        foreach ($allOffers as $offer) {
            $offerImages = is_string($offer['images_json'] ?? null)
                ? json_decode($offer['images_json'], true) ?: []
                : ($offer['images_json'] ?? []);

            foreach ($offerImages as $url) {
                if (is_string($url) && !empty($url) && !isset($seen[$url])) {
                    $seen[$url] = true;
                    $images[] = [
                        'url'    => $url,
                        'source' => $offer['supplier_code'] ?? 'offer',
                    ];
                }
            }
        }

        return $images;
    }
}
