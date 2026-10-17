<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAFacultad;
use App\Enums\EstatusAsesoria;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asesoria extends Model
{
    use PerteneceAFacultad;
    protected $table = 'asesorias';

    protected $fillable = [
        'facultad_id', 'ciclo_escolar_id', 'grupo_id', 'solicitud_id', 'estudiante_id',
        'asesor_id', 'materia_id', 'horario_id', 'modalidad', 'razon', 'estatus',
        'fecha_inicio', 'finalizada_en', 'observaciones', 'motivo_cancelacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio'  => 'date',
            'modalidad'     => Modalidad::class,
            'razon'         => RazonAsesoria::class,
            'estatus'       => EstatusAsesoria::class,
            'finalizada_en' => 'datetime',
        ];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(Solicitud::class);
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

        public function sesiones(): HasMany
    {
        return $this->hasMany(Sesion::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }

    public function material(): HasMany
    {
        return $this->hasMany(MaterialAdicional::class);
    }

    public function evidencias(): HasMany
    {
        return $this->hasMany(EvidenciaAsesoria::class);
    }
}