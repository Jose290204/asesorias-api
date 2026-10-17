<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

// Implementar la interfaz Scope obliga a tener el método apply()
class FacultadScope implements Scope
{
    // Laravel llama a apply() cada vez que se arma una consulta del modelo.
    public function apply(Builder $builder, Model $model): void
    {
        // Auth::user() devuelve el usuario logueado, o null si no hay nadie
        $usuario = Auth::user();

        // Sin usuario (seeders, comandos de consola, el propio login):
        // no hay facultad que aplicar, así que no se filtra nada
        if (! $usuario) {
            return;
        }

        // El super admin ve todas las facultades: se sale sin filtrar
        if ($usuario->esSuperAdmin()) {
            return;
        }

        // Se antepone el nombre de la tabla ("asesorias.facultad_id") para evitar
        // ambigüedad cuando la consulta tiene joins con otras tablas que
        // también tienen columna facultad_id
        $builder->where($model->getTable().'.facultad_id', $usuario->facultad_id);
    }
}