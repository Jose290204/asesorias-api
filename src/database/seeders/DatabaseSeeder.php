<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Solo catálogos. Usuarios (super admins, admins, asesores y estudiantes)
        // se crean desde el sistema o con el comando "superadmin:crear".
        // El orden importa: cada seeder depende de los anteriores
        $this->call([
            FacultadSeeder::class,        // primero: todo apunta a facultad_id = 1
            LicenciaturaSeeder::class,
            HorarioSeeder::class,
            CicloEscolarSeeder::class,
            GrupoSeeder::class,           // necesita database/seeders/data/grupos.csv
            MateriaSeeder::class,         // necesita database/seeders/data/materias.csv
        ]);
    }
}