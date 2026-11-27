<?php

// Guardar como: tests/Feature/AdminAsesoresTest.php
// Autocontenido (prefijo "ads"). Los datos de apoyo se crean ANTES de la primera petición del test.

use App\Enums\EstatusAsesoria;
use App\Enums\EstatusSolicitud;
use App\Enums\EstatusUsuario;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Models\Asesoria;
use App\Models\Bitacora;
use App\Models\CicloEscolar;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Solicitud;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

const URL_ADS_DISC = '/api/v1/admin/asesores-disciplinares';
const URL_ADS_PAR = '/api/v1/admin/asesores-par';

// ---------- Ayudantes ----------

function adsToken(Usuario $u): string
{
    return $u->createToken('prueba')->plainTextToken;
}

function adsUsuario(Rol $rol, string $siglas = 'FIC', array $extra = []): Usuario
{
    return Usuario::factory()->conRol($rol)->create(
        array_merge(['facultad_id' => UsuarioFactory::facultad($siglas)->id], $extra)
    );
}

function adsComoAdmin(?Usuario $admin = null)
{
    app('auth')->forgetGuards();

    return test()->withToken(adsToken($admin ?? adsUsuario(Rol::Admin)));
}

// Disciplinar: solo rol asesor
function adsDisciplinar(array $extra = [], string $siglas = 'FIC'): Usuario
{
    return adsUsuario(Rol::Asesor, $siglas, $extra);
}

// Par: asesor + estudiante
function adsPar(array $extra = [], string $siglas = 'FIC'): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create(
        array_merge(['facultad_id' => UsuarioFactory::facultad($siglas)->id], $extra)
    );
}

function adsPayload(array $extra = []): array
{
    return array_merge([
        'nombre' => 'Carlos Iván',
        'apellido_paterno' => 'Ramírez',
        'apellido_materno' => 'Soto',
        'correo' => 'carlos.ramirez@fic.test',
        'numero_cuenta' => '20190001',
        'num_cel' => '6671234567',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ], $extra);
}

function adsBase(int $facultadId): array
{
    $ciclo = CicloEscolar::withoutGlobalScopes()->where('facultad_id', $facultadId)->first()
        ?? CicloEscolar::create([
            'facultad_id' => $facultadId,
            'nombre' => '2026-2027 1',
            'periodo' => 1,
            'fecha_inicio' => now()->subMonth()->toDateString(),
            'fecha_fin' => now()->addYear()->toDateString(),
            'vigente' => true,
        ]);
    $lic = Licenciatura::firstOrCreate(['facultad_id' => $facultadId, 'nombre' => 'LIC ADS']);
    $grupo = Grupo::firstOrCreate(['licenciatura_id' => $lic->id, 'nombre' => '9-9'], ['anio' => 3]);
    $materia = Materia::firstOrCreate(['licenciatura_id' => $lic->id, 'nombre' => 'Estructura de Datos'], ['semestre' => 1]);

    return [$ciclo, $grupo, $materia];
}

function adsDatosRelacion(Usuario $estudiante, Usuario $asesor, array $extra = []): array
{
    [$ciclo, $grupo, $materia] = adsBase($estudiante->facultad_id);

    return array_merge([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => $ciclo->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => Horario::firstOrCreate(['etiqueta' => '7:00-8:00 AM'])->id,
        'fecha_inicio' => Carbon::now()->next(Carbon::MONDAY)->toDateString(),
        'modalidad' => Modalidad::Virtual,
        'razon' => RazonAsesoria::Parcial,
    ], $extra);
}

function adsSolicitud(Usuario $estudiante, Usuario $asesor, EstatusSolicitud $estatus = EstatusSolicitud::Pendiente): Solicitud
{
    return Solicitud::create(adsDatosRelacion($estudiante, $asesor, ['estatus' => $estatus]));
}

function adsAsesoria(Usuario $estudiante, Usuario $asesor, EstatusAsesoria $estatus = EstatusAsesoria::EnCurso): Asesoria
{
    return Asesoria::create(adsDatosRelacion($estudiante, $asesor, ['estatus' => $estatus]));
}

