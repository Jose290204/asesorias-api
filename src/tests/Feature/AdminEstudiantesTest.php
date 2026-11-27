<?php


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

const URL_ADE = '/api/v1/admin/estudiantes';

// ---------- Ayudantes ----------

function adeToken(Usuario $u): string
{
    return $u->createToken('prueba')->plainTextToken;
}

function adeUsuario(Rol $rol, string $siglas = 'FIC', array $extra = []): Usuario
{
    return Usuario::factory()->conRol($rol)->create(
        array_merge(['facultad_id' => UsuarioFactory::facultad($siglas)->id], $extra)
    );
}

// Petición como admin (de FIC si no se pasa otro). Limpia el guard: Sanctum cachea al usuario
function adeComoAdmin(?Usuario $admin = null)
{
    app('auth')->forgetGuards();

    return test()->withToken(adeToken($admin ?? adeUsuario(Rol::Admin)));
}

// Grupo de una licenciatura de la facultad dada (se crean si no existen)
function adeGrupo(string $siglas = 'FIC', string $nombre = '3-1', ?string $licenciatura = null): Grupo
{
    $facultad = UsuarioFactory::facultad($siglas);
    $lic = Licenciatura::firstOrCreate([
        'facultad_id' => $facultad->id,
        'nombre' => $licenciatura ?? "LICENCIATURA {$siglas}",
    ]);

    return Grupo::firstOrCreate(['licenciatura_id' => $lic->id, 'nombre' => $nombre], ['anio' => 3]);
}

function adeEstudiante(Grupo $grupo, array $extra = [], string $siglas = 'FIC'): Usuario
{
    return adeUsuario(Rol::Estudiante, $siglas, array_merge(['grupo_id' => $grupo->id], $extra));
}

function adePayload(Grupo $grupo, array $extra = []): array
{
    return array_merge([
        'nombre' => 'Ana María',
        'apellido_paterno' => 'López',
        'apellido_materno' => 'Pérez',
        'correo' => 'ana.lopez@fic.test',
        'numero_cuenta' => '20241234',
        'num_cel' => '6671234567',
        'grupo_id' => $grupo->id,
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ], $extra);
}

// Ciclo, grupo y materia para poder crear solicitudes y asesorías directo en la BD
function adeBase(int $facultadId): array
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
    $lic = Licenciatura::firstOrCreate(['facultad_id' => $facultadId, 'nombre' => 'LIC ADE']);
    $grupo = Grupo::firstOrCreate(['licenciatura_id' => $lic->id, 'nombre' => '9-9'], ['anio' => 3]);
    $materia = Materia::firstOrCreate(['licenciatura_id' => $lic->id, 'nombre' => 'Estructura de Datos'], ['semestre' => 1]);

    return [$ciclo, $grupo, $materia];
}

