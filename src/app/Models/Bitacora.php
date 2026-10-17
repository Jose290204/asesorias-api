<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use App\Models\Concerns\PerteneceAFacultad;
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

    // Un fallo al registrar nunca debe romper la acción principal
    public static function registrar(string $accion, ?Model $entidad = null, array $detalles = []): void
    {
        try {
            $usuario = Auth::user();

            static::create([
                'facultad_id'  => $usuario?->facultad_id,
                'usuario_id'   => $usuario?->id,
                'accion'       => $accion,
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