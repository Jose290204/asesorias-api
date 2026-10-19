<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LicenciaturaSeeder extends Seeder
{
    public function run(): void
    {
        $ahora = now();

        // La Informática virtual no es una licenciatura aparte: comparte materias
        // con la presencial y se distingue por la modalidad del grupo
        $nombres = [
            1 => 'LICENCIATURA EN INFORMATICA',
            3 => 'LICENCIATURA EN INGENIERIA EN CIENCIA DE DATOS',
            4 => 'LICENCIATURA EN INGENIERÍA EN TELECOMUNICACIONES, SISTEMAS Y ELECTRÓNICA',
        ];

        $filas = [];
        foreach ($nombres as $id => $nombre) {
            $filas[] = [
                'id' => $id,
                'facultad_id' => 1,              // en consola no hay usuario: va explícito
                'nombre' => $nombre,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        DB::table('licenciaturas')->upsert($filas, ['id'], ['facultad_id', 'nombre', 'updated_at']);
    }
}