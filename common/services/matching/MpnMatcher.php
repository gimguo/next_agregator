<?php

namespace common\services\matching;

use common\dto\MatchResult;
use common\dto\ProductDTO;
use Yii;

/**
 * Матчер по связке Brand + MPN (Manufacturer Part Number).
 *
 * MPN — артикул производителя (например, "ОПТ-160-200").
 * Ищем точное совпадение: brand_id модели + mpn варианта.
 *
 * Приоритет: 20 (после GTIN).
 * Уверенность: 0.95
 */
class MpnMatcher implements ProductMatcherInterface
{
    public function getName(): string
    {
        return 'mpn';
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function match(ProductDTO $dto, array $context = []): ?MatchResult
    {
        $mpn = $this->extractMpn($dto);
        $brandId = $context['brand_id'] ?? null;

        if (empty($mpn)) {
            return null; // Нет MPN — передаём следующему
        }

        // Если brand_id неизвестен, ищем по manufacturer name
        if (!$brandId && !empty($dto->manufacturer)) {
            $brandId = $this->resolveBrandId($dto->manufacturer);
        }

        if (!$brandId) {
            // Без бренда MPN неоднозначен (разные бренды могут иметь одинаковые артикулы)
            // Пробуем найти просто по MPN если он уникален
            return $this->matchByMpnOnly($mpn, $dto);
        }

        // Ищем: reference_variants.mpn = :mpn AND product_models.brand_id = :brand_id
        $row = Yii::$app->db->createCommand("
            SELECT rv.id AS variant_id, rv.model_id
            FROM {{%reference_variants}} rv
            JOIN {{%product_models}} pm ON pm.id = rv.model_id
            WHERE rv.mpn = :mpn AND pm.brand_id = :brand_id
            LIMIT 1
        ", [':mpn' => $mpn, ':brand_id' => $brandId])->queryOne();

        if (!$row) {
            return null;
        }

        Yii::info(
            "MpnMatcher: MATCH brand_id={$brandId} mpn={$mpn} → variant_id={$row['variant_id']}",
            'matching'
        );

        return MatchResult::found(
            variantId:   (int)$row['variant_id'],
            modelId:     (int)$row['model_id'],
            matcherName: $this->getName(),
            confidence:  0.95,
            details:     ['mpn' => $mpn, 'brand_id' => $brandId],
        );
    }

    /**
     * Фолбэк: ищем только по MPN (без бренда), но только если результат уникален.
     */
    protected function matchByMpnOnly(string $mpn, ProductDTO $dto): ?MatchResult
    {
        $rows = Yii::$app->db->createCommand("
            SELECT rv.id AS variant_id, rv.model_id
            FROM {{%reference_variants}} rv
            WHERE rv.mpn = :mpn
        ", [':mpn' => $mpn])->queryAll();

        // Только если ровно одно совпадение
        if (count($rows) !== 1) {
            return null;
        }

        $row = $rows[0];

        Yii::info(
            "MpnMatcher: MATCH (mpn-only) mpn={$mpn} → variant_id={$row['variant_id']}",
            'matching'
        );

        return MatchResult::found(
            variantId:   (int)$row['variant_id'],
            modelId:     (int)$row['model_id'],
            matcherName: $this->getName(),
            confidence:  0.80, // Ниже уверенность без бренда
            details:     ['mpn' => $mpn, 'brand_id' => null, 'mpn_only' => true],
        );
    }

    protected function extractMpn(ProductDTO $dto): ?string
    {
        $candidates = [
            $dto->supplierSku,
            $dto->attributes['mpn'] ?? null,
            $dto->attributes['article'] ?? null,
            $dto->attributes['artikul'] ?? null,
            $dto->rawData['mpn'] ?? null,
            $dto->rawData['article'] ?? null,
        ];

        foreach ($candidates as $val) {
            if (!empty($val) && is_string($val) && strlen(trim($val)) >= 3) {
                return trim($val);
            }
        }

        return null;
    }

    protected function resolveBrandId(string $manufacturer): ?int
    {
        // Точное совпадение
        $id = Yii::$app->db->createCommand(
            "SELECT id FROM {{%brands}} WHERE canonical_name = :name LIMIT 1",
            [':name' => $manufacturer]
        )->queryScalar();

        if ($id) return (int)$id;

        // Через алиасы
        $id = Yii::$app->db->createCommand(
            "SELECT brand_id FROM {{%brand_aliases}} WHERE alias ILIKE :name LIMIT 1",
            [':name' => $manufacturer]
        )->queryScalar();

        return $id ? (int)$id : null;
    }
}
