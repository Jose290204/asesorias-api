<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Usuario */
class AdministradorResource extends JsonResource
{
    // Nunca incluye la contraseña
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'apellido_paterno' => $this->apellido_paterno,
            'apellido_materno' => $this->apellido_materno,
            'nombre_completo' => implode(' ', array_filter([
                $this->nombre, $this->apellido_paterno, $this->apellido_materno,
            ])),
            'correo' => $this->correo,
            'facultad_id' => $this->facultad_id,
            'estatus' => $this->estatus->value,
        ];
    }
}