<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubirMaterialRequest;
use App\Http\Resources\MaterialResource;
use App\Models\Asesoria;
use App\Models\MaterialAdicional;
use App\Services\MaterialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaterialController extends Controller
{
    // GET /asesorias/{asesoria}/material: lo ven el asesor asignado y el estudiante de la asesoría
    public function index(Asesoria $asesoria, MaterialService $servicio): AnonymousResourceCollection
    {
        // No hay body que validar, así que el permiso se revisa aquí (403 si no participa).
        // El 404 (no existe u otra facultad) ya salió antes, al resolver {asesoria}
        Gate::authorize('ver', $asesoria);

        return MaterialResource::collection($servicio->listar($asesoria));
    }

    // POST /asesor/asesorias/{asesoria}/material: responde 201 con la lista de archivos recién subidos.
    // El permiso (AsesoriaPolicy::gestionar) lo revisa SubirMaterialRequest::authorize().
    public function subir(SubirMaterialRequest $request, Asesoria $asesoria, MaterialService $servicio): JsonResponse
    {
        // Una colección responde 200 por defecto; aquí se fuerza el 201 de "creado"
        return MaterialResource::collection(
            $servicio->subir($asesoria, $request->user(), $request->file('archivos'))
        )->response()->setStatusCode(201);
    }

    // GET /material/{material}/descargar: lo descargan los dos participantes de la asesoría
    public function descargar(MaterialAdicional $material, MaterialService $servicio): StreamedResponse
    {
        Gate::authorize('descargar', $material);

        return $servicio->descargar($material);
    }

    // DELETE /asesor/material/{material}: responde 204 sin cuerpo. Solo quien lo subió.
    public function eliminar(MaterialAdicional $material, MaterialService $servicio): Response
    {
        Gate::authorize('eliminar', $material);

        $servicio->eliminar($material);

        return response()->noContent();
    }
}