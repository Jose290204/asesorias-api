<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sesion extends Model
{
    protected $table = 'sesiones';

    protected $fillable = ['asesoria_id', 'fecha', 'horario_id', 'tema', 'notas', 'registrada_por'];

    protected function casts(): array
    {
        return ['fecha' => 'date'];
    }

    public function asesoria(): BelongsTo
    {
        return $this->belongsTo(Asesoria::class);
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class);
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrada_por');
    }
}