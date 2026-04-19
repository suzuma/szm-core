<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\FormRequest;

/**
 * UpdateUserRequest — valida la edición de un usuario existente.
 *
 * La contraseña es opcional; si se envía vacía no se actualiza.
 */
class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'    => ['required', 'max:150'],
            'email'   => ['required', 'email', 'max:254'],
            'role_id' => ['required', 'numeric'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'    => 'El nombre es requerido.',
            'name.max'         => 'El nombre no puede superar 150 caracteres.',
            'email.required'   => 'El correo es requerido.',
            'email.email'      => 'El formato del correo no es válido.',
            'email.max'        => 'El correo no puede superar 254 caracteres.',
            'role_id.required' => 'Selecciona un rol.',
            'role_id.numeric'  => 'El rol no es válido.',
        ];
    }
}