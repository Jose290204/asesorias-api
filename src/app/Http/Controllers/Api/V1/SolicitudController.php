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
use App\Http\Requests\RechazarSolicitudRequest;
use App\Http\Requests\EditarSolicitudRequest;

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

    // POST /asesor/solicitudes/{solicitud}/rechazar
    public function rechazar(RechazarSolicitudRequest $request, Solicitud $solicitud): SolicitudResource
    {
        return new SolicitudResource(
            $this->solicitudes->rechazar($solicitud, $request->validated()['motivo_rechazo'])
        );
    }

    // POST /asesor/solicitudes/{solicitud}/aceptar
    public function aceptar(Solicitud $solicitud): SolicitudResource
    {
        // 403 si no es la solicitud de este asesor (no hay body que validar, así que se hace aquí)
        Gate::authorize('responder', $solicitud);

        return new SolicitudResource($this->solicitudes->aceptar($solicitud));
    }

     // PATCH /estudiante/solicitudes/{solicitud}
    public function editar(EditarSolicitudRequest $request, Solicitud $solicitud): SolicitudResource
    {
        return new SolicitudResource(
            $this->solicitudes->editar($request->user(), $solicitud, $request->validated())
        );
    }
}