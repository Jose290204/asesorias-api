<?php

// Guardar como: tests/Feature/FacultadesSuperAdminTest.php
// Autocontenido (prefijo "fac"): las funciones y constantes de los archivos de Pest son globales.

use App\Enums\Rol;
use App\Models\Bitacora;
use App\Models\Facultad;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

const URL_FAC = '/api/v1/superadmin/facultades';
const URL_FAC_LOGIN = '/api/v1/auth/login';

// ---------- Ayudantes ----------

function facSuperAdmin(): Usuario
{
    return Usuario::factory()->conRol(Rol::SuperAdmin)->create();
}

function facActuar(): Usuario
{
    $s = facSuperAdmin();
    Sanctum::actingAs($s);

    return $s;
}

function facCrear(string $nombre = 'Facultad de Prueba', string $siglas = 'FPR', array $extra = []): Facultad
{
    return Facultad::forceCreate(array_merge([
        'nombre' => $nombre, 'siglas' => $siglas, 'activa' => true,
    ], $extra));
}

function facUsuario(Rol $rol, Facultad $facultad): Usuario
{
    return Usuario::factory()->conRol($rol)->create(['facultad_id' => $facultad->id]);
}

// Multipart: sin JSON, para poder mandar archivos. Se fuerza Accept JSON para recibir JSON.
function facPostMultipart($t, string $url, array $datos)
{
    return $t->withHeaders(['Accept' => 'application/json'])->post($url, $datos);
}

beforeEach(function () {
    Storage::fake('public');
});

// ---------- Acceso ----------

it('responde 401 sin autenticar en todas las rutas', function (string $metodo, string $sufijo) {
    $this->json($metodo, URL_FAC.$sufijo)->assertUnauthorized();
})->with([
    ['get', ''],
    ['post', ''],
    ['put', '/1'],
    ['patch', '/1/estado'],
]);

it('responde 403 a admin, asesor y estudiante en todas las rutas', function (Rol $rol, string $metodo, string $sufijo) {
    $f = facCrear();
    Sanctum::actingAs(facUsuario($rol, $f));

    $this->json($metodo, URL_FAC.str_replace('/1', '/'.$f->id, $sufijo))->assertForbidden();
})->with([Rol::Admin, Rol::Asesor, Rol::Estudiante])
    ->with([
        ['get', ''],
        ['post', ''],
        ['put', '/1'],
        ['patch', '/1/estado'],
    ]);

// ---------- Listar ----------

it('lista las facultades con sus conteos', function () {
    $fic = UsuarioFactory::facultad('FIC');
    $otra = facCrear('Otra Facultad', 'OTR');

    facUsuario(Rol::Admin, $otra);
    facUsuario(Rol::Asesor, $otra);
    facUsuario(Rol::Asesor, $otra);
    facUsuario(Rol::Estudiante, $otra);
    facUsuario(Rol::Estudiante, $fic);

    facActuar(); // el super admin pertenece a la FIC y no cuenta como admin

    $r = $this->getJson(URL_FAC)->assertOk();

    $datos = collect($r->json('data'))->keyBy('siglas');

    expect($datos['OTR'])->toMatchArray([
        'administradores' => 1, 'asesores' => 2, 'estudiantes' => 1, 'asesorias' => 0, 'activa' => true,
    ]);
    expect($datos['FIC'])->toMatchArray([
        'administradores' => 0, 'asesores' => 0, 'estudiantes' => 1,
    ]);
});

// ---------- Crear ----------

it('crea una facultad activa, con siglas en mayúsculas y sin logo', function () {
    facActuar();

    $this->postJson(URL_FAC, ['nombre' => '  Facultad Nueva ', 'siglas' => 'fnu'])
        ->assertCreated()
        ->assertJsonPath('data.nombre', 'Facultad Nueva')
        ->assertJsonPath('data.siglas', 'FNU')
        ->assertJsonPath('data.activa', true)
        ->assertJsonPath('data.logo', null);

    expect(Facultad::where('siglas', 'FNU')->exists())->toBeTrue();
});

it('crea una facultad con logo y lo guarda en el disco público', function () {
    facActuar();

    $r = facPostMultipart($this, URL_FAC, [
        'nombre' => 'Con Logo', 'siglas' => 'CLG',
        'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
    ])->assertCreated();

    $facultad = Facultad::where('siglas', 'CLG')->first();

    Storage::disk('public')->assertExists($facultad->logo);
    expect($r->json('data.logo'))->toBe(Storage::disk('public')->url($facultad->logo));
});

