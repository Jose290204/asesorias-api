<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarEstudianteRequest;
use App\Http\Requests\ListarEstudiantesAdminRequest;
use App\Http\Requests\ListarEstudiantesRequest;
use App\Http\Resources\EstudianteAdminResource;
use App\Http\Resources\EstudianteResource;
use App\Services\EstudianteService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class EstudianteController extends Controller
{
    // GET /asesor/estudiantes?buscar=: alumnos que el asesor puede elegir al crear una asesoría
    public function index(ListarEstudiantesRequest $request, EstudianteService $estudiantes): AnonymousResourceCollection
    {
        return EstudianteResource::collection(
            // validated('buscar') devuelve null si el parámetro no llegó
            $estudiantes->buscar($request->user(), $request->validated('buscar'))
        );
    }

    // GET /admin/estudiantes?buscar=&licenciatura_id=&grupo_id=: toda la facultad en contexto
    public function indexAdmin(ListarEstudiantesAdminRequest $request, EstudianteService $estudiantes): AnonymousResourceCollection
    {
        return EstudianteAdminResource::collection(
            $estudiantes->buscarEnFacultad($request->validated())
        );
    }

    // POST /admin/estudiantes: responde 201
    public function crear(GuardarEstudianteRequest $request, EstudianteService $estudiantes): EstudianteAdminResource
    {
        return new EstudianteAdminResource($estudiantes->crear($request->validated()));
    }

    // PUT /admin/estudiantes/{estudiante}
    // 404 si no es un estudiante de la facultad en contexto (Usuario no tiene FacultadScope,
    // por eso el id se resuelve en el service y no con el binding de la ruta)
    public function actualizar(GuardarEstudianteRequest $request, int $estudiante, EstudianteService $estudiantes): EstudianteAdminResource
    {
        $modelo = $estudiantes->encontrarEnFacultad($estudiante);

        return new EstudianteAdminResource($estudiantes->actualizar($modelo, $request->validated()));
    }

    // DELETE /admin/estudiantes/{estudiante}: baja (pasa a inactivo); responde 204
    public function eliminar(int $estudiante, EstudianteService $estudiantes): Response
    {
        $estudiantes->darDeBaja($estudiantes->encontrarEnFacultad($estudiante));

        return response()->noContent();
    }
}