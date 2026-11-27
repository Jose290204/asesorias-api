<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Dia;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Http\Controllers\Controller;
use App\Models\Horario;
use App\Models\CicloEscolar;
use Illuminate\Http\JsonResponse;
use App\Models\Grupo;
use App\Models\Licenciatura;

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
            // Para los filtros del administrador. Licenciatura ya filtra por facultad;
            // los grupos llegan a la facultad por su licenciatura.
            'licenciaturas' => Licenciatura::orderBy('nombre')->get(['id', 'nombre']),
            'grupos' => Grupo::whereHas('licenciatura')
                ->orderBy('nombre')
                ->get(['id', 'licenciatura_id', 'nombre', 'modalidad']),
            'ciclo_escolar' => CicloEscolar::vigente()->first(['id', 'nombre', 'periodo']),
        ]);
    }
}