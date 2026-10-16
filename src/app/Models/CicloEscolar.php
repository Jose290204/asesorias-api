<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CicloEscolar extends Model
{
    protected $table = 'ciclos_escolares';

    protected $fillable = ['facultad_id', 'nombre', 'fecha_inicio', 'fecha_fin', 'vigente'];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin'    => 'date',
            'vigente'      => 'boolean',
        ];
    }
}