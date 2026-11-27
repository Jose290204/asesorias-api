<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CicloEscolarSeeder extends Seeder
{
    // Fechas del Calendario Escolar UAS 2026-2027 (Acuerdo 622 del H. Consejo Universitario).
    // fecha_inicio = inicio de semestre (la primera quincena es de actualización docente;
    // las clases arrancan el 24 de agosto). Confirma el fin del semestre 2 con el PDF oficial.
    public function run(): void
    {
        $ahora = now();
        $hoy = $ahora->toDateString();

        // [nombre, periodo, inicio, fin]
        $ciclos = [
            ['2026-2027 1', 1, '2026-08-10', '2027-01-15'],
            ['2026-2027 2', 2, '2027-01-25', '2027-06-11'],
            // El 2027-2028 se agrega cuando el Consejo Universitario apruebe
            // su calendario (normalmente en julio).
        ];

        // Vigente = el último ciclo que ya empezó: en vacaciones no queda hueco
        $vigente = null;
        foreach ($ciclos as [$nombre, , $inicio]) {
            if ($inicio <= $hoy) {
                $vigente = $nombre;
            }
        }

        $filas = [];
        foreach ($ciclos as [$nombre, $periodo, $inicio, $fin]) {
            $filas[] = [
                'facultad_id' => 1,
                'nombre' => $nombre,
                'periodo' => $periodo,
                'fecha_inicio' => $inicio,
                'fecha_fin' => $fin,
                'vigente' => $nombre === $vigente,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        DB::table('ciclos_escolares')->upsert(
            $filas,
            ['facultad_id', 'nombre'],
            ['periodo', 'fecha_inicio', 'fecha_fin', 'vigente', 'updated_at']
        );
    }
}