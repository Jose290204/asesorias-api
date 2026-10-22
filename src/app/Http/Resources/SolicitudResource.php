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
            // Solo aparece si el service cargó la relación (listar sí; crear no)
            'estudiante' => $this->whenLoaded('estudiante', fn () => [
                'id' => $this->estudiante->id,
                'nombre' => trim("{$this->estudiante->nombre} {$this->estudiante->apellido_paterno} {$this->estudiante->apellido_materno}"),
            ]),
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
            // El front lo muestra como "Notas del asesor"; null mientras esté pendiente
            'motivo_rechazo' => $this->motivo_rechazo,
            'respondida_en' => $this->respondida_en?->toIso8601String(),
        ];
    }
}