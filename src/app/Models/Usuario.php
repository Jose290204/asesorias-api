<?php

namespace App\Models;

use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'usuarios';

    protected $fillable = [
        'facultad_id', 'estatus', 'nombre', 'apellido_paterno', 'apellido_materno',
        'correo', 'password', 'numero_cuenta', 'num_cel', 'grupo_id', 'promedio',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'estatus'           => EstatusUsuario::class,
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'promedio'          => 'decimal:2',
        ];
    }

    public function facultad(): BelongsTo
    {
        return $this->belongsTo(Facultad::class);
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(UsuarioRol::class);
    }

    public function verificaciones(): HasMany
    {
        return $this->hasMany(Verificacion::class);
    }

    public function tieneRol(Rol $rol): bool
    {
        return $this->roles->contains(fn (UsuarioRol $r) => $r->rol === $rol);
    }

    // Laravel manda los correos a la columna "correo", no a "email"
    public function routeNotificationForMail(): string
    {
        return $this->correo;
    }
}