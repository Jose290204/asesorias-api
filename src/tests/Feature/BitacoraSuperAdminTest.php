<?php

// Autocontenido (prefijo "bit"): las funciones y constantes de los archivos de Pest son globales.

use App\Enums\AccionBitacora;
use App\Enums\Rol;
use App\Models\Bitacora;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Laravel\Sanctum\Sanctum;

const URL_BIT = '/api/v1/superadmin/bitacora';
const URL_BIT_LOGIN = '/api/v1/auth/login';

// ---------- Ayudantes ----------

function bitActuar(): Usuario
{
    $s = Usuario::factory()->conRol(Rol::SuperAdmin)->create();
    Sanctum::actingAs($s);

    return $s;
}

// Inserta un registro tal cual (sin pasar por Bitacora::registrar)
function bitRegistro(array $extra = []): Bitacora
{
    return Bitacora::withoutGlobalScopes()->forceCreate(array_merge([
        'facultad_id' => UsuarioFactory::facultad('FIC')->id,
        'usuario_id' => null,
        'accion' => 'sesion.inicio',
        'detalles' => ['etiqueta' => 'Sesión', 'detalle' => 'Desde el navegador web.'],
        'ip' => '10.0.0.1',
        'created_at' => now(),
    ], $extra));
}

function bitIds($respuesta): array
{
    return collect($respuesta->json('data'))->pluck('id')->all();
}

// ---------- Acceso ----------

it('responde 401 sin autenticar', function () {
    $this->getJson(URL_BIT)->assertUnauthorized();
});

it('responde 403 a admin, asesor y estudiante', function (Rol $rol) {
    Sanctum::actingAs(Usuario::factory()->conRol($rol)->create());

    $this->getJson(URL_BIT)->assertForbidden();
})->with([Rol::Admin, Rol::Asesor, Rol::Estudiante]);

it('es de solo lectura: no hay POST, PUT ni DELETE', function (string $metodo) {
    bitActuar();

    $this->json($metodo, URL_BIT)->assertStatus(405);
})->with(['post', 'put', 'patch', 'delete']);

// ---------- Listado ----------

it('lista los registros con sus campos, el más reciente primero y de todas las facultades', function () {
    bitActuar();
    $fic = UsuarioFactory::facultad('FIC');
    $otra = UsuarioFactory::facultad('OTR');
    $autor = Usuario::factory()->create(['nombre' => 'Laura', 'apellido_paterno' => 'Gómez', 'apellido_materno' => null]);

    $viejo = bitRegistro(['created_at' => now()->subDay(), 'facultad_id' => $fic->id]);
    $nuevo = bitRegistro([
        'facultad_id' => $otra->id,
        'usuario_id' => $autor->id,
        'accion' => 'facultad.creada',
        'detalles' => ['etiqueta' => 'Facultad Otra', 'detalle' => 'Se creó la facultad OTR.', 'super_admin' => true],
        'ip' => '187.190.10.4',
    ]);

    $r = $this->getJson(URL_BIT)->assertOk();

    expect(bitIds($r))->toBe([$nuevo->id, $viejo->id]);
    expect($r->json('data.0'))->toMatchArray([
        'id' => $nuevo->id,
        'usuario' => 'Laura Gómez',
        'facultad_id' => $otra->id,
        'accion' => 'facultad.creada',
        'entidad' => 'Facultad Otra',
        'detalle' => 'Se creó la facultad OTR.',
        'super_admin' => true,
        'ip' => '187.190.10.4',
    ]);
    expect($r->json('data.0.fecha'))->toBeString();
    expect($r->json('data.1.usuario'))->toBeNull();
    expect($r->json('data.1.super_admin'))->toBeFalse();
});

it('un usuario eliminado sigue apareciendo con su nombre', function () {
    bitActuar();
    $autor = Usuario::factory()->conRol(Rol::Admin)->create(['nombre' => 'Mario', 'apellido_paterno' => 'Soto', 'apellido_materno' => null]);
    bitRegistro(['usuario_id' => $autor->id]);
    $autor->delete();

    $this->getJson(URL_BIT)->assertOk()->assertJsonPath('data.0.usuario', 'Mario Soto');
});

// ---------- Paginación ----------

