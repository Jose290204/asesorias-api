<?php

namespace App\Http\Middleware;

use App\Enums\Rol;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequiereRol
{
    // Con rol:asesor,estudiante llega $roles = ['asesor', 'estudiante']
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $usuario = $request->user();

        // 401: no estás autenticado
        if (! $usuario) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        foreach ($roles as $nombre) {
            $rol = Rol::desdeNombre($nombre);

            // Basta con tener uno de los roles listados
            if ($rol && $usuario->tieneRol($rol)) {
                return $next($request);
            }
        }

        // 403: estás autenticado pero no tienes el rol
        return response()->json([
            'message' => 'No tienes permiso para acceder a este recurso.',
        ], 403);
    }
}