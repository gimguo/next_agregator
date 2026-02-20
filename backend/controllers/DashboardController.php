<?php

namespace backend\controllers;

use common\models\CardImage;
use common\models\ProductCard;
use common\models\Supplier;
use common\models\SupplierOffer;
use yii\filters\AccessControl;
use yii\web\Controller;
use Yii;

/**
 * Дашборд агрегатора — главная страница админки.
 */
class DashboardController extends Controller
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
        $stats = [
            'cards' => ProductCard::find()->count(),
            'cards_active' => ProductCard::find()->where(['status' => 'active', 'has_active_offers' => true])->count(),
            'offers' => SupplierOffer::find()->count(),
            'suppliers' => Supplier::find()->where(['is_active' => true])->count(),
            'images_total' => CardImage::find()->count(),
            'images_completed' => CardImage::find()->where(['status' => 'completed'])->count(),
            'images_pending' => CardImage::find()->where(['status' => 'pending'])->count(),
            'images_failed' => CardImage::find()->where(['status' => 'failed'])->count(),
        ];

        $suppliers = Supplier::find()->where(['is_active' => true])->all();

        $recentCards = ProductCard::find()
            ->orderBy(['created_at' => SORT_DESC])
            ->limit(10)
            ->all();

        return $this->render('index', [
            'stats' => $stats,
            'suppliers' => $suppliers,
            'recentCards' => $recentCards,
        ]);
    }
}
