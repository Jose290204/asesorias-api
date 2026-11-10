<?php

// Autocontenido (prefijo "abt"): las funciones y constantes de los archivos de Pest son globales.

use App\Enums\Rol;
use App\Models\Bitacora;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;

const URL_ABT = '/api/v1/admin/bitacora';

// ---------- Ayudantes ----------

function abtUsuario(Rol $rol, string $siglas = 'FIC', array $extra = []): Usuario
{
    return Usuario::factory()->conRol($rol)->create(
        array_merge(['facultad_id' => UsuarioFactory::facultad($siglas)->id], $extra)
    );
}

// Petición como el usuario dado (admin de FIC si no se pasa). Limpia el guard: Sanctum cachea al usuario
function abtComo(?Usuario $usuario = null)
{
    app('auth')->forgetGuards();

    return test()->withToken(($usuario ?? abtUsuario(Rol::Admin))->createToken('prueba')->plainTextToken);
}

// Inserta un registro tal cual (sin pasar por Bitacora::registrar)
function abtRegistro(string $siglas = 'FIC', array $extra = []): Bitacora
{
    return Bitacora::withoutGlobalScopes()->forceCreate(array_merge([
        'facultad_id' => UsuarioFactory::facultad($siglas)->id,
        'usuario_id' => null,
        'accion' => 'sesion.inicio',
        'detalles' => ['etiqueta' => 'Sesión', 'detalle' => 'Desde el navegador web.'],
        'ip' => '10.0.0.1',
        'created_at' => now(),
    ], $extra));
}

function abtIds($respuesta): array
{
    return collect($respuesta->json('data'))->pluck('id')->all();
}

// ---------- Acceso ----------

it('responde 401 sin autenticar', function () {
    $this->getJson(URL_ABT)->assertUnauthorized();
});

it('responde 403 a asesor y estudiante', function (Rol $rol) {
    abtComo(abtUsuario($rol))->getJson(URL_ABT)->assertForbidden();
})->with([Rol::Asesor, Rol::Estudiante]);

it('es de solo lectura: no hay POST, PUT ni DELETE', function (string $metodo) {
    abtComo()->json($metodo, URL_ABT)->assertStatus(405);
})->with(['post', 'put', 'patch', 'delete']);

// ---------- Listado ----------

it('lista solo los registros de la facultad del admin, el más reciente primero', function () {
    $admin = abtUsuario(Rol::Admin);
    $autor = abtUsuario(Rol::Admin, 'FIC', ['nombre' => 'Laura', 'apellido_paterno' => 'Gómez', 'apellido_materno' => null]);

    $viejo = abtRegistro('FIC', ['created_at' => now()->subDay()]);
    $nuevo = abtRegistro('FIC', [
        'usuario_id' => $autor->id,
        'accion' => 'asesor.creado',
        'detalles' => ['etiqueta' => 'Pedro Pérez', 'detalle' => 'Se creó el asesor disciplinar.'],
        'ip' => '187.190.10.4',
    ]);
    $ajeno = abtRegistro('OTR');

    $r = abtComo($admin)->getJson(URL_ABT)->assertOk();

    expect(abtIds($r))->toBe([$nuevo->id, $viejo->id])
        ->and(abtIds($r))->not->toContain($ajeno->id);

    $r->assertJsonPath('data.0.usuario', 'Laura Gómez')
        ->assertJsonPath('data.0.accion', 'asesor.creado')
        ->assertJsonPath('data.0.entidad', 'Pedro Pérez')
        ->assertJsonPath('data.0.detalle', 'Se creó el asesor disciplinar.')
        ->assertJsonPath('data.0.ip', '187.190.10.4')
        ->assertJsonPath('meta.total', 2);
});

it('ignora facultad_id: un admin no puede ver la bitácora de otra facultad', function () {
    $propio = abtRegistro('FIC');
    $ajeno = abtRegistro('OTR');

    $ids = abtIds(abtComo()->getJson(URL_ABT.'?facultad_id='.$ajeno->facultad_id)->assertOk());

    expect($ids)->toBe([$propio->id]);
});

it('muestra como "sistema" (usuario null) lo que hizo el sistema', function () {
    abtRegistro('FIC', ['usuario_id' => null, 'accion' => 'solicitud.rechazada_auto']);

    abtComo()->getJson(URL_ABT)->assertOk()->assertJsonPath('data.0.usuario', null);
});

// ---------- Filtros ----------

it('filtra por acción', function () {
    $a = abtRegistro('FIC', ['accion' => 'asesor.creado']);
    abtRegistro('FIC', ['accion' => 'estudiante.baja']);

    expect(abtIds(abtComo()->getJson(URL_ABT.'?accion=asesor.creado')->assertOk()))->toBe([$a->id]);
});

it('rechaza una acción que no existe', function () {
    abtComo()->getJson(URL_ABT.'?accion=inventada')->assertUnprocessable()->assertJsonValidationErrors('accion');
});

it('busca por nombre del usuario, detalle o IP', function () {
    $autor = abtUsuario(Rol::Admin, 'FIC', ['nombre' => 'Zacarías', 'apellido_paterno' => 'Quiroz']);

    $porUsuario = abtRegistro('FIC', ['usuario_id' => $autor->id]);
    $porDetalle = abtRegistro('FIC', ['detalles' => ['etiqueta' => 'X', 'detalle' => 'Cambio raro de grupo']]);
    $porIp = abtRegistro('FIC', ['ip' => '201.5.5.5']);
    abtRegistro('FIC');

    foreach ([['Zacarías', $porUsuario], ['raro de grupo', $porDetalle], ['201.5.5.5', $porIp]] as [$texto, $esperado]) {
        expect(abtIds(abtComo()->getJson(URL_ABT.'?buscar='.urlencode($texto))->assertOk()))->toBe([$esperado->id]);
    }
});

it('filtra por rango de fechas, incluyendo todo el día "hasta"', function () {
    $dentro = abtRegistro('FIC', ['created_at' => '2026-03-10 23:30:00']);
    abtRegistro('FIC', ['created_at' => '2026-03-01 08:00:00']);
    abtRegistro('FIC', ['created_at' => '2026-03-12 08:00:00']);

    $r = abtComo()->getJson(URL_ABT.'?desde=2026-03-05&hasta=2026-03-10')->assertOk();

    expect(abtIds($r))->toBe([$dentro->id]);
});

it('rechaza "hasta" anterior a "desde"', function () {
    abtComo()->getJson(URL_ABT.'?desde=2026-03-10&hasta=2026-03-01')->assertUnprocessable()->assertJsonValidationErrors('hasta');
});

// ---------- Paginación ----------

it('pagina de 10 en 10 por defecto y acepta 25 y 50; rechaza otros tamaños', function () {
    foreach (range(1, 12) as $i) {
        abtRegistro('FIC');
    }

    abtComo()->getJson(URL_ABT)->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 12);
    abtComo()->getJson(URL_ABT.'?page=2')->assertOk()->assertJsonCount(2, 'data');
    abtComo()->getJson(URL_ABT.'?per_page=25')->assertOk()->assertJsonCount(12, 'data');
    abtComo()->getJson(URL_ABT.'?per_page=7')->assertUnprocessable()->assertJsonValidationErrors('per_page');
});