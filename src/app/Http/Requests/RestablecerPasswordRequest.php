<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RestablecerPasswordRequest extends FormRequest
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
            'correo'   => ['required', 'string', 'email', 'max:150'],
            'codigo'   => ['required', 'digits:6'],
            // "confirmed" exige un campo password_confirmation igual.
            // max:72 porque bcrypt ignora lo que pase de 72 bytes
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.min'       => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }
}