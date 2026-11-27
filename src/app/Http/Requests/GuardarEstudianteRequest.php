<?php

namespace App\Http\Requests;

use App\Support\ContextoFacultad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// Alta (POST) y edición (PUT) de un estudiante desde el panel del admin.
// El rol (admin o super admin) ya lo exige el middleware de la ruta.
class GuardarEstudianteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // Mismo criterio que el login: "  Ana@FIC.test " y "ana@fic.test" son el mismo correo
    protected function prepareForValidation(): void
    {
        if (is_string($this->correo)) {
            $this->merge(['correo' => Str::lower(trim($this->correo))]);
        }
    }

    public function rules(): array
    {
        // null al crear; el id del estudiante al editar (la ruta lo trae como número)
        $id = $this->route('estudiante');
        $facultad = app(ContextoFacultad::class)->id();

        return [
            'nombre' => ['required', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'apellido_paterno' => ['required', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'apellido_materno' => ['nullable', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
            // unique cuenta también a los dados de baja y a los eliminados: su correo queda reservado
            'correo' => ['required', 'string', 'email', 'max:150', Rule::unique('usuarios', 'correo')->ignore($id)],
            'numero_cuenta' => ['required', 'string', 'max:20', 'regex:/^\d+$/', Rule::unique('usuarios', 'numero_cuenta')->ignore($id)],
            'num_cel' => ['nullable', 'regex:/^\d{10,15}$/'],
            // Solo grupos de las licenciaturas de la facultad en contexto
            'grupo_id' => [
                'required',
                'integer',
                Rule::exists('grupos', 'id')->where(
                    fn ($q) => $q->whereIn(
                        'licenciatura_id',
                        DB::table('licenciaturas')->where('facultad_id', $facultad)->select('id')
                    )
                ),
            ],
            // Al crear es obligatoria; al editar solo si se quiere cambiar (por si la olvidó).
            // "confirmed" exige password_confirmation igual. max:72: bcrypt ignora el resto
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
            'numero_cuenta.required' => 'El número de cuenta es obligatorio.',
            'numero_cuenta.max' => 'El número de cuenta no puede pasar de 20 dígitos.',
            'numero_cuenta.regex' => 'El número de cuenta solo puede tener dígitos.',
            'numero_cuenta.unique' => 'Ya existe un usuario con ese número de cuenta.',
            'num_cel.regex' => 'El teléfono debe tener entre 10 y 15 dígitos.',
            'grupo_id.required' => 'Elige un grupo.',
            'grupo_id.exists' => 'El grupo no existe en esta facultad.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max' => 'La contraseña no puede pasar de 72 caracteres.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
        ];
    }
}