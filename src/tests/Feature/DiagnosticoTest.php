<?php

use App\Models\Usuario;
use App\Services\AuthService;

it('diagnóstico 2: service directo contra petición HTTP', function () {
    $u = Usuario::factory()->create(['password' => 'secreto123']);

    // 1. El service solo, sin HTTP
    try {
        $r = app(AuthService::class)->autenticar($u->correo, 'secreto123');
        dump('SERVICE DIRECTO: OK, devolvió el usuario '.$r->id);
    } catch (Throwable $e) {
        dump('SERVICE DIRECTO FALLÓ: '.get_class($e).' -> '.$e->getMessage());
    }

    // 2. La misma credencial por HTTP
    $resp = $this->postJson('/api/v1/auth/login', [
        'correo' => $u->correo, 'password' => 'secreto123', 'dispositivo' => 'android',
    ]);
    dump('HTTP: '.$resp->status());

    expect(true)->toBeTrue();
});