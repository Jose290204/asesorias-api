<?php

namespace App\Services;

use App\Enums\Dia;
use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Models\Usuario;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AsesorService
{
    // Relaciones que necesita DisponibilidadResource. Una sola lista para la lista y el detalle
    private const RELACIONES = [
        'disponibilidadMaterias.materia.licenciatura',
        'disponibilidadHorarios.horario',
        'disponibilidadModalidades',
    ];

    // Consulta base: "qué asesores puede ver este estudiante". Devuelve el Builder SIN ejecutar
    public function visibles(Usuario $estudiante): Builder
    {
        return Usuario::query()
            // Usuario no tiene FacultadScope, por eso la facultad se filtra a mano
            ->where('facultad_id', $estudiante->facultad_id)
            ->where('estatus', EstatusUsuario::Activo->value)
            // Nunca el propio estudiante (asesor par)
            ->where('id', '!=', $estudiante->id)
            // whereHas = "que exista al menos una fila relacionada que cumpla esto"
            ->whereHas('roles', fn (Builder $q) => $q->where('rol_id', Rol::Asesor->value))
            // Red de seguridad: por la decisión B ya debería cumplirse siempre
            ->whereHas('disponibilidadMaterias')
            ->whereHas('disponibilidadHorarios')
            ->whereHas('disponibilidadModalidades');
    }

    // $filtros viene de $request->validated(): buscar, materia_id, modalidad, fecha, horario_id.
    // Un Builder es un objeto: cada filtro lo modifica directamente, no hace falta devolverlo
    public function buscar(Usuario $estudiante, array $filtros): LengthAwarePaginator
    {
        $consulta = $this->visibles($estudiante);

        $this->filtrarPorNombre($consulta, $filtros['buscar'] ?? null);
        $this->filtrarPorMateria($consulta, $filtros['materia_id'] ?? null);
        $this->filtrarPorModalidad($consulta, $filtros['modalidad'] ?? null);
        $this->filtrarPorHorario($consulta, $filtros['fecha'] ?? null, $filtros['horario_id'] ?? null);

        return $consulta
            ->with(self::RELACIONES)
            ->orderBy('apellido_paterno')
            ->orderBy('apellido_materno')
            ->orderBy('nombre')
            // El id desempata: la paginación no repite ni salta asesores con el mismo nombre
            ->orderBy('id')
            ->paginate(10);
    }

    // Un asesor visible para este estudiante, o 404. Se usa en el detalle y en horarios-disponibles
    public function encontrar(Usuario $estudiante, int $id): Usuario
    {
        // findOrFail lanza ModelNotFoundException y bootstrap/app.php la convierte en el 404 JSON.
        // No existe, otra facultad, no es asesor, inactivo o es el propio estudiante: misma respuesta
        return $this->visibles($estudiante)
            ->with(self::RELACIONES)
            ->findOrFail($id);
    }

    // ---------- Filtros: si el valor no llegó, no hacen nada ----------

    private function filtrarPorNombre(Builder $consulta, ?string $texto): void
    {
        if ($texto === null) {
            return;
        }

        // CONCAT_WS une nombre y apellidos con espacio: "Juan Pérez" encuentra a Juan Pérez López
        $consulta->whereRaw("CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) like ?", ["%{$texto}%"]);
    }

    private function filtrarPorMateria(Builder $consulta, ?int $materiaId): void
    {
        if ($materiaId === null) {
            return;
        }

        $consulta->whereHas('disponibilidadMaterias', fn (Builder $m) => $m->where('materia_id', $materiaId));
    }

    private function filtrarPorModalidad(Builder $consulta, ?string $modalidad): void
    {
        if ($modalidad === null) {
            return;
        }

        $consulta->whereHas('disponibilidadModalidades', fn (Builder $m) => $m->where('modalidad', $modalidad));
    }

    // fecha y horario_id condicionan LA MISMA fila de disponibilidad_horarios:
    // "lunes + 9:00" no debe encontrar a quien tiene lunes 7:00 y miércoles 9:00
    private function filtrarPorHorario(Builder $consulta, ?string $fecha, ?int $horarioId): void
    {
        if ($fecha === null && $horarioId === null) {
            return;
        }

        // De la fecha sale el día de la semana
        $dia = null;

        if ($fecha !== null) {
            $dia = Dia::desdeFecha(Carbon::parse($fecha));

            // Sábado o domingo: nadie ofrece esos días, la lista sale vacía
            if ($dia === null) {
                $consulta->whereRaw('1 = 0');

                return;
            }
        }

        $consulta->whereHas('disponibilidadHorarios', function (Builder $h) use ($dia, $horarioId) {
            if ($dia !== null) {
                $h->where('dia', $dia->value);
            }

            if ($horarioId !== null) {
                $h->where('horario_id', $horarioId);
            }
        });
    }
}