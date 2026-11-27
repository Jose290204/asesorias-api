<?php

// Guardar como: tests/Feature/AceptarSolicitudTest.php
// Autocontenido (prefijo "asol"): las funciones y constantes de los archivos de Pest son globales.

use App\Enums\EstatusAsesoria;
use App\Enums\EstatusSolicitud;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Models\Asesoria;
use App\Models\CicloEscolar;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Solicitud;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;

const URL_ASOL_ASESOR = '/api/v1/asesor/solicitudes';
const URL_ASOL_ESTUDIANTE = '/api/v1/estudiante/solicitudes';
const ASOL_MOTIVO_CUPO = 'Este horario ya fue tomado por otra solicitud aceptada.';


// ---------- Ayudantes ----------

function asolToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function asolEstudiante(): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create();
}

function asolAsesor(): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create();
}

function asolHorario(string $etiqueta = '7:00-8:00 AM'): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Próximo lunes (o el día dado) a partir de hoy, más $semanas semanas
function asolFecha(int $diaSemana = Carbon::MONDAY, int $semanas = 0): string
{
    return Carbon::now()->next($diaSemana)->addWeeks($semanas)->toDateString();
}

// Datos base compartidos: ciclo, licenciatura, grupo y materia
function asolBase(): array
{
    $facultad = UsuarioFactory::facultad();

    $ciclo = CicloEscolar::first() ?? CicloEscolar::create([
        'facultad_id' => $facultad->id,
        'nombre' => '2026-2027 1',
        'periodo' => 1,
        'fecha_inicio' => now()->subMonth()->toDateString(),
        'fecha_fin' => now()->addYear()->toDateString(),
        'vigente' => true,
    ]);

    $lic = Licenciatura::firstOrCreate(['facultad_id' => $facultad->id, 'nombre' => 'LICENCIATURA PRUEBA']);
    $grupo = Grupo::firstOrCreate(['licenciatura_id' => $lic->id, 'nombre' => '3-1'], ['anio' => 3]);
    $materia = Materia::firstOrCreate(['licenciatura_id' => $lic->id, 'nombre' => 'Estructura de Datos'], ['semestre' => 1]);

    return [$ciclo, $grupo, $materia];
}

// Inserta una solicitud directo en la BD. facultad_id va explícito porque al preparar datos
// no hay usuario logueado y el trait PerteneceAFacultad no lo rellena.
// $extra permite cambiar horario_id, fecha_inicio, etc.
function asolSolicitud(Usuario $estudiante, Usuario $asesor, EstatusSolicitud $estatus = EstatusSolicitud::Pendiente, array $extra = []): Solicitud
{
    [$ciclo, $grupo, $materia] = asolBase();

    return Solicitud::create(array_merge([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => $ciclo->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => asolHorario()->id,
        'fecha_inicio' => asolFecha(),
        'modalidad' => Modalidad::Virtual,
        'razon' => RazonAsesoria::Parcial,
        'estatus' => $estatus,
    ], $extra));
}

// Inserta una asesoría directo en la BD (para simular un horario ya ocupado)
function asolAsesoria(Usuario $estudiante, Usuario $asesor, EstatusAsesoria $estatus = EstatusAsesoria::EnCurso, array $extra = []): Asesoria
{
    [$ciclo, $grupo, $materia] = asolBase();

    return Asesoria::create(array_merge([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => $ciclo->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => asolHorario()->id,
        'modalidad' => Modalidad::Virtual,
        'razon' => RazonAsesoria::Parcial,
        'estatus' => $estatus,
        'fecha_inicio' => asolFecha(Carbon::MONDAY, -1),
    ], $extra));
}

function asolUrl(Solicitud|int $solicitud): string
{
    $id = $solicitud instanceof Solicitud ? $solicitud->id : $solicitud;

    return URL_ASOL_ASESOR.'/'.$id.'/aceptar';
}


// ---------- Acceso ----------

it('aceptar solicitud devuelve 401 sin sesión', function () {
    $this->postJson(asolUrl(1))->assertUnauthorized();
});

