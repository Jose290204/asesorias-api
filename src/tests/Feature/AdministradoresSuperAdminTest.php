<?php


use App\Enums\Rol;
use App\Models\Bitacora;
use App\Models\Facultad;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

const URL_ADM = '/api/v1/superadmin/administradores';
const URL_ADM_LOGIN = '/api/v1/auth/login';

// ---------- Ayudantes ----------

function admActuar(): Usuario
{
    $s = Usuario::factory()->conRol(Rol::SuperAdmin)->create();
    Sanctum::actingAs($s);

    return $s;
}

function admFacultad(string $siglas = 'OTR', bool $activa = true): Facultad
{
    $f = UsuarioFactory::facultad($siglas);
    if ($f->activa !== $activa) {
        $f->update(['activa' => $activa]);
    }

    return $f;
}

function admAdministrador(?Facultad $facultad = null, array $extra = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Admin)->create(array_merge(
        ['facultad_id' => ($facultad ?? admFacultad('FIC'))->id],
        $extra
    ));
}

function admPayload(array $extra = []): array
{
    return array_merge([
        'facultad_id' => admFacultad('FIC')->id,
        'nombre' => 'Ana',
        'apellido_paterno' => 'Pérez',
        'apellido_materno' => 'Soto',
        'correo' => 'ana.perez@fic.test',
        'password' => 'secreto123',
    ], $extra);
}

function admSesion(Usuario $u): void
{
    $u->createToken('app');
    DB::table('sessions')->insert([
        'id' => 'sesion-'.$u->id, 'user_id' => $u->id, 'payload' => '', 'last_activity' => time(),
    ]);
}

function admSinSesiones(Usuario $u): bool
{
    return $u->tokens()->count() === 0
        && ! DB::table('sessions')->where('user_id', $u->id)->exists();
}

function admBitacora(int $usuarioId): array
{
    return Bitacora::withoutGlobalScopes()
        ->where('entidad_id', $usuarioId)
        ->where('entidad_type', (new Usuario)->getMorphClass())
        ->orderBy('id')
        ->pluck('accion')
        ->all();
}

// ---------- Acceso ----------

it('responde 401 sin autenticar en todas las rutas', function (string $metodo, string $sufijo) {
    $this->json($metodo, URL_ADM.$sufijo)->assertUnauthorized();
})->with([
    ['get', ''],
    ['post', ''],
    ['put', '/1'],
    ['patch', '/1/estado'],
    ['delete', '/1'],
]);

it('responde 403 a admin, asesor y estudiante en todas las rutas', function (Rol $rol, string $metodo, string $sufijo) {
    $u = Usuario::factory()->conRol($rol)->create();
    Sanctum::actingAs($u);

    $this->json($metodo, URL_ADM.$sufijo)->assertForbidden();
})->with([Rol::Admin, Rol::Asesor, Rol::Estudiante])
    ->with([
        ['get', ''],
        ['post', ''],
        ['put', '/1'],
        ['patch', '/1/estado'],
        ['delete', '/1'],
    ]);

// ---------- Listar ----------

it('lista solo administradores de facultad con los campos esperados', function () {
    admActuar(); // un super admin: no debe aparecer
    $a = admAdministrador(null, ['nombre' => 'Laura', 'apellido_paterno' => 'Gómez', 'apellido_materno' => null]);
    Usuario::factory()->conRol(Rol::Asesor)->create();
    Usuario::factory()->conRol(Rol::Estudiante)->create();

    $r = $this->getJson(URL_ADM)->assertOk();

    expect($r->json('data'))->toHaveCount(1);
    expect($r->json('data.0'))->toMatchArray([
        'id' => $a->id,
        'nombre' => 'Laura',
        'apellido_paterno' => 'Gómez',
        'apellido_materno' => null,
        'nombre_completo' => 'Laura Gómez',
        'correo' => $a->correo,
        'facultad_id' => $a->facultad_id,
        'estatus' => 'activo',
    ]);
    expect($r->json('data.0'))->not->toHaveKey('password');
});

