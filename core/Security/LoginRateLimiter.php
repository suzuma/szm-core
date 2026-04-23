<?php

declare(strict_types=1);

namespace Core\Security;

/**
 * LoginRateLimiter — límite de intentos de inicio de sesión por IP.
 *
 * Máximo 10 intentos en 15 minutos por dirección IP, independientemente
 * del usuario que se intente autenticar. Protege contra credential stuffing
 * y fuerza bruta distribuida por cuenta.
 *
 * Requiere APCu (extensión PHP) habilitado. Si APCu no está disponible,
 * los métodos retornan false/void silenciosamente — el bloqueo por cuenta
 * (User::recordFailedAttempt) y el WAF global siguen activos.
 *
 * Uso:
 *   if (LoginRateLimiter::tooManyAttempts($ip)) {
 *       // 429 Too Many Requests
 *   }
 *   // ... intento de login ...
 *   LoginRateLimiter::clearAttempts($ip); // en login exitoso
 */
final class LoginRateLimiter
{
    /** Máximo de intentos permitidos dentro de la ventana. */
    private const int MAX_ATTEMPTS = 10;

    /** Ventana de tiempo en segundos (15 minutos). */
    private const int WINDOW_SECONDS = 900;

    /**
     * Registra un intento para la IP dada y devuelve true si supera el límite.
     *
     * El TTL del contador se fija en la primera petición dentro de la ventana
     * y no se renueva en intentos posteriores (ventana deslizante fija).
     */
    public static function tooManyAttempts(string $ip): bool
    {
        if (!self::available()) {
            return false;
        }

        $key = 'login_rate:' . hash('sha256', $ip);

        // Crea el contador solo si no existe (preserva TTL original)
        apcu_add($key, 0, self::WINDOW_SECONDS);

        // Incremento atómico; retorna el nuevo valor
        $attempts = apcu_inc($key);

        return $attempts > self::MAX_ATTEMPTS;
    }

    /**
     * Elimina el contador de intentos para la IP (login exitoso).
     * Permite que el usuario vuelva a tener el máximo de intentos disponibles.
     */
    public static function clearAttempts(string $ip): void
    {
        if (self::available()) {
            apcu_delete('login_rate:' . hash('sha256', $ip));
        }
    }

    /**
     * Segundos de bloqueo configurados — útil para el mensaje de error.
     */
    public static function windowSeconds(): int
    {
        return self::WINDOW_SECONDS;
    }

    private static function available(): bool
    {
        return function_exists('apcu_fetch') && apcu_enabled();
    }
}