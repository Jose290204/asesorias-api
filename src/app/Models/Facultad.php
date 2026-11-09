<?php

namespace App\Models;

use App\Enums\Rol;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Facultad extends Model
{
    protected $table = 'facultades';

    protected $fillable = ['nombre', 'siglas', 'logo', 'activa'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class);
    }

    public function asesorias(): HasMany
    {
        return $this->hasMany(Asesoria::class);
    }

    // Los conteos del resumen. Un usuario con dos roles (asesor par) cuenta en ambos.
    // withoutGlobalScopes: Asesoria tiene FacultadScope y aquí se cuenta por facultad a mano.
    public static function conteos(): array
    {
        $conRol = fn (Rol $rol) => fn ($q) => $q->whereHas(
            'roles', fn ($r) => $r->where('rol_id', $rol->value)
        );

        return [
            'usuarios as administradores_count' => $conRol(Rol::Admin),
            'usuarios as asesores_count' => $conRol(Rol::Asesor),
            'usuarios as estudiantes_count' => $conRol(Rol::Estudiante),
            'asesorias as asesorias_count' => fn ($q) => $q->withoutGlobalScopes(),
        ];
    }

    public function scopeConConteos(Builder $query): Builder
    {
        return $query->withCount(self::conteos());
    }

    // URL pública del logo (null si no tiene)
    public function logoUrl(): ?string
    {
        return $this->logo ? Storage::disk('public')->url($this->logo) : null;
    }
}