<?php

namespace App\Http\Requests;

use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EditarAsesoriaRequest extends FormRequest
{
    // El permiso va aquí para que un asesor ajeno reciba 403
    public function authorize(): bool
    {
        return $this->user()->can('gestionar', $this->route('asesoria'));
    }

    // Todos son opcionales ("sometimes"), pero si llegan no pueden ir en null,
    // salvo observaciones, donde null significa "borrar".
    // Solo estos campos se pueden editar: lo demás que mande el cliente se ignora.
    public function rules(): array
    {
        return [
            'fecha_inicio' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'horario_id' => ['sometimes', 'required', 'integer', 'exists:horarios,id'],
            'modalidad' => ['sometimes', 'required', Rule::enum(Modalidad::class)],
            'razon' => ['sometimes', 'required', Rule::enum(RazonAsesoria::class)],
            'observaciones' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    // Al menos uno de los cinco campos debe venir en el body.
    // hasAny cuenta la llave aunque su valor sea null (observaciones: null es válido).
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->hasAny(['fecha_inicio', 'horario_id', 'modalidad', 'razon', 'observaciones'])) {
                    $validator->errors()->add('fecha_inicio', 'Manda al menos un dato para editar.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'fecha_inicio.required' => 'La fecha de inicio no puede ir vacía.',
            'fecha_inicio.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'horario_id.required' => 'El horario no puede ir vacío.',
            'horario_id.exists' => 'El horario elegido no existe.',
            'modalidad.required' => 'La modalidad no puede ir vacía.',
            'razon.required' => 'La razón no puede ir vacía.',
            'observaciones.max' => 'Las observaciones no pueden pasar de 1000 caracteres.',
        ];
    }
}