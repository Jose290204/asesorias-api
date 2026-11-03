<?php

namespace App\Services;

use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;

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
}