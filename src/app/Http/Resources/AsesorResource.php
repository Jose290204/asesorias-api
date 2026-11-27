<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Usuario */
class AsesorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'apellido_paterno' => $this->apellido_paterno,
            'apellido_materno' => $this->apellido_materno,
            'correo' => $this->correo,
            // $this->resource es el Usuario original. DisponibilidadResource lo envuelve
            // y entrega materias, horarios por día y modalidades con el formato ya conocido.
            // Un resource dentro de otro NO lleva su propio "data".
            'disponibilidad' => new DisponibilidadResource($this->resource),
        ];
    }
}