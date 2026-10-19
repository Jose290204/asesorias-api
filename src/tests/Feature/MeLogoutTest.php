<?php

use App\Enums\Rol;
use App\Models\Usuario;

// Constantes con nombre propio: las de un archivo de Pest son globales
const URL_ME = '/api/v1/auth/me';
const URL_LOGOUT = '/api/v1/auth/logout';
// Con este Origin, Sanctum trata la petición como del front y activa la sesión
const ORIGEN_FRONT = ['Origin' => 'http://localhost:5173'];


// ---------- ME ----------

it('me devuelve 401 sin sesión ni token', function () {
    $this->getJson(URL_ME)->assertUnauthorized();
});

it('me devuelve el usuario y sus roles con sesión web', function () {
    $u = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();

    // actingAs con el guard "web" simula una sesión ya iniciada
    $r = $this->actingAs($u, 'web')
        ->withHeaders(ORIGEN_FRONT)
        ->getJson(URL_ME)
        ->assertOk()
        ->assertJsonPath('data.correo', $u->correo)
        ->assertJsonPath('data.facultad.siglas', 'FIC');

    expect($r->json('data.roles'))->toContain(3, 4);
});

it('me funciona con token Bearer', function () {
    $u = Usuario::factory()->create();
    $token = $u->createToken('android')->plainTextToken;

    $this->withToken($token)
        ->getJson(URL_ME)
        ->assertOk()
        ->assertJsonPath('data.id', $u->id);
});

it('me bloquea con 403 a una cuenta no activa', function () {
    $u = Usuario::factory()->pendiente()->create(['password' => 'secreto123']);
    $token = $u->createToken('android')->plainTextToken;

    $this->withToken($token)
        ->getJson(URL_ME)
        ->assertForbidden();
});


// ---------- LOGOUT ----------

it('logout devuelve 401 sin autenticar', function () {
    $this->postJson(URL_LOGOUT)->assertUnauthorized();
});

it('logout con token revoca solo el token usado', function () {
    $u = Usuario::factory()->create();
    $tokenAndroid = $u->createToken('android')->plainTextToken;
    $tokenIphone = $u->createToken('iphone')->plainTextToken;

    $this->withToken($tokenAndroid)->postJson(URL_LOGOUT)->assertNoContent();

    // Quedó solo el del otro dispositivo
    expect($u->tokens()->count())->toBe(1);

    // El guard guarda en memoria al usuario de la petición anterior; sin esto
    // la siguiente petición podría "heredarlo" y la prueba daría un falso resultado
    $this->app['auth']->forgetGuards();

    // El token revocado ya no sirve...
    $this->withToken($tokenAndroid)->getJson(URL_ME)->assertUnauthorized();

    $this->app['auth']->forgetGuards();

    // ...y el del otro dispositivo sigue vivo (hermana feliz)
    $this->withToken($tokenIphone)->getJson(URL_ME)->assertOk();
});

it('logout con sesión web cierra la sesión', function () {
    $u = Usuario::factory()->create();

    $this->actingAs($u, 'web')
        ->withHeaders(ORIGEN_FRONT)
        ->postJson(URL_LOGOUT)
        ->assertNoContent();

    $this->assertGuest('web');
});

it('logout permite salir a una cuenta no activa', function () {
    $u = Usuario::factory()->pendiente()->create(['password' => 'secreto123']);
    $token = $u->createToken('android')->plainTextToken;

    $this->withToken($token)->postJson(URL_LOGOUT)->assertNoContent();

    expect($u->tokens()->count())->toBe(0);
});