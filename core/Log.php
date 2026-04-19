<?php

declare(strict_types=1);

namespace Core;

use Core\ServicesContainer;
use Monolog\Logger;
use Monolog\Level;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Log
 *
 * Fachada estática sobre Monolog.
 * Permite usar canales nombrados con lazy instantiation.
 *
 * Canales predefinidos:
 *   Log::channel('app')     → app-YYYY-MM-DD.log    (canal general)
 *   Log::channel('access')  → access-YYYY-MM-DD.log (peticiones HTTP)
 *   Log::channel('error')   → error-YYYY-MM-DD.log  (errores y excepciones)
 *   Log::channel('query')   → query-YYYY-MM-DD.log  (queries SQL en dev)
 *
 * Métodos PSR-3:
 *   Log::channel('app')->info('Mensaje', ['contexto' => 'valor']);
 *   Log::channel('error')->critical('Fallo grave', ['exception' => $e]);
 *
 * Shortcut (usa el canal 'app' por defecto):
 *   Log::info('Mensaje');
 *   Log::error('Algo falló');
 */
final class Log
{
    /** Instancias de Logger cacheadas por canal */
    private static array $channels = [];

    /** Formato de línea para los archivos de log */
    private const LINE_FORMAT = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    // Clase utilitaria — no se instancia
    private function __construct() {}

    /* ------------------------------------------------------------------
     | ACCESO POR CANAL
     ------------------------------------------------------------------ */

    /**
     * Retorna (o crea) un Logger de Monolog para el canal indicado.
     * Los canales se cachean — solo se crean una vez por request.
     *
     * @param string $name  Nombre del canal: 'app' | 'access' | 'error' | 'query' | cualquier string
     */
    public static function channel(string $name = 'app'): Logger
    {
        if (!isset(self::$channels[$name])) {
            self::$channels[$name] = self::createLogger($name);
        }

        return self::$channels[$name];
    }

    /* ------------------------------------------------------------------
     | SHORTCUTS — canal 'app' por defecto (métodos PSR-3)
     ------------------------------------------------------------------ */

    public static function debug(string $message, array $context = []): void
    {
        self::channel('app')->debug($message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::channel('app')->info($message, $context);
    }

    public static function notice(string $message, array $context = []): void
    {
        self::channel('app')->notice($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::channel('app')->warning($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::channel('error')->error($message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::channel('error')->critical($message, $context);
    }

    public static function alert(string $message, array $context = []): void
    {
        self::channel('error')->alert($message, $context);
    }

    public static function emergency(string $message, array $context = []): void
    {
        self::channel('error')->emergency($message, $context);
    }

    /* ------------------------------------------------------------------
     | FACTORY PRIVADA
     ------------------------------------------------------------------ */

    /**
     * Construye un Logger de Monolog para el canal dado.
     */
    private static function createLogger(string $channel): Logger
    {
        $logPath  = self::resolveLogPath();
        $logLevel = self::resolveLogLevel();
        $handler  = self::resolveChannelType();

        $formatter = new LineFormatter(
            format: self::LINE_FORMAT,
            dateFormat: self::DATE_FORMAT,
            allowInlineLineBreaks: true,
            ignoreEmptyContextAndExtra: true
        );

        // ── Handler según configuración ────────────────────────────────
        $streamHandler = match ($handler) {

            // Un archivo por día, máximo 30 archivos (un mes)
            'daily' => new RotatingFileHandler(
                filename: $logPath . "/{$channel}.log",
                maxFiles: 30,
                level:    $logLevel,
            ),

            // Un único archivo acumulativo
            'single' => new StreamHandler(
                stream: $logPath . "/{$channel}.log",
                level:  $logLevel,
            ),

            // Salida estándar de errores (útil en contenedores/Docker)
            'stderr' => new StreamHandler(
                stream: 'php://stderr',
                level:  $logLevel,
            ),

            // Fallback — daily por defecto
            default => new RotatingFileHandler(
                filename: $logPath . "/{$channel}.log",
                maxFiles: 30,
                level:    $logLevel,
            ),
        };

        $streamHandler->setFormatter($formatter);

        $logger = new Logger($channel);
        $logger->pushHandler($streamHandler);

        return $logger;
    }

    /* ------------------------------------------------------------------
     | HELPERS PRIVADOS
     ------------------------------------------------------------------ */

    /**
     * Resuelve la ruta de logs desde config o usa un fallback seguro.
     */
    private static function resolveLogPath(): string
    {
        // Intentamos leer desde el contenedor (puede no estar listo en el bootstrap temprano)
        try {
            $path = ServicesContainer::getConfig('logging.path', '');
        } catch (\Throwable) {
            $path = '';
        }

        // Fallback: si la constante ya está definida la usamos
        if (empty($path) && defined('_LOG_PATH_')) {
            $path = rtrim(_LOG_PATH_, '/');
        }

        // Último recurso: ruta relativa desde este archivo
        if (empty($path)) {
            $path = dirname(__DIR__, 2) . '/storage/logs';
        }

        // Crear el directorio si no existe
        if (!is_dir($path)) {
            mkdir($path, 0755, recursive: true);
        }

        return $path;
    }

    /**
     * Resuelve el nivel de log desde config.
     * En dev → DEBUG, en prod → WARNING.
     */
    private static function resolveLogLevel(): Level
    {
        try {
            $level = ServicesContainer::getConfig('logging.level', 'debug');
        } catch (\Throwable) {
            $level = 'debug';
        }

        return match (strtolower($level)) {
            'debug'     => Level::Debug,
            'info'      => Level::Info,
            'notice'    => Level::Notice,
            'warning'   => Level::Warning,
            'error'     => Level::Error,
            'critical'  => Level::Critical,
            'alert'     => Level::Alert,
            'emergency' => Level::Emergency,
            default     => Level::Debug,
        };
    }

    /**
     * Resuelve el tipo de handler desde config.
     */
    private static function resolveChannelType(): string
    {
        try {
            return ServicesContainer::getConfig('logging.channel', 'daily');
        } catch (\Throwable) {
            return 'daily';
        }
    }
}