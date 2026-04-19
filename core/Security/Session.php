<?php
declare(strict_types=1);

namespace Core\Security;

use Core\ServicesContainer;

/**
 * Session
 *
 * Gestiona el ciclo de vida de la sesión PHP con configuración segura.
 * Lee los parámetros desde ServicesContainer (config.php → sección 'session').
 *
 * Centralizar aquí permite cambiar el handler (Redis, DB)
 * sin tocar el bootstrap ni ningún otro archivo.
 *
 * Uso:
 *   Session::start();           // inicia la sesión
 *   Session::set('user_id', 1); // guarda un valor
 *   Session::get('user_id');    // lee un valor
 *   Session::destroy();         // cierra la sesión
 */
final class Session
{
    // Clase utilitaria — no se instancia
    private function __construct() {}
    /* ------------------------------------------------------------------
     | CICLO DE VIDA
     ------------------------------------------------------------------ */

    /**
     * Inicia la sesión si no está activa.
     * Aplica configuración segura de cookies desde config_.php.
     */
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $config = ServicesContainer::getConfig('session');

        session_set_cookie_params([
            'lifetime' => (int) ($config['lifetime'] ?? 0) * 60, // minutos → segundos
            'path'     => '/',
            'domain'   => $config['domain']   ?? '',
            'secure'   => $config['secure']   ?? self::isHttps(),
            'httponly' => true,          // Nunca accesible desde JavaScript
            'samesite' => $config['samesite'] ?? 'Lax',
        ]);

        session_name($config['name'] ?? 'SPAA_SESSION');
        session_start();
    }

    /**
     * Regenera el ID de sesión.
     * Llamar siempre después de un login exitoso (previene session fixation).
     */
    public static function regenerate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        // Guardamos los datos actuales (incluye _token, _flash, etc.)
        $data = $_SESSION;

        // Destruimos completamente la sesión vieja
        session_unset();
        session_destroy();

        // Iniciamos sesión nueva (nuevo ID, nueva cookie Set-Cookie)
        session_start();

        // Restauramos los datos en la nueva sesión
        $_SESSION = $data;
    }

    /**
     * Destruye completamente la sesión y elimina la cookie del cliente.
     */
    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        // Vacía el array de sesión
        $_SESSION = [];

        // Elimina la cookie de sesión del navegador
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /* ------------------------------------------------------------------
     | LECTURA Y ESCRITURA
     ------------------------------------------------------------------ */

    /**
     * Guarda un valor en sesión.
     */
    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Lee un valor de sesión con valor por defecto.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Verifica si una clave existe en sesión.
     */
    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Elimina una clave de sesión.
     */
    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Guarda múltiples valores de una sola vez.
     * Ideal para guardar datos del usuario tras el login.
     *
     * Ejemplo:
     *   Session::put([
     *       'user_id'   => $user->id,
     *       'user_role' => $user->role,
     *       'user'      => $user->toArray(),
     *   ]);
     */
    public static function put(array $data): void
    {
        foreach ($data as $key => $value) {
            $_SESSION[$key] = $value;
        }
    }

    /**
     * Lee un valor y lo elimina de sesión en una sola operación.
     * Útil para mensajes flash manuales.
     */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);
        return $value;
    }

    /**
     * Retorna todos los datos de la sesión actual.
     */
    public static function all(): array
    {
        return $_SESSION ?? [];
    }

    /* ------------------------------------------------------------------
     | ESTADO
     ------------------------------------------------------------------ */

    /**
     * Verifica si la sesión está activa.
     */
    public static function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * Retorna el ID de sesión actual.
     */
    public static function id(): string
    {
        return session_id() ?: '';
    }

    /* ------------------------------------------------------------------
     | HELPER PRIVADO
     ------------------------------------------------------------------ */

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    }

}