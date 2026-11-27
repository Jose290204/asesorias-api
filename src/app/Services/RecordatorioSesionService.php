<?php

namespace App\Services;

use App\Enums\EstatusAsesoria;
use App\Enums\EstatusUsuario;
use App\Enums\TipoNotificacion;
use App\Models\Asesoria;
use App\Models\Usuario;
use App\Notifications\RecordatorioSesionNotificacion;
use App\Support\HorasDeHorario;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

// Recordatorios del día de la sesión: dos avisos al estudiante y al asesor.
//  - "manana": a las 9:30 ("Hoy tienes asesoría de ... a las ...").
//  - "previo": una hora antes de la sesión ("En 1 hora tienes asesoría ...").
// Se corre cada pocos minutos: cada aviso sale en cuanto pasa su hora y antes de que empiece la sesión,
// y no se repite (se revisa en las notificaciones). Si el scheduler se detiene un rato, se pone al corriente.
class RecordatorioSesionService
{
    public function __construct(private DiasHabilesService $diasHabiles)
    {
    }

    private const HORA_MANANA = '09:30';

    // El aviso de la mañana solo sale si la sesión empieza después de esta hora; si no, quedaría pegado
    // al de "1 hora antes" (o llegaría tarde). Esas sesiones tempranas solo reciben el aviso previo.
    private const SESION_MINIMA_PARA_AVISO_MANANA = '10:30';

    /** @return int cuántos avisos se enviaron */
    public function enviar(?CarbonInterface $ahora = null): int
    {
        $ahora = $ahora ? Carbon::instance($ahora) : now();
        $hoy = $ahora->copy()->startOfDay();
        $fecha = $hoy->toDateString();

        $asesorias = Asesoria::query()
            ->where('estatus', EstatusAsesoria::EnCurso)
            ->whereDate('fecha_inicio', '<=', $fecha)
            ->with([
                'materia', 'horario', 'cicloEscolar', 'estudiante', 'asesor',
                // Si ya se registró la sesión de hoy, no hace falta recordarla
                'sesiones' => fn ($q) => $q->whereDate('fecha', $fecha),
            ])
            ->get();

        $enviados = 0;

        foreach ($asesorias as $asesoria) {
            // Misma regla del calendario: mismo día de la semana que la fecha de inicio, dentro del ciclo
            if ($asesoria->fecha_inicio->dayOfWeek !== $hoy->dayOfWeek) {
                continue;
            }
            if ($asesoria->cicloEscolar && $asesoria->cicloEscolar->fecha_fin->lt($hoy)) {
                continue;
            }
            if ($asesoria->sesiones->isNotEmpty()) {
                continue;
            }
            // Vacaciones o suspensión: ese día no hay sesión
            if ($this->diasHabiles->motivo($asesoria->facultad_id, $hoy)) {
                continue;
            }

            [$horaInicio] = HorasDeHorario::desde($asesoria->horario->etiqueta);
            $inicio = $hoy->copy()->setTimeFromTimeString($horaInicio);

            // La sesión ya empezó: ya no tiene caso avisar
            if ($ahora->gte($inicio)) {
                continue;
            }

            if ($ahora->gte($inicio->copy()->subHour())) {
                // Ya toca el aviso previo; si el de la mañana no salió, ya no tiene sentido
                $momento = RecordatorioSesionNotificacion::PREVIO;
            } elseif (
                $inicio->gt($hoy->copy()->setTimeFromTimeString(self::SESION_MINIMA_PARA_AVISO_MANANA))
                && $ahora->gte($hoy->copy()->setTimeFromTimeString(self::HORA_MANANA))
            ) {
                $momento = RecordatorioSesionNotificacion::MANANA;
            } else {
                continue;
            }

            $hora = $inicio->format('g:i A');

            $destinos = [
                [$asesoria->estudiante, 'estudiante', $asesoria->asesor->nombreCompleto()],
                [$asesoria->asesor, 'asesor', $asesoria->estudiante->nombreCompleto()],
            ];

            foreach ($destinos as [$usuario, $como, $persona]) {
                if ($usuario->estatus !== EstatusUsuario::Activo || $this->yaAvisado($usuario, $asesoria->id, $fecha, $momento)) {
                    continue;
                }

                try {
                    $usuario->notifyNow(new RecordatorioSesionNotificacion(
                        $asesoria->id, $asesoria->materia->nombre, $persona, $como, $fecha, $hora, $momento
                    ));
                    $enviados++;
                } catch (Throwable $e) {
                    // Un fallo con una persona (por ejemplo, el servicio de push) no frena a las demás
                    report($e);
                }
            }
        }

        return $enviados;
    }

    private function yaAvisado(Usuario $usuario, int $asesoriaId, string $fecha, string $momento): bool
    {
        return $usuario->notifications()
            ->where('type', TipoNotificacion::SesionRecordatorio->value)
            ->where('data->asesoria_id', $asesoriaId)
            ->where('data->fecha', $fecha)
            ->where('data->momento', $momento)
            ->exists();
    }
}
