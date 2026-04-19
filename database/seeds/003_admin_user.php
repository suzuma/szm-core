<?php

/**
 * Seed 003 — Usuario administrador inicial
 *
 * Uso desde la raíz del proyecto:
 *   php database/seeds/003_admin_user.php
 *
 * Variables de entorno requeridas (se leen del .env):
 *   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
 *   ADMIN_EMAIL    (opcional, default: admin@ejemplo.com)
 *   ADMIN_NAME     (opcional, default: Administrador)
 *   ADMIN_PASSWORD (obligatorio o se solicita interactivamente)
 */

declare(strict_types=1);

// ── Carga del .env ────────────────────────────────────────────────────────────
$rootDir = dirname(__DIR__, 2);

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

// ── Configuración ─────────────────────────────────────────────────────────────
$host     = $_ENV['DB_HOST']     ?? '127.0.0.1';
$port     = $_ENV['DB_PORT']     ?? '3306';
$dbname   = $_ENV['DB_DATABASE'] ?? 'szm_core';
$username = $_ENV['DB_USERNAME'] ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? 'mysql';

$adminEmail = $_ENV['ADMIN_EMAIL'] ?? 'admin@ejemplo.com';
$adminName  = $_ENV['ADMIN_NAME']  ?? 'Administrador';

// Contraseña: de .env o solicitar interactivamente
$adminPass  = $_ENV['ADMIN_PASSWORD'] ?? '';

if ($adminPass === '') {
    echo "Contraseña para el administrador ({$adminEmail}): ";
    // Ocultar input en terminales Unix
    if (PHP_OS_FAMILY !== 'Windows') {
        system('stty -echo');
    }
    $adminPass = trim((string) fgets(STDIN));
    if (PHP_OS_FAMILY !== 'Windows') {
        system('stty echo');
    }
    echo PHP_EOL;
}

if (strlen($adminPass) < 8) {
    fwrite(STDERR, "ERROR: La contraseña debe tener al menos 8 caracteres.\n");
    exit(1);
}

// ── Conexión PDO ──────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR de conexión: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Obtener ID del rol admin ───────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id FROM szm_roles WHERE name = 'admin' LIMIT 1");
$stmt->execute();
$role = $stmt->fetch();

if (!$role) {
    fwrite(STDERR, "ERROR: No se encontró el rol 'admin'. Ejecuta primero los seeds 001 y 002.\n");
    exit(1);
}

$roleId = (int) $role['id'];

// ── Verificar si ya existe el usuario ─────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id FROM szm_users WHERE email = ? LIMIT 1");
$stmt->execute([$adminEmail]);

if ($stmt->fetch()) {
    echo "INFO: El usuario '{$adminEmail}' ya existe. No se realizaron cambios.\n";
    exit(0);
}

// ── Insertar usuario administrador ────────────────────────────────────────────
$hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
$now  = date('Y-m-d H:i:s');

$stmt = $pdo->prepare("
    INSERT INTO szm_users
        (role_id, name, email, password, active, failed_attempts, created_at, updated_at)
    VALUES
        (:role_id, :name, :email, :password, 1, 0, :created_at, :updated_at)
");

$stmt->execute([
    ':role_id'    => $roleId,
    ':name'       => $adminName,
    ':email'      => $adminEmail,
    ':password'   => $hash,
    ':created_at' => $now,
    ':updated_at' => $now,
]);

$userId = (int) $pdo->lastInsertId();

echo "✓ Usuario administrador creado:\n";
echo "  ID:     {$userId}\n";
echo "  Nombre: {$adminName}\n";
echo "  Email:  {$adminEmail}\n";
echo "  Rol:    admin\n";
echo "\n⚠  Cambia la contraseña en el primer inicio de sesión.\n";