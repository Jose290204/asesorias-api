<?php

namespace App\Services;

use App\Enums\EstatusAsesoria;
use App\Exceptions\ErrorDeNegocio;
use App\Models\Asesoria;
use App\Models\Sesion;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Enums\AccionBitacora;
use App\Models\Bitacora;

class SesionService
{
    // Sesiones de una asesoría, la más reciente primero.
    // Sin paginar: una asesoría tiene pocas sesiones (unas por semana, dentro de un ciclo)
    // y el modal del front las muestra todas en una lista.
    public function listar(Asesoria $asesoria): Collection
    {
        return $asesoria->sesiones()
            // El Resource muestra el horario y el nombre de quien la registró: se cargan de golpe
            ->with(['horario', 'registradaPor'])
            ->orderByDesc('fecha')
            // El id desempata: dos sesiones el mismo día (otro horario) salen en orden estable
            ->orderByDesc('id')
            ->get();
    }

    // El asesor asignado registra una sesión de una asesoría en curso.
    // $datos viene de $request->validated(): fecha, horario_id y opcionalmente tema y notas.
    public function registrar(Asesoria $asesoria, Usuario $asesor, array $datos): Sesion
    {
        return DB::transaction(function () use ($asesoria, $asesor, $datos) {
            // Relee la asesoría y la BLOQUEA hasta que termine la transacción: si en el mismo
            // instante se está completando, cancelando o editando su fecha de inicio, este
            // método espera su turno y después ve el estado ya cambiado.
            $actual = Asesoria::whereKey($asesoria->id)->lockForUpdate()->firstOrFail();

            // Solo se registran sesiones mientras la asesoría sigue en curso.
            // Va antes que las demás reglas: una cerrada ya no se toca
            if ($actual->estatus !== EstatusAsesoria::EnCurso) {
                throw ErrorDeNegocio::conflicto('Solo se pueden registrar sesiones en asesorías en curso.', 'asesoria_no_en_curso');
            }

            $fecha = Carbon::parse($datos['fecha'])->startOfDay();

            // Una sesión no puede ser anterior al inicio de la asesoría
            if ($fecha->lt($actual->fecha_inicio)) {
                throw ErrorDeNegocio::invalido(
                    'La sesión no puede ser anterior a la fecha de inicio de la asesoría.',
                    'fecha_anterior_al_inicio'
                );
            }

            // Única por asesoría + fecha + horario (la BD también lo impone con un índice único;
            // esta consulta da un 409 claro en vez de un error de base de datos)
            $repetida = $actual->sesiones()
                ->whereDate('fecha', $fecha->toDateString())
                ->where('horario_id', $datos['horario_id'])
                ->exists();

            if ($repetida) {
                throw ErrorDeNegocio::conflicto('Ya hay una sesión registrada en esa fecha y horario.', 'sesion_duplicada');
            }

            // Sin tope de sesiones. create() desde la relación rellena asesoria_id solo
            $sesion = $actual->sesiones()->create([
                'fecha' => $fecha,
                'horario_id' => $datos['horario_id'],
                'tema' => $datos['tema'] ?? null,
                'notas' => $datos['notas'] ?? null,
                'registrada_por' => $asesor->id,
            ]);

            // Relaciones que el Resource necesita para armar la respuesta
            $actual->loadMissing('materia');

            Bitacora::registrar(AccionBitacora::SesionRegistrada, $actual, [
                'etiqueta' => "Asesoría de {$actual->materia->nombre}",
                'detalle' => "Registró una sesión del {$fecha->format('d/m/Y')}".($sesion->tema ? ": {$sesion->tema}." : '.'),
            ]);

            return $sesion->load(['horario', 'registradaPor']);
        });
    }

        // El asesor asignado elimina una sesión. Es un borrado real: la sesión no tiene historial propio.
    public function eliminar(Sesion $sesion): void
    {
        DB::transaction(function () use ($sesion) {
            // Se bloquea la asesoría, igual que en registrar(): si en el mismo instante se está
            // completando o cancelando, este método espera su turno y ve el estatus ya cambiado.
            $asesoria = Asesoria::whereKey($sesion->asesoria_id)->lockForUpdate()->firstOrFail();

            // Una asesoría cerrada conserva su historial de sesiones tal como quedó
            if ($asesoria->estatus !== EstatusAsesoria::EnCurso) {
                throw ErrorDeNegocio::conflicto('Solo se pueden eliminar sesiones de asesorías en curso.', 'asesoria_no_en_curso');
            }

            $asesoria->loadMissing('materia');

            Bitacora::registrar(AccionBitacora::SesionEliminada, $asesoria, [
                'etiqueta' => "Asesoría de {$asesoria->materia->nombre}",
                'detalle' => "Eliminó la sesión del {$sesion->fecha->format('d/m/Y')}.",
            ]);

            $sesion->delete();
        });
    }
}