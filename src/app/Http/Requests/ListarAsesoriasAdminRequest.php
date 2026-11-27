<?php

namespace App\Http\Requests;

use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use Illuminate\Validation\Rule;

// Mismos filtros que la lista del asesor y del estudiante (estatus, buscar),
// más los que solo tienen sentido para quien ve toda la facultad.
class ListarAsesoriasAdminRequest extends ListarAsesoriasRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'asesor_id' => ['sometimes', 'integer'],
            'materia_id' => ['sometimes', 'integer'],
            'ciclo_id' => ['sometimes', 'integer'],
            'licenciatura_id' => ['sometimes', 'integer'],
            'grupo_id' => ['sometimes', 'integer'],
            'horario_id' => ['sometimes', 'integer'],
            'modalidad' => ['sometimes', Rule::enum(Modalidad::class)],
            'razon' => ['sometimes', Rule::enum(RazonAsesoria::class)],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'asesor_id.integer' => 'El asesor no es válido.',
            'materia_id.integer' => 'La materia no es válida.',
            'ciclo_id.integer' => 'El ciclo escolar no es válido.',
            'licenciatura_id.integer' => 'La licenciatura no es válida.',
            'grupo_id.integer' => 'El grupo no es válido.',
            'horario_id.integer' => 'El horario no es válido.',
            'modalidad.enum' => 'La modalidad no es válida.',
            'razon.enum' => 'La razón no es válida.',
        ]);
    }
}