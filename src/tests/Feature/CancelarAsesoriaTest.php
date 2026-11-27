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

const MOTIVO_CANA = 'El alumno ya no la necesita';


// ---------- Ayudantes ----------

function canaUrl(int $id): string
{
    return "/api/v1/asesor/asesorias/{$id}/cancelar";
}

function canaToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function canaEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create($datos);
}

// Para cancelar no hace falta disponibilidad: basta un usuario con rol asesor
function canaAsesor(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create($datos);
}

// Reutiliza el ciclo si ya existe en la prueba
function canaCiclo(): CicloEscolar
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
function canaInsertar(
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
        'ciclo_escolar_id' => canaCiclo()->id,
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

it('cancelar asesoría devuelve 401 sin sesión', function () {
    $asesoria = canaInsertar(canaEstudiante(), canaAsesor());

    $this->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => MOTIVO_CANA])
        ->assertUnauthorized();
});

it('cancelar asesoría rechaza con 403 a un estudiante puro', function () {
    $estudiante = canaEstudiante();
    $asesoria = canaInsertar($estudiante, canaAsesor());

    $this->withToken(canaToken($estudiante))
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => MOTIVO_CANA])
        ->assertForbidden();

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});

it('cancelar asesoría bloquea con 403 a un asesor con cuenta no activa', function () {
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);
    $asesoria = canaInsertar(canaEstudiante(), $asesor);

    $this->withToken(canaToken($asesor))
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => MOTIVO_CANA])
        ->assertForbidden();

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});


// ---------- Permiso por registro (policy) ----------

it('cancelar asesoría rechaza con 403 a un asesor que no es el asignado', function () {
    $asesoria = canaInsertar(canaEstudiante(), canaAsesor());

    $this->withToken(canaToken(canaAsesor()))
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => MOTIVO_CANA])
        ->assertForbidden();

    $actual = $asesoria->fresh();

    expect($actual->estatus)->toBe(EstatusAsesoria::EnCurso);
    expect($actual->motivo_cancelacion)->toBeNull();
});

it('cancelar asesoría devuelve 403 y no 422 a un asesor ajeno que olvida el motivo', function () {
    $asesoria = canaInsertar(canaEstudiante(), canaAsesor());

    // authorize() corre antes que rules(): el ajeno no llega a ver errores de validación
    $this->withToken(canaToken(canaAsesor()))
        ->postJson(canaUrl($asesoria->id), [])
        ->assertForbidden();
});

it('cancelar asesoría rechaza con 403 a un asesor par que es el estudiante de esa asesoría', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $asesoria = canaInsertar($par, canaAsesor());

    $this->withToken(canaToken($par))
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => MOTIVO_CANA])
        ->assertForbidden();

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});

it('cancelar asesoría: un asesor par la cancela cuando él es el asesor asignado', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $asesoria = canaInsertar(canaEstudiante(), $par);

    $this->withToken(canaToken($par))
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => MOTIVO_CANA])
        ->assertOk()
        ->assertJsonPath('data.estatus', 'cancelada');
});


// ---------- 404 ----------

it('cancelar asesoría devuelve 404 si no existe', function () {
    $this->withToken(canaToken(canaAsesor()))
        ->postJson(canaUrl(999999), ['motivo_cancelacion' => MOTIVO_CANA])
        ->assertNotFound();
});

it('cancelar asesoría devuelve 404 si es de otra facultad', function () {
    $otraFacultad = UsuarioFactory::facultad('OTR');
    $estudianteAjeno = canaEstudiante(['facultad_id' => $otraFacultad->id]);
    $asesorAjeno = canaAsesor(['facultad_id' => $otraFacultad->id]);
    $asesoria = canaInsertar($estudianteAjeno, $asesorAjeno);

    $this->withToken(canaToken(canaAsesor()))
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => MOTIVO_CANA])
        ->assertNotFound();

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});


