<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CrearSolicitudRequest;
use App\Http\Requests\ListarSolicitudesRequest;
use App\Http\Resources\SolicitudResource;
use App\Services\SolicitudService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Enums\EstatusSolicitud;
use App\Models\Solicitud;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;

class SolicitudController extends Controller
{
    public function __construct(private SolicitudService $solicitudes)
    {
    }


    // GET /estudiante/solicitudes y GET /asesor/solicitudes (?estatus=)
    public function index(ListarSolicitudesRequest $request)
    {
        $solicitudes = $this->solicitudes->listar(
            $request->user(),
            // 'como' lo pone la ruta con ->defaults(); no viene del cliente
            $request->route('como'),
            // enum() devuelve el enum o null si el parámetro no vino
            $request->enum('estatus', EstatusSolicitud::class),
        );

        return SolicitudResource::collection($solicitudes);
    }

    // POST /estudiante/solicitudes
    public function crear(CrearSolicitudRequest $request): SolicitudResource
    {
        $solicitud = $this->solicitudes->crear($request->user(), $request->validated());

        return new SolicitudResource($solicitud);
    }

    // POST /estudiante/solicitudes/{solicitud}/cancelar
    public function cancelar(Request $request, Solicitud $solicitud): SolicitudResource
    {
        // 403 si la policy dice false. Si dice true, el código sigue.
        Gate::authorize('cancelar', $solicitud);

        // El service hace el trabajo y devuelve la solicitud ya cancelada
        return new SolicitudResource($this->solicitudes->cancelar($solicitud));
    }
}