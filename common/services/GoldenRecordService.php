<?php

namespace common\services;

use yii\base\Component;
use yii\db\JsonExpression;
use Yii;

/**
 * Сервис пересчёта Golden Record — агрегированных данных модели и варианта.
 *
 * Когда новый оффер привязан к reference_variant, нужно:
 *   1. Пересчитать агрегаты варианта (best_price, is_in_stock, supplier_count)
 *   2. Пересчитать агрегаты модели (price_range, variant_count, offer_count)
 *   3. Обновить canonical_attributes модели, если у нового оффера Data Trust Level выше
 *
 * Data Trust Level (приоритет источника):
 *   1. Данные от производителя (наивысший доверие)
 *   2. Данные от крупных поставщиков (Askona, Ormatek собственные товары)
 *   3. Данные от дилеров (низший доверие)
 */
class GoldenRecordService extends Component
{
    /**
     * Пересчитать агрегаты reference_variant.
     */
    public function recalculateVariant(int $variantId): void
    {
        $db = Yii::$app->db;

        $db->createCommand("
            UPDATE {{%reference_variants}} rv SET
                best_price = (
                    SELECT MIN(so.price_min)
                    FROM {{%supplier_offers}} so
                    WHERE so.variant_id = rv.id AND so.is_active = true AND so.price_min > 0
                ),
                price_range_min = (
                    SELECT MIN(so.price_min)
                    FROM {{%supplier_offers}} so
                    WHERE so.variant_id = rv.id AND so.is_active = true AND so.price_min > 0
                ),
                price_range_max = (
                    SELECT MAX(so.price_max)
                    FROM {{%supplier_offers}} so
                    WHERE so.variant_id = rv.id AND so.is_active = true
                ),
                is_in_stock = EXISTS(
                    SELECT 1 FROM {{%supplier_offers}} so
                    WHERE so.variant_id = rv.id AND so.is_active = true AND so.in_stock = true
                ),
                supplier_count = (
                    SELECT COUNT(DISTINCT so.supplier_id)
                    FROM {{%supplier_offers}} so
                    WHERE so.variant_id = rv.id AND so.is_active = true
                ),
                updated_at = NOW()
            WHERE rv.id = :id
        ", [':id' => $variantId])->execute();
    }

    /**
     * Пересчитать агрегаты product_model.
     */
    public function recalculateModel(int $modelId): void
    {
        $db = Yii::$app->db;

        $db->createCommand("
            UPDATE {{%product_models}} pm SET
                best_price = (
                    SELECT MIN(rv.best_price)
                    FROM {{%reference_variants}} rv
                    WHERE rv.model_id = pm.id AND rv.best_price > 0
                ),
                price_range_min = (
                    SELECT MIN(rv.price_range_min)
                    FROM {{%reference_variants}} rv
                    WHERE rv.model_id = pm.id AND rv.price_range_min > 0
                ),
                price_range_max = (
                    SELECT MAX(rv.price_range_max)
                    FROM {{%reference_variants}} rv
                    WHERE rv.model_id = pm.id
                ),
                variant_count = (
                    SELECT COUNT(*) FROM {{%reference_variants}} rv
                    WHERE rv.model_id = pm.id
                ),
                offer_count = (
                    SELECT COUNT(*) FROM {{%supplier_offers}} so
                    WHERE so.model_id = pm.id AND so.is_active = true
                ),
                supplier_count = (
                    SELECT COUNT(DISTINCT so.supplier_id)
                    FROM {{%supplier_offers}} so
                    WHERE so.model_id = pm.id AND so.is_active = true
                ),
                is_in_stock = EXISTS(
                    SELECT 1 FROM {{%reference_variants}} rv
                    WHERE rv.model_id = pm.id AND rv.is_in_stock = true
                ),
                updated_at = NOW()
            WHERE pm.id = :id
        ", [':id' => $modelId])->execute();
    }

    /**
     * Обновить canonical_attributes модели на основе лучшего оффера.
     *
     * Логика Data Trust Level:
     *   - Если новый оффер от производителя (supplier.code == model.manufacturer) → высший приоритет
     *   - Если атрибуты модели пусты → берём любые
     *   - Иначе → мержим (новые ключи дополняют, но не перезаписывают существующие)
     */
    /**
     * @return bool true если атрибуты были обновлены
     */
    public function updateAttributes(int $modelId, int $supplierId, array $newAttributes): bool
    {
        if (empty($newAttributes)) return false;

        $db = Yii::$app->db;

        // Текущие атрибуты модели
        $current = $db->createCommand(
            "SELECT canonical_attributes FROM {{%product_models}} WHERE id = :id",
            [':id' => $modelId]
        )->queryScalar();

        $currentAttrs = is_string($current) ? (json_decode($current, true) ?: []) : (is_array($current) ? $current : []);

        // Проверяем, является ли поставщик производителем (наивысший приоритет)
        $isManufacturer = $db->createCommand("
            SELECT 1 FROM {{%product_models}} pm
            JOIN {{%suppliers}} s ON LOWER(s.code) = LOWER(pm.manufacturer)
            WHERE pm.id = :model_id AND s.id = :supplier_id
            LIMIT 1
        ", [':model_id' => $modelId, ':supplier_id' => $supplierId])->queryScalar();

        if ($isManufacturer) {
            // Данные от производителя — перезаписываем всё
            $merged = array_merge($currentAttrs, $newAttributes);
        } elseif (empty($currentAttrs)) {
            // Пустые атрибуты — берём любые
            $merged = $newAttributes;
        } else {
            // Мержим: новые ключи дополняют, не перезаписывают
            $merged = $currentAttrs;
            foreach ($newAttributes as $key => $val) {
                if (!isset($merged[$key]) || $merged[$key] === null || $merged[$key] === '') {
                    $merged[$key] = $val;
                }
            }
        }

        if ($merged !== $currentAttrs) {
            $db->createCommand()->update('{{%product_models}}', [
                'canonical_attributes' => new JsonExpression($merged ?: new \stdClass()),
                'updated_at'           => new \yii\db\Expression('NOW()'),
            ], ['id' => $modelId])->execute();
            return true;
        }

        return false;
    }

    /**
     * Обновить описание модели если текущее пустое или короче нового.
     */
    /**
     * @return bool true если описание было обновлено
     */
    public function updateDescription(int $modelId, ?string $description, ?string $shortDescription): bool
    {
        if (empty($description) && empty($shortDescription)) return false;

        $db = Yii::$app->db;

        $current = $db->createCommand(
            "SELECT description, short_description FROM {{%product_models}} WHERE id = :id",
            [':id' => $modelId]
        )->queryOne();

        $updates = [];

        if (!empty($description) && (empty($current['description']) || strlen($description) > strlen($current['description']))) {
            $updates['description'] = $description;
        }

        if (!empty($shortDescription) && empty($current['short_description'])) {
            $updates['short_description'] = $shortDescription;
        }

        if (!empty($updates)) {
            $updates['updated_at'] = new \yii\db\Expression('NOW()');
            $db->createCommand()->update('{{%product_models}}', $updates, ['id' => $modelId])->execute();
            return true;
        }

        return false;
    }
}
