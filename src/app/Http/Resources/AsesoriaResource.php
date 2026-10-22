<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Asesoria */
class AsesoriaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'estatus' => $this->estatus->value,
            'fecha_inicio' => $this->fecha_inicio->toDateString(),
            // Solo sale la persona que el service cargó: el asesor al estudiante, el estudiante al asesor
            'asesor' => $this->whenLoaded('asesor', fn () => [
                'id' => $this->asesor->id,
                'nombre' => trim("{$this->asesor->nombre} {$this->asesor->apellido_paterno} {$this->asesor->apellido_materno}"),
            ]),
            'estudiante' => $this->whenLoaded('estudiante', fn () => [
                'id' => $this->estudiante->id,
                'nombre' => trim("{$this->estudiante->nombre} {$this->estudiante->apellido_paterno} {$this->estudiante->apellido_materno}"),
            ]),
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
            'observaciones' => $this->observaciones,
            // null mientras no esté cancelada / completada
            'motivo_cancelacion' => $this->motivo_cancelacion,
            'finalizada_en' => $this->finalizada_en?->toIso8601String(),
            // Solo existe si el service usó withCount('sesiones')
            'sesiones_count' => $this->whenCounted('sesiones'),
        ];
    }
}