<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListarAsesoriasRequest;
use App\Http\Requests\EditarAsesoriaRequest;
use App\Http\Resources\AsesoriaResource;
use App\Services\AsesoriaService;
use App\Models\Asesoria;
use Illuminate\Support\Facades\Gate;
use App\Http\Requests\CancelarAsesoriaRequest;
use App\Http\Requests\CrearAsesoriaRequest;

class AsesoriaController extends Controller
{
    public function __construct(private AsesoriaService $asesorias)
    {
    }

    // GET /estudiante/asesorias y GET /asesor/asesorias (?estatus=&buscar=)
    public function index(ListarAsesoriasRequest $request)
    {
        $asesorias = $this->asesorias->listar(
            $request->user(),
            // 'como' lo pone la ruta con ->defaults(); no viene del cliente
            $request->route('como'),
            $request->input('estatus'),
            $request->input('buscar'),
        );

        return AsesoriaResource::collection($asesorias);
    }

     // POST /asesor/asesorias/{asesoria}/completar
    public function completar(Asesoria $asesoria): AsesoriaResource
    {
        // 403 si no es el asesor asignado. Sin body que validar, así que se hace aquí;
        // el 403 sale antes que cualquier 409 del service
        Gate::authorize('gestionar', $asesoria);

        return new AsesoriaResource($this->asesorias->completar($asesoria));
    }

        // POST /asesor/asesorias/{asesoria}/cancelar
    public function cancelar(CancelarAsesoriaRequest $request, Asesoria $asesoria): AsesoriaResource
    {
        return new AsesoriaResource(
            $this->asesorias->cancelar($asesoria, $request->validated()['motivo_cancelacion'])
        );
    }

    // POST /asesor/asesorias
    public function crear(CrearAsesoriaRequest $request, AsesoriaService $asesorias): AsesoriaResource
    {
        return new AsesoriaResource(
            $asesorias->crear($request->user(), $request->validated())
        );
    }

    // PATCH /asesor/asesorias/{asesoria}
    // El permiso (AsesoriaPolicy::gestionar) lo revisa EditarAsesoriaRequest::authorize().
    public function editar(EditarAsesoriaRequest $request, Asesoria $asesoria, AsesoriaService $asesorias): AsesoriaResource
    {
        return new AsesoriaResource(
            $asesorias->editar($asesoria, $request->validated())
        );
    }
}