<?php

namespace common\services;

use common\models\CatalogPreview;
use common\models\CatalogTemplate;
use common\models\ModelChannelReadiness;
use common\models\ProductModel;
use common\models\ReferenceVariant;
use common\models\SalesChannel;
use Yii;

/**
 * Sprint 17: Catalog Builder Service.
 *
 * Доменный сервис для построения предпросмотров каталога товаров.
 */
class CatalogBuilderService
{
    /**
     * Построить предпросмотр каталога на основе шаблона и поставщиков.
     *
     * @param int $templateId ID шаблона
     * @param array $supplierIds Массив ID поставщиков
     * @param string|null $name Название предпросмотра
     * @return CatalogPreview
     * @throws \Exception
     */
    public function buildPreview(int $templateId, array $supplierIds, ?string $name = null): CatalogPreview
    {
        if (empty($supplierIds)) {
            throw new \InvalidArgumentException('Необходимо указать хотя бы одного поставщика');
        }

        $template = CatalogTemplate::findOne($templateId);
        if (!$template) {
            throw new \InvalidArgumentException("Шаблон #{$templateId} не найден");
        }

        // 1. Получить активный канал для проверки Readiness
        $channel = SalesChannel::find()->where(['is_active' => true])->one();
        if (!$channel) {
            throw new \Exception('Нет активного канала продаж для проверки Readiness');
        }

        // 2. Получить все ProductModel, у которых есть офферы от выбранных поставщиков
        //    и которые прошли Readiness Scoring (is_ready = true)
        $modelIds = (new \yii\db\Query())
            ->select('DISTINCT pm.id')
            ->from('{{%product_models}} pm')
            ->innerJoin('{{%supplier_offers}} so', 'so.model_id = pm.id')
            ->leftJoin('{{%model_channel_readiness}} mcr', 'mcr.model_id = pm.id AND mcr.channel_id = :channelId', [
                ':channelId' => $channel->id,
            ])
            ->where(['so.supplier_id' => $supplierIds])
            ->andWhere(['pm.status' => 'active'])
            ->andWhere(['mcr.is_ready' => true]) // Только готовые к экспорту
            ->column();

        if (empty($modelIds)) {
            throw new \Exception('Нет готовых товаров от выбранных поставщиков');
        }

        $models = ProductModel::find()
            ->where(['id' => $modelIds])
            ->all();

        // 3. Получить структуру категорий из шаблона
        $structure = $template->getStructure();
        $categories = $structure['categories'] ?? [];

        // 4. Распределить товары по категориям через Rule Engine
        $productsByCategory = $this->distributeProducts($models, $categories);

        // 5. Подсчитать количество категорий с товарами (включая orphan)
        $categoryCount = count(array_filter($categories, function ($cat) use ($productsByCategory) {
            return isset($productsByCategory[$cat['id']]) && !empty($productsByCategory[$cat['id']]);
        }));
        // Добавляем orphan категорию в подсчёт, если есть товары
        if (isset($productsByCategory['orphan']) && !empty($productsByCategory['orphan'])) {
            $categoryCount++;
        }

        // 6. Добавить виртуальную категорию "Прочее" для сирот
        if (isset($productsByCategory['orphan']) && !empty($productsByCategory['orphan'])) {
            $categories[] = [
                'id' => 'orphan',
                'name' => 'Прочее (Не распределено)',
                'slug' => 'prochee',
                'parent_id' => null,
                'sort_order' => 9999,
                'rules' => null,
                'children' => [],
            ];
        }

        // 7. Сформировать preview_data
        $previewData = [
            'categories' => $categories,
            'products_by_category' => $productsByCategory,
            'total_products' => count($models),
            'total_categories' => $categoryCount,
        ];

        // 8. Сохранить в CatalogPreview
        $preview = new CatalogPreview();
        $preview->template_id = $templateId;
        $preview->name = $name ?: sprintf('Каталог (поставщики: %s)', implode(', ', $supplierIds));
        $preview->setSupplierIdsArray($supplierIds);
        $preview->preview_data = $previewData;
        $preview->product_count = count($models);
        $preview->category_count = $categoryCount;
        $preview->created_by = Yii::$app->user->id ?? null;

        if (!$preview->save()) {
            throw new \Exception('Ошибка сохранения предпросмотра: ' . implode(', ', $preview->getFirstErrors()));
        }

        return $preview;
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * SPRINT 20: Rule Engine — умное распределение товаров по категориям
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Распределить товары по категориям на основе правил (Rule Engine).
     *
     * @param ProductModel[] $models
     * @param array $categories Дерево категорий из шаблона
     * @return array [category_id => [model_id, ...], 'orphan' => [model_id, ...]]
     */
    protected function distributeProducts(array $models, array $categories): array
    {
        $productsByCategory = [];
        $orphanProducts = [];

        foreach ($models as $model) {
            // Получаем эталонный вариант для проверки атрибутов
            $goldenVariant = $model->getVariants()
                ->orderBy(['sort_order' => SORT_ASC, 'id' => SORT_ASC])
                ->one();

            // Ищем самую "глубокую" подходящую категорию
            $matchedCategoryId = $this->findMatchingCategory($model, $goldenVariant, $categories);

            if ($matchedCategoryId) {
                if (!isset($productsByCategory[$matchedCategoryId])) {
                    $productsByCategory[$matchedCategoryId] = [];
                }
                $productsByCategory[$matchedCategoryId][] = $model->id;
            } else {
                // Товар не подошёл ни под одно правило — отправляем в "сироты"
                $orphanProducts[] = $model->id;
            }
        }

        // Добавляем виртуальную категорию для сирот
        if (!empty($orphanProducts)) {
            $productsByCategory['orphan'] = $orphanProducts;
        }

        return $productsByCategory;
    }

    /**
     * Найти самую "глубокую" подходящую категорию для модели.
     *
     * @param ProductModel $model
     * @param ReferenceVariant|null $goldenVariant
     * @param array $categories Дерево категорий
     * @return int|string|null ID категории или null
     */
    protected function findMatchingCategory(ProductModel $model, ?ReferenceVariant $goldenVariant, array $categories): int|string|null
    {
        $bestMatch = null;
        $bestDepth = -1;

        foreach ($categories as $category) {
            $match = $this->matchCategoryRecursive($model, $goldenVariant, $category, 0);
            if ($match && $match['depth'] > $bestDepth) {
                $bestMatch = $match['category_id'];
                $bestDepth = $match['depth'];
            }
        }

        return $bestMatch;
    }

    /**
     * Рекурсивно проверить соответствие категории и её дочерних категорий.
     *
     * @param ProductModel $model
     * @param ReferenceVariant|null $goldenVariant
     * @param array $category
     * @param int $depth Текущая глубина вложенности
     * @return array|null ['category_id' => int, 'depth' => int] или null
     */
    protected function matchCategoryRecursive(ProductModel $model, ?ReferenceVariant $goldenVariant, array $category, int $depth): ?array
    {
        // Проверяем правила текущей категории
        $matches = $this->checkCategoryRules($model, $goldenVariant, $category);

        $bestMatch = null;
        $bestDepth = -1;

        // Если категория подходит, проверяем дочерние (ищем самую глубокую)
        if ($matches) {
            $children = $category['children'] ?? [];
            if (empty($children)) {
                // Это листовая категория и она подходит — возвращаем её
                return ['category_id' => $category['id'], 'depth' => $depth];
            }

            // Проверяем дочерние категории
            foreach ($children as $child) {
                $childMatch = $this->matchCategoryRecursive($model, $goldenVariant, $child, $depth + 1);
                if ($childMatch && $childMatch['depth'] > $bestDepth) {
                    $bestMatch = $childMatch;
                    $bestDepth = $childMatch['depth'];
                }
            }

            // Если нашли подходящую дочернюю категорию, возвращаем её
            if ($bestMatch) {
                return $bestMatch;
            }

            // Если дочерние не подошли, но текущая категория подходит — возвращаем её
            return ['category_id' => $category['id'], 'depth' => $depth];
        }

        return null;
    }

    /**
     * Проверить соответствие модели правилам категории.
     *
     * @param ProductModel $model
     * @param ReferenceVariant|null $goldenVariant
     * @param array $category
     * @return bool
     */
    protected function checkCategoryRules(ProductModel $model, ?ReferenceVariant $goldenVariant, array $category): bool
    {
        $rules = $category['rules'] ?? [];
        if (empty($rules)) {
            // Если правил нет, категория не подходит (нужно явное правило)
            return false;
        }

        // Проверка family
        if (isset($rules['family']) && is_array($rules['family'])) {
            $modelFamily = $model->product_family;
            if (!in_array($modelFamily, $rules['family'], true)) {
                return false; // Семейство не совпадает
            }
        }

        // Проверка attributes
        if (isset($rules['attributes']) && is_array($rules['attributes'])) {
            // Получаем атрибуты модели
            $modelAttributes = $this->getModelAttributes($model, $goldenVariant);

            foreach ($rules['attributes'] as $attrKey => $allowedValues) {
                if (!is_array($allowedValues)) {
                    continue;
                }

                // Получаем значение атрибута у модели
                $modelAttrValue = $modelAttributes[$attrKey] ?? null;

                if ($modelAttrValue === null) {
                    return false; // Атрибут отсутствует у модели
                }

                // Проверяем, входит ли значение в список разрешённых
                // Поддерживаем как строковые, так и массив значений
                $modelValues = is_array($modelAttrValue) ? $modelAttrValue : [$modelAttrValue];
                $hasMatch = false;
                foreach ($modelValues as $modelValue) {
                    if (in_array($modelValue, $allowedValues, true)) {
                        $hasMatch = true;
                        break;
                    }
                }

                if (!$hasMatch) {
                    return false; // Значение атрибута не совпадает
                }
            }
        }

        return true; // Все правила выполнены
    }

    /**
     * Получить атрибуты модели для проверки правил.
     *
     * @param ProductModel $model
     * @param ReferenceVariant|null $goldenVariant
     * @return array [attribute_key => value]
     */
    protected function getModelAttributes(ProductModel $model, ?ReferenceVariant $goldenVariant): array
    {
        $attributes = [];

        // Получаем canonical_attributes модели
        $canonicalAttrs = $model->canonical_attributes;
        if (is_string($canonicalAttrs)) {
            $canonicalAttrs = json_decode($canonicalAttrs, true) ?: [];
        }
        if (is_array($canonicalAttrs)) {
            $attributes = array_merge($attributes, $canonicalAttrs);
        }

        // Получаем variant_attributes из эталонного варианта
        if ($goldenVariant) {
            $variantAttrs = $goldenVariant->getAttrs();
            if (is_array($variantAttrs)) {
                $attributes = array_merge($attributes, $variantAttrs);
            }
        }

        return $attributes;
    }
}
