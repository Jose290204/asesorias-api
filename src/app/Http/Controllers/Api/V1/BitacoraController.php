<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListarBitacoraRequest;
use App\Http\Resources\BitacoraResource;
use App\Services\BitacoraService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BitacoraController extends Controller
{
    // GET /superadmin/bitacora?facultad_id=&accion=&buscar=&desde=&hasta=&per_page=&page=
    // Solo lectura: la bitácora no se crea, edita ni borra desde la API.
    public function index(ListarBitacoraRequest $request, BitacoraService $bitacora): AnonymousResourceCollection
    {
        return BitacoraResource::collection($bitacora->listar($request->validated()));
    }
}