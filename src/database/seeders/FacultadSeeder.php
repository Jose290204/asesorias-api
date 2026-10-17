<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FacultadSeeder extends Seeder
{
    public function run(): void
    {
        $ahora = now();

        // upsert: inserta si no existe, actualiza si ya existe (por id)
        DB::table('facultades')->upsert([
            [
                'id' => 1,                       // los demás seeders usan facultad_id = 1
                'nombre' => 'Facultad de Informática Culiacán',
                'siglas' => 'FIC',
                'logo' => null,
                'activa' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
        ], ['id'], ['nombre', 'siglas', 'logo', 'activa', 'updated_at']);
    }
}