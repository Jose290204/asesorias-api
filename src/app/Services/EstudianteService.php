<?php

namespace App\Services;

use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class EstudianteService
{
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

    // Un estudiante visible para este asesor, o 404 (no existe, otra facultad,
    // sin rol de estudiante, inactivo o el propio asesor: misma respuesta)
    public function encontrar(Usuario $asesor, int $id): Usuario
    {
        return $this->visibles($asesor)->findOrFail($id);
    }

    // Lista para el autocompletado de "Crear asesoría".
    // $buscar filtra por nombre completo (nombre + apellidos) o por número de cuenta.
    public function buscar(Usuario $asesor, ?string $buscar): LengthAwarePaginator
    {
        return $this->visibles($asesor)
            // El Resource muestra el nombre del grupo: se carga de golpe (evita N+1)
            ->with('grupo')
            ->when($buscar, function (Builder $consulta) use ($buscar) {
                // Los OR van agrupados: sin el grupo se saltarían los filtros de visibles()
                $consulta->where(function (Builder $q) use ($buscar) {
                    // CONCAT_WS une nombre y apellidos con espacio: "Luis Pérez" encuentra a Luis Pérez Gámez
                    $q->whereRaw("CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) like ?", ["%{$buscar}%"])
                      ->orWhere('numero_cuenta', 'like', "%{$buscar}%");
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