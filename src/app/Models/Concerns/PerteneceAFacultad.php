<?php

namespace App\Models\Concerns;

use App\Models\Facultad;
use App\Models\Scopes\FacultadScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;


trait PerteneceAFacultad
{
    // Laravel ejecuta solo este método cuando el modelo arranca (boot + nombre del trait)
    protected static function bootPerteneceAFacultad(): void
    {
        // Desde aquí toda consulta del modelo se filtra por facultad
        static::addGlobalScope(new FacultadScope);

        // Se dispara justo antes de insertar un registro nuevo
        static::creating(function ($modelo) {
            if (empty($modelo->facultad_id) && Auth::check()) {
                /** @var \App\Models\Usuario $usuario */
                $usuario = Auth::user();
                $modelo->facultad_id = $usuario->facultad_id;
            }
        });
    }

    public function facultad(): BelongsTo
    {
        return $this->belongsTo(Facultad::class);
    }
}