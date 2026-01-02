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
    // Note: rabbitmq/telegram/db are intentionally not set here in order to avoid
    // duplicate-key conflicts with params-local.php on production. Provide them
    // in config/common/params-local.php or in environment-specific params.
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
    // 'db' moved to params-local.php or env-specific files; DI has safe defaults.
    'app' => [
        'profileCreateUrl' => 'https://www.znakomstva-chat-bot.com/1cd89232-cbdc-4795-b3fb-620e8340d3a8',
        // Public base URL of this bot service (used to compose image URLs for profiles in /storage)
        'publicBaseUrl' => 'https://back.znakomstva-chat.com',
    ],
    'APP_ENV' => 'prod'
];
