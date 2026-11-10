<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// GET /admin/asesores-disciplinares y /admin/asesores-par
class ListarAsesoresAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Nombre, correo o número de cuenta
            'buscar' => ['nullable', 'string', 'max:100'],
            'materia_id' => ['nullable', 'integer'],
        ];
    }
}