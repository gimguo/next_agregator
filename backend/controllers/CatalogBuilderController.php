<?php

namespace backend\controllers;

use common\models\CatalogExport;
use common\models\CatalogPreview;
use common\models\CatalogTemplate;
use common\models\ModelChannelReadiness;
use common\models\ProductModel;
use common\models\SalesChannel;
use common\models\Supplier;
use common\services\CatalogBuilderService;
use common\services\OutboxService;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use Yii;

/**
 * Sprint 18: Catalog Builder Controller.
 *
 * Конструктор каталога для витрины:
 *   - actionIndex: список превью каталогов
 *   - actionCreate: создание нового превью (выбор шаблона и поставщиков)
 *   - actionView: интерактивный предпросмотр с деревом категорий
 *   - actionExport: экспорт каталога на витрину через Outbox
 */
class CatalogBuilderController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'roles' => ['@']],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'export' => ['post'],
                    'delete' => ['post'],
                ],
            ],
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * INDEX — Список превью каталогов
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => CatalogPreview::find()->with('template')->orderBy(['created_at' => SORT_DESC]),
            'pagination' => ['pageSize' => 20],
        ]);

        return $this->render('index', [
            'dataProvider' => $dataProvider,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * CREATE — Создание нового превью каталога
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionCreate(): string|Response
    {
        $model = new CatalogPreview();
        $templates = CatalogTemplate::find()->orderBy(['name' => SORT_ASC])->all();
        $suppliers = Supplier::find()->where(['is_active' => true])->orderBy(['name' => SORT_ASC])->all();

        if (Yii::$app->request->isPost) {
            $post = Yii::$app->request->post();

            // Получаем данные из формы
            $modelData = $post['CatalogPreview'] ?? [];
            $templateId = (int)($modelData['template_id'] ?? 0);
            $supplierIds = isset($modelData['supplier_ids']) && is_array($modelData['supplier_ids'])
                ? array_filter(array_map('intval', $modelData['supplier_ids']))
                : [];
            $name = trim($modelData['name'] ?? '');

            if (!$templateId) {
                Yii::$app->session->setFlash('error', 'Необходимо выбрать шаблон каталога.');
                return $this->render('create', [
                    'model' => $model,
                    'templates' => $templates,
                    'suppliers' => $suppliers,
                ]);
            }

            if (empty($supplierIds)) {
                Yii::$app->session->setFlash('error', 'Необходимо выбрать хотя бы одного поставщика.');
                return $this->render('create', [
                    'model' => $model,
                    'templates' => $templates,
                    'suppliers' => $suppliers,
                ]);
            }

            try {
                /** @var CatalogBuilderService $builder */
                $builder = Yii::$app->get('catalogBuilder');
                $preview = $builder->buildPreview($templateId, $supplierIds, $name ?: null);

                Yii::$app->session->setFlash('success', 
                    "Превью каталога создано! Товаров: {$preview->product_count}, Категорий: {$preview->category_count}");

                return $this->redirect(['view', 'id' => $preview->id]);
            } catch (\Throwable $e) {
                Yii::error("CatalogBuilder::create error: {$e->getMessage()}", 'catalog.builder');
                Yii::$app->session->setFlash('error', "Ошибка создания превью: {$e->getMessage()}");
            }
        }

        return $this->render('create', [
            'model' => $model,
            'templates' => $templates,
            'suppliers' => $suppliers,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * VIEW — Интерактивный предпросмотр каталога
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionView(int $id): string
    {
        $preview = $this->findPreview($id);
        $previewData = $preview->getPreviewDataArray();
        $categories = $previewData['categories'] ?? [];
        $productsByCategory = $previewData['products_by_category'] ?? [];

        // Получаем названия поставщиков
        $supplierIds = $preview->getSupplierIdsArray();
        $suppliers = Supplier::find()
            ->where(['id' => $supplierIds])
            ->indexBy('id')
            ->all();

        return $this->render('view', [
            'preview' => $preview,
            'categories' => $categories,
            'productsByCategory' => $productsByCategory,
            'suppliers' => $suppliers,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * CATEGORY PRODUCTS — Просмотр товаров категории (Drill-down)
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionCategoryProducts(int $preview_id, string $category_id): string
    {
        $preview = $this->findPreview($preview_id);
        $previewData = $preview->getPreviewDataArray();
        $productsByCategory = $previewData['products_by_category'] ?? [];

        // Получаем ID товаров для категории
        $modelIds = $productsByCategory[$category_id] ?? [];
        if (empty($modelIds)) {
            Yii::$app->session->setFlash('warning', 'В этой категории нет товаров.');
            return $this->redirect(['view', 'id' => $preview_id]);
        }

        // Находим название категории
        $categoryName = 'Неизвестная категория';
        $categories = $previewData['categories'] ?? [];
        foreach ($categories as $cat) {
            if (($cat['id'] ?? null) == $category_id || ($cat['id'] ?? null) === $category_id) {
                $categoryName = $cat['name'] ?? 'Неизвестная категория';
                break;
            }
        }
        if ($category_id === 'orphan') {
            $categoryName = 'Прочее (Не распределено)';
        }

        // Получаем активный канал для Readiness
        $channel = SalesChannel::find()->where(['is_active' => true])->one();
        $readinessMap = [];
        if ($channel) {
            $readinessRecords = ModelChannelReadiness::find()
                ->where(['model_id' => $modelIds, 'channel_id' => $channel->id])
                ->indexBy('model_id')
                ->all();
            foreach ($readinessRecords as $record) {
                $readinessMap[$record->model_id] = $record;
            }
        }

        // Создаём DataProvider
        $dataProvider = new ActiveDataProvider([
            'query' => ProductModel::find()->where(['id' => $modelIds]),
            'pagination' => ['pageSize' => 50],
            'sort' => [
                'defaultOrder' => ['id' => SORT_ASC],
            ],
        ]);

        return $this->render('category-products', [
            'preview' => $preview,
            'categoryId' => $category_id,
            'categoryName' => $categoryName,
            'dataProvider' => $dataProvider,
            'readinessMap' => $readinessMap,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * EXPORT — Экспорт каталога на витрину
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionExport(int $id): Response
    {
        $preview = $this->findPreview($id);
        $previewData = $preview->getPreviewDataArray();
        $productsByCategory = $previewData['products_by_category'] ?? [];

        $channel = SalesChannel::find()->where(['is_active' => true])->one();
        if (!$channel) {
            Yii::$app->session->setFlash('error', 'Нет активного канала продаж.');
            return $this->redirect(['view', 'id' => $id]);
        }

        $db = Yii::$app->db;
        $tx = $db->beginTransaction();

        try {
            // 1. Создаём запись в catalog_exports
            $export = new CatalogExport();
            $export->preview_id = $id;
            $export->status = CatalogExport::STATUS_PENDING;
            if (!$export->save()) {
                throw new \RuntimeException('Ошибка создания записи экспорта: ' . implode(', ', $export->getFirstErrors()));
            }

            // 2. Отправляем структуру категорий (первыми!)
            /** @var OutboxService $outbox */
            $outbox = Yii::$app->get('outbox');
            $outbox->emitCategoryTreeUpdate($id, $channel->id);

            // 3. Отправляем товары по категориям
            $totalProducts = 0;
            foreach ($productsByCategory as $categoryId => $modelIds) {
                foreach ($modelIds as $modelId) {
                    $outbox->emitContentUpdate((int)$modelId);
                    $totalProducts++;
                }
            }

            // 4. Обновляем статус экспорта
            $stats = [
                'products' => $totalProducts,
                'categories' => count($previewData['categories'] ?? []),
            ];
            $export->status = CatalogExport::STATUS_COMPLETED;
            $export->stats_json = $stats;
            if (!$export->save()) {
                throw new \RuntimeException('Ошибка обновления статуса экспорта: ' . implode(', ', $export->getFirstErrors()));
            }

            $tx->commit();

            Yii::$app->session->setFlash('success', 
                "Каталог отправлен в очередь на выгрузку! Товаров: {$totalProducts}, Категорий: {$stats['categories']}");

        } catch (\Throwable $e) {
            $tx->rollBack();
            Yii::error("CatalogBuilder::export error: {$e->getMessage()}", 'catalog.builder');
            Yii::$app->session->setFlash('error', "Ошибка экспорта: {$e->getMessage()}");
        }

        return $this->redirect(['view', 'id' => $id]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * DELETE — Удаление превью
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionDelete(int $id): Response
    {
        $preview = $this->findPreview($id);
        $preview->delete();

        Yii::$app->session->setFlash('success', 'Превью каталога удалено.');
        return $this->redirect(['index']);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * HELPERS
     * ═══════════════════════════════════════════════════════════════════ */

    protected function findPreview(int $id): CatalogPreview
    {
        $preview = CatalogPreview::findOne($id);
        if (!$preview) {
            throw new NotFoundHttpException('Превью каталога не найдено.');
        }
        return $preview;
    }
}
