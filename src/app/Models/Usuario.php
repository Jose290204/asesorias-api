<?php

namespace App\Models;

use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

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
        return $this->roles->contains(fn (UsuarioRol $r) => $r->rol_id === $rol);
    }

    public function esSuperAdmin(): bool
    {
        return $this->tieneRol(Rol::SuperAdmin);
    }

        // Administradores de facultad: tienen el rol Admin y no son super admin.
    // Lo usan el listado y el binding {administrador} del panel del super admin.
    public function scopeAdministradores(Builder $query): Builder
    {
        return $query
            ->whereHas('roles', fn ($q) => $q->where('rol_id', Rol::Admin->value))
            ->whereDoesntHave('roles', fn ($q) => $q->where('rol_id', Rol::SuperAdmin->value));
    }

    public function asignarRol(Rol $rol): void
    {
         $this->roles()->firstOrCreate(['rol_id' => $rol->value]);
        // Descarta la relación ya cargada para que tieneRol() vea el rol nuevo
        $this->unsetRelation('roles');
    }

    // Laravel manda los correos a la columna "correo", no a "email"
    public function routeNotificationForMail(): string
    {
        return $this->correo;
    }

    public function disponibilidadModalidades(): HasMany
    {
        return $this->hasMany(DisponibilidadModalidad::class, 'asesor_id');
    }

    //un usuario (asesor) tiene muchas filas en disponibilidad_materias
    //asesor_id en el nombre real de la llave foranea, sin el laravel buscaria 'usuario_id'
    public function disponibilidadMaterias(): HasMany
    {
        return $this->hasMany(DisponibilidadMateria::class, 'asesor_id');
    }

    // lo mismo de arriba, pero para las filas día + horario de disponibilidad_horarios
    public function disponibilidadHorarios(): HasMany
    {
        return $this->hasMany(DisponibilidadHorario::class, 'asesor_id');
    }

        public function nombreCompleto(): string
    {
        return implode(' ', array_filter([$this->nombre, $this->apellido_paterno, $this->apellido_materno]));
    }

        /**
     * ¿Puede administrar la facultad en la que está operando?
     * (admin de facultad o super admin)
     */
    public function puedeAdministrar(): bool
    {
        return $this->tieneRol(Rol::Admin) || $this->esSuperAdmin();
    }
}