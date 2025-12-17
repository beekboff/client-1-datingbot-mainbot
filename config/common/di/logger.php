<?php

declare(strict_types=1);

use App\Infrastructure\Logging\TelegramLogTarget;
use Psr\Log\LoggerInterface;
use Yiisoft\Definitions\ReferencesArray;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;
use Yiisoft\Log\Target\File\FileTarget;

/** @var array $params */

return [
    LoggerInterface::class => [
        'class' => Logger::class,
        '__construct()' => [
            'targets' => ReferencesArray::from([
                FileTarget::class,
                StreamTarget::class,
                TelegramLogTarget::class,
            ]),
        ],
        // Flush to targets on every message (important for long-running consumers)
        'setFlushInterval()' => [1],
        // Include short call stack in context for better diagnostics in Telegram/file logs
        'setTraceLevel()' => [5],
    ],
];
