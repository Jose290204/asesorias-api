<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CambiarEstadoAdministradorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // Solo se activa o desactiva; "pendiente" no se puede asignar a mano
    public function rules(): array
    {
        return [
            'estatus' => ['required', 'in:activo,inactivo'],
        ];
    }

    public function messages(): array
    {
        return [
            'estatus.required' => 'Falta indicar el estado.',
            'estatus.in' => 'El estado debe ser activo o inactivo.',
        ];
    }
}