function adeDatosRelacion(Usuario $estudiante, Usuario $asesor, array $extra = []): array
{
    [$ciclo, $grupo, $materia] = adeBase($estudiante->facultad_id);

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

function adeSolicitud(Usuario $estudiante, Usuario $asesor, EstatusSolicitud $estatus = EstatusSolicitud::Pendiente): Solicitud
{
    return Solicitud::create(adeDatosRelacion($estudiante, $asesor, ['estatus' => $estatus]));
}

function adeAsesoria(Usuario $estudiante, Usuario $asesor, EstatusAsesoria $estatus = EstatusAsesoria::EnCurso): Asesoria
{
    return Asesoria::create(adeDatosRelacion($estudiante, $asesor, ['estatus' => $estatus]));
}

// ---------- Acceso ----------

it('ade: sin token recibe 401 en las cuatro rutas', function () {
    $this->getJson(URL_ADE)->assertUnauthorized();
    $this->postJson(URL_ADE, [])->assertUnauthorized();
    $this->putJson(URL_ADE.'/1', [])->assertUnauthorized();
    $this->deleteJson(URL_ADE.'/1')->assertUnauthorized();
});

it('ade: asesor y estudiante reciben 403', function () {
    $grupo = adeGrupo();
    $est = adeEstudiante($grupo);

    foreach ([adeUsuario(Rol::Asesor), adeUsuario(Rol::Estudiante)] as $usuario) {
        app('auth')->forgetGuards();
        $this->withToken(adeToken($usuario));

        $this->postJson(URL_ADE, adePayload($grupo))->assertForbidden();
        $this->putJson(URL_ADE."/{$est->id}", adePayload($grupo))->assertForbidden();
        $this->deleteJson(URL_ADE."/{$est->id}")->assertForbidden();
    }
});

it('ade: el super admin sin X-Facultad-Id recibe 422', function () {
    $grupo = adeGrupo();

    $this->withToken(adeToken(Usuario::factory()->conRol(Rol::SuperAdmin)->create()))
        ->postJson(URL_ADE, adePayload($grupo))->assertStatus(422);
});

// ---------- Listado ----------

it('ade listado: trae los datos para ver y editar, y solo estudiantes activos de su facultad', function () {
    $grupo = adeGrupo('FIC', '3-1', 'INFORMÁTICA');
    $est = adeEstudiante($grupo, ['correo' => 'luis@fic.test', 'numero_cuenta' => '20230001', 'num_cel' => '6670000001']);

    adeEstudiante($grupo, ['estatus' => EstatusUsuario::Inactivo]);
    adeUsuario(Rol::Asesor);
    adeEstudiante(adeGrupo('OTR'), [], 'OTR');

    adeComoAdmin()->getJson(URL_ADE)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $est->id)
        ->assertJsonPath('data.0.correo', 'luis@fic.test')
        ->assertJsonPath('data.0.numero_cuenta', '20230001')
        ->assertJsonPath('data.0.telefono', '6670000001')
        ->assertJsonPath('data.0.nombres', $est->nombre)
        ->assertJsonPath('data.0.apellido_paterno', $est->apellido_paterno)
        ->assertJsonPath('data.0.grupo', '3-1')
        ->assertJsonPath('data.0.grupo_id', $grupo->id)
        ->assertJsonPath('data.0.licenciatura', 'INFORMÁTICA')
        ->assertJsonPath('data.0.licenciatura_id', $grupo->licenciatura_id)
        ->assertJsonMissingPath('data.0.promedio')
        ->assertJsonMissingPath('data.0.estatus');
});

it('ade listado: filtra por grupo y por licenciatura', function () {
    $g1 = adeGrupo('FIC', '3-1', 'INFORMÁTICA');
    $g2 = adeGrupo('FIC', '1-1', 'CIENCIA DE DATOS');
    $a = adeEstudiante($g1);
    $b = adeEstudiante($g2);

    adeComoAdmin()->getJson(URL_ADE.'?grupo_id='.$g1->id)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);

    adeComoAdmin()->getJson(URL_ADE.'?licenciatura_id='.$g2->licenciatura_id)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->id);
});

it('ade listado: busca por nombre, número de cuenta y correo', function () {
    $g = adeGrupo();
    $a = adeEstudiante($g, ['nombre' => 'Zacarías', 'apellido_paterno' => 'Quiroz', 'numero_cuenta' => '20219999', 'correo' => 'unico@fic.test']);
    adeEstudiante($g);

    foreach (['Zacarías', '20219999', 'unico@fic'] as $texto) {
        adeComoAdmin()->getJson(URL_ADE.'?buscar='.urlencode($texto))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
    }
});

it('ade listado: filtros inválidos dan 422', function () {
    adeComoAdmin()->getJson(URL_ADE.'?grupo_id=abc')->assertStatus(422);
});

// ---------- Alta ----------

it('ade alta: crea al estudiante activo, con su rol, en la facultad del admin y con la contraseña cifrada', function () {
    $grupo = adeGrupo();

    adeComoAdmin()->postJson(URL_ADE, adePayload($grupo))
        ->assertCreated()
        ->assertJsonPath('data.nombre', 'Ana María López Pérez')
        ->assertJsonPath('data.correo', 'ana.lopez@fic.test')
        ->assertJsonPath('data.grupo_id', $grupo->id)
        ->assertJsonMissingPath('data.password');

    $nuevo = Usuario::where('correo', 'ana.lopez@fic.test')->firstOrFail();

    expect($nuevo->estatus)->toBe(EstatusUsuario::Activo)
        ->and($nuevo->facultad_id)->toBe(UsuarioFactory::facultad()->id)
        ->and($nuevo->tieneRol(Rol::Estudiante))->toBeTrue()
        ->and($nuevo->tieneRol(Rol::Asesor))->toBeFalse()
        ->and($nuevo->password)->not->toBe('secreto123')
        ->and(Hash::check('secreto123', $nuevo->password))->toBeTrue();
});

