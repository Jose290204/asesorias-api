<?php

namespace App\Http\Requests;

use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrearSolicitudRequest extends FormRequest
{
    // El permiso ya lo resuelven los middlewares de la ruta (auth, activo, rol:estudiante)
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asesor_id' => ['required', 'integer'],
            // exists:materias,id y exists:horarios,id: solo validan que EXISTAN en esas tablas.
            'materia_id' => ['required', 'integer', 'exists:materias,id'],
            'horario_id' => ['required', 'integer', 'exists:horarios,id'],
            // De aquí sale el día de la semana (Dia::desdeFecha)
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'modalidad' => ['required', Rule::enum(Modalidad::class)],
            'razon' => ['required', Rule::enum(RazonAsesoria::class)],
            'nota' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'asesor_id.required' => 'Falta elegir un asesor.',
            'materia_id.required' => 'Falta elegir una materia.',
            'horario_id.required' => 'Falta elegir un horario.',
            'fecha.required' => 'Falta elegir una fecha.',
            'fecha.after_or_equal' => 'La fecha no puede ser anterior a hoy.',
            'modalidad.required' => 'Falta elegir una modalidad.',
            'razon.required' => 'Falta elegir una razón.',
            'nota.max' => 'La nota no puede pasar de 500 caracteres.',
        ];
    }
}