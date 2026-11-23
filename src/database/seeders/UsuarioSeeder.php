<?php

namespace Database\Seeders;

use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

class UsuarioSeeder extends Seeder
{
    // Facultad FIC (la crea FacultadSeeder con id = 1)
    private const FACULTAD_ID = 1;

    public function run(): void
    {
        // Contraseñas conocidas solo en local: nunca en producción
        if (! app()->environment('local')) {
            $this->command?->warn('UsuarioSeeder omitido: solo corre en entorno local.');

            return;
        }

        // Los estudiantes reales entran por la carga masiva (padrón en Excel),
        // aquí solo quedan los asesores de prueba.
        $usuarios = [
            // Asesor disciplinar: solo asesor, sin grupo ni número de cuenta
            [
                'correo' => 'asesor@fic.test',
                'nombre' => 'Marco Antonio',
                'apellido_paterno' => 'Valdez',
                'apellido_materno' => 'Ríos',
                'numero_cuenta' => null,
                'num_cel' => '6671000001',
                'grupo_id' => null,
                'promedio' => null,
                'estatus' => EstatusUsuario::Activo,
                'roles' => [Rol::Asesor],
            ],
            // Asesor par: estudiante + asesor (para probar el selector de rol)
            [
                'correo' => 'asesor.par@fic.test',
                'nombre' => 'Daniela',
                'apellido_paterno' => 'Soto',
                'apellido_materno' => 'Lara',
                'numero_cuenta' => '20220001',
                'num_cel' => '6671000002',
                'grupo_id' => 5,                       // 5-1
                'promedio' => 9.40,
                'estatus' => EstatusUsuario::Activo,
                'roles' => [Rol::Estudiante, Rol::Asesor],
            ],
        ];

        foreach ($usuarios as $datos) {
            $roles = $datos['roles'];
            unset($datos['roles']);

            // Todo usuario que no sea super admin debe tener facultad. Si no la tuviera,
            // FacultadScope filtraría "facultad_id = NULL" y vería listas vacías.
            $datos['facultad_id'] = self::FACULTAD_ID;

            // El pendiente no tiene contraseña: la crea al activar la cuenta con el código.
            // Los demás usan "password" (el cast "hashed" del modelo la encripta).
            $datos['password'] = $datos['estatus'] === EstatusUsuario::Pendiente ? null : 'password';

            // updateOrCreate: repetir el seeder no duplica usuarios (la llave es el correo)
            $usuario = Usuario::updateOrCreate(['correo' => $datos['correo']], $datos);

            foreach ($roles as $rol) {
                // asignarRol usa firstOrCreate: tampoco duplica roles
                $usuario->asignarRol($rol);
            }
        }
    }
}