<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubirEvidenciasRequest;
use App\Http\Resources\EvidenciaResource;
use App\Models\EvidenciaSesion;
use App\Models\Sesion;
use App\Services\EvidenciaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenciaController extends Controller
{
    // POST /asesor/sesiones/{sesion}/evidencias (y /admin/...): responde 201 con las recién subidas.
    // El permiso lo revisa SubirEvidenciasRequest::authorize().
    public function subir(SubirEvidenciasRequest $request, Sesion $sesion, EvidenciaService $servicio): JsonResponse
    {
        return EvidenciaResource::collection(
            $servicio->subir($sesion, $request->user(), $request->file('archivos'))
        )->response()->setStatusCode(201);
    }

    // DELETE /asesor/evidencias/{evidencia} (y /admin/...): responde 204
    public function eliminar(EvidenciaSesion $evidencia, EvidenciaService $servicio): Response
    {
        Gate::authorize('eliminar', $evidencia);

        $servicio->eliminar($evidencia);

        return response()->noContent();
    }

    // GET /evidencias/{evidencia}/descargar: la descargan quienes participan en la asesoría
    public function descargar(EvidenciaSesion $evidencia, EvidenciaService $servicio): StreamedResponse
    {
        Gate::authorize('descargar', $evidencia);

        return $servicio->descargar($evidencia);
    }
}