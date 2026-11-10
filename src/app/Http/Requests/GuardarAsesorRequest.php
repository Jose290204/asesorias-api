<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// Alta (POST) y edición (PUT) de un asesor disciplinar. El rol lo exige el middleware de la ruta.
// El número de cuenta es opcional: un disciplinar no siempre tiene.
class GuardarAsesorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->correo)) {
            $this->merge(['correo' => Str::lower(trim($this->correo))]);
        }

        // "" cuenta como "sin cuenta" (varios usuarios sin cuenta no deben chocar en el unique)
        if ($this->numero_cuenta === '') {
            $this->merge(['numero_cuenta' => null]);
        }
    }

    public function rules(): array
    {
        // null al crear; el id del asesor al editar
        $id = $this->route('asesor');

        return [
            'nombre' => ['required', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'apellido_paterno' => ['required', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'apellido_materno' => ['nullable', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'correo' => ['required', 'string', 'email', 'max:150', Rule::unique('usuarios', 'correo')->ignore($id)],
            'numero_cuenta' => ['nullable', 'string', 'max:20', 'regex:/^\d+$/', Rule::unique('usuarios', 'numero_cuenta')->ignore($id)],
            'num_cel' => ['nullable', 'regex:/^\d{10,15}$/'],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:8', 'max:72', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max' => 'El nombre no puede pasar de 60 caracteres.',
            'nombre.regex' => 'El nombre solo puede tener letras.',
            'apellido_paterno.required' => 'El apellido paterno es obligatorio.',
            'apellido_paterno.max' => 'El apellido paterno no puede pasar de 60 caracteres.',
            'apellido_paterno.regex' => 'El apellido paterno solo puede tener letras.',
            'apellido_materno.max' => 'El apellido materno no puede pasar de 60 caracteres.',
            'apellido_materno.regex' => 'El apellido materno solo puede tener letras.',
            'correo.required' => 'El correo es obligatorio.',
            'correo.email' => 'Escribe un correo válido.',
            'correo.max' => 'El correo es demasiado largo.',
            'correo.unique' => 'Ya existe un usuario con ese correo.',
            'numero_cuenta.max' => 'El número de cuenta no puede pasar de 20 dígitos.',
            'numero_cuenta.regex' => 'El número de cuenta solo puede tener dígitos.',
            'numero_cuenta.unique' => 'Ya existe un usuario con ese número de cuenta.',
            'num_cel.regex' => 'El teléfono debe tener entre 10 y 15 dígitos.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max' => 'La contraseña no puede pasar de 72 caracteres.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
        ];
    }
}