<?php

namespace backend\controllers;

use common\models\ProductCard;
use common\models\SupplierOffer;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Управление карточками товаров.
 */
class ProductCardController extends Controller
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

    public function actionIndex(): string
    {
        $query = ProductCard::find()->orderBy(['created_at' => SORT_DESC]);

        // Простая фильтрация
        $search = \Yii::$app->request->get('search');
        $status = \Yii::$app->request->get('status');
        $brand = \Yii::$app->request->get('brand');

        if ($search) {
            $query->andWhere(['or',
                ['ilike', 'canonical_name', $search],
                ['ilike', 'brand', $search],
                ['ilike', 'manufacturer', $search],
            ]);
        }
        if ($status) {
            $query->andWhere(['status' => $status]);
        }
        if ($brand) {
            $query->andWhere(['brand' => $brand]);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 50],
        ]);

        // Доступные бренды для фильтра
        $brands = ProductCard::find()
            ->select('brand')
            ->distinct()
            ->where(['not', ['brand' => null]])
            ->orderBy('brand')
            ->column();

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'search' => $search,
            'status' => $status,
            'brand' => $brand,
            'brands' => $brands,
        ]);
    }

    public function actionView(int $id): string
    {
        $card = $this->findModel($id);

        $offers = SupplierOffer::find()
            ->where(['card_id' => $id])
            ->with('supplier')
            ->all();

        return $this->render('view', [
            'card' => $card,
            'offers' => $offers,
        ]);
    }

    protected function findModel(int $id): ProductCard
    {
        $model = ProductCard::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException('Карточка не найдена.');
        }
        return $model;
    }
}
