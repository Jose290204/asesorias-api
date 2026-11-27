<?php

namespace App\Http\Requests;

use App\Enums\AccionBitacora;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListarBitacoraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'facultad_id' => ['nullable', 'integer'],
            'accion' => ['nullable', Rule::in(AccionBitacora::valores())],
            'buscar' => ['nullable', 'string', 'max:100'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'accion.in' => 'La acción no es válida.',
            'desde.date_format' => 'La fecha "desde" debe tener el formato AAAA-MM-DD.',
            'hasta.date_format' => 'La fecha "hasta" debe tener el formato AAAA-MM-DD.',
            'hasta.after_or_equal' => 'La fecha "hasta" no puede ser anterior a "desde".',
            'per_page.in' => 'Elige 10, 25 o 50 registros por página.',
        ];
    }
}