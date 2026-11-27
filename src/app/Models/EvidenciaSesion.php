<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenciaSesion extends Model
{
    protected $table = 'evidencias_sesiones';

    protected $fillable = ['sesion_id', 'usuario_id', 'nombre_archivo', 'ruta', 'disco', 'mime_type', 'tamano'];

    // La ruta en disco no le sirve al front y revelaría cómo está organizado el almacenamiento
    protected $hidden = ['ruta', 'disco'];

    // {evidencia} en la ruta: whereHas('sesion.asesoria') aplica el filtro de facultad de la asesoría,
    // así que una evidencia de otra facultad (o inexistente) da 404.
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)
            ->whereHas('sesion.asesoria')
            ->first();
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(Sesion::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}