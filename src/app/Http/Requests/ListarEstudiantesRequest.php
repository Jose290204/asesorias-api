<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListarEstudiantesRequest extends FormRequest
{
    
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'buscar' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'buscar.max' => 'La búsqueda no puede pasar de 100 caracteres.',
        ];
    }
}