it('aceptar solicitud rechaza con 403 a un estudiante puro', function () {
    $this->withToken(asolToken(asolEstudiante()))
        ->postJson(asolUrl(1))
        ->assertForbidden();
});

it('aceptar solicitud bloquea con 403 a un asesor con cuenta no activa', function () {
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);

    $this->withToken(asolToken($asesor))
        ->postJson(asolUrl(1))
        ->assertForbidden();
});


// ---------- Caso feliz ----------

it('aceptar solicitud la pasa a aceptada, crea la asesoría en curso y responde 200', function () {
    $asesor = asolAsesor();
    $estudiante = asolEstudiante();
    $solicitud = asolSolicitud($estudiante, $asesor);

    $r = $this->withToken(asolToken($asesor))
        ->postJson(asolUrl($solicitud))
        ->assertOk()
        ->assertJsonPath('data.id', $solicitud->id)
        ->assertJsonPath('data.estatus', 'aceptada');

    expect($solicitud->fresh()->respondida_en)->not->toBeNull();

    $this->assertDatabaseHas('asesorias', [
        'id' => $r->json('data.asesoria.id'),
        'solicitud_id' => $solicitud->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $solicitud->materia_id,
        'horario_id' => $solicitud->horario_id,
        'modalidad' => 'virtual',
        'razon' => 'parcial',
        'estatus' => EstatusAsesoria::EnCurso->value,
    ]);

    expect(Asesoria::count())->toBe(1);
    expect(Asesoria::first()->fecha_inicio->toDateString())->toBe($solicitud->fecha_inicio->toDateString());
});

it('aceptar solicitud: un asesor par puede aceptar la que le pidieron como asesor', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $solicitud = asolSolicitud(asolEstudiante(), $par);

    $this->withToken(asolToken($par))
        ->postJson(asolUrl($solicitud))
        ->assertOk()
        ->assertJsonPath('data.estatus', 'aceptada');
});


// ---------- Rechazo automático de competidoras ----------

it('aceptar solicitud rechaza las pendientes del mismo asesor, día de la semana y horario', function () {
    $asesor = asolAsesor();
    $aceptada = asolSolicitud(asolEstudiante(), $asesor);
    // Mismo asesor, mismo horario, otro lunes (misma día de la semana)
    $competidora = asolSolicitud(asolEstudiante(), $asesor, EstatusSolicitud::Pendiente, ['fecha_inicio' => asolFecha(Carbon::MONDAY, 1)]);

    $this->withToken(asolToken($asesor))->postJson(asolUrl($aceptada))->assertOk();

    $competidora->refresh();
    expect($competidora->estatus)->toBe(EstatusSolicitud::Rechazada);
    expect($competidora->motivo_rechazo)->toBe(ASOL_MOTIVO_CUPO);
    expect($competidora->respondida_en)->not->toBeNull();
});

it('aceptar solicitud rechaza también a otro estudiante que pidió el mismo cupo la misma fecha', function () {
    $asesor = asolAsesor();
    $aceptada = asolSolicitud(asolEstudiante(), $asesor);
    $competidora = asolSolicitud(asolEstudiante(), $asesor);

    $this->withToken(asolToken($asesor))->postJson(asolUrl($aceptada))->assertOk();

    expect($competidora->fresh()->estatus)->toBe(EstatusSolicitud::Rechazada);
});

it('aceptar solicitud no toca solicitudes de otro horario, otro día, otro asesor ni ya cerradas', function () {
    $asesor = asolAsesor();
    $otroAsesor = asolAsesor();
    $aceptada = asolSolicitud(asolEstudiante(), $asesor);

    $otroHorario = asolSolicitud(asolEstudiante(), $asesor, EstatusSolicitud::Pendiente, ['horario_id' => asolHorario('8:00-9:00 AM')->id]);
    $otroDia = asolSolicitud(asolEstudiante(), $asesor, EstatusSolicitud::Pendiente, ['fecha_inicio' => asolFecha(Carbon::TUESDAY)]);
    $deOtroAsesor = asolSolicitud(asolEstudiante(), $otroAsesor);
    $cancelada = asolSolicitud(asolEstudiante(), $asesor, EstatusSolicitud::Cancelada);

    $this->withToken(asolToken($asesor))->postJson(asolUrl($aceptada))->assertOk();

    expect($otroHorario->fresh()->estatus)->toBe(EstatusSolicitud::Pendiente);
    expect($otroDia->fresh()->estatus)->toBe(EstatusSolicitud::Pendiente);
    expect($deOtroAsesor->fresh()->estatus)->toBe(EstatusSolicitud::Pendiente);
    expect($cancelada->fresh()->estatus)->toBe(EstatusSolicitud::Cancelada);
    expect($cancelada->fresh()->motivo_rechazo)->toBeNull();
});


