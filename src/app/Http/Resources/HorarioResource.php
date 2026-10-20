<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Horario */
class HorarioResource extends JsonResource
{
    // Mismo formato que los horarios de GET /catalogos
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'etiqueta' => $this->etiqueta,
        ];
    }
}