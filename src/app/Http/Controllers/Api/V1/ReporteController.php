<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListarReporteRequest;
use App\Services\ReporteService;
use Illuminate\Http\JsonResponse;

// Reportes del administrador. Solo lectura; las rutas ya validan {tipo} (ver routes/api_v1.php).
class ReporteController extends Controller
{
    // GET /admin/ciclos
    public function ciclos(ReporteService $reportes): JsonResponse
    {
        return response()->json(['data' => $reportes->ciclos()]);
    }

    // GET /admin/reportes/{tipo}?ciclo_id=   (asesores | materias | estudiantes | grupos | licenciaturas)
    public function resumen(ListarReporteRequest $request, ReporteService $reportes, string $tipo): JsonResponse
    {
        return response()->json(['data' => $reportes->resumen($tipo, (int) $request->validated('ciclo_id'))]);
    }

    // GET /admin/reportes/{tipo}/{id}/asesorias?ciclo_id=
    public function asesorias(ListarReporteRequest $request, ReporteService $reportes, string $tipo, int $id): JsonResponse
    {
        return response()->json(['data' => $reportes->asesorias($tipo, $id, (int) $request->validated('ciclo_id'))]);
    }
}