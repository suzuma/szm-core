<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * OldInput — persiste valores POST y errores por campo entre redirecciones.
 *
 * Uso típico en un controlador:
 *   OldInput::flash(['email' => $email], ['email' => 'Correo inválido.']);
 *   $this->back('/login');
 *
 * TwigFactory lee y consume los valores en el primer render:
 *   {{ old.email }}        → valor anterior del campo
 *   {{ errors.email }}     → error por campo
 */
final class OldInput
{
    private const KEY_DATA   = '_old_input';
    private const KEY_ERRORS = '_field_errors';

    private function __construct() {}

    /**
     * Guarda valores de input y errores por campo en sesión.
     * Llamar antes de redirect() en validaciones fallidas.
     */
    public static function flash(array $data, array $errors = []): void
    {
        $_SESSION[self::KEY_DATA]   = $data;
        $_SESSION[self::KEY_ERRORS] = $errors;
    }

    /**
     * Lee y elimina los datos de sesión.
     * Devuelve [$data, $errors].
     * Llamado por TwigFactory para inyectarlos como globals `old` y `errors`.
     */
    public static function pull(): array
    {
        $data   = $_SESSION[self::KEY_DATA]   ?? [];
        $errors = $_SESSION[self::KEY_ERRORS] ?? [];
        unset($_SESSION[self::KEY_DATA], $_SESSION[self::KEY_ERRORS]);
        return [$data, $errors];
    }
}