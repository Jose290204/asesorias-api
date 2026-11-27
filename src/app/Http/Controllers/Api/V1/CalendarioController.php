<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListarCalendarioRequest;
use App\Services\CalendarioService;

class CalendarioController extends Controller
{
    public function __construct(private CalendarioService $calendario)
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
        ]);
    }
}
