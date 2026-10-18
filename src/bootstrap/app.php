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
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Sin "api:" para controlar los prefijos aquí: cada versión es un archivo de rutas
        then: function () {
            // Infraestructura (ping): /api/ping
            Route::middleware('api')->prefix('api')
                ->group(base_path('routes/api.php'));

            // Negocio, versión 1: /api/v1/...
            // Cuando exista la v2, se agrega otra línea con routes/api_v2.php
            Route::middleware('api')->prefix('api/v1')
                ->group(base_path('routes/api_v1.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum por cookie (sesión + CSRF) para los dominios de SANCTUM_STATEFUL_DOMAINS
        $middleware->statefulApi();

        // ForzarJson corre primero en el grupo api
        $middleware->api(prepend: [ForzarJson::class]);

        $middleware->alias([
            'activo' => UsuarioActivo::class,
            'rol' => RequiereRol::class,
        ]);

        // "activo" y "rol" corren ANTES de buscar el modelo de la ruta ({asesoria}):
        // quien no tiene permiso recibe siempre el mismo error y no sabe si el registro existe
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: UsuarioActivo::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: RequiereRol::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sin sesión válida: 401 en JSON (solo para rutas /api)
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'No autenticado.'], 401);
            }
        });

        // Ruta inexistente o modelo no encontrado: 404 en JSON
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Recurso no encontrado.'], 404);
            }
        });
    })
    ->create();