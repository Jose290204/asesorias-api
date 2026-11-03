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

function lsesUrl(int $asesoriaId): string
{
    return "/api/v1/asesorias/{$asesoriaId}/sesiones";
}

function lsesToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function lsesEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create($datos);
}

function lsesAsesor(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create($datos);
}

function lsesPar(): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
}

function lsesCiclo(): CicloEscolar
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

function lsesHorario(string $etiqueta = '7:00-8:00 AM'): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Asesoría insertada directo en la BD (facultad_id explícito: no hay usuario logueado)
function lsesAsesoria(
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
        'ciclo_escolar_id' => lsesCiclo()->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => lsesHorario()->id,
        'fecha_inicio' => Carbon::now()->subWeeks(3)->toDateString(),
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => $estatus,
    ]);
}

function lsesSesion(Asesoria $asesoria, array $extra = []): Sesion
{
    return Sesion::create(array_merge([
        'asesoria_id' => $asesoria->id,
        'fecha' => Carbon::now()->subWeek()->toDateString(),
        'horario_id' => lsesHorario()->id,
        'registrada_por' => $asesoria->asesor_id,
    ], $extra));
}


// ---------- Acceso ----------

it('listar sesiones devuelve 401 sin sesión', function () {
    $asesoria = lsesAsesoria(lsesEstudiante(), lsesAsesor());

    $this->getJson(lsesUrl($asesoria->id))->assertUnauthorized();
});

it('listar sesiones bloquea con 403 a un participante con cuenta no activa', function () {
    $estudiante = Usuario::factory()->pendiente()->conRol(Rol::Estudiante)->create(['password' => 'secreto123']);
    $asesoria = lsesAsesoria($estudiante, lsesAsesor());
    lsesSesion($asesoria);

    $this->withToken(lsesToken($estudiante))
        ->getJson(lsesUrl($asesoria->id))
        ->assertForbidden();
});


// ---------- Permiso por registro (policy) ----------

