<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanEstudiosSeeder extends Seeder
{
    public function run(): void
    {
        $ahora = now();

        $nombres = [1 => '2011', 2 => '2023'];

        $filas = [];
        foreach ($nombres as $id => $nombre) {
            $filas[] = [
                'id' => $id,
                'facultad_id' => 1,
                'nombre' => $nombre,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        DB::table('plan_estudios')->upsert($filas, ['id'], ['facultad_id', 'nombre', 'updated_at']);
    }
}