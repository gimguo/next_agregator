<?php

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/../../common/config/params-local.php',
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php'
);

return [
    'id' => 'app-backend',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'backend\controllers',
    'defaultRoute' => 'dashboard',
    'bootstrap' => ['log', 'queue'],
    'modules' => [
        'api' => [
            'class' => \backend\modules\api\ApiModule::class,
        ],
    ],
    'components' => [
        'request' => [
            'csrfParam' => '_csrf-backend',
        ],
        'user' => [
            'identityClass' => 'common\models\User',
            'enableAutoLogin' => true,
            'identityCookie' => ['name' => '_identity-backend', 'httpOnly' => true],
        ],
        'session' => [
            'name' => 'agregator-backend',
        ],
        'queue' => [
            'class' => \yii\queue\redis\Queue::class,
            'redis' => 'redis',
            'channel' => 'agregator-queue',
            'as log' => \yii\queue\LogBehavior::class,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                // REST API v1
                'GET api/v1/cards' => 'api/v1/cards',
                'GET api/v1/updated' => 'api/v1/updated',
                'GET api/v1/brands' => 'api/v1/brands',
                'GET api/v1/categories' => 'api/v1/categories',
                'GET api/v1/suppliers' => 'api/v1/suppliers',
                'GET api/v1/stats' => 'api/v1/stats',
            ],
        ],
    ],
    'params' => $params,
];
