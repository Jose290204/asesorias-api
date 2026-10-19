<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SolicitarCodigoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ruta pública
    }

    // Normaliza antes de validar: sin espacios y en minúsculas
    protected function prepareForValidation(): void
    {
        $this->merge(['correo' => mb_strtolower(trim((string) $this->input('correo')))]);
    }

    public function rules(): array
    {
        return [
            'correo' => ['required', 'string', 'email', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'correo.required' => 'Escribe tu correo.',
            'correo.email'    => 'Escribe un correo válido.',
        ];
    }
}