it('ade alta: apellido materno y teléfono son opcionales', function () {
    $grupo = adeGrupo();

    adeComoAdmin()->postJson(URL_ADE, adePayload($grupo, ['apellido_materno' => null, 'num_cel' => null]))
        ->assertCreated();
});

it('ade alta: el correo se guarda en minúsculas y sin espacios', function () {
    $grupo = adeGrupo();

    adeComoAdmin()->postJson(URL_ADE, adePayload($grupo, ['correo' => '  ANA.Lopez@FIC.test ']))
        ->assertCreated()->assertJsonPath('data.correo', 'ana.lopez@fic.test');
});

it('ade alta: queda en bitácora con el admin como autor', function () {
    $grupo = adeGrupo();
    $admin = adeUsuario(Rol::Admin);

    adeComoAdmin($admin)->postJson(URL_ADE, adePayload($grupo))->assertCreated();

    expect(Bitacora::where('accion', 'estudiante.creado')->where('usuario_id', $admin->id)->exists())->toBeTrue();
});

it('ade alta: 422 con correo o número de cuenta repetidos, aunque sea de un estudiante dado de baja', function () {
    $grupo = adeGrupo();
    adeEstudiante($grupo, ['correo' => 'ana.lopez@fic.test', 'estatus' => EstatusUsuario::Inactivo]);
    adeEstudiante($grupo, ['numero_cuenta' => '20241234']);

    adeComoAdmin()->postJson(URL_ADE, adePayload($grupo, ['numero_cuenta' => '20250000']))
        ->assertStatus(422)->assertJsonValidationErrors('correo');

    adeComoAdmin()->postJson(URL_ADE, adePayload($grupo, ['correo' => 'otra@fic.test']))
        ->assertStatus(422)->assertJsonValidationErrors('numero_cuenta');
});

it('ade alta: 422 con un grupo de otra facultad o sin grupo', function () {
    $grupo = adeGrupo();
    $ajeno = adeGrupo('OTR');

    adeComoAdmin()->postJson(URL_ADE, adePayload($grupo, ['grupo_id' => $ajeno->id]))
        ->assertStatus(422)->assertJsonValidationErrors('grupo_id');

    adeComoAdmin()->postJson(URL_ADE, adePayload($grupo, ['grupo_id' => null]))
        ->assertStatus(422)->assertJsonValidationErrors('grupo_id');
});

it('ade alta: 422 con contraseña faltante, corta o sin confirmación igual', function () {
    $grupo = adeGrupo();

    adeComoAdmin()->postJson(URL_ADE, adePayload($grupo, ['password' => null, 'password_confirmation' => null]))
        ->assertStatus(422)->assertJsonValidationErrors('password');

    adeComoAdmin()->postJson(URL_ADE, adePayload($grupo, ['password' => 'corta', 'password_confirmation' => 'corta']))
        ->assertStatus(422)->assertJsonValidationErrors('password');

    adeComoAdmin()->postJson(URL_ADE, adePayload($grupo, ['password_confirmation' => 'distinta123']))
        ->assertStatus(422)->assertJsonValidationErrors('password');
});

it('ade alta: 422 con nombres con números, cuenta con letras o teléfono mal formado', function () {
    $grupo = adeGrupo();

    adeComoAdmin()->postJson(URL_ADE, adePayload($grupo, ['nombre' => 'Ana2', 'numero_cuenta' => '2024ABC', 'num_cel' => '123']))
        ->assertStatus(422)->assertJsonValidationErrors(['nombre', 'numero_cuenta', 'num_cel']);
});

// ---------- Edición ----------

