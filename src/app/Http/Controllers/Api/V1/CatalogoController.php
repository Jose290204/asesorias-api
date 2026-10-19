<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Dia;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Http\Controllers\Controller;
use App\Models\Horario;
use Illuminate\Http\JsonResponse;

class CatalogoController extends Controller
{
    public function catalogos(): JsonResponse
    {
        // cases() devuelve todos los casos del enum; los convertimos a {valor, etiqueta}
        $aLista = fn (array $casos) => array_map(
            fn ($c) => ['valor' => $c->value, 'etiqueta' => $c->etiqueta()],
            $casos
        );

        return response()->json([
            'horarios' => Horario::orderBy('id')->get(['id', 'etiqueta']),
            'modalidades' => $aLista(Modalidad::cases()),
            'razones' => $aLista(RazonAsesoria::cases()),
            'dias' => $aLista(Dia::cases()),
        ]);
    }
}