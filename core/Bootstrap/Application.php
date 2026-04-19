<?php
declare(strict_types=1);

namespace Core\Bootstrap;


use Core\Security\CsrfToken;
use Core\Security\Session;
use Core\Security\Waf\Waf;
use Core\Http\StaticFileHandler;
use Core\Http\Router;
use Core\Log;
use Core\ServicesContainer;
use Dotenv\Dotenv;
use Phroute\Phroute\RouteCollector;
use Phroute\Phroute\Dispatcher;
use Core\PhrouteResolver;
use App\Controllers\ErrorController;
use Throwable;

final class Application
{
    /** Ruta física de /public (donde vive index.php) */
    private readonly string $publicDir;

    /** URI limpia (sin query string) */
    private readonly string $uri;

    /** RouteCollector de Phroute */
    private RouteCollector $routeCollector;

    public function __construct(string $publicDir, string $uri){
        $this->publicDir      = $publicDir;
        $this->uri            = $uri;
        $this->routeCollector = new RouteCollector();
    }

    public static function boot(string $publicDir): self
    {
        // URI limpia, disponible desde el primer instante
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $app = new self($publicDir, $uri);

        $app->loadEnvironment();
        $app->loadConfig();
        $app->configurePhpRuntime();
        $app->defineConstants();
        $app->initializeDatabase();
        $app->guardSystemState();
        $app->runWaf();
        $app->startSession();
        $app->writeAccessLog();

        return $app;
    }

    /* ------------------------------------------------------------------
      | PASOS DE BOOTSTRAP (privados, orden explícito en ::boot)
      ------------------------------------------------------------------ */

