<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GrupoSeeder extends Seeder
{
    public function run(): void
    {
        $archivo = fopen(database_path('seeders/data/grupos.csv'), 'r');

        // Encabezados; se quita el BOM que algunos exportadores agregan
        $encabezados = fgetcsv($archivo);
        $encabezados[0] = ltrim($encabezados[0], "\xEF\xBB\xBF");

        $ahora = now();
        $filas = [];

        while (($datos = fgetcsv($archivo)) !== false) {
            if ($datos === [null]) {
                continue;                        // línea vacía al final
            }

            $fila = array_combine($encabezados, $datos);

            $filas[] = [
                'id' => (int) $fila['id_grupo'],
                'licenciatura_id' => (int) $fila['id_licenciatura'],
                'nombre' => trim($fila['grupo']),
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        fclose($archivo);

        DB::table('grupos')->upsert($filas, ['id'], ['licenciatura_id', 'nombre', 'updated_at']);
    }
}