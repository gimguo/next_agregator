<?php

namespace backend\controllers;

use yii\filters\AccessControl;
use yii\web\Controller;
use Yii;

/**
 * Мониторинг очереди задач.
 */
class QueueDashboardController extends Controller
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
        $redis = Yii::$app->redis;
        $channel = 'agregator-queue';

        $stats = [
            'waiting' => 0,
            'reserved' => 0,
            'done' => 0,
            'delayed' => 0,
        ];

        try {
            $stats['waiting'] = (int)$redis->executeCommand('LLEN', ["{$channel}.waiting"]);
            $stats['reserved'] = (int)$redis->executeCommand('ZCARD', ["{$channel}.reserved"]);
            $stats['done'] = (int)$redis->executeCommand('GET', ["{$channel}.message_id"]) ?: 0;
            $stats['delayed'] = (int)$redis->executeCommand('ZCARD', ["{$channel}.delayed"]);
        } catch (\Throwable $e) {
            Yii::warning("Queue stats error: {$e->getMessage()}", 'queue');
        }

        // Последние AI-логи
        $db = Yii::$app->db;
        $aiLogs = [];
        try {
            $aiLogs = $db->createCommand("
                SELECT id, operation, model, prompt_tokens, completion_tokens, duration_ms, created_at
                FROM {{%ai_logs}}
                ORDER BY created_at DESC
                LIMIT 15
            ")->queryAll();
        } catch (\Throwable $e) {
            // Table might not exist yet
        }

        return $this->render('index', [
            'stats' => $stats,
            'aiLogs' => $aiLogs,
        ]);
    }
}
