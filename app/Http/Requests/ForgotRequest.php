<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\FormRequest;

/**
 * ForgotRequest — valida el formulario de recuperación de contraseña.
 */
final class ForgotRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:254'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'El correo electrónico es requerido.',
            'email.email'    => 'Ingresa un correo electrónico válido.',
            'email.max'      => 'El correo no puede superar los 254 caracteres.',
        ];
    }
}