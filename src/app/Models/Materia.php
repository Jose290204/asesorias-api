<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Materia extends Model
{
    protected $table = 'materias';

    protected $fillable = [
        'facultad_id', 'plan_estudios_id', 'licenciatura_id',
        'nombre', 'semestre', 'activa',
    ];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }
}