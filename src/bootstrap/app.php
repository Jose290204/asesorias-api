<?php

use App\Http\Middleware\ForzarJson;
use App\Http\Middleware\RequiereRol;
use App\Http\Middleware\UsuarioActivo;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',   // Laravel agrega solo el prefijo /api
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Activa Sanctum por cookie (sesión + CSRF) para los dominios de
        // SANCTUM_STATEFUL_DOMAINS. El token Bearer sigue funcionando igual.
        $middleware->statefulApi();

        // prepend = ForzarJson corre PRIMERO en el grupo api, antes de cualquier
        // otro middleware que pudiera responder con HTML
        $middleware->api(prepend: [ForzarJson::class]);

        // Alias para usarlos en rutas como 'activo' y 'rol:asesor'
        $middleware->alias([
            'activo' => UsuarioActivo::class,
            'rol' => RequiereRol::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sin sesión válida: 401 en JSON (solo para rutas /api)
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'No autenticado.'], 401);
            }
        });

        // Ruta inexistente o route model binding fallido (/asesorias/999): 404 en JSON
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Recurso no encontrado.'], 404);
            }
        });
    })
    ->create();