it('pagina de 10 en 10 por defecto y acepta 25 y 50', function () {
    bitActuar();
    foreach (range(1, 12) as $i) {
        bitRegistro(['created_at' => now()->subMinutes($i)]);
    }

    $p1 = $this->getJson(URL_BIT)->assertOk();
    expect($p1->json('data'))->toHaveCount(10);
    expect($p1->json('meta.total'))->toBe(12);
    expect($p1->json('meta.last_page'))->toBe(2);

    $p2 = $this->getJson(URL_BIT.'?page=2')->assertOk();
    expect($p2->json('data'))->toHaveCount(2);

    expect($this->getJson(URL_BIT.'?per_page=25')->json('data'))->toHaveCount(12);
    expect($this->getJson(URL_BIT.'?per_page=50')->json('data'))->toHaveCount(12);
});

it('rechaza un per_page que no sea 10, 25 o 50', function () {
    bitActuar();

    $this->getJson(URL_BIT.'?per_page=100')->assertStatus(422)->assertJsonValidationErrors('per_page');
});

// ---------- Filtros ----------

it('filtra por facultad y por acción', function () {
    bitActuar();
    $fic = UsuarioFactory::facultad('FIC');
    $otra = UsuarioFactory::facultad('OTR');

    $a = bitRegistro(['facultad_id' => $fic->id, 'accion' => 'facultad.creada']);
    $b = bitRegistro(['facultad_id' => $otra->id, 'accion' => 'facultad.creada']);
    $c = bitRegistro(['facultad_id' => $otra->id, 'accion' => 'administrador.eliminado']);

    expect(bitIds($this->getJson(URL_BIT.'?facultad_id='.$otra->id)))->toEqualCanonicalizing([$b->id, $c->id]);
    expect(bitIds($this->getJson(URL_BIT.'?accion=facultad.creada')))->toEqualCanonicalizing([$a->id, $b->id]);
    expect(bitIds($this->getJson(URL_BIT.'?accion=facultad.creada&facultad_id='.$otra->id)))->toBe([$b->id]);
});

it('busca por registro, detalle, IP y nombre del usuario', function () {
    bitActuar();
    $autor = Usuario::factory()->create(['nombre' => 'Zoila', 'apellido_paterno' => 'Quintero', 'apellido_materno' => 'Lara']);

    $porEtiqueta = bitRegistro(['detalles' => ['etiqueta' => 'Facultad de Ingeniería', 'detalle' => 'x']]);
    $porDetalle = bitRegistro(['detalles' => ['etiqueta' => 'y', 'detalle' => 'Cambió el logo']]);
    $porIp = bitRegistro(['ip' => '201.141.22.9']);
    $porNombre = bitRegistro(['usuario_id' => $autor->id]);

    expect(bitIds($this->getJson(URL_BIT.'?buscar=Ingeniería')))->toBe([$porEtiqueta->id]);
    expect(bitIds($this->getJson(URL_BIT.'?buscar=logo')))->toBe([$porDetalle->id]);
    expect(bitIds($this->getJson(URL_BIT.'?buscar=201.141')))->toBe([$porIp->id]);
    expect(bitIds($this->getJson(URL_BIT.'?buscar=Zoila Quintero')))->toBe([$porNombre->id]);
    expect(bitIds($this->getJson(URL_BIT.'?buscar=nadie-se-llama-asi')))->toBe([]);
});

it('filtra por rango de fechas incluyendo todo el día de "hasta"', function () {
    bitActuar();
    $viejo = bitRegistro(['created_at' => now()->subDays(10)]);
    $medio = bitRegistro(['created_at' => now()->subDays(5)->setTime(23, 30)]);
    $hoy = bitRegistro(['created_at' => now()]);

    $desde = now()->subDays(6)->toDateString();
    $hasta = now()->subDays(5)->toDateString();

    expect(bitIds($this->getJson(URL_BIT."?desde={$desde}")))->toEqualCanonicalizing([$medio->id, $hoy->id]);
    expect(bitIds($this->getJson(URL_BIT."?desde={$desde}&hasta={$hasta}")))->toBe([$medio->id]);
    expect(bitIds($this->getJson(URL_BIT.'?hasta='.now()->subDays(9)->toDateString())))->toBe([$viejo->id]);
});

