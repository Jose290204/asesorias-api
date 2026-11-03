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

function rsesUrl(int $asesoriaId): string
{
    return "/api/v1/asesor/asesorias/{$asesoriaId}/sesiones";
}

function rsesToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function rsesEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create($datos);
}

function rsesAsesor(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create($datos);
}

function rsesCiclo(): CicloEscolar
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

function rsesHorario(string $etiqueta = '7:00-8:00 AM'): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Fecha de inicio de las asesorías que arman los ayudantes: hace tres semanas
function rsesInicio(): Carbon
{
    return Carbon::now()->subWeeks(3)->startOfDay();
}

// Asesoría insertada directo en la BD (facultad_id explícito: no hay usuario logueado)
function rsesAsesoria(
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
        'ciclo_escolar_id' => rsesCiclo()->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => rsesHorario()->id,
        'fecha_inicio' => rsesInicio()->toDateString(),
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => $estatus,
    ]);
}

// Body válido: una sesión de hace una semana (dentro de [fecha_inicio, hoy]) en el horario de la asesoría
function rsesDatos(array $extra = []): array
{
    return array_merge([
        'fecha' => Carbon::now()->subWeek()->toDateString(),
        'horario_id' => rsesHorario()->id,
    ], $extra);
}

// Sesión preexistente, insertada directo en la BD
function rsesInsertar(Asesoria $asesoria, array $extra = []): Sesion
{
    return Sesion::create(array_merge([
        'asesoria_id' => $asesoria->id,
        'fecha' => Carbon::now()->subWeek()->toDateString(),
        'horario_id' => rsesHorario()->id,
        'registrada_por' => $asesoria->asesor_id,
    ], $extra));
}


// ---------- Acceso ----------

it('registrar sesión devuelve 401 sin sesión', function () {
    $asesoria = rsesAsesoria(rsesEstudiante(), rsesAsesor());

    $this->postJson(rsesUrl($asesoria->id), rsesDatos())->assertUnauthorized();
});

it('registrar sesión rechaza con 403 a un estudiante puro', function () {
    $estudiante = rsesEstudiante();
    $asesoria = rsesAsesoria($estudiante, rsesAsesor());

    $this->withToken(rsesToken($estudiante))
        ->postJson(rsesUrl($asesoria->id), rsesDatos())
        ->assertForbidden();

    expect(Sesion::count())->toBe(0);
});

it('registrar sesión bloquea con 403 a un asesor con cuenta no activa', function () {
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor);

    $this->withToken(rsesToken($asesor))
        ->postJson(rsesUrl($asesoria->id), rsesDatos())
        ->assertForbidden();

    expect(Sesion::count())->toBe(0);
});


// ---------- Permiso por registro (policy) ----------

it('registrar sesión rechaza con 403 a un asesor que no es el asignado', function () {
    $asesoria = rsesAsesoria(rsesEstudiante(), rsesAsesor());

    $this->withToken(rsesToken(rsesAsesor()))
        ->postJson(rsesUrl($asesoria->id), rsesDatos())
        ->assertForbidden();

    expect(Sesion::count())->toBe(0);
});

it('registrar sesión devuelve 403 y no 422 a un asesor ajeno que manda datos inválidos', function () {
    $asesoria = rsesAsesoria(rsesEstudiante(), rsesAsesor());

    // authorize() corre antes que rules(): el ajeno no llega a ver errores de validación
    $this->withToken(rsesToken(rsesAsesor()))
        ->postJson(rsesUrl($asesoria->id), ['fecha' => 'basura'])
        ->assertForbidden();
});

it('registrar sesión rechaza con 403 a un asesor par que es el estudiante de esa asesoría', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $asesoria = rsesAsesoria($par, rsesAsesor());

    $this->withToken(rsesToken($par))
        ->postJson(rsesUrl($asesoria->id), rsesDatos())
        ->assertForbidden();

    expect(Sesion::count())->toBe(0);
});

it('registrar sesión: un asesor par la registra cuando él es el asesor asignado', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $asesoria = rsesAsesoria(rsesEstudiante(), $par);

    $this->withToken(rsesToken($par))
        ->postJson(rsesUrl($asesoria->id), rsesDatos())
        ->assertCreated();
});


// ---------- 404 ----------

it('registrar sesión devuelve 404 si la asesoría no existe', function () {
    $this->withToken(rsesToken(rsesAsesor()))
        ->postJson(rsesUrl(999999), rsesDatos())
        ->assertNotFound();
});

it('registrar sesión devuelve 404 si la asesoría es de otra facultad', function () {
    $otra = UsuarioFactory::facultad('OTR');
    $asesoria = rsesAsesoria(
        rsesEstudiante(['facultad_id' => $otra->id]),
        rsesAsesor(['facultad_id' => $otra->id]),
    );

    $this->withToken(rsesToken(rsesAsesor()))
        ->postJson(rsesUrl($asesoria->id), rsesDatos())
        ->assertNotFound();

    expect(Sesion::count())->toBe(0);
});


