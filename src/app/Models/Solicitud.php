<?php

namespace App\Models;

use App\Enums\EstatusSolicitud;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Concerns\PerteneceAFacultad;

class Solicitud extends Model
{
    use PerteneceAFacultad;
    protected $table = 'solicitudes';

    protected $fillable = [
        'facultad_id', 'ciclo_escolar_id', 'grupo_id', 'estudiante_id', 'asesor_id',
        'materia_id', 'horario_id', 'fecha_inicio', 'modalidad', 'razon', 'estatus',
        'nota_estudiante', 'motivo_rechazo', 'respondida_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio'  => 'date',
            'modalidad'     => Modalidad::class,
            'razon'         => RazonAsesoria::class,
            'estatus'       => EstatusSolicitud::class,
            'respondida_en' => 'datetime',
        ];
    }

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'estudiante_id');
    }

    public function asesor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'asesor_id');
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class);
    }

    public function cicloEscolar(): BelongsTo
    {
        return $this->belongsTo(CicloEscolar::class);
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function asesoria(): HasOne
    {
        return $this->hasOne(Asesoria::class);
    }
}