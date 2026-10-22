<?php

namespace App\Services;

use App\Enums\EstatusAsesoria;
use App\Models\Asesoria;
use App\Models\Usuario;
use Illuminate\Pagination\LengthAwarePaginator;

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

   
}