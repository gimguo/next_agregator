<?php

namespace backend\controllers;

use backend\models\ProductModelSearch;
use common\components\S3UrlGenerator;
use common\models\MediaAsset;
use common\models\ProductModel;
use common\models\ReferenceVariant;
use common\models\SupplierOffer;
use yii\filters\AccessControl;
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
}
