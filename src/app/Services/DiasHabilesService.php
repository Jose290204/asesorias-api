<?php

namespace App\Services;

use App\Enums\Dia;
use App\Exceptions\ErrorDeNegocio;
use App\Models\DiaInhabil;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

// Calendario de cada facultad: qué días NO hay asesorías (fin de semana, vacaciones y suspensiones).
// Los días inhábiles con facultad_id = null valen para todas; los que traen facultad_id, solo para esa.
class DiasHabilesService
{
    /** @var array<string, DiaInhabil|null> */
    private array $memo = [];

    /** @return Collection<int, DiaInhabil> los inhábiles que tocan el rango, de la facultad y los generales */
    public function rango(?int $facultadId, CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return DiaInhabil::query()
            ->where(function ($q) use ($facultadId) {
                $q->whereNull('facultad_id');
                if ($facultadId) {
                    $q->orWhere('facultad_id', $facultadId);
                }
            })
            ->whereDate('fecha_inicio', '<=', $hasta->toDateString())
            ->whereDate('fecha_fin', '>=', $desde->toDateString())
            ->orderBy('fecha_inicio')
            ->get();
    }

    /** El inhábil que cubre esa fecha dentro de una colección ya cargada (para recorrer fechas sin consultar) */
    public static function cubre(Collection $inhabiles, string $fecha): ?DiaInhabil
    {
        return $inhabiles->first(
            fn (DiaInhabil $d) => $d->fecha_inicio->toDateString() <= $fecha && $d->fecha_fin->toDateString() >= $fecha
        );
    }

    /** El inhábil de un día suelto (con memoria por petición) */
    public function motivo(?int $facultadId, CarbonInterface $fecha): ?DiaInhabil
    {
        $llave = ($facultadId ?? 0).'|'.$fecha->toDateString();

        if (! array_key_exists($llave, $this->memo)) {
            $this->memo[$llave] = self::cubre($this->rango($facultadId, $fecha, $fecha), $fecha->toDateString());
        }

        return $this->memo[$llave];
    }

    /** Lanza 422 si ese día no hay asesorías: fin de semana, vacaciones o suspensión */
    public function validar(?int $facultadId, CarbonInterface $fecha): void
    {
        if (Dia::desdeFecha($fecha) === null) {
            throw ErrorDeNegocio::invalido('Ese día no se ofrecen asesorías.', 'dia_no_disponible');
        }

        $inhabil = $this->motivo($facultadId, $fecha);

        if ($inhabil) {
            throw ErrorDeNegocio::invalido("Ese día no hay asesorías: {$inhabil->motivo}.", 'dia_inhabil');
        }
    }

    /** Formato para el front */
    public function aArreglo(Collection $inhabiles): array
    {
        return $inhabiles->map(fn (DiaInhabil $d) => [
            'fecha_inicio' => $d->fecha_inicio->toDateString(),
            'fecha_fin' => $d->fecha_fin->toDateString(),
            'tipo' => $d->tipo,
            'motivo' => $d->motivo,
        ])->values()->all();
    }
}
