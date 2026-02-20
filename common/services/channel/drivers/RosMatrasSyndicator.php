<?php

namespace common\services\channel\drivers;

use common\models\SalesChannel;
use common\services\channel\SyndicatorInterface;
use common\services\RosMatrasSyndicationService;
use yii\base\Component;
use Yii;

/**
 * RosMatras Syndicator — адаптер существующего RosMatrasSyndicationService
 * под интерфейс SyndicatorInterface для многоканальной архитектуры.
 *
 * === Full Content ===
 *   Делегирует RosMatrasSyndicationService::buildProductProjection()
 *   для полной проекции (контент, атрибуты, картинки, selector_axes).
 *
 * === Fast-Lane (Sprint 10) ===
 *   buildPriceProjection() — лёгкая проекция: только variant_id + GTIN + цена.
 *   buildStockProjection() — лёгкая проекция: только variant_id + GTIN + наличие.
 *
 *   RosMatras пока не имеет отдельных эндпоинтов для цен/остатков,
 *   поэтому Fast-Lane проекции отправляются через тот же /import/product,
 *   но payload значительно меньше (без картинок, описаний, атрибутов).
 */
class RosMatrasSyndicator extends Component implements SyndicatorInterface
{
    /** @var RosMatrasSyndicationService|null */
    private ?RosMatrasSyndicationService $service = null;

    /**
     * {@inheritdoc}
     * Полная проекция (lane: content_updated).
     */
    public function buildProjection(int $modelId, SalesChannel $channel): ?array
    {
        return $this->getService()->buildProductProjection($modelId);
    }

    /**
     * {@inheritdoc}
     * Лёгкая проекция цен (lane: price_updated).
     *
     * Возвращает:
     *   [
     *     'model_id' => 123,
     *     'items' => [
     *       ['variant_id' => 1, 'sku' => 'GTIN-001', 'price' => 12500.00, 'compare_price' => 15000.00],
     *       ...
     *     ]
     *   ]
     */
    public function buildPriceProjection(int $modelId, SalesChannel $channel): ?array
    {
        $db = Yii::$app->db;

        // Проверяем что модель существует
        $modelExists = $db->createCommand("
            SELECT 1 FROM {{%product_models}} WHERE id = :id
        ", [':id' => $modelId])->queryScalar();

        if (!$modelExists) {
            return null;
        }

        // Получаем варианты с лучшими ценами
        $variants = $db->createCommand("
            SELECT
                rv.id AS variant_id,
                rv.gtin,
                rv.best_price,
                rv.price_range_min,
                rv.price_range_max
            FROM {{%reference_variants}} rv
            WHERE rv.model_id = :model_id
            ORDER BY rv.sort_order, rv.id
        ", [':model_id' => $modelId])->queryAll();

        if (empty($variants)) {
            return null;
        }

        // Получаем compare_price из лучших офферов
        $variantIds = array_column($variants, 'variant_id');
        $inParams = [];
        $inPlaceholders = [];
        foreach ($variantIds as $i => $vid) {
            $key = ':vid' . $i;
            $inParams[$key] = $vid;
            $inPlaceholders[] = $key;
        }
        $inSql = implode(',', $inPlaceholders);

        $comparePrices = $db->createCommand(
            "SELECT DISTINCT ON (so.variant_id)
                so.variant_id,
                so.compare_price
            FROM {{%supplier_offers}} so
            WHERE so.variant_id IN ({$inSql})
                AND so.is_active = true
            ORDER BY so.variant_id, so.price_min ASC NULLS LAST",
            $inParams
        )->queryAll();

        $comparePriceMap = [];
        foreach ($comparePrices as $cp) {
            $comparePriceMap[(int)$cp['variant_id']] = $cp['compare_price'] ? (float)$cp['compare_price'] : null;
        }

        $items = [];
        foreach ($variants as $v) {
            $varId = (int)$v['variant_id'];
            $items[] = [
                'variant_id'    => $varId,
                'sku'           => $v['gtin'] ?: 'V' . $varId,
                'price'         => $v['best_price'] ? (float)$v['best_price'] : null,
                'compare_price' => $comparePriceMap[$varId] ?? null,
            ];
        }

        return [
            'model_id' => $modelId,
            'items'    => $items,
        ];
    }

    /**
     * {@inheritdoc}
     * Лёгкая проекция остатков (lane: stock_updated).
     *
     * Возвращает:
     *   [
     *     'model_id' => 123,
     *     'items' => [
     *       ['variant_id' => 1, 'sku' => 'GTIN-001', 'in_stock' => true, 'stock_status' => 'available'],
     *       ...
     *     ]
     *   ]
     */
    public function buildStockProjection(int $modelId, SalesChannel $channel): ?array
    {
        $db = Yii::$app->db;

        // Проверяем что модель существует
        $modelExists = $db->createCommand("
            SELECT 1 FROM {{%product_models}} WHERE id = :id
        ", [':id' => $modelId])->queryScalar();

        if (!$modelExists) {
            return null;
        }

        // Получаем варианты с наличием
        $variants = $db->createCommand("
            SELECT
                rv.id AS variant_id,
                rv.gtin,
                rv.is_in_stock
            FROM {{%reference_variants}} rv
            WHERE rv.model_id = :model_id
            ORDER BY rv.sort_order, rv.id
        ", [':model_id' => $modelId])->queryAll();

        if (empty($variants)) {
            return null;
        }

        // Получаем stock_status из лучших офферов
        $variantIds = array_column($variants, 'variant_id');
        $inParams = [];
        $inPlaceholders = [];
        foreach ($variantIds as $i => $vid) {
            $key = ':vid' . $i;
            $inParams[$key] = $vid;
            $inPlaceholders[] = $key;
        }
        $inSql = implode(',', $inPlaceholders);

        $stockStatuses = $db->createCommand(
            "SELECT DISTINCT ON (so.variant_id)
                so.variant_id,
                so.stock_status,
                so.in_stock
            FROM {{%supplier_offers}} so
            WHERE so.variant_id IN ({$inSql})
                AND so.is_active = true
            ORDER BY so.variant_id, so.price_min ASC NULLS LAST",
            $inParams
        )->queryAll();

        $stockMap = [];
        foreach ($stockStatuses as $ss) {
            $stockMap[(int)$ss['variant_id']] = [
                'stock_status' => $ss['stock_status'] ?: ($ss['in_stock'] ? 'available' : 'out_of_stock'),
                'in_stock'     => (bool)$ss['in_stock'],
            ];
        }

        $items = [];
        foreach ($variants as $v) {
            $varId = (int)$v['variant_id'];
            $stock = $stockMap[$varId] ?? ['stock_status' => 'unknown', 'in_stock' => false];

            $items[] = [
                'variant_id'   => $varId,
                'sku'          => $v['gtin'] ?: 'V' . $varId,
                'in_stock'     => (bool)$v['is_in_stock'],
                'stock_status' => $stock['stock_status'],
            ];
        }

        return [
            'model_id' => $modelId,
            'items'    => $items,
        ];
    }

    /**
     * Получить базовый сервис (lazy load из DI).
     */
    private function getService(): RosMatrasSyndicationService
    {
        if ($this->service === null) {
            $this->service = Yii::$app->get('syndicationService');
        }
        return $this->service;
    }
}
