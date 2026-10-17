<?php

namespace App\Models;

use App\Enums\Modalidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\PerteneceAFacultad;

class DisponibilidadModalidad extends Model
{
    use PerteneceAFacultad;
    protected $table = 'disponibilidad_modalidades';

    protected $fillable = ['facultad_id', 'asesor_id', 'modalidad'];

    protected function casts(): array
    {
        return [
            'modalidad' => Modalidad::class,
        ];
    }

    public function asesor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'asesor_id');
    }
}