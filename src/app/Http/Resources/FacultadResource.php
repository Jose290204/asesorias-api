<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Espera la facultad con sus conteos cargados (Facultad::conConteos() o loadCount(Facultad::conteos()))
class FacultadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'siglas' => $this->siglas,
            'logo' => $this->logoUrl(),
            'activa' => $this->activa,
            'administradores' => $this->administradores_count ?? 0,
            'asesores' => $this->asesores_count ?? 0,
            'estudiantes' => $this->estudiantes_count ?? 0,
            'asesorias' => $this->asesorias_count ?? 0,
        ];
    }
}