<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanEstudios extends Model
{
    protected $table = 'plan_estudios';

    protected $fillable = ['facultad_id', 'nombre', 'vigente'];

    protected function casts(): array
    {
        return ['vigente' => 'boolean'];
    }
}