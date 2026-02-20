<?php

namespace backend\controllers;

use common\models\MarketplaceOutbox;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\Controller;

/**
 * Очередь выгрузки (Transactional Outbox) — просмотр marketplace_outbox.
 */
class OutboxUiController extends Controller
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
        $query = MarketplaceOutbox::find()->orderBy(['id' => SORT_DESC]);

        // Фильтры
        $status = \Yii::$app->request->get('status');
        $entityType = \Yii::$app->request->get('entity_type');

        if ($status) {
            $query->andWhere(['status' => $status]);
        }
        if ($entityType) {
            $query->andWhere(['entity_type' => $entityType]);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 50],
        ]);

        // Статистика
        $stats = \Yii::$app->db->createCommand("
            SELECT status, count(*) as cnt FROM {{%marketplace_outbox}} GROUP BY status ORDER BY status
        ")->queryAll();

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'status' => $status,
            'entityType' => $entityType,
            'stats' => $stats,
        ]);
    }
}
