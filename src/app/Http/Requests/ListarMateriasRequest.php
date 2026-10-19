<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListarMateriasRequest extends FormRequest
{
    // La autorización ya la resuelven los middlewares de la ruta
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Texto opcional para buscar por nombre de materia
            'buscar' => ['nullable', 'string', 'max:100'],
        ];
    }
}