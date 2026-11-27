<?php

namespace App\Services;

use App\Enums\EstatusAsesoria;
use App\Models\Asesoria;
use App\Models\Usuario;
use App\Support\HorasDeHorario;
use Carbon\CarbonInterface;

// Calendario derivado: no hay sesiones agendadas en la BD. Cada asesoría EN CURSO se repite
// cada semana, el mismo día y horario de su fecha de inicio, hasta el fin de su ciclo.
class CalendarioService
{
    public function __construct(private DiasHabilesService $diasHabiles)
    {
    }

    /**
     * @param  string  $como  'estudiante' o 'asesor' (lo pone la ruta)
     * @return array<int, array<string, mixed>>
     */
    public function ocurrencias(Usuario $usuario, string $como, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $columna = $como === 'asesor' ? 'asesor_id' : 'estudiante_id';
        $otro = $como === 'asesor' ? 'estudiante' : 'asesor';

        $asesorias = Asesoria::query()
            ->where($columna, $usuario->id)
            ->where('estatus', EstatusAsesoria::EnCurso)
            ->with(['materia', 'horario', 'cicloEscolar', $otro, 'sesiones:id,asesoria_id,fecha'])
            ->get();

        $salida = [];

        // Vacaciones y suspensiones: esas semanas no hay sesión
        $inhabiles = $this->diasHabiles->rango($usuario->facultad_id, $desde, $hasta);

        foreach ($asesorias as $asesoria) {
            $inicio = $asesoria->fecha_inicio->copy()->startOfDay();
            [$horaInicio, $horaFin] = HorasDeHorario::desde($asesoria->horario->etiqueta);

            // La serie termina al acabar el ciclo de la asesoría
            $tope = $asesoria->cicloEscolar
                ? $hasta->copy()->min($asesoria->cicloEscolar->fecha_fin->copy()->endOfDay())
                : $hasta->copy();

            // Primer día >= desde (y >= inicio) que cae en el mismo día de la semana
            $dia = $desde->copy()->startOfDay();
            if ($dia->lt($inicio)) {
                $dia = $inicio->copy();
            }
            $dia->addDays(($inicio->dayOfWeek - $dia->dayOfWeek + 7) % 7);

            $registradas = $asesoria->sesiones->map(fn ($s) => $s->fecha->toDateString())->all();

            for (; $dia->lte($tope); $dia->addWeek()) {
                $fecha = $dia->toDateString();

                if (DiasHabilesService::cubre($inhabiles, $fecha)) {
                    continue;
                }

                $salida[] = [
                    'asesoria_id' => $asesoria->id,
                    'fecha' => $fecha,
                    'hora_inicio' => $horaInicio,
                    'hora_fin' => $horaFin,
                    'horario' => $asesoria->horario->etiqueta,
                    'materia' => $asesoria->materia->nombre,
                    'con' => $asesoria->{$otro}->nombreCompleto(),
                    'modalidad' => $asesoria->modalidad->etiqueta(),
                    // ¿Ya se registró la sesión de ese día? (útil para el asesor en días pasados)
                    'registrada' => in_array($fecha, $registradas, true),
                ];
            }
        }

        usort($salida, fn ($a, $b) => [$a['fecha'], $a['hora_inicio']] <=> [$b['fecha'], $b['hora_inicio']]);

        return $salida;
    }
}
