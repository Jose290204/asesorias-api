<?php

namespace Database\Seeders;

use App\Models\DiaInhabil;
use Illuminate\Database\Seeder;

class DiasInhabilesSeeder extends Seeder
{
    // Calendario Escolar UAS 2026-2027 (Acuerdo 622 del H. Consejo Universitario): general para todas las
    // facultades (facultad_id = null). Una facultad puede sumar los suyos con facultad_id.
    // Solo se guardan vacaciones y suspensiones de alumnos. Los periodos de exámenes ordinarios y
    // extraordinarios NO se bloquean (ahí es donde más se necesitan las asesorías). El 9 de octubre es
    // solo para personal administrativo, no aplica. Revisa las fechas contra el calendario oficial.
    public function run(): void
    {
        // [inicio, fin, tipo, motivo]
        $dias = [
            ['2026-09-16', '2026-09-16', DiaInhabil::SUSPENSION, 'Aniversario del inicio de la Independencia de México'],
            ['2026-11-02', '2026-11-02', DiaInhabil::SUSPENSION, 'Día de Muertos'],
            ['2026-11-16', '2026-11-16', DiaInhabil::SUSPENSION, 'Día de la Revolución Mexicana'],
            ['2026-12-21', '2027-01-01', DiaInhabil::VACACIONES, 'Vacaciones de diciembre'],
            ['2027-02-01', '2027-02-01', DiaInhabil::SUSPENSION, 'Aniversario de la Constitución de 1917'],
            ['2027-03-15', '2027-03-15', DiaInhabil::SUSPENSION, 'Natalicio de Benito Juárez'],
            ['2027-03-22', '2027-04-02', DiaInhabil::VACACIONES, 'Vacaciones de Semana Santa'],
            ['2027-05-10', '2027-05-10', DiaInhabil::SUSPENSION, 'Día de las Madres'],
            ['2027-07-19', '2027-08-06', DiaInhabil::VACACIONES, 'Vacaciones de verano'],
        ];

        foreach ($dias as [$inicio, $fin, $tipo, $motivo]) {
            DiaInhabil::updateOrCreate(
                ['facultad_id' => null, 'fecha_inicio' => $inicio, 'fecha_fin' => $fin],
                ['tipo' => $tipo, 'motivo' => $motivo]
            );
        }
    }
}
