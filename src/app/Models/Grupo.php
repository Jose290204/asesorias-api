<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Grupo extends Model
{
    protected $table = 'grupos';

    protected $fillable = ['licenciatura_id', 'nombre'];

    public function licenciatura(): BelongsTo
    {
        return $this->belongsTo(Licenciatura::class);
    }
}