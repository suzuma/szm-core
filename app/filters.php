<?php

declare(strict_types=1);

use Core\Auth\Auth;
use Core\Security\CsrfToken;

/**
 * Filtros (middleware) de la aplicación.
 *
 * Se registran sobre $router (RouteCollector) y se aplican a rutas
 * o grupos con ['before' => 'nombre'] / ['after' => 'nombre'].
 *
 * Si el filtro retorna null  → la petición continúa al controlador.
 * Si el filtro retorna valor → ese valor se usa como respuesta (cortocircuito).
 * Si el filtro hace exit     → termina la ejecución.
 *
 * Uso en rutas:
 *   $router->get('/dashboard', [DashboardController::class, 'index'], ['before' => 'auth']);
 *
 * Uso en grupos:
 *   $router->group(['before' => 'auth'], function($router) {
 *       $router->get('/perfil', [PerfilController::class, 'index']);
 *       $router->get('/config', [ConfigController::class, 'index']);
 *   });
 */

// ── auth — requiere sesión activa ──────────────────────────────────────────
// Redirige a /login si el usuario no está autenticado.
$router->filter('auth', function (): void {
    if (Auth::guest()) {
        header('Location: /login', true, 302);
        exit;
    }
});

// ── guest — solo para no autenticados ─────────────────────────────────────
// Redirige a / si el usuario ya inició sesión.
// Úsalo en login, forgot-password, reset-password.
$router->filter('guest', function (): void {
    if (Auth::check()) {
        header('Location: /', true, 302);
        exit;
    }
});

// ── csrf — valida token CSRF en POST / PUT / PATCH / DELETE ───────────────
// Úsalo en rutas que no llaman a validateCsrf() desde el controlador.
$router->filter('csrf', function (): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        if (!CsrfToken::validate($token)) {
            http_response_code(419);
            echo 'Token CSRF inválido.';
            exit;
        }
    }
});

// ── admin — requiere sesión activa con rol 'admin' ────────────────────────
// Redirige a / si el usuario autenticado no es administrador.
$router->filter('admin', function (): void {
    if (Auth::guest()) {
        header('Location: /login', true, 302);
        exit;
    }
    if (Auth::user()?->role?->name !== 'admin') {
        http_response_code(403);
        // Redirige al home con mensaje; el controlador podría hacer abort(403)
        header('Location: /', true, 302);
        exit;
    }
});

// ── can:{permiso} — verifica permiso específico ───────────────────────────
// Ejemplo: ['before' => 'can:users.edit']
// Nota: Phroute no soporta parámetros en filtros nativamente;
// para permisos dinámicos usa Auth::can() dentro del controlador.