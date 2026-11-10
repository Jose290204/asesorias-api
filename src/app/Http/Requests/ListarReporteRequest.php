<?php

namespace App\Http\Requests;

use App\Support\ContextoFacultad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListarReporteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // El ciclo tiene que ser de la facultad en contexto: un ciclo de otra facultad es 422, no datos ajenos
            'ciclo_id' => [
                'required',
                'integer',
                Rule::exists('ciclos_escolares', 'id')->where('facultad_id', app(ContextoFacultad::class)->id()),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'ciclo_id.required' => 'Elige un semestre.',
            'ciclo_id.integer' => 'El semestre no es válido.',
            'ciclo_id.exists' => 'El semestre no existe en esta facultad.',
        ];
    }
}