<?php

namespace common\services;

use common\models\CatalogPreview;
use common\models\CatalogTemplate;
use common\models\ModelChannelReadiness;
use common\models\ProductModel;
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

        // 4. Распределить товары по категориям (простая заглушка)
        //    Пока просто распределяем по category_id модели
        $productsByCategory = [];
        foreach ($models as $model) {
            // Если у модели есть category_id, используем его
            // Иначе относим к первой категории шаблона
            $categoryId = $model->category_id ?? ($categories[0]['id'] ?? null);
            if ($categoryId) {
                if (!isset($productsByCategory[$categoryId])) {
                    $productsByCategory[$categoryId] = [];
                }
                $productsByCategory[$categoryId][] = $model->id;
            }
        }

        // 5. Подсчитать количество категорий с товарами
        $categoryCount = count(array_filter($categories, function ($cat) use ($productsByCategory) {
            return isset($productsByCategory[$cat['id']]) && !empty($productsByCategory[$cat['id']]);
        }));

        // 6. Сформировать preview_data
        $previewData = [
            'categories' => $categories,
            'products_by_category' => $productsByCategory,
            'total_products' => count($models),
            'total_categories' => $categoryCount,
        ];

        // 7. Сохранить в CatalogPreview
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
}
