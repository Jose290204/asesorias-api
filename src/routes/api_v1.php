<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

// Las rutas van agrupadas por bloque. Cada tarea agrega sus rutas
// SOLO dentro de su bloque, así dos ramas que tocan este archivo no chocan en git.


// Público y autenticación

// El limitador "login" cuenta por correo + IP (definido en AppServiceProvider)
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');


// Compartido: cualquier usuario autenticado y activo

Route::middleware(['auth:sanctum', 'activo'])->group(function () {
    // GET /auth/me
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