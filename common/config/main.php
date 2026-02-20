<?php

return [
    'name' => 'Агрегатор',
    'language' => 'ru-RU',
    'timeZone' => 'Asia/Krasnoyarsk',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
        '@storage' => dirname(dirname(__DIR__)) . '/storage',
    ],
    'vendorPath' => dirname(dirname(__DIR__)) . '/vendor',
    'components' => [
        'cache' => [
            'class' => \yii\redis\Cache::class,
        ],
        'authManager' => [
            'class' => \yii\rbac\DbManager::class,
        ],

        // ═══ Реестр парсеров ═══
        'parserRegistry' => [
            'class' => \common\components\parsers\ParserRegistry::class,
            'parsers' => [
                'ormatek' => ['class' => \common\components\parsers\OrmatekXmlParser::class],
            ],
        ],

        // ═══ AI Service (DeepSeek через OpenRouter) ═══
        // apiKey, model, baseUrl берутся из params['openrouter'] в init()
        'aiService' => [
            'class' => \common\services\AIService::class,
        ],

        // ═══ Сервис управления брендами ═══
        // aiService подтягивается из Yii::$app в init()
        'brandService' => [
            'class' => \common\services\BrandService::class,
        ],

        // ═══ Сопоставление товаров (4-уровневое) ═══
        // aiService подтягивается из Yii::$app в init()
        'productMatcher' => [
            'class' => \common\services\ProductMatcher::class,
        ],

        // ═══ Оркестратор импорта ═══
        // parserRegistry подтягивается из Yii::$app в init()
        'importService' => [
            'class' => \common\services\ImportService::class,
        ],

        // ═══ Получение прайсов (FTP, URL, Email, API) ═══
        'priceFetcher' => [
            'class' => \common\services\PriceFetcher::class,
        ],
    ],
];
