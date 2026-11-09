<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Bitacora */
class BitacoraResource extends JsonResource
{
    // "entidad" y "detalle" salen del snapshot guardado en `detalles`, no de un join:
    // así el registro se lee igual aunque la facultad o el administrador ya no existan
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->created_at?->toIso8601String(),
            'usuario' => $this->usuario
                ? implode(' ', array_filter([
                    $this->usuario->nombre, $this->usuario->apellido_paterno, $this->usuario->apellido_materno,
                ]))
                : null,
            'facultad_id' => $this->facultad_id,
            'accion' => $this->accion,
            'entidad' => $this->detalles['etiqueta'] ?? null,
            'detalle' => $this->detalles['detalle'] ?? null,
            'super_admin' => (bool) ($this->detalles['super_admin'] ?? false),
            'ip' => $this->ip,
        ];
    }
}