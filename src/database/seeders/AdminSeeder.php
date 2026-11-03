<?php

namespace Database\Seeders;

use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    // Facultad FIC (la crea FacultadSeeder con id = 1)
    private const FACULTAD_ID = 1;

    public function run(): void
    {
        // En local usa "password". Fuera de local la contraseña DEBE venir del .env;
        // si no, se omite el seeder para no crear admins con una clave conocida.
        $password = env('ADMIN_SEED_PASSWORD', app()->environment('local') ? 'password' : null);

        if ($password === null) {
            $this->command?->warn('AdminSeeder omitido: define ADMIN_SEED_PASSWORD en el .env.');

            return;
        }

        $admins = [
            [
                'correo' => 'admin1@fic.test',          // cambia por el correo real
                'nombre' => 'Admin',
                'apellido_paterno' => 'Uno',
                'apellido_materno' => 'FIC',
                'num_cel' => '6671000101',
            ],
            [
                'correo' => 'admin2@fic.test',          // cambia por el correo real
                'nombre' => 'Admin',
                'apellido_paterno' => 'Dos',
                'apellido_materno' => 'FIC',
                'num_cel' => '6671000102',
            ],
        ];

        foreach ($admins as $datos) {
            // Un admin de facultad no es estudiante: sin grupo, cuenta ni promedio.
            // Lleva facultad_id para que FacultadScope le muestre los datos de su facultad.
            $datos += [
                'facultad_id' => self::FACULTAD_ID,
                'estatus' => EstatusUsuario::Activo,
                'password' => $password,                // el cast "hashed" la encripta
                'numero_cuenta' => null,
                'grupo_id' => null,
                'promedio' => null,
            ];

            // updateOrCreate: repetir el seeder no duplica usuarios (la llave es el correo)
            $usuario = Usuario::updateOrCreate(['correo' => $datos['correo']], $datos);

            // asignarRol usa firstOrCreate: tampoco duplica el rol
            $usuario->asignarRol(Rol::Admin);
        }
    }
}