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
            // Las notas son del asesor: solo salen para quien administra la asesoría (asesor asignado o admin).
            // El estudiante no las recibe.
            'notas' => $this->when(
                $request->user()?->can('gestionar', $this->asesoria),
                $this->notas
            ),
            // Las evidencias, igual que las notas, son del asesor: el estudiante no las recibe
            'evidencias' => $this->when(
                $request->user()?->can('gestionar', $this->asesoria),
                fn () => EvidenciaResource::collection($this->whenLoaded('evidencias'))
            ),
            // Nombre completo de quien la registró (el asesor asignado)
            'registrada_por' => $this->whenLoaded('registradaPor', fn () => trim(
                "{$this->registradaPor->nombre} {$this->registradaPor->apellido_paterno} {$this->registradaPor->apellido_materno}"
            )),
        ];
    }
}