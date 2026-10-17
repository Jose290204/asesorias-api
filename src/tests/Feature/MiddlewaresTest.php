<?php

use App\Enums\Rol;
use App\Models\Usuario;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // Rutas de prueba con la misma cadena que usarán las reales
    Route::middleware(['api', 'auth:sanctum', 'activo', 'rol:asesor'])
        ->get('/api/_prueba/asesor', fn () => response()->json(['ok' => true]));
});

it('responde 401 en JSON sin autenticar', function () {
    $this->getJson('/api/_prueba/asesor')
        ->assertUnauthorized()
        ->assertJson(['message' => 'No autenticado.']);
});

it('bloquea con 403 a un usuario pendiente', function () {
    $usuario = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create();
    Sanctum::actingAs($usuario);

    $this->getJson('/api/_prueba/asesor')->assertForbidden();
});

it('bloquea con 403 a un usuario activo sin el rol', function () {
    $usuario = Usuario::factory()->conRol(Rol::Estudiante)->create();
    Sanctum::actingAs($usuario);

    $this->getJson('/api/_prueba/asesor')->assertForbidden();
});

it('deja pasar a un asesor activo', function () {
    $usuario = Usuario::factory()->conRol(Rol::Asesor)->create();
    Sanctum::actingAs($usuario);

    $this->getJson('/api/_prueba/asesor')->assertOk()->assertJson(['ok' => true]);
});

it('deja pasar al asesor par con cualquiera de sus dos roles', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    Sanctum::actingAs($par);

    $this->getJson('/api/_prueba/asesor')->assertOk();
});