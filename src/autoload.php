<?php

declare(strict_types=1);

use App\Environment;
use Dotenv\Dotenv;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load environment variables from .env if available (optional for local dev)
$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    if (class_exists(Dotenv::class)) {
        Dotenv::createImmutable($root)->safeLoad();
    } else {
        // Minimal fallback loader for simple KEY=VALUE lines
        $envPath = $root . '/.env';
        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            // Strip optional quotes
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            if ($key !== '') {
                putenv($key . '=' . $val);
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

// Bridge params to environment for early bootstrap
// We prefer params-local (not committed) to define APP_ENV in non-Docker prod.
// Note: we only export APP_ENV here; other settings are read from $params later via config plugin.
try {
    $commonParamsPath = $root . '/config/common/params.php';
    $commonParamsLocalPath = $root . '/config/common/params-local.php';
    if (is_file($commonParamsPath)) {
        /** @var array $commonParams */
        $commonParams = require $commonParamsPath;
        $params = $commonParams;
        if (is_file($commonParamsLocalPath)) {
            /** @var array $localParams */
            $localParams = require $commonParamsLocalPath;
            // Simple shallow merge is sufficient for APP_ENV at the root level
            $params = array_replace_recursive($params, $localParams);
        }
        if (isset($params['APP_ENV']) && is_string($params['APP_ENV']) && $params['APP_ENV'] !== '') {
            // Override any value possibly set from .env
            $appEnv = $params['APP_ENV'];
            putenv('APP_ENV=' . $appEnv);
            $_ENV['APP_ENV'] = $appEnv;
            $_SERVER['APP_ENV'] = $appEnv;
        }
    }
} catch (\Throwable) {
    // Ignore any errors here to not break bootstrap; Environment::prepare() will validate later.
}

Environment::prepare();
