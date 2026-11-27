<?php

namespace App\Http\Requests;

use App\Enums\Modalidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListarAsesoresRequest extends FormRequest
{
    // La autorización ya la resuelven los middlewares de la ruta (auth, activo, rol:estudiante)
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Texto para buscar por nombre o apellidos del asesor
            'buscar' => ['nullable', 'string', 'max:100'],
            // Id de la materia; el filtro solo deja asesores que la dan
            'materia_id' => ['nullable', 'integer'],
            // "presencial" o "virtual"; Rule::enum rechaza cualquier otro texto (422)
            'modalidad' => ['nullable', Rule::enum(Modalidad::class)],
            // Fecha que quiere el estudiante (AAAA-MM-DD). De aquí se saca el día de la semana
            'fecha' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            // Id del horario (el mismo que entrega GET /catalogos); debe existir
            'horario_id' => ['nullable', 'integer', 'exists:horarios,id'],
        ];
    }
}