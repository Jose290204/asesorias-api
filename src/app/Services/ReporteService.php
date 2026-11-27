<?php

namespace App\Services;

use App\Enums\EstatusAsesoria;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Models\Asesoria;
use App\Models\CicloEscolar;
use App\Models\Grupo;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Solicitud;
use App\Models\Usuario;
use App\Support\ContextoFacultad;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Reportes del administrador de facultad. Todo sale de las asesorías, solicitudes y sesiones de UN ciclo escolar.
//
// Los cinco reportes (asesores, materias, estudiantes, grupos y licenciaturas) funcionan igual:
//   resumen()    -> un renglón por entidad con sus totales (agregados con GROUP BY, sin cargar asesorías en memoria)
//   asesorias()  -> las asesorías de UNA entidad (la pantalla calcula de ellas los desgloses)
//
// La licenciatura de una asesoría es la de su MATERIA (siempre existe); el grupo puede ser null.
class ReporteService
{
    public const TIPOS = ['asesores', 'materias', 'estudiantes', 'grupos', 'licenciaturas'];

    // ---------- Ciclos ----------

    // El vigente primero (es el que la pantalla elige por defecto) y luego del más nuevo al más viejo
    public function ciclos(): array
    {
        return CicloEscolar::query()
            ->orderByDesc('vigente')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CicloEscolar $c) => [
                'id' => $c->id,
                'nombre' => $c->nombre.($c->vigente ? ' (vigente)' : ''),
                'periodo' => $c->periodo,
                'vigente' => $c->vigente,
            ])
            ->all();
    }

    // ---------- Resumen ----------

    public function resumen(string $tipo, int $cicloId): array
    {
        $agregados = $this->agregados($tipo, $cicloId);
        $sesiones = $this->sesiones($tipo, $cicloId);
        $solicitudes = $this->solicitudes($tipo, $cicloId);

        // Los estudiantes son demasiados para listarlos todos: solo salen los que tuvieron actividad en el ciclo
        $conActividad = $agregados->keys()->merge($solicitudes->keys())->unique()->values()->all();

        return $this->entidades($tipo, $conActividad)
            ->map(function (array $fila) use ($agregados, $sesiones, $solicitudes) {
                $a = $agregados->get($fila['id']);

                return $fila + [
                    'solicitudes' => (int) $solicitudes->get($fila['id'], 0),
                    'en_curso' => (int) ($a->en_curso ?? 0),
                    'completadas' => (int) ($a->completadas ?? 0),
                    'canceladas' => (int) ($a->canceladas ?? 0),
                    'sesiones' => (int) $sesiones->get($fila['id'], 0),
                    'asesores' => (int) ($a->asesores ?? 0),
                    'materias' => (int) ($a->materias ?? 0),
                    'estudiantes' => (int) ($a->estudiantes ?? 0),
                    'grupos' => (int) ($a->grupos ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    // Asesorías por estatus y cantidad de asesores, materias, estudiantes y grupos distintos, por entidad
    private function agregados(string $tipo, int $cicloId): Collection
    {
        $col = $this->columna($tipo, 'asesorias');

        return Asesoria::query()
            ->join('materias', 'materias.id', '=', 'asesorias.materia_id')
            ->where('asesorias.ciclo_escolar_id', $cicloId)
            ->whereNotNull($col)
            ->selectRaw("{$col} as entidad_id")
            ->selectRaw('SUM(CASE WHEN asesorias.estatus = ? THEN 1 ELSE 0 END) as en_curso', [EstatusAsesoria::EnCurso->value])
            ->selectRaw('SUM(CASE WHEN asesorias.estatus = ? THEN 1 ELSE 0 END) as completadas', [EstatusAsesoria::Completada->value])
            ->selectRaw('SUM(CASE WHEN asesorias.estatus = ? THEN 1 ELSE 0 END) as canceladas', [EstatusAsesoria::Cancelada->value])
            ->selectRaw('COUNT(DISTINCT asesorias.asesor_id) as asesores')
            ->selectRaw('COUNT(DISTINCT asesorias.materia_id) as materias')
            ->selectRaw('COUNT(DISTINCT asesorias.estudiante_id) as estudiantes')
            ->selectRaw('COUNT(DISTINCT asesorias.grupo_id) as grupos')
            ->groupBy($col)
            ->toBase()
            ->get()
            ->keyBy('entidad_id');
    }

    // Sesiones registradas en las asesorías del ciclo, por entidad. [entidad_id => total]
    private function sesiones(string $tipo, int $cicloId): Collection
    {
        $col = $this->columna($tipo, 'asesorias');

        return DB::table('sesiones')
            ->join('asesorias', 'asesorias.id', '=', 'sesiones.asesoria_id')
            ->join('materias', 'materias.id', '=', 'asesorias.materia_id')
            ->where('asesorias.facultad_id', $this->facultad())
            ->where('asesorias.ciclo_escolar_id', $cicloId)
            ->whereNotNull($col)
            ->selectRaw("{$col} as entidad_id, COUNT(*) as total")
            ->groupBy($col)
            ->pluck('total', 'entidad_id');
    }

    // Solicitudes del ciclo (de cualquier estatus), por entidad. [entidad_id => total]
    private function solicitudes(string $tipo, int $cicloId): Collection
    {
        $col = $this->columna($tipo, 'solicitudes');

        return Solicitud::query()
            ->join('materias', 'materias.id', '=', 'solicitudes.materia_id')
            ->where('solicitudes.ciclo_escolar_id', $cicloId)
            ->whereNotNull($col)
            ->selectRaw("{$col} as entidad_id, COUNT(*) as total")
            ->groupBy($col)
            ->toBase()
            ->pluck('total', 'entidad_id');
    }

    // Columna por la que se agrupa cada reporte. $tabla es 'asesorias' o 'solicitudes' (las dos tienen las mismas)
    private function columna(string $tipo, string $tabla): string
    {
        return match ($tipo) {
            'asesores' => "{$tabla}.asesor_id",
            'materias' => "{$tabla}.materia_id",
            'estudiantes' => "{$tabla}.estudiante_id",
            'grupos' => "{$tabla}.grupo_id",
            'licenciaturas' => 'materias.licenciatura_id',
        };
    }

    // ---------- Entidades de cada reporte (id, nombre y datos propios) ----------

    private function entidades(string $tipo, array $conActividad): Collection
    {
        return match ($tipo) {
            'asesores' => $this->asesores(),
            'materias' => $this->materias(),
            'estudiantes' => $this->estudiantes($conActividad),
            'grupos' => $this->grupos(),
            'licenciaturas' => $this->licenciaturas(),
        };
    }

    // Todos los asesores de la facultad. Par = asesor que además es estudiante
    private function asesores(): Collection
    {
        return Usuario::query()
            ->where('usuarios.facultad_id', $this->facultad())
            ->whereHas('roles', fn ($q) => $q->where('rol_id', Rol::Asesor->value))
            ->select('usuarios.id')
            ->selectRaw("CONCAT_WS(' ', usuarios.nombre, usuarios.apellido_paterno, usuarios.apellido_materno) as nombre")
            ->selectRaw(
                'EXISTS (SELECT 1 FROM usuario_roles ur WHERE ur.usuario_id = usuarios.id AND ur.rol_id = ?) as es_par',
                [Rol::Estudiante->value]
            )
            ->orderBy('nombre')
            ->orderBy('usuarios.id')
            ->toBase()
            ->get()
            ->map(fn ($u) => [
                'id' => (int) $u->id,
                'nombre' => $u->nombre,
                'tipo' => $u->es_par ? 'Par' : 'Disciplinar',
            ]);
    }

    // Todas las materias de la facultad (las que no tuvieron actividad salen en ceros)
    private function materias(): Collection
    {
        return Materia::query()
            ->join('licenciaturas', 'licenciaturas.id', '=', 'materias.licenciatura_id')
            ->where('licenciaturas.facultad_id', $this->facultad())
            ->orderBy('materias.nombre')
            ->orderBy('licenciaturas.nombre')
            ->toBase()
            ->get(['materias.id', 'materias.nombre', 'licenciaturas.nombre as licenciatura'])
            ->map(fn ($m) => ['id' => (int) $m->id, 'nombre' => $m->nombre, 'licenciatura' => $m->licenciatura]);
    }

    // Solo los estudiantes con asesorías o solicitudes en el ciclo. grupo y licenciatura son los ACTUALES del alumno
    private function estudiantes(array $conActividad): Collection
    {
        if ($conActividad === []) {
            return collect();
        }

        return Usuario::withTrashed()
            ->leftJoin('grupos', 'grupos.id', '=', 'usuarios.grupo_id')
            ->leftJoin('licenciaturas', 'licenciaturas.id', '=', 'grupos.licenciatura_id')
            ->where('usuarios.facultad_id', $this->facultad())
            ->whereIn('usuarios.id', $conActividad)
            ->selectRaw("usuarios.id, CONCAT_WS(' ', usuarios.nombre, usuarios.apellido_paterno, usuarios.apellido_materno) as nombre")
            ->selectRaw('grupos.nombre as grupo, licenciaturas.nombre as licenciatura')
            ->orderBy('nombre')
            ->orderBy('usuarios.id')
            ->toBase()
            ->get()
            ->map(fn ($e) => [
                'id' => (int) $e->id,
                'nombre' => $e->nombre,
                'grupo' => $e->grupo ?? '',
                'licenciatura' => $e->licenciatura ?? '',
            ]);
    }

    // Todos los grupos de la facultad. Si el nombre se repite en presencial y virtual, el virtual lo indica
    private function grupos(): Collection
    {
        return Grupo::query()
            ->join('licenciaturas', 'licenciaturas.id', '=', 'grupos.licenciatura_id')
            ->where('licenciaturas.facultad_id', $this->facultad())
            ->orderBy('licenciaturas.nombre')
            ->orderBy('grupos.nombre')
            ->orderBy('grupos.id')
            ->toBase()
            ->get(['grupos.id', 'grupos.nombre', 'grupos.modalidad', 'licenciaturas.nombre as licenciatura'])
            ->map(fn ($g) => [
                'id' => (int) $g->id,
                'nombre' => $g->modalidad === Modalidad::Virtual->value ? "{$g->nombre} (Virtual)" : $g->nombre,
                'licenciatura' => $g->licenciatura,
            ]);
    }

    private function licenciaturas(): Collection
    {
        return Licenciatura::query()
            ->where('facultad_id', $this->facultad())
            ->orderBy('nombre')
            ->toBase()
            ->get(['id', 'nombre'])
            ->map(fn ($l) => ['id' => (int) $l->id, 'nombre' => $l->nombre]);
    }

    // ---------- Detalle: asesorías de una entidad ----------

    // 404 si la entidad no existe o es de otra facultad
    public function asesorias(string $tipo, int $id, int $cicloId): array
    {
        abort_unless($this->existe($tipo, $id), 404);

        return Asesoria::query()
            ->join('materias', 'materias.id', '=', 'asesorias.materia_id')
            ->join('licenciaturas', 'licenciaturas.id', '=', 'materias.licenciatura_id')
            ->join('horarios', 'horarios.id', '=', 'asesorias.horario_id')
            ->join('usuarios as u_asesor', 'u_asesor.id', '=', 'asesorias.asesor_id')
            ->join('usuarios as u_est', 'u_est.id', '=', 'asesorias.estudiante_id')
            ->leftJoin('grupos', 'grupos.id', '=', 'asesorias.grupo_id')
            ->where('asesorias.ciclo_escolar_id', $cicloId)
            ->where($this->columna($tipo, 'asesorias'), $id)
            ->orderByDesc('asesorias.fecha_inicio')
            ->orderByDesc('asesorias.id')
            ->select([
                'asesorias.id', 'asesorias.estatus', 'asesorias.modalidad', 'asesorias.razon',
                'asesorias.fecha_inicio', 'asesorias.finalizada_en',
                'materias.nombre as materia', 'licenciaturas.nombre as licenciatura',
                'horarios.etiqueta as horario', 'grupos.nombre as grupo',
            ])
            ->selectRaw("CONCAT_WS(' ', u_asesor.nombre, u_asesor.apellido_paterno, u_asesor.apellido_materno) as asesor")
            ->selectRaw("CONCAT_WS(' ', u_est.nombre, u_est.apellido_paterno, u_est.apellido_materno) as estudiante")
            ->selectSub(
                DB::table('sesiones')->selectRaw('COUNT(*)')->whereColumn('sesiones.asesoria_id', 'asesorias.id'),
                'sesiones'
            )
            ->toBase()
            ->get()
            ->map(fn ($a) => [
                'id' => (int) $a->id,
                'asesor' => $a->asesor,
                'materia' => $a->materia,
                'estudiante' => $a->estudiante,
                'grupo' => $a->grupo ?? 'Sin grupo',
                'licenciatura' => $a->licenciatura,
                'fecha_inicio' => substr((string) $a->fecha_inicio, 0, 10),
                // Solo las completadas tienen fecha de término; la pantalla muestra una leyenda en las demás
                'fecha_fin' => $a->estatus === EstatusAsesoria::Completada->value && $a->finalizada_en
                    ? substr((string) $a->finalizada_en, 0, 10)
                    : null,
                'horario' => $a->horario,
                'modalidad' => Modalidad::from($a->modalidad)->etiqueta(),
                'razon' => RazonAsesoria::from($a->razon)->etiqueta(),
                'estatus' => $this->etiquetaEstatus($a->estatus),
                'sesiones' => (int) $a->sesiones,
            ])
            ->all();
    }

    private function existe(string $tipo, int $id): bool
    {
        return match ($tipo) {
            'asesores' => Usuario::query()
                ->where('facultad_id', $this->facultad())
                ->whereHas('roles', fn ($q) => $q->where('rol_id', Rol::Asesor->value))
                ->whereKey($id)
                ->exists(),
            'estudiantes' => Usuario::withTrashed()
                ->where('facultad_id', $this->facultad())
                ->whereHas('roles', fn ($q) => $q->where('rol_id', Rol::Estudiante->value))
                ->whereKey($id)
                ->exists(),
            'materias' => Materia::query()
                ->join('licenciaturas', 'licenciaturas.id', '=', 'materias.licenciatura_id')
                ->where('licenciaturas.facultad_id', $this->facultad())
                ->where('materias.id', $id)
                ->exists(),
            'grupos' => Grupo::query()
                ->join('licenciaturas', 'licenciaturas.id', '=', 'grupos.licenciatura_id')
                ->where('licenciaturas.facultad_id', $this->facultad())
                ->where('grupos.id', $id)
                ->exists(),
            'licenciaturas' => Licenciatura::query()->where('facultad_id', $this->facultad())->whereKey($id)->exists(),
        };
    }

    private function etiquetaEstatus(string $estatus): string
    {
        return match ($estatus) {
            EstatusAsesoria::EnCurso->value => 'En curso',
            EstatusAsesoria::Completada->value => 'Completada',
            EstatusAsesoria::Cancelada->value => 'Cancelada',
            default => $estatus,
        };
    }

    // Facultad del contexto (el admin siempre trabaja en la suya; el super admin en la del header)
    private function facultad(): ?int
    {
        return app(ContextoFacultad::class)->id();
    }
}