<?php

/**
 * migrate.php — Runner de migraciones y seeds de szm-core
 *
 * Uso desde la raíz del proyecto:
 *
 *   php database/migrate.php              → migraciones + todos los seeds
 *   php database/migrate.php --no-seed    → solo migraciones (sin seeds)
 *   php database/migrate.php --fresh      → DROP + migraciones + todos los seeds
 *
 * Seeds ejecutados en orden numérico:
 *   *.sql  → ejecutados directamente via PDO
 *   *.php  → ejecutados como subproceso (preserva input interactivo de terminal)
 *
 * Variables de entorno requeridas en .env:
 *   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
 *
 * Variables opcionales para el seed del admin (003_admin_user.php):
 *   ADMIN_EMAIL, ADMIN_NAME, ADMIN_PASSWORD
 */

declare(strict_types=1);

// ── CLI guard ─────────────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo puede ejecutarse desde la línea de comandos.');
}

// ── Flags ─────────────────────────────────────────────────────────────────────
$noSeed = in_array('--no-seed', $argv ?? [], true);
$fresh  = in_array('--fresh',   $argv ?? [], true);

// --seed se mantiene por compatibilidad pero ya no es necesario
$withSeed = !$noSeed;

// ── Carga del .env ────────────────────────────────────────────────────────────
$rootDir = dirname(__DIR__);

if (file_exists($rootDir . '/.env')) {
    $lines = file($rootDir . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

// ── Conexión PDO ──────────────────────────────────────────────────────────────
$host   = $_ENV['DB_HOST']     ?? '127.0.0.1';
$port   = $_ENV['DB_PORT']     ?? '3306';
$dbname = $_ENV['DB_DATABASE'] ?? 'szm_core';
$user   = $_ENV['DB_USERNAME'] ?? 'root';
$pass   = $_ENV['DB_PASSWORD'] ?? 'mysql';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    err("Conexión fallida: " . $e->getMessage());
    exit(1);
}

out("szm-core — Runner de migraciones");
out("Base de datos: {$dbname}@{$host}:{$port}");
out(str_repeat('─', 55));

// ── --fresh: elimina tablas en orden inverso ───────────────────────────────────
if ($fresh) {
    out("\n[FRESH] Eliminando tablas existentes...");

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $drops = [
        'szm_audit_log',
        'szm_role_permissions',
        'szm_users',
        'szm_permissions',
        'szm_roles',
        'waf_attack_logs_szm',
        'waf_blocked_ips_szm',
        'waf_requests_szm',
        'waf_cloud_ranges_szm',
    ];

    foreach ($drops as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        out("  ✓ DROP {$table}");
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

// ── Ejecutar migraciones ──────────────────────────────────────────────────────
$migrationsDir = __DIR__ . '/migrations';
$migrationFiles = glob($migrationsDir . '/*.sql');
sort($migrationFiles);

out("\n[MIGRATIONS]");

foreach ($migrationFiles as $file) {
    $name = basename($file);
    try {
        $sql = file_get_contents($file);
        // Ejecutar sentencia por sentencia (separadas por ';')
        foreach (splitStatements($sql) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
        out("  ✓ {$name}");
    } catch (PDOException $e) {
        err("  ✗ {$name}: " . $e->getMessage());
        exit(1);
    }
}

// ── Ejecutar seeds (SQL + PHP) ────────────────────────────────────────────────
if ($withSeed) {
    $seedsDir  = __DIR__ . '/seeds';

    // Recopilar SQL y PHP en un solo listado ordenado numéricamente
    $seedFiles = array_merge(
        glob($seedsDir . '/*.sql') ?: [],
        glob($seedsDir . '/*.php') ?: []
    );
    usort($seedFiles, fn(string $a, string $b): int => basename($a) <=> basename($b));

    out("\n[SEEDS]");

    foreach ($seedFiles as $file) {
        $name = basename($file);
        $ext  = pathinfo($file, PATHINFO_EXTENSION);

        if ($ext === 'sql') {
            // Seeds SQL: ejecutados directamente via PDO
            try {
                $sql = file_get_contents($file);
                foreach (splitStatements($sql) as $statement) {
                    if (trim($statement) !== '') {
                        $pdo->exec($statement);
                    }
                }
                out("  ✓ {$name}");
            } catch (PDOException $e) {
                err("  ✗ {$name}: " . $e->getMessage());
                exit(1);
            }
        } elseif ($ext === 'php') {
            // Seeds PHP: ejecutados como subproceso para aislar scope y
            // preservar el input interactivo de la terminal (ej: prompt de contraseña).
            out("  → {$name}");
            passthru(PHP_BINARY . ' ' . escapeshellarg($file), $exitCode);
            if ($exitCode !== 0) {
                err("  ✗ {$name} terminó con código {$exitCode}");
                exit($exitCode);
            }
            out("  ✓ {$name}");
        }
    }
}

out("\n✓ Completado.\n");

// ── Helpers ───────────────────────────────────────────────────────────────────

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

function err(string $msg): void
{
    fwrite(STDERR, "ERROR: {$msg}" . PHP_EOL);
}

/**
 * Divide un archivo SQL en sentencias individuales ignorando comentarios.
 * Soporta -- comentarios y bloques de varias líneas.
 *
 * @return string[]
 */
function splitStatements(string $sql): array
{
    // Elimina comentarios de bloque /* ... */
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

    // Elimina comentarios de línea -- ...
    $sql = preg_replace('/--[^\n]*/', '', $sql);

    return array_filter(
        array_map('trim', explode(';', $sql)),
        fn(string $s): bool => $s !== ''
    );
}