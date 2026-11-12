<?php

// Guardar como: tests/Feature/NotificacionesTest.php
// Autocontenido (prefijo "not"): las funciones y constantes de los archivos de Pest son globales.

use App\Enums\EstatusSolicitud;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Enums\TipoNotificacion;
use App\Models\CicloEscolar;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Solicitud;
use App\Models\Usuario;
use App\Notifications\SolicitudNotificacion;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

const URL_NOT = '/api/v1/notificaciones';


// ---------- Ayudantes ----------

function notToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function notUsuario(Rol $rol = Rol::Estudiante): Usuario
{
    return Usuario::factory()->conRol($rol)->create();
}

// notifyNow escribe en la BD en el momento, sin pasar por la cola
function notAviso(Usuario $usuario, TipoNotificacion $tipo = TipoNotificacion::SolicitudNueva): void
{
    $usuario->notifyNow(
        new SolicitudNotificacion($tipo, 1, 'Estructura de Datos', 'Ana Pérez', '10/11/2026'),
        ['database']
    );
}

function notSolicitud(Usuario $estudiante, Usuario $asesor, array $extra = []): Solicitud
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

    return Solicitud::create(array_merge([
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
        'estatus' => EstatusSolicitud::Pendiente,
    ], $extra));
}


// ---------- Acceso ----------

it('notificaciones devuelve 401 sin sesión', function () {
    $this->getJson(URL_NOT)->assertUnauthorized();
    $this->postJson(URL_NOT.'/leidas')->assertUnauthorized();
    $this->postJson(URL_NOT.'/abc/leida')->assertUnauthorized();
});


// ---------- Listar ----------

it('notificaciones lista solo las del usuario y cuenta las no leídas', function () {
    $yo = notUsuario();
    $otro = notUsuario();

    notAviso($yo);
    notAviso($yo, TipoNotificacion::SolicitudAceptada);
    notAviso($otro);

    $this->withToken(notToken($yo))
        ->getJson(URL_NOT)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.no_leidas', 2)
        ->assertJsonStructure(['data' => [['id', 'tipo', 'titulo', 'mensaje', 'solicitud_id', 'asesoria_id', 'como', 'leida', 'fecha']]]);
});

it('notificaciones guarda el código del tipo, no el nombre de la clase', function () {
    $yo = notUsuario();
    notAviso($yo, TipoNotificacion::SolicitudRechazadaPorCupo);

    $this->assertDatabaseHas('notifications', ['type' => 'solicitud.rechazada_por_cupo']);

    $this->withToken(notToken($yo))
        ->getJson(URL_NOT)
        ->assertJsonPath('data.0.tipo', 'solicitud.rechazada_por_cupo');
});

it('notificaciones filtra solo las no leídas con ?no_leidas=1', function () {
    $yo = notUsuario();
    notAviso($yo);
    notAviso($yo);
    $yo->notifications()->first()->markAsRead();

    $this->withToken(notToken($yo))
        ->getJson(URL_NOT.'?no_leidas=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.no_leidas', 1);
});


// ---------- Marcar como leída ----------

it('notificaciones marca una como leída y baja el contador', function () {
    $yo = notUsuario();
    notAviso($yo);
    notAviso($yo);

    $id = $yo->notifications()->first()->id;

    $this->withToken(notToken($yo))
        ->postJson(URL_NOT."/{$id}/leida")
        ->assertOk()
        ->assertJsonPath('data.leida', true);

    expect($yo->unreadNotifications()->count())->toBe(1);
});

it('notificaciones no deja marcar la de otro usuario (404) y sigue sin leer', function () {
    $yo = notUsuario();
    $otro = notUsuario();
    notAviso($otro);

    $id = $otro->notifications()->first()->id;

    $this->withToken(notToken($yo))
        ->postJson(URL_NOT."/{$id}/leida")
        ->assertNotFound();

    expect($otro->unreadNotifications()->count())->toBe(1);
});

it('notificaciones marca todas como leídas sin tocar las de otro', function () {
    $yo = notUsuario();
    $otro = notUsuario();
    notAviso($yo);
    notAviso($yo);
    notAviso($otro);

    $this->withToken(notToken($yo))
        ->postJson(URL_NOT.'/leidas')
        ->assertOk()
        ->assertJsonPath('marcadas', 2);

    expect($yo->unreadNotifications()->count())->toBe(0)
        ->and($otro->unreadNotifications()->count())->toBe(1);
});


// ---------- Los avisos salen de los servicios ----------

