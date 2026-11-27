<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;


class LoginRequest extends FormRequest
{
    // En el login cualquiera puede intentar esta peticion, así que siempre true.
    public function authorize(): bool
    {
        return true;
    }

    // Se ejecuta ANTES de validar. Sirve para limpiar los datos de entrada.
    protected function prepareForValidation(): void
    {
        if (is_string($this->correo)) {
            // "  Alumno@FIC.test " y "alumno@fic.test" pasan a ser el mismo correo
            $this->merge(['correo' => Str::lower(trim($this->correo))]);
        }
    }

    // Las reglas: qué datos se esperan y cómo deben venir
    public function rules(): array
    {
        return [
            'correo' => ['required', 'email', 'max:150'],     // obligatorio, con formato de correo, máx. 150
            'password' => ['required', 'string'],             // obligatoria; no se limita el largo para no revelar la regla
            // Opcional. Si viene, quien llama es la app móvil y se devuelve un token;
            // si no viene, es la web y se abre una sesión con cookie
            'dispositivo' => ['nullable', 'string', 'max:100'],
        ];
    }

    // Los mensajes que el front imprime tal cual. Sin esto, Laravel los da en inglés.
    // La clave es "campo.regla".
    public function messages(): array
    {
        return [
            'correo.required' => 'Escribe tu correo.',
            'correo.email' => 'Escribe un correo válido.',
            'correo.max' => 'El correo es demasiado largo.',
            'password.required' => 'Escribe tu contraseña.',
        ];
    }
}