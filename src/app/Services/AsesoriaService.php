<?php

namespace App\Services;

use App\Enums\Dia;
use App\Enums\EstatusAsesoria;
use App\Exceptions\ErrorDeNegocio;
use App\Models\Asesoria;
use App\Models\CicloEscolar;
use App\Models\DisponibilidadHorario;
use App\Models\Usuario;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Enums\AccionBitacora;
use App\Models\Bitacora;
use Illuminate\Support\Str;

class AsesoriaService
{
    public function __construct(private EstudianteService $estudiantes)
    {
    }

    // $como = 'estudiante' | 'asesor': decide de qué lado se filtra la lista
    public function listar(Usuario $usuario, string $como, ?string $estatus = null, ?string $buscar = null): LengthAwarePaginator
    {
        // Estudiante: las que él tomó. Asesor: las que él imparte.
        $columna = $como === 'asesor' ? 'asesor_id' : 'estudiante_id';
        // La "otra persona" que el Resource debe mostrar
        $otro = $como === 'asesor' ? 'estudiante' : 'asesor';

        return Asesoria::query()
            ->where($columna, $usuario->id)
            // Relaciones del Resource cargadas de golpe (evita N+1)
            ->with(['materia', 'horario', $otro])
            // Agrega sesiones_count sin cargar las sesiones
            ->withCount([
                'sesiones',
                'chats as mensajes_sin_leer_count' => fn ($q) => $q
                    ->where('usuario_id', '!=', $usuario->id)
                    ->whereNull('leido_en'),
            ])
            // Atajo "historial": completadas + canceladas
            ->when(
                $estatus === 'historial',
                fn ($q) => $q->whereIn('estatus', [EstatusAsesoria::Completada, EstatusAsesoria::Cancelada])
            )
            // Un estatus concreto del enum
            ->when(
                $estatus && $estatus !== 'historial',
                fn ($q) => $q->where('estatus', $estatus)
            )
            ->when($buscar, function ($q) use ($buscar, $otro) {
                // Los OR van agrupados: sin el grupo se saltarían el filtro del usuario
                $q->where(function ($q) use ($buscar, $otro) {
                    $q->whereHas('materia', fn ($m) => $m->where('nombre', 'like', "%{$buscar}%"))
                      ->orWhereHas($otro, fn ($u) => $u->whereRaw(
                          "CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) like ?",
                          ["%{$buscar}%"]
                      ));
                });
            })
            // Más recientes primero; el id desempata
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->paginate(10);
    }

        // Lista de la facultad en contexto para el administrador: no filtra por asesor ni
    // por estudiante, así que carga a los dos. El filtro de facultad lo pone el scope del modelo.
    // $filtros viene de $request->validated(): estatus, buscar, asesor_id, materia_id, ciclo_id.
    public function listarTodas(array $filtros): LengthAwarePaginator
    {
        $estatus = $filtros['estatus'] ?? null;

        return Asesoria::query()
            ->with(['materia', 'horario', 'asesor', 'estudiante'])
            ->withCount('sesiones')
            ->when(
                $estatus === 'historial',
                fn ($q) => $q->whereIn('estatus', [EstatusAsesoria::Completada, EstatusAsesoria::Cancelada])
            )
            ->when(
                $estatus && $estatus !== 'historial',
                fn ($q) => $q->where('estatus', $estatus)
            )
            ->when($filtros['asesor_id'] ?? null, fn ($q, $id) => $q->where('asesor_id', $id))
            ->when($filtros['materia_id'] ?? null, fn ($q, $id) => $q->where('materia_id', $id))
            ->when($filtros['ciclo_id'] ?? null, fn ($q, $id) => $q->where('ciclo_escolar_id', $id))
            // La licenciatura de una asesoría es la de su materia
            ->when($filtros['licenciatura_id'] ?? null, fn ($q, $id) => $q->whereHas(
                'materia',
                fn ($m) => $m->where('licenciatura_id', $id)
            ))
            ->when($filtros['grupo_id'] ?? null, fn ($q, $id) => $q->where('grupo_id', $id))
            ->when($filtros['modalidad'] ?? null, fn ($q, $valor) => $q->where('modalidad', $valor))
            ->when($filtros['horario_id'] ?? null, fn ($q, $id) => $q->where('horario_id', $id))
            ->when($filtros['razon'] ?? null, fn ($q, $valor) => $q->where('razon', $valor))
            ->when($filtros['buscar'] ?? null, function ($q, $buscar) {
                // Los OR van agrupados: sin el grupo se saltarían los demás filtros
                $q->where(function ($q) use ($buscar) {
                    $q->whereHas('materia', fn ($m) => $m->where('nombre', 'like', "%{$buscar}%"))
                      ->orWhereHas('asesor', fn ($u) => $u->whereRaw(
                          "CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) like ?",
                          ["%{$buscar}%"]
                      ))
                      ->orWhereHas('estudiante', fn ($u) => $u->whereRaw(
                          "CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) like ?",
                          ["%{$buscar}%"]
                      ));
                });
            })
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->paginate(10);
    }

