<?php

namespace backend\controllers;

use backend\models\ProductModelSearch;
use common\components\S3UrlGenerator;
use common\models\MediaAsset;
use common\models\ProductModel;
use common\models\ReferenceVariant;
use common\models\SupplierOffer;
use common\services\RosMatrasSyndicationService;
use common\services\marketplace\MarketplaceApiClientInterface;
use common\services\marketplace\MarketplaceUnavailableException;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use Yii;

/**
 * MDM Каталог — просмотр склеенных данных (product_models → variants → offers).
 */
class CatalogController extends Controller
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
                    'sync' => ['post'],
                ],
            ],
        ];
    }

    /**
     * Список всех моделей товаров.
     */
    public function actionIndex(): string
    {
        $searchModel = new ProductModelSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Детальная карточка модели: атрибуты, изображения, варианты, офферы.
     */
    public function actionView(int $id): string
    {
        $model = ProductModel::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException('Модель не найдена.');
        }

        // Изображения модели
        $images = MediaAsset::find()
            ->where(['entity_type' => 'model', 'entity_id' => $id])
            ->orderBy(['is_primary' => SORT_DESC, 'sort_order' => SORT_ASC])
            ->all();

        // Варианты с офферами
        $variants = ReferenceVariant::find()
            ->where(['model_id' => $id])
            ->orderBy(['sort_order' => SORT_ASC, 'variant_label' => SORT_ASC])
            ->all();

        // Офферы, сгруппированные по variant_id
        $variantIds = array_map(fn($v) => $v->id, $variants);
        $offers = [];
        if (!empty($variantIds)) {
            $allOffers = SupplierOffer::find()
                ->where(['variant_id' => $variantIds])
                ->with('supplier')
                ->orderBy(['variant_id' => SORT_ASC, 'price_min' => SORT_ASC])
                ->all();
            foreach ($allOffers as $offer) {
                $offers[$offer->variant_id][] = $offer;
            }
        }

        // Офферы без варианта (привязаны только к модели)
        $orphanOffers = SupplierOffer::find()
            ->where(['model_id' => $id, 'variant_id' => null])
            ->with('supplier')
            ->all();

        return $this->render('view', [
            'model' => $model,
            'images' => $images,
            'variants' => $variants,
            'offers' => $offers,
            'orphanOffers' => $orphanOffers,
        ]);
    }

    /**
     * Принудительная синхронизация модели на витрину РосМатрас.
     *
     * Собирает проекцию и отправляет напрямую через RosMatrasApiClient::pushProduct(),
     * минуя Outbox-очередь. Используется для ручной синхронизации из админки.
     *
     * @param int $id ID модели (product_model)
     * @return \yii\web\Response
     * @throws NotFoundHttpException Если модель не найдена
     */
    public function actionSync(int $id)
    {
        $model = ProductModel::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException('Модель не найдена.');
        }

        try {
            /** @var RosMatrasSyndicationService $syndicator */
            $syndicator = Yii::$app->get('syndicationService');

            /** @var MarketplaceApiClientInterface $client */
            $client = Yii::$app->get('marketplaceClient');

            // 1. Строим проекцию
            $projection = $syndicator->buildProductProjection($id);

            if (!$projection) {
                Yii::$app->session->setFlash('warning',
                    "Не удалось построить проекцию для модели #{$id}. Возможно, она неактивна."
                );
                return $this->redirect(['view', 'id' => $id]);
            }

            // 2. Отправляем напрямую (мимо Outbox)
            $result = $client->pushProduct($id, $projection);

            if ($result) {
                $varCount = $projection['variant_count'] ?? 0;
                $imgCount = count($projection['images'] ?? []);
                $price = $projection['best_price']
                    ? number_format($projection['best_price'], 0, '.', ' ') . ' ₽'
                    : 'N/A';

                Yii::$app->session->setFlash('success',
                    "✓ Товар «{$model->name}» успешно отправлен на витрину! " .
                    "({$varCount} вариантов, {$imgCount} изображений, цена: {$price})"
                );
            } else {
                Yii::$app->session->setFlash('error',
                    "Ошибка при отправке товара «{$model->name}» на витрину. API вернул false."
                );
            }

        } catch (MarketplaceUnavailableException $e) {
            Yii::$app->session->setFlash('error',
                "API витрины РосМатрас недоступен: {$e->getMessage()}"
            );
            Yii::error("Catalog sync: API unavailable for model #{$id}: {$e->getMessage()}", 'marketplace.admin');

        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error',
                "Ошибка синхронизации: {$e->getMessage()}"
            );
            Yii::error("Catalog sync error for model #{$id}: {$e->getMessage()}", 'marketplace.admin');
        }

        return $this->redirect(['view', 'id' => $id]);
    }
}
