<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListarAdministradoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'facultad_id' => ['nullable', 'integer'],
            'estatus' => ['nullable', 'in:pendiente,activo,inactivo'],
            'buscar' => ['nullable', 'string', 'max:100'],
        ];
    }
}