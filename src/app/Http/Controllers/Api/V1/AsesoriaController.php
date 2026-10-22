<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListarAsesoriasRequest;
use App\Http\Resources\AsesoriaResource;
use App\Services\AsesoriaService;

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
}