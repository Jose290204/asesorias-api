<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Grupo extends Model
{
    protected $table = 'grupos';

    protected $fillable = ['licenciatura_id', 'nombre', 'anio', 'modalidad'];

    public function licenciatura(): BelongsTo
    {
        return $this->belongsTo(Licenciatura::class);
    }

    // Semestre que cursa el grupo según el periodo del ciclo (1 o 2).
    // Año 3, periodo 1 => semestre 5; año 3, periodo 2 => semestre 6
    public function semestreActual(int $periodo): int
    {
        return $this->anio * 2 - (2 - $periodo);
    }
}