// Le arma disponibilidad completa a un asesor directo en la BD
function adsDisponibilidad(Usuario $asesor, ?Materia $materia = null): Materia
{
    [, , $base] = adsBase($asesor->facultad_id);
    $materia ??= $base;
    $horario = Horario::firstOrCreate(['etiqueta' => '7:00-8:00 AM']);

    // facultad_id explícito: fuera de una petición el contexto de facultad está vacío y el trait lo dejaría en null
    $f = ['facultad_id' => $asesor->facultad_id];
    $asesor->disponibilidadMaterias()->create($f + ['materia_id' => $materia->id]);
    $asesor->disponibilidadHorarios()->create($f + ['dia' => 'lunes', 'horario_id' => $horario->id]);
    $asesor->disponibilidadModalidades()->create($f + ['modalidad' => Modalidad::Virtual]);

    return $materia;
}

// ---------- Acceso ----------

it('ads: sin token recibe 401 en todas las rutas', function () {
    $this->getJson(URL_ADS_DISC)->assertUnauthorized();
    $this->postJson(URL_ADS_DISC, [])->assertUnauthorized();
    $this->putJson(URL_ADS_DISC.'/1', [])->assertUnauthorized();
    $this->deleteJson(URL_ADS_DISC.'/1')->assertUnauthorized();
    $this->getJson(URL_ADS_PAR)->assertUnauthorized();
    $this->getJson(URL_ADS_PAR.'/candidatos')->assertUnauthorized();
    $this->postJson(URL_ADS_PAR, [])->assertUnauthorized();
    $this->deleteJson(URL_ADS_PAR.'/1')->assertUnauthorized();
    $this->putJson('/api/v1/admin/asesores/1/disponibilidad', [])->assertUnauthorized();
});

it('ads: asesor y estudiante reciben 403', function () {
    $asesor = adsDisciplinar();

    foreach ([adsUsuario(Rol::Asesor), adsUsuario(Rol::Estudiante)] as $usuario) {
        app('auth')->forgetGuards();
        $this->withToken(adsToken($usuario));

        $this->getJson(URL_ADS_DISC)->assertForbidden();
        $this->postJson(URL_ADS_DISC, adsPayload())->assertForbidden();
        $this->deleteJson(URL_ADS_DISC."/{$asesor->id}")->assertForbidden();
        $this->getJson(URL_ADS_PAR)->assertForbidden();
        $this->postJson(URL_ADS_PAR, ['usuario_id' => 1])->assertForbidden();
    }
});

it('ads: el super admin sin X-Facultad-Id recibe 422', function () {
    $this->withToken(adsToken(Usuario::factory()->conRol(Rol::SuperAdmin)->create()))
        ->getJson(URL_ADS_DISC)->assertStatus(422);
});

// ---------- Listado de disciplinares ----------

it('ads listado: solo disciplinares activos de su facultad, aunque no tengan disponibilidad', function () {
    $d = adsDisciplinar(['correo' => 'disc@fic.test', 'numero_cuenta' => null, 'num_cel' => '6670000001']);
    adsPar();
    adsDisciplinar(['estatus' => EstatusUsuario::Inactivo]);
    adsDisciplinar([], 'OTR');
    adsUsuario(Rol::Estudiante);

    adsComoAdmin()->getJson(URL_ADS_DISC)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $d->id)
        ->assertJsonPath('data.0.correo', 'disc@fic.test')
        ->assertJsonPath('data.0.numero_cuenta', null)
        ->assertJsonPath('data.0.telefono', '6670000001')
        ->assertJsonPath('data.0.nombres', $d->nombre)
        ->assertJsonCount(0, 'data.0.disponibilidad.materias')
        ->assertJsonCount(5, 'data.0.disponibilidad.horarios')
        ->assertJsonMissingPath('data.0.password');
});

it('ads listado: busca por nombre, correo y número de cuenta', function () {
    $a = adsDisciplinar(['nombre' => 'Zacarías', 'correo' => 'a@fic.test', 'numero_cuenta' => '111']);
    $b = adsDisciplinar(['nombre' => 'Berta', 'correo' => 'busca.me@fic.test', 'numero_cuenta' => '222']);
    $c = adsDisciplinar(['nombre' => 'Camilo', 'correo' => 'c@fic.test', 'numero_cuenta' => '98765']);

    adsComoAdmin()->getJson(URL_ADS_DISC.'?buscar=Zacar')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
    adsComoAdmin()->getJson(URL_ADS_DISC.'?buscar=busca.me')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->id);
    adsComoAdmin()->getJson(URL_ADS_DISC.'?buscar=9876')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $c->id);
});

