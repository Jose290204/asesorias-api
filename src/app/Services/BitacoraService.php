<?php

namespace App\Services;

use App\Models\Bitacora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class BitacoraService
{
    // Super admin: ve la bitácora de todas las facultades y puede filtrar por una
    public function listar(array $filtros): LengthAwarePaginator
    {
        $consulta = Bitacora::withoutGlobalScopes()
            ->when($filtros['facultad_id'] ?? null, fn ($q, $id) => $q->where('facultad_id', $id));

        return $this->paginar($consulta, $filtros);
    }

    // Admin: solo la facultad activa. Aquí SÍ aplica el FacultadScope de Bitacora (por eso no hay
    // withoutGlobalScopes) y se ignora "facultad_id": un admin no puede mirar otra facultad.
    public function listarDeFacultad(array $filtros): LengthAwarePaginator
    {
        return $this->paginar(Bitacora::query(), $filtros);
    }

    // Filtros y orden que comparten las dos bitácoras
    private function paginar(Builder $consulta, array $filtros): LengthAwarePaginator
    {
        return $consulta
            // withTrashed: un administrador eliminado sigue apareciendo con su nombre
            ->with(['usuario' => fn ($q) => $q->withTrashed()])
            ->when($filtros['accion'] ?? null, fn ($q, $accion) => $q->where('accion', $accion))
            // Los días se interpretan en la zona horaria de la app; "hasta" incluye todo ese día
            ->when($filtros['desde'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filtros['hasta'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()))
            ->when($filtros['buscar'] ?? null, function ($q, $texto) {
                $like = "%{$texto}%";

                $q->where(function ($g) use ($like) {
                    $g->where('detalles->etiqueta', 'like', $like)
                        ->orWhere('detalles->detalle', 'like', $like)
                        ->orWhere('ip', 'like', $like)
                        ->orWhereHas('usuario', function ($u) use ($like) {
                            $u->withTrashed()->where(function ($n) use ($like) {
                                $n->whereRaw("CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) like ?", [$like])
                                    ->orWhere('correo', 'like', $like);
                            });
                        });
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filtros['per_page'] ?? 10);
    }
}