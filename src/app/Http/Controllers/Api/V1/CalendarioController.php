<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListarCalendarioRequest;
use App\Services\CalendarioService;
use App\Services\DiasHabilesService;

class CalendarioController extends Controller
{
    public function __construct(private CalendarioService $calendario, private DiasHabilesService $dias)
    {
    }

    // GET /estudiante/calendario y GET /asesor/calendario (?desde=YYYY-MM-DD&hasta=YYYY-MM-DD)
    public function index(ListarCalendarioRequest $request)
    {
        return response()->json([
            'data' => $this->calendario->ocurrencias(
                $request->user(),
                // 'como' lo pone la ruta con ->defaults(); no viene del cliente
                $request->route('como'),
                $request->desde(),
                $request->hasta(),
            ),
            // Vacaciones y suspensiones del rango, para marcarlas en el calendario
            'inhabiles' => $this->dias->aArreglo(
                $this->dias->rango($request->user()->facultad_id, $request->desde(), $request->hasta())
            ),
        ]);
    }
}