it('valida los filtros', function (string $consulta, string $campo) {
    bitActuar();

    $this->getJson(URL_BIT.$consulta)->assertStatus(422)->assertJsonValidationErrors($campo);
})->with([
    'acción inexistente' => ['?accion=inventada', 'accion'],
    'desde con mal formato' => ['?desde=08/11/2026', 'desde'],
    'hasta con mal formato' => ['?hasta=ayer', 'hasta'],
    'hasta anterior a desde' => ['?desde=2026-11-08&hasta=2026-11-01', 'hasta'],
    'facultad no numérica' => ['?facultad_id=abc', 'facultad_id'],
]);

// ---------- Inicio de sesión ----------

it('registra el inicio de sesión de un administrador y de un super admin', function (Rol $rol) {
    $u = Usuario::factory()->conRol($rol)->create(['password' => 'secreto123']);

    $this->postJson(URL_BIT_LOGIN, [
        'correo' => $u->correo, 'password' => 'secreto123', 'dispositivo' => 'android',
    ])->assertOk();

    $b = Bitacora::withoutGlobalScopes()->where('accion', 'sesion.inicio')->sole();

    expect($b->usuario_id)->toBe($u->id);
    expect($b->facultad_id)->toBe($u->facultad_id);
    expect($b->detalles['etiqueta'])->toBe('Sesión');
    expect($b->detalles['detalle'])->toContain('app móvil');
})->with([Rol::Admin, Rol::SuperAdmin]);

it('no registra el inicio de sesión de asesores y estudiantes', function (Rol $rol) {
    $u = Usuario::factory()->conRol($rol)->create(['password' => 'secreto123']);

    $this->postJson(URL_BIT_LOGIN, [
        'correo' => $u->correo, 'password' => 'secreto123', 'dispositivo' => 'android',
    ])->assertOk();

    expect(Bitacora::withoutGlobalScopes()->count())->toBe(0);
})->with([Rol::Asesor, Rol::Estudiante]);

it('no registra un inicio de sesión fallido', function () {
    $u = Usuario::factory()->conRol(Rol::Admin)->create(['password' => 'secreto123']);

    $this->postJson(URL_BIT_LOGIN, [
        'correo' => $u->correo, 'password' => 'incorrecta', 'dispositivo' => 'android',
    ])->assertStatus(422);

    expect(Bitacora::withoutGlobalScopes()->count())->toBe(0);
});

// ---------- Bitacora::registrar ----------

it('marca super_admin solo cuando quien actúa es super admin', function () {
    $facultad = UsuarioFactory::facultad('FIC');

    Sanctum::actingAs(Usuario::factory()->conRol(Rol::Admin)->create());
    Bitacora::registrar(AccionBitacora::FacultadEditada, $facultad, ['etiqueta' => 'a']);

    Sanctum::actingAs(Usuario::factory()->conRol(Rol::SuperAdmin)->create());
    Bitacora::registrar(AccionBitacora::FacultadEditada, $facultad, ['etiqueta' => 'b']);

    $registros = Bitacora::withoutGlobalScopes()->orderBy('id')->get();

    expect($registros[0]->detalles)->not->toHaveKey('super_admin');
    expect($registros[1]->detalles['super_admin'])->toBeTrue();
});

it('usa la facultad explícita antes que la del usuario o la del contexto', function () {
    $otra = UsuarioFactory::facultad('OTR');
    Sanctum::actingAs(Usuario::factory()->conRol(Rol::SuperAdmin)->create());

    Bitacora::registrar(AccionBitacora::FacultadCreada, $otra, ['etiqueta' => 'x'], $otra->id);

    expect(Bitacora::withoutGlobalScopes()->sole()->facultad_id)->toBe($otra->id);
});

it('las acciones del super admin sobre facultades y administradores quedan marcadas y visibles en la bitácora', function () {
    bitActuar();

    $this->postJson('/api/v1/superadmin/facultades', ['nombre' => 'Facultad X', 'siglas' => 'fx'])->assertCreated();

    $r = $this->getJson(URL_BIT.'?accion=facultad.creada')->assertOk();

    expect($r->json('data'))->toHaveCount(1);
    expect($r->json('data.0.super_admin'))->toBeTrue();
    expect($r->json('data.0.entidad'))->toBe('Facultad X');
});