<?php

namespace backend\modules\api\controllers;

use yii\rest\Controller;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\ContentNegotiator;
use yii\web\Response;

/**
 * Базовый API-контроллер с Bearer-авторизацией и JSON-ответами.
 */
class BaseApiController extends Controller
{
    /**
     * Отключаем CSRF для REST API.
     */
    public $enableCsrfValidation = false;

    public function beforeAction($action): bool
    {
        // Для REST API не нужна CSRF-проверка
        \Yii::$app->request->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        // JSON-ответы
        $behaviors['contentNegotiator'] = [
            'class' => ContentNegotiator::class,
            'formats' => [
                'application/json' => Response::FORMAT_JSON,
            ],
        ];

        // API Key авторизация (если задан ключ)
        $apiKey = \Yii::$app->params['rosmatras']['apiKey'] ?? '';
        if (!empty($apiKey) && $apiKey !== 'your-rosmatras-api-key') {
            $behaviors['authenticator'] = [
                'class' => HttpBearerAuth::class,
            ];
        }

        return $behaviors;
    }

    /**
     * Успешный ответ.
     */
    protected function success(mixed $data, array $meta = []): array
    {
        $response = ['success' => true, 'data' => $data];
        if (!empty($meta)) {
            $response['meta'] = $meta;
        }
        return $response;
    }

    /**
     * Ответ с ошибкой.
     */
    protected function error(string $message, int $code = 400): array
    {
        \Yii::$app->response->statusCode = $code;
        return [
            'success' => false,
            'error' => ['message' => $message, 'code' => $code],
        ];
    }
}
