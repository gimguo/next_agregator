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
];
