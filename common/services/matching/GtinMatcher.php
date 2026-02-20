<?php

namespace common\services\matching;

use common\dto\MatchResult;
use common\dto\ProductDTO;
use Yii;

/**
 * Матчер по GTIN/EAN штрихкоду — 100% точное совпадение.
 *
 * GTIN (Global Trade Item Number) / EAN-13 — уникальный идентификатор товара.
 * Если штрихкод совпадает → это тот же товар, без вариантов.
 *
 * Приоритет: 10 (первый в цепочке).
 * Уверенность: 1.0
 */
class GtinMatcher implements ProductMatcherInterface
{
    public function getName(): string
    {
        return 'gtin';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function match(ProductDTO $dto, array $context = []): ?MatchResult
    {
        // Извлекаем GTIN из атрибутов или rawData
        $gtin = $this->extractGtin($dto);
        if (empty($gtin)) {
            return null; // Нет GTIN — передаём следующему матчеру
        }

        // Поиск по unique index
        $row = Yii::$app->db->createCommand("
            SELECT rv.id AS variant_id, rv.model_id
            FROM {{%reference_variants}} rv
            WHERE rv.gtin = :gtin
            LIMIT 1
        ", [':gtin' => $gtin])->queryOne();

        if (!$row) {
            return null; // GTIN не найден в каталоге
        }

        Yii::info(
            "GtinMatcher: MATCH gtin={$gtin} → variant_id={$row['variant_id']} model_id={$row['model_id']}",
            'matching'
        );

        return MatchResult::found(
            variantId:   (int)$row['variant_id'],
            modelId:     (int)$row['model_id'],
            matcherName: $this->getName(),
            confidence:  1.0,
            details:     ['gtin' => $gtin],
        );
    }

    /**
     * Извлечь GTIN из DTO (атрибуты, rawData, варианты).
     */
    protected function extractGtin(ProductDTO $dto): ?string
    {
        // Прямое поле
        $candidates = [
            $dto->attributes['gtin'] ?? null,
            $dto->attributes['ean'] ?? null,
            $dto->attributes['ean13'] ?? null,
            $dto->attributes['barcode'] ?? null,
            $dto->rawData['gtin'] ?? null,
            $dto->rawData['ean'] ?? null,
            $dto->rawData['barcode'] ?? null,
        ];

        foreach ($candidates as $val) {
            $gtin = $this->normalizeGtin($val);
            if ($gtin !== null) {
                return $gtin;
            }
        }

        return null;
    }

    /**
     * Нормализовать и валидировать GTIN.
     * Допустимые форматы: EAN-8 (8 цифр), EAN-13 (13 цифр), UPC-A (12 цифр), GTIN-14 (14 цифр).
     */
    protected function normalizeGtin(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // Убираем пробелы и тире
        $clean = preg_replace('/[\s\-]/', '', trim($value));

        // Только цифры
        if (!preg_match('/^\d{8,14}$/', $clean)) {
            return null;
        }

        // Дополняем до 13 цифр (EAN-13) если нужно
        if (strlen($clean) === 12) {
            $clean = '0' . $clean; // UPC-A → EAN-13
        }

        // Принимаем 8, 13, 14 цифр
        if (!in_array(strlen($clean), [8, 13, 14])) {
            return null;
        }

        return $clean;
    }
}
