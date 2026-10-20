<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListarAsesoresRequest;
use App\Http\Resources\AsesorResource;
use App\Services\AsesorService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AsesorController extends Controller
{
    // Laravel inyecta el service solo
    public function __construct(private AsesorService $asesores)
    {
    }

    // GET /estudiante/asesores
    public function index(ListarAsesoresRequest $request): AnonymousResourceCollection
    {
        // collection() sobre un paginador agrega solo "links" y "meta" junto a "data"
        return AsesorResource::collection(
            $this->asesores->buscar($request->user(), $request->validated())
        );
    }
}