<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Usuario */
class EstudianteAdminResource extends JsonResource
{
    // Lo del EstudianteResource (id, nombre completo, número de cuenta y nombre del grupo,
    // que usa el autocompletado de "Crear asesoría") más lo que el admin necesita para ver y editar.
    // Sin promedio ni estatus: el panel no los maneja.
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Nombre completo, para mostrar
            'nombre' => $this->nombreCompleto(),
            // Por separado, para el formulario de edición
            'nombres' => $this->nombre,
            'apellido_paterno' => $this->apellido_paterno,
            'apellido_materno' => $this->apellido_materno,
            'correo' => $this->correo,
            'numero_cuenta' => $this->numero_cuenta,
            'telefono' => $this->num_cel,
            // Solo si el service cargó la relación; null si el estudiante no tiene grupo
            'grupo' => $this->whenLoaded('grupo', fn () => $this->grupo?->nombre),
            'grupo_id' => $this->grupo_id,
            'licenciatura' => $this->whenLoaded('grupo', fn () => $this->grupo?->licenciatura?->nombre),
            'licenciatura_id' => $this->whenLoaded('grupo', fn () => $this->grupo?->licenciatura_id),
        ];
    }
}