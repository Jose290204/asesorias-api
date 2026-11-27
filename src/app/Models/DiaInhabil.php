<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Días en que no hay asesorías (vacaciones y suspensiones oficiales). Los fines de semana no se
// guardan: ya se descartan por ser sábado o domingo. Los periodos de exámenes NO son inhábiles.
// No usa PerteneceAFacultad a propósito: facultad_id puede ser null (aplica a todas) y el filtro
// automático por facultad ocultaría esas filas. Se consulta siempre por DiasHabilesService.
class DiaInhabil extends Model
{
    public const VACACIONES = 'vacaciones';
    public const SUSPENSION = 'suspension';

    protected $table = 'dias_inhabiles';

    protected $fillable = ['facultad_id', 'fecha_inicio', 'fecha_fin', 'tipo', 'motivo'];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
        ];
    }
}