// ---------- Caso feliz ----------

it('registrar sesión: el asesor asignado la registra y responde 201', function () {
    $asesor = rsesAsesor(['nombre' => 'Marco', 'apellido_paterno' => 'Valdez', 'apellido_materno' => 'Ríos']);
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor);
    $fecha = Carbon::now()->subWeek()->toDateString();

    $this->withToken(rsesToken($asesor))
        ->postJson(rsesUrl($asesoria->id), rsesDatos([
            'fecha' => $fecha,
            'tema' => 'Arreglos y ciclos',
            'notas' => 'Quedó pendiente el ejercicio 4',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.fecha', $fecha)
        ->assertJsonPath('data.horario.id', rsesHorario()->id)
        ->assertJsonPath('data.horario.etiqueta', '7:00-8:00 AM')
        ->assertJsonPath('data.tema', 'Arreglos y ciclos')
        ->assertJsonPath('data.notas', 'Quedó pendiente el ejercicio 4')
        ->assertJsonPath('data.registrada_por', 'Marco Valdez Ríos');

    $sesion = Sesion::first();

    expect(Sesion::count())->toBe(1);
    expect($sesion->asesoria_id)->toBe($asesoria->id);
    expect($sesion->fecha->toDateString())->toBe($fecha);
    expect($sesion->registrada_por)->toBe($asesor->id);
});

it('registrar sesión: tema y notas son opcionales', function () {
    $asesor = rsesAsesor();
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor);

    $this->withToken(rsesToken($asesor))
        ->postJson(rsesUrl($asesoria->id), rsesDatos())
        ->assertCreated()
        ->assertJsonPath('data.tema', null)
        ->assertJsonPath('data.notas', null);
});

it('registrar sesión ignora lo que mande el cliente fuera de los campos permitidos', function () {
    $asesor = rsesAsesor();
    $otro = rsesAsesor();
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor);
    $otraAsesoria = rsesAsesoria(rsesEstudiante(), $otro);

    $this->withToken(rsesToken($asesor))
        ->postJson(rsesUrl($asesoria->id), rsesDatos([
            'id' => 999,
            'asesoria_id' => $otraAsesoria->id,
            'registrada_por' => $otro->id,
        ]))
        ->assertCreated();

    $sesion = Sesion::first();

    expect($sesion->asesoria_id)->toBe($asesoria->id);
    expect($sesion->registrada_por)->toBe($asesor->id);
    expect($sesion->id)->not->toBe(999);
});

it('registrar sesión: aparece en la lista de sesiones y suma a sesiones_count', function () {
    $asesor = rsesAsesor();
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor);
    $token = rsesToken($asesor);

    $id = $this->withToken($token)
        ->postJson(rsesUrl($asesoria->id), rsesDatos())
        ->assertCreated()
        ->json('data.id');

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson("/api/v1/asesorias/{$asesoria->id}/sesiones")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id);

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/v1/asesor/asesorias?estatus=en_curso')
        ->assertOk()
        ->assertJsonPath('data.0.sesiones_count', 1);
});

it('registrar sesión: no hay tope de sesiones por asesoría', function () {
    $asesor = rsesAsesor();
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor);
    $token = rsesToken($asesor);

    foreach (range(0, 14) as $dias) {
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson(rsesUrl($asesoria->id), rsesDatos(['fecha' => Carbon::now()->subDays($dias)->toDateString()]))
            ->assertCreated();
    }

    expect(Sesion::count())->toBe(15);
});


// ---------- Validación de forma (422) ----------

it('registrar sesión rechaza con 422 cada campo obligatorio que falte', function () {
    $asesor = rsesAsesor();
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor);
    $token = rsesToken($asesor);

    foreach (['fecha', 'horario_id'] as $campo) {
        $datos = rsesDatos();
        unset($datos[$campo]);

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson(rsesUrl($asesoria->id), $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($campo);
    }

    expect(Sesion::count())->toBe(0);
});

