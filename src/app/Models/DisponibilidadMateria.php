<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\PerteneceAFacultad;

class DisponibilidadMateria extends Model
{
    use PerteneceAFacultad;
    protected $table = 'disponibilidad_materias';

    protected $fillable = ['facultad_id', 'asesor_id', 'materia_id'];

    public function asesor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'asesor_id');
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }
}