<?php

// Guardar como: tests/Feature/RechazarSolicitudTest.php
// Autocontenido (prefijo "rsol"): las funciones y constantes de los archivos de Pest son globales.

use App\Enums\EstatusSolicitud;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Models\CicloEscolar;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Solicitud;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;

const URL_RSOL_ASESOR = '/api/v1/asesor/solicitudes';
const URL_RSOL_ESTUDIANTE = '/api/v1/estudiante/solicitudes';
const RSOL_MOTIVO = 'Ese día no puedo atender';


// ---------- Ayudantes ----------

function rsolToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function rsolEstudiante(): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create();
}

function rsolAsesor(): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create();
}

// Inserta una solicitud directo en la BD. facultad_id va explícito porque al preparar datos
// no hay usuario logueado y el trait PerteneceAFacultad no lo rellena
function rsolInsertar(Usuario $estudiante, Usuario $asesor, EstatusSolicitud $estatus = EstatusSolicitud::Pendiente): Solicitud
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
    $horario = Horario::firstOrCreate(['etiqueta' => '7:00-8:00 AM']);

    return Solicitud::create([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => $ciclo->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => $horario->id,
        'fecha_inicio' => Carbon::now()->next(Carbon::MONDAY)->toDateString(),
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => $estatus,
    ]);
}

function rsolUrl(Solicitud|int $solicitud): string
{
    $id = $solicitud instanceof Solicitud ? $solicitud->id : $solicitud;

    return URL_RSOL_ASESOR.'/'.$id.'/rechazar';
}


// ---------- Acceso ----------

it('rechazar solicitud devuelve 401 sin sesión', function () {
    $this->postJson(rsolUrl(1), ['motivo_rechazo' => RSOL_MOTIVO])->assertUnauthorized();
});

it('rechazar solicitud rechaza con 403 a un estudiante puro', function () {
    $this->withToken(rsolToken(rsolEstudiante()))
        ->postJson(rsolUrl(1), ['motivo_rechazo' => RSOL_MOTIVO])
        ->assertForbidden();
});

it('rechazar solicitud bloquea con 403 a un asesor con cuenta no activa', function () {
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);

    $this->withToken(rsolToken($asesor))
        ->postJson(rsolUrl(1), ['motivo_rechazo' => RSOL_MOTIVO])
        ->assertForbidden();
});


// ---------- Caso feliz ----------

it('rechazar solicitud pasa la pendiente a rechazada, guarda el motivo y responde 200', function () {
    $asesor = rsolAsesor();
    $solicitud = rsolInsertar(rsolEstudiante(), $asesor);

    $this->withToken(rsolToken($asesor))
        ->postJson(rsolUrl($solicitud), ['motivo_rechazo' => RSOL_MOTIVO])
        ->assertOk()
        ->assertJsonPath('data.id', $solicitud->id)
        ->assertJsonPath('data.estatus', 'rechazada')
        ->assertJsonPath('data.motivo_rechazo', RSOL_MOTIVO);

    $this->assertDatabaseHas('solicitudes', [
        'id' => $solicitud->id,
        'estatus' => EstatusSolicitud::Rechazada->value,
        'motivo_rechazo' => RSOL_MOTIVO,
    ]);
    expect($solicitud->fresh()->respondida_en)->not->toBeNull();
});

it('rechazar solicitud solo cambia la indicada y deja las demás pendientes', function () {
    $asesor = rsolAsesor();
    $estudiante = rsolEstudiante();
    $rechazar = rsolInsertar($estudiante, $asesor);
    $queda = rsolInsertar($estudiante, $asesor);

    $this->withToken(rsolToken($asesor))
        ->postJson(rsolUrl($rechazar), ['motivo_rechazo' => RSOL_MOTIVO])
        ->assertOk();

    expect($rechazar->fresh()->estatus)->toBe(EstatusSolicitud::Rechazada);
    expect($queda->fresh()->estatus)->toBe(EstatusSolicitud::Pendiente);
    expect($queda->fresh()->motivo_rechazo)->toBeNull();
});

it('rechazar solicitud: un asesor par puede rechazar la que le pidieron como asesor', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $solicitud = rsolInsertar(rsolEstudiante(), $par);

    $this->withToken(rsolToken($par))
        ->postJson(rsolUrl($solicitud), ['motivo_rechazo' => RSOL_MOTIVO])
        ->assertOk()
        ->assertJsonPath('data.estatus', 'rechazada');
});


// ---------- Permiso (403) y existencia (404) ----------

it('rechazar solicitud responde 403 si la solicitud es de otro asesor y no la toca', function () {
    $solicitud = rsolInsertar(rsolEstudiante(), rsolAsesor());

    $this->withToken(rsolToken(rsolAsesor()))
        ->postJson(rsolUrl($solicitud), ['motivo_rechazo' => RSOL_MOTIVO])
        ->assertForbidden();

    expect($solicitud->fresh()->estatus)->toBe(EstatusSolicitud::Pendiente);
});

it('rechazar solicitud responde 403 y no 422 si es de otro asesor y el body viene vacío', function () {
    $solicitud = rsolInsertar(rsolEstudiante(), rsolAsesor());

    // El permiso se revisa antes que la validación: no se filtran errores a quien no es el asesor
    $this->withToken(rsolToken(rsolAsesor()))
        ->postJson(rsolUrl($solicitud), [])
        ->assertForbidden();
});

