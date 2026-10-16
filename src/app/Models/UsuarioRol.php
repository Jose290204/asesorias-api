<?php

namespace App\Models;

use App\Enums\Rol;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsuarioRol extends Model
{
    protected $table = 'usuario_roles';

    protected $fillable = ['usuario_id', 'rol'];

    protected function casts(): array
    {
        return ['rol' => Rol::class];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}