<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Illuminate\Notifications\DatabaseNotification */
class NotificacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->type,
            'titulo' => $this->data['titulo'] ?? null,
            'mensaje' => $this->data['mensaje'] ?? null,
            'solicitud_id' => $this->data['solicitud_id'] ?? null,
            'asesoria_id' => $this->data['asesoria_id'] ?? null,
            'como' => $this->data['como'] ?? null,
            'leida' => $this->read_at !== null,
            'fecha' => $this->created_at?->toIso8601String(),
        ];
    }
}