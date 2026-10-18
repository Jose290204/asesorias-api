<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Usuario */
class UsuarioResource extends JsonResource
{
    // Devuelve el arreglo que Laravel convierte en JSON.
    // En $this están las propiedades del modelo: $this->nombre equivale a $usuario->nombre.
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'apellido_paterno' => $this->apellido_paterno,
            'apellido_materno' => $this->apellido_materno,
            'correo' => $this->correo,

            // ->value da el texto del enum ("activo") en vez del objeto
            'estatus' => $this->estatus->value,

            // El front recibe números: [4, 3] (4 = estudiante, 3 = asesor).
            // map recorre cada UsuarioRol; rol_id es un enum (por el cast) y ->value da su número.
            // values() reordena los índices para que salga como lista y no como objeto.
            // El front usa esta lista para decidir qué pantallas mostrar. La seguridad real
            // no depende de ella: la hacen los middlewares y las Policies del backend.
            'roles' => $this->roles->map(fn ($r) => $r->rol_id->value)->values(),

            // whenLoaded: solo incluye la facultad si se cargó antes con load('facultad').
            // Así el Resource no dispara consultas 
            'facultad' => $this->whenLoaded('facultad', fn () => [
                'id' => $this->facultad->id,
                'nombre' => $this->facultad->nombre,
                'siglas' => $this->facultad->siglas,
            ]),
        ];
    }
}