it('ads listado: filtra por materia y pagina de 10 en 10', function () {
    $con = adsDisciplinar();
    $materia = adsDisponibilidad($con);
    adsDisciplinar();
    Usuario::factory()->count(10)->conRol(Rol::Asesor)
        ->create(['facultad_id' => UsuarioFactory::facultad()->id]);

    adsComoAdmin()->getJson(URL_ADS_DISC.'?materia_id='.$materia->id)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $con->id);

    adsComoAdmin()->getJson(URL_ADS_DISC)
        ->assertJsonCount(10, 'data')->assertJsonPath('meta.last_page', 2);
});

it('ads listado: el filtro materia_id inválido da 422', function () {
    adsComoAdmin()->getJson(URL_ADS_DISC.'?materia_id=abc')->assertStatus(422);
});

// ---------- Alta ----------

it('ads alta: crea al disciplinar activo, solo con rol asesor, en la facultad del admin y con la contraseña cifrada', function () {
    adsComoAdmin()->postJson(URL_ADS_DISC, adsPayload())
        ->assertCreated()
        ->assertJsonPath('data.nombre', 'Carlos Iván Ramírez Soto')
        ->assertJsonPath('data.correo', 'carlos.ramirez@fic.test')
        ->assertJsonCount(0, 'data.disponibilidad.materias')
        ->assertJsonMissingPath('data.password');

    $nuevo = Usuario::where('correo', 'carlos.ramirez@fic.test')->firstOrFail();

    expect($nuevo->estatus)->toBe(EstatusUsuario::Activo)
        ->and($nuevo->facultad_id)->toBe(UsuarioFactory::facultad()->id)
        ->and($nuevo->tieneRol(Rol::Asesor))->toBeTrue()
        ->and($nuevo->tieneRol(Rol::Estudiante))->toBeFalse()
        ->and(Hash::check('secreto123', $nuevo->password))->toBeTrue();
});

it('ads alta: número de cuenta, apellido materno y teléfono son opcionales', function () {
    adsComoAdmin()->postJson(URL_ADS_DISC, adsPayload([
        'numero_cuenta' => '', 'apellido_materno' => null, 'num_cel' => null,
    ]))->assertCreated();

    expect(Usuario::where('correo', 'carlos.ramirez@fic.test')->first()->numero_cuenta)->toBeNull();
});

it('ads alta: dos disciplinares sin cuenta no chocan entre sí', function () {
    adsComoAdmin()->postJson(URL_ADS_DISC, adsPayload(['numero_cuenta' => null]))->assertCreated();
    adsComoAdmin()->postJson(URL_ADS_DISC, adsPayload(['numero_cuenta' => null, 'correo' => 'otro@fic.test']))->assertCreated();
});

it('ads alta: el correo se guarda en minúsculas y sin espacios', function () {
    adsComoAdmin()->postJson(URL_ADS_DISC, adsPayload(['correo' => '  Carlos@FIC.test ']))->assertCreated();

    expect(Usuario::where('correo', 'carlos@fic.test')->exists())->toBeTrue();
});

it('ads alta: queda en bitácora con el admin como autor', function () {
    $admin = adsUsuario(Rol::Admin);

    adsComoAdmin($admin)->postJson(URL_ADS_DISC, adsPayload())->assertCreated();

    expect(Bitacora::where('accion', 'asesor.creado')->where('usuario_id', $admin->id)->exists())->toBeTrue();
});

it('ads alta: 422 con correo o cuenta repetidos, aunque sea de alguien dado de baja', function () {
    adsDisciplinar(['correo' => 'repe@fic.test', 'numero_cuenta' => '555', 'estatus' => EstatusUsuario::Inactivo]);

    adsComoAdmin()->postJson(URL_ADS_DISC, adsPayload(['correo' => 'repe@fic.test']))
        ->assertStatus(422)->assertJsonValidationErrors('correo');
    adsComoAdmin()->postJson(URL_ADS_DISC, adsPayload(['numero_cuenta' => '555']))
        ->assertStatus(422)->assertJsonValidationErrors('numero_cuenta');
});

it('ads alta: 422 con contraseña faltante, corta o sin confirmación igual', function () {
    foreach ([
        ['password' => null, 'password_confirmation' => null],
        ['password' => 'corta', 'password_confirmation' => 'corta'],
        ['password' => 'secreto123', 'password_confirmation' => 'otra-cosa-1'],
    ] as $extra) {
        adsComoAdmin()->postJson(URL_ADS_DISC, adsPayload($extra))
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }
});

