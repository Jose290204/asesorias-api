<?php

namespace App\Http\Middleware;

use App\Support\ContextoFacultad;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequiereFacultad
{
    public function __construct(private ContextoFacultad $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->contexto->estaDefinido()) {
            return response()->json([
                'message' => 'Selecciona una facultad para continuar.',
                'codigo'  => 'facultad_requerida',
            ], 422);
        }

        return $next($request);
    }
}