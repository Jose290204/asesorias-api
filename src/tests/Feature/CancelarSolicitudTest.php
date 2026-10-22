<?php

// Guardar como: tests/Feature/CancelarSolicitudTest.php
// Reemplaza a EliminarSolicitudTest.php (la solicitud ya no se borra: pasa a "cancelada").
// Autocontenido (prefijo "csol"): las funciones y constantes de los archivos de Pest son globales.

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

const URL_CSOL = '/api/v1/estudiante/solicitudes';


// ---------- Ayudantes ----------

function csolToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function csolEstudiante(): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create();
}

function csolAsesor(): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create();
}

// Inserta una solicitud directo en la BD. facultad_id va explícito porque al preparar datos
// no hay usuario logueado y el trait PerteneceAFacultad no lo rellena
function csolInsertar(Usuario $estudiante, Usuario $asesor, EstatusSolicitud $estatus = EstatusSolicitud::Pendiente): Solicitud
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

function csolUrl(Solicitud|int $solicitud): string
{
    $id = $solicitud instanceof Solicitud ? $solicitud->id : $solicitud;

    return URL_CSOL.'/'.$id.'/cancelar';
}


// ---------- Acceso ----------

it('cancelar solicitud devuelve 401 sin sesión', function () {
    $this->postJson(csolUrl(1))->assertUnauthorized();
});

it('cancelar solicitud rechaza con 403 a un asesor puro', function () {
    $this->withToken(csolToken(csolAsesor()))
        ->postJson(csolUrl(1))
        ->assertForbidden();
});

it('cancelar solicitud bloquea con 403 a un estudiante con cuenta no activa', function () {
    $estudiante = Usuario::factory()->pendiente()->conRol(Rol::Estudiante)->create(['password' => 'secreto123']);

    $this->withToken(csolToken($estudiante))
        ->postJson(csolUrl(1))
        ->assertForbidden();
});


// ---------- Caso feliz ----------

it('cancelar solicitud pasa la pendiente propia a cancelada, no la borra y responde 200', function () {
    $estudiante = csolEstudiante();
    $solicitud = csolInsertar($estudiante, csolAsesor());

    $this->withToken(csolToken($estudiante))
        ->postJson(csolUrl($solicitud))
        ->assertOk()
        ->assertJsonPath('data.id', $solicitud->id)
        ->assertJsonPath('data.estatus', 'cancelada');

    // La fila sigue existiendo, con el estatus nuevo y la fecha de cierre
    $this->assertDatabaseHas('solicitudes', [
        'id' => $solicitud->id,
        'estatus' => EstatusSolicitud::Cancelada->value,
    ]);
    expect($solicitud->fresh()->respondida_en)->not->toBeNull();
});

it('cancelar solicitud solo cambia la indicada y deja las demás pendientes', function () {
    $estudiante = csolEstudiante();
    $asesor = csolAsesor();
    $cancelar = csolInsertar($estudiante, $asesor);
    $queda = csolInsertar($estudiante, $asesor);

    $this->withToken(csolToken($estudiante))
        ->postJson(csolUrl($cancelar))
        ->assertOk();

    expect($cancelar->fresh()->estatus)->toBe(EstatusSolicitud::Cancelada);
    expect($queda->fresh()->estatus)->toBe(EstatusSolicitud::Pendiente);
});

it('cancelar solicitud: un asesor par puede cancelar la que pidió como estudiante', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $solicitud = csolInsertar($par, csolAsesor());

    $this->withToken(csolToken($par))
        ->postJson(csolUrl($solicitud))
        ->assertOk()
        ->assertJsonPath('data.estatus', 'cancelada');
});


// ---------- Permiso (403) y existencia (404) ----------

it('cancelar solicitud responde 403 si la solicitud es de otro estudiante y no la toca', function () {
    $solicitud = csolInsertar(csolEstudiante(), csolAsesor());

    $this->withToken(csolToken(csolEstudiante()))
        ->postJson(csolUrl($solicitud))
        ->assertForbidden();

    expect($solicitud->fresh()->estatus)->toBe(EstatusSolicitud::Pendiente);
});

it('cancelar solicitud responde 404 si no existe', function () {
    $this->withToken(csolToken(csolEstudiante()))
        ->postJson(csolUrl(999999))
        ->assertNotFound();
});


// ---------- Estado (409): solo se cancelan las pendientes ----------

it('cancelar solicitud responde 409 si ya estaba cancelada', function () {
    $estudiante = csolEstudiante();
    $solicitud = csolInsertar($estudiante, csolAsesor());
    $token = csolToken($estudiante);

    $this->withToken($token)->postJson(csolUrl($solicitud))->assertOk();

    // Sanctum recuerda al primer usuario dentro de la misma prueba; se limpia antes de la segunda petición
    $this->app['auth']->forgetGuards();
    $this->withToken($token)
        ->postJson(csolUrl($solicitud))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'solicitud_no_pendiente');
});

it('cancelar solicitud responde 409 si ya fue aceptada y no cambia su estatus', function () {
    $estudiante = csolEstudiante();
    $solicitud = csolInsertar($estudiante, csolAsesor(), EstatusSolicitud::Aceptada);

    $this->withToken(csolToken($estudiante))
        ->postJson(csolUrl($solicitud))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'solicitud_no_pendiente');

    expect($solicitud->fresh()->estatus)->toBe(EstatusSolicitud::Aceptada);
});

it('cancelar solicitud responde 409 si ya fue rechazada y no cambia su estatus', function () {
    $estudiante = csolEstudiante();
    $solicitud = csolInsertar($estudiante, csolAsesor(), EstatusSolicitud::Rechazada);

    $this->withToken(csolToken($estudiante))
        ->postJson(csolUrl($solicitud))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'solicitud_no_pendiente');

    expect($solicitud->fresh()->estatus)->toBe(EstatusSolicitud::Rechazada);
});


// ---------- Efecto en las listas ----------

it('cancelar solicitud: la cancelada sale con ?estatus=cancelada y deja de salir como pendiente', function () {
    $estudiante = csolEstudiante();
    $solicitud = csolInsertar($estudiante, csolAsesor());
    $token = csolToken($estudiante);

    $this->withToken($token)->postJson(csolUrl($solicitud))->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson(URL_CSOL.'?estatus=cancelada')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $solicitud->id);

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson(URL_CSOL.'?estatus=pendiente')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});