<?php

namespace backend\controllers;

use common\models\Supplier;
use common\models\SupplierOffer;
use common\models\ProductCard;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\Controller;
use Yii;

/**
 * Управление поставщиками.
 */
class SupplierController extends Controller
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
        $dataProvider = new ActiveDataProvider([
            'query' => Supplier::find()->orderBy(['is_active' => SORT_DESC, 'name' => SORT_ASC]),
            'pagination' => ['pageSize' => 50],
        ]);

        return $this->render('index', [
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionView(int $id): string
    {
        $supplier = Supplier::findOne($id);
        if (!$supplier) {
            throw new \yii\web\NotFoundHttpException('Поставщик не найден.');
        }

        $offersProvider = new ActiveDataProvider([
            'query' => SupplierOffer::find()
                ->where(['supplier_id' => $id])
                ->with('card')
                ->orderBy(['updated_at' => SORT_DESC]),
            'pagination' => ['pageSize' => 30],
        ]);

        $stats = [
            'total_offers' => SupplierOffer::find()->where(['supplier_id' => $id])->count(),
            'active_offers' => SupplierOffer::find()->where(['supplier_id' => $id, 'is_active' => true])->count(),
            'in_stock' => SupplierOffer::find()->where(['supplier_id' => $id, 'in_stock' => true])->count(),
        ];

        return $this->render('view', [
            'supplier' => $supplier,
            'offersProvider' => $offersProvider,
            'stats' => $stats,
        ]);
    }
}
