<?php

namespace common\services\matching;

use common\dto\MatchResult;
use common\dto\ProductDTO;
use common\enums\ProductFamily;
use common\services\ProductFamilySchema;
use Yii;

/**
 * Композитный матчер по family + brand + model + ключевые атрибуты.
 *
 * Фолбэк-стратегия: если GTIN и MPN не дали результата,
 * ищем по комбинации бренд + название модели + оси вариаций.
 *
 * Важно: НЕ склеивает разные размеры одной модели!
 *   "Орматек Оптима 160×200" ≠ "Орматек Оптима 180×200"
 *   Это разные reference_variants одной product_model.
 *
 * Алгоритм:
 *   1. Ищем product_model по (brand_id + model_name) или (manufacturer + model_name)
 *   2. Если модель найдена → ищем reference_variant по variant_attributes (width + length)
 *   3. Если вариант найден → матч!
 *   4. Если модель найдена но вариант нет → возвращаем modelId (вариант будет создан)
 *
 * Приоритет: 30 (последний в цепочке).
 * Уверенность: 0.70–0.90
 */
class CompositeAttributeMatcher implements ProductMatcherInterface
{
    public function getName(): string
    {
        return 'composite';
    }

    public function getPriority(): int
    {
        return 30;
    }

    public function match(ProductDTO $dto, array $context = []): ?MatchResult
    {
        $brandId = $context['brand_id'] ?? null;
        $family = $context['product_family'] ?? null;

        // Определяем бренд
        if (!$brandId && !empty($dto->manufacturer)) {
            $brandId = $this->resolveBrandId($dto->manufacturer);
        }

        // Определяем название модели
        $modelName = $this->extractModelName($dto);
        if (empty($modelName)) {
            return null;
        }

        // Определяем ProductFamily
        if (!$family) {
            $family = ProductFamily::detect($dto->name . ' ' . ($dto->categoryPath ?? ''))->value;
        }

        // ═══ ШАГ 1: Ищем product_model ═══
        $modelId = $this->findModel($brandId, $modelName, $dto->manufacturer);
        if (!$modelId) {
            // Модель не найдена — пробуем fuzzy
            $modelId = $this->findModelFuzzy($brandId, $modelName, $family);
        }

        if (!$modelId) {
            return null; // Модель не найдена → товар новый
        }

        // ═══ ШАГ 2: Ищем reference_variant по атрибутам ═══
        $variantAttrs = $this->extractVariantAttributes($dto, $family);

        if (!empty($variantAttrs)) {
            $variantId = $this->findVariant($modelId, $variantAttrs);

            if ($variantId) {
                Yii::info(
                    "CompositeAttributeMatcher: MATCH model_id={$modelId} variant_id={$variantId} " .
                    "attrs=" . json_encode($variantAttrs),
                    'matching'
                );

                return MatchResult::found(
                    variantId:   $variantId,
                    modelId:     $modelId,
                    matcherName: $this->getName(),
                    confidence:  0.85,
                    details:     [
                        'brand_id'   => $brandId,
                        'model_name' => $modelName,
                        'attrs'      => $variantAttrs,
                    ],
                );
            }
        }

        // Модель найдена, но вариант с такими атрибутами — нет.
        // Возвращаем modelId чтобы CatalogPersister создал только новый variant.
        Yii::info(
            "CompositeAttributeMatcher: MODEL MATCH model_id={$modelId}, NEW VARIANT attrs=" . json_encode($variantAttrs),
            'matching'
        );

        return MatchResult::found(
            variantId:   null,               // Варианта нет — создать!
            modelId:     $modelId,
            matcherName: $this->getName(),
            confidence:  0.70,
            details:     [
                'brand_id'     => $brandId,
                'model_name'   => $modelName,
                'attrs'        => $variantAttrs,
                'model_exists' => true,
                'variant_new'  => true,
            ],
        );
    }

    // ═══════════════════════════════════════════
    // MODEL SEARCH
    // ═══════════════════════════════════════════

