<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\FormRequest;

/**
 * LoginRequest — valida el formulario de inicio de sesión.
 */
final class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email'    => ['required', 'email', 'max:254'],
            'password' => ['required', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'    => 'El correo electrónico es requerido.',
            'email.email'       => 'Ingresa un correo electrónico válido.',
            'email.max'         => 'El correo no puede superar los 254 caracteres.',
            'password.required' => 'La contraseña es requerida.',
            'password.max'      => 'La contraseña no puede superar los 128 caracteres.',
        ];
    }
}