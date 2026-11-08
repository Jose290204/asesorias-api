<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Chat */
class ChatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // En la tabla la columna se llama "mensaje"; el front lo recibe como "texto"
            'texto' => $this->mensaje,
            'fecha' => $this->created_at->toIso8601String(),
            // Se calcula para QUIEN CONSULTA: el mismo mensaje sale propio:true para su autor
            // y propio:false para la otra persona (burbuja a la derecha o a la izquierda).
            // Se compara por id, no por rol.
            'propio' => $this->usuario_id === $request->user()->id,
            'emisor' => $this->whenLoaded('usuario', fn () => [
                'id' => $this->usuario->id,
                'nombre' => trim("{$this->usuario->nombre} {$this->usuario->apellido_paterno} {$this->usuario->apellido_materno}"),
            ]),
        ];
    }
}