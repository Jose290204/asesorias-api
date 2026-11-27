<?php

// Guardar como: tests/Feature/ContextoFacultadTest.php
// Autocontenido (prefijo "ctx"): las funciones y constantes de los archivos de Pest son globales.

use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Models\Facultad;
use App\Models\Licenciatura;
use App\Models\Usuario;
use App\Support\ContextoFacultad;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

const URL_CTX_LOGIN = '/api/v1/auth/login';
const URL_CTX_LISTA = '/api/_prueba/ctx/licenciaturas';
const URL_CTX_ADMIN = '/api/_prueba/ctx/admin';
const URL_CTX_CREAR = '/api/_prueba/ctx/crear';

// ---------- Ayudantes ----------

function ctxFacultad(string $siglas): Facultad
{
    return UsuarioFactory::facultad($siglas);
}

function ctxSuperAdmin(): Usuario
{
    // El super admin pertenece a la FIC (facultad de origen)
    return Usuario::factory()->conRol(Rol::SuperAdmin)->create();
}

function ctxUsuario(Rol $rol, ?Facultad $facultad = null): Usuario
{
    return Usuario::factory()
        ->conRol($rol)
        ->create(['facultad_id' => ($facultad ?? ctxFacultad('FIC'))->id]);
}

// Una licenciatura por facultad para distinguir qué ve cada quien.
// forceCreate + withoutGlobalScopes: no depende del usuario autenticado en el momento de crear.
function ctxLicenciatura(Facultad $facultad, string $nombre): Licenciatura
{
    return Licenciatura::withoutGlobalScopes()->forceCreate([
        'facultad_id' => $facultad->id,
        'nombre' => $nombre,
    ]);
}

beforeEach(function () {
    // Mismas cadenas de middlewares que usarán las rutas reales
    Route::middleware(['api', 'auth:sanctum', 'activo', 'facultad'])
        ->get('/api/_prueba/ctx/licenciaturas', fn () => response()->json([
            'nombres' => Licenciatura::orderBy('nombre')->pluck('nombre'),
            'contexto' => app(ContextoFacultad::class)->id(),
        ]));

    Route::middleware(['api', 'auth:sanctum', 'activo', 'facultad', 'requiere.facultad'])
        ->get('/api/_prueba/ctx/admin', fn () => response()->json(['ok' => true]));

    Route::middleware(['api', 'auth:sanctum', 'activo', 'facultad', 'requiere.facultad'])
        ->post('/api/_prueba/ctx/crear', function () {
            $l = Licenciatura::create(['nombre' => 'CREADA EN CONTEXTO']);

            return response()->json(['facultad_id' => $l->facultad_id]);
        });
});

// Evita que el singleton conserve el contexto entre pruebas
afterEach(function () {
    app(ContextoFacultad::class)->establecer(null);
});

// ---------- Usuarios normales: el header se ignora ----------

it('ignora el header X-Facultad-Id de un admin y usa su propia facultad', function () {
    $fic = ctxFacultad('FIC');
    $otra = ctxFacultad('OTRA');
    ctxLicenciatura($fic, 'DE LA FIC');
    ctxLicenciatura($otra, 'DE OTRA');

    Sanctum::actingAs(ctxUsuario(Rol::Admin, $fic));

    $this->withHeaders(['X-Facultad-Id' => (string) $otra->id])
        ->getJson(URL_CTX_LISTA)
        ->assertOk()
        ->assertJsonPath('contexto', $fic->id)
        ->assertJson(['nombres' => ['DE LA FIC']]);
});

it('ignora el header de un asesor y de un estudiante', function (Rol $rol) {
    $fic = ctxFacultad('FIC');
    $otra = ctxFacultad('OTRA');
    ctxLicenciatura($fic, 'DE LA FIC');
    ctxLicenciatura($otra, 'DE OTRA');

    Sanctum::actingAs(ctxUsuario($rol, $fic));

    $this->withHeaders(['X-Facultad-Id' => (string) $otra->id])
        ->getJson(URL_CTX_LISTA)
        ->assertOk()
        ->assertJson(['nombres' => ['DE LA FIC']]);
})->with([Rol::Asesor, Rol::Estudiante]);

it('un admin con header basura no recibe error (se ignora)', function () {
    Sanctum::actingAs(ctxUsuario(Rol::Admin));

    $this->withHeaders(['X-Facultad-Id' => 'no-es-un-numero'])
        ->getJson(URL_CTX_LISTA)
        ->assertOk();
});

// ---------- Super admin ----------

it('el super admin con header ve solo la facultad elegida', function () {
    $fic = ctxFacultad('FIC');
    $otra = ctxFacultad('OTRA');
    ctxLicenciatura($fic, 'DE LA FIC');
    ctxLicenciatura($otra, 'DE OTRA');

    Sanctum::actingAs(ctxSuperAdmin());

    $this->withHeaders(['X-Facultad-Id' => (string) $otra->id])
        ->getJson(URL_CTX_LISTA)
        ->assertOk()
        ->assertJsonPath('contexto', $otra->id)
        ->assertJson(['nombres' => ['DE OTRA']]);
});

it('el super admin sin header está en modo global y ve todas las facultades', function () {
    $fic = ctxFacultad('FIC');
    $otra = ctxFacultad('OTRA');
    ctxLicenciatura($fic, 'DE LA FIC');
    ctxLicenciatura($otra, 'DE OTRA');

    Sanctum::actingAs(ctxSuperAdmin());

    $this->getJson(URL_CTX_LISTA)
        ->assertOk()
        ->assertJsonPath('contexto', null)
        ->assertJson(['nombres' => ['DE LA FIC', 'DE OTRA']]);
});

