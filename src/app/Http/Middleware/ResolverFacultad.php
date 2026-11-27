<?php

namespace App\Http\Middleware;

use App\Models\Facultad;
use App\Support\ContextoFacultad;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolverFacultad
{
    public function __construct(private ContextoFacultad $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var \App\Models\Usuario|null $usuario */
        $usuario = $request->user();

        if (! $usuario) {
        $this->contexto->establecer(null);
        return $next($request);
        }

        // Usuarios normales: el header se ignora, su facultad es la suya
        if (! $usuario->esSuperAdmin()) {
            $this->contexto->establecer($usuario->facultad_id);
            return $next($request);
        }

        // Super admin SIN header: modo global
        $header = $request->header('X-Facultad-Id');
        if ($header === null || $header === '') {
            $this->contexto->establecer(null);
            return $next($request);
        }

        // Super admin CON header: debe ser un id de una facultad existente y activa
        $facultad = ctype_digit((string) $header)
            ? Facultad::find((int) $header)
            : null;

        if (! $facultad || ! $facultad->activa) {
            return response()->json([
                'message' => 'La facultad indicada no existe o está inactiva.',
                'codigo'  => 'facultad_invalida',
            ], 422);
        }

        $this->contexto->establecer($facultad->id);

        return $next($request);
    }
}