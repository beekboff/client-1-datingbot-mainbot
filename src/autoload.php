<?php

declare(strict_types=1);

use App\Environment;
use Dotenv\Dotenv;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load environment variables from .env if available
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

Environment::prepare();
