<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EvidenciaSesion */
class EvidenciaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre_archivo,
            'tipo' => $this->mime_type,
            'tamano' => $this->tamano,
            'fecha' => $this->created_at->toIso8601String(),
        ];
    }
}