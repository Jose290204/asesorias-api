<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Licenciatura extends Model
{
    protected $table = 'licenciaturas';

    protected $fillable = ['facultad_id', 'nombre', 'siglas', 'duracion_semestres', 'activa'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }
}