<?php

declare(strict_types=1);

use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Mysql\Connection;
use Yiisoft\Db\Mysql\Driver;

$rootPath = __DIR__;

// You can override via env vars DB_DSN, DB_USER, DB_PASS
$dsn = getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=dating_bot;charset=utf8mb4';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

return [
    'db' => new Connection(new Driver($dsn, $user, $pass), new SchemaCache(new ArrayCache())),
    // Where to generate new migrations and search for existing ones.
    // Enable BOTH namespace and path discovery to be robust across environments.
    'newMigrationPath' => $rootPath . '/migrations',
    'newMigrationNamespace' => 'App\\Migrations',
    'sourcePaths' => [$rootPath . '/migrations'],
    'sourceNamespaces' => ['App\\Migrations'],
    // Migration config options expected by yiisoft/db-migration binary
    // History table name
    'historyTable' => 'migration',
    // Optional settings (avoid PHP warnings in the CLI wrapper and use sane defaults)
    'container' => null,
    'migrationNameLimit' => 180,
    'maxSqlOutputLength' => 5000,
    'useTablePrefix' => false,
];
