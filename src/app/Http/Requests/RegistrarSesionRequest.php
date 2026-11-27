<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegistrarSesionRequest extends FormRequest
{
    // El permiso va aquí (y no en el controlador) para que un asesor ajeno reciba 403
    // antes que cualquier 422. Solo el asesor asignado registra sesiones (AsesoriaPolicy::gestionar)
    public function authorize(): bool
    {
        return $this->user()->can('gestionar', $this->route('asesoria'));
    }

    // Aquí solo va la validación de forma. Lo que depende de la asesoría
    // (que siga en curso, la fecha de inicio, el duplicado) se revisa en el service,
    // dentro de la transacción y con la fila bloqueada.
    public function rules(): array
    {
        return [
            // Una sesión ya ocurrió: no puede ser futura (hoy sí vale)
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'horario_id' => ['required', 'integer', 'exists:horarios,id'],
            'tema' => ['nullable', 'string', 'max:150'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha.required' => 'Falta la fecha de la sesión.',
            'fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'fecha.before_or_equal' => 'La fecha de la sesión no puede ser futura.',
            'horario_id.required' => 'Falta elegir un horario.',
            'horario_id.exists' => 'El horario elegido no existe.',
            'tema.max' => 'El tema no puede pasar de 150 caracteres.',
            'notas.max' => 'Las notas no pueden pasar de 2000 caracteres.',
        ];
    }
}