<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GrupoSeeder extends Seeder
{
    public function run(): void
    {
        $archivo = fopen(database_path('seeders/data/grupos.csv'), 'r');

        $encabezados = fgetcsv($archivo);
        $encabezados[0] = ltrim($encabezados[0], "\xEF\xBB\xBF");

        $ahora = now();
        $filas = [];
        $vistos = [];

        while (($datos = fgetcsv($archivo)) !== false) {
            if ($datos === [null]) {
                continue;
            }

            $fila = array_combine($encabezados, $datos);

            $licenciatura = (int) $fila['id_licenciatura'];
            $nombre = trim($fila['grupo']);
            $modalidad = trim($fila['modalidad']);

            // Carga inicial: el año es el número antes del guion ("4-1" => 4)
            $anio = (int) explode('-', $nombre)[0];

            if ($anio < 1) {
                throw new RuntimeException("Grupo con nombre inválido: {$nombre}");
            }

            if (! in_array($modalidad, ['presencial', 'virtual'], true)) {
                throw new RuntimeException("Modalidad inválida en el grupo {$nombre}: {$modalidad}");
            }

            // Sin esta revisión, el upsert fusiona los duplicados en silencio
            $clave = "{$licenciatura}|{$modalidad}|{$nombre}";
            if (isset($vistos[$clave])) {
                throw new RuntimeException("Grupo repetido: {$clave}");
            }
            $vistos[$clave] = true;

            $filas[] = [
                'id' => (int) $fila['id_grupo'],
                'licenciatura_id' => $licenciatura,
                'nombre' => $nombre,
                'anio' => $anio,
                'modalidad' => $modalidad,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        fclose($archivo);

        DB::table('grupos')->upsert(
            $filas,
            ['id'],
            ['licenciatura_id', 'nombre', 'anio', 'modalidad', 'updated_at']
        );
    }
}