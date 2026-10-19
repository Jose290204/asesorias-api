<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Materia extends Model
{
    protected $table = 'materias';

    protected $fillable = ['licenciatura_id', 'semestre', 'nombre'];

    public function licenciatura(): BelongsTo
    {
        return $this->belongsTo(Licenciatura::class);
    }
}