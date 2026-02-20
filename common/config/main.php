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
    ],
];
