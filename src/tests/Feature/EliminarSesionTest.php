<?php

use App\Enums\EstatusAsesoria;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Models\Asesoria;
use App\Models\CicloEscolar;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Sesion;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;


// ---------- Ayudantes ----------

function dsesUrl(int $sesionId): string
{
    return "/api/v1/asesor/sesiones/{$sesionId}";
}

function dsesToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function dsesEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create($datos);
}

function dsesAsesor(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create($datos);
}

function dsesCiclo(): CicloEscolar
{
    return CicloEscolar::first() ?? CicloEscolar::create([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => '2026-2027 1',
        'periodo' => 1,
        'fecha_inicio' => now()->subMonth()->toDateString(),
        'fecha_fin' => now()->addYear()->toDateString(),
        'vigente' => true,
    ]);
}

function dsesHorario(string $etiqueta = '7:00-8:00 AM'): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Asesoría insertada directo en la BD (facultad_id explícito: no hay usuario logueado)
function dsesAsesoria(
    Usuario $estudiante,
    Usuario $asesor,
    EstatusAsesoria $estatus = EstatusAsesoria::EnCurso,
): Asesoria {
    $licenciatura = Licenciatura::firstOrCreate([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => 'LICENCIATURA PRUEBA',
    ]);

    $grupo = Grupo::firstOrCreate(
        ['licenciatura_id' => $licenciatura->id, 'nombre' => '3-1'],
        ['anio' => 3]
    );

    $materia = Materia::firstOrCreate(
        ['licenciatura_id' => $licenciatura->id, 'nombre' => 'Estructura de Datos'],
        ['semestre' => 1]
    );

    return Asesoria::create([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => dsesCiclo()->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => dsesHorario()->id,
        'fecha_inicio' => Carbon::now()->subWeeks(3)->toDateString(),
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => $estatus,
    ]);
}

function dsesSesion(Asesoria $asesoria, array $extra = []): Sesion
{
    return Sesion::create(array_merge([
        'asesoria_id' => $asesoria->id,
        'fecha' => Carbon::now()->subWeek()->toDateString(),
        'horario_id' => dsesHorario()->id,
        'registrada_por' => $asesoria->asesor_id,
    ], $extra));
}


// ---------- Acceso ----------

it('eliminar sesión devuelve 401 sin sesión', function () {
    $sesion = dsesSesion(dsesAsesoria(dsesEstudiante(), dsesAsesor()));

    $this->deleteJson(dsesUrl($sesion->id))->assertUnauthorized();

    expect(Sesion::count())->toBe(1);
});

it('eliminar sesión rechaza con 403 a un estudiante puro', function () {
    $estudiante = dsesEstudiante();
    $sesion = dsesSesion(dsesAsesoria($estudiante, dsesAsesor()));

    $this->withToken(dsesToken($estudiante))
        ->deleteJson(dsesUrl($sesion->id))
        ->assertForbidden();

    expect(Sesion::count())->toBe(1);
});

it('eliminar sesión bloquea con 403 a un asesor con cuenta no activa', function () {
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);
    $sesion = dsesSesion(dsesAsesoria(dsesEstudiante(), $asesor));

    $this->withToken(dsesToken($asesor))
        ->deleteJson(dsesUrl($sesion->id))
        ->assertForbidden();

    expect(Sesion::count())->toBe(1);
});


// ---------- Permiso por registro (policy) ----------

it('eliminar sesión rechaza con 403 a un asesor que no es el asignado', function () {
    $sesion = dsesSesion(dsesAsesoria(dsesEstudiante(), dsesAsesor()));

    $this->withToken(dsesToken(dsesAsesor()))
        ->deleteJson(dsesUrl($sesion->id))
        ->assertForbidden();

    expect(Sesion::count())->toBe(1);
});

it('eliminar sesión rechaza con 403 a un asesor par que es el estudiante de esa asesoría', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $sesion = dsesSesion(dsesAsesoria($par, dsesAsesor()));

    $this->withToken(dsesToken($par))
        ->deleteJson(dsesUrl($sesion->id))
        ->assertForbidden();

    expect(Sesion::count())->toBe(1);
});

it('eliminar sesión: un asesor par la elimina cuando él es el asesor asignado', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $sesion = dsesSesion(dsesAsesoria(dsesEstudiante(), $par));

    $this->withToken(dsesToken($par))
        ->deleteJson(dsesUrl($sesion->id))
        ->assertNoContent();

    expect(Sesion::count())->toBe(0);
});

it('eliminar sesión devuelve 403 antes que 409 a quien no es el asesor, aunque la asesoría esté cerrada', function () {
    $sesion = dsesSesion(dsesAsesoria(dsesEstudiante(), dsesAsesor(), EstatusAsesoria::Completada));

    $this->withToken(dsesToken(dsesAsesor()))
        ->deleteJson(dsesUrl($sesion->id))
        ->assertForbidden();

    expect(Sesion::count())->toBe(1);
});