it('ade edición: cambia nombre, apellidos, correo, cuenta, teléfono y grupo', function () {
    $g1 = adeGrupo('FIC', '3-1');
    $g2 = adeGrupo('FIC', '1-1');
    $est = adeEstudiante($g1);

    adeComoAdmin()->putJson(URL_ADE."/{$est->id}", adePayload($g2, [
        'nombre' => 'Luis', 'apellido_paterno' => 'Gámez', 'apellido_materno' => null,
        'correo' => 'luis@fic.test', 'numero_cuenta' => '20230002', 'num_cel' => '6679999999',
        'password' => null, 'password_confirmation' => null,
    ]))
        ->assertOk()
        ->assertJsonPath('data.nombre', 'Luis Gámez')
        ->assertJsonPath('data.grupo_id', $g2->id)
        ->assertJsonPath('data.grupo', '1-1');

    $est->refresh();
    expect($est->correo)->toBe('luis@fic.test')
        ->and($est->numero_cuenta)->toBe('20230002')
        ->and($est->num_cel)->toBe('6679999999')
        ->and($est->grupo_id)->toBe($g2->id);
});

it('ade edición: sin contraseña no la cambia ni cierra sus sesiones', function () {
    $grupo = adeGrupo();
    $est = adeEstudiante($grupo);
    $hash = $est->password;
    $est->createToken('app');

    adeComoAdmin()->putJson(URL_ADE."/{$est->id}", adePayload($grupo, [
        'correo' => $est->correo, 'numero_cuenta' => $est->numero_cuenta,
        'password' => null, 'password_confirmation' => null,
    ]))->assertOk();

    expect($est->fresh()->password)->toBe($hash)
        ->and($est->tokens()->count())->toBe(1);
});

it('ade edición: con contraseña nueva la cambia y cierra sus sesiones', function () {
    $grupo = adeGrupo();
    $est = adeEstudiante($grupo);
    $est->createToken('app');

    adeComoAdmin()->putJson(URL_ADE."/{$est->id}", adePayload($grupo, [
        'correo' => $est->correo, 'numero_cuenta' => $est->numero_cuenta,
        'password' => 'nueva-clave-1', 'password_confirmation' => 'nueva-clave-1',
    ]))->assertOk();

    expect(Hash::check('nueva-clave-1', $est->fresh()->password))->toBeTrue()
        ->and($est->tokens()->count())->toBe(0);
});

it('ade edición: la contraseña nueva exige confirmación igual', function () {
    $grupo = adeGrupo();
    $est = adeEstudiante($grupo);

    adeComoAdmin()->putJson(URL_ADE."/{$est->id}", adePayload($grupo, [
        'correo' => $est->correo, 'numero_cuenta' => $est->numero_cuenta,
        'password' => 'nueva-clave-1', 'password_confirmation' => 'otra-clave-22',
    ]))->assertStatus(422)->assertJsonValidationErrors('password');
});

it('ade edición: conservar su propio correo y cuenta no choca, pero los de otro sí', function () {
    $grupo = adeGrupo();
    $est = adeEstudiante($grupo);
    $otro = adeEstudiante($grupo, ['correo' => 'otro@fic.test', 'numero_cuenta' => '20230077']);

    $sinCambios = adePayload($grupo, [
        'correo' => $est->correo, 'numero_cuenta' => $est->numero_cuenta,
        'password' => null, 'password_confirmation' => null,
    ]);

    adeComoAdmin()->putJson(URL_ADE."/{$est->id}", $sinCambios)->assertOk();

    adeComoAdmin()->putJson(URL_ADE."/{$est->id}", array_merge($sinCambios, ['correo' => 'otro@fic.test']))
        ->assertStatus(422)->assertJsonValidationErrors('correo');

    adeComoAdmin()->putJson(URL_ADE."/{$est->id}", array_merge($sinCambios, ['numero_cuenta' => '20230077']))
        ->assertStatus(422)->assertJsonValidationErrors('numero_cuenta');
});

it('ade edición: 404 con un estudiante de otra facultad, con un asesor puro o con uno que no existe', function () {
    $grupo = adeGrupo();
    $ajeno = adeEstudiante(adeGrupo('OTR'), [], 'OTR');
    $asesor = adeUsuario(Rol::Asesor);

    foreach ([$ajeno->id, $asesor->id, 999999] as $id) {
        adeComoAdmin()->putJson(URL_ADE."/{$id}", adePayload($grupo))->assertNotFound();
    }
});

