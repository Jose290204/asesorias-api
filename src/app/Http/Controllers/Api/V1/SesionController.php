<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarSesionRequest;
use App\Http\Resources\SesionResource;
use App\Models\Asesoria;
use App\Models\Sesion;
use App\Services\SesionService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SesionController extends Controller
{
    // GET /asesorias/{asesoria}/sesiones: las ven el asesor asignado y el estudiante de la asesoría
    public function index(Asesoria $asesoria, SesionService $sesiones): AnonymousResourceCollection
    {
        // No hay body que validar, así que el permiso se revisa aquí (403 si no participa).
        // El 404 (no existe u otra facultad) ya salió antes, al resolver {asesoria}
        Gate::authorize('ver', $asesoria);

        return SesionResource::collection($sesiones->listar($asesoria));
    }

    // POST /asesor/asesorias/{asesoria}/sesiones: responde 201 con la sesión recién creada.
    // El permiso (AsesoriaPolicy::gestionar) lo revisa RegistrarSesionRequest::authorize().
    public function registrar(RegistrarSesionRequest $request, Asesoria $asesoria, SesionService $sesiones): SesionResource
    {
        return new SesionResource(
            $sesiones->registrar($asesoria, $request->user(), $request->validated())
        );
    }

        // DELETE /asesor/sesiones/{sesion}: responde 204 sin cuerpo.
    public function eliminar(Sesion $sesion, SesionService $sesiones): Response
    {
        Gate::authorize('eliminar', $sesion);

        $sesiones->eliminar($sesion);

        return response()->noContent();
    }
}