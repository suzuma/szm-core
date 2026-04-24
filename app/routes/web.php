<?php

declare(strict_types=1);

use App\Controllers\Admin\AuditController;
use App\Controllers\Admin\ConfigController;
use App\Controllers\Admin\WafController;
use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\UserController;
use Phroute\Phroute\Route;

/**
 * Rutas del núcleo — autenticación.
 *
 * Los proyectos añaden sus propias rutas después de este bloque.
 * Usa $router->group() para agrupar rutas con filtros comunes.
 *
 * Filtros disponibles (definidos en filters.php):
 *   'auth'  — requiere sesión activa
 *   'guest' — solo para no autenticados
 *   'csrf'  — valida token CSRF
 *
 * Ejemplo de ruta protegida:
 *   $router->get('/dashboard', [DashboardController::class, 'index'], [Route::BEFORE => 'auth']);
 *
 * Ejemplo de grupo protegido:
 *   $router->group([Route::BEFORE => 'auth'], function($router) {
 *       $router->get('/perfil', [PerfilController::class, 'index']);
 *   });
 */

// ── Health check — sin autenticación (para load balancers y monitoreo) ────
$router->get('/health', [HealthController::class, 'check']);

// ── Autenticación (solo para no autenticados) ──────────────────────────────
$router->group([Route::BEFORE => 'guest'], function ($router): void {
    $router->get('/login',  [AuthController::class, 'loginForm']);
    $router->post('/login', [AuthController::class, 'login']);

    $router->get('/forgot-password',  [AuthController::class, 'forgotForm']);
    $router->post('/forgot-password', [AuthController::class, 'forgot']);

    $router->get('/reset-password/{token}', [AuthController::class, 'resetForm']);
    $router->post('/reset-password',        [AuthController::class, 'reset']);
});

// ── Logout (requiere sesión activa) ────────────────────────────────────────
$router->post('/logout', [AuthController::class, 'logout'], [Route::BEFORE => 'auth']);

// ── Session keepalive (requiere sesión activa) ─────────────────────────────
$router->get('/session-keepalive', [AuthController::class, 'keepalive'], [Route::BEFORE => 'auth']);

// ── Home (requiere sesión activa) ──────────────────────────────────────────
$router->get('/', [HomeController::class, 'index'], [Route::BEFORE => 'auth']);

// ── Administración — solo rol admin ────────────────────────────────────────
$router->get('/admin/audit-log', [AuditController::class, 'index'], [Route::BEFORE => 'admin']);

// ── Configuración del sistema ───────────────────────────────────────────────
$router->get('/admin/config',  [ConfigController::class, 'index'],  [Route::BEFORE => 'admin']);
$router->post('/admin/config', [ConfigController::class, 'update'], [Route::BEFORE => 'admin']);

// ── WAF — monitoreo y gestión ──────────────────────────────────────────────
$router->group([Route::BEFORE => 'admin'], function ($router): void {
    $router->get('/admin/waf',              [WafController::class, 'index']);
    $router->get('/admin/waf/blocked-ips',  [WafController::class, 'blockedIps']);
    $router->get('/admin/waf/attack-logs',  [WafController::class, 'attackLogs']);
    $router->get('/admin/waf/geo-map',               [WafController::class, 'geoMap']);
    $router->post('/admin/waf/unban/{id:i}',         [WafController::class, 'unban']);
    $router->post('/admin/waf/sync-geo/{id:i}',      [WafController::class, 'syncGeo']);
    $router->get('/admin/waf/export/attack-logs',    [WafController::class, 'exportAttackLogs']);
    $router->get('/admin/waf/export/blocked-ips',    [WafController::class, 'exportBlockedIps']);
});

// ── Gestión de usuarios (CRUD vía AJAX) ────────────────────────────────────
$router->group([Route::BEFORE => 'admin'], function ($router): void {
    $router->get('/admin/users',                   [UserController::class, 'index']);
    $router->post('/admin/users',                  [UserController::class, 'store']);
    $router->put('/admin/users/{id:i}',            [UserController::class, 'update']);
    $router->patch('/admin/users/{id:i}/toggle',   [UserController::class, 'toggleActive']);
    $router->delete('/admin/users/{id:i}',         [UserController::class, 'destroy']);
});