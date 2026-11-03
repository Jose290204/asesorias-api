<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Sesion */
class SesionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha->toDateString(),
            'horario' => $this->whenLoaded('horario', fn () => [
                'id' => $this->horario->id,
                'etiqueta' => $this->horario->etiqueta,
            ]),
            'tema' => $this->tema,
            'notas' => $this->notas,
            // Nombre completo de quien la registró (el asesor asignado)
            'registrada_por' => $this->whenLoaded('registradaPor', fn () => trim(
                "{$this->registradaPor->nombre} {$this->registradaPor->apellido_paterno} {$this->registradaPor->apellido_materno}"
            )),
        ];
    }
}