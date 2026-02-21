<?php

namespace common\services;

use yii\base\Component;
use yii\db\Connection;
use yii\db\JsonExpression;
use Yii;

/**
 * Sprint 16: Variant Explosion — «Взрыв вариантов».
 *
 * Проблема:
 *   CatalogPersisterService создавал ОДИН reference_variant на supplier_offer,
 *   а все суб-варианты (80×190, 80×200, 90×200…) лежали как JSON в supplier_offers.variants_json.
 *   Результат: 872K реальных вариантов заперты внутри 2587 записей-«заглушек».
 *
 * Решение:
 *   Для каждого supplier_offer парсим variants_json, извлекаем уникальные размеры,
 *   создаём reference_variant PER SIZE и перепривязываем цены/наличие.
 *
 * Стратегия группировки:
 *   Ключ варианта = «Размер» (WxL). Декор / тип декора — НЕ вариантообразующие
 *   (у одного размера может быть 10 декоров — берём лучшую цену среди них).
 *
 * Использование:
 *   $exploder = Yii::$app->get('variantExploder');
 *   $stats = $exploder->explodeModel($modelId);
 *   // или
 *   $stats = $exploder->explodeAll(100); // батчами по 100 моделей
 */
class VariantExploderService extends Component
{
    /** @var GoldenRecordService */
    private GoldenRecordService $goldenRecord;

    /** @var OutboxService */
    private OutboxService $outbox;

    public function init(): void
    {
        parent::init();
        $this->goldenRecord = Yii::$app->get('goldenRecord');
        $this->outbox = Yii::$app->get('outbox');
    }

    /**
     * «Взорвать» варианты одной модели.
     *
     * @return array{created: int, updated: int, deleted: int, sizes_found: int}
     */
    public function explodeModel(int $modelId): array
    {
        $db = Yii::$app->db;
        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'sizes_found' => 0];

