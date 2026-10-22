<?php

namespace App\Services;

use App\Enums\Dia;
use App\Enums\EstatusSolicitud;
use App\Exceptions\ErrorDeNegocio;
use App\Models\CicloEscolar;
use App\Models\Solicitud;
use App\Models\Usuario;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Enums\EstatusAsesoria;
use App\Models\Asesoria;

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

        // Lista las solicitudes del usuario según el rol con el que consulta.
    // $como llega desde la ruta ('estudiante' o 'asesor'); decide por qué columna se filtra.
    // Un asesor par usa las dos rutas y en cada una ve solo lo suyo en ese rol.
    public function listar(Usuario $usuario, string $como, ?EstatusSolicitud $estatus): LengthAwarePaginator
    {
        // Estudiante: las que él pidió. Asesor: las que le pidieron a él.
        $columna = $como === 'asesor' ? 'asesor_id' : 'estudiante_id';

        return Solicitud::query()
            ->where($columna, $usuario->id)
            // when() agrega el filtro solo si $estatus no es null
            ->when($estatus, fn ($q) => $q->where('estatus', $estatus))
            // Relaciones que el Resource necesita, cargadas de golpe (evita N+1)
            ->with(['estudiante', 'asesor', 'materia', 'horario'])
            // Más recientes primero; el id desempata
            ->latest('id')
            ->paginate(10);
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
    private function validarSinDuplicado(Usuario $estudiante, Usuario $asesor, array $datos, Carbon $fecha, ?int $ignorarSolicitudId = null): void
    {
        $existe = Solicitud::where('estudiante_id', $estudiante->id)
            ->where('asesor_id', $asesor->id)
            ->where('materia_id', $datos['materia_id'])
            ->where('horario_id', $datos['horario_id'])
            ->whereDate('fecha_inicio', $fecha)
            ->where('estatus', EstatusSolicitud::Pendiente)
            ->when($ignorarSolicitudId, fn ($q) => $q->where('id', '!=', $ignorarSolicitudId))
            ->exists();

        if ($existe) {
            throw ErrorDeNegocio::conflicto('Ya tienes una solicitud pendiente para ese mismo horario.', 'solicitud_duplicada');
        }
    }

    public function cancelar(Solicitud $solicitud): Solicitud
    {
         // se hace todo en una transaccion
        return DB::transaction(function () use ($solicitud) {

        // Vuelve a leer la fila desde la BD y la BLOQUEA (lockForUpdate) hasta que
        // termine la transacción. Si el asesor la está aceptando justo ahora, esta línea
        // espera su turno. firstOrFail() lanza 404 si la fila ya no existe.
        $actual = Solicitud::whereKey($solicitud->id)->lockForUpdate()->firstOrFail();

        // solo se cancela solicitudes pendientes
        if ($actual->estatus !== EstatusSolicitud::Pendiente) {
            throw ErrorDeNegocio::conflicto('Solo se pueden cancelar solicitudes pendientes.', 'solicitud_no_pendiente');
        }

        // No se borra: se cambia el estatus y se anota cuándo se cerró
        $actual->update([
            'estatus' => EstatusSolicitud::Cancelada,
            'respondida_en' => now(),
        ]);

        // Carga las relaciones que el Resource va a usar y devuelve la solicitud
        return $actual->load(['estudiante', 'asesor', 'materia', 'horario']);
    });
    }

    // El asesor rechaza una solicitud pendiente y deja el motivo.
    // con el estudiante cancelando, o con el propio asesor aceptando, en el mismo instante.
    public function rechazar(Solicitud $solicitud, string $motivo): Solicitud
    {
        return DB::transaction(function () use ($solicitud, $motivo) {
            // Se relee la fila bloqueada: el $solicitud que llegó puede estar viejo
            $actual = Solicitud::whereKey($solicitud->id)->lockForUpdate()->firstOrFail();

            // Solo se rechaza lo pendiente (la cancelada, aceptada o rechazada ya está cerrada)
            if ($actual->estatus !== EstatusSolicitud::Pendiente) {
                throw ErrorDeNegocio::conflicto('Solo se pueden rechazar solicitudes pendientes.', 'solicitud_no_pendiente');
            }

            $actual->update([
                'estatus' => EstatusSolicitud::Rechazada,
                'motivo_rechazo' => $motivo,
                'respondida_en' => now(),
            ]);

            return $actual->load(['estudiante', 'asesor', 'materia', 'horario']);
        });
    }

        // Motivo que ve el estudiante cuando el sistema rechaza su solicitud porque el cupo se ocupó
    private const MOTIVO_CUPO_TOMADO = 'Este horario ya fue tomado por otra solicitud aceptada.';

    // El asesor acepta una solicitud pendiente: crea la asesoría y rechaza a las competidoras.
    public function aceptar(Solicitud $solicitud): Solicitud
    {
        return DB::transaction(function () use ($solicitud) {
            // Se bloquea la fila del ASESOR primero. Así dos aceptaciones del mismo asesor
            //    (aunque sean de solicitudes distintas) se hacen una tras otra y la segunda
            //    ya ve el horario ocupado por la primera.
            Usuario::whereKey($solicitud->asesor_id)->lockForUpdate()->firstOrFail();

            // 2. Se relee la solicitud bloqueada: la que llegó por la ruta puede estar vieja
            $actual = Solicitud::whereKey($solicitud->id)->lockForUpdate()->firstOrFail();

            if ($actual->estatus !== EstatusSolicitud::Pendiente) {
                throw ErrorDeNegocio::conflicto('Solo se pueden aceptar solicitudes pendientes.', 'solicitud_no_pendiente');
            }

            // El día de la semana sale de fecha_inicio (lunes a viernes; las solicitudes nunca caen en fin de semana)
            $dia = Dia::desdeFecha($actual->fecha_inicio);

            // ¿El asesor ya tiene una asesoría EN CURSO en ese día de la semana y ese horario?
            $ocupado = Asesoria::where('asesor_id', $actual->asesor_id)
                ->where('estatus', EstatusAsesoria::EnCurso)
                ->where('horario_id', $actual->horario_id)
                ->get(['fecha_inicio'])
                ->contains(fn ($a) => Dia::desdeFecha($a->fecha_inicio) === $dia);

            if ($ocupado) {
                throw ErrorDeNegocio::conflicto('Ya tienes una asesoría en curso en ese día y horario.', 'horario_ocupado');
            }

            // 4. La solicitud queda aceptada
            $actual->update([
                'estatus' => EstatusSolicitud::Aceptada,
                'respondida_en' => now(),
            ]);

            // 5. Se crea la asesoría copiando los datos de la solicitud
            Asesoria::create([
                'facultad_id' => $actual->facultad_id,
                'ciclo_escolar_id' => $actual->ciclo_escolar_id,
                'grupo_id' => $actual->grupo_id,
                'solicitud_id' => $actual->id,
                'estudiante_id' => $actual->estudiante_id,
                'asesor_id' => $actual->asesor_id,
                'materia_id' => $actual->materia_id,
                'horario_id' => $actual->horario_id,
                'modalidad' => $actual->modalidad,
                'razon' => $actual->razon,
                'estatus' => EstatusAsesoria::EnCurso,
                'fecha_inicio' => $actual->fecha_inicio,
            ]);

            // sen buscan las otras solicitudes pendientes del mismo asesor + mismo horario + mismo día de la semana
            $competidoras = Solicitud::where('asesor_id', $actual->asesor_id)
                ->where('horario_id', $actual->horario_id)
                ->where('estatus', EstatusSolicitud::Pendiente)
                ->where('id', '!=', $actual->id)
                ->get(['id', 'fecha_inicio'])
                ->filter(fn ($s) => Dia::desdeFecha($s->fecha_inicio) === $dia)
                ->pluck('id');

            if ($competidoras->isNotEmpty()) {
                Solicitud::whereIn('id', $competidoras)->update([
                    'estatus' => EstatusSolicitud::Rechazada->value,
                    'motivo_rechazo' => self::MOTIVO_CUPO_TOMADO,
                    'respondida_en' => now(),
                ]);
            }

            return $actual->load(['estudiante', 'asesor', 'materia', 'horario', 'asesoria']);
        });
    }

        // El estudiante edita su solicitud pendiente: fecha, horario y/o nota.
    // Mismas reglas que crear(), y la propia solicitud no cuenta como duplicado.
    public function editar(Usuario $estudiante, Solicitud $solicitud, array $datos): Solicitud
    {
        return DB::transaction(function () use ($estudiante, $solicitud, $datos) {
            // Mismo orden de bloqueos que aceptar(): primero el asesor, luego la solicitud.
            // Así editar y aceptar nunca se esperan en círculo, y si el asesor acepta
            // en este instante, una de las dos operaciones espera a la otra.
            Usuario::whereKey($solicitud->asesor_id)->lockForUpdate()->firstOrFail();
            $actual = Solicitud::whereKey($solicitud->id)->lockForUpdate()->firstOrFail();

            if ($actual->estatus !== EstatusSolicitud::Pendiente) {
                throw ErrorDeNegocio::conflicto('Solo se pueden editar solicitudes pendientes.', 'solicitud_no_pendiente');
            }

            // 404 si el asesor ya no es visible; trae cargada su disponibilidad
            $asesor = $this->asesores->encontrar($estudiante, $actual->asesor_id);

            // Lo que no se mande se queda como estaba
            $horarioId = $datos['horario_id'] ?? $actual->horario_id;
            $fecha = isset($datos['fecha']) ? Carbon::parse($datos['fecha']) : $actual->fecha_inicio->copy();

            $dia = Dia::desdeFecha($fecha);

            if ($dia === null) {
                throw ErrorDeNegocio::invalido('Ese día no se ofrecen asesorías.', 'dia_no_disponible');
            }

            // La materia y la modalidad no cambian, pero se revalidan por si el asesor
            // ya dejó de ofrecerlas desde que se creó la solicitud
            $this->validarQueOfrece($asesor, [
                'materia_id' => $actual->materia_id,
                'modalidad' => $actual->modalidad->value,
                'horario_id' => $horarioId,
            ], $dia);

            $ciclo = CicloEscolar::vigente()->first();

            if (! $ciclo) {
                throw ErrorDeNegocio::conflicto('No hay un ciclo escolar vigente.', 'sin_ciclo');
            }

            if ($fecha->gt($ciclo->fecha_fin)) {
                throw ErrorDeNegocio::invalido('La fecha queda fuera del ciclo escolar vigente.', 'fuera_de_ciclo');
            }

            // El último argumento hace que la propia solicitud no choque consigo misma
            $this->validarSinDuplicado($estudiante, $asesor, [
                'materia_id' => $actual->materia_id,
                'horario_id' => $horarioId,
            ], $fecha, $actual->id);

            $cambios = [
                'ciclo_escolar_id' => $ciclo->id,
                'horario_id' => $horarioId,
                'fecha_inicio' => $fecha,
            ];

            // array_key_exists y no isset: "nota": null es válido y significa "borrar la nota"
            if (array_key_exists('nota', $datos)) {
                $cambios['nota_estudiante'] = $datos['nota'];
            }

            $actual->update($cambios);

            return $actual->load(['estudiante', 'asesor', 'materia', 'horario']);
        });
    }
}