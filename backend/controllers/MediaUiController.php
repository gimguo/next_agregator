<?php

namespace backend\controllers;

use common\models\MediaAsset;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;
use Yii;

/**
 * Медиа-файлы (S3/MinIO DAM) — просмотр media_assets.
 */
class MediaUiController extends Controller
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
        $query = MediaAsset::find()->orderBy(['id' => SORT_DESC]);

        // Фильтры
        $status = Yii::$app->request->get('status');
        $entityType = Yii::$app->request->get('entity_type');

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
        $stats = Yii::$app->db->createCommand("
            SELECT status, count(*) as cnt FROM {{%media_assets}} GROUP BY status ORDER BY status
        ")->queryAll();

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'status' => $status,
            'entityType' => $entityType,
            'stats' => $stats,
        ]);
    }

    /**
     * AJAX: Live-статистика медиа-ассетов.
     */
    public function actionLiveStats(): Response
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $stats = Yii::$app->db->createCommand("
            SELECT status, count(*) as cnt, COALESCE(SUM(size_bytes),0) as total_size
            FROM {{%media_assets}} GROUP BY status ORDER BY status
        ")->queryAll();

        $result = ['pending' => 0, 'downloading' => 0, 'processed' => 0, 'deduplicated' => 0, 'error' => 0, 'total' => 0, 'total_size' => 0];
        foreach ($stats as $row) {
            $result[$row['status']] = (int)$row['cnt'];
            $result['total'] += (int)$row['cnt'];
            $result['total_size'] += (int)$row['total_size'];
        }
        $result['ready'] = $result['processed'] + $result['deduplicated'];
        $result['timestamp'] = date('H:i:s');

        return $this->asJson($result);
    }
}