// ---------- 404 ----------

it('eliminar sesión devuelve 404 si no existe', function () {
    $this->withToken(dsesToken(dsesAsesor()))
        ->deleteJson(dsesUrl(999999))
        ->assertNotFound();
});

it('eliminar sesión devuelve 404 si es de una asesoría de otra facultad', function () {
    $otra = UsuarioFactory::facultad('OTR');
    $asesoriaAjena = dsesAsesoria(
        dsesEstudiante(['facultad_id' => $otra->id]),
        dsesAsesor(['facultad_id' => $otra->id]),
    );
    $sesion = dsesSesion($asesoriaAjena);

    $this->withToken(dsesToken(dsesAsesor()))
        ->deleteJson(dsesUrl($sesion->id))
        ->assertNotFound();

    expect(Sesion::count())->toBe(1);
});


// ---------- Caso feliz ----------

it('eliminar sesión: el asesor asignado la elimina y responde 204 sin cuerpo', function () {
    $asesor = dsesAsesor();
    $asesoria = dsesAsesoria(dsesEstudiante(), $asesor);
    $sesion = dsesSesion($asesoria);

    $respuesta = $this->withToken(dsesToken($asesor))
        ->deleteJson(dsesUrl($sesion->id))
        ->assertNoContent();

    expect($respuesta->getContent())->toBe('');
    expect(Sesion::find($sesion->id))->toBeNull();
});

it('eliminar sesión borra solo esa sesión y no toca la asesoría ni las demás', function () {
    $asesor = dsesAsesor();
    $estudiante = dsesEstudiante();
    $asesoria = dsesAsesoria($estudiante, $asesor);
    $otraAsesoria = dsesAsesoria($estudiante, $asesor);

    $objetivo = dsesSesion($asesoria);
    $hermana = dsesSesion($asesoria, ['fecha' => Carbon::now()->subDays(2)->toDateString()]);
    $ajena = dsesSesion($otraAsesoria);

    $this->withToken(dsesToken($asesor))
        ->deleteJson(dsesUrl($objetivo->id))
        ->assertNoContent();

    expect(Sesion::find($objetivo->id))->toBeNull();
    expect(Sesion::find($hermana->id))->not->toBeNull();
    expect(Sesion::find($ajena->id))->not->toBeNull();
    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});

it('eliminar sesión: baja el sesiones_count y desaparece de la lista de sesiones', function () {
    $asesor = dsesAsesor();
    $asesoria = dsesAsesoria(dsesEstudiante(), $asesor);
    $objetivo = dsesSesion($asesoria);
    dsesSesion($asesoria, ['fecha' => Carbon::now()->subDays(2)->toDateString()]);
    $token = dsesToken($asesor);

    $this->withToken($token)->deleteJson(dsesUrl($objetivo->id))->assertNoContent();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson("/api/v1/asesorias/{$asesoria->id}/sesiones")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/v1/asesor/asesorias?estatus=en_curso')
        ->assertOk()
        ->assertJsonPath('data.0.sesiones_count', 1);
});

it('eliminar sesión: después se puede registrar otra con la misma fecha y horario', function () {
    $asesor = dsesAsesor();
    $asesoria = dsesAsesoria(dsesEstudiante(), $asesor);
    $sesion = dsesSesion($asesoria);
    $token = dsesToken($asesor);

    $this->withToken($token)->deleteJson(dsesUrl($sesion->id))->assertNoContent();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson("/api/v1/asesor/asesorias/{$asesoria->id}/sesiones", [
            'fecha' => $sesion->fecha->toDateString(),
            'horario_id' => $sesion->horario_id,
        ])
        ->assertCreated();
});

it('eliminar sesión: la segunda llamada seguida da 404', function () {
    $asesor = dsesAsesor();
    $sesion = dsesSesion(dsesAsesoria(dsesEstudiante(), $asesor));
    $token = dsesToken($asesor);

    $this->withToken($token)->deleteJson(dsesUrl($sesion->id))->assertNoContent();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->deleteJson(dsesUrl($sesion->id))->assertNotFound();
});


// ---------- Estado (409) ----------

it('eliminar sesión rechaza con 409 si la asesoría ya está completada o cancelada y no la borra', function () {
    $asesor = dsesAsesor();
    $estudiante = dsesEstudiante();
    $token = dsesToken($asesor);

    foreach ([EstatusAsesoria::Completada, EstatusAsesoria::Cancelada] as $cerrada) {
        $sesion = dsesSesion(dsesAsesoria($estudiante, $asesor, $cerrada));

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->deleteJson(dsesUrl($sesion->id))
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'asesoria_no_en_curso');

        expect(Sesion::find($sesion->id))->not->toBeNull();
    }
});