it('aceptar una solicitud avisa al estudiante', function () {
    Notification::fake();

    $estudiante = notUsuario();
    $asesor = notUsuario(Rol::Asesor);
    $solicitud = notSolicitud($estudiante, $asesor);

    $this->withToken(notToken($asesor))
        ->postJson("/api/v1/asesor/solicitudes/{$solicitud->id}/aceptar")
        ->assertSuccessful();

    Notification::assertSentTo($estudiante, SolicitudNotificacion::class,
        fn ($n) => $n->tipo === TipoNotificacion::SolicitudAceptada && $n->solicitudId === $solicitud->id);
});

it('aceptar avisa también a los estudiantes cuya solicitud se rechazó por cupo', function () {
    Notification::fake();

    $asesor = notUsuario(Rol::Asesor);
    $elegido = notUsuario();
    $perdedor = notUsuario();

    $aceptada = notSolicitud($elegido, $asesor);
    notSolicitud($perdedor, $asesor); // mismo asesor, horario y día

    $this->withToken(notToken($asesor))
        ->postJson("/api/v1/asesor/solicitudes/{$aceptada->id}/aceptar")
        ->assertSuccessful();

    Notification::assertSentTo($perdedor, SolicitudNotificacion::class,
        fn ($n) => $n->tipo === TipoNotificacion::SolicitudRechazadaPorCupo);
});

it('rechazar una solicitud avisa al estudiante con el motivo', function () {
    Notification::fake();

    $estudiante = notUsuario();
    $asesor = notUsuario(Rol::Asesor);
    $solicitud = notSolicitud($estudiante, $asesor);

    $this->withToken(notToken($asesor))
        ->postJson("/api/v1/asesor/solicitudes/{$solicitud->id}/rechazar", ['motivo_rechazo' => 'No tengo tiempo'])
        ->assertSuccessful();

    Notification::assertSentTo($estudiante, SolicitudNotificacion::class,
        fn ($n) => $n->tipo === TipoNotificacion::SolicitudRechazada && $n->motivo === 'No tengo tiempo');
});

it('cancelar su solicitud avisa al asesor', function () {
    Notification::fake();

    $estudiante = notUsuario();
    $asesor = notUsuario(Rol::Asesor);
    $solicitud = notSolicitud($estudiante, $asesor);

    $this->withToken(notToken($estudiante))
        ->postJson("/api/v1/estudiante/solicitudes/{$solicitud->id}/cancelar")
        ->assertSuccessful();

    Notification::assertSentTo($asesor, SolicitudNotificacion::class,
        fn ($n) => $n->tipo === TipoNotificacion::SolicitudCancelada);
});

it('una solicitud que falla al aceptar no manda avisos', function () {
    Notification::fake();

    $estudiante = notUsuario();
    $asesor = notUsuario(Rol::Asesor);
    $solicitud = notSolicitud($estudiante, $asesor, ['estatus' => EstatusSolicitud::Rechazada]);

    $this->withToken(notToken($asesor))
        ->postJson("/api/v1/asesor/solicitudes/{$solicitud->id}/aceptar")
        ->assertStatus(409);

    Notification::assertNothingSent();
});

// ---------- Borrar ----------

it('notificaciones borra una y no toca las demás', function () {
    $yo = notUsuario();
    notAviso($yo);
    notAviso($yo);

    $id = $yo->notifications()->first()->id;

    $this->withToken(notToken($yo))
        ->deleteJson(URL_NOT."/{$id}")
        ->assertOk();

    expect($yo->notifications()->count())->toBe(1)
        ->and($yo->notifications()->where('id', $id)->exists())->toBeFalse();
});

it('notificaciones no deja borrar la de otro usuario (404)', function () {
    $yo = notUsuario();
    $otro = notUsuario();
    notAviso($otro);

    $id = $otro->notifications()->first()->id;

    $this->withToken(notToken($yo))
        ->deleteJson(URL_NOT."/{$id}")
        ->assertNotFound();

    expect($otro->notifications()->count())->toBe(1);
});

it('notificaciones borra todas las del usuario sin tocar las de otro', function () {
    $yo = notUsuario();
    $otro = notUsuario();
    notAviso($yo);
    notAviso($yo);
    notAviso($otro);

    $this->withToken(notToken($yo))
        ->deleteJson(URL_NOT)
        ->assertOk()
        ->assertJsonPath('borradas', 2);

    expect($yo->notifications()->count())->toBe(0)
        ->and($otro->notifications()->count())->toBe(1);
});

it('notificaciones con solo_leidas=1 conserva las que no se han leído', function () {
    $yo = notUsuario();
    notAviso($yo);
    notAviso($yo);
    $yo->notifications()->first()->markAsRead();

    $this->withToken(notToken($yo))
        ->deleteJson(URL_NOT.'?solo_leidas=1')
        ->assertOk()
        ->assertJsonPath('borradas', 1);

    expect($yo->unreadNotifications()->count())->toBe(1);
});