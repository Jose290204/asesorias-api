<?php

namespace App\Http\Middleware;

use App\Enums\EstatusUsuario;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UsuarioActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        // 403: sé quién eres, pero tu cuenta no está activa (por ejemplo, pendiente)
        if (! $usuario || $usuario->estatus !== EstatusUsuario::Activo) {
            return response()->json([
                'message' => 'Tu cuenta no está activa.',
            ], 403);
        }

        // Los usuarios de una facultad desactivada no pueden usar la API (el super admin sí)
        if (! $usuario->esSuperAdmin() && ! $usuario->facultad?->activa) {
            return response()->json([
                'message' => 'Tu facultad está desactivada.',
                'codigo'  => 'facultad_inactiva',
            ], 403);
        }

        return $next($request);
    }
}