<?php

use App\Enums\TipoVerificacion;
use App\Models\Usuario;
use App\Models\Verificacion;
use App\Services\VerificacionService;

// Helper: devuelve el service resuelto por el contenedor de Laravel.
// Función con nombre propio (las de un archivo de Pest son globales).
function servicioVerificacion(): VerificacionService
{
    return app(VerificacionService::class);
}

it('genera un código de 6 dígitos y lo valida', function () {
    $u = Usuario::factory()->create();

    $codigo = servicioVerificacion()->generar($u, TipoVerificacion::Recuperacion);

    // Exactamente 6 dígitos, incluidos los ceros a la izquierda
    expect($codigo)->toMatch('/^\d{6}$/');
    expect(servicioVerificacion()->validar($u, TipoVerificacion::Recuperacion, $codigo))->toBeTrue();
});

it('guarda el código con hash, no en texto plano', function () {
    $u = Usuario::factory()->create();

    $codigo = servicioVerificacion()->generar($u, TipoVerificacion::Recuperacion);

    // makeVisible porque el modelo oculta "codigo" en $hidden
    $guardado = Verificacion::where('usuario_id', $u->id)->first()->makeVisible('codigo')->codigo;
    expect($guardado)->not->toBe($codigo);
});

it('rechaza un código incorrecto y cuenta el intento', function () {
    $u = Usuario::factory()->create();
    servicioVerificacion()->generar($u, TipoVerificacion::Recuperacion);

    $ok = servicioVerificacion()->validar($u, TipoVerificacion::Recuperacion, '000000x');

    expect($ok)->toBeFalse();
    expect(Verificacion::where('usuario_id', $u->id)->first()->intentos)->toBe(1);
});

it('tras 5 intentos fallidos ya no acepta ni el código correcto', function () {
    $u = Usuario::factory()->create();
    $codigo = servicioVerificacion()->generar($u, TipoVerificacion::Recuperacion);

    foreach (range(1, 5) as $i) {
        servicioVerificacion()->validar($u, TipoVerificacion::Recuperacion, 'mal');
    }

    expect(servicioVerificacion()->validar($u, TipoVerificacion::Recuperacion, $codigo))->toBeFalse();
});

it('rechaza un código vencido', function () {
    $u = Usuario::factory()->create();
    $codigo = servicioVerificacion()->generar($u, TipoVerificacion::Recuperacion);

    // Hermana feliz: a los 9 minutos todavía sirve
    $this->travel(9)->minutes();
    expect(servicioVerificacion()->validar($u, TipoVerificacion::Recuperacion, $codigo))->toBeTrue();

    // A los 11 minutos ya venció
    $this->travel(2)->minutes();
    expect(servicioVerificacion()->validar($u, TipoVerificacion::Recuperacion, $codigo))->toBeFalse();
});

it('con consumir el código sirve una sola vez', function () {
    $u = Usuario::factory()->create();
    $codigo = servicioVerificacion()->generar($u, TipoVerificacion::Recuperacion);

    // Sin consumir se puede comprobar las veces que haga falta (así avanza el front)
    expect(servicioVerificacion()->validar($u, TipoVerificacion::Recuperacion, $codigo))->toBeTrue();
    expect(servicioVerificacion()->validar($u, TipoVerificacion::Recuperacion, $codigo))->toBeTrue();

    // Consumiéndolo funciona esta vez...
    expect(servicioVerificacion()->validar($u, TipoVerificacion::Recuperacion, $codigo, consumir: true))->toBeTrue();
    // ...y ya no una segunda
    expect(servicioVerificacion()->validar($u, TipoVerificacion::Recuperacion, $codigo))->toBeFalse();
});

it('no permite reenviar antes de 60 segundos', function () {
    $u = Usuario::factory()->create();

    expect(servicioVerificacion()->generar($u, TipoVerificacion::Recuperacion))->not->toBeNull();
    expect(servicioVerificacion()->generar($u, TipoVerificacion::Recuperacion))->toBeNull();

    // Hermana feliz: pasado el minuto sí genera otro
    $this->travel(61)->seconds();
    expect(servicioVerificacion()->generar($u, TipoVerificacion::Recuperacion))->not->toBeNull();
});

it('un código nuevo invalida el anterior', function () {
    $u = Usuario::factory()->create();
    $viejo = servicioVerificacion()->generar($u, TipoVerificacion::Recuperacion);

    $this->travel(61)->seconds();
    $nuevo = servicioVerificacion()->generar($u, TipoVerificacion::Recuperacion);

    // El viejo ya no sirve (puede coincidir por azar con el nuevo: 1 en un millón)
    if ($viejo !== $nuevo) {
        expect(servicioVerificacion()->validar($u, TipoVerificacion::Recuperacion, $viejo))->toBeFalse();
    }
    expect(servicioVerificacion()->validar($u, TipoVerificacion::Recuperacion, $nuevo))->toBeTrue();
});

it('el código de un usuario no sirve para otro', function () {
    $a = Usuario::factory()->create();
    $b = Usuario::factory()->create();
    $codigoDeA = servicioVerificacion()->generar($a, TipoVerificacion::Recuperacion);
    servicioVerificacion()->generar($b, TipoVerificacion::Recuperacion);

    expect(servicioVerificacion()->validar($b, TipoVerificacion::Recuperacion, $codigoDeA))->toBeFalse();
});