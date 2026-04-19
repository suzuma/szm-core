<?php

/**
 * config.php — Configuración central del framework.
 *
 * Lee valores desde $_ENV (cargados por Dotenv desde .env).
 * Copia .env.example → .env y ajusta los valores antes de arrancar.
 */

return [

    // ── Aplicación ────────────────────────────────────────────────────────
    'app' => [
        'environment' => $_ENV['APP_ENV']      ?? 'prod',   // dev | prod | stop
        'url'         => $_ENV['APP_URL']       ?? '',
        'timezone'    => $_ENV['APP_TIMEZONE']  ?? 'UTC',
    ],

    // ── Empresa / proyecto ────────────────────────────────────────────────
    'empresa' => [
        'nombre' => $_ENV['EMPRESA_NOMBRE'] ?? 'SZM Framework',
    ],

    // ── Base de datos ─────────────────────────────────────────────────────
    'database' => [
        'driver'    => 'mysql',
        'host'      => $_ENV['DB_HOST']     ?? '127.0.0.1',
        'port'      => (int) ($_ENV['DB_PORT'] ?? 3306),
        'database'  => $_ENV['DB_DATABASE'] ?? '',
        'username'  => $_ENV['DB_USERNAME'] ?? 'root',
        'password'  => $_ENV['DB_PASSWORD'] ?? '',
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix'    => '',
        'strict'    => true,
        'engine'    => 'InnoDB',
    ],

    // ── Sesión ────────────────────────────────────────────────────────────
    'session' => [
        'name'     => $_ENV['SESSION_NAME']     ?? 'SZM_SESSION',
        'lifetime' => (int) ($_ENV['SESSION_LIFETIME'] ?? 120),  // minutos
        'secure'   => filter_var($_ENV['SESSION_SECURE'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'samesite' => $_ENV['SESSION_SAMESITE'] ?? 'Lax',
        'domain'   => '',
    ],

    // ── Logging ───────────────────────────────────────────────────────────
    'logging' => [
        'channel' => 'daily',   // daily | single | stderr
        'level'   => 'debug',
        'path'    => __DIR__ . '/storage/logs',
    ],

    // ── Almacenamiento ────────────────────────────────────────────────────
    'cache' => [
        'path' => __DIR__ . '/storage/cache',
    ],

    'storage' => [
        'path' => __DIR__ . '/storage/uploads',
    ],

];