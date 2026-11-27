<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstatusUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\CambiarEstadoAdministradorRequest;
use App\Http\Requests\GuardarAdministradorRequest;
use App\Http\Requests\ListarAdministradoresRequest;
use App\Http\Resources\AdministradorResource;
use App\Models\Usuario;
use App\Services\AdministradorService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AdministradorController extends Controller
{
    // GET /superadmin/administradores?facultad_id=&estatus=&buscar=
    public function index(ListarAdministradoresRequest $request, AdministradorService $administradores): AnonymousResourceCollection
    {
        return AdministradorResource::collection($administradores->listar($request->validated()));
    }

    // POST /superadmin/administradores: responde 201
    public function store(GuardarAdministradorRequest $request, AdministradorService $administradores): AdministradorResource
    {
        return new AdministradorResource($administradores->crear($request->validated()));
    }

    // PUT /superadmin/administradores/{administrador}
    public function update(GuardarAdministradorRequest $request, Usuario $administrador, AdministradorService $administradores): AdministradorResource
    {
        return new AdministradorResource($administradores->actualizar($administrador, $request->validated()));
    }

    // PATCH /superadmin/administradores/{administrador}/estado
    public function estado(CambiarEstadoAdministradorRequest $request, Usuario $administrador, AdministradorService $administradores): AdministradorResource
    {
        $estatus = EstatusUsuario::from($request->validated('estatus'));

        return new AdministradorResource($administradores->cambiarEstado($administrador, $estatus));
    }

    // DELETE /superadmin/administradores/{administrador}: responde 204
    public function destroy(Usuario $administrador, AdministradorService $administradores): Response
    {
        $administradores->eliminar($administrador);

        return response()->noContent();
    }
}