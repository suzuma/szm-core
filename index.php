<?php
declare(strict_types=1);

use Core\Bootstrap\Application;
use Core\Bootstrap\ExceptionHandler;


// ── 1. Autoloader ────────────────────────────────────────────────────────────
require_once __DIR__ . '/vendor/autoload.php';

// ── 2. Manejo global de excepciones (antes de cualquier otra cosa) ────────────
//    ExceptionHandler registra set_error_handler + set_exception_handler
//    y delega a Monolog. No muestra nada en producción.
ExceptionHandler::register();

// ── 3. Arranque de la aplicación ──────────────────────────────────────────────
//    Application::boot() orquesta en orden estricto:
//      a) .env  (Dotenv)
//      b) Config (config_.php → ServicesContainer)
//      c) PHP ini / timezone / error_reporting según entorno
//      d) Sesión + token CSRF
//      e) Constantes de ruta (_BASE_PATH_, _APP_PATH_, etc.)
//      f) DB Context
//      g) WAF
//      h) Access log (Monolog channel "access")
//    Devuelve la app ya lista; lanza RuntimeException si algo falla.
$app = Application::boot(__DIR__);

// ── 4. Servir assets estáticos (CSS, JS, imágenes, fuentes) ──────────────────
//    StaticFileHandler::handle() decide si la petición es un asset,
//    lo sirve con el Content-Type correcto y hace exit.
//    Si no es un asset, retorna false y continuamos al router.
$app->serveStaticOrContinue();

// ── 5. Despacho HTTP ──────────────────────────────────────────────────────────
//    - Carga filters.php  (Phroute before/after filters)
//    - Carga routes/web.php
//    - Despacha METHOD + URI
//    - En caso de Throwable: log de error → ErrorController::notFound()
$app->dispatch();
