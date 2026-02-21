<?php

namespace backend\controllers;

use common\models\ModelChannelReadiness;
use common\models\SalesChannel;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\Controller;
use Yii;

/**
 * Качество данных — дашборд готовности и проблем.
 */
class QualityController extends Controller
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
     * Обзор готовности по каналам + топ проблем.
     */
    public function actionIndex(): string
    {
        $db = Yii::$app->db;

        // Каналы
        $channels = SalesChannel::find()->where(['is_active' => true])->all();

        // Readiness по каналам
        $channelStats = [];
        foreach ($channels as $ch) {
            $total = (int)$db->createCommand("SELECT count(*) FROM {{%model_channel_readiness}} WHERE channel_id = :ch", [':ch' => $ch->id])->queryScalar();
            $ready = (int)$db->createCommand("SELECT count(*) FROM {{%model_channel_readiness}} WHERE channel_id = :ch AND is_ready = true", [':ch' => $ch->id])->queryScalar();
            $notReady = $total - $ready;
            $avgScore = (float)$db->createCommand("SELECT COALESCE(ROUND(AVG(score)::numeric, 1), 0) FROM {{%model_channel_readiness}} WHERE channel_id = :ch", [':ch' => $ch->id])->queryScalar();

            // Heal stats
            $healed = (int)$db->createCommand("SELECT count(*) FROM {{%model_channel_readiness}} WHERE channel_id = :ch AND last_heal_attempt_at IS NOT NULL", [':ch' => $ch->id])->queryScalar();
            $healedOk = (int)$db->createCommand("SELECT count(*) FROM {{%model_channel_readiness}} WHERE channel_id = :ch AND last_heal_attempt_at IS NOT NULL AND is_ready = true", [':ch' => $ch->id])->queryScalar();

            $channelStats[] = [
                'channel' => $ch,
                'total' => $total,
                'ready' => $ready,
                'notReady' => $notReady,
                'avgScore' => $avgScore,
                'healed' => $healed,
                'healedOk' => $healedOk,
            ];
        }

        // Топ проблем (missing_fields)
        $topProblems = $this->analyzeProblems($db);

        // Не-ready модели для первого канала
        $selectedChannelId = Yii::$app->request->get('channel_id', $channels[0]->id ?? 0);

        $query = ModelChannelReadiness::find()
            ->where(['channel_id' => $selectedChannelId, 'is_ready' => false])
            ->orderBy(['score' => SORT_ASC]);

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 30],
        ]);

        return $this->render('index', [
            'channelStats' => $channelStats,
            'topProblems' => $topProblems,
            'dataProvider' => $dataProvider,
            'selectedChannelId' => (int)$selectedChannelId,
            'channels' => $channels,
        ]);
    }

    /**
     * Анализировать частоту пропущенных полей.
     */
    private function analyzeProblems($db): array
    {
        $rows = $db->createCommand("
            SELECT missing_fields FROM {{%model_channel_readiness}}
            WHERE is_ready = false AND missing_fields IS NOT NULL
        ")->queryAll();

        $fieldCounts = [];
        foreach ($rows as $row) {
            $fields = is_array($row['missing_fields'])
                ? $row['missing_fields']
                : json_decode($row['missing_fields'] ?? '[]', true);

            if (is_array($fields)) {
                foreach ($fields as $field) {
                    $fieldCounts[$field] = ($fieldCounts[$field] ?? 0) + 1;
                }
            }
        }

        arsort($fieldCounts);
        return array_slice($fieldCounts, 0, 15, true);
    }
}