    /** a) Carga variables de entorno desde .env */
    private function loadEnvironment(): void
    {
        // El .env vive en el mismo directorio que index.php (raíz del proyecto)
        $rootDir = $this->publicDir;

        if (file_exists($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->load();
        }
    }

    /** b) Carga la configuración y la inyecta en el contenedor */
    private function loadConfig(): void
    {
        $configFile = $this->publicDir . '/config.php';

        if (!file_exists($configFile)) {
            throw new \RuntimeException("Archivo de configuración no encontrado: {$configFile}");
        }

        ServicesContainer::setConfig(require $configFile);
    }

    /** c) Ajusta PHP runtime según entorno (dev / prod) */
    private function configurePhpRuntime(): void
    {
        $isDev   = ServicesContainer::getConfig('app.environment', 'prod') === 'dev';
        $logPath = ServicesContainer::getConfig('logging.path', dirname($this->publicDir) . '/storage/logs');

        date_default_timezone_set(
            ServicesContainer::getConfig('app.timezone', 'UTC')
        );

        ini_set('memory_limit', '-1');
        ini_set('log_errors',   '1');
        ini_set('error_log',    $logPath . '/php-error.log');

        //error_reporting($isDev ? E_ALL & ~E_DEPRECATED : 0);
        error_reporting(E_ALL & ~E_DEPRECATED);
        ini_set('display_errors', $isDev ? '1' : '0');
    }

    /** d) Define constantes de ruta y URL base */
    private function defineConstants(): void
    {
        $rootDir = $this->publicDir;

        // ── Tiempo de inicio (solo si no está definida, evita redefinición) ──
        defined('_START_TIME_') || define('_START_TIME_', microtime(true));

        // ── Empresa ───────────────────────────────────────────────────────────
        define('_EMPRESA_', ServicesContainer::getConfig('empresa.nombre', 'SPAAUTES'));

        // ── Rutas del sistema de archivos ─────────────────────────────────────
        define('_BASE_PATH_',  $rootDir . '/');
        define('_APP_PATH_',   $rootDir . '/app/');
        define('_CACHE_PATH_', ServicesContainer::getConfig('cache.path',   $rootDir . '/storage/cache') . '/');
        define('_LOG_PATH_',   ServicesContainer::getConfig('logging.path', $rootDir . '/storage/logs')  . '/');
        define('_UPLOAD_PATH_',ServicesContainer::getConfig('storage.path', $rootDir . '/storage/uploads') . '/');

        // ── URL base (detecta HTTPS automáticamente) ──────────────────────────
        $appUrl = ServicesContainer::getConfig('app.url', '');

        if ($appUrl !== '') {
            // Usamos la URL explícita del .env (más confiable en producción)
            define('_BASE_HTTP_', rtrim($appUrl, '/'));
        } else {
            // Fallback: detectamos esquema y host desde el servidor
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            define('_BASE_HTTP_', $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        }
    }

    /** e) Inicializa la conexión a base de datos */
    private function initializeDatabase(): void
    {
        ServicesContainer::initializeDbContext();
    }

    /** f) Detiene la app si el entorno es "stop" (mantenimiento) */
    private function guardSystemState(): void
    {
        if (ServicesContainer::getConfig('app.environment', 'prod') === 'stop') {
            http_response_code(503);
            $view = _APP_PATH_ . 'Views/errors/503.twig';
            // La vista 503 es HTML puro (sin variables Twig), se puede servir directamente
            file_exists($view) ? readfile($view) : print('Sitio en mantenimiento. Vuelve pronto.');
            exit;
        }
    }

    /** g) Ejecuta el Web Application Firewall */
    private function runWaf(): void
    {
        (new Waf())->handle();
    }

    /** h) Inicia sesión segura y genera token CSRF */
    private function startSession(): void
    {
        Session::start();
        CsrfToken::ensureExists();
    }

    /** i) Registra la petición en el log de acceso */
    private function writeAccessLog(): void
    {
        Log::channel('access')->info('HTTP Request', [
            'ip'     => $this->resolveClientIp(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? '-',
            'uri'    => $_SERVER['REQUEST_URI']    ?? '-',
        ]);
    }

    /* ------------------------------------------------------------------
     | HTTP — servir assets y despachar rutas
     ------------------------------------------------------------------ */

    /**
     * Si la URI apunta a un archivo estático válido, lo sirve y termina.
     * Si no, retorna sin hacer nada (el flujo continúa al router).
     */
    public function serveStaticOrContinue(): void
    {
        StaticFileHandler::serveOrContinue($this->publicDir, $this->uri);
    }

    /**
     * Carga filtros y rutas, despacha la petición.
     * Ante cualquier Throwable: log + 404.
     */
    public function dispatch(): void
    {


        $router = $this->routeCollector;

        require_once _APP_PATH_ . 'providers.php';
        require_once _APP_PATH_ . 'filters.php';
        require_once _APP_PATH_ . 'routes/web.php';

        $dispatcher = new Dispatcher(
            $router->getData(),
            new PhrouteResolver()
        );

        // Soporte para _method override (PUT, PATCH, DELETE vía POST)
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method === 'POST') {
            $override = strtoupper((string) ($_POST['_method'] ?? ''));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        try {
            echo $dispatcher->dispatch($method, $this->uri);
        } catch (Throwable $e) {
            $this->handleDispatchError($e);
        }
    }

    /* ------------------------------------------------------------------
     | Helpers privados
     ------------------------------------------------------------------ */

    /** Resuelve la IP real del cliente respetando proxies de confianza */
    private function resolveClientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', $_SERVER[$key])[0]);
            }
        }
        return '0.0.0.0';
    }

    /** Loguea el error de despacho y responde con 404 */
    private function handleDispatchError(Throwable $e): void
    {
        Log::channel('error')->error('Dispatch error', [
            'uri'     => $_SERVER['REQUEST_URI'] ?? '-',
            'ip'      => $this->resolveClientIp(),
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ]);

        http_response_code(404);
        echo (new ErrorController())->notFound();
    }

    /* Accesores de solo lectura para tests / middleware */
    public function getUri(): string { return $this->uri; }
    public function getRouteCollector(): RouteCollector { return $this->routeCollector; }

}