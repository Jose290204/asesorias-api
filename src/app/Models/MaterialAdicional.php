<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialAdicional extends Model
{
    protected $table = 'material_adicional';

    protected $fillable = ['asesoria_id', 'usuario_id', 'nombre_archivo', 'drive_file_id', 'mime_type', 'tamano'];

    protected $hidden = ['drive_file_id'];

    public function asesoria(): BelongsTo
    {
        return $this->belongsTo(Asesoria::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}