    // El asesor da de alta una asesoría directa (sin solicitud). Nace "en_curso".
    public function crear(Usuario $asesor, array $datos): Asesoria
    {
        // 404 si el estudiante no es visible para este asesor
        $estudiante = $this->estudiantes->encontrar($asesor, (int) $datos['estudiante_id']);

        return DB::transaction(function () use ($asesor, $estudiante, $datos) {
            // Se bloquea la fila del asesor (mismo orden que SolicitudService::aceptar):
            // dos altas simultáneas del mismo cupo se hacen una tras otra.
            // Las disponibilidades se leen ya dentro del lock, frescas.
            $asesor = Usuario::whereKey($asesor->id)
                ->with(['disponibilidadMaterias', 'disponibilidadModalidades'])
                ->lockForUpdate()
                ->firstOrFail();

            $ciclo = CicloEscolar::vigente()->first();

            if (! $ciclo) {
                throw ErrorDeNegocio::conflicto('No hay un ciclo escolar vigente.', 'sin_ciclo');
            }

            $fecha = Carbon::parse($datos['fecha_inicio'])->startOfDay();

            if ($fecha->lt($ciclo->fecha_inicio) || $fecha->gt($ciclo->fecha_fin)) {
                throw ErrorDeNegocio::invalido('La fecha queda fuera del ciclo escolar vigente.', 'fuera_de_ciclo');
            }

            $this->validarQueOfrece($asesor, $datos);
            $this->validarHorarioLibre($asesor->id, Dia::desdeFecha($fecha), (int) $datos['horario_id']);

            $asesoria = Asesoria::create([
                'facultad_id' => $asesor->facultad_id,
                'ciclo_escolar_id' => $ciclo->id,
                'grupo_id' => $estudiante->grupo_id,
                'solicitud_id' => null,
                'estudiante_id' => $estudiante->id,
                'asesor_id' => $asesor->id,
                'materia_id' => $datos['materia_id'],
                'horario_id' => $datos['horario_id'],
                'fecha_inicio' => $fecha,
                'modalidad' => $datos['modalidad'],
                'razon' => $datos['razon'],
                'estatus' => EstatusAsesoria::EnCurso,
                'observaciones' => $datos['observaciones'] ?? null,
            ]);

            $respuesta = $this->paraRespuesta($asesoria);

            $this->bitacora(
                AccionBitacora::AsesoriaCreada,
                $respuesta,
                "Creó una asesoría para {$respuesta->estudiante->nombreCompleto()} con inicio el {$respuesta->fecha_inicio->format('d/m/Y')}."
            );

            return $respuesta;
        });
    }