it('registrar sesión rechaza con 422 cada campo inválido', function () {
    $asesor = rsesAsesor();
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor);
    $token = rsesToken($asesor);

    $casos = [
        'fecha' => '26/10/2026',
        'horario_id' => 999999,
        'tema' => str_repeat('a', 151),
        'notas' => str_repeat('a', 2001),
    ];

    foreach ($casos as $campo => $valor) {
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson(rsesUrl($asesoria->id), rsesDatos([$campo => $valor]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($campo);
    }

    expect(Sesion::count())->toBe(0);
});

it('registrar sesión rechaza con 422 una fecha futura y acepta la de hoy', function () {
    $asesor = rsesAsesor();
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor);
    $token = rsesToken($asesor);

    $this->withToken($token)
        ->postJson(rsesUrl($asesoria->id), rsesDatos(['fecha' => Carbon::now()->addDay()->toDateString()]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fecha');

    expect(Sesion::count())->toBe(0);

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson(rsesUrl($asesoria->id), rsesDatos(['fecha' => Carbon::now()->toDateString()]))
        ->assertCreated();
});

it('registrar sesión acepta tema de exactamente 150 caracteres y notas de 2000', function () {
    $asesor = rsesAsesor();
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor);

    $this->withToken(rsesToken($asesor))
        ->postJson(rsesUrl($asesoria->id), rsesDatos([
            'tema' => str_repeat('a', 150),
            'notas' => str_repeat('a', 2000),
        ]))
        ->assertCreated();
});


// ---------- Reglas de negocio ----------

it('registrar sesión rechaza con 422 una fecha anterior al inicio de la asesoría', function () {
    $asesor = rsesAsesor();
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor);
    $antes = rsesInicio()->subDay()->toDateString();

    $this->withToken(rsesToken($asesor))
        ->postJson(rsesUrl($asesoria->id), rsesDatos(['fecha' => $antes]))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'fecha_anterior_al_inicio');

    expect(Sesion::count())->toBe(0);
});

it('registrar sesión acepta una fecha igual a la de inicio de la asesoría', function () {
    $asesor = rsesAsesor();
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor);

    $this->withToken(rsesToken($asesor))
        ->postJson(rsesUrl($asesoria->id), rsesDatos(['fecha' => rsesInicio()->toDateString()]))
        ->assertCreated();
});

it('registrar sesión rechaza con 409 una asesoría que ya está completada o cancelada', function () {
    $asesor = rsesAsesor();
    $estudiante = rsesEstudiante();
    $token = rsesToken($asesor);

    foreach ([EstatusAsesoria::Completada, EstatusAsesoria::Cancelada] as $cerrada) {
        $asesoria = rsesAsesoria($estudiante, $asesor, $cerrada);

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson(rsesUrl($asesoria->id), rsesDatos())
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'asesoria_no_en_curso');
    }

    expect(Sesion::count())->toBe(0);
});

it('registrar sesión devuelve 409 y no el error de fecha si la asesoría ya no está en curso', function () {
    $asesor = rsesAsesor();
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor, EstatusAsesoria::Cancelada);

    $this->withToken(rsesToken($asesor))
        ->postJson(rsesUrl($asesoria->id), rsesDatos(['fecha' => rsesInicio()->subDay()->toDateString()]))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'asesoria_no_en_curso');
});

it('registrar sesión rechaza con 409 repetir la misma fecha y horario en la misma asesoría', function () {
    $asesor = rsesAsesor();
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor);
    rsesInsertar($asesoria);

    $this->withToken(rsesToken($asesor))
        ->postJson(rsesUrl($asesoria->id), rsesDatos())
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'sesion_duplicada');

    expect(Sesion::count())->toBe(1);
});

it('registrar sesión: la misma fecha en otro horario, o el mismo horario otro día, sí se puede', function () {
    $asesor = rsesAsesor();
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor);
    rsesInsertar($asesoria);
    $token = rsesToken($asesor);

    // Misma fecha, otro horario
    $this->withToken($token)
        ->postJson(rsesUrl($asesoria->id), rsesDatos(['horario_id' => rsesHorario('8:00-9:00 AM')->id]))
        ->assertCreated();

    $this->app['auth']->forgetGuards();

    // Mismo horario, otra fecha
    $this->withToken($token)
        ->postJson(rsesUrl($asesoria->id), rsesDatos(['fecha' => Carbon::now()->subDays(2)->toDateString()]))
        ->assertCreated();

    expect(Sesion::count())->toBe(3);
});

it('registrar sesión: la misma fecha y horario en otra asesoría no estorba', function () {
    $asesor = rsesAsesor();
    $estudiante = rsesEstudiante();
    $objetivo = rsesAsesoria($estudiante, $asesor);
    $otra = rsesAsesoria($estudiante, $asesor);
    rsesInsertar($otra);

    $this->withToken(rsesToken($asesor))
        ->postJson(rsesUrl($objetivo->id), rsesDatos())
        ->assertCreated();

    expect(Sesion::count())->toBe(2);
});

it('registrar sesión: la segunda alta seguida de la misma sesión da 409', function () {
    $asesor = rsesAsesor();
    $asesoria = rsesAsesoria(rsesEstudiante(), $asesor);
    $token = rsesToken($asesor);

    $this->withToken($token)->postJson(rsesUrl($asesoria->id), rsesDatos())->assertCreated();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson(rsesUrl($asesoria->id), rsesDatos())
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'sesion_duplicada');

    expect(Sesion::count())->toBe(1);
});