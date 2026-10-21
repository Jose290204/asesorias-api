<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerfilResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'apellido_paterno' => $this->apellido_paterno,
            'apellido_materno' => $this->apellido_materno,
            'correo' => $this->correo,
            'numero_cuenta' => $this->numero_cuenta,
            'num_cel' => $this->num_cel,
            'promedio' => $this->promedio,
            'grupo' => $this->grupo?->nombre,
            'licenciatura' => $this->grupo?->licenciatura?->nombre,
            // El controller la agrega con setAttribute() antes de devolver el recurso
            'estadisticas' => $this->estadisticas,
        ];
    }
}