<?php

namespace common\services\channel;

use common\models\SalesChannel;

/**
 * Интерфейс синдикатора (Strategy) — строит проекцию для конкретного канала.
 *
 * Каждый канал (RosMatras, Ozon, WB, Yandex) имеет свой формат проекции:
 *   - RosMatras: плоский JSON с selector_axes
 *   - Ozon:      Ozon Product API format (description_category_id, attributes, ...)
 *   - WB:        Wildberries card format (nmID, subjectID, addin, ...)
 *
 * === Fast-Lane (Sprint 10) ===
 * Три типа проекций:
 *   - buildProjection()      → полная проекция (контент, атрибуты, картинки) — ТЯЖЁЛАЯ
 *   - buildPriceProjection() → только SKU + цены — ЛЁГКАЯ
 *   - buildStockProjection() → только SKU + остатки — ЛЁГКАЯ
 *
 * Маркетплейсы имеют отдельные эндпоинты для обновления цен и остатков,
 * которые работают быстрее полного обновления карточки.
 */
interface SyndicatorInterface
{
    /**
     * Построить полную проекцию товара (контент + атрибуты + картинки).
     * Lane: content_updated
     *
     * @param int          $modelId ID product_model в MDM
     * @param SalesChannel $channel Канал продаж
     *
     * @return array|null Проекция в формате канала или null
     */
    public function buildProjection(int $modelId, SalesChannel $channel): ?array;

    /**
     * Построить лёгкую проекцию цен (только SKU + Price).
     * Lane: price_updated
     *
     * Формат (рекомендуемый):
     *   [
     *     'model_id' => 123,
     *     'items' => [
     *       ['variant_id' => 1, 'sku' => 'GTIN-001', 'price' => 12500, 'compare_price' => 15000],
     *       ['variant_id' => 2, 'sku' => 'GTIN-002', 'price' => 14000, 'compare_price' => null],
     *     ]
     *   ]
     *
     * @param int          $modelId ID product_model в MDM
     * @param SalesChannel $channel Канал продаж
     *
     * @return array|null Проекция цен или null
     */
    public function buildPriceProjection(int $modelId, SalesChannel $channel): ?array;

    /**
     * Построить лёгкую проекцию остатков (только SKU + Qty).
     * Lane: stock_updated
     *
     * Формат (рекомендуемый):
     *   [
     *     'model_id' => 123,
     *     'items' => [
     *       ['variant_id' => 1, 'sku' => 'GTIN-001', 'in_stock' => true,  'stock_status' => 'available'],
     *       ['variant_id' => 2, 'sku' => 'GTIN-002', 'in_stock' => false, 'stock_status' => 'out_of_stock'],
     *     ]
     *   ]
     *
     * @param int          $modelId ID product_model в MDM
     * @param SalesChannel $channel Канал продаж
     *
     * @return array|null Проекция остатков или null
     */
    public function buildStockProjection(int $modelId, SalesChannel $channel): ?array;
}
