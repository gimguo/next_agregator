<?php

namespace backend\controllers;

use common\models\MarketplaceOutbox;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;
use Yii;

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
        $status = Yii::$app->request->get('status');
        $entityType = Yii::$app->request->get('entity_type');
        $lane = Yii::$app->request->get('lane');

        if ($status) {
            $query->andWhere(['status' => $status]);
        }
        if ($entityType) {
            $query->andWhere(['entity_type' => $entityType]);
        }
        if ($lane) {
            $query->andWhere(['lane' => $lane]);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 50],
        ]);

        // Статистика по статусам
        $stats = Yii::$app->db->createCommand("
            SELECT status, count(*) as cnt FROM {{%marketplace_outbox}} GROUP BY status ORDER BY status
        ")->queryAll();

        // Статистика по лейнам
        $laneStats = Yii::$app->db->createCommand("
            SELECT lane, count(*) as cnt FROM {{%marketplace_outbox}} WHERE status='pending' GROUP BY lane ORDER BY lane
        ")->queryAll();

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'status' => $status,
            'entityType' => $entityType,
            'lane' => $lane,
            'stats' => $stats,
            'laneStats' => $laneStats,
        ]);
    }

    /**
     * AJAX: Live-статистика outbox.
     */
    public function actionLiveStats(): Response
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $stats = Yii::$app->db->createCommand("
            SELECT status, count(*) as cnt FROM {{%marketplace_outbox}} GROUP BY status ORDER BY status
        ")->queryAll();

        $result = ['pending' => 0, 'processing' => 0, 'success' => 0, 'error' => 0, 'failed' => 0, 'total' => 0];
        foreach ($stats as $row) {
            if (isset($result[$row['status']])) {
                $result[$row['status']] = (int)$row['cnt'];
            }
            $result['total'] += (int)$row['cnt'];
        }

        $result['pending_models'] = (int)Yii::$app->db->createCommand("
            SELECT count(DISTINCT model_id) FROM {{%marketplace_outbox}} WHERE status='pending'
        ")->queryScalar();

        $result['timestamp'] = date('H:i:s');

        return $this->asJson($result);
    }
}
