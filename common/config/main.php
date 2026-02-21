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

        // ═══ Redis Staging для импорта ═══
        // Быстрое хранилище для парсинга → нормализации → bulk persist
        'importStaging' => [
            'class' => \common\services\ImportStagingService::class,
            'cleanupHours' => 48, // Авто-очистка сессий старше 48 часов
        ],

        // ═══ Получение прайсов (FTP, URL, Email, API) — Legacy ═══
        'priceFetcher' => [
            'class' => \common\services\PriceFetcher::class,
        ],

        // ═══ Fetcher Factory — Strategy Pattern (Sprint 14) ═══
        'fetcherFactory' => [
            'class' => \common\services\fetcher\FetcherFactory::class,
        ],

        // ═══ MDM Matching Engine (Chain of Responsibility) ═══
        'matchingService' => [
            'class' => \common\services\matching\MatchingService::class,
            'logResults' => true,
        ],

        // ═══ Golden Record — пересчёт агрегатов модели/варианта ═══
        'goldenRecord' => [
            'class' => \common\services\GoldenRecordService::class,
        ],

        // ═══ Pricing Engine — движок ценообразования (Sprint 11) ═══
        'pricingService' => [
            'class' => \common\services\PriceCalculationService::class,
        ],

        // ═══ Readiness Scoring — скоринг полноты данных (Sprint 12) ═══
        'readinessService' => [
            'class' => \common\services\ReadinessScoringService::class,
        ],

        // ═══ Auto-Healing — AI самовосстановление каталога (Sprint 13) ═══
        'autoHealer' => [
            'class' => \common\services\AutoHealingService::class,
        ],

        // ═══ Catalog Persister — материализация DTO → MDM каталог ═══
        'catalogPersister' => [
            'class' => \common\services\CatalogPersisterService::class,
        ],

        // ═══ Transactional Outbox — очередь изменений для синдикации ═══
        'outbox' => [
            'class' => \common\services\OutboxService::class,
            'deduplication' => true,
        ],

        // ═══ Syndication — трансформация MDM → проекция для витрины ═══
        'syndicationService' => [
            'class' => \common\services\RosMatrasSyndicationService::class,
        ],

        // ═══ Marketplace API Client (RosMatras HTTP) — Legacy single-channel ═══
        'marketplaceClient' => [
            'class' => \common\services\marketplace\RosMatrasApiClient::class,
            // apiUrl и apiToken подтягиваются из params['rosmatras'] в init()
        ],

        // ═══ Channel Driver Factory — Multi-Channel Syndication ═══
        'channelFactory' => [
            'class' => \common\services\channel\ChannelDriverFactory::class,
            'drivers' => [
                'rosmatras' => [
                    'syndicator' => \common\services\channel\drivers\RosMatrasSyndicator::class,
                    'client'     => \common\services\channel\drivers\RosMatrasChannelClient::class,
                ],
                // 'ozon' => [
                //     'syndicator' => \common\services\channel\drivers\OzonSyndicator::class,
                //     'client'     => \common\services\channel\drivers\OzonChannelClient::class,
                // ],
            ],
        ],

        // ═══ Variant Exploder — разложение вариантов по размерам (Sprint 16) ═══
        'variantExploder' => [
            'class' => \common\services\VariantExploderService::class,
        ],

        // ═══ DAM — Digital Asset Management (S3/MinIO медиа-пайплайн) ═══
        'mediaService' => [
            'class' => \common\services\MediaProcessingService::class,
            'webpQuality'  => 85,
            'maxDimension' => 1600,
            'thumbSize'    => 300,
        ],
    ],
];
