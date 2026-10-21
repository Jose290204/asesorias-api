<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Solicitud */
class SolicitudResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha_inicio->toDateString(),
            'estatus' => $this->estatus->value,
            'asesor' => [
                'id' => $this->asesor->id,
                'nombre' => trim("{$this->asesor->nombre} {$this->asesor->apellido_paterno} {$this->asesor->apellido_materno}"),
            ],
            'materia' => [
                'id' => $this->materia->id,
                'nombre' => $this->materia->nombre,
            ],
            'horario' => [
                'id' => $this->horario->id,
                'etiqueta' => $this->horario->etiqueta,
            ],
            'modalidad' => [
                'valor' => $this->modalidad->value,
                'etiqueta' => $this->modalidad->etiqueta(),
            ],
            'razon' => [
                'valor' => $this->razon->value,
                'etiqueta' => $this->razon->etiqueta(),
            ],
            'nota' => $this->nota_estudiante,
        ];
    }
}