// ---------- Horario ocupado (409) ----------

it('aceptar solicitud responde 409 si ya hay una asesoría en curso ese día y horario, y no cambia nada', function () {
    $asesor = asolAsesor();
    // Asesoría en curso con fecha_inicio en otro lunes: mismo día de la semana y mismo horario
    asolAsesoria(asolEstudiante(), $asesor);
    $solicitud = asolSolicitud(asolEstudiante(), $asesor);

    $this->withToken(asolToken($asesor))
        ->postJson(asolUrl($solicitud))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'horario_ocupado');

    expect($solicitud->fresh()->estatus)->toBe(EstatusSolicitud::Pendiente);
    expect(Asesoria::count())->toBe(1);
});

it('aceptar solicitud permite aceptar si la asesoría del mismo cupo ya está completada', function () {
    $asesor = asolAsesor();
    asolAsesoria(asolEstudiante(), $asesor, EstatusAsesoria::Completada);
    $solicitud = asolSolicitud(asolEstudiante(), $asesor);

    $this->withToken(asolToken($asesor))->postJson(asolUrl($solicitud))->assertOk();
});

it('aceptar solicitud permite aceptar si la asesoría del mismo cupo fue cancelada', function () {
    $asesor = asolAsesor();
    asolAsesoria(asolEstudiante(), $asesor, EstatusAsesoria::Cancelada);
    $solicitud = asolSolicitud(asolEstudiante(), $asesor);

    $this->withToken(asolToken($asesor))->postJson(asolUrl($solicitud))->assertOk();
});

it('aceptar solicitud permite aceptar si la asesoría en curso es de otro día de la semana', function () {
    $asesor = asolAsesor();
    asolAsesoria(asolEstudiante(), $asesor, EstatusAsesoria::EnCurso, ['fecha_inicio' => asolFecha(Carbon::TUESDAY, -1)]);
    $solicitud = asolSolicitud(asolEstudiante(), $asesor);

    $this->withToken(asolToken($asesor))->postJson(asolUrl($solicitud))->assertOk();
});

it('aceptar solicitud permite aceptar si la asesoría en curso es de otro horario', function () {
    $asesor = asolAsesor();
    asolAsesoria(asolEstudiante(), $asesor, EstatusAsesoria::EnCurso, ['horario_id' => asolHorario('8:00-9:00 AM')->id]);
    $solicitud = asolSolicitud(asolEstudiante(), $asesor);

    $this->withToken(asolToken($asesor))->postJson(asolUrl($solicitud))->assertOk();
});

it('aceptar solicitud permite aceptar si la asesoría en curso del mismo cupo es de otro asesor', function () {
    $asesor = asolAsesor();
    asolAsesoria(asolEstudiante(), asolAsesor());
    $solicitud = asolSolicitud(asolEstudiante(), $asesor);

    $this->withToken(asolToken($asesor))->postJson(asolUrl($solicitud))->assertOk();
});


// ---------- Permiso (403) y existencia (404) ----------

it('aceptar solicitud responde 403 si la solicitud es de otro asesor y no la toca', function () {
    $solicitud = asolSolicitud(asolEstudiante(), asolAsesor());

    $this->withToken(asolToken(asolAsesor()))
        ->postJson(asolUrl($solicitud))
        ->assertForbidden();

    expect($solicitud->fresh()->estatus)->toBe(EstatusSolicitud::Pendiente);
    expect(Asesoria::count())->toBe(0);
});

