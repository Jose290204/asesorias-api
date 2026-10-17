<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MateriaSeeder extends Seeder
{
    public function run(): void
    {
        $archivo = fopen(database_path('seeders/data/materias.csv'), 'r');

        $encabezados = fgetcsv($archivo);
        $encabezados[0] = ltrim($encabezados[0], "\xEF\xBB\xBF");

        $ahora = now();
        $filas = [];

        while (($datos = fgetcsv($archivo)) !== false) {
            if ($datos === [null]) {
                continue;
            }

            $fila = array_combine($encabezados, $datos);

            $filas[] = [
                'id' => (int) $fila['id_materia'],
                'nombre' => trim($fila['materia']),
                'semestre' => (int) $fila['id_semestre'],   // el id coincide con el número de semestre
                'licenciatura_id' => (int) $fila['id_licenciatura'],
                'plan_estudio_id' => (int) $fila['id_plan'],
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        fclose($archivo);

        // Lotes de 100 para no armar una consulta enorme
        foreach (array_chunk($filas, 100) as $lote) {
            DB::table('materias')->upsert(
                $lote,
                ['id'],
                ['nombre', 'semestre', 'licenciatura_id', 'plan_estudio_id', 'updated_at']
            );
        }
    }
}