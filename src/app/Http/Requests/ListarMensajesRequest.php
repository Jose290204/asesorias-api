<?php

namespace App\Http\Requests;

use App\Models\Chat;
use Illuminate\Foundation\Http\FormRequest;

class ListarMensajesRequest extends FormRequest
{
    // Leer el chat: los participantes .
    public function authorize(): bool
    {
        return $this->user()->can('leer', [Chat::class, $this->route('asesoria')]);
    }

    public function rules(): array
    {
        return [
            // Id del último mensaje que el front ya tiene. Con él, cada consulta trae solo lo nuevo.
            // Sin él, trae la conversación completa.
            'despues_de' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'despues_de.integer' => 'El parámetro despues_de debe ser un número.',
            'despues_de.min' => 'El parámetro despues_de no puede ser negativo.',
        ];
    }
}