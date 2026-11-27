<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListarDiasInhabilesRequest;
use App\Services\DiasHabilesService;
use App\Support\ContextoFacultad;

class DiaInhabilController extends Controller
{
    public function __construct(private DiasHabilesService $dias)
    {
    }

    // GET /dias-inhabiles?desde=&hasta= : vacaciones y suspensiones de la facultad (para deshabilitar fechas en el front)
    public function index(ListarDiasInhabilesRequest $request)
    {
        $facultadId = app(ContextoFacultad::class)->id() ?? $request->user()->facultad_id;

        return response()->json([
            'data' => $this->dias->aArreglo($this->dias->rango($facultadId, $request->desde(), $request->hasta())),
        ]);
    }
}
