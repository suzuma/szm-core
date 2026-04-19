<?php

namespace Core\Security;

/**
 * CsrfToken
 *
 * Genera y verifica el token CSRF de la sesión.
 */
final class CsrfToken
{
    private const SESSION_KEY = '_token';
    private const BYTES       = 32;

    private function __construct() {}

    /** Genera el token si no existe en sesión */
    public static function ensureExists(): void
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(self::BYTES));
        }
    }

    /** Obtiene el token actual */
    public static function get(): string
    {
        return $_SESSION[self::SESSION_KEY] ?? '';
    }

    /**
     * Valida un token recibido (hash_equals para timing-safe).
     */
    public static function validate(string $token): bool
    {
        return hash_equals(self::get(), $token);
    }
}