<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// POST /admin/asesores-par
class AsignarAsesorParRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Que sea un estudiante activo de la facultad lo resuelve el service (404)
        return ['usuario_id' => ['required', 'integer']];
    }

    public function messages(): array
    {
        return ['usuario_id.required' => 'Elige un estudiante.'];
    }
}