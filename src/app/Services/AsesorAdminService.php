<?php

namespace App\Services;

use App\Enums\AccionBitacora;
use App\Enums\EstatusAsesoria;
use App\Enums\EstatusSolicitud;
use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Exceptions\ErrorDeNegocio;
use App\Models\Asesoria;
use App\Models\Bitacora;
use App\Models\Solicitud;
use App\Models\Usuario;
use App\Models\UsuarioRol;
use App\Support\ContextoFacultad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

// Gestión de asesores desde el panel del admin :
//  - DISCIPLINAR: tiene el rol asesor y NO el de estudiante. El admin lo da de alta, lo edita y lo da de baja.
//  - PAR: tiene los dos roles. Sus datos personales son los del estudiante (se editan en Estudiantes);
//    aquí solo se le asigna o se le quita el rol de asesor.
class AsesorAdminService
{
    private const MOTIVO_BAJA = 'El asesor ya no está disponible.';

    // Lo que necesita DisponibilidadResource (igual que AsesorService)
    private const RELACIONES = [
        'disponibilidadMaterias.materia.licenciatura',
        'disponibilidadHorarios.horario',
        'disponibilidadModalidades',
    ];

    private const CAMPOS_EDITABLES = [
        'nombre' => 'nombre',
        'apellido_paterno' => 'apellido paterno',
        'apellido_materno' => 'apellido materno',
        'correo' => 'correo',
        'numero_cuenta' => 'número de cuenta',
        'num_cel' => 'teléfono',
        'password' => 'contraseña',
    ];

    public function __construct(private CierreDeSesiones $sesiones)
    {
    }

    // ---------- Consultas base ----------

    // Activos de la facultad en contexto. Usuario no tiene FacultadScope: se filtra a mano.
    private function activosEnFacultad(): Builder
    {
        return Usuario::query()
            ->where('facultad_id', app(ContextoFacultad::class)->id())
            ->where('estatus', EstatusUsuario::Activo->value);
    }

    private function conRol(Builder $q, Rol $rol): Builder
    {
        return $q->whereHas('roles', fn (Builder $r) => $r->where('rol_id', $rol->value));
    }

    private function sinRol(Builder $q, Rol $rol): Builder
    {
        return $q->whereDoesntHave('roles', fn (Builder $r) => $r->where('rol_id', $rol->value));
    }

    public function disciplinares(): Builder
    {
        return $this->sinRol($this->conRol($this->activosEnFacultad(), Rol::Asesor), Rol::Estudiante);
    }

    public function pares(): Builder
    {
        return $this->conRol($this->conRol($this->activosEnFacultad(), Rol::Asesor), Rol::Estudiante);
    }

    // Un disciplinar activo de la facultad, o 404 (no existe, otra facultad, es par, estudiante, inactivo...)
    public function encontrarDisciplinar(int $id): Usuario
    {
        return $this->disciplinares()->with(self::RELACIONES)->findOrFail($id);
    }

    public function encontrarPar(int $id): Usuario
    {
        return $this->pares()->with(self::RELACIONES)->findOrFail($id);
    }

    // ---------- Listados ----------

    // $filtros: buscar (nombre, correo o número de cuenta) y materia_id
    public function listarDisciplinares(array $filtros = []): LengthAwarePaginator
    {
        return $this->listar($this->disciplinares(), $filtros);
    }

    public function listarPares(array $filtros = []): LengthAwarePaginator
    {
        return $this->listar($this->pares(), $filtros);
    }

    // Estudiantes activos de la facultad que todavía NO son asesores (candidatos a par)
    public function candidatosPar(?string $buscar): LengthAwarePaginator
    {
        $consulta = $this->sinRol($this->conRol($this->activosEnFacultad(), Rol::Estudiante), Rol::Asesor)
            ->with('grupo.licenciatura');

        return $this->ordenar($this->buscar($consulta, $buscar))->paginate(10);
    }

    // ---------- Disciplinares: alta, edición y baja ----------

    public function crear(array $datos): Usuario
    {
        return DB::transaction(function () use ($datos) {
            $asesor = Usuario::create([
                'facultad_id' => app(ContextoFacultad::class)->id(),
                'estatus' => EstatusUsuario::Activo,
                'nombre' => $datos['nombre'],
                'apellido_paterno' => $datos['apellido_paterno'],
                'apellido_materno' => $datos['apellido_materno'] ?? null,
                'correo' => $datos['correo'],
                'password' => $datos['password'],
                'numero_cuenta' => $datos['numero_cuenta'] ?? null,
                'num_cel' => $datos['num_cel'] ?? null,
            ]);

            $asesor->asignarRol(Rol::Asesor);

            Bitacora::registrar(AccionBitacora::AsesorCreado, $asesor, [
                'etiqueta' => $asesor->nombreCompleto(),
                'detalle' => "Se dio de alta al asesor disciplinar {$asesor->correo}.",
            ]);

            return $asesor->load(self::RELACIONES);
        });
    }

    public function actualizar(Usuario $asesor, array $datos): Usuario
    {
        return DB::transaction(function () use ($asesor, $datos) {
            $cambiaPassword = ! empty($datos['password']);

            $asesor->fill(Arr::only($datos, [
                'nombre', 'apellido_paterno', 'apellido_materno',
                'correo', 'numero_cuenta', 'num_cel',
            ]));

            if ($cambiaPassword) {
                $asesor->password = $datos['password'];
            }

            $asesor->save();

            if ($cambiaPassword) {
                $this->sesiones->cerrar([$asesor->id]);
            }

            $campos = collect($asesor->getChanges())->keys()
                ->map(fn ($c) => self::CAMPOS_EDITABLES[$c] ?? null)
                ->filter()->unique()->implode(', ');

            if ($campos !== '') {
                Bitacora::registrar(AccionBitacora::AsesorEditado, $asesor, [
                    'etiqueta' => $asesor->nombreCompleto(),
                    'detalle' => "Se editó al asesor {$asesor->correo}: cambió {$campos}.",
                ]);
            }

            return $asesor->load(self::RELACIONES);
        });
    }

