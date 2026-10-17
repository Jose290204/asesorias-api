<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAFacultad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CicloEscolar extends Model
{
    use PerteneceAFacultad;

    // Se indica la tabla porque Laravel buscaría "ciclo_escolars"
    protected $table = 'ciclos_escolares';

    protected $fillable = ['facultad_id', 'nombre', 'fecha_inicio', 'fecha_fin', 'vigente'];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'vigente' => 'boolean',
        ];
    }

    // Uso: CicloEscolar::vigente()->first()
    // Útil al crear una solicitud, que debe copiar el ciclo vigente
    public function scopeVigente(Builder $query): Builder
    {
        return $query->where('vigente', true);
    }
}