<?php

declare(strict_types=1);

use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Mysql\Connection;
use Yiisoft\Db\Mysql\Driver;

$rootPath = __DIR__;

// Prefer params (with optional params-local) for DB configuration instead of .env
$params = [];
$commonParams = $rootPath . '/config/common/params.php';
$commonParamsLocal = $rootPath . '/config/common/params-local.php';
if (is_file($commonParams)) {
    /** @var array $params */
    $params = require $commonParams;
    if (is_file($commonParamsLocal)) {
        /** @var array $local */
        $local = require $commonParamsLocal;
        $params = array_replace_recursive($params, $local);
    }
}

$dsn = $params['db']['dsn'] ?? 'mysql:host=127.0.0.1;dbname=dating_bot;charset=utf8mb4';
$user = $params['db']['user'] ?? 'root';
$pass = $params['db']['pass'] ?? '';

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
