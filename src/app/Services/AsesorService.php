<?php

namespace App\Services;

use App\Enums\Dia;
use App\Enums\EstatusAsesoria;
use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Exceptions\ErrorDeNegocio;
use App\Models\Asesoria;
use App\Models\CicloEscolar;
use App\Models\Materia;
use App\Models\Usuario;
use App\Support\ContextoFacultad;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AsesorService
{
    // Relaciones que necesita DisponibilidadResource. Una sola lista para la lista y el detalle
    private const RELACIONES = [
        'disponibilidadMaterias.materia.licenciatura',
        'disponibilidadHorarios.horario',
        'disponibilidadModalidades',
    ];

    // Ids de las materias que puede pedir este estudiante: las de la licenciatura de su grupo,
    // del semestre que cursa hacia abajo (la misma regla de GET /estudiante/materias).
    public function materiasCursables(Usuario $estudiante): array
    {
        $grupo = $estudiante->grupo;

        // Sin grupo no hay semestre del cual partir: conflicto de estado de la cuenta
        if (! $grupo) {
            throw ErrorDeNegocio::conflicto('Tu cuenta no tiene un grupo asignado.', 'sin_grupo');
        }

        $ciclo = CicloEscolar::vigente()->first();

        if (! $ciclo) {
            throw ErrorDeNegocio::conflicto('No hay un ciclo escolar vigente.', 'sin_ciclo');
        }

        return Materia::query()
            ->whereHas('licenciatura')
            ->where('licenciatura_id', $grupo->licenciatura_id)
            ->where('semestre', '<=', $grupo->semestreActual($ciclo->periodo))
            ->pluck('id')
            ->all();
    }

    // Consulta base: "qué asesores puede ver este estudiante". Devuelve el Builder SIN ejecutar.
    // $materiaIds: las materias que el estudiante puede pedir (si no se pasan, se calculan).
    public function visibles(Usuario $estudiante, ?array $materiaIds = null): Builder
    {
        $materiaIds ??= $this->materiasCursables($estudiante);

        return Usuario::query()
            // Usuario no tiene FacultadScope, por eso la facultad se filtra a mano
            ->where('facultad_id', $estudiante->facultad_id)
            ->where('estatus', EstatusUsuario::Activo->value)
            // Nunca el propio estudiante (asesor par)
            ->where('id', '!=', $estudiante->id)
            // whereHas = "que exista al menos una fila relacionada que cumpla esto"
            ->whereHas('roles', fn (Builder $q) => $q->where('rol_id', Rol::Asesor->value))
            // Que dé al menos una materia de la carga del estudiante
            ->whereHas('disponibilidadMaterias', fn (Builder $q) => $q->whereIn('materia_id', $materiaIds))
            // Que tenga al menos un horario LIBRE (sin asesoría en curso en ese día y horario)
            ->whereHas('disponibilidadHorarios', fn (Builder $h) => $this->soloHorariosLibres($h))
            ->whereHas('disponibilidadModalidades');
    }

    // Base del admin: asesores activos de la facultad en contexto (la del admin, o la que
    // eligió el super admin con X-Facultad-Id). NO exige disponibilidad: un asesor recién
    // dado de alta todavía no la tiene y el admin debe poder encontrarlo para armársela.
    private function enFacultad(): Builder
    {
        return Usuario::query()
            ->where('facultad_id', app(ContextoFacultad::class)->id())
            ->where('estatus', EstatusUsuario::Activo->value)
            ->whereHas('roles', fn (Builder $q) => $q->where('rol_id', Rol::Asesor->value));
    }

    // $filtros viene de $request->validated(): buscar, materia_id, modalidad, fecha, horario_id.
    public function buscar(Usuario $estudiante, array $filtros): LengthAwarePaginator
    {
        $materiaIds = $this->materiasCursables($estudiante);

        $consulta = $this->visibles($estudiante, $materiaIds);

        // Filtrar por una materia que no está en su carga no debe regresar a nadie,
        // aunque algún asesor la dé (solo se le muestran las materias de su carga)
        $materiaFiltro = $filtros['materia_id'] ?? null;

        if ($materiaFiltro !== null && ! in_array((int) $materiaFiltro, $materiaIds, true)) {
            $consulta->whereRaw('1 = 0');
        }

        $pagina = $this->listar($consulta, $filtros, soloLibres: true);

        // Cada asesor muestra solo las materias que este estudiante puede pedirle
        // y solo los horarios que todavía están libres
        $asesores = $pagina->getCollection();
        $asesores->each(fn (Usuario $asesor) => $this->soloMaterias($asesor, $materiaIds));
        $this->quitarHorariosOcupados($asesores->all());

        return $pagina;
    }

    // Lo mismo para el admin ("Crear asesoría"). Aquí sí se piden materias, horarios y
    // modalidades: un asesor sin disponibilidad no puede recibir asesorías.
    public function buscarEnFacultad(array $filtros): LengthAwarePaginator
    {
        $consulta = $this->enFacultad()
            ->whereHas('disponibilidadMaterias')
            ->whereHas('disponibilidadHorarios')
            ->whereHas('disponibilidadModalidades');

        return $this->listar($consulta, $filtros);
    }

    // Un asesor visible para este estudiante, o 404. Se usa en el detalle, en horarios-disponibles
    // y al crear o editar una solicitud. Solo trae las materias que el estudiante puede pedirle
    // y los horarios libres: así validarQueOfrece rechaza una materia fuera de su carga
    // o un horario que ya está ocupado.
    public function encontrar(Usuario $estudiante, int $id): Usuario
    {
        $materiaIds = $this->materiasCursables($estudiante);

        // findOrFail lanza ModelNotFoundException y bootstrap/app.php la convierte en el 404 JSON.
        // No existe, otra facultad, no es asesor, inactivo, es el propio estudiante, no da
        // ninguna materia de su carga o no tiene horarios libres: misma respuesta
        $asesor = $this->visibles($estudiante, $materiaIds)
            ->with(self::RELACIONES)
            ->findOrFail($id);

        $this->soloMaterias($asesor, $materiaIds);
        $this->quitarHorariosOcupados([$asesor]);

        return $asesor;
    }

    // Un asesor activo de la facultad en contexto, o 404 (no existe, otra facultad,
    // no es asesor o está inactivo: misma respuesta). No exige disponibilidad.
    public function encontrarEnFacultad(int $id): Usuario
    {
        return $this->enFacultad()
            ->with(self::RELACIONES)
            ->findOrFail($id);
    }

    // Deja en la relación solo las materias indicadas (no toca la base de datos)
    private function soloMaterias(Usuario $asesor, array $materiaIds): void
    {
        $asesor->setRelation(
            'disponibilidadMaterias',
            $asesor->disponibilidadMaterias->whereIn('materia_id', $materiaIds)->values()
        );
    }

    // Quita de cada asesor los horarios (día + horario) en los que ya tiene una asesoría EN CURSO.
    // Es el mismo criterio con el que SolicitudService::aceptar rechaza con "horario_ocupado".
    // Una sola consulta para todos los asesores de la página.
    private function quitarHorariosOcupados(array $asesores): void
    {
        if ($asesores === []) {
            return;
        }

        $ocupados = Asesoria::query()
            ->whereIn('asesor_id', collect($asesores)->pluck('id'))
            ->where('estatus', EstatusAsesoria::EnCurso)
            ->get(['asesor_id', 'horario_id', 'fecha_inicio'])
            ->map(fn (Asesoria $a) => $a->asesor_id.'|'.Dia::desdeFecha($a->fecha_inicio)?->value.'|'.$a->horario_id)
            ->flip();

        foreach ($asesores as $asesor) {
            $asesor->setRelation(
                'disponibilidadHorarios',
                $asesor->disponibilidadHorarios
                    ->reject(fn ($fila) => $ocupados->has($asesor->id.'|'.$fila->dia->value.'|'.$fila->horario_id))
                    ->values()
            );
        }
    }

    // Para usar dentro de whereHas('disponibilidadHorarios', ...): deja solo las filas de
    // disponibilidad que NO tienen una asesoría en curso del mismo asesor, horario y día de la semana.
    // WEEKDAY() en MySQL: 0 = lunes ... 6 = domingo; ELT lo convierte al valor del enum Dia.
    private function soloHorariosLibres(Builder $filas): void
    {
        $filas->whereNotExists(function ($q) {
            $q->selectRaw('1')
                ->from('asesorias')
                ->whereColumn('asesorias.asesor_id', 'disponibilidad_horarios.asesor_id')
                ->whereColumn('asesorias.horario_id', 'disponibilidad_horarios.horario_id')
                ->where('asesorias.estatus', EstatusAsesoria::EnCurso->value)
                ->whereRaw("ELT(WEEKDAY(asesorias.fecha_inicio) + 1, 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo') = disponibilidad_horarios.dia");
        });
    }

    // Filtros + relaciones + orden + paginación, comunes a la vista del estudiante y a la del admin.
    // $soloLibres: en la vista del estudiante el filtro por día y hora ignora los horarios ocupados.
    private function listar(Builder $consulta, array $filtros, bool $soloLibres = false): LengthAwarePaginator
    {
        $this->filtrarPorNombre($consulta, $filtros['buscar'] ?? null);
        $this->filtrarPorMateria($consulta, $filtros['materia_id'] ?? null);
        $this->filtrarPorModalidad($consulta, $filtros['modalidad'] ?? null);
        $this->filtrarPorHorario($consulta, $filtros['fecha'] ?? null, $filtros['horario_id'] ?? null, $soloLibres);

        return $consulta
            ->with(self::RELACIONES)
            ->orderBy('apellido_paterno')
            ->orderBy('apellido_materno')
            ->orderBy('nombre')
            // El id desempata: la paginación no repite ni salta asesores con el mismo nombre
            ->orderBy('id')
            ->paginate(10);
    }

    // ---------- Filtros: si el valor no llegó, no hacen nada ----------

    private function filtrarPorNombre(Builder $consulta, ?string $texto): void
    {
        if ($texto === null) {
            return;
        }

        // CONCAT_WS une nombre y apellidos con espacio: "Juan Pérez" encuentra a Juan Pérez López
        $consulta->whereRaw("CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) like ?", ["%{$texto}%"]);
    }

    private function filtrarPorMateria(Builder $consulta, ?int $materiaId): void
    {
        if ($materiaId === null) {
            return;
        }

        $consulta->whereHas('disponibilidadMaterias', fn (Builder $m) => $m->where('materia_id', $materiaId));
    }

    private function filtrarPorModalidad(Builder $consulta, ?string $modalidad): void
    {
        if ($modalidad === null) {
            return;
        }

        $consulta->whereHas('disponibilidadModalidades', fn (Builder $m) => $m->where('modalidad', $modalidad));
    }

    // fecha y horario_id condicionan LA MISMA fila de disponibilidad_horarios:
    // "lunes + 9:00" no debe encontrar a quien tiene lunes 7:00 y miércoles 9:00
    private function filtrarPorHorario(Builder $consulta, ?string $fecha, ?int $horarioId, bool $soloLibres = false): void
    {
        if ($fecha === null && $horarioId === null) {
            return;
        }

        // De la fecha sale el día de la semana
        $dia = null;

        if ($fecha !== null) {
            $dia = Dia::desdeFecha(Carbon::parse($fecha));

            // Sábado o domingo: nadie ofrece esos días, la lista sale vacía
            if ($dia === null) {
                $consulta->whereRaw('1 = 0');

                return;
            }
        }

        $consulta->whereHas('disponibilidadHorarios', function (Builder $h) use ($dia, $horarioId, $soloLibres) {
            if ($dia !== null) {
                $h->where('dia', $dia->value);
            }

            if ($horarioId !== null) {
                $h->where('horario_id', $horarioId);
            }

            if ($soloLibres) {
                $this->soloHorariosLibres($h);
            }
        });
    }
}