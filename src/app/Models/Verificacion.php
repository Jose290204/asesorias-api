<?php

namespace App\Models;

use App\Enums\TipoVerificacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Verificacion extends Model
{
    protected $table = 'verificaciones';

    protected $fillable = ['usuario_id', 'tipo', 'destino', 'codigo', 'expira_en', 'intentos', 'usado_en'];

    protected $hidden = ['codigo'];

    protected function casts(): array
    {
        return [
            'tipo'      => TipoVerificacion::class,
            'expira_en' => 'datetime',
            'usado_en'  => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}