it('aceptar solicitud: un asesor par no puede aceptar la que pidió él como estudiante', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $solicitud = asolSolicitud($par, asolAsesor());

    $this->withToken(asolToken($par))
        ->postJson(asolUrl($solicitud))
        ->assertForbidden();

    expect($solicitud->fresh()->estatus)->toBe(EstatusSolicitud::Pendiente);
});

it('aceptar solicitud responde 404 si no existe', function () {
    $this->withToken(asolToken(asolAsesor()))
        ->postJson(asolUrl(999999))
        ->assertNotFound();
});


// ---------- Estado (409): solo se aceptan las pendientes ----------

it('aceptar solicitud responde 409 la segunda vez y no crea otra asesoría', function () {
    $asesor = asolAsesor();
    $solicitud = asolSolicitud(asolEstudiante(), $asesor);
    $token = asolToken($asesor);

    $this->withToken($token)->postJson(asolUrl($solicitud))->assertOk();

    // Sanctum recuerda al primer usuario dentro de la misma prueba; se limpia antes de la segunda petición
    $this->app['auth']->forgetGuards();
    $this->withToken($token)
        ->postJson(asolUrl($solicitud))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'solicitud_no_pendiente');

    expect(Asesoria::count())->toBe(1);
});

it('aceptar solicitud responde 409 si ya fue rechazada y no crea asesoría', function () {
    $asesor = asolAsesor();
    $solicitud = asolSolicitud(asolEstudiante(), $asesor, EstatusSolicitud::Rechazada);

    $this->withToken(asolToken($asesor))
        ->postJson(asolUrl($solicitud))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'solicitud_no_pendiente');

    expect($solicitud->fresh()->estatus)->toBe(EstatusSolicitud::Rechazada);
    expect(Asesoria::count())->toBe(0);
});

it('aceptar solicitud responde 409 si el estudiante ya la canceló y no crea asesoría', function () {
    $asesor = asolAsesor();
    $solicitud = asolSolicitud(asolEstudiante(), $asesor, EstatusSolicitud::Cancelada);

    $this->withToken(asolToken($asesor))
        ->postJson(asolUrl($solicitud))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'solicitud_no_pendiente');

    expect($solicitud->fresh()->estatus)->toBe(EstatusSolicitud::Cancelada);
    expect(Asesoria::count())->toBe(0);
});

it('aceptar solicitud: dos solicitudes del mismo cupo no pueden aceptarse las dos', function () {
    $asesor = asolAsesor();
    $primera = asolSolicitud(asolEstudiante(), $asesor);
    $segunda = asolSolicitud(asolEstudiante(), $asesor);
    $token = asolToken($asesor);

    $this->withToken($token)->postJson(asolUrl($primera))->assertOk();

    // La segunda ya fue rechazada automáticamente, así que no se puede aceptar
    $this->app['auth']->forgetGuards();
    $this->withToken($token)
        ->postJson(asolUrl($segunda))
        ->assertStatus(409);

    expect(Asesoria::count())->toBe(1);
});


// ---------- Efecto en las listas ----------

it('aceptar solicitud: el estudiante la ve aceptada y la competidora rechazada con su motivo', function () {
    $asesor = asolAsesor();
    $estudiante = asolEstudiante();
    $otro = asolEstudiante();
    $aceptada = asolSolicitud($estudiante, $asesor);
    $competidora = asolSolicitud($otro, $asesor);

    $this->withToken(asolToken($asesor))->postJson(asolUrl($aceptada))->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withToken(asolToken($estudiante))
        ->getJson(URL_ASOL_ESTUDIANTE.'?estatus=aceptada')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $aceptada->id);

    $this->app['auth']->forgetGuards();
    $this->withToken(asolToken($otro))
        ->getJson(URL_ASOL_ESTUDIANTE.'?estatus=rechazada')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $competidora->id)
        ->assertJsonPath('data.0.motivo_rechazo', ASOL_MOTIVO_CUPO);

    // Y el asesor ya no tiene pendientes
    $this->app['auth']->forgetGuards();
    $this->withToken(asolToken($asesor))
        ->getJson(URL_ASOL_ASESOR.'?estatus=pendiente')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});