<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HorarioSeeder extends Seeder
{
    public function run(): void
    {
        $ahora = now();

        $etiquetas = [
            1 => '7:00-8:00 AM',
            2 => '8:00-9:00 AM',
            3 => '9:00-10:00 AM',
            4 => '10:00-11:00 AM',
            5 => '11:00-12:00 PM',
            6 => '12:00-1:00 PM',
            7 => '1:00-2:00 PM',
            8 => '2:00-3:00 PM',
            9 => '3:00-4:00 PM',
            10 => '4:00-5:00 PM',
            11 => '5:00-6:00 PM',
            12 => '6:00-7:00 PM',
            13 => '7:00-8:00 PM',
            14 => '8:00-9:00 PM',
            15 => '9:00-10:00 PM',
            16 => '10:00-11:00 PM',
            17 => '11:00-12:00 AM',
        ];

        $filas = [];
        foreach ($etiquetas as $id => $etiqueta) {
            $filas[] = [
                'id' => $id,
                'etiqueta' => $etiqueta,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        DB::table('horarios')->upsert($filas, ['id'], ['etiqueta', 'updated_at']);
    }
}