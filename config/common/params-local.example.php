<?php

declare(strict_types=1);

// Example of private overrides. Copy this file to params-local.php and adjust values.
// This project already enables recursive merge for params, so plain arrays are enough.
return [
    // Application environment (dev|test|prod)
    'APP_ENV' => 'prod',

    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=dating_bot;charset=utf8mb4',
        'user' => 'root',
        'pass' => 'change-me',
    ],

    'rabbitmq' => [
        'host' => '127.0.0.1',
        'port' => 5672,
        'user' => 'guest',
        'pass' => 'guest',
        'vhost' => '/',
    ],

    'telegram' => [
        'bots' => [
            '123' => [
                'token' => '123456:ABCDEF...',
                'profile_create_url' => 'https://www.znakomstva-chat-bot.com/custom-url-for-123',
            ],
            // '456' => [
            //     'token' => '...',
            //     'profile_create_url' => '...',
            // ],
        ],
        'base_url' => 'https://api.telegram.org',
        // optional logging bot/token and chat
        'log_bot_token' => null,
        'log_chat_id' => null,
    ],
];
