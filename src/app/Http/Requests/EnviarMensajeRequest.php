<?php

namespace App\Http\Requests;

use App\Models\Chat;
use Illuminate\Foundation\Http\FormRequest;

class EnviarMensajeRequest extends FormRequest
{
    // El permiso va aquí para que el 403 salga ANTES que un 422.
    // [Chat::class, asesoria] le dice a Laravel que use ChatPolicy::escribir($usuario, $asesoria).
    public function authorize(): bool
    {
        return $this->user()->can('escribir', [Chat::class, $this->route('asesoria')]);
    }

    public function rules(): array
    {
        return [
            // Laravel ya recorta los espacios de los extremos y convierte "" en null,
            // así que un mensaje de puros espacios falla con "required".
            'mensaje' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'mensaje.required' => 'Escribe un mensaje.',
            'mensaje.string' => 'El mensaje debe ser texto.',
            'mensaje.max' => 'El mensaje puede tener máximo 1000 caracteres.',
        ];
    }
}