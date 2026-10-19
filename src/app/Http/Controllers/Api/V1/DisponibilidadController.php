<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarDisponibilidadRequest;
use App\Http\Resources\DisponibilidadResource;
use App\Services\DisponibilidadService;
use Illuminate\Http\Request;

class DisponibilidadController extends Controller
{
    public function __construct(private DisponibilidadService $disponibilidad)
    {
    }

    //GET /asesor/disponibilidad
    public function mostrar(Request $request): DisponibilidadResource
    {
        //$request->user() es el asesor autenticado: el token o la sesion ya nos lo dio
        //nunca se recibe un id por la url, asi que un asesor jamas puede ver las materiaa de otro

        return new DisponibilidadResource($this->disponibilidad->obtener($request->user()));
    }

    //PUT /asesor/disponibilidad
    //con el request, se valida antes de entrar aqui
    public function actualizar(GuardarDisponibilidadRequest $request): DisponibilidadResource
    {
        //validated() devuelve solo los campos que pasaron las reglas 
        //se responde con el estado nuevo, con la misma forma que el GET
        //asei el front no necesita hacer un get despues de guardar
        return new DisponibilidadResource(
            $this->disponibilidad->reemplazar($request->user(), $request->validated())
        );
    }
}