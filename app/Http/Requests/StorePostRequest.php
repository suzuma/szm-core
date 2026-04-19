<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\FormRequest;

/**
 * StorePostRequest — valida la creación de un nuevo post.
 */
class StorePostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title'       => ['required', 'max:255'],
            'category_id' => ['numeric'],
            'status'      => ['required'],
            'content'     => ['required'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'    => 'El título es obligatorio.',
            'title.max'         => 'El título no puede superar 255 caracteres.',
            'status.required'   => 'Selecciona un estado.',
            'content.required'  => 'El contenido no puede estar vacío.',
        ];
    }
}