    // Baja: pasa a INACTIVO (conserva su historial), se cierran sus sesiones y se rechazan
    // las solicitudes pendientes que tenía. 409 si tiene asesorías en curso.
    public function darDeBaja(Usuario $asesor): void
    {
        DB::transaction(function () use ($asesor) {
            $actual = Usuario::whereKey($asesor->id)->lockForUpdate()->firstOrFail();

            $this->exigirSinAsesoriasEnCurso($actual, 'Complétalas o cancélalas antes de darlo de baja.');
            $rechazadas = $this->rechazarPendientes($actual);

            $actual->update(['estatus' => EstatusUsuario::Inactivo]);
            $this->sesiones->cerrar([$actual->id]);

            $detalle = "Se dio de baja al asesor {$actual->correo}.";
            if ($rechazadas > 0) {
                $detalle .= " Se rechazaron {$rechazadas} solicitud(es) pendiente(s).";
            }

            Bitacora::registrar(AccionBitacora::AsesorDadoDeBaja, $actual, [
                'etiqueta' => $actual->nombreCompleto(),
                'detalle' => $detalle,
            ]);
        });
    }

    // ---------- Asesores par ----------

    // Le da el rol de asesor a un estudiante activo de la facultad. Conserva su rol de estudiante.
    // 404 si no es un estudiante activo de la facultad; 409 si ya es asesor.
    public function asignarPar(int $usuarioId): Usuario
    {
        return DB::transaction(function () use ($usuarioId) {
            $estudiante = $this->conRol($this->activosEnFacultad(), Rol::Estudiante)
                ->lockForUpdate()
                ->findOrFail($usuarioId);

            if ($estudiante->tieneRol(Rol::Asesor)) {
                throw ErrorDeNegocio::conflicto('Ese estudiante ya es asesor.', 'ya_es_asesor');
            }

            $estudiante->asignarRol(Rol::Asesor);

            Bitacora::registrar(AccionBitacora::AsesorParAsignado, $estudiante, [
                'etiqueta' => $estudiante->nombreCompleto(),
                'detalle' => "Se asignó el rol de asesor al estudiante {$estudiante->correo}.",
            ]);

            return $estudiante->load(self::RELACIONES);
        });
    }

    // Quita solo el rol de asesor: sigue siendo estudiante y su disponibilidad queda guardada.
    public function quitarPar(Usuario $par): void
    {
        DB::transaction(function () use ($par) {
            $actual = Usuario::whereKey($par->id)->lockForUpdate()->firstOrFail();

            $this->exigirSinAsesoriasEnCurso($actual, 'Complétalas o cancélalas antes de quitarle el rol.');
            $rechazadas = $this->rechazarPendientes($actual);

            UsuarioRol::where('usuario_id', $actual->id)->where('rol_id', Rol::Asesor->value)->delete();
            $actual->unsetRelation('roles');

            $detalle = "Se quitó el rol de asesor al estudiante {$actual->correo}.";
            if ($rechazadas > 0) {
                $detalle .= " Se rechazaron {$rechazadas} solicitud(es) pendiente(s).";
            }

            Bitacora::registrar(AccionBitacora::AsesorParQuitado, $actual, [
                'etiqueta' => $actual->nombreCompleto(),
                'detalle' => $detalle,
            ]);
        });
    }

    // ---------- Internos ----------

    private function exigirSinAsesoriasEnCurso(Usuario $asesor, string $sugerencia): void
    {
        $enCurso = Asesoria::where('estatus', EstatusAsesoria::EnCurso)
            ->where('asesor_id', $asesor->id)
            ->exists();

        if ($enCurso) {
            throw ErrorDeNegocio::conflicto(
                "El asesor tiene asesorías en curso. {$sugerencia}",
                'asesor_con_asesorias_en_curso'
            );
        }
    }

    // Las solicitudes pendientes dirigidas a este asesor se rechazan con motivo. Devuelve cuántas.
    private function rechazarPendientes(Usuario $asesor): int
    {
        return Solicitud::where('estatus', EstatusSolicitud::Pendiente)
            ->where('asesor_id', $asesor->id)
            ->update([
                'estatus' => EstatusSolicitud::Rechazada->value,
                'motivo_rechazo' => self::MOTIVO_BAJA,
                'respondida_en' => now(),
            ]);
    }

    private function listar(Builder $consulta, array $filtros): LengthAwarePaginator
    {
        $consulta->when($filtros['materia_id'] ?? null, fn (Builder $q, $id) => $q->whereHas(
            'disponibilidadMaterias',
            fn (Builder $m) => $m->where('materia_id', $id)
        ));

        return $this->ordenar($this->buscar($consulta, $filtros['buscar'] ?? null))
            ->with(self::RELACIONES)
            ->paginate(10);
    }

    // Busca por nombre completo, correo o número de cuenta. Los OR van agrupados
    private function buscar(Builder $consulta, ?string $texto): Builder
    {
        return $consulta->when($texto, fn (Builder $q) => $q->where(function (Builder $w) use ($texto) {
            $w->whereRaw("CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) like ?", ["%{$texto}%"])
                ->orWhere('correo', 'like', "%{$texto}%")
                ->orWhere('numero_cuenta', 'like', "%{$texto}%");
        }));
    }

    private function ordenar(Builder $consulta): Builder
    {
        return $consulta->orderBy('id');
    }
}