it('ads alta: 422 con nombres con números, cuenta con letras o teléfono mal formado', function () {
    adsComoAdmin()->postJson(URL_ADS_DISC, adsPayload(['nombre' => 'Car1os']))->assertStatus(422)->assertJsonValidationErrors('nombre');
    adsComoAdmin()->postJson(URL_ADS_DISC, adsPayload(['numero_cuenta' => '12ab']))->assertStatus(422)->assertJsonValidationErrors('numero_cuenta');
    adsComoAdmin()->postJson(URL_ADS_DISC, adsPayload(['num_cel' => '123']))->assertStatus(422)->assertJsonValidationErrors('num_cel');
});

// ---------- Edición ----------

it('ads edición: cambia nombre, apellidos, correo, cuenta y teléfono', function () {
    $d = adsDisciplinar();

    adsComoAdmin()->putJson(URL_ADS_DISC."/{$d->id}", adsPayload([
        'nombre' => 'Nuevo', 'correo' => 'nuevo@fic.test', 'numero_cuenta' => '777', 'num_cel' => '6679998888',
        'password' => null, 'password_confirmation' => null,
    ]))->assertOk()->assertJsonPath('data.nombres', 'Nuevo')->assertJsonPath('data.telefono', '6679998888');

    $d->refresh();
    expect($d->correo)->toBe('nuevo@fic.test')->and($d->numero_cuenta)->toBe('777');
});

it('ads edición: sin contraseña no la cambia ni cierra sus sesiones', function () {
    $d = adsDisciplinar();
    $hash = $d->password;
    $d->createToken('app');

    adsComoAdmin()->putJson(URL_ADS_DISC."/{$d->id}", adsPayload([
        'correo' => $d->correo, 'numero_cuenta' => $d->numero_cuenta,
        'password' => null, 'password_confirmation' => null,
    ]))->assertOk();

    expect($d->fresh()->password)->toBe($hash)->and($d->tokens()->count())->toBe(1);
});

it('ads edición: con contraseña nueva la cambia y cierra sus sesiones', function () {
    $d = adsDisciplinar();
    $d->createToken('app');

    adsComoAdmin()->putJson(URL_ADS_DISC."/{$d->id}", adsPayload([
        'correo' => $d->correo, 'numero_cuenta' => $d->numero_cuenta,
        'password' => 'nueva-clave-1', 'password_confirmation' => 'nueva-clave-1',
    ]))->assertOk();

    expect(Hash::check('nueva-clave-1', $d->fresh()->password))->toBeTrue()->and($d->tokens()->count())->toBe(0);
});

it('ads edición: conservar su propio correo y cuenta no choca, pero los de otro sí', function () {
    $d = adsDisciplinar();
    $otro = adsDisciplinar(['correo' => 'otro@fic.test', 'numero_cuenta' => '4444']);
    $base = ['password' => null, 'password_confirmation' => null];

    adsComoAdmin()->putJson(URL_ADS_DISC."/{$d->id}", adsPayload($base + [
        'correo' => $d->correo, 'numero_cuenta' => $d->numero_cuenta,
    ]))->assertOk();

    adsComoAdmin()->putJson(URL_ADS_DISC."/{$d->id}", adsPayload($base + [
        'correo' => $otro->correo, 'numero_cuenta' => $d->numero_cuenta,
    ]))->assertStatus(422)->assertJsonValidationErrors('correo');

    adsComoAdmin()->putJson(URL_ADS_DISC."/{$d->id}", adsPayload($base + [
        'correo' => $d->correo, 'numero_cuenta' => $otro->numero_cuenta,
    ]))->assertStatus(422)->assertJsonValidationErrors('numero_cuenta');
});

it('ads edición: 404 con otra facultad, con un par, con un estudiante o uno que no existe', function () {
    $datos = adsPayload(['password' => null, 'password_confirmation' => null]);

    foreach ([adsDisciplinar([], 'OTR'), adsPar(), adsUsuario(Rol::Estudiante)] as $u) {
        adsComoAdmin()->putJson(URL_ADS_DISC."/{$u->id}", $datos)->assertNotFound();
    }

    adsComoAdmin()->putJson(URL_ADS_DISC.'/999999', $datos)->assertNotFound();
});