        // 1. Собираем ВСЕ суб-варианты из ВСЕХ офферов этой модели
        $offers = $db->createCommand("
            SELECT id, variant_id, variants_json, price_min, compare_price, in_stock
            FROM {{%supplier_offers}}
            WHERE model_id = :model_id AND is_active = true
        ", [':model_id' => $modelId])->queryAll();

        if (empty($offers)) {
            return $stats;
        }

        // 2. Группируем по размеру: "80x200" → [{price, compare_price, in_stock, offer_id}, ...]
        $sizeGroups = $this->groupBySize($offers);
        $stats['sizes_found'] = count($sizeGroups);

        if (empty($sizeGroups)) {
            // Нет распознанных размеров — оставляем как есть
            return $stats;
        }

        // 3. Получаем существующие reference_variants
        $existingVariants = $db->createCommand("
            SELECT id, variant_attributes::text as attrs_text, variant_label
            FROM {{%reference_variants}}
            WHERE model_id = :model_id
        ", [':model_id' => $modelId])->queryAll();

        $existingByLabel = [];
        foreach ($existingVariants as $ev) {
            $existingByLabel[$ev['variant_label']] = (int)$ev['id'];
        }

        // 4. Для каждого размера — создаём/обновляем reference_variant
        $newVariantIds = [];
        $sortOrder = 0;

        foreach ($sizeGroups as $sizeKey => $groupData) {
            $sortOrder++;
            $width = $groupData['width'];
            $length = $groupData['length'];
            $label = $width . '×' . $length;
            $attrs = ['width' => $width, 'length' => $length];

            // Агрегируем: лучшая цена, наличие
            $bestPrice = null;
            $priceMin = null;
            $priceMax = null;
            $comparePrice = null;
            $inStock = false;
            $supplierCount = 0;
            $seenSuppliers = [];

            foreach ($groupData['items'] as $item) {
                $price = (float)$item['price'];
                if ($price > 0) {
                    if ($bestPrice === null || $price < $bestPrice) {
                        $bestPrice = $price;
                    }
                    if ($priceMin === null || $price < $priceMin) {
                        $priceMin = $price;
                    }
                    if ($priceMax === null || $price > $priceMax) {
                        $priceMax = $price;
                    }
                }
                if ($item['compare_price'] && (float)$item['compare_price'] > 0) {
                    if ($comparePrice === null || (float)$item['compare_price'] > $comparePrice) {
                        $comparePrice = (float)$item['compare_price'];
                    }
                }
                if ($item['in_stock']) {
                    $inStock = true;
                }
                if (!in_array($item['offer_id'], $seenSuppliers)) {
                    $seenSuppliers[] = $item['offer_id'];
                    $supplierCount++;
                }
            }

            // Ищем существующий variant по label
            $variantId = $existingByLabel[$label] ?? null;

            if ($variantId) {
                // Обновляем
                $db->createCommand()->update('{{%reference_variants}}', [
                    'variant_attributes' => new JsonExpression($attrs),
                    'variant_label'      => $label,
                    'best_price'         => $bestPrice,
                    'price_range_min'    => $priceMin,
                    'price_range_max'    => $priceMax,
                    'is_in_stock'        => $inStock,
                    'supplier_count'     => $supplierCount,
                    'sort_order'         => $sortOrder,
                    'updated_at'         => new \yii\db\Expression('NOW()'),
                ], ['id' => $variantId])->execute();
                $stats['updated']++;
            } else {
                // Создаём новый
                $db->createCommand()->insert('{{%reference_variants}}', [
                    'model_id'           => $modelId,
                    'variant_attributes' => new JsonExpression($attrs),
                    'variant_label'      => $label,
                    'best_price'         => $bestPrice,
                    'price_range_min'    => $priceMin,
                    'price_range_max'    => $priceMax,
                    'is_in_stock'        => $inStock,
                    'supplier_count'     => $supplierCount,
                    'sort_order'         => $sortOrder,
                ])->execute();
                $variantId = (int)$db->getLastInsertID('reference_variants_id_seq');
                $stats['created']++;
            }

            $newVariantIds[$label] = $variantId;
        }

        // 5. Перепривязываем supplier_offers к первому подходящему варианту (самый дешёвый размер)
        // Каждый offer сейчас указывает на «заглушку» — привяжем к первому варианту (сортировка по цене)
        $firstVariantId = reset($newVariantIds);
        foreach ($offers as $offer) {
            $offerId = (int)$offer['id'];
            $oldVariantId = (int)$offer['variant_id'];

            // Если у оффера variant_id указывает на старую заглушку — перенаправляем
            if (!in_array($oldVariantId, $newVariantIds)) {
                $db->createCommand()->update('{{%supplier_offers}}', [
                    'variant_id' => $firstVariantId,
                    'updated_at' => new \yii\db\Expression('NOW()'),
                ], ['id' => $offerId])->execute();
            }
        }

        // 6. Удаляем старые «заглушки» (варианты без офферов)
        $allNewIds = array_values($newVariantIds);
        $oldVariantIds = array_column($existingVariants, 'id');
        $toDelete = array_diff(array_map('intval', $oldVariantIds), $allNewIds);

        foreach ($toDelete as $deadId) {
            // Проверяем что нет офферов
            $offerCount = $db->createCommand(
                "SELECT COUNT(*) FROM {{%supplier_offers}} WHERE variant_id = :vid",
                [':vid' => $deadId]
            )->queryScalar();

            if ((int)$offerCount === 0) {
                $db->createCommand()->delete('{{%reference_variants}}', ['id' => $deadId])->execute();
                $stats['deleted']++;
            }
        }

        // 7. Пересчитываем Golden Record ТОЛЬКО для модели
        // НЕ вызываем recalculateVariant — он перезатрёт наши цены из variants_json,
        // потому что один supplier_offer привязан только к первому варианту.
        // Цены мы уже установили корректно в шаге 4 из variants_json.
        $this->goldenRecord->recalculateModel($modelId);

        // 8. Помечаем модель для ресинка
        $this->outbox->emitContentUpdate($modelId, 'variant-exploder');

        return $stats;
    }

    /**
     * «Взорвать» все модели с не-разложенными вариантами.
     *
     * @param int $limit Максимум моделей за один запуск
     * @param callable|null $progressCallback fn(int $current, int $total, string $modelName, array $stats)
     * @return array{models_processed: int, total_created: int, total_updated: int, total_deleted: int}
     */
    public function explodeAll(int $limit = 0, ?callable $progressCallback = null): array
    {
        $db = Yii::$app->db;

        // Находим модели, которые нуждаются в explode:
        // 1. Заглушки: variant_attributes = '{}' / label = 'Основной'
        // 2. Варианты с нулевой ценой (GoldenRecord мог затереть)
        $sql = "
            SELECT DISTINCT pm.id, pm.name
            FROM {{%product_models}} pm
            JOIN {{%supplier_offers}} so ON so.model_id = pm.id AND so.is_active = true
            WHERE jsonb_array_length(COALESCE(so.variants_json, '[]'::jsonb)) > 1
              AND (
                EXISTS (
                    SELECT 1 FROM {{%reference_variants}} rv 
                    WHERE rv.model_id = pm.id
                      AND (rv.variant_attributes = '{}' OR rv.variant_attributes IS NULL OR rv.variant_label = 'Основной')
                )
                OR EXISTS (
                    SELECT 1 FROM {{%reference_variants}} rv
                    WHERE rv.model_id = pm.id
                      AND rv.variant_label != 'Основной'
                      AND (rv.best_price IS NULL OR rv.best_price = 0)
                )
              )
            ORDER BY pm.id
        ";

        if ($limit > 0) {
            $sql .= " LIMIT {$limit}";
        }

        $models = $db->createCommand($sql)->queryAll();
        $total = count($models);

        $totals = [
            'models_processed' => 0,
            'total_created'    => 0,
            'total_updated'    => 0,
            'total_deleted'    => 0,
            'total_sizes'      => 0,
            'models_skipped'   => 0,
        ];

        foreach ($models as $idx => $model) {
            $modelId = (int)$model['id'];

            try {
                $stats = $this->explodeModel($modelId);
                $totals['models_processed']++;
                $totals['total_created'] += $stats['created'];
                $totals['total_updated'] += $stats['updated'];
                $totals['total_deleted'] += $stats['deleted'];
                $totals['total_sizes'] += $stats['sizes_found'];

                if ($progressCallback) {
                    $progressCallback($idx + 1, $total, $model['name'], $stats);
                }
            } catch (\Throwable $e) {
                $totals['models_skipped']++;
                Yii::warning(
                    "VariantExploder: ошибка model_id={$modelId}: {$e->getMessage()}",
                    'catalog'
                );

                if ($progressCallback) {
                    $progressCallback($idx + 1, $total, $model['name'] . ' [ОШИБКА]', ['error' => $e->getMessage()]);
                }
            }
        }

        return $totals;
    }

    /**
     * Парсим variants_json всех офферов, группируем по размеру.
     *
     * @return array<string, array{width: int, length: int, items: array}>
     */
    protected function groupBySize(array $offers): array
    {
        $groups = [];

        foreach ($offers as $offer) {
            $offerId = (int)$offer['id'];
            $variantsJson = $offer['variants_json'];

            if (is_string($variantsJson)) {
                $variants = json_decode($variantsJson, true) ?: [];
            } else {
                $variants = $variantsJson ?: [];
            }

            foreach ($variants as $subVar) {
                $options = $subVar['options'] ?? [];
                $sizeStr = $options['Размер'] ?? $options['размер'] ?? $options['size'] ?? null;

                if (!$sizeStr) {
                    // Пробуем парсить из name
                    $name = $subVar['name'] ?? '';
                    if (preg_match('/(\d{2,4})\s*[xхXХ×\*]\s*(\d{2,4})/', $name, $m)) {
                        $sizeStr = $m[1] . 'x' . $m[2];
                    }
                }

                if (!$sizeStr) continue;

                // Парсим "80x200" → width=80, length=200
                if (!preg_match('/(\d{2,4})\s*[xхXХ×\*]\s*(\d{2,4})/', $sizeStr, $m)) {
                    continue;
                }

                $w = (int)$m[1];
                $l = (int)$m[2];

                // Sanity check
                if ($w < 30 || $w > 400 || $l < 30 || $l > 400) {
                    continue;
                }

                $key = "{$w}x{$l}";

                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'width'  => $w,
                        'length' => $l,
                        'items'  => [],
                    ];
                }

                $groups[$key]['items'][] = [
                    'offer_id'      => $offerId,
                    'sku'           => $subVar['sku'] ?? null,
                    'price'         => $subVar['price'] ?? $offer['price_min'],
                    'compare_price' => $subVar['compare_price'] ?? $offer['compare_price'],
                    'in_stock'      => $subVar['in_stock'] ?? $offer['in_stock'],
                ];
            }
        }

        // Сортируем группы: сначала по ширине, потом по длине
        uksort($groups, function ($a, $b) {
            [$aw, $al] = explode('x', $a);
            [$bw, $bl] = explode('x', $b);
            return ((int)$aw * 1000 + (int)$al) - ((int)$bw * 1000 + (int)$bl);
        });

        return $groups;
    }
}
