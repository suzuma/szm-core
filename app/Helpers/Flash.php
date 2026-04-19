<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Flash
 *
 * Mensajes de un solo uso almacenados en sesión.
 * Se escriben en un request y se consumen en el siguiente.
 *
 * Tipos convencionales: 'success', 'error', 'warning', 'info'
 *
 * Uso en controlador:
 *   Flash::set('success', 'Sesión iniciada correctamente.');
 *   Flash::set('error',   'Credenciales incorrectas.');
 *
 * Uso en Twig (via TwigFactory):
 *   {% if has_flash('error') %}
 *     <p>{{ flash('error') }}</p>
 *   {% endif %}
 */
final class Flash
{
    private const KEY = '_flash';

    private function __construct() {}

    /** Guarda un mensaje flash en sesión. */
    public static function set(string $type, string $message): void
    {
        $_SESSION[self::KEY][$type] = $message;
    }

    /**
     * Lee y elimina un mensaje flash (one-time read).
     * Retorna cadena vacía si no existe.
     */
    public static function get(string $type): string
    {
        $message = $_SESSION[self::KEY][$type] ?? '';
        unset($_SESSION[self::KEY][$type]);
        return $message;
    }

    /** Verifica si existe un mensaje flash sin consumirlo. */
    public static function has(string $type): bool
    {
        return !empty($_SESSION[self::KEY][$type]);
    }
}