it('ads edición: queda en bitácora solo si algo cambió', function () {
    $d = adsDisciplinar();
    $admin = adsUsuario(Rol::Admin);

    $igual = adsPayload([
        'nombre' => $d->nombre, 'apellido_paterno' => $d->apellido_paterno,
        'apellido_materno' => $d->apellido_materno, 'correo' => $d->correo,
        'numero_cuenta' => $d->numero_cuenta, 'num_cel' => $d->num_cel,
        'password' => null, 'password_confirmation' => null,
    ]);

    adsComoAdmin($admin)->putJson(URL_ADS_DISC."/{$d->id}", $igual)->assertOk();
    expect(Bitacora::where('accion', 'asesor.editado')->count())->toBe(0);

    adsComoAdmin($admin)->putJson(URL_ADS_DISC."/{$d->id}", array_merge($igual, ['num_cel' => '6671112222']))->assertOk();
    expect(Bitacora::where('accion', 'asesor.editado')->where('usuario_id', $admin->id)->count())->toBe(1);
});

// ---------- Baja de disciplinar ----------

it('ads baja: pasa a inactivo, conserva el registro y su disponibilidad, cierra sesiones y sale del listado', function () {
    $d = adsDisciplinar();
    adsDisponibilidad($d);
    $d->createToken('app');

    adsComoAdmin()->deleteJson(URL_ADS_DISC."/{$d->id}")->assertNoContent();

    $d->refresh();
    expect($d->estatus)->toBe(EstatusUsuario::Inactivo)
        ->and($d->trashed())->toBeFalse()
        ->and($d->tokens()->count())->toBe(0)
        ->and($d->disponibilidadMaterias()->count())->toBe(1);

    adsComoAdmin()->getJson(URL_ADS_DISC)->assertOk()->assertJsonCount(0, 'data');
});

it('ads baja: queda en bitácora con el admin como autor', function () {
    $d = adsDisciplinar();
    $admin = adsUsuario(Rol::Admin);

    adsComoAdmin($admin)->deleteJson(URL_ADS_DISC."/{$d->id}")->assertNoContent();

    expect(Bitacora::where('accion', 'asesor.baja')->where('usuario_id', $admin->id)->exists())->toBeTrue();
});

it('ads baja: 409 con asesorías en curso, y no cambia nada', function () {
    $d = adsDisciplinar();
    adsAsesoria(adsUsuario(Rol::Estudiante), $d);

    adsComoAdmin()->deleteJson(URL_ADS_DISC."/{$d->id}")
        ->assertStatus(409)->assertJsonPath('codigo', 'asesor_con_asesorias_en_curso');

    expect($d->fresh()->estatus)->toBe(EstatusUsuario::Activo);
});

it('ads baja: una asesoría completada no la bloquea', function () {
    $d = adsDisciplinar();
    adsAsesoria(adsUsuario(Rol::Estudiante), $d, EstatusAsesoria::Completada);

    adsComoAdmin()->deleteJson(URL_ADS_DISC."/{$d->id}")->assertNoContent();
});

it('ads baja: rechaza con motivo las solicitudes pendientes y no toca las cerradas', function () {
    $d = adsDisciplinar();
    $est = adsUsuario(Rol::Estudiante);
    $pendiente = adsSolicitud($est, $d);
    $aceptada = adsSolicitud($est, $d, EstatusSolicitud::Aceptada);

    adsComoAdmin()->deleteJson(URL_ADS_DISC."/{$d->id}")->assertNoContent();

    expect($pendiente->fresh()->estatus)->toBe(EstatusSolicitud::Rechazada)
        ->and($pendiente->fresh()->motivo_rechazo)->toBe('El asesor ya no está disponible.')
        ->and($pendiente->fresh()->respondida_en)->not->toBeNull()
        ->and($aceptada->fresh()->estatus)->toBe(EstatusSolicitud::Aceptada);
});

it('ads baja: 404 con otra facultad, con un par y al repetir la baja', function () {
    $d = adsDisciplinar();
    $otraFacultad = adsDisciplinar([], 'OTR');
    $par = adsPar();

    adsComoAdmin()->deleteJson(URL_ADS_DISC."/{$otraFacultad->id}")->assertNotFound();
    adsComoAdmin()->deleteJson(URL_ADS_DISC."/{$par->id}")->assertNotFound();

    adsComoAdmin()->deleteJson(URL_ADS_DISC."/{$d->id}")->assertNoContent();
    adsComoAdmin()->deleteJson(URL_ADS_DISC."/{$d->id}")->assertNotFound();
});

