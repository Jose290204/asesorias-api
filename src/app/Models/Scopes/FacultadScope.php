<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use App\Support\ContextoFacultad;


// Implementar la interfaz Scope obliga a tener el método apply()
class FacultadScope implements Scope
{
        public function apply(Builder $builder, Model $model): void
    {
        $contexto = app(ContextoFacultad::class);

        // Sin contexto (seeders, consola, login, o super admin en modo global):
        // no se filtra nada
        if (! $contexto->estaDefinido()) {
            return;
        }

        // Se antepone el nombre de la tabla para evitar ambigüedad con joins
        $builder->where($model->getTable().'.facultad_id', $contexto->id());
    }
}