    // El asesor edita una asesoría en curso: fecha de inicio, horario, modalidad, razón y observaciones.
    // $datos viene de $request->validated(): solo trae las llaves que el cliente mandó.
    public function editar(Asesoria $asesoria, array $datos): Asesoria
    {
        return DB::transaction(function () use ($asesoria, $datos) {
            // Mismo orden de bloqueos que crear y SolicitudService::aceptar: primero la fila
            // del asesor y luego la asesoría. Así dos cambios del mismo cupo se hacen en fila.
            Usuario::whereKey($asesoria->asesor_id)->lockForUpdate()->firstOrFail();

            // 409 antes que cualquier validación de datos: una cerrada ya no se toca
            $actual = $this->bloquearEnCurso($asesoria, 'editar');

            $cambios = Arr::only($datos, ['fecha_inicio', 'horario_id', 'modalidad', 'razon', 'observaciones']);

            // Valores finales: lo que llegó, o lo que ya tenía la asesoría
            $fecha = isset($cambios['fecha_inicio'])
                ? Carbon::parse($cambios['fecha_inicio'])->startOfDay()
                : $actual->fecha_inicio;
            $horarioId = (int) ($cambios['horario_id'] ?? $actual->horario_id);

            // La fecha solo se revisa contra el ciclo y las sesiones si realmente cambia
            if (isset($cambios['fecha_inicio']) && ! $fecha->equalTo($actual->fecha_inicio)) {
                $ciclo = $this->validarFechaInicio($actual, $fecha);

                $cambios['ciclo_escolar_id'] = $ciclo->id;
            }

            // El slot es día de la semana + horario. Solo se revalida si alguno de los dos cambia:
            // mover la fecha a otra semana con el mismo día, o tocar solo observaciones,
            // no debe fallar porque el asesor ya no ofrezca ese horario.
            $diaActual = Dia::desdeFecha($actual->fecha_inicio);
            $diaNuevo = Dia::desdeFecha($fecha);

            if ($horarioId !== $actual->horario_id || $diaNuevo !== $diaActual) {
                // Se ignora a sí misma: no puede chocar con su propio cupo
                $this->validarHorarioLibre($actual->asesor_id, $diaNuevo, $horarioId, $actual->id);
            }

            $actual->update($cambios);

            $respuesta = $this->paraRespuesta($actual);

            $campos = collect($actual->getChanges())->keys()
                ->map(fn ($c) => self::CAMPOS_EDITABLES[$c] ?? null)
                ->filter()->unique()->implode(', ');

            if ($campos !== '') {
                $this->bitacora(AccionBitacora::AsesoriaEditada, $respuesta, "Editó la asesoría de {$respuesta->estudiante->nombreCompleto()}: cambió {$campos}.");
            }

            return $respuesta;
        });
    }

    // El asesor da por terminada una asesoría en curso.
    public function completar(Asesoria $asesoria): Asesoria
    {
        // Todo en una transacción
        return DB::transaction(function () use ($asesoria) {

            // Vuelve a leer la fila y la BLOQUEA hasta que termine la transacción.
            // Si en el mismo instante se está cancelando, esta línea espera su turno
            // y después ve el estatus ya cambiado
            $actual = Asesoria::whereKey($asesoria->id)->lockForUpdate()->firstOrFail();

            // Solo se completan las que siguen en curso (409 si ya estaba completada o cancelada)
            if ($actual->estatus !== EstatusAsesoria::EnCurso) {
                throw ErrorDeNegocio::conflicto('Solo se pueden completar asesorías en curso.', 'asesoria_no_en_curso');
            }

            // No se borra nada: se cambia el estatus y se anota cuándo terminó
            $actual->update([
                'estatus' => EstatusAsesoria::Completada,
                'finalizada_en' => now(),
            ]);

            $respuesta = $this->paraRespuesta($actual);

            $this->bitacora(AccionBitacora::AsesoriaCompletada, $respuesta, "Completó la asesoría de {$respuesta->estudiante->nombreCompleto()}.");

            return $respuesta;
        });
    }

    // El asesor cancela una asesoría en curso y deja el motivo.
    // No se borra (el estudiante la ve en su historial como "Cancelada"
    // y las sesiones ya registradas se conservan).
    public function cancelar(Asesoria $asesoria, string $motivo): Asesoria
    {
        return DB::transaction(function () use ($asesoria, $motivo) {
            $actual = $this->bloquearEnCurso($asesoria, 'cancelar');

            // finalizada_en se queda en null: esa fecha es solo de las completadas
            $actual->update([
                'estatus' => EstatusAsesoria::Cancelada,
                'motivo_cancelacion' => $motivo,
            ]);

            $respuesta = $this->paraRespuesta($actual);

            $this->bitacora(
                AccionBitacora::AsesoriaCancelada,
                $respuesta,
                "Canceló la asesoría de {$respuesta->estudiante->nombreCompleto()}. Motivo: ".Str::limit($motivo, 120)
            );

            return $respuesta;
        });
    }

    // ---------- Ayudantes privados ----------

    // El asesor debe ofrecer esa materia y esa modalidad
    private function validarQueOfrece(Usuario $asesor, array $datos): void
    {
        $daMateria = $asesor->disponibilidadMaterias
            ->contains(fn ($fila) => $fila->materia_id === (int) $datos['materia_id']);

        if (! $daMateria) {
            throw ErrorDeNegocio::invalido('No impartes esa materia.', 'materia_no_ofrecida');
        }

        $daModalidad = $asesor->disponibilidadModalidades
            ->contains(fn ($fila) => $fila->modalidad->value === $datos['modalidad']);

        if (! $daModalidad) {
            throw ErrorDeNegocio::invalido('No ofreces esa modalidad.', 'modalidad_no_ofrecida');
        }
    }

