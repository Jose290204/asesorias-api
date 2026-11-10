<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Usuario */
class AsesorAdminResource extends JsonResource
{
    // Datos personales (para ver y editar) + disponibilidad con el formato de siempre.
    // Los pares traen además grupo/licenciatura si el service los cargó.
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombreCompleto(),
            'nombres' => $this->nombre,
            'apellido_paterno' => $this->apellido_paterno,
            'apellido_materno' => $this->apellido_materno,
            'correo' => $this->correo,
            'numero_cuenta' => $this->numero_cuenta,
            'telefono' => $this->num_cel,
            'disponibilidad' => new DisponibilidadResource($this->resource),
        ];
    }
}