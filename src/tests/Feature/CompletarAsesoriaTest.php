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

function compUrl(int $id): string
{
    return "/api/v1/asesor/asesorias/{$id}/completar";
}

function compToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function compEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create($datos);
}

// Para completar no hace falta disponibilidad: basta un usuario con rol asesor
function compAsesor(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create($datos);
}

// Reutiliza el ciclo si ya existe en la prueba
function compCiclo(): CicloEscolar
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

// Inserta una asesoría directo en la BD (sin pasar por la API) para armar el escenario.
// facultad_id sale del estudiante y va explícito: al preparar datos no hay usuario
// logueado y el trait PerteneceAFacultad no lo rellena
function compInsertar(
    Usuario $estudiante,
    Usuario $asesor,
    EstatusAsesoria $estatus = EstatusAsesoria::EnCurso,
    array $extra = [],
): Asesoria {
    $lic = Licenciatura::firstOrCreate([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => 'LICENCIATURA PRUEBA',
    ]);

    $grupo = Grupo::firstOrCreate(
        ['licenciatura_id' => $lic->id, 'nombre' => '3-1'],
        ['anio' => 3]
    );

    $materia = Materia::firstOrCreate(
        ['licenciatura_id' => $lic->id, 'nombre' => 'Estructura de Datos'],
        ['semestre' => 1]
    );

    $horario = Horario::firstOrCreate(['etiqueta' => '7:00-8:00 AM']);

    return Asesoria::create(array_merge([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => compCiclo()->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => $horario->id,
        'fecha_inicio' => Carbon::now()->next(Carbon::MONDAY)->toDateString(),
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => $estatus,
    ], $extra));
}


// ---------- Acceso ----------

it('completar asesoría devuelve 401 sin sesión', function () {
    $asesoria = compInsertar(compEstudiante(), compAsesor());

    $this->postJson(compUrl($asesoria->id))->assertUnauthorized();
});

it('completar asesoría rechaza con 403 a un estudiante puro', function () {
    $estudiante = compEstudiante();
    $asesoria = compInsertar($estudiante, compAsesor());

    $this->withToken(compToken($estudiante))
        ->postJson(compUrl($asesoria->id))
        ->assertForbidden();

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});

it('completar asesoría bloquea con 403 a un asesor con cuenta no activa', function () {
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);
    $asesoria = compInsertar(compEstudiante(), $asesor);

    $this->withToken(compToken($asesor))
        ->postJson(compUrl($asesoria->id))
        ->assertForbidden();

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});


// ---------- Permiso por registro (policy) ----------

it('completar asesoría rechaza con 403 a un asesor que no es el asignado', function () {
    $asesoria = compInsertar(compEstudiante(), compAsesor());

    $this->withToken(compToken(compAsesor()))
        ->postJson(compUrl($asesoria->id))
        ->assertForbidden();

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});

it('completar asesoría rechaza con 403 a un asesor par que es el estudiante de esa asesoría', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $asesoria = compInsertar($par, compAsesor());

    $this->withToken(compToken($par))
        ->postJson(compUrl($asesoria->id))
        ->assertForbidden();

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});

it('completar asesoría: un asesor par la completa cuando él es el asesor asignado', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $asesoria = compInsertar(compEstudiante(), $par);

    $this->withToken(compToken($par))
        ->postJson(compUrl($asesoria->id))
        ->assertOk()
        ->assertJsonPath('data.estatus', 'completada');
});

it('completar asesoría devuelve 403 antes que 409 a quien no es el asesor, aunque ya esté completada', function () {
    $asesoria = compInsertar(compEstudiante(), compAsesor(), EstatusAsesoria::Completada);

    $this->withToken(compToken(compAsesor()))
        ->postJson(compUrl($asesoria->id))
        ->assertForbidden();
});


// ---------- 404 ----------

it('completar asesoría devuelve 404 si no existe', function () {
    $this->withToken(compToken(compAsesor()))
        ->postJson(compUrl(999999))
        ->assertNotFound();
});

it('completar asesoría devuelve 404 si es de otra facultad', function () {
    $otraFacultad = UsuarioFactory::facultad('OTR');
    $estudianteAjeno = compEstudiante(['facultad_id' => $otraFacultad->id]);
    $asesorAjeno = compAsesor(['facultad_id' => $otraFacultad->id]);
    $asesoria = compInsertar($estudianteAjeno, $asesorAjeno);

    $this->withToken(compToken(compAsesor()))
        ->postJson(compUrl($asesoria->id))
        ->assertNotFound();

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});


// ---------- Caso feliz ----------

