<?php

use App\Enums\Rol;
use App\Models\Usuario;

// Un solo lugar para la URL: si cambia la versión, se cambia aquí.
// Es una constante y no una propiedad de $this, para que el editor no marque rojo.
const URL_LOGIN = '/api/v1/auth/login';

it('inicia sesión en la web y devuelve los roles sin token', function () {
    $u = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create(['password' => 'secreto123']);

    // El header Origin hace que la petición cuente como del front (la sesión solo existe así)
    $r = $this->withHeaders(['Origin' => 'http://localhost:5173'])
        ->postJson(URL_LOGIN, ['correo' => $u->correo, 'password' => 'secreto123'])
        ->assertOk()
        ->assertJsonPath('data.correo', $u->correo);

    // Números: 3 = asesor, 4 = estudiante (el orden no está garantizado)
    expect($r->json('data.roles'))->toContain(3, 4);
    expect($r->json('token'))->toBeNull();
});

it('devuelve un token cuando viene "dispositivo"', function () {
    $u = Usuario::factory()->create(['password' => 'secreto123']);

    $this->postJson(URL_LOGIN, [
        'correo' => $u->correo, 'password' => 'secreto123', 'dispositivo' => 'android',
    ])->assertOk()->assertJsonStructure(['data', 'token']);
});

it('acepta el correo con mayúsculas y espacios', function () {
    $u = Usuario::factory()->create(['password' => 'secreto123']);

    $this->postJson(URL_LOGIN, [
        'correo' => '  '.strtoupper($u->correo).' ', 'password' => 'secreto123', 'dispositivo' => 'android',
    ])->assertOk();
});

it('responde igual con contraseña incorrecta que con correo inexistente', function () {
    $u = Usuario::factory()->create(['password' => 'secreto123']);

    $mala = $this->postJson(URL_LOGIN, ['correo' => $u->correo, 'password' => 'otra'])
        ->assertUnprocessable();
    $nadie = $this->postJson(URL_LOGIN, ['correo' => 'nadie@fic.test', 'password' => 'otra'])
        ->assertUnprocessable();

    expect($mala->json('errors.correo'))->toBe($nadie->json('errors.correo'));
});

it('no deja entrar a una cuenta pendiente sin contraseña', function () {
    $u = Usuario::factory()->pendiente()->create();

    $this->postJson(URL_LOGIN, ['correo' => $u->correo, 'password' => 'lo-que-sea'])
        ->assertUnprocessable();
});

it('bloquea con 403 a una cuenta con contraseña pero no activa', function () {
    $u = Usuario::factory()->pendiente()->create(['password' => 'secreto123']);

    $this->postJson(URL_LOGIN, ['correo' => $u->correo, 'password' => 'secreto123'])
        ->assertForbidden()
        ->assertJson(['codigo' => 'cuenta_inactiva']);
});

it('limita los intentos de login a 5 por minuto', function () {
    $u = Usuario::factory()->create(['password' => 'secreto123']);

    foreach (range(1, 5) as $i) {
        $this->postJson(URL_LOGIN, ['correo' => $u->correo, 'password' => 'mala'])->assertUnprocessable();
    }

    $this->postJson(URL_LOGIN, ['correo' => $u->correo, 'password' => 'mala'])
        ->assertStatus(429);
});