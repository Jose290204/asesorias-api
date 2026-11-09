<?php

use App\Enums\AccionBitacora;
use App\Enums\EstatusAsesoria;
use App\Enums\EstatusSolicitud;
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

const URL_ASD = '/api/v1/admin/solicitudes';

function asdToken(Usuario $u): string
{
    return $u->createToken('prueba')->plainTextToken;
}

function asdUsuario(Rol $rol, string $siglas = 'FIC'): Usuario
{
    return Usuario::factory()->conRol($rol)->create(['facultad_id' => UsuarioFactory::facultad($siglas)->id]);
}

function asdComoAdmin(?Usuario $admin = null)
{
    app('auth')->forgetGuards();

    return test()->withToken(asdToken($admin ?? asdUsuario(Rol::Admin)));
}

function asdBase(int $facultadId): array
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
    $lic = Licenciatura::firstOrCreate(['facultad_id' => $facultadId, 'nombre' => 'LIC ASD']);
    $grupo = Grupo::firstOrCreate(['licenciatura_id' => $lic->id, 'nombre' => '3-1'], ['anio' => 3]);
    $materia = Materia::firstOrCreate(['licenciatura_id' => $lic->id, 'nombre' => 'Estructura de Datos'], ['semestre' => 1]);

    return [$ciclo, $grupo, $materia];
}

function asdSolicitud(Usuario $est, Usuario $ase, EstatusSolicitud $estatus = EstatusSolicitud::Pendiente, array $extra = []): Solicitud
{
    [$ciclo, $grupo, $materia] = asdBase($est->facultad_id);

    return Solicitud::create(array_merge([
        'facultad_id' => $est->facultad_id,
        'ciclo_escolar_id' => $ciclo->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $est->id,
        'asesor_id' => $ase->id,
        'materia_id' => $materia->id,
        'horario_id' => Horario::firstOrCreate(['etiqueta' => '7:00-8:00 AM'])->id,
        'fecha_inicio' => Carbon::now()->next(Carbon::MONDAY)->toDateString(),
        'modalidad' => Modalidad::Virtual,
        'razon' => RazonAsesoria::Parcial,
        'estatus' => $estatus,
    ], $extra));
}

// ---------- Acceso ----------

it('asd: sin token recibe 401', function () {
    $this->getJson(URL_ASD)->assertUnauthorized();
});

it('asd: asesor y estudiante reciben 403', function () {
    foreach ([Rol::Asesor, Rol::Estudiante] as $rol) {
        $this->app['auth']->forgetGuards();
        $this->withToken(asdToken(asdUsuario($rol)))->getJson(URL_ASD)->assertForbidden();
    }
});

it('asd: el super admin sin X-Facultad-Id recibe 422', function () {
    $this->withToken(asdToken(Usuario::factory()->conRol(Rol::SuperAdmin)->create()))
        ->getJson(URL_ASD)->assertStatus(422);
});

// ---------- Listado ----------

it('asd: lista solo las solicitudes de su facultad', function () {
    $est = asdUsuario(Rol::Estudiante);
    $ase = asdUsuario(Rol::Asesor);
    asdSolicitud($est, $ase);

    $estO = asdUsuario(Rol::Estudiante, 'OTR');
    $aseO = asdUsuario(Rol::Asesor, 'OTR');
    asdSolicitud($estO, $aseO);

    asdComoAdmin()->getJson(URL_ASD)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.estudiante.id', $est->id);
});

it('asd: filtra por estatus y por asesor', function () {
    $est = asdUsuario(Rol::Estudiante);
    $a1 = asdUsuario(Rol::Asesor);
    $a2 = asdUsuario(Rol::Asesor);
    asdSolicitud($est, $a1);
    asdSolicitud($est, $a2, EstatusSolicitud::Rechazada, ['motivo_rechazo' => 'x']);

    asdComoAdmin()->getJson(URL_ASD.'?estatus=rechazada')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.asesor.id', $a2->id);

    asdComoAdmin()->getJson(URL_ASD.'?asesor_id='.$a1->id)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.asesor.id', $a1->id);
});

it('asd: busca por nombre del estudiante o del asesor', function () {
    $est = Usuario::factory()->conRol(Rol::Estudiante)->create(['nombre' => 'Zacarías', 'apellido_paterno' => 'Quiroz']);
    $ase = Usuario::factory()->conRol(Rol::Asesor)->create(['nombre' => 'Berenice', 'apellido_paterno' => 'Lugo']);
    asdSolicitud($est, $ase);
    asdSolicitud(asdUsuario(Rol::Estudiante), asdUsuario(Rol::Asesor));

    asdComoAdmin()->getJson(URL_ASD.'?buscar=Zacarías')->assertOk()->assertJsonCount(1, 'data');
    asdComoAdmin()->getJson(URL_ASD.'?buscar=Berenice')->assertOk()->assertJsonCount(1, 'data');
});

