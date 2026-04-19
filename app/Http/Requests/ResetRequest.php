<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\FormRequest;

/**
 * ResetRequest — valida el formulario de nueva contraseña.
 *
 * Regla 'hextoken' verifica que el token sea hex de 64 chars (SHA-256 en texto plano).
 * Regla 'min:8' / 'max:128' impone longitud segura de contraseña.
 */
final class ResetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'token'    => ['required', 'hextoken'],
            'password' => ['required', 'min:8', 'max:128'],
            'confirm'  => ['required', 'min:8'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required'    => 'El token de recuperación es requerido.',
            'token.hextoken'    => 'El enlace de recuperación no es válido.',
            'password.required' => 'La nueva contraseña es requerida.',
            'password.min'      => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max'      => 'La contraseña no puede superar los 128 caracteres.',
            'confirm.required'  => 'Confirma tu nueva contraseña.',
            'confirm.min'       => 'La contraseña debe tener al menos 8 caracteres.',
        ];
    }
}