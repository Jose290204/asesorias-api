<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GuardarAdministradorRequest extends FormRequest
{
    // El rol (super admin) ya lo exige el middleware de la ruta
    public function authorize(): bool
    {
        return true;
    }

    // Mismo criterio que el login: "  Admin@FIC.test " y "admin@fic.test" son el mismo correo
    protected function prepareForValidation(): void
    {
        if (is_string($this->correo)) {
            $this->merge(['correo' => Str::lower(trim($this->correo))]);
        }
    }

    public function rules(): array
    {
        // null al crear; el administrador de la ruta al editar
        $administrador = $this->route('administrador');

        return [
            // La facultad debe existir y estar activa. Al editar se acepta además la actual,
            // para poder corregir los datos de un administrador cuya facultad esté desactivada
            'facultad_id' => [
                'required',
                'integer',
                Rule::exists('facultades', 'id')->where(function ($q) use ($administrador) {
                    $q->where(function ($g) use ($administrador) {
                        $g->where('activa', true);
                        if ($administrador) {
                            $g->orWhere('id', $administrador->facultad_id);
                        }
                    });
                }),
            ],
           'nombre' => ['required', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'apellido_paterno' => ['required', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'apellido_materno' => ['nullable', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
            // unique cuenta también a los eliminados: su correo queda reservado
            'correo' => ['required', 'string', 'email', 'max:150', Rule::unique('usuarios', 'correo')->ignore($administrador)],
            // Al crear es obligatoria; al editar solo si se quiere cambiar. max:72: bcrypt ignora el resto
            'password' => [$administrador ? 'nullable' : 'required', 'string', 'min:8', 'max:72'],
        ];
    }

    public function messages(): array
    {
        return [
            'facultad_id.required' => 'Elige una facultad.',
            'facultad_id.exists' => 'La facultad no existe o está inactiva.',
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max' => 'El nombre no puede pasar de 60 caracteres.',
            'apellido_paterno.required' => 'El apellido paterno es obligatorio.',
            'apellido_paterno.max' => 'El apellido paterno no puede pasar de 60 caracteres.',
            'apellido_materno.max' => 'El apellido materno no puede pasar de 60 caracteres.',
            'correo.required' => 'El correo es obligatorio.',
            'correo.email' => 'Escribe un correo válido.',
            'correo.max' => 'El correo es demasiado largo.',
            'correo.unique' => 'Ya existe un usuario con ese correo.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max' => 'La contraseña no puede pasar de 72 caracteres.',
             'nombre.regex' => 'El nombre solo puede tener letras.',
            'apellido_paterno.regex' => 'El apellido paterno solo puede tener letras.',
            'apellido_materno.regex' => 'El apellido materno solo puede tener letras.',
        ];
    }
}