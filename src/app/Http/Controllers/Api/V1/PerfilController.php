<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerfilResource;
use App\Services\EstadisticasService;
use Illuminate\Http\Request;

class PerfilController extends Controller
{
    // El service se inyecta solo: Laravel lo crea y lo pasa al constructor
    public function __construct(private EstadisticasService $estadisticas)
    {
    }

    // GET /estudiante/perfil
    public function estudiante(Request $request): PerfilResource
    {
        $usuario = $request->user()->load('grupo');
        $usuario->setAttribute('estadisticas', $this->estadisticas->delEstudiante($usuario));

        return new PerfilResource($usuario);
    }

    // GET /asesor/perfil
    public function asesor(Request $request): PerfilResource
    {
        $usuario = $request->user()->load('grupo');
        $usuario->setAttribute('estadisticas', $this->estadisticas->delAsesor($usuario));

        return new PerfilResource($usuario);
    }
}