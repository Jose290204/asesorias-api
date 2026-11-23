<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Carga masiva de asesores disciplinares: el front manda las filas ya leídas del Excel.
// El rol (admin o super admin) lo exige el middleware de la ruta.
class CargarAsesoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'confirmar' => ['sometimes', 'boolean'],   // false = solo validar; true = crear
            'filas' => ['required', 'array', 'min:1', 'max:1000'],
            'filas.*' => ['array'],
        ];
    }
}
