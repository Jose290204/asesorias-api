<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chat extends Model
{
    protected $table = 'chats';

    protected $fillable = ['asesoria_id', 'usuario_id', 'mensaje', 'leido_en'];

    protected function casts(): array
    {
        return ['leido_en' => 'datetime'];
    }

    public function asesoria(): BelongsTo
    {
        return $this->belongsTo(Asesoria::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}