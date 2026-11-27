<?php

namespace App\Console\Commands;

use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Models\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CrearSuperAdmin extends Command
{
    protected $signature = 'superadmin:crear {--facultad=1 : Facultad de origen del super admin}';

    protected $description = 'Crea un super admin pidiendo los datos por consola (nada queda guardado en el código)';

    public function handle(): int
    {
        $datos = [
            'nombre' => trim((string) $this->ask('Nombre')),
            'apellido_paterno' => trim((string) $this->ask('Apellido paterno')),
            'apellido_materno' => trim((string) $this->ask('Apellido materno (opcional)')) ?: null,
            'correo' => strtolower(trim((string) $this->ask('Correo'))),
        ];

        // La contraseña se escribe oculta y no queda en el historial de la terminal
        $password = (string) $this->secret('Contraseña (mínimo 8 caracteres)');
        $confirmacion = (string) $this->secret('Repite la contraseña');

        $validador = Validator::make(
            $datos + ['password' => $password, 'password_confirmation' => $confirmacion],
            [
                'nombre' => ['required', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
                'apellido_paterno' => ['required', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
                'apellido_materno' => ['nullable', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
                'correo' => ['required', 'email', 'max:255', 'unique:usuarios,correo'],
                'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
            ]
        );

        if ($validador->fails()) {
            foreach ($validador->errors()->all() as $mensaje) {
                $this->error($mensaje);
            }

            return self::FAILURE;
        }

        $usuario = DB::transaction(function () use ($datos, $password) {
            $usuario = Usuario::create($datos + [
                'password' => $password,               // el cast "hashed" la encripta
                'facultad_id' => (int) $this->option('facultad'),
                'estatus' => EstatusUsuario::Activo,
            ]);

            $usuario->asignarRol(Rol::SuperAdmin);

            return $usuario;
        });

        $this->info("Super admin creado: {$usuario->correo}");

        return self::SUCCESS;
    }
}