    // La nueva fecha de inicio debe caer dentro del ciclo vigente y no pasar de la primera sesión
    // ya registrada (no tiene sentido que la asesoría "empiece" después de haber dado sesión).
    // Devuelve el ciclo vigente para que editar() actualice ciclo_escolar_id.
    private function validarFechaInicio(Asesoria $asesoria, Carbon $fecha): CicloEscolar
    {
        $ciclo = CicloEscolar::vigente()->first();

        if (! $ciclo) {
            throw ErrorDeNegocio::conflicto('No hay un ciclo escolar vigente.', 'sin_ciclo');
        }

        if ($fecha->lt($ciclo->fecha_inicio) || $fecha->gt($ciclo->fecha_fin)) {
            throw ErrorDeNegocio::invalido('La fecha queda fuera del ciclo escolar vigente.', 'fuera_de_ciclo');
        }

        // min() sobre la relación devuelve la fecha más antigua como texto, o null si no hay sesiones
        $primera = $asesoria->sesiones()->min('fecha');

        if ($primera !== null && $fecha->gt(Carbon::parse($primera))) {
            throw ErrorDeNegocio::invalido(
                'La fecha de inicio no puede ser posterior a la primera sesión registrada.',
                'fecha_posterior_a_sesiones'
            );
        }

        return $ciclo;
    }

    // Día laborable + horario que el asesor ofrece + cupo no ocupado por otra asesoría en curso.
    // $ignorarAsesoriaId se usa al editar, para no chocar consigo misma.
    private function validarHorarioLibre(int $asesorId, ?Dia $dia, int $horarioId, ?int $ignorarAsesoriaId = null): void
    {
        // Sábado o domingo: nadie ofrece esos días
        if ($dia === null) {
            throw ErrorDeNegocio::invalido('Ese día no se ofrecen asesorías.', 'dia_no_disponible');
        }

        $ofrece = DisponibilidadHorario::where('asesor_id', $asesorId)
            ->where('dia', $dia->value)
            ->where('horario_id', $horarioId)
            ->exists();

        if (! $ofrece) {
            throw ErrorDeNegocio::invalido('No tienes ese horario disponible ese día.', 'horario_no_disponible');
        }

        // El cupo es día de la semana + horario: se calcula, nunca se guarda
        $ocupado = Asesoria::where('asesor_id', $asesorId)
            ->where('horario_id', $horarioId)
            ->where('estatus', EstatusAsesoria::EnCurso)
            ->when($ignorarAsesoriaId, fn ($q) => $q->where('id', '!=', $ignorarAsesoriaId))
            ->pluck('fecha_inicio')
            ->contains(fn ($fecha) => Dia::desdeFecha($fecha) === $dia);

        if ($ocupado) {
            throw ErrorDeNegocio::conflicto('Ya tienes otra asesoría en curso en ese horario.', 'horario_ocupado');
        }
    }

    // Relee la fila y la BLOQUEA hasta que termine la transacción; el $asesoria que llegó
    // puede estar viejo. Así completar, cancelar y editar simultáneos se hacen una tras otra y
    // el segundo ve el estatus ya cambiado.
    // OJO: solo bloquea si se llama dentro de DB::transaction().
    private function bloquearEnCurso(Asesoria $asesoria, string $accion): Asesoria
    {
        $actual = Asesoria::whereKey($asesoria->id)->lockForUpdate()->firstOrFail();

        if ($actual->estatus !== EstatusAsesoria::EnCurso) {
            throw ErrorDeNegocio::conflicto("Solo se pueden {$accion} asesorías en curso.", 'asesoria_no_en_curso');
        }

        return $actual;
    }

    // Relaciones que el Resource usa (como en la lista del asesor) y el conteo de sesiones
    private function paraRespuesta(Asesoria $asesoria): Asesoria
    {
        return $asesoria->load(['estudiante', 'materia', 'horario'])->loadCount('sesiones');
    }

    private const CAMPOS_EDITABLES = [
        'fecha_inicio' => 'fecha de inicio',
        'horario_id' => 'horario',
        'modalidad' => 'modalidad',
        'razon' => 'razón',
        'observaciones' => 'observaciones',
    ];

    // La asesoría debe traer cargada la materia (paraRespuesta la carga)
    private function bitacora(AccionBitacora $accion, Asesoria $asesoria, string $detalle): void
    {
        Bitacora::registrar($accion, $asesoria, [
            'etiqueta' => "Asesoría de {$asesoria->materia->nombre}",
            'detalle' => $detalle,
        ]);
    }
}