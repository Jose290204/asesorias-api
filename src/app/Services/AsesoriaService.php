<?php

namespace App\Services;

use App\Enums\EstatusAsesoria;
use App\Models\Asesoria;
use App\Models\Usuario;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Exceptions\ErrorDeNegocio;
use Illuminate\Support\Facades\DB;

class AsesoriaService
{
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
            ->withCount('sesiones')
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

            // Relaciones que el Resource usa (como en la lista del asesor) y el conteo de sesiones
            return $actual->load(['estudiante', 'materia', 'horario'])->loadCount('sesiones');
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

            return $this->paraRespuesta($actual);
        });
    }

    // Relee la fila y la BLOQUEA hasta que termine la transacción; el $asesoria que llegó
    // puede estar viejo. Así completar y cancelar simultáneos se hacen una tras otra y
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

   
}