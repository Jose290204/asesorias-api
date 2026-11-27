<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use App\Models\Concerns\PerteneceAFacultad;
use App\Support\ContextoFacultad;
use App\Enums\AccionBitacora;
use Throwable;

class Bitacora extends Model
{
    use PerteneceAFacultad;
    use MassPrunable;

    protected $table = 'bitacora';

    const UPDATED_AT = null;

    protected $fillable = [
        'facultad_id', 'usuario_id', 'accion', 'entidad_type', 'entidad_id', 'detalles', 'ip',
    ];

    protected function casts(): array
    {
        return ['detalles' => 'array'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function entidad(): MorphTo
    {
        return $this->morphTo();
    }

    // Registros de más de un año se borran con: php artisan model:prune
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subYear());
    }
       // Un fallo al registrar nunca debe romper la acción principal.
    // $facultadId explícito: para acciones sobre una facultad desde el panel del super admin.
    // $actor explícito: en el login todavía no hay nadie autenticado, así que Auth::user() sería null.
    public static function registrar(
        AccionBitacora|string $accion,
        ?Model $entidad = null,
        array $detalles = [],
        ?int $facultadId = null,
        ?Usuario $actor = null,
    ): void {
        try {
            $usuario = $actor ?? Auth::user();

            // Se marca lo que hizo un super admin (desde su panel o dentro de una facultad)
            if ($usuario?->esSuperAdmin()) {
                $detalles['super_admin'] = true;
            }

            static::create([
                'facultad_id'  => $facultadId ?? app(ContextoFacultad::class)->id() ?? $usuario?->facultad_id,
                'usuario_id'   => $usuario?->id,
                'accion'       => $accion instanceof AccionBitacora ? $accion->value : $accion,
                'entidad_type' => $entidad?->getMorphClass(),
                'entidad_id'   => $entidad?->getKey(),
                'detalles'     => $detalles ?: null,
                'ip'           => request()->ip(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}