// ---------- Validación del motivo ----------

it('cancelar asesoría rechaza con 422 si falta el motivo', function () {
    $asesor = canaAsesor();
    $asesoria = canaInsertar(canaEstudiante(), $asesor);

    $this->withToken(canaToken($asesor))
        ->postJson(canaUrl($asesoria->id), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo_cancelacion');

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});

it('cancelar asesoría rechaza con 422 un motivo vacío o de solo espacios', function () {
    $asesor = canaAsesor();
    $asesoria = canaInsertar(canaEstudiante(), $asesor);
    $token = canaToken($asesor);

    foreach (['', '   '] as $motivo) {
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => $motivo])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motivo_cancelacion');
    }

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});

it('cancelar asesoría rechaza con 422 un motivo que no es texto', function () {
    $asesor = canaAsesor();
    $asesoria = canaInsertar(canaEstudiante(), $asesor);

    $this->withToken(canaToken($asesor))
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => ['uno', 'dos']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo_cancelacion');
});

it('cancelar asesoría rechaza con 422 un motivo de 501 caracteres', function () {
    $asesor = canaAsesor();
    $asesoria = canaInsertar(canaEstudiante(), $asesor);

    $this->withToken(canaToken($asesor))
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => str_repeat('a', 501)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo_cancelacion');

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});

it('cancelar asesoría acepta un motivo de exactamente 500 caracteres', function () {
    $asesor = canaAsesor();
    $asesoria = canaInsertar(canaEstudiante(), $asesor);

    $this->withToken(canaToken($asesor))
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => str_repeat('a', 500)])
        ->assertOk()
        ->assertJsonPath('data.estatus', 'cancelada');
});


// ---------- Caso feliz ----------

it('cancelar asesoría: el asesor asignado la cancela y queda guardado el motivo', function () {
    $asesor = canaAsesor();
    $estudiante = canaEstudiante(['nombre' => 'Luis', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'Gámez']);
    $asesoria = canaInsertar($estudiante, $asesor);

    $this->withToken(canaToken($asesor))
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => MOTIVO_CANA])
        ->assertOk()
        ->assertJsonPath('data.id', $asesoria->id)
        ->assertJsonPath('data.estatus', 'cancelada')
        ->assertJsonPath('data.motivo_cancelacion', MOTIVO_CANA)
        ->assertJsonPath('data.finalizada_en', null)
        ->assertJsonPath('data.estudiante.nombre', 'Luis Pérez Gámez')
        ->assertJsonPath('data.sesiones_count', 0)
        ->assertJsonMissingPath('data.asesor');

    $actual = $asesoria->fresh();

    expect($actual->estatus)->toBe(EstatusAsesoria::Cancelada);
    expect($actual->motivo_cancelacion)->toBe(MOTIVO_CANA);
    // La fecha de fin es solo de las completadas
    expect($actual->finalizada_en)->toBeNull();
});

it('cancelar asesoría conserva las sesiones ya registradas', function () {
    $asesor = canaAsesor();
    $asesoria = canaInsertar(canaEstudiante(), $asesor);

    foreach (['2026-10-01', '2026-10-08'] as $fecha) {
        Sesion::create([
            'asesoria_id' => $asesoria->id,
            'fecha' => $fecha,
            'horario_id' => $asesoria->horario_id,
            'registrada_por' => $asesor->id,
        ]);
    }

    $this->withToken(canaToken($asesor))
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => MOTIVO_CANA])
        ->assertOk()
        ->assertJsonPath('data.sesiones_count', 2);

    expect(Sesion::where('asesoria_id', $asesoria->id)->count())->toBe(2);
});

