<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MaterialAdicional */
class MaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Nombre original del archivo, tal como lo verá el usuario
            'nombre' => $this->nombre_archivo,
            // Tipo detectado por el contenido (por ejemplo application/pdf)
            'tipo' => $this->mime_type,
            // Tamaño en bytes; el front lo convierte a KB o MB
            'tamano' => $this->tamano,
            // Nombre completo de quien lo subió (el asesor asignado)
            'subido_por' => $this->whenLoaded('usuario', fn () => trim(
                "{$this->usuario->nombre} {$this->usuario->apellido_paterno} {$this->usuario->apellido_materno}"
            )),
            'fecha' => $this->created_at->toIso8601String(),
        ];
    }
}