it('filtra por facultad, estatus y texto', function () {
    admActuar();
    $fic = admFacultad('FIC');
    $otra = admFacultad('OTR');

    admAdministrador($fic, ['nombre' => 'Zeta', 'correo' => 'zeta@fic.test']);
    $inactivo = admAdministrador($otra, ['nombre' => 'Beta', 'correo' => 'beta@otr.test', 'estatus' => 'inactivo']);
    admAdministrador($otra, ['nombre' => 'Alfa', 'correo' => 'alfa@otr.test']);

    $ids = fn ($r) => collect($r->json('data'))->pluck('correo')->sort()->values()->all();

    expect($ids($this->getJson(URL_ADM.'?facultad_id='.$otra->id)))->toBe(['alfa@otr.test', 'beta@otr.test']);
    expect($ids($this->getJson(URL_ADM.'?estatus=inactivo')))->toBe(['beta@otr.test']);
    expect($ids($this->getJson(URL_ADM.'?buscar=zeta')))->toBe(['zeta@fic.test']);
    expect($ids($this->getJson(URL_ADM.'?buscar=otr.test&facultad_id='.$otra->id)))->toBe(['alfa@otr.test', 'beta@otr.test']);
    expect($inactivo->estatus->value)->toBe('inactivo');
});

it('valida los filtros del listado', function () {
    admActuar();

    $this->getJson(URL_ADM.'?estatus=raro')->assertStatus(422)->assertJsonValidationErrors('estatus');
});

// ---------- Crear ----------

it('crea un administrador activo con rol Admin y contraseña que sirve para entrar', function () {
    admActuar();

    $r = $this->postJson(URL_ADM, admPayload(['correo' => '  Ana.Perez@FIC.test ']))
        ->assertCreated()
        ->assertJsonPath('data.nombre', 'Ana')
        ->assertJsonPath('data.correo', 'ana.perez@fic.test')
        ->assertJsonPath('data.estatus', 'activo');

    expect($r->json('data'))->not->toHaveKey('password');

    $admin = Usuario::find($r->json('data.id'));
    expect($admin->tieneRol(Rol::Admin))->toBeTrue();
    expect($admin->tieneRol(Rol::SuperAdmin))->toBeFalse();
    expect(Hash::check('secreto123', $admin->password))->toBeTrue();

    $this->postJson(URL_ADM_LOGIN, [
        'correo' => 'ana.perez@fic.test', 'password' => 'secreto123', 'dispositivo' => 'android',
    ])->assertOk();
});

it('crea el administrador sin apellido materno', function () {
    admActuar();

    $this->postJson(URL_ADM, admPayload(['apellido_materno' => null]))
        ->assertCreated()
        ->assertJsonPath('data.apellido_materno', null);
});

it('valida los datos al crear', function (array $cambios, string $campo) {
    admActuar();

    $this->postJson(URL_ADM, array_merge(admPayload(), $cambios))
        ->assertStatus(422)
        ->assertJsonValidationErrors($campo);
})->with([
    'sin facultad' => [['facultad_id' => null], 'facultad_id'],
    'facultad inexistente' => [['facultad_id' => 99999], 'facultad_id'],
    'sin nombre' => [['nombre' => ''], 'nombre'],
    'nombre muy largo' => [['nombre' => str_repeat('a', 61)], 'nombre'],
    'sin apellido paterno' => [['apellido_paterno' => ''], 'apellido_paterno'],
    'sin correo' => [['correo' => ''], 'correo'],
    'correo inválido' => [['correo' => 'no-es-correo'], 'correo'],
    'sin contraseña' => [['password' => ''], 'password'],
    'contraseña corta' => [['password' => '1234567'], 'password'],
    'contraseña muy larga' => [['password' => str_repeat('a', 73)], 'password'],
]);

