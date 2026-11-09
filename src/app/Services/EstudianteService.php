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
use App\Support\ContextoFacultad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EstudianteService
{
    // Motivo que ve el estudiante cuando su solicitud pendiente se rechaza porque dieron de baja a su asesor
    private const MOTIVO_BAJA = 'El asesor ya no está disponible.';

    // Campos de actualizar() que se muestran en la bitácora (nunca el valor de la contraseña)
    private const CAMPOS_EDITABLES = [
        'nombre' => 'nombre',
        'apellido_paterno' => 'apellido paterno',
        'apellido_materno' => 'apellido materno',
        'correo' => 'correo',
        'numero_cuenta' => 'número de cuenta',
        'num_cel' => 'teléfono',
        'grupo_id' => 'grupo',
        'password' => 'contraseña',
    ];

    public function __construct(private CierreDeSesiones $sesiones)
    {
    }

    // "Qué estudiantes puede ver este asesor". Devuelve el Builder SIN ejecutar
    public function visibles(Usuario $asesor): Builder
    {
        return Usuario::query()
            ->where('facultad_id', $asesor->facultad_id)
            ->where('estatus', EstatusUsuario::Activo->value)
            // Nunca el propio asesor (asesor par)
            ->where('id', '!=', $asesor->id)
            ->whereHas('roles', fn (Builder $q) => $q->where('rol_id', Rol::Estudiante->value));
    }

    // "Qué estudiantes ve el admin": todos los activos de la facultad en contexto
    // (la del admin, o la que eligió el super admin con X-Facultad-Id).
    // Usuario no tiene FacultadScope, por eso la facultad se filtra a mano.
    // Un estudiante dado de baja (inactivo) deja de aparecer aquí.
    public function enFacultad(): Builder
    {
        return Usuario::query()
            ->where('facultad_id', app(ContextoFacultad::class)->id())
            ->where('estatus', EstatusUsuario::Activo->value)
            ->whereHas('roles', fn (Builder $q) => $q->where('rol_id', Rol::Estudiante->value));
    }

    // Un estudiante visible para este asesor, o 404 (no existe, otra facultad,
    // sin rol de estudiante, inactivo o el propio asesor: misma respuesta)
    public function encontrar(Usuario $asesor, int $id): Usuario
    {
        return $this->visibles($asesor)->findOrFail($id);
    }

    // Un estudiante de la facultad en contexto, o 404 (no existe, otra facultad,
    // sin rol de estudiante o dado de baja: misma respuesta). Trae grupo y licenciatura.
    public function encontrarEnFacultad(int $id): Usuario
    {
        return $this->enFacultad()->with('grupo.licenciatura')->findOrFail($id);
    }

    // Lista para el autocompletado de "Crear asesoría" (vista del asesor).
    // $buscar filtra por nombre completo (nombre + apellidos) o por número de cuenta.
    public function buscar(Usuario $asesor, ?string $buscar): LengthAwarePaginator
    {
        return $this->listar($this->visibles($asesor), $buscar);
    }

    // Lo mismo para el admin: toda la facultad en contexto.
    // $filtros: buscar (nombre, número de cuenta o correo), licenciatura_id y grupo_id.
    public function buscarEnFacultad(array $filtros = []): LengthAwarePaginator
    {
        $consulta = $this->enFacultad()
            ->with('grupo.licenciatura')
            ->when($filtros['grupo_id'] ?? null, fn (Builder $q, $id) => $q->where('grupo_id', $id))
            ->when(
                $filtros['licenciatura_id'] ?? null,
                fn (Builder $q, $id) => $q->whereHas('grupo', fn (Builder $g) => $g->where('licenciatura_id', $id))
            );

        // El correo solo se busca desde el panel del admin: al asesor no se le muestra
        return $this->listar($consulta, $filtros['buscar'] ?? null, true);
    }

    // El admin da de alta a un estudiante: nace ACTIVO, con la contraseña que el admin le pone
    public function crear(array $datos): Usuario
    {
        return DB::transaction(function () use ($datos) {
            $estudiante = Usuario::create([
                'facultad_id' => app(ContextoFacultad::class)->id(),
                'estatus' => EstatusUsuario::Activo,
                'nombre' => $datos['nombre'],
                'apellido_paterno' => $datos['apellido_paterno'],
                'apellido_materno' => $datos['apellido_materno'] ?? null,
                'correo' => $datos['correo'],
                'password' => $datos['password'],
                'numero_cuenta' => $datos['numero_cuenta'],
                'num_cel' => $datos['num_cel'] ?? null,
                'grupo_id' => $datos['grupo_id'],
            ]);

            $estudiante->asignarRol(Rol::Estudiante);
            $estudiante->load('grupo.licenciatura');

            Bitacora::registrar(AccionBitacora::EstudianteCreado, $estudiante, [
                'etiqueta' => $estudiante->nombreCompleto(),
                'detalle' => "Se dio de alta al estudiante {$estudiante->correo}.",
            ]);

            return $estudiante;
        });
    }

    // El admin edita todo: nombre, apellidos, correo, cuenta, teléfono, grupo y (si manda una) la contraseña.
    // Si cambia la contraseña se cierran sus sesiones.
    public function actualizar(Usuario $estudiante, array $datos): Usuario
    {
        return DB::transaction(function () use ($estudiante, $datos) {
            $cambiaPassword = ! empty($datos['password']);

            $estudiante->fill(Arr::only($datos, [
                'nombre', 'apellido_paterno', 'apellido_materno',
                'correo', 'numero_cuenta', 'num_cel', 'grupo_id',
            ]));

            if ($cambiaPassword) {
                $estudiante->password = $datos['password'];
            }

            $estudiante->save();

            if ($cambiaPassword) {
                $this->sesiones->cerrar([$estudiante->id]);
            }

            // Solo se registra si algo cambió de verdad
            $campos = collect($estudiante->getChanges())->keys()
                ->map(fn ($c) => self::CAMPOS_EDITABLES[$c] ?? null)
                ->filter()->unique()->implode(', ');

            if ($campos !== '') {
                Bitacora::registrar(AccionBitacora::EstudianteEditado, $estudiante, [
                    'etiqueta' => $estudiante->nombreCompleto(),
                    'detalle' => "Se editó al estudiante {$estudiante->correo}: cambió {$campos}.",
                ]);
            }

            return $estudiante->load('grupo.licenciatura');
        });
    }

    // Baja: el usuario pasa a INACTIVO (no se borra: conserva su historial de asesorías, sesiones y chats).
    // Deja de poder entrar y se cierran sus sesiones. Se bloquea si tiene asesorías en curso.
    public function darDeBaja(Usuario $estudiante): void
    {
        DB::transaction(function () use ($estudiante) {
            // Se relee la fila bloqueada: la que llegó puede estar vieja
            $actual = Usuario::whereKey($estudiante->id)->lockForUpdate()->firstOrFail();

            // En curso como estudiante o, si es asesor par, como asesor
            $enCurso = Asesoria::query()
                ->where('estatus', EstatusAsesoria::EnCurso)
                ->where(fn ($q) => $q->where('estudiante_id', $actual->id)->orWhere('asesor_id', $actual->id))
                ->exists();

            if ($enCurso) {
                throw ErrorDeNegocio::conflicto(
                    'El estudiante tiene asesorías en curso. Complétalas o cancélalas antes de darlo de baja.',
                    'estudiante_con_asesorias_en_curso'
                );
            }

            // Sus solicitudes pendientes quedarían sin respuesta: las que pidió se cancelan
            // y, si también es asesor, las que le pidieron se rechazan
            $canceladas = Solicitud::where('estatus', EstatusSolicitud::Pendiente)
                ->where('estudiante_id', $actual->id)
                ->update([
                    'estatus' => EstatusSolicitud::Cancelada->value,
                    'respondida_en' => now(),
                ]);

            Solicitud::where('estatus', EstatusSolicitud::Pendiente)
                ->where('asesor_id', $actual->id)
                ->update([
                    'estatus' => EstatusSolicitud::Rechazada->value,
                    'motivo_rechazo' => self::MOTIVO_BAJA,
                    'respondida_en' => now(),
                ]);

            $actual->update(['estatus' => EstatusUsuario::Inactivo]);

            $this->sesiones->cerrar([$actual->id]);

            $detalle = "Se dio de baja al estudiante {$actual->correo}.";
            if ($canceladas > 0) {
                $detalle .= " Se cancelaron {$canceladas} solicitud(es) pendiente(s).";
            }

            Bitacora::registrar(AccionBitacora::EstudianteDadoDeBaja, $actual, [
                'etiqueta' => $actual->nombreCompleto(),
                'detalle' => $detalle,
            ]);
        });
    }

    private function listar(Builder $consulta, ?string $buscar, bool $porCorreo = false): LengthAwarePaginator
    {
        return $consulta
            // El Resource muestra el nombre del grupo: se carga de golpe (evita N+1)
            ->with('grupo')
            ->when($buscar, function (Builder $consulta) use ($buscar, $porCorreo) {
                // Los OR van agrupados: sin el grupo se saltarían los filtros de la consulta base
                $consulta->where(function (Builder $q) use ($buscar, $porCorreo) {
                    // CONCAT_WS une nombre y apellidos con espacio: "Luis Pérez" encuentra a Luis Pérez Gámez
                    $q->whereRaw("CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) like ?", ["%{$buscar}%"])
                      ->orWhere('numero_cuenta', 'like', "%{$buscar}%");

                    if ($porCorreo) {
                        $q->orWhere('correo', 'like', "%{$buscar}%");
                    }
                });
            })
            ->orderBy('apellido_paterno')
            ->orderBy('apellido_materno')
            ->orderBy('nombre')
            // El id desempata: la paginación no repite ni salta alumnos con el mismo nombre
            ->orderBy('id')
            ->paginate(10);
    }
}