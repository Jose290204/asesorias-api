<?php

namespace App\Services;

use App\Enums\Dia;
use App\Enums\EstatusSolicitud;
use App\Exceptions\ErrorDeNegocio;
use App\Models\CicloEscolar;
use App\Models\Solicitud;
use App\Models\Usuario;
use Illuminate\Support\Carbon;

class SolicitudService
{
    // AsesorService ya trae encontrar(), que aplica el mismo filtro que la lista:
    // existe, misma facultad, activo, con disponibilidad, nunca el propio estudiante
    public function __construct(private AsesorService $asesores)
    {
    }

    public function crear(Usuario $estudiante, array $datos): Solicitud
    {
        // 404 si el asesor no es visible para este estudiante (facultad, rol, activo, etc.)
        // Ya trae cargadas disponibilidadMaterias, disponibilidadHorarios y disponibilidadModalidades
        $asesor = $this->asesores->encontrar($estudiante, $datos['asesor_id']);

        $fecha = Carbon::parse($datos['fecha']);
        $dia = Dia::desdeFecha($fecha);

        // Sábado o domingo: ningún asesor ofrece disponibilidad esos días
        if ($dia === null) {
            throw ErrorDeNegocio::invalido('Ese día no se ofrecen asesorías.', 'dia_no_disponible');
        }

        $this->validarQueOfrece($asesor, $datos, $dia);

        $ciclo = CicloEscolar::vigente()->first();

        if (! $ciclo) {
            throw ErrorDeNegocio::conflicto('No hay un ciclo escolar vigente.', 'sin_ciclo');
        }

        if ($fecha->gt($ciclo->fecha_fin)) {
            throw ErrorDeNegocio::invalido('La fecha queda fuera del ciclo escolar vigente.', 'fuera_de_ciclo');
        }

        $this->validarSinDuplicado($estudiante, $asesor, $datos, $fecha);

        $solicitud = Solicitud::create([
            'facultad_id' => $estudiante->facultad_id,
            'ciclo_escolar_id' => $ciclo->id,
            'grupo_id' => $estudiante->grupo_id,
            'estudiante_id' => $estudiante->id,
            'asesor_id' => $asesor->id,
            'materia_id' => $datos['materia_id'],
            'horario_id' => $datos['horario_id'],
            'fecha_inicio' => $fecha,
            'modalidad' => $datos['modalidad'],
            'razon' => $datos['razon'],
            'estatus' => EstatusSolicitud::Pendiente,
            'nota_estudiante' => $datos['nota'] ?? null,
        ]);

        // El Resource necesita estas relaciones cargadas para armar la respuesta
        return $solicitud->load(['asesor', 'materia', 'horario']);
    }

    // El asesor debe ofrecer esa materia, esa modalidad y ese día+horario.
    // Reutiliza las colecciones que ya trae cargadas AsesorService::encontrar (sin consultas nuevas)
    private function validarQueOfrece(Usuario $asesor, array $datos, Dia $dia): void
    {
        $daMateria = $asesor->disponibilidadMaterias
            ->contains(fn ($fila) => $fila->materia_id === (int) $datos['materia_id']);

        if (! $daMateria) {
            throw ErrorDeNegocio::invalido('El asesor no imparte esa materia.', 'materia_no_ofrecida');
        }

        $daModalidad = $asesor->disponibilidadModalidades
            ->contains(fn ($fila) => $fila->modalidad->value === $datos['modalidad']);

        if (! $daModalidad) {
            throw ErrorDeNegocio::invalido('El asesor no ofrece esa modalidad.', 'modalidad_no_ofrecida');
        }

        $daHorario = $asesor->disponibilidadHorarios
            ->contains(fn ($fila) => $fila->dia === $dia && $fila->horario_id === (int) $datos['horario_id']);

        if (! $daHorario) {
            throw ErrorDeNegocio::invalido('El asesor no tiene ese horario disponible ese día.', 'horario_no_disponible');
        }
    }

    // Mismo estudiante + asesor + materia + fecha + horario, con una solicitud anterior aún pendiente
    private function validarSinDuplicado(Usuario $estudiante, Usuario $asesor, array $datos, Carbon $fecha): void
    {
        $existe = Solicitud::where('estudiante_id', $estudiante->id)
            ->where('asesor_id', $asesor->id)
            ->where('materia_id', $datos['materia_id'])
            ->where('horario_id', $datos['horario_id'])
            ->whereDate('fecha_inicio', $fecha)
            ->where('estatus', EstatusSolicitud::Pendiente)
            ->exists();

        if ($existe) {
            throw ErrorDeNegocio::conflicto('Ya tienes una solicitud pendiente para ese mismo horario.', 'solicitud_duplicada');
        }
    }
}