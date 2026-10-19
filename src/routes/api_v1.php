<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\RecuperacionController;
use Illuminate\Support\Facades\Route;




// Público y autenticación

// El limitador "login" cuenta por correo + IP (definido en AppServiceProvider)
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

// Logout solo exige estar autenticado, NO cuenta activa: una cuenta desactivada
// con sesión abierta tiene que poder salir.
Route::post('/auth/logout', [AuthController::class, 'logout'])
    ->middleware('auth:sanctum');

// Recuperación de contraseña (pública). Cada paso con su limitador (clave correo + IP)
Route::post('/auth/codigo', [RecuperacionController::class, 'solicitar'])
    ->middleware('throttle:codigo');
Route::post('/auth/codigo/verificar', [RecuperacionController::class, 'verificar'])
    ->middleware('throttle:verificar');
Route::post('/auth/recuperar', [RecuperacionController::class, 'restablecer'])
    ->middleware('throttle:verificar');


// Compartido: cualquier usuario autenticado y activo

Route::middleware(['auth:sanctum', 'activo'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
});


// Estudiante. Prefijo /estudiante.

Route::prefix('estudiante')
    ->middleware(['auth:sanctum', 'activo', 'rol:estudiante'])
    ->group(function () {
        // Fase 1.2 en adelante
    });


// Asesor. Prefijo /asesor.

Route::prefix('asesor')
    ->middleware(['auth:sanctum', 'activo', 'rol:asesor'])
    ->group(function () {
        // Fase 1.3 en adelante
    });