<?php

return [
    'adminEmail' => 'admin@example.com',
    'supportEmail' => 'support@example.com',
    'senderEmail' => 'noreply@example.com',
    'senderName' => 'Example.com mailer',
    'user.passwordResetTokenExpire' => 3600,
    'user.passwordMinLength' => 8,

    // ═══ OpenRouter / DeepSeek AI ═══
    'openrouter' => [
        'apiKey' => '', // Задаётся в params-local.php или через env
        'model' => 'deepseek/deepseek-chat-v3-0324',
        'baseUrl' => 'https://openrouter.ai/api/v1',
    ],

    // ═══ Приложение ═══
    'appUrl' => 'http://localhost:8095',

    // ═══ Хранилище ═══
    'storagePath' => dirname(dirname(__DIR__)) . '/storage',
    'pricesPath' => dirname(dirname(__DIR__)) . '/storage/prices',
    'imagesPath' => dirname(dirname(__DIR__)) . '/storage/images',

    // ═══ Импорт ═══
    'import' => [
        'batchSize' => 50,
        'imageDownloadTimeout' => 30,
        'maxConcurrentImages' => 5,
    ],

    // ═══ RosMatras API (для синхронизации) ═══
    'rosmatras' => [
        'apiUrl' => '', // Задаётся в params-local.php
        'apiKey' => '', // Задаётся в params-local.php
    ],

    // ═══ S3 / MinIO ═══
    's3' => [
        'endpoint'  => getenv('S3_ENDPOINT') ?: 'http://minio:9000',
        'bucket'    => getenv('S3_BUCKET') ?: 'media',
        'key'       => getenv('S3_KEY') ?: 'minioadmin',
        'secret'    => getenv('S3_SECRET') ?: 'minioadmin',
        'region'    => getenv('S3_REGION') ?: 'us-east-1',
        'usePathStyle' => (bool)(getenv('S3_USE_PATH_STYLE') ?: true),
        'publicUrl' => getenv('S3_PUBLIC_URL') ?: 'http://localhost:9002/media',
    ],
];