// ---------- Pares: listado y candidatos ----------

it('ads par listado: solo pares activos de su facultad, con sus datos y disponibilidad', function () {
    $p = adsPar(['correo' => 'par@fic.test']);
    $materia = adsDisponibilidad($p);
    adsDisciplinar();
    adsUsuario(Rol::Estudiante);
    adsPar(['estatus' => EstatusUsuario::Inactivo]);
    adsPar([], 'OTR');

    adsComoAdmin()->getJson(URL_ADS_PAR)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $p->id)
        ->assertJsonPath('data.0.correo', 'par@fic.test')
        ->assertJsonPath('data.0.disponibilidad.materias.0.id', $materia->id);
});

it('ads par listado: busca y filtra por materia', function () {
    $a = adsPar(['nombre' => 'Zacarías']);
    $materia = adsDisponibilidad($a);
    adsPar(['nombre' => 'Berta']);

    adsComoAdmin()->getJson(URL_ADS_PAR.'?buscar=Zacar')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
    adsComoAdmin()->getJson(URL_ADS_PAR.'?materia_id='.$materia->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
});

it('ads par candidatos: estudiantes activos de su facultad que aún no son asesores', function () {
    $c = adsUsuario(Rol::Estudiante, 'FIC', ['nombre' => 'Candidata']);
    adsPar();
    adsDisciplinar();
    adsUsuario(Rol::Estudiante, 'FIC', ['estatus' => EstatusUsuario::Inactivo]);
    adsUsuario(Rol::Estudiante, 'OTR');

    adsComoAdmin()->getJson(URL_ADS_PAR.'/candidatos')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $c->id);

    adsComoAdmin()->getJson(URL_ADS_PAR.'/candidatos?buscar=Candid')->assertJsonCount(1, 'data');
    adsComoAdmin()->getJson(URL_ADS_PAR.'/candidatos?buscar=zzzz')->assertJsonCount(0, 'data');
});

// ---------- Pares: asignar ----------

it('ads par asignar: agrega el rol asesor y conserva el de estudiante', function () {
    $est = adsUsuario(Rol::Estudiante);
    $admin = adsUsuario(Rol::Admin);

    adsComoAdmin($admin)->postJson(URL_ADS_PAR, ['usuario_id' => $est->id])
        ->assertCreated()->assertJsonPath('data.id', $est->id);

    $est = $est->fresh();
    expect($est->tieneRol(Rol::Asesor))->toBeTrue()->and($est->tieneRol(Rol::Estudiante))->toBeTrue();
    expect(Bitacora::where('accion', 'asesor_par.asignado')->where('usuario_id', $admin->id)->exists())->toBeTrue();
});

it('ads par asignar: 409 si ya es asesor', function () {
    $p = adsPar();

    adsComoAdmin()->postJson(URL_ADS_PAR, ['usuario_id' => $p->id])
        ->assertStatus(409)->assertJsonPath('codigo', 'ya_es_asesor');
});

it('ads par asignar: 404 con inactivo, de otra facultad, sin rol de estudiante o inexistente; 422 sin usuario_id', function () {
    foreach ([
        adsUsuario(Rol::Estudiante, 'FIC', ['estatus' => EstatusUsuario::Inactivo]),
        adsUsuario(Rol::Estudiante, 'OTR'),
        adsDisciplinar(),
    ] as $u) {
        adsComoAdmin()->postJson(URL_ADS_PAR, ['usuario_id' => $u->id])->assertNotFound();
    }

    adsComoAdmin()->postJson(URL_ADS_PAR, ['usuario_id' => 999999])->assertNotFound();
    adsComoAdmin()->postJson(URL_ADS_PAR, [])->assertStatus(422)->assertJsonValidationErrors('usuario_id');
});

// ---------- Pares: quitar rol ----------

