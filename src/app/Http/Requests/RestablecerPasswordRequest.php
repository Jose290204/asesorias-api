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
            'correo.required'    => 'Escribe tu correo.',
            'correo.email'       => 'Escribe un correo válido.',
            'correo.max'         => 'El correo es demasiado largo.',
            'codigo.required'    => 'Escribe el código.',
            'codigo.digits'      => 'El código tiene 6 dígitos.',
            'password.required'  => 'Escribe la nueva contraseña.',
            'password.min'       => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max'       => 'La contraseña es demasiado larga (máximo 72 caracteres).',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }
}