it('rechazar solicitud: un asesor par no puede rechazar la que pidió él como estudiante', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $solicitud = rsolInsertar($par, rsolAsesor());

    $this->withToken(rsolToken($par))
        ->postJson(rsolUrl($solicitud), ['motivo_rechazo' => RSOL_MOTIVO])
        ->assertForbidden();

    expect($solicitud->fresh()->estatus)->toBe(EstatusSolicitud::Pendiente);
});

it('rechazar solicitud responde 404 si no existe', function () {
    $this->withToken(rsolToken(rsolAsesor()))
        ->postJson(rsolUrl(999999), ['motivo_rechazo' => RSOL_MOTIVO])
        ->assertNotFound();
});


// ---------- Validación (422) ----------

it('rechazar solicitud responde 422 si falta el motivo y no cambia nada', function () {
    $asesor = rsolAsesor();
    $solicitud = rsolInsertar(rsolEstudiante(), $asesor);

    $this->withToken(rsolToken($asesor))
        ->postJson(rsolUrl($solicitud), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo_rechazo');

    expect($solicitud->fresh()->estatus)->toBe(EstatusSolicitud::Pendiente);
});

it('rechazar solicitud responde 422 si el motivo viene vacío', function () {
    $asesor = rsolAsesor();
    $solicitud = rsolInsertar(rsolEstudiante(), $asesor);

    $this->withToken(rsolToken($asesor))
        ->postJson(rsolUrl($solicitud), ['motivo_rechazo' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo_rechazo');
});

it('rechazar solicitud responde 422 si el motivo pasa de 500 caracteres', function () {
    $asesor = rsolAsesor();
    $solicitud = rsolInsertar(rsolEstudiante(), $asesor);

    $this->withToken(rsolToken($asesor))
        ->postJson(rsolUrl($solicitud), ['motivo_rechazo' => str_repeat('a', 501)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo_rechazo');
});

it('rechazar solicitud acepta un motivo de exactamente 500 caracteres', function () {
    $asesor = rsolAsesor();
    $solicitud = rsolInsertar(rsolEstudiante(), $asesor);

    $this->withToken(rsolToken($asesor))
        ->postJson(rsolUrl($solicitud), ['motivo_rechazo' => str_repeat('a', 500)])
        ->assertOk();
});


// ---------- Estado (409): solo se rechazan las pendientes ----------

it('rechazar solicitud responde 409 si ya estaba rechazada y no cambia el motivo', function () {
    $asesor = rsolAsesor();
    $solicitud = rsolInsertar(rsolEstudiante(), $asesor);
    $token = rsolToken($asesor);

    $this->withToken($token)->postJson(rsolUrl($solicitud), ['motivo_rechazo' => RSOL_MOTIVO])->assertOk();

    // Sanctum recuerda al primer usuario dentro de la misma prueba; se limpia antes de la segunda petición
    $this->app['auth']->forgetGuards();
    $this->withToken($token)
        ->postJson(rsolUrl($solicitud), ['motivo_rechazo' => 'Otro motivo'])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'solicitud_no_pendiente');

    expect($solicitud->fresh()->motivo_rechazo)->toBe(RSOL_MOTIVO);
});

it('rechazar solicitud responde 409 si ya fue aceptada y no cambia su estatus', function () {
    $asesor = rsolAsesor();
    $solicitud = rsolInsertar(rsolEstudiante(), $asesor, EstatusSolicitud::Aceptada);

    $this->withToken(rsolToken($asesor))
        ->postJson(rsolUrl($solicitud), ['motivo_rechazo' => RSOL_MOTIVO])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'solicitud_no_pendiente');

    expect($solicitud->fresh()->estatus)->toBe(EstatusSolicitud::Aceptada);
});

it('rechazar solicitud responde 409 si el estudiante ya la canceló', function () {
    $asesor = rsolAsesor();
    $solicitud = rsolInsertar(rsolEstudiante(), $asesor, EstatusSolicitud::Cancelada);

    $this->withToken(rsolToken($asesor))
        ->postJson(rsolUrl($solicitud), ['motivo_rechazo' => RSOL_MOTIVO])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'solicitud_no_pendiente');

    expect($solicitud->fresh()->estatus)->toBe(EstatusSolicitud::Cancelada);
});


// ---------- Efecto en las listas ----------

it('rechazar solicitud: el estudiante ve el motivo en su lista y el asesor ya no la ve como pendiente', function () {
    $asesor = rsolAsesor();
    $estudiante = rsolEstudiante();
    $solicitud = rsolInsertar($estudiante, $asesor);

    $this->withToken(rsolToken($asesor))
        ->postJson(rsolUrl($solicitud), ['motivo_rechazo' => RSOL_MOTIVO])
        ->assertOk();

    // El asesor ya no la tiene pendiente
    $this->app['auth']->forgetGuards();
    $this->withToken(rsolToken($asesor))
        ->getJson(URL_RSOL_ASESOR.'?estatus=pendiente')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // El estudiante la ve rechazada con el motivo (su "Notas del asesor")
    $this->app['auth']->forgetGuards();
    $this->withToken(rsolToken($estudiante))
        ->getJson(URL_RSOL_ESTUDIANTE.'?estatus=rechazada')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $solicitud->id)
        ->assertJsonPath('data.0.motivo_rechazo', RSOL_MOTIVO);
});