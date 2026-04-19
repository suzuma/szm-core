<?php

declare(strict_types=1);

use App\Controllers\Admin\AuditController;
use App\Controllers\Admin\CategoryAdminController;
use App\Controllers\Admin\MediaController;
use App\Controllers\Admin\PostAdminController;
use App\Controllers\AuthController;
use App\Controllers\Blog\BlogController;
use App\Controllers\FeedController;
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

// ── Gestión de usuarios (CRUD vía AJAX) ────────────────────────────────────
$router->group([Route::BEFORE => 'admin'], function ($router): void {
    $router->get('/admin/users',                   [UserController::class, 'index']);
    $router->post('/admin/users',                  [UserController::class, 'store']);
    $router->put('/admin/users/{id:i}',            [UserController::class, 'update']);
    $router->patch('/admin/users/{id:i}/toggle',   [UserController::class, 'toggleActive']);
    $router->delete('/admin/users/{id:i}',         [UserController::class, 'destroy']);
});

// ── Blog admin (solo rol admin) ────────────────────────────────────────────
$router->group([Route::BEFORE => 'admin'], function ($router): void {
    // Posts
    $router->get('/admin/blog',                       [PostAdminController::class, 'index']);
    $router->get('/admin/blog/create',                [PostAdminController::class, 'create']);
    $router->post('/admin/blog',                      [PostAdminController::class, 'store']);
    $router->post('/admin/blog/slug-preview',         [PostAdminController::class, 'slugPreview']);
    $router->post('/admin/blog/media',                [MediaController::class, 'store']);
    $router->get('/admin/blog/{id:i}/edit',           [PostAdminController::class, 'edit']);
    $router->put('/admin/blog/{id:i}',                [PostAdminController::class, 'update']);
    $router->delete('/admin/blog/{id:i}',             [PostAdminController::class, 'destroy']);
    $router->post('/admin/blog/{id:i}/publish',       [PostAdminController::class, 'publish']);
    // Categorías
    $router->get('/admin/blog/categories',                      [CategoryAdminController::class, 'index']);
    $router->post('/admin/blog/categories',                     [CategoryAdminController::class, 'store']);
    $router->put('/admin/blog/categories/{id:i}',               [CategoryAdminController::class, 'update']);
    $router->delete('/admin/blog/categories/{id:i}',            [CategoryAdminController::class, 'destroy']);
    $router->patch('/admin/blog/categories/{id:i}/toggle',      [CategoryAdminController::class, 'toggleActive']);
});

// ── Feeds SEO (públicos) ───────────────────────────────────────────────────
$router->get('/sitemap.xml', [FeedController::class, 'sitemap']);
$router->get('/rss.xml',     [FeedController::class, 'rss']);
$router->get('/robots.txt',  [FeedController::class, 'robots']);

// ── Blog / Noticias (acceso público, sin filtro auth) ─────────────────────
$router->get('/blog',                          [BlogController::class, 'index']);
$router->get('/blog/categoria/{slug}',         [BlogController::class, 'byCategory']);
$router->get('/blog/tag/{slug}',               [BlogController::class, 'byTag']);
$router->get('/blog/{slug}',                   [BlogController::class, 'show']);