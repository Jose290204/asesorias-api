<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerificarCodigoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['correo' => mb_strtolower(trim((string) $this->input('correo')))]);
    }

    public function rules(): array
    {
        return [
            'correo' => ['required', 'string', 'email', 'max:150'],
            // Exactamente 6 dígitos
            'codigo' => ['required', 'digits:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'Escribe el código.',
            'codigo.digits'   => 'El código tiene 6 dígitos.',
        ];
    }
}