it('completar asesoría: el asesor asignado la completa y queda registrada la fecha de fin', function () {
    $asesor = compAsesor();
    $estudiante = compEstudiante(['nombre' => 'Luis', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'Gámez']);
    $asesoria = compInsertar($estudiante, $asesor);

    expect($asesoria->finalizada_en)->toBeNull();

    $this->withToken(compToken($asesor))
        ->postJson(compUrl($asesoria->id))
        ->assertOk()
        ->assertJsonPath('data.id', $asesoria->id)
        ->assertJsonPath('data.estatus', 'completada')
        ->assertJsonPath('data.estudiante.nombre', 'Luis Pérez Gámez')
        ->assertJsonPath('data.motivo_cancelacion', null)
        ->assertJsonPath('data.sesiones_count', 0)
        ->assertJsonMissingPath('data.asesor');

    $actual = $asesoria->fresh();

    expect($actual->estatus)->toBe(EstatusAsesoria::Completada);
    expect($actual->finalizada_en)->not->toBeNull();
});

it('completar asesoría se puede hacer con sesiones registradas y las cuenta en la respuesta', function () {
    $asesor = compAsesor();
    $asesoria = compInsertar(compEstudiante(), $asesor);

    foreach (['2026-10-01', '2026-10-08'] as $fecha) {
        Sesion::create([
            'asesoria_id' => $asesoria->id,
            'fecha' => $fecha,
            'horario_id' => $asesoria->horario_id,
            'registrada_por' => $asesor->id,
        ]);
    }

    $this->withToken(compToken($asesor))
        ->postJson(compUrl($asesoria->id))
        ->assertOk()
        ->assertJsonPath('data.estatus', 'completada')
        ->assertJsonPath('data.sesiones_count', 2);
});

it('completar asesoría ignora lo que mande el cliente en el body', function () {
    $asesor = compAsesor();
    $asesoria = compInsertar(compEstudiante(), $asesor);

    $this->withToken(compToken($asesor))
        ->postJson(compUrl($asesoria->id), [
            'estatus' => 'cancelada',
            'motivo_cancelacion' => 'intento de colarlo',
            'finalizada_en' => '2000-01-01 00:00:00',
        ])
        ->assertOk()
        ->assertJsonPath('data.estatus', 'completada')
        ->assertJsonPath('data.motivo_cancelacion', null);

    $actual = $asesoria->fresh();

    expect($actual->estatus)->toBe(EstatusAsesoria::Completada);
    expect($actual->motivo_cancelacion)->toBeNull();
    expect($actual->finalizada_en->year)->not->toBe(2000);
});

it('completar asesoría no toca las demás asesorías del mismo asesor', function () {
    $asesor = compAsesor();
    $estudiante = compEstudiante();
    $objetivo = compInsertar($estudiante, $asesor);
    $otra = compInsertar($estudiante, $asesor);

    $this->withToken(compToken($asesor))
        ->postJson(compUrl($objetivo->id))
        ->assertOk();

    expect($objetivo->fresh()->estatus)->toBe(EstatusAsesoria::Completada);
    expect($otra->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
    expect($otra->fresh()->finalizada_en)->toBeNull();
});


// ---------- Estado (409) ----------

it('completar asesoría rechaza con 409 una que ya está completada y no cambia su fecha de fin', function () {
    $asesor = compAsesor();
    $asesoria = compInsertar(compEstudiante(), $asesor, EstatusAsesoria::Completada, [
        'finalizada_en' => '2026-10-01 10:00:00',
    ]);

    $this->withToken(compToken($asesor))
        ->postJson(compUrl($asesoria->id))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'asesoria_no_en_curso');

    expect($asesoria->fresh()->finalizada_en->toDateTimeString())->toBe('2026-10-01 10:00:00');
});

it('completar asesoría rechaza con 409 una que está cancelada y sigue cancelada', function () {
    $asesor = compAsesor();
    $asesoria = compInsertar(compEstudiante(), $asesor, EstatusAsesoria::Cancelada, [
        'motivo_cancelacion' => 'El alumno ya no la necesita',
    ]);

    $this->withToken(compToken($asesor))
        ->postJson(compUrl($asesoria->id))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'asesoria_no_en_curso');

    $actual = $asesoria->fresh();

    expect($actual->estatus)->toBe(EstatusAsesoria::Cancelada);
    expect($actual->motivo_cancelacion)->toBe('El alumno ya no la necesita');
    expect($actual->finalizada_en)->toBeNull();
});

it('completar asesoría: la segunda llamada seguida da 409', function () {
    $asesor = compAsesor();
    $asesoria = compInsertar(compEstudiante(), $asesor);
    $token = compToken($asesor);

    $this->withToken($token)->postJson(compUrl($asesoria->id))->assertOk();

    // Sanctum recuerda al primer usuario dentro de la misma prueba
    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson(compUrl($asesoria->id))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'asesoria_no_en_curso');
});