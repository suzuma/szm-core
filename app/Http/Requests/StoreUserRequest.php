<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\FormRequest;

/**
 * StoreUserRequest — valida la creación de un nuevo usuario.
 */
class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'     => ['required', 'max:150'],
            'email'    => ['required', 'email', 'max:254'],
            'role_id'  => ['required', 'numeric'],
            'password' => ['required', 'min:8', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'     => 'El nombre es requerido.',
            'name.max'          => 'El nombre no puede superar 150 caracteres.',
            'email.required'    => 'El correo es requerido.',
            'email.email'       => 'El formato del correo no es válido.',
            'email.max'         => 'El correo no puede superar 254 caracteres.',
            'role_id.required'  => 'Selecciona un rol.',
            'role_id.numeric'   => 'El rol no es válido.',
            'password.required' => 'La contraseña es requerida.',
            'password.min'      => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max'      => 'La contraseña no puede superar 128 caracteres.',
        ];
    }
}