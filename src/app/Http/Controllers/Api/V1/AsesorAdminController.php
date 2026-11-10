<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AsignarAsesorParRequest;
use App\Http\Requests\GuardarAsesorRequest;
use App\Http\Requests\GuardarDisponibilidadRequest;
use App\Http\Requests\ListarAsesoresAdminRequest;
use App\Http\Resources\AsesorAdminResource;
use App\Http\Resources\DisponibilidadResource;
use App\Http\Resources\EstudianteAdminResource;
use App\Services\AsesorAdminService;
use App\Services\AsesorService;
use App\Services\DisponibilidadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;


// Gestión de asesores del panel del admin, Todo cae en la facultad en contexto.
class AsesorAdminController extends Controller
{
    public function __construct(private AsesorAdminService $asesores)
    {
    }

    // GET /admin/asesores-disciplinares?buscar=&materia_id=
    public function indexDisciplinares(ListarAsesoresAdminRequest $request): AnonymousResourceCollection
    {
        return AsesorAdminResource::collection($this->asesores->listarDisciplinares($request->validated()));
    }

    // POST /admin/asesores-disciplinares: responde 201
    public function crear(GuardarAsesorRequest $request): AsesorAdminResource
    {
        return new AsesorAdminResource($this->asesores->crear($request->validated()));
    }

    // PUT /admin/asesores-disciplinares/{asesor}: 404 si no es un disciplinar activo de la facultad
    public function actualizar(GuardarAsesorRequest $request, int $asesor): AsesorAdminResource
    {
        $modelo = $this->asesores->encontrarDisciplinar($asesor);

        return new AsesorAdminResource($this->asesores->actualizar($modelo, $request->validated()));
    }

    // DELETE /admin/asesores-disciplinares/{asesor}: baja; 204
    public function eliminar(int $asesor): Response
    {
        $this->asesores->darDeBaja($this->asesores->encontrarDisciplinar($asesor));

        return response()->noContent();
    }

    // GET /admin/asesores-par?buscar=&materia_id=
    public function indexPares(ListarAsesoresAdminRequest $request): AnonymousResourceCollection
    {
        return AsesorAdminResource::collection($this->asesores->listarPares($request->validated()));
    }

    // GET /admin/asesores-par/candidatos?buscar=: estudiantes activos que aún no son asesores
    public function candidatosPar(Request $request): AnonymousResourceCollection
    {
        $datos = $request->validate(['buscar' => ['nullable', 'string', 'max:100']]);

        return EstudianteAdminResource::collection($this->asesores->candidatosPar($datos['buscar'] ?? null));
    }

    // POST /admin/asesores-par {usuario_id}: responde 201
       public function asignarPar(AsignarAsesorParRequest $request): JsonResponse
    {
        $par = $this->asesores->asignarPar($request->validated('usuario_id'));

        // No se crea un usuario nuevo (solo un rol), así que Laravel no pone 201 solo: se fuerza aquí
        return (new AsesorAdminResource($par))->response()->setStatusCode(201);
    }

    // DELETE /admin/asesores-par/{usuario}: le quita el rol de asesor; 204
    public function quitarPar(int $usuario): Response
    {
        $this->asesores->quitarPar($this->asesores->encontrarPar($usuario));

        return response()->noContent();
    }

    // PUT /admin/asesores/{asesor}/disponibilidad: sirve para disciplinar y par
    public function actualizarDisponibilidad(
        GuardarDisponibilidadRequest $request,
        int $asesor,
        AsesorService $buscador,
        DisponibilidadService $disponibilidad
    ): DisponibilidadResource {
        return new DisponibilidadResource(
            $disponibilidad->reemplazar($buscador->encontrarEnFacultad($asesor), $request->validated())
        );
    }
}