<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CrearSolicitudRequest;
use App\Http\Resources\SolicitudResource;
use App\Services\SolicitudService;

class SolicitudController extends Controller
{
    public function __construct(private SolicitudService $solicitudes)
    {
    }

    // POST /estudiante/solicitudes
    public function crear(CrearSolicitudRequest $request): SolicitudResource
    {
        $solicitud = $this->solicitudes->crear($request->user(), $request->validated());

        return new SolicitudResource($solicitud);
    }
}