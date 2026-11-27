<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CambiarEstadoFacultadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'activa' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'activa.required' => 'Falta indicar si la facultad queda activa o inactiva.',
            'activa.boolean' => 'El estado debe ser verdadero o falso.',
        ];
    }
}