it('asd: pagina de 10 en 10', function () {
    $est = asdUsuario(Rol::Estudiante);
    $ase = asdUsuario(Rol::Asesor);
    foreach (range(1, 12) as $i) {
        asdSolicitud($est, $ase, EstatusSolicitud::Cancelada);
    }

    asdComoAdmin()->getJson(URL_ASD)
        ->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.last_page', 2);
});

it('asd: un estatus inválido da 422', function () {
    asdComoAdmin()->getJson(URL_ASD.'?estatus=loquesea')->assertStatus(422);
});

// ---------- Aceptar ----------

it('asd: el admin acepta en lugar del asesor: crea la asesoría y rechaza a las competidoras', function () {
    $ase = asdUsuario(Rol::Asesor);
    $est = asdUsuario(Rol::Estudiante);
    $comp = asdUsuario(Rol::Estudiante);
    $s = asdSolicitud($est, $ase);
    $otra = asdSolicitud($comp, $ase);

    asdComoAdmin()->postJson("/api/v1/admin/solicitudes/{$s->id}/aceptar")
        ->assertOk()->assertJsonPath('data.estatus', 'aceptada');

    expect(Asesoria::where('solicitud_id', $s->id)->where('estatus', EstatusAsesoria::EnCurso)->exists())->toBeTrue();
    expect($otra->fresh()->estatus)->toBe(EstatusSolicitud::Rechazada);
});

it('asd: aceptar una solicitud ya respondida da 409 solicitud_no_pendiente', function () {
    $s = asdSolicitud(asdUsuario(Rol::Estudiante), asdUsuario(Rol::Asesor), EstatusSolicitud::Cancelada);

    asdComoAdmin()->postJson("/api/v1/admin/solicitudes/{$s->id}/aceptar")
        ->assertStatus(409)->assertJsonPath('codigo', 'solicitud_no_pendiente');
});

it('asd: no se puede aceptar una solicitud de otra facultad (404)', function () {
    $s = asdSolicitud(asdUsuario(Rol::Estudiante, 'OTR'), asdUsuario(Rol::Asesor, 'OTR'));

    asdComoAdmin(asdUsuario(Rol::Admin))->postJson("/api/v1/admin/solicitudes/{$s->id}/aceptar")
        ->assertNotFound();
});

it('asd: el asesor sigue sin poder aceptar solicitudes ajenas', function () {
    $s = asdSolicitud(asdUsuario(Rol::Estudiante), asdUsuario(Rol::Asesor));

    $this->withToken(asdToken(asdUsuario(Rol::Asesor)))
        ->postJson("/api/v1/asesor/solicitudes/{$s->id}/aceptar")->assertForbidden();
});

// ---------- Rechazar ----------

it('asd: el admin rechaza con motivo', function () {
    $s = asdSolicitud(asdUsuario(Rol::Estudiante), asdUsuario(Rol::Asesor));

    asdComoAdmin()->postJson("/api/v1/admin/solicitudes/{$s->id}/rechazar", ['motivo_rechazo' => 'Sin cupo'])
        ->assertOk()->assertJsonPath('data.estatus', 'rechazada')->assertJsonPath('data.motivo_rechazo', 'Sin cupo');
});

it('asd: rechazar sin motivo da 422', function () {
    $s = asdSolicitud(asdUsuario(Rol::Estudiante), asdUsuario(Rol::Asesor));

    asdComoAdmin()->postJson("/api/v1/admin/solicitudes/{$s->id}/rechazar", [])->assertStatus(422);
});

it('asd: rechazar una solicitud ya respondida da 409', function () {
    $s = asdSolicitud(asdUsuario(Rol::Estudiante), asdUsuario(Rol::Asesor), EstatusSolicitud::Aceptada);

    asdComoAdmin()->postJson("/api/v1/admin/solicitudes/{$s->id}/rechazar", ['motivo_rechazo' => 'x'])
        ->assertStatus(409)->assertJsonPath('codigo', 'solicitud_no_pendiente');
});

it('asd: la bitácora registra al admin como autor', function () {
    $admin = asdUsuario(Rol::Admin);
    $s = asdSolicitud(asdUsuario(Rol::Estudiante), asdUsuario(Rol::Asesor));

    asdComoAdmin($admin)->postJson("/api/v1/admin/solicitudes/{$s->id}/rechazar", ['motivo_rechazo' => 'x'])->assertOk();

    expect(Bitacora::where('accion', AccionBitacora::SolicitudRechazada->value)
        ->where('usuario_id', $admin->id)->where('entidad_id', $s->id)->exists())->toBeTrue();
});