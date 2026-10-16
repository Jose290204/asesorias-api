<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Horario extends Model
{
    protected $table = 'horarios';

    protected $fillable = ['facultad_id', 'hora_inicio', 'hora_fin', 'activo'];

    protected $appends = ['etiqueta'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    protected function etiqueta(): Attribute
    {
        return Attribute::get(
            fn () => substr($this->hora_inicio, 0, 5) . ' - ' . substr($this->hora_fin, 0, 5)
        );
    }
}