it('cancelar asesoría ignora lo que mande el cliente fuera del motivo', function () {
    $asesor = canaAsesor();
    $asesoria = canaInsertar(canaEstudiante(), $asesor);

    $this->withToken(canaToken($asesor))
        ->postJson(canaUrl($asesoria->id), [
            'motivo_cancelacion' => MOTIVO_CANA,
            'estatus' => 'completada',
            'finalizada_en' => '2000-01-01 00:00:00',
        ])
        ->assertOk()
        ->assertJsonPath('data.estatus', 'cancelada')
        ->assertJsonPath('data.finalizada_en', null);

    $actual = $asesoria->fresh();

    expect($actual->estatus)->toBe(EstatusAsesoria::Cancelada);
    expect($actual->finalizada_en)->toBeNull();
});

it('cancelar asesoría no toca las demás asesorías del mismo asesor', function () {
    $asesor = canaAsesor();
    $estudiante = canaEstudiante();
    $objetivo = canaInsertar($estudiante, $asesor);
    $otra = canaInsertar($estudiante, $asesor);

    $this->withToken(canaToken($asesor))
        ->postJson(canaUrl($objetivo->id), ['motivo_cancelacion' => MOTIVO_CANA])
        ->assertOk();

    expect($objetivo->fresh()->estatus)->toBe(EstatusAsesoria::Cancelada);
    expect($otra->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
    expect($otra->fresh()->motivo_cancelacion)->toBeNull();
});

it('cancelar asesoría: el estudiante la ve en su historial con el motivo', function () {
    $asesor = canaAsesor();
    $estudiante = canaEstudiante();
    $asesoria = canaInsertar($estudiante, $asesor);

    $this->withToken(canaToken($asesor))
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => MOTIVO_CANA])
        ->assertOk();

    // Sanctum recuerda al primer usuario dentro de la misma prueba
    $this->app['auth']->forgetGuards();

    $this->withToken(canaToken($estudiante))
        ->getJson('/api/v1/estudiante/asesorias?estatus=historial')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $asesoria->id)
        ->assertJsonPath('data.0.estatus', 'cancelada')
        ->assertJsonPath('data.0.motivo_cancelacion', MOTIVO_CANA);

    $this->app['auth']->forgetGuards();

    // Ya no aparece en "En curso"
    $this->withToken(canaToken($estudiante))
        ->getJson('/api/v1/estudiante/asesorias?estatus=en_curso')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});


// ---------- Estado (409) ----------

it('cancelar asesoría rechaza con 409 una que ya está cancelada y no cambia su motivo', function () {
    $asesor = canaAsesor();
    $asesoria = canaInsertar(canaEstudiante(), $asesor, EstatusAsesoria::Cancelada, [
        'motivo_cancelacion' => 'Motivo original',
    ]);

    $this->withToken(canaToken($asesor))
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => 'Otro motivo'])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'asesoria_no_en_curso');

    expect($asesoria->fresh()->motivo_cancelacion)->toBe('Motivo original');
});

it('cancelar asesoría rechaza con 409 una que ya está completada y sigue completada', function () {
    $asesor = canaAsesor();
    $asesoria = canaInsertar(canaEstudiante(), $asesor, EstatusAsesoria::Completada, [
        'finalizada_en' => '2026-10-01 10:00:00',
    ]);

    $this->withToken(canaToken($asesor))
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => MOTIVO_CANA])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'asesoria_no_en_curso');

    $actual = $asesoria->fresh();

    expect($actual->estatus)->toBe(EstatusAsesoria::Completada);
    expect($actual->motivo_cancelacion)->toBeNull();
    expect($actual->finalizada_en->toDateTimeString())->toBe('2026-10-01 10:00:00');
});

it('cancelar asesoría: la segunda llamada seguida da 409', function () {
    $asesor = canaAsesor();
    $asesoria = canaInsertar(canaEstudiante(), $asesor);
    $token = canaToken($asesor);

    $this->withToken($token)
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => MOTIVO_CANA])
        ->assertOk();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson(canaUrl($asesoria->id), ['motivo_cancelacion' => MOTIVO_CANA])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'asesoria_no_en_curso');
});