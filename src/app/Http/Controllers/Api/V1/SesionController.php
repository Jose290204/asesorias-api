<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SesionResource;
use App\Models\Asesoria;
use App\Services\SesionService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
}