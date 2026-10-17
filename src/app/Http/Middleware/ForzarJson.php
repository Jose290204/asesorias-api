<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForzarJson
{
    public function handle(Request $request, Closure $next): Response
    {
        // Laravel decide el formato de los errores según este header;
        // así nunca responde con redirecciones o páginas HTML
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}