it('rechaza crear en una facultad inactiva', function () {
    admActuar();
    $inactiva = admFacultad('INA', false);

    $this->postJson(URL_ADM, admPayload(['facultad_id' => $inactiva->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('facultad_id');
});

it('rechaza un correo repetido sin importar mayúsculas', function () {
    admActuar();
    admAdministrador(null, ['correo' => 'repetido@fic.test']);

    $this->postJson(URL_ADM, admPayload(['correo' => 'REPETIDO@fic.test']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('correo');
});

it('el correo de un administrador eliminado queda reservado', function () {
    admActuar();
    $viejo = admAdministrador(null, ['correo' => 'reservado@fic.test']);
    $viejo->delete();

    $this->postJson(URL_ADM, admPayload(['correo' => 'reservado@fic.test']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('correo');
});

// ---------- Editar ----------

it('edita los datos sin tocar la contraseña ni las sesiones', function () {
    admActuar();
    $a = admAdministrador(null, ['password' => 'original123']);
    admSesion($a);

    $this->putJson(URL_ADM.'/'.$a->id, admPayload([
        'nombre' => 'Editado', 'correo' => 'editado@fic.test', 'password' => null,
    ]))->assertOk()
        ->assertJsonPath('data.nombre', 'Editado')
        ->assertJsonPath('data.correo', 'editado@fic.test');

    expect(Hash::check('original123', $a->fresh()->password))->toBeTrue();
    expect(admSinSesiones($a))->toBeFalse();
});

it('permite guardar su propio correo y rechaza el de otro', function () {
    admActuar();
    $a = admAdministrador(null, ['correo' => 'propio@fic.test']);
    admAdministrador(null, ['correo' => 'ajeno@fic.test']);

    $this->putJson(URL_ADM.'/'.$a->id, admPayload(['correo' => 'propio@fic.test', 'password' => null]))->assertOk();

    $this->putJson(URL_ADM.'/'.$a->id, admPayload(['correo' => 'ajeno@fic.test', 'password' => null]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('correo');
});

it('cambia la contraseña, cierra sus sesiones y la anterior deja de servir', function () {
    admActuar();
    $a = admAdministrador(null, ['password' => 'original123']);
    admSesion($a);

    $this->putJson(URL_ADM.'/'.$a->id, admPayload(['correo' => $a->correo, 'password' => 'nueva12345']))->assertOk();

    expect(Hash::check('nueva12345', $a->fresh()->password))->toBeTrue();
    expect(admSinSesiones($a))->toBeTrue();
});

it('mueve al administrador de facultad y cierra sus sesiones', function () {
    admActuar();
    $fic = admFacultad('FIC');
    $otra = admFacultad('OTR');
    $a = admAdministrador($fic);
    admSesion($a);

    $this->putJson(URL_ADM.'/'.$a->id, admPayload(['facultad_id' => $otra->id, 'correo' => $a->correo, 'password' => null]))
        ->assertOk()
        ->assertJsonPath('data.facultad_id', $otra->id);

    expect($a->fresh()->facultad_id)->toBe($otra->id);
    expect(admSinSesiones($a))->toBeTrue();
});

it('rechaza mover a una facultad inactiva, pero deja editar a quien ya está en una inactiva', function () {
    admActuar();
    $inactiva = admFacultad('INA', false);
    $a = admAdministrador(admFacultad('FIC'));
    $deInactiva = admAdministrador($inactiva);

    $this->putJson(URL_ADM.'/'.$a->id, admPayload(['facultad_id' => $inactiva->id, 'correo' => $a->correo, 'password' => null]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('facultad_id');

    $this->putJson(URL_ADM.'/'.$deInactiva->id, admPayload([
        'facultad_id' => $inactiva->id, 'nombre' => 'Corregido', 'correo' => $deInactiva->correo, 'password' => null,
    ]))->assertOk()->assertJsonPath('data.nombre', 'Corregido');
});

// ---------- Estado ----------

it('desactiva, cierra sesiones, impide el login y se puede volver a activar', function () {
    admActuar();
    $a = admAdministrador(null, ['password' => 'secreto123']);
    admSesion($a);

    $this->patchJson(URL_ADM.'/'.$a->id.'/estado', ['estatus' => 'inactivo'])
        ->assertOk()
        ->assertJsonPath('data.estatus', 'inactivo');

    expect(admSinSesiones($a))->toBeTrue();

    $this->postJson(URL_ADM_LOGIN, [
        'correo' => $a->correo, 'password' => 'secreto123', 'dispositivo' => 'android',
    ])->assertForbidden()->assertJsonPath('codigo', 'cuenta_inactiva');

    $this->patchJson(URL_ADM.'/'.$a->id.'/estado', ['estatus' => 'activo'])
        ->assertOk()
        ->assertJsonPath('data.estatus', 'activo');

    $this->postJson(URL_ADM_LOGIN, [
        'correo' => $a->correo, 'password' => 'secreto123', 'dispositivo' => 'android',
    ])->assertOk();
});

it('permite activar a un administrador pendiente', function () {
    admActuar();
    $a = Usuario::factory()->pendiente()->conRol(Rol::Admin)->create();

    $this->patchJson(URL_ADM.'/'.$a->id.'/estado', ['estatus' => 'activo'])
        ->assertOk()
        ->assertJsonPath('data.estatus', 'activo');
});

it('valida el estado: solo activo o inactivo', function (array $datos) {
    admActuar();
    $a = admAdministrador();

    $this->patchJson(URL_ADM.'/'.$a->id.'/estado', $datos)
        ->assertStatus(422)
        ->assertJsonValidationErrors('estatus');
})->with([
    'vacío' => [[]],
    'pendiente' => [['estatus' => 'pendiente']],
    'raro' => [['estatus' => 'tal vez']],
]);

it('cambiar al mismo estado no falla ni registra bitácora', function () {
    admActuar();
    $a = admAdministrador();

    $this->patchJson(URL_ADM.'/'.$a->id.'/estado', ['estatus' => 'activo'])->assertOk();

    expect(admBitacora($a->id))->toBe([]);
});

// ---------- Eliminar ----------

it('elimina de forma lógica: no aparece, no entra y conserva el registro', function () {
    admActuar();
    $a = admAdministrador(null, ['password' => 'secreto123']);
    admSesion($a);

    $this->deleteJson(URL_ADM.'/'.$a->id)->assertNoContent();

    expect(Usuario::withTrashed()->find($a->id)->deleted_at)->not->toBeNull();
    expect(admSinSesiones($a))->toBeTrue();

    expect($this->getJson(URL_ADM)->json('data'))->toBe([]);

    $this->postJson(URL_ADM_LOGIN, [
        'correo' => $a->correo, 'password' => 'secreto123', 'dispositivo' => 'android',
    ])->assertStatus(422);

    $this->deleteJson(URL_ADM.'/'.$a->id)->assertNotFound();
});

// ---------- Solo administradores de facultad ----------

it('responde 404 al tocar a un super admin, asesor, estudiante o id inexistente', function (string $metodo, string $sufijo) {
    $yo = admActuar();
    $otroSuper = Usuario::factory()->conRol(Rol::SuperAdmin)->create();
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();
    $estudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();

    foreach ([$yo->id, $otroSuper->id, $asesor->id, $estudiante->id, 99999] as $id) {
        $this->json($metodo, URL_ADM.'/'.$id.$sufijo, admPayload(['estatus' => 'inactivo']))->assertNotFound();
    }

    // Nada cambió
    expect(Usuario::find($asesor->id))->not->toBeNull();
})->with([
    ['put', ''],
    ['patch', '/estado'],
    ['delete', ''],
]);

// ---------- Bitácora ----------

it('registra en bitácora crear, editar, desactivar, activar, mover y eliminar', function () {
    $s = admActuar();
    $otra = admFacultad('OTR');

    $id = $this->postJson(URL_ADM, admPayload())->assertCreated()->json('data.id');
    $this->putJson(URL_ADM.'/'.$id, admPayload(['nombre' => 'Otro', 'password' => null]))->assertOk();
    $this->putJson(URL_ADM.'/'.$id, admPayload(['facultad_id' => $otra->id, 'password' => null]))->assertOk();
    $this->patchJson(URL_ADM.'/'.$id.'/estado', ['estatus' => 'inactivo'])->assertOk();
    $this->patchJson(URL_ADM.'/'.$id.'/estado', ['estatus' => 'activo'])->assertOk();
    $this->deleteJson(URL_ADM.'/'.$id)->assertNoContent();

    expect(admBitacora($id))->toBe([
        'administrador.creado',
        'administrador.editado',
        'administrador.movido',
        'administrador.desactivado',
        'administrador.activado',
        'administrador.eliminado',
    ]);

    $registros = Bitacora::withoutGlobalScopes()->where('entidad_id', $id)->orderBy('id')->get();
    expect($registros->every(fn ($b) => $b->usuario_id === $s->id))->toBeTrue();

    // La acción de crear queda en la facultad inicial y las siguientes a la movida, en la nueva
    expect($registros->first()->facultad_id)->toBe(admFacultad('FIC')->id);
    expect($registros->last()->facultad_id)->toBe($otra->id);
    expect($registros->first()->detalles['etiqueta'])->toBe('Ana Pérez Soto');
});

it('la bitácora nunca guarda la contraseña', function () {
    admActuar();

    $id = $this->postJson(URL_ADM, admPayload(['password' => 'clave-super-secreta']))->json('data.id');
    $this->putJson(URL_ADM.'/'.$id, admPayload(['password' => 'otra-clave-secreta']))->assertOk();

    $todo = Bitacora::withoutGlobalScopes()->get()->toJson();

    expect($todo)->not->toContain('clave-super-secreta')->not->toContain('otra-clave-secreta');
});