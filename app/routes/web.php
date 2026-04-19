<?php

declare(strict_types=1);

use App\Controllers\Admin\AuditController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
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