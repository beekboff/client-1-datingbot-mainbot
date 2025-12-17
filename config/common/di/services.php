<?php

declare(strict_types=1);

use App\Infrastructure\I18n\Localizer;
use App\Infrastructure\RabbitMQ\RabbitMqConnectionFactory;
use App\Infrastructure\RabbitMQ\RabbitMqService;
use App\Infrastructure\Logging\TelegramLogTarget;
use App\Infrastructure\Telegram\TelegramApi;
use App\Shared\AppOptions;
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
    MysqlConnection::class => static function () use ($params) {
        $dsn = getenv('DB_DSN') ?: ($params['db']['dsn'] ?? 'mysql:host=127.0.0.1;dbname=dating_bot;charset=utf8mb4');
        $user = getenv('DB_USER') ?: ($params['db']['user'] ?? 'root');
        $pass = getenv('DB_PASS') ?: ($params['db']['pass'] ?? '');
        $driver = new MysqlDriver($dsn, $user, $pass);
        $schemaCache = new SchemaCache(new ArrayCache());
        $conn = new MysqlConnection($driver, $schemaCache);
        return $conn;
    },

    ConnectionInterface::class => MysqlConnection::class,

    GuzzleClient::class => static fn () => new GuzzleClient(['timeout' => 10.0]),

    TelegramApi::class => static function (GuzzleClient $client, LoggerInterface $logger) use ($params) {
        $token = getenv('TELEGRAM_BOT_TOKEN') ?: ($params['telegram']['token'] ?? '');
        $baseUrl = rtrim($params['telegram']['base_url'] ?? 'https://api.telegram.org', '/');
        return new TelegramApi($client, $logger, $baseUrl, $token);
    },

    RabbitMqConnectionFactory::class => static function () use ($params) {
        $cfg = $params['rabbitmq'] ?? [];
        $host = getenv('RABBITMQ_HOST') ?: ($cfg['host'] ?? '127.0.0.1');
        $port = (int)(getenv('RABBITMQ_PORT') ?: ($cfg['port'] ?? 5672));
        $user = getenv('RABBITMQ_USER') ?: ($cfg['user'] ?? 'guest');
        $pass = getenv('RABBITMQ_PASS') ?: ($cfg['pass'] ?? 'guest');
        $vhost = getenv('RABBITMQ_VHOST') ?: ($cfg['vhost'] ?? '/');
        return new RabbitMqConnectionFactory($host, $port, $user, $pass, $vhost);
    },

    Localizer::class => static function () use ($params) {
        $path = $params['i18n']['path'] ?? __DIR__ . '/../../../resources/i18n';
        $default = $params['i18n']['default'] ?? 'en';
        $supported = $params['i18n']['supported'] ?? ['en', 'ru', 'es'];
        return new Localizer($path, $default, $supported);
    },

    // UserRepository will be auto-wired with ConnectionInterface

    AppOptions::class => [
        '__construct()' => [
            'profileCreateUrl' => $params['app']['profileCreateUrl'] ?? 'https://example.com/profile/create',
            'publicBaseUrl' => $params['app']['publicBaseUrl'] ?? 'https://example.com',
        ],
    ],

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
    TelegramLogTarget::class => static function (GuzzleClient $client) use ($params) {
        // Use a dedicated token for the logging bot if provided; fall back to main bot token
        $token = getenv('TELEGRAM_BOT_TOKEN_LOG')
            ?: getenv('TELEGRAM_LOG_BOT_TOKEN')
            ?: (getenv('TELEGRAM_BOT_TOKEN') ?: ($params['telegram']['token'] ?? ''));
        $baseUrl = rtrim($params['telegram']['base_url'] ?? 'https://api.telegram.org', '/');
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
        $api = new TelegramApi($client, $apiLogger, $baseUrl, $token);

        $chatId = getenv('TELEGRAM_LOG_CHAT_ID') ?: ($params['telegram']['log_chat_id'] ?? '');
        $env = getenv('APP_ENV') ?: ($params['APP_ENV'] ?? 'dev');
        return new TelegramLogTarget($api, $chatId, $env);
    },
];
