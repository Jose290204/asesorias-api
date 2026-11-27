<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EditarSolicitudRequest extends FormRequest
{
    // El permiso se revisa AQUÍ (antes que rules): otro estudiante recibe 403 y no 422.
    // $this->route('solicitud') ya es el modelo: el route model binding corre antes.
    public function authorize(): bool
    {
        return $this->user()->can('editar', $this->route('solicitud'));
    }

    public function rules(): array
    {
        return [
            // 'sometimes': si la clave no viene, no se valida ni se toca (es un PATCH)
            'fecha' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'horario_id' => ['sometimes', 'required', 'integer', 'exists:horarios,id'],
            // nullable: mandar "nota": null borra la nota
            'nota' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    // Tiene que venir al menos uno de los tres. hasAny() mira si la CLAVE existe,
    // aunque su valor sea null (así se puede borrar la nota sin mandar nada más)
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->hasAny(['fecha', 'horario_id', 'nota'])) {
                    $validator->errors()->add('fecha', 'Envía al menos un dato para editar: fecha, horario_id o nota.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'fecha.after_or_equal' => 'La fecha no puede ser anterior a hoy.',
            'horario_id.exists' => 'Ese horario no existe.',
            'nota.max' => 'La nota no puede pasar de 500 caracteres.',
        ];
    }
}