it('valida los datos al crear', function (array $datos, string $campo) {
    facActuar();

    $this->postJson(URL_FAC, $datos)->assertStatus(422)->assertJsonValidationErrors($campo);
})->with([
    'sin nombre' => [['siglas' => 'AAA'], 'nombre'],
    'sin siglas' => [['nombre' => 'Algo'], 'siglas'],
    'nombre muy largo' => [['nombre' => str_repeat('a', 151), 'siglas' => 'AAA'], 'nombre'],
    'siglas muy largas' => [['nombre' => 'Algo', 'siglas' => str_repeat('A', 21)], 'siglas'],
]);

it('rechaza siglas duplicadas sin importar mayúsculas', function () {
    facActuar();
    facCrear('Existente', 'ABC');

    $this->postJson(URL_FAC, ['nombre' => 'Otra', 'siglas' => 'abc'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('siglas');
});

it('rechaza un logo que no es imagen o pesa más de 2 MB', function () {
    facActuar();

    facPostMultipart($this, URL_FAC, [
        'nombre' => 'X', 'siglas' => 'XXX',
        'logo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ])->assertStatus(422)->assertJsonValidationErrors('logo');

    facPostMultipart($this, URL_FAC, [
        'nombre' => 'X', 'siglas' => 'XXX',
        'logo' => UploadedFile::fake()->image('grande.png')->size(3000),
    ])->assertStatus(422)->assertJsonValidationErrors('logo');

    expect(Facultad::where('siglas', 'XXX')->exists())->toBeFalse();
});

// ---------- Editar ----------

it('edita nombre y siglas y conserva el logo si no se manda ninguno', function () {
    facActuar();
    Storage::disk('public')->put('facultades/viejo.png', 'x');
    $f = facCrear('Vieja', 'VIE', ['logo' => 'facultades/viejo.png']);

    facPostMultipart($this, URL_FAC.'/'.$f->id, ['_method' => 'PUT', 'nombre' => 'Nueva', 'siglas' => 'nue'])
        ->assertOk()
        ->assertJsonPath('data.nombre', 'Nueva')
        ->assertJsonPath('data.siglas', 'NUE');

    expect($f->fresh()->logo)->toBe('facultades/viejo.png');
    Storage::disk('public')->assertExists('facultades/viejo.png');
});

it('permite guardar sus propias siglas sin dar error de duplicado', function () {
    facActuar();
    $f = facCrear('Misma', 'MIS');

    $this->putJson(URL_FAC.'/'.$f->id, ['nombre' => 'Misma editada', 'siglas' => 'MIS'])
        ->assertOk();
});

it('rechaza editar con las siglas de otra facultad', function () {
    facActuar();
    facCrear('Una', 'UNA');
    $otra = facCrear('Otra', 'OTR');

    $this->putJson(URL_FAC.'/'.$otra->id, ['nombre' => 'Otra', 'siglas' => 'UNA'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('siglas');
});

it('reemplaza el logo y borra el archivo anterior', function () {
    facActuar();
    Storage::disk('public')->put('facultades/viejo.png', 'x');
    $f = facCrear('Con logo', 'CLO', ['logo' => 'facultades/viejo.png']);

    facPostMultipart($this, URL_FAC.'/'.$f->id, [
        '_method' => 'PUT', 'nombre' => 'Con logo', 'siglas' => 'CLO',
        'logo' => UploadedFile::fake()->image('nuevo.png'),
    ])->assertOk();

    $nuevo = $f->fresh()->logo;

    expect($nuevo)->not->toBe('facultades/viejo.png');
    Storage::disk('public')->assertExists($nuevo);
    Storage::disk('public')->assertMissing('facultades/viejo.png');
});

it('quita el logo con quitar_logo y borra el archivo', function () {
    facActuar();
    Storage::disk('public')->put('facultades/viejo.png', 'x');
    $f = facCrear('Con logo', 'CLO', ['logo' => 'facultades/viejo.png']);

    $this->putJson(URL_FAC.'/'.$f->id, ['nombre' => 'Con logo', 'siglas' => 'CLO', 'quitar_logo' => true])
        ->assertOk()
        ->assertJsonPath('data.logo', null);

    expect($f->fresh()->logo)->toBeNull();
    Storage::disk('public')->assertMissing('facultades/viejo.png');
});

it('responde 404 al editar una facultad que no existe', function () {
    facActuar();

    $this->putJson(URL_FAC.'/99999', ['nombre' => 'X', 'siglas' => 'XXX'])->assertNotFound();
});

// ---------- Estado ----------

it('desactiva y vuelve a activar una facultad', function () {
    facActuar();
    $f = facCrear();

    $this->patchJson(URL_FAC.'/'.$f->id.'/estado', ['activa' => false])
        ->assertOk()
        ->assertJsonPath('data.activa', false);
    expect($f->fresh()->activa)->toBeFalse();

    $this->patchJson(URL_FAC.'/'.$f->id.'/estado', ['activa' => true])
        ->assertOk()
        ->assertJsonPath('data.activa', true);
    expect($f->fresh()->activa)->toBeTrue();
});

it('valida el campo activa', function () {
    facActuar();
    $f = facCrear();

    $this->patchJson(URL_FAC.'/'.$f->id.'/estado', [])->assertStatus(422)->assertJsonValidationErrors('activa');
    $this->patchJson(URL_FAC.'/'.$f->id.'/estado', ['activa' => 'tal vez'])->assertStatus(422)->assertJsonValidationErrors('activa');
});

it('al desactivar revoca tokens y sesiones de sus usuarios y no toca a los de otras facultades', function () {
    facActuar();
    $f = facCrear();
    $otra = facCrear('Otra', 'OTR');

    $propio = facUsuario(Rol::Admin, $f);
    $ajeno = facUsuario(Rol::Admin, $otra);

    $propio->createToken('app');
    $ajeno->createToken('app');

    foreach ([$propio, $ajeno] as $u) {
        DB::table('sessions')->insert([
            'id' => 'sesion-'.$u->id, 'user_id' => $u->id, 'payload' => '', 'last_activity' => time(),
        ]);
    }

    $this->patchJson(URL_FAC.'/'.$f->id.'/estado', ['activa' => false])->assertOk();

    expect($propio->tokens()->count())->toBe(0);
    expect(DB::table('sessions')->where('user_id', $propio->id)->exists())->toBeFalse();

    expect($ajeno->tokens()->count())->toBe(1);
    expect(DB::table('sessions')->where('user_id', $ajeno->id)->exists())->toBeTrue();
});

it('al desactivar la facultad de origen del super admin, él conserva sus tokens', function () {
    $s = facActuar();
    $s->createToken('app');

    $this->patchJson(URL_FAC.'/'.$s->facultad_id.'/estado', ['activa' => false])->assertOk();

    expect($s->tokens()->count())->toBe(1);
});

it('una facultad desactivada impide el login de sus usuarios', function () {
    facActuar();
    $f = facCrear();
    $admin = facUsuario(Rol::Admin, $f);
    $admin->update(['password' => 'secreto123']);

    $this->patchJson(URL_FAC.'/'.$f->id.'/estado', ['activa' => false])->assertOk();

    $this->postJson(URL_FAC_LOGIN, [
        'correo' => $admin->correo, 'password' => 'secreto123', 'dispositivo' => 'android',
    ])->assertForbidden()->assertJsonPath('codigo', 'facultad_inactiva');
});

it('cambiar al mismo estado no falla ni registra bitácora', function () {
    facActuar();
    $f = facCrear();

    $this->patchJson(URL_FAC.'/'.$f->id.'/estado', ['activa' => true])->assertOk();

    expect(Bitacora::withoutGlobalScopes()->where('accion', 'facultad.activada')->count())->toBe(0);
});

// ---------- Bitácora ----------

it('registra en bitácora crear, editar, desactivar y activar con la facultad afectada', function () {
    $s = facActuar();

    $id = $this->postJson(URL_FAC, ['nombre' => 'Bit', 'siglas' => 'BIT'])->assertCreated()->json('data.id');
    $this->putJson(URL_FAC.'/'.$id, ['nombre' => 'Bit 2', 'siglas' => 'BIT'])->assertOk();
    $this->patchJson(URL_FAC.'/'.$id.'/estado', ['activa' => false])->assertOk();
    $this->patchJson(URL_FAC.'/'.$id.'/estado', ['activa' => true])->assertOk();

    $registros = Bitacora::withoutGlobalScopes()->where('entidad_id', $id)->orderBy('id')->get();

    expect($registros->pluck('accion')->all())->toBe([
        'facultad.creada', 'facultad.editada', 'facultad.desactivada', 'facultad.activada',
    ]);
    expect($registros->every(fn ($b) => $b->facultad_id === $id && $b->usuario_id === $s->id))->toBeTrue();
    expect($registros->first()->detalles['etiqueta'])->toBe('Bit');
});