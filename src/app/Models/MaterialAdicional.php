<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialAdicional extends Model
{
    // La tabla no sigue el plural automático de Laravel, por eso se indica el nombre.
    protected $table = 'material_adicional';

    // Columnas que se pueden llenar con create(). 'ruta' y 'disco' reemplazan a 'drive_file_id'.
    protected $fillable = ['asesoria_id', 'usuario_id', 'nombre_archivo', 'ruta', 'disco', 'mime_type', 'tamano'];

    // Datos internos que nunca salen en el JSON: la ruta en disco no le sirve al front
    // y exponerla revelaría cómo está organizado el almacenamiento.
    protected $hidden = ['ruta', 'disco'];

    // Cómo Laravel busca el material cuando la ruta trae {material}.
    // Igual que Sesion: esta tabla no tiene facultad_id ni el trait PerteneceAFacultad,
    // pero Asesoria sí. whereHas('asesoria') aplica el filtro de facultad, así que material
    // de otra facultad (o inexistente) da null y Laravel responde 404.
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)
            ->whereHas('asesoria')
            ->first();
    }

    // La asesoría a la que pertenece el archivo.
    public function asesoria(): BelongsTo
    {
        return $this->belongsTo(Asesoria::class);
    }

    // El usuario que subió el archivo (usuario_id).
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}