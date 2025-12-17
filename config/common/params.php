<?php

declare(strict_types=1);

return [
    'application' => require __DIR__ . '/application.php',

    'yiisoft/aliases' => [
        'aliases' => require __DIR__ . '/aliases.php',
    ],

    // App specific params (can be overridden via env or env-specific params files)
    'i18n' => [
        'path' => dirname(__DIR__, 2) . '/resources/i18n',
        'default' => 'en',
        'supported' => ['en', 'ru', 'es'],
    ],
    'rabbitmq' => [
        'host' => '127.0.0.1',
        'port' => 5672,
        'user' => 'guest',
        'pass' => 'guest',
        'vhost' => '/',
    ],
    'telegram' => [
        'token' => '',
        'base_url' => 'https://api.telegram.org',
    ],
    // Yii DB Migration settings: make migrations work without extra flags
    'yiisoft/db-migration' => [
        // Use namespaced migrations under App\\Migrations (mapped to ./migrations via composer.json)
        'newMigrationNamespace' => 'App\\Migrations',
        'sourceNamespaces' => [
            'App\\Migrations',
        ],
        // Disable path-based discovery to avoid duplicate non-namespaced classes
        'newMigrationPath' => '',
        'sourcePaths' => [],
    ],
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=dating_bot;charset=utf8mb4',
        'user' => 'root',
        'pass' => 'verysecret',
    ],
    'app' => [
        'profileCreateUrl' => 'https://www.znakomstva-chat-bot.com/1cd89232-cbdc-4795-b3fb-620e8340d3a8',
        // Public base URL of this bot service (used to compose image URLs for profiles in /storage)
        'publicBaseUrl' => 'https://back.znakomstva-chat.com',
    ],
    'APP_ENV' => 'dev'
];
