<?php

// Guardar como: tests/Feature/NotificacionesAvisosTest.php
// Autocontenido (prefijo "nav"): las funciones de los archivos de Pest son globales.

use App\Enums\EstatusAsesoria;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Enums\TipoNotificacion;
use App\Models\Asesoria;
use App\Models\CicloEscolar;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Usuario;
use App\Notifications\AsesoriaNotificacion;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;


// ---------- Ayudantes ----------

function navToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function navUsuario(Rol $rol = Rol::Estudiante): Usuario
{
    return Usuario::factory()->conRol($rol)->create();
}

function navAsesoria(Usuario $estudiante, Usuario $asesor): Asesoria
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

    return Asesoria::create([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => $ciclo->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => Horario::firstOrCreate(['etiqueta' => '7:00-8:00 AM'])->id,
        'modalidad' => Modalidad::Virtual,
        'razon' => RazonAsesoria::Parcial,
        'estatus' => EstatusAsesoria::EnCurso,
        'fecha_inicio' => Carbon::now()->next(Carbon::MONDAY)->subWeek()->toDateString(),
    ]);
}


// ---------- Chat ----------

it('un mensaje de chat avisa a la otra persona y varios se juntan en un solo aviso', function () {
    $estudiante = navUsuario();
    $asesor = navUsuario(Rol::Asesor);
    $asesoria = navAsesoria($estudiante, $asesor);

    foreach (['Hola', 'Segunda pregunta'] as $texto) {
        $this->withToken(navToken($estudiante))
            ->postJson("/api/v1/asesorias/{$asesoria->id}/mensajes", ['mensaje' => $texto])
            ->assertSuccessful();
    }

    $avisos = $asesor->unreadNotifications()->where('type', 'chat.mensaje')->get();

    expect($avisos)->toHaveCount(1)
        ->and($avisos->first()->data['cuantos'])->toBe(2)
        ->and($avisos->first()->data['como'])->toBe('asesor')
        ->and($estudiante->notifications()->count())->toBe(0);
});

it('abrir el chat apaga el aviso del mensaje', function () {
    $estudiante = navUsuario();
    $asesor = navUsuario(Rol::Asesor);
    $asesoria = navAsesoria($estudiante, $asesor);

    $this->withToken(navToken($estudiante))
        ->postJson("/api/v1/asesorias/{$asesoria->id}/mensajes", ['mensaje' => 'Hola'])
        ->assertSuccessful();

    expect($asesor->unreadNotifications()->count())->toBe(1);

    // Olvida el usuario de la petición anterior (si no, la siguiente sigue como el estudiante)
    $this->app['auth']->forgetGuards();

    $this->withToken(navToken($asesor))
        ->postJson("/api/v1/asesorias/{$asesoria->id}/mensajes/leidos")
        ->assertOk();

    expect($asesor->unreadNotifications()->count())->toBe(0);
});


// ---------- Asesorías ----------

it('cancelar una asesoría avisa al estudiante con el motivo, no al asesor que cancela', function () {
    Notification::fake();

    $estudiante = navUsuario();
    $asesor = navUsuario(Rol::Asesor);
    $asesoria = navAsesoria($estudiante, $asesor);

    $this->withToken(navToken($asesor))
        ->postJson("/api/v1/asesor/asesorias/{$asesoria->id}/cancelar", ['motivo_cancelacion' => 'Me enfermé'])
        ->assertSuccessful();

    Notification::assertSentTo($estudiante, AsesoriaNotificacion::class,
        fn ($n) => $n->tipo === TipoNotificacion::AsesoriaCancelada && $n->detalle === 'Me enfermé');

    Notification::assertNotSentTo($asesor, AsesoriaNotificacion::class);
});

it('si un admin cancela la asesoría, también se avisa al asesor', function () {
    Notification::fake();

    $estudiante = navUsuario();
    $asesor = navUsuario(Rol::Asesor);
    $admin = navUsuario(Rol::Admin);
    $asesoria = navAsesoria($estudiante, $asesor);

    $this->withToken(navToken($admin))
        ->postJson("/api/v1/admin/asesorias/{$asesoria->id}/cancelar", ['motivo_cancelacion' => 'Cierre de ciclo'])
        ->assertSuccessful();

    Notification::assertSentTo($estudiante, AsesoriaNotificacion::class);
    Notification::assertSentTo($asesor, AsesoriaNotificacion::class,
        fn ($n) => $n->como === 'asesor');
});

it('completar una asesoría no manda ningún aviso', function () {
    Notification::fake();

    $estudiante = navUsuario();
    $asesor = navUsuario(Rol::Asesor);
    $asesoria = navAsesoria($estudiante, $asesor);

    $this->withToken(navToken($asesor))
        ->postJson("/api/v1/asesor/asesorias/{$asesoria->id}/completar")
        ->assertSuccessful();

    Notification::assertNothingSent();
});