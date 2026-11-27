<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ErrorDeNegocio;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListarMateriasRequest;
use App\Http\Resources\MateriaResource;
use App\Models\CicloEscolar;
use App\Models\Materia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MateriaController extends Controller
{
    // GET /materias: todas las materias de la facultad (la usa el asesor para marcar las que da)
    public function index(ListarMateriasRequest $request): AnonymousResourceCollection
    {
        $materias = $this->consulta($request)
            ->orderBy('nombre')
            ->get();

        return MateriaResource::collection($materias);
    }

    // GET /estudiante/materias: su licenciatura, del semestre que cursa hacia abajo
    public function paraEstudiante(ListarMateriasRequest $request): AnonymousResourceCollection
    {
        $grupo = $request->user()->grupo;

        // Sin grupo no hay semestre del cual partir: conflicto de estado de la cuenta
        if (! $grupo) {
            throw ErrorDeNegocio::conflicto('Tu cuenta no tiene un grupo asignado.', 'sin_grupo');
        }

        // Del ciclo vigente sale el periodo (1 o 2)
        $ciclo = CicloEscolar::vigente()->first();

        if (! $ciclo) {
            throw ErrorDeNegocio::conflicto('No hay un ciclo escolar vigente.', 'sin_ciclo');
        }

        $materias = $this->consulta($request)
            ->where('licenciatura_id', $grupo->licenciatura_id)
            ->where('semestre', '<=', $grupo->semestreActual($ciclo->periodo))
            ->orderBy('semestre')
            ->orderBy('nombre')
            ->get();

        return MateriaResource::collection($materias);
    }

    // Parte común de las dos rutas: facultad, relación y búsqueda
    private function consulta(ListarMateriasRequest $request): Builder
    {
        return Materia::query()
            // Solo materias cuya licenciatura es visible para el usuario (su facultad)
            ->whereHas('licenciatura')
            ->with('licenciatura:id,nombre')
            // when(): solo agrega el filtro si "buscar" trae texto
            ->when(
                $request->validated('buscar'),
                fn ($q, $buscar) => $q->where('nombre', 'like', "%{$buscar}%")
            );
    }
}