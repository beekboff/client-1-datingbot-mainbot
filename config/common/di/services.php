<?php

declare(strict_types=1);

use App\Infrastructure\I18n\Localizer;
use App\Infrastructure\RabbitMQ\RabbitMqConnectionFactory;
use App\Infrastructure\RabbitMQ\RabbitMqService;
use App\Infrastructure\Logging\TelegramLogTarget;
use App\Infrastructure\Telegram\TelegramApi;
use App\Shared\AppOptions;
use App\Shared\BotContext;
use App\User\UserRepository;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\Log\LogLevel;
use Yiisoft\Cache\CacheInterface;
use Yiisoft\Cache\Cache as YiisoftCache;
use Yiisoft\Cache\File\FileCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Mysql\Connection as MysqlConnection;
use Yiisoft\Db\Mysql\Driver as MysqlDriver;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Definitions\Reference;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;
use Yiisoft\Log\Logger;
use Yiisoft\Log\Target\File\FileTarget;

/** @var array $params */

return [
    BotContext::class => BotContext::class,

    MysqlConnection::class => static function (BotContext $botContext) use ($params) {
        $dsn = $params['db']['dsn'] ?? 'mysql:host=127.0.0.1;dbname=dating_bot;charset=utf8mb4';
        $botId = $botContext->getBotId();
        if ($botId !== null) {
            $dsn = preg_replace('/dbname=[^;]+/', "dbname={$botId}_dating_bot", $dsn);
        }
        $user = $params['db']['user'] ?? 'root';
        $pass = $params['db']['pass'] ?? '';
        $driver = new MysqlDriver($dsn, $user, $pass);
        $schemaCache = new SchemaCache(new ArrayCache());
        return new MysqlConnection($driver, $schemaCache);
    },

    ConnectionInterface::class => MysqlConnection::class,

    GuzzleClient::class => static fn () => new GuzzleClient(['timeout' => 10.0]),

    TelegramApi::class => static function (GuzzleClient $client, LoggerInterface $logger, BotContext $botContext) use ($params) {
        $botTokens = [];
        $bots = $params['telegram']['bots'] ?? [];
        foreach ($bots as $id => $botCfg) {
            if (isset($botCfg['token'])) {
                $botTokens[(string)$id] = $botCfg['token'];
            }
        }
        $baseUrl = rtrim($params['telegram']['base_url'] ?? 'https://api.telegram.org', '/');
        $defaultToken = $params['telegram']['token'] ?? '';
        return new TelegramApi($client, $logger, $baseUrl, $botContext, $botTokens, $defaultToken);
    },

    RabbitMqConnectionFactory::class => static function (BotContext $botContext) use ($params) {
        $cfg = $params['rabbitmq'] ?? [];
        $host = $cfg['host'] ?? '127.0.0.1';
        $port = (int) ($cfg['port'] ?? 5672);
        $user = $cfg['user'] ?? 'guest';
        $pass = $cfg['pass'] ?? 'guest';
        $vhost = $botContext->getBotId() ?? ($cfg['vhost'] ?? '/');
        return new RabbitMqConnectionFactory($host, $port, $user, $pass, (string)$vhost);
    },

    Localizer::class => static function () use ($params) {
        $path = $params['i18n']['path'] ?? __DIR__ . '/../../../resources/i18n';
        $default = $params['i18n']['default'] ?? 'en';
        $supported = $params['i18n']['supported'] ?? ['en', 'ru', 'es'];
        return new Localizer($path, $default, $supported);
    },

    // UserRepository will be auto-wired with ConnectionInterface

    AppOptions::class => static function (BotContext $botContext) use ($params) {
        $profileCreateUrl = $params['app']['profileCreateUrl'] ?? 'https://example.com/profile/create';
        $botId = $botContext->getBotId();
        if ($botId !== null && isset($params['telegram']['bots'][$botId]['profile_create_url'])) {
            $profileCreateUrl = (string)$params['telegram']['bots'][$botId]['profile_create_url'];
        }
        $publicBaseUrl = $params['app']['publicBaseUrl'] ?? 'https://example.com';
        return new AppOptions($profileCreateUrl, $publicBaseUrl);
    },

    RabbitMqService::class => [
        '__construct()' => [
            'factory' => Reference::to(RabbitMqConnectionFactory::class),
        ],
    ],

    // Application cache wiring
    // 1) PSR-16 SimpleCache adapter: file-based cache stored in runtime/cache
    SimpleCacheInterface::class => static function () {
        $base = dirname(__DIR__, 3); // project root
        $path = $base . '/runtime/cache';
        return new FileCache($path);
    },

    // 2) Yiisoft CacheInterface wrapper around PSR-16 cache
    CacheInterface::class => static function (SimpleCacheInterface $simpleCache) {
        return new YiisoftCache($simpleCache);
    },

    // Important: construct TelegramApi with a NullLogger here to avoid circular dependency:
    // Logger -> TelegramLogTarget -> TelegramApi -> LoggerInterface
    TelegramLogTarget::class => static function (GuzzleClient $client, BotContext $botContext) use ($params) {
        $botTokens = [];
        $bots = $params['telegram']['bots'] ?? [];
        foreach ($bots as $id => $botCfg) {
            if (isset($botCfg['token'])) {
                $botTokens[(string)$id] = $botCfg['token'];
            }
        }
        $baseUrl = rtrim($params['telegram']['base_url'] ?? 'https://api.telegram.org', '/');
        $defaultToken = $params['telegram']['log_bot_token']
            ?? $params['telegram']['token']
            ?? '';

        // Create a dedicated file logger for Telegram API diagnostics to avoid circular deps
        $base = dirname(__DIR__, 3);
        $tgApiLogFile = $base . '/runtime/logs/telegram-api.log';
        $tgApiTarget = (new FileTarget($tgApiLogFile))->setLevels([
            LogLevel::WARNING,
            LogLevel::ERROR,
            LogLevel::CRITICAL,
            LogLevel::ALERT,
            LogLevel::EMERGENCY,
        ]);
        $apiLogger = new Logger([$tgApiTarget]);
        $api = new TelegramApi($client, $apiLogger, $baseUrl, $botContext, $botTokens, $defaultToken);

        $chatId = $params['telegram']['log_chat_id'] ?? '';
        $env = $params['APP_ENV'] ?? 'dev';
        return new TelegramLogTarget($api, $chatId, $env);
    },
];