    /**
     * Точный поиск модели: brand_id + model_name или manufacturer + model_name.
     */
    protected function findModel(?int $brandId, string $modelName, ?string $manufacturer): ?int
    {
        // Стратегия 1: brand_id + model_name (точное)
        if ($brandId) {
            $id = Yii::$app->db->createCommand("
                SELECT id FROM {{%product_models}}
                WHERE brand_id = :brand_id AND LOWER(model_name) = LOWER(:model)
                LIMIT 1
            ", [':brand_id' => $brandId, ':model' => $modelName])->queryScalar();

            if ($id) return (int)$id;
        }

        // Стратегия 2: manufacturer + model_name (для неразрешённых брендов)
        if (!empty($manufacturer)) {
            $id = Yii::$app->db->createCommand("
                SELECT id FROM {{%product_models}}
                WHERE LOWER(manufacturer) = LOWER(:mfr) AND LOWER(model_name) = LOWER(:model)
                LIMIT 1
            ", [':mfr' => $manufacturer, ':model' => $modelName])->queryScalar();

            if ($id) return (int)$id;
        }

        // Стратегия 3: brand_id + FULL NAME match
        if ($brandId) {
            $id = Yii::$app->db->createCommand("
                SELECT id FROM {{%product_models}}
                WHERE brand_id = :brand_id AND LOWER(name) = LOWER(:name)
                LIMIT 1
            ", [':brand_id' => $brandId, ':name' => $modelName])->queryScalar();

            if ($id) return (int)$id;
        }

        return null;
    }

    /**
     * Fuzzy-поиск модели через trigram similarity.
     * Только при high similarity (> 0.6) и в рамках одного бренда/семейства.
     */
    protected function findModelFuzzy(?int $brandId, string $modelName, string $family): ?int
    {
        if (!$brandId) return null;

        $row = Yii::$app->db->createCommand("
            SELECT id, name, similarity(LOWER(model_name), LOWER(:model)) AS sim
            FROM {{%product_models}}
            WHERE brand_id = :brand_id
              AND product_family = :family
              AND similarity(LOWER(model_name), LOWER(:model)) > 0.6
            ORDER BY sim DESC
            LIMIT 1
        ", [
            ':brand_id' => $brandId,
            ':family'   => $family,
            ':model'    => $modelName,
        ])->queryOne();

        if ($row) {
            Yii::info(
                "CompositeAttributeMatcher: fuzzy model match sim={$row['sim']} " .
                "'{$modelName}' ≈ '{$row['name']}' → model_id={$row['id']}",
                'matching'
            );
            return (int)$row['id'];
        }

        return null;
    }

    // ═══════════════════════════════════════════
    // VARIANT SEARCH
    // ═══════════════════════════════════════════

    /**
     * Поиск reference_variant по model_id + ключевые JSONB-атрибуты.
     *
     * Сравнивает оси вариаций (width, length для матрасов; color для кроватей).
     * НЕ склеивает 160×200 и 180×200.
     */
    protected function findVariant(int $modelId, array $attrs): ?int
    {
        if (empty($attrs)) {
            // Нет атрибутов вариации — ищем единственный вариант без атрибутов
            $id = Yii::$app->db->createCommand("
                SELECT id FROM {{%reference_variants}}
                WHERE model_id = :model_id AND variant_attributes = '{}'::jsonb
                LIMIT 1
            ", [':model_id' => $modelId])->queryScalar();

            return $id ? (int)$id : null;
        }

        // Строим JSONB containment query: variant_attributes @> '{"width": 160, "length": 200}'
        $jsonFilter = json_encode($attrs, JSON_UNESCAPED_UNICODE);

        $id = Yii::$app->db->createCommand("
            SELECT id FROM {{%reference_variants}}
            WHERE model_id = :model_id AND variant_attributes @> :attrs::jsonb
            LIMIT 1
        ", [':model_id' => $modelId, ':attrs' => $jsonFilter])->queryScalar();

        return $id ? (int)$id : null;
    }

    // ═══════════════════════════════════════════
    // ATTRIBUTE EXTRACTION
    // ═══════════════════════════════════════════

    /**
     * Извлечь атрибуты, определяющие вариант (оси вариаций).
     *
     * Для матрасов: width, length
     * Для кроватей: width, length, color
     * Для подушек: width, length, height
     */
    protected function extractVariantAttributes(ProductDTO $dto, string $family): array
    {
        $familyEnum = ProductFamily::tryFrom($family);
        if (!$familyEnum) {
            return $this->extractDimensionsFromName($dto);
        }

        $familySchema = ProductFamilySchema::getSchema($familyEnum);
        $variantKeys = $familySchema['variant_forming'] ?? [];

        if (empty($variantKeys)) {
            return $this->extractDimensionsFromName($dto);
        }

        $result = [];

        foreach ($variantKeys as $key) {
            // Ищем в атрибутах DTO
            $val = $dto->attributes[$key] ?? null;

            // Ищем в вариантах (первый вариант)
            if ($val === null && !empty($dto->variants)) {
                $firstVariant = $dto->variants[0];
                $val = $firstVariant->options[$key] ?? null;
            }

            // Ищем в rawData
            if ($val === null) {
                $val = $dto->rawData[$key] ?? null;
            }

            if ($val !== null) {
                // Числовые значения приводим к int
                if (is_numeric($val)) {
                    $result[$key] = (int)$val;
                } else {
                    $result[$key] = (string)$val;
                }
            }
        }

        // Если ключевые размеры не найдены в атрибутах — парсим из названия
        if (empty($result) || (!isset($result['width']) && !isset($result['length']))) {
            $fromName = $this->extractDimensionsFromName($dto);
            $result = array_merge($fromName, $result);
        }

        return $result;
    }

    /**
     * Парсинг размеров из названия товара.
     *
     * "Матрас Орматек Оптима 160x200" → {"width": 160, "length": 200}
     * "Подушка 50х70" → {"width": 50, "length": 70}
     */
    protected function extractDimensionsFromName(ProductDTO $dto): array
    {
        $text = $dto->name . ' ' . ($dto->model ?? '');

        // Ищем паттерн: NNNxNNN, NNNхNNN (кириллическая х), NNN*NNN
        if (preg_match('/(\d{2,4})\s*[xхXХ×\*]\s*(\d{2,4})/', $text, $m)) {
            $w = (int)$m[1];
            $l = (int)$m[2];

            // Проверка адекватности (матрас: 60-300 см)
            if ($w >= 30 && $w <= 400 && $l >= 30 && $l <= 400) {
                return ['width' => $w, 'length' => $l];
            }
        }

        return [];
    }

    protected function extractModelName(ProductDTO $dto): ?string
    {
        // Предпочитаем model, затем name
        $model = $dto->model ?? $dto->name;
        if (empty($model)) return null;

        // Убираем размеры из названия (они в атрибутах варианта)
        $clean = preg_replace('/\s*\d{2,4}\s*[xхXХ×\*]\s*\d{2,4}\s*/', '', $model);
        // Убираем trailing размеры вроде "160 200"
        $clean = preg_replace('/\s+\d{2,4}\s+\d{2,4}\s*$/', '', $clean);

        return trim($clean) ?: $model;
    }

    protected function resolveBrandId(string $manufacturer): ?int
    {
        $id = Yii::$app->db->createCommand(
            "SELECT id FROM {{%brands}} WHERE canonical_name = :name LIMIT 1",
            [':name' => $manufacturer]
        )->queryScalar();

        if ($id) return (int)$id;

        $id = Yii::$app->db->createCommand(
            "SELECT brand_id FROM {{%brand_aliases}} WHERE alias ILIKE :name LIMIT 1",
            [':name' => $manufacturer]
        )->queryScalar();

        return $id ? (int)$id : null;
    }
}