it('ade edición: queda en bitácora solo si algo cambió', function () {
    $grupo = adeGrupo();
    $est = adeEstudiante($grupo);
    $admin = adeUsuario(Rol::Admin);

    $igual = adePayload($grupo, [
        'nombre' => $est->nombre, 'apellido_paterno' => $est->apellido_paterno,
        'apellido_materno' => $est->apellido_materno, 'correo' => $est->correo,
        'numero_cuenta' => $est->numero_cuenta, 'num_cel' => $est->num_cel,
        'password' => null, 'password_confirmation' => null,
    ]);

    adeComoAdmin($admin)->putJson(URL_ADE."/{$est->id}", $igual)->assertOk();
    expect(Bitacora::where('accion', 'estudiante.editado')->count())->toBe(0);

    adeComoAdmin($admin)->putJson(URL_ADE."/{$est->id}", array_merge($igual, ['num_cel' => '6671112222']))->assertOk();
    expect(Bitacora::where('accion', 'estudiante.editado')->where('usuario_id', $admin->id)->count())->toBe(1);
});

// ---------- Baja ----------

it('ade baja: pasa a inactivo, conserva el registro, cierra sus sesiones y desaparece del listado', function () {
    $grupo = adeGrupo();
    $est = adeEstudiante($grupo);
    $est->createToken('app');

    adeComoAdmin()->deleteJson(URL_ADE."/{$est->id}")->assertNoContent();

    $est->refresh();
    expect($est->estatus)->toBe(EstatusUsuario::Inactivo)
        ->and($est->trashed())->toBeFalse()
        ->and($est->tokens()->count())->toBe(0);

    adeComoAdmin()->getJson(URL_ADE)->assertOk()->assertJsonCount(0, 'data');
});

it('ade baja: queda en bitácora con el admin como autor', function () {
    $est = adeEstudiante(adeGrupo());
    $admin = adeUsuario(Rol::Admin);

    adeComoAdmin($admin)->deleteJson(URL_ADE."/{$est->id}")->assertNoContent();

    expect(Bitacora::where('accion', 'estudiante.baja')->where('usuario_id', $admin->id)->exists())->toBeTrue();
});

it('ade baja: 409 si tiene asesorías en curso, y no cambia nada', function () {
    $est = adeEstudiante(adeGrupo());
    $asesor = adeUsuario(Rol::Asesor);
    adeAsesoria($est, $asesor);

    adeComoAdmin()->deleteJson(URL_ADE."/{$est->id}")
        ->assertStatus(409)->assertJsonPath('codigo', 'estudiante_con_asesorias_en_curso');

    expect($est->fresh()->estatus)->toBe(EstatusUsuario::Activo);
});

it('ade baja: una asesoría ya completada no la bloquea', function () {
    $est = adeEstudiante(adeGrupo());
    adeAsesoria($est, adeUsuario(Rol::Asesor), EstatusAsesoria::Completada);

    adeComoAdmin()->deleteJson(URL_ADE."/{$est->id}")->assertNoContent();
});

it('ade baja: cancela las solicitudes pendientes que había pedido y no toca las ya cerradas', function () {
    $est = adeEstudiante(adeGrupo());
    $asesor = adeUsuario(Rol::Asesor);
    $pendiente = adeSolicitud($est, $asesor);
    $aceptada = adeSolicitud($est, $asesor, EstatusSolicitud::Aceptada);

    adeComoAdmin()->deleteJson(URL_ADE."/{$est->id}")->assertNoContent();

    expect($pendiente->fresh()->estatus)->toBe(EstatusSolicitud::Cancelada)
        ->and($pendiente->fresh()->respondida_en)->not->toBeNull()
        ->and($aceptada->fresh()->estatus)->toBe(EstatusSolicitud::Aceptada);
});

it('ade baja: 404 con uno de otra facultad, con un asesor puro y al repetir la baja', function () {
    $ajeno = adeEstudiante(adeGrupo('OTR'), [], 'OTR');
    $asesor = adeUsuario(Rol::Asesor);
    $est = adeEstudiante(adeGrupo());

    adeComoAdmin()->deleteJson(URL_ADE."/{$ajeno->id}")->assertNotFound();
    adeComoAdmin()->deleteJson(URL_ADE."/{$asesor->id}")->assertNotFound();

    adeComoAdmin()->deleteJson(URL_ADE."/{$est->id}")->assertNoContent();
    adeComoAdmin()->deleteJson(URL_ADE."/{$est->id}")->assertNotFound();
});