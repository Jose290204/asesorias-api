<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AsesorController;
use App\Http\Controllers\Api\V1\AsesoriaController;
use App\Http\Controllers\Api\V1\CatalogoController;
use App\Http\Controllers\Api\V1\DisponibilidadController;
use App\Http\Controllers\Api\V1\MateriaController;
use App\Http\Controllers\Api\V1\PerfilController;
use App\Http\Controllers\Api\V1\RecuperacionController;
use App\Http\Controllers\Api\V1\SolicitudController;
use App\Http\Controllers\Api\V1\EstudianteController;
use App\Http\Controllers\Api\V1\SesionController;
use App\Http\Controllers\Api\V1\MaterialController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\FacultadController;
use App\Http\Controllers\Api\V1\AdministradorController;
use App\Http\Controllers\Api\V1\BitacoraController;
use App\Http\Controllers\Api\V1\Admin\AdminPerfilController;
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

Route::middleware(['auth:sanctum', 'activo', 'facultad'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/catalogos', [CatalogoController::class, 'catalogos']);
    Route::get('/materias', [MateriaController::class, 'index']);
    Route::get('/asesorias/{asesoria}/sesiones', [SesionController::class, 'index']);
    Route::get('/asesorias/{asesoria}/material', [MaterialController::class, 'index']);
    Route::get('/material/{material}/descargar', [MaterialController::class, 'descargar']);
    
     // Chat de la asesoría: lo usan el estudiante y el asesoria, el policy checa y decide quein puede hacer que
    Route::get('/asesorias/{asesoria}/mensajes', [ChatController::class, 'index']);
    Route::post('/asesorias/{asesoria}/mensajes', [ChatController::class, 'enviar'])
        ->middleware('throttle:mensajes');
    Route::post('/asesorias/{asesoria}/mensajes/leidos', [ChatController::class, 'marcarLeidos']);
});



    // Admin de facultad. Prefijo /admin.
    // También lo recorre el super admin, siempre con X-Facultad-Id (requiere.facultad).

    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'activo', 'facultad', 'requiere.facultad', 'rol:admin,superadmin'])
        ->group(function () {
            Route::get('/perfil', [PerfilController::class, 'admin']);
            // Asesorías de la facultad. Los métodos son los mismos del asesor:
            // las policies (gestionar, ver, eliminar) ya admiten a quien administra.
            Route::get('/asesorias', [AsesoriaController::class, 'indexAdmin']);
            Route::post('/asesorias', [AsesoriaController::class, 'crearAdmin']);
            Route::patch('/asesorias/{asesoria}', [AsesoriaController::class, 'editar']);
            Route::post('/asesorias/{asesoria}/completar', [AsesoriaController::class, 'completar']);
            Route::post('/asesorias/{asesoria}/cancelar', [AsesoriaController::class, 'cancelar']);
            Route::post('/asesorias/{asesoria}/sesiones', [SesionController::class, 'registrar']);
            Route::delete('/sesiones/{sesion}', [SesionController::class, 'eliminar']);
            Route::post('/asesorias/{asesoria}/material', [MaterialController::class, 'subir']);
            Route::delete('/material/{material}', [MaterialController::class, 'eliminar']);
            Route::get('/estudiantes', [EstudianteController::class, 'indexAdmin']);
            Route::get('/asesores', [AsesorController::class, 'indexAdmin']);
            Route::get('/asesores/{asesor}/disponibilidad', [DisponibilidadController::class, 'mostrarDeAsesor'])->whereNumber('asesor');
        });


// Estudiante. Prefijo /estudiante.

Route::prefix('estudiante')
    ->middleware(['auth:sanctum', 'activo', 'facultad', 'rol:estudiante'])
    ->group(function () {
        Route::get('/perfil', [PerfilController::class, 'estudiante']);
        Route::get('/materias', [MateriaController::class, 'paraEstudiante']);
        Route::get('/asesores', [AsesorController::class, 'index']);
        Route::post('/solicitudes', [SolicitudController::class, 'crear']);
        Route::get('/solicitudes', [SolicitudController::class, 'index'])->defaults('como', 'estudiante');
        Route::post('/solicitudes/{solicitud}/cancelar', [SolicitudController::class, 'cancelar']);
        Route::patch('/solicitudes/{solicitud}', [SolicitudController::class, 'editar']);
        Route::get('/asesorias', [AsesoriaController::class, 'index'])->defaults('como', 'estudiante');
    });


// Asesor. Prefijo /asesor.

Route::prefix('asesor')
    ->middleware(['auth:sanctum', 'activo', 'facultad', 'rol:asesor'])
    ->group(function () {
        Route::get('/perfil', [PerfilController::class, 'asesor']);
        Route::get('/disponibilidad', [DisponibilidadController::class, 'mostrar']);
        Route::put('/disponibilidad', [DisponibilidadController::class, 'actualizar']);
        Route::get('/solicitudes', [SolicitudController::class, 'index'])->defaults('como', 'asesor');
        Route::post('/solicitudes/{solicitud}/rechazar', [SolicitudController::class, 'rechazar']);
        Route::post('/solicitudes/{solicitud}/aceptar', [SolicitudController::class, 'aceptar']);
        Route::get('/asesorias', [AsesoriaController::class, 'index'])->defaults('como', 'asesor');
        Route::post('/asesorias/{asesoria}/completar', [AsesoriaController::class, 'completar']);
        Route::post('/asesorias/{asesoria}/cancelar', [AsesoriaController::class, 'cancelar']);
        Route::post('/asesorias', [AsesoriaController::class, 'crear']);
        Route::patch('/asesorias/{asesoria}', [AsesoriaController::class, 'editar']);
        Route::get('/estudiantes', [EstudianteController::class, 'index']);
        Route::post('/asesorias/{asesoria}/sesiones', [SesionController::class, 'registrar']);
        Route::delete('/sesiones/{sesion}', [SesionController::class, 'eliminar']);
        Route::post('/asesorias/{asesoria}/material', [MaterialController::class, 'subir']);
        Route::delete('/material/{material}', [MaterialController::class, 'eliminar']);
    });



// Super admin. Prefijo /superadmin.
// Sin 'facultad': aquí el super admin trabaja en modo global, no dentro de una facultad.

Route::prefix('superadmin')
    ->middleware(['auth:sanctum', 'activo', 'rol:superadmin'])
    ->group(function () {
        Route::get('/facultades', [FacultadController::class, 'index']);
        Route::post('/facultades', [FacultadController::class, 'store']);
        // Laravel no lee archivos en un PUT real: el front manda POST con _method=PUT
        Route::put('/facultades/{facultad}', [FacultadController::class, 'update']);
        Route::patch('/facultades/{facultad}/estado', [FacultadController::class, 'estado']);
        Route::get('/administradores', [AdministradorController::class, 'index']);
        Route::post('/administradores', [AdministradorController::class, 'store']);
        Route::put('/administradores/{administrador}', [AdministradorController::class, 'update']);
        Route::patch('/administradores/{administrador}/estado', [AdministradorController::class, 'estado']);
        Route::delete('/administradores/{administrador}', [AdministradorController::class, 'destroy']);
        Route::get('/bitacora', [BitacoraController::class, 'index']);
    });