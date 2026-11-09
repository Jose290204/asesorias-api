<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CambiarEstadoFacultadRequest;
use App\Http\Requests\GuardarFacultadRequest;
use App\Http\Resources\FacultadResource;
use App\Models\Facultad;
use App\Services\FacultadService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FacultadController extends Controller
{
    // GET /superadmin/facultades: todas, con sus conteos 
    public function index(): AnonymousResourceCollection
    {
        return FacultadResource::collection(
            Facultad::conConteos()->orderBy('nombre')->get()
        );
    }

    // POST /superadmin/facultades (multipart): responde 201
    public function store(GuardarFacultadRequest $request, FacultadService $facultades): FacultadResource
    {
        $facultad = $facultades->crear($request->validated(), $request->file('logo'));

        return new FacultadResource($facultad->loadCount(Facultad::conteos()));
    }

    // PUT /superadmin/facultades/{facultad}
    public function update(GuardarFacultadRequest $request, Facultad $facultad, FacultadService $facultades): FacultadResource
    {
        $facultad = $facultades->actualizar(
            $facultad,
            $request->validated(),
            $request->file('logo'),
            $request->boolean('quitar_logo'),
        );

        return new FacultadResource($facultad->loadCount(Facultad::conteos()));
    }

    // PATCH /superadmin/facultades/{facultad}/estado
    public function estado(CambiarEstadoFacultadRequest $request, Facultad $facultad, FacultadService $facultades): FacultadResource
    {
        $facultad = $facultades->cambiarEstado($facultad, $request->boolean('activa'));

        return new FacultadResource($facultad->loadCount(Facultad::conteos()));
    }
}