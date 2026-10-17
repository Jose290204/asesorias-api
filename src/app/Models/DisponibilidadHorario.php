<?php

namespace App\Models;

use App\Enums\Dia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\PerteneceAFacultad;

class DisponibilidadHorario extends Model
{
    use PerteneceAFacultad;
    protected $table = 'disponibilidad_horarios';

    protected $fillable = ['facultad_id', 'asesor_id', 'dia', 'horario_id'];

    protected function casts(): array
    {
        return ['dia' => Dia::class];
    }

    public function asesor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'asesor_id');
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class);
    }
}