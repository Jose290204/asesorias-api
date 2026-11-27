<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Usuario */
class EstudianteResource extends JsonResource
{
    // Solo lo necesario para elegir al alumno: sin correo, teléfono ni promedio
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => trim("{$this->nombre} {$this->apellido_paterno} {$this->apellido_materno}"),
            'numero_cuenta' => $this->numero_cuenta,
            // Solo si el service cargó la relación; null si el estudiante no tiene grupo
            'grupo' => $this->whenLoaded('grupo', fn () => $this->grupo?->nombre),
        ];
    }
}