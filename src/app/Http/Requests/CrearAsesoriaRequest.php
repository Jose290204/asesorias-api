<?php

namespace App\Http\Requests;

use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrearAsesoriaRequest extends FormRequest
{
    // El permiso lo resuelven los middlewares de la ruta (auth, activo, rol:asesor)
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Sin "exists": si no es un estudiante visible, el service responde 404
            'estudiante_id' => ['required', 'integer'],
            'materia_id' => ['required', 'integer', 'exists:materias,id'],
            'horario_id' => ['required', 'integer', 'exists:horarios,id'],
            // Puede ser pasada: el asesor registra una asesoría que ya empezó
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'modalidad' => ['required', Rule::enum(Modalidad::class)],
            'razon' => ['required', Rule::enum(RazonAsesoria::class)],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'estudiante_id.required' => 'Falta elegir un estudiante.',
            'materia_id.required' => 'Falta elegir una materia.',
            'horario_id.required' => 'Falta elegir un horario.',
            'fecha_inicio.required' => 'Falta la fecha de inicio.',
            'fecha_inicio.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'modalidad.required' => 'Falta elegir una modalidad.',
            'razon.required' => 'Falta elegir una razón.',
            'observaciones.max' => 'Las observaciones no pueden pasar de 1000 caracteres.',
        ];
    }
}