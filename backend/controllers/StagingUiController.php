<?php

namespace backend\controllers;

use common\models\StagingRawOffer;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\Controller;

/**
 * Сырые данные (Staging) — просмотр staging_raw_offers (UNLOGGED TABLE).
 */
class StagingUiController extends Controller
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
        $query = StagingRawOffer::find()->orderBy(['id' => SORT_DESC]);

        // Фильтры
        $status = \Yii::$app->request->get('status');
        $sessionId = \Yii::$app->request->get('session_id');

        if ($status) {
            $query->andWhere(['status' => $status]);
        }
        if ($sessionId) {
            $query->andWhere(['import_session_id' => $sessionId]);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 50],
        ]);

        // Статистика
        $stats = \Yii::$app->db->createCommand("
            SELECT status, count(*) as cnt FROM {{%staging_raw_offers}} GROUP BY status ORDER BY status
        ")->queryAll();

        // Уникальные сессии для фильтра
        $sessions = \Yii::$app->db->createCommand("
            SELECT DISTINCT import_session_id FROM {{%staging_raw_offers}} ORDER BY import_session_id DESC LIMIT 50
        ")->queryColumn();

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'status' => $status,
            'sessionId' => $sessionId,
            'stats' => $stats,
            'sessions' => $sessions,
        ]);
    }
}