it('el super admin sin header recibe 422 facultad_requerida en rutas que la exigen', function () {
    Sanctum::actingAs(ctxSuperAdmin());

    $this->getJson(URL_CTX_ADMIN)
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'facultad_requerida');
});

it('el super admin con header válido pasa por rutas que exigen facultad', function () {
    $fic = ctxFacultad('FIC');
    Sanctum::actingAs(ctxSuperAdmin());

    $this->withHeaders(['X-Facultad-Id' => (string) $fic->id])
        ->getJson(URL_CTX_ADMIN)
        ->assertOk();
});

it('el super admin con una facultad inexistente recibe 422 facultad_invalida', function () {
    Sanctum::actingAs(ctxSuperAdmin());

    $this->withHeaders(['X-Facultad-Id' => '99999'])
        ->getJson(URL_CTX_LISTA)
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'facultad_invalida');
});

it('el super admin con un header que no es número recibe 422 facultad_invalida', function () {
    Sanctum::actingAs(ctxSuperAdmin());

    $this->withHeaders(['X-Facultad-Id' => 'abc'])
        ->getJson(URL_CTX_LISTA)
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'facultad_invalida');
});

it('el super admin con una facultad inactiva recibe 422 facultad_invalida', function () {
    $otra = ctxFacultad('OTRA');
    $otra->update(['activa' => false]);

    Sanctum::actingAs(ctxSuperAdmin());

    $this->withHeaders(['X-Facultad-Id' => (string) $otra->id])
        ->getJson(URL_CTX_LISTA)
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'facultad_invalida');
});

// ---------- Crear registros dentro del contexto ----------

it('un registro creado por el super admin dentro de una facultad queda en esa facultad', function () {
    ctxFacultad('FIC');
    $otra = ctxFacultad('OTRA');

    Sanctum::actingAs(ctxSuperAdmin());

    $this->withHeaders(['X-Facultad-Id' => (string) $otra->id])
        ->postJson(URL_CTX_CREAR)
        ->assertOk()
        ->assertJsonPath('facultad_id', $otra->id);

    expect(
        Licenciatura::withoutGlobalScopes()->where('nombre', 'CREADA EN CONTEXTO')->value('facultad_id')
    )->toBe($otra->id);
});

it('un registro creado por un admin queda en su propia facultad', function () {
    $fic = ctxFacultad('FIC');
    Sanctum::actingAs(ctxUsuario(Rol::Admin, $fic));

    $this->postJson(URL_CTX_CREAR)
        ->assertOk()
        ->assertJsonPath('facultad_id', $fic->id);
});

// ---------- Facultad inactiva ----------

it('bloquea con 403 facultad_inactiva a un usuario con sesión cuando su facultad se desactiva', function (Rol $rol) {
    $otra = ctxFacultad('OTRA');
    $usuario = ctxUsuario($rol, $otra);
    Sanctum::actingAs($usuario);

    $this->getJson(URL_CTX_LISTA)->assertOk();

    $otra->update(['activa' => false]);

    // Se vuelve a leer el usuario para que traiga la facultad actualizada
    Sanctum::actingAs($usuario->fresh());

    $this->getJson(URL_CTX_LISTA)
        ->assertForbidden()
        ->assertJsonPath('codigo', 'facultad_inactiva');
})->with([Rol::Admin, Rol::Asesor, Rol::Estudiante]);

it('el super admin sigue entrando aunque su facultad de origen esté inactiva', function () {
    $fic = ctxFacultad('FIC');
    $superAdmin = ctxSuperAdmin();
    $fic->update(['activa' => false]);

    Sanctum::actingAs($superAdmin->fresh());

    $this->getJson(URL_CTX_LISTA)->assertOk();
});

it('no deja iniciar sesión a un usuario de una facultad inactiva', function () {
    $otra = ctxFacultad('OTRA');
    $usuario = ctxUsuario(Rol::Admin, $otra);
    $usuario->update(['password' => 'secreto123']);
    $otra->update(['activa' => false]);

    $this->postJson(URL_CTX_LOGIN, [
        'correo' => $usuario->correo,
        'password' => 'secreto123',
        'dispositivo' => 'android',
    ])->assertForbidden()
        ->assertJsonPath('codigo', 'facultad_inactiva');
});

it('el super admin puede iniciar sesión aunque la FIC esté inactiva', function () {
    $fic = ctxFacultad('FIC');
    $superAdmin = ctxSuperAdmin();
    $superAdmin->update(['password' => 'secreto123']);
    $fic->update(['activa' => false]);

    $this->postJson(URL_CTX_LOGIN, [
        'correo' => $superAdmin->correo,
        'password' => 'secreto123',
        'dispositivo' => 'android',
    ])->assertOk();
});

it('con contraseña incorrecta el login sigue dando 422 aunque la facultad esté inactiva', function () {
    $otra = ctxFacultad('OTRA');
    $usuario = ctxUsuario(Rol::Admin, $otra);
    $otra->update(['activa' => false]);

    // No se revela el estado de la facultad a quien no conoce la contraseña
    $this->postJson(URL_CTX_LOGIN, [
        'correo' => $usuario->correo,
        'password' => 'incorrecta',
        'dispositivo' => 'android',
    ])->assertStatus(422);
});

it('un usuario pendiente sigue recibiendo 403 por cuenta inactiva, no por facultad', function () {
    $usuario = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create();
    Sanctum::actingAs($usuario);

    $this->getJson(URL_CTX_LISTA)->assertForbidden();
    expect($usuario->estatus)->toBe(EstatusUsuario::Pendiente);
});