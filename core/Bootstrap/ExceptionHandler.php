<?php
declare(strict_types=1);

namespace Core\Bootstrap;

use Core\Log;
use ErrorException;
use Throwable;

final class ExceptionHandler
{
    private static bool $registered = false;

    // Clase utilitaria — no se instancia.
    private function __construct() {}

    /**
     * Registra los handlers. Idempotente: llamadas múltiples son seguras.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::registerErrorHandler();
        self::registerExceptionHandler();
        self::registerShutdownHandler();

        self::$registered = true;
    }

    /* ------------------------------------------------------------------
     | Handlers
     ------------------------------------------------------------------ */

    /**
     * Convierte errores de PHP en ErrorException.
     * Permite que el bloque try/catch del código normal los capture.
     */
    private static function registerErrorHandler(): void
    {
        set_error_handler(static function (
            int    $severity,
            string $message,
            string $file,
            int    $line
        ): bool {
            // Silenciar errores suprimidos con @
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    /**
     * Captura excepciones no manejadas.
     * Loguea y muestra una respuesta genérica.
     */
    private static function registerExceptionHandler(): void
    {
        set_exception_handler(static function (Throwable $e): void {
            self::logThrowable($e);
            self::renderError();
        });
    }

    /**
     * Captura errores fatales que PHP no convierte en excepciones
     * (E_ERROR, E_PARSE, E_CORE_ERROR, etc.)
     */
    private static function registerShutdownHandler(): void
    {
        register_shutdown_function(static function (): void {
            $error = error_get_last();

            if ($error === null) {
                return;
            }

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

            if (!in_array($error['type'], $fatalTypes, true)) {
                return;
            }

            self::logThrowable(new ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            ));

            self::renderError();
        });
    }

    /* ------------------------------------------------------------------
     | Helpers
     ------------------------------------------------------------------ */

    private static function logThrowable(Throwable $e): void
    {
        // Log::channel puede no estar disponible si el error ocurre
        // muy temprano (antes de que el contenedor esté listo).
        // Por eso usamos un fallback a error_log nativo.
        try {
            Log::channel('error')->critical('Unhandled exception', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ]);
        } catch (Throwable) {
            error_log(sprintf(
                '[CRITICAL] %s: %s in %s:%d',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }
    }

    /**
     * Emite una respuesta HTTP genérica sin filtrar información sensible.
     * En entorno dev podríamos mostrar más detalles (extensión futura).
     */
    private static function renderError(): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }

        // Respuesta mínima — sin stack trace en producción.
        echo '<!DOCTYPE html><html lang="es"><body>'
            . '<h1>Error interno del servidor</h1>'
            . '<p>Ocurrió un problema inesperado. Por favor intenta más tarde.</p>'
            . '</body></html>';
    }

}