it('listar sesiones: el asesor asignado ve las sesiones de su asesoría', function () {
    $asesor = lsesAsesor();
    $asesoria = lsesAsesoria(lsesEstudiante(), $asesor);
    lsesSesion($asesoria);

    $this->withToken(lsesToken($asesor))
        ->getJson(lsesUrl($asesoria->id))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('listar sesiones: el estudiante de la asesoría también las ve', function () {
    $estudiante = lsesEstudiante();
    $asesoria = lsesAsesoria($estudiante, lsesAsesor());
    lsesSesion($asesoria);

    $this->withToken(lsesToken($estudiante))
        ->getJson(lsesUrl($asesoria->id))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('listar sesiones rechaza con 403 a un asesor que no es el asignado', function () {
    $asesoria = lsesAsesoria(lsesEstudiante(), lsesAsesor());
    lsesSesion($asesoria);

    $this->withToken(lsesToken(lsesAsesor()))
        ->getJson(lsesUrl($asesoria->id))
        ->assertForbidden();
});

it('listar sesiones rechaza con 403 a un estudiante que no es el de la asesoría', function () {
    $asesoria = lsesAsesoria(lsesEstudiante(), lsesAsesor());
    lsesSesion($asesoria);

    $this->withToken(lsesToken(lsesEstudiante()))
        ->getJson(lsesUrl($asesoria->id))
        ->assertForbidden();
});

it('listar sesiones rechaza con 403 a un asesor par que no participa en esa asesoría', function () {
    $asesoria = lsesAsesoria(lsesEstudiante(), lsesAsesor());

    $this->withToken(lsesToken(lsesPar()))
        ->getJson(lsesUrl($asesoria->id))
        ->assertForbidden();
});

it('listar sesiones: un asesor par ve las sesiones tanto de la que imparte como de la que recibe', function () {
    $par = lsesPar();
    $comoAsesor = lsesAsesoria(lsesEstudiante(), $par);
    $comoEstudiante = lsesAsesoria($par, lsesAsesor());
    lsesSesion($comoAsesor);
    lsesSesion($comoEstudiante);
    $token = lsesToken($par);

    $this->withToken($token)
        ->getJson(lsesUrl($comoAsesor->id))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson(lsesUrl($comoEstudiante->id))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});


// ---------- 404 ----------

it('listar sesiones devuelve 404 si la asesoría no existe', function () {
    $this->withToken(lsesToken(lsesAsesor()))
        ->getJson(lsesUrl(999999))
        ->assertNotFound();
});

it('listar sesiones devuelve 404 si la asesoría es de otra facultad', function () {
    $otra = UsuarioFactory::facultad('OTR');
    $asesoria = lsesAsesoria(
        lsesEstudiante(['facultad_id' => $otra->id]),
        lsesAsesor(['facultad_id' => $otra->id]),
    );
    lsesSesion($asesoria);

    $this->withToken(lsesToken(lsesAsesor()))
        ->getJson(lsesUrl($asesoria->id))
        ->assertNotFound();
});


// ---------- Contenido ----------

it('listar sesiones: una asesoría sin sesiones devuelve la lista vacía', function () {
    $asesor = lsesAsesor();
    $asesoria = lsesAsesoria(lsesEstudiante(), $asesor);

    $this->withToken(lsesToken($asesor))
        ->getJson(lsesUrl($asesoria->id))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('listar sesiones: cada sesión trae fecha, horario, tema, notas y quién la registró', function () {
    $asesor = lsesAsesor(['nombre' => 'Marco', 'apellido_paterno' => 'Valdez', 'apellido_materno' => 'Ríos']);
    $asesoria = lsesAsesoria(lsesEstudiante(), $asesor);
    $fecha = Carbon::now()->subWeek()->toDateString();

    $sesion = lsesSesion($asesoria, [
        'fecha' => $fecha,
        'tema' => 'Arreglos y ciclos',
        'notas' => 'Quedó pendiente el ejercicio 4',
    ]);

    $this->withToken(lsesToken($asesor))
        ->getJson(lsesUrl($asesoria->id))
        ->assertOk()
        ->assertJsonPath('data.0.id', $sesion->id)
        ->assertJsonPath('data.0.fecha', $fecha)
        ->assertJsonPath('data.0.horario.id', lsesHorario()->id)
        ->assertJsonPath('data.0.horario.etiqueta', '7:00-8:00 AM')
        ->assertJsonPath('data.0.tema', 'Arreglos y ciclos')
        ->assertJsonPath('data.0.notas', 'Quedó pendiente el ejercicio 4')
        ->assertJsonPath('data.0.registrada_por', 'Marco Valdez Ríos');
});

it('listar sesiones: tema y notas son opcionales y salen como null', function () {
    $asesor = lsesAsesor();
    $asesoria = lsesAsesoria(lsesEstudiante(), $asesor);
    lsesSesion($asesoria);

    $this->withToken(lsesToken($asesor))
        ->getJson(lsesUrl($asesoria->id))
        ->assertOk()
        ->assertJsonPath('data.0.tema', null)
        ->assertJsonPath('data.0.notas', null);
});

it('listar sesiones: ordena por fecha, la más reciente primero, y desempata por id', function () {
    $asesor = lsesAsesor();
    $asesoria = lsesAsesoria(lsesEstudiante(), $asesor);
    $otroHorario = lsesHorario('8:00-9:00 AM');

    // Se crean desordenadas a propósito
    $vieja = lsesSesion($asesoria, ['fecha' => Carbon::now()->subDays(21)->toDateString()]);
    $reciente = lsesSesion($asesoria, ['fecha' => Carbon::now()->subDays(7)->toDateString()]);
    $media = lsesSesion($asesoria, ['fecha' => Carbon::now()->subDays(14)->toDateString()]);
    // Mismo día que la reciente pero otro horario: se creó después, así que sale primero
    $mismoDia = lsesSesion($asesoria, [
        'fecha' => Carbon::now()->subDays(7)->toDateString(),
        'horario_id' => $otroHorario->id,
    ]);

    $ids = $this->withToken(lsesToken($asesor))
        ->getJson(lsesUrl($asesoria->id))
        ->assertOk()
        ->json('data.*.id');

    expect($ids)->toBe([$mismoDia->id, $reciente->id, $media->id, $vieja->id]);
});

it('listar sesiones: solo trae las de esa asesoría', function () {
    $asesor = lsesAsesor();
    $estudiante = lsesEstudiante();
    $objetivo = lsesAsesoria($estudiante, $asesor);
    $otra = lsesAsesoria($estudiante, $asesor);

    $propia = lsesSesion($objetivo);
    lsesSesion($otra);
    lsesSesion($otra, ['fecha' => Carbon::now()->subDays(10)->toDateString()]);

    $this->withToken(lsesToken($asesor))
        ->getJson(lsesUrl($objetivo->id))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $propia->id);
});

it('listar sesiones: una asesoría completada o cancelada conserva su lista de sesiones', function () {
    $asesor = lsesAsesor();
    $estudiante = lsesEstudiante();

    foreach ([EstatusAsesoria::Completada, EstatusAsesoria::Cancelada] as $cerrada) {
        $asesoria = lsesAsesoria($estudiante, $asesor, $cerrada);
        lsesSesion($asesoria);

        $this->app['auth']->forgetGuards();

        $this->withToken(lsesToken($asesor))
            ->getJson(lsesUrl($asesoria->id))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
});