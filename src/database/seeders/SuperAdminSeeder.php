<?php

namespace Database\Seeders;

use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    // Facultad FIC (la crea FacultadSeeder con id = 1). Es la "facultad de origen" del super admin.
    private const FACULTAD_ID = 1;

    public function run(): void
    {
        // En local usa "password". Fuera de local la contraseña DEBE venir del .env;
        // si no, se omite el seeder para no crear un super admin con una clave conocida.
        $password = env('SUPERADMIN_SEED_PASSWORD', app()->environment('local') ? 'password' : null);

        if ($password === null) {
            $this->command?->warn('SuperAdminSeeder omitido: define SUPERADMIN_SEED_PASSWORD en el .env.');

            return;
        }

        $datos = [
            'correo' => 'superadmin@fic.test',          // cambia por el correo real
            'nombre' => 'Super',
            'apellido_paterno' => 'Admin',
            'apellido_materno' => 'UAS',
            'num_cel' => '6671000100',
            'facultad_id' => self::FACULTAD_ID,
            'estatus' => EstatusUsuario::Activo,
            'password' => $password,                    // el cast "hashed" la encripta
            'numero_cuenta' => null,
            'grupo_id' => null,
            'promedio' => null,
        ];

        // updateOrCreate: repetir el seeder no duplica el usuario (la llave es el correo)
        $usuario = Usuario::updateOrCreate(['correo' => $datos['correo']], $datos);

        // asignarRol usa firstOrCreate: tampoco duplica el rol
        $usuario->asignarRol(Rol::SuperAdmin);
    }
}