it('ads par quitar: deja de ser asesor, sigue de estudiante, conserva la disponibilidad y no cierra sesiones', function () {
    $p = adsPar();
    adsDisponibilidad($p);
    $p->createToken('app');
    $admin = adsUsuario(Rol::Admin);

    adsComoAdmin($admin)->deleteJson(URL_ADS_PAR."/{$p->id}")->assertNoContent();

    $p = $p->fresh();
    expect($p->tieneRol(Rol::Asesor))->toBeFalse()
        ->and($p->tieneRol(Rol::Estudiante))->toBeTrue()
        ->and($p->estatus)->toBe(EstatusUsuario::Activo)
        ->and($p->disponibilidadMaterias()->count())->toBe(1)
        ->and($p->tokens()->count())->toBe(1);

    expect(Bitacora::where('accion', 'asesor_par.quitado')->where('usuario_id', $admin->id)->exists())->toBeTrue();

    adsComoAdmin()->getJson(URL_ADS_PAR)->assertJsonCount(0, 'data');
});

it('ads par quitar: 409 con asesorías en curso como asesor, y no cambia nada', function () {
    $p = adsPar();
    adsAsesoria(adsUsuario(Rol::Estudiante), $p);

    adsComoAdmin()->deleteJson(URL_ADS_PAR."/{$p->id}")
        ->assertStatus(409)->assertJsonPath('codigo', 'asesor_con_asesorias_en_curso');

    expect($p->fresh()->tieneRol(Rol::Asesor))->toBeTrue();
});

it('ads par quitar: rechaza las solicitudes pendientes que le habían pedido como asesor', function () {
    $p = adsPar();
    $pendiente = adsSolicitud(adsUsuario(Rol::Estudiante), $p);

    adsComoAdmin()->deleteJson(URL_ADS_PAR."/{$p->id}")->assertNoContent();

    expect($pendiente->fresh()->estatus)->toBe(EstatusSolicitud::Rechazada)
        ->and($pendiente->fresh()->motivo_rechazo)->toBe('El asesor ya no está disponible.');
});

it('ads par quitar: 404 con un disciplinar, de otra facultad o al repetir', function () {
    $p = adsPar();
    $disciplinar = adsDisciplinar();
    $otraFacultad = adsPar([], 'OTR');

    adsComoAdmin()->deleteJson(URL_ADS_PAR."/{$disciplinar->id}")->assertNotFound();
    adsComoAdmin()->deleteJson(URL_ADS_PAR."/{$otraFacultad->id}")->assertNotFound();

    adsComoAdmin()->deleteJson(URL_ADS_PAR."/{$p->id}")->assertNoContent();
    adsComoAdmin()->deleteJson(URL_ADS_PAR."/{$p->id}")->assertNotFound();
});

// ---------- Disponibilidad desde el admin ----------

function adsCuerpoDisponibilidad(Materia $materia): array
{
    return [
        'materias' => [$materia->id],
        'horarios' => [['dia' => 'martes', 'horario_id' => Horario::firstOrCreate(['etiqueta' => '7:00-8:00 AM'])->id]],
        'modalidades' => ['presencial'],
    ];
}

it('ads disponibilidad: el admin la arma a un disciplinar y a un par, y luego aparecen en la lista', function () {
    $d = adsDisciplinar();
    $p = adsPar();
    [, , $materia] = adsBase($d->facultad_id);

    foreach ([$d, $p] as $asesor) {
        adsComoAdmin()->putJson("/api/v1/admin/asesores/{$asesor->id}/disponibilidad", adsCuerpoDisponibilidad($materia))
            ->assertOk()
            ->assertJsonPath('data.materias.0.id', $materia->id)
            ->assertJsonPath('data.modalidades.0.valor', 'presencial');
    }

    adsComoAdmin()->getJson(URL_ADS_DISC)->assertJsonPath('data.0.disponibilidad.materias.0.id', $materia->id);
    adsComoAdmin()->getJson('/api/v1/admin/asesores')->assertOk()->assertJsonCount(2, 'data');
});

it('ads disponibilidad: 404 con un asesor de otra facultad, un estudiante o inactivo; 422 con cuerpo vacío', function () {
    $d = adsDisciplinar();
    [, , $materia] = adsBase($d->facultad_id);

    foreach ([adsDisciplinar([], 'OTR'), adsUsuario(Rol::Estudiante), adsDisciplinar(['estatus' => EstatusUsuario::Inactivo])] as $u) {
        adsComoAdmin()->putJson("/api/v1/admin/asesores/{$u->id}/disponibilidad", adsCuerpoDisponibilidad($materia))->assertNotFound();
    }

    adsComoAdmin()->putJson("/api/v1/admin/asesores/{$d->id}/disponibilidad", [])->assertStatus(422);
});