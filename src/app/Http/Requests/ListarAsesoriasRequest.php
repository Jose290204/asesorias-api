<?php

namespace App\Http\Requests;

use App\Enums\EstatusAsesoria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListarAsesoriasRequest extends FormRequest
{
    // No hay un registro ajeno que proteger: la lista siempre se filtra por el usuario autenticado
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Un valor del enum (en_curso, completada, cancelada) o el atajo "historial"
            'estatus' => [
                'sometimes',
                Rule::in([...array_column(EstatusAsesoria::cases(), 'value'), 'historial']),
            ],
            // Texto libre para buscar por materia o por la otra persona de la asesoría
            'buscar' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    // Mensajes en español (el predeterminado sale en inglés)
    public function messages(): array
    {
        return [
            'estatus.in' => 'El estatus no es válido.',
        ];
    }
}