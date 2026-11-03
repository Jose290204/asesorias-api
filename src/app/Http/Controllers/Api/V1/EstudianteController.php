<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListarEstudiantesRequest;
use App\Http\Resources\EstudianteResource;
use App\Services\EstudianteService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
}