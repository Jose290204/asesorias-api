<?php

use App\Enums\Dia;
use App\Enums\EstatusAsesoria;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Models\Asesoria;
use App\Models\CicloEscolar;
use App\Models\DisponibilidadHorario;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Sesion;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;


// ---------- Ayudantes ----------

function edasUrl(int $id): string
{
    return "/api/v1/asesor/asesorias/{$id}";
}

function edasToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function edasEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create($datos);
}

function edasAsesor(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create($datos);
}

// Reutiliza el ciclo si ya existe en la prueba: empieza hace un mes y dura un año
function edasCiclo(): CicloEscolar
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

function edasHorario(string $etiqueta = '7:00-8:00 AM'): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Próximo lunes: día de la asesoría que arman los ayudantes
function edasLunes(): Carbon
{
    return Carbon::now()->next(Carbon::MONDAY)->startOfDay();
}

// El asesor ofrece ese día + horario en su disponibilidad
function edasOfrecer(Usuario $asesor, Dia $dia, Horario $horario): DisponibilidadHorario
{
    return DisponibilidadHorario::create([
        'facultad_id' => $asesor->facultad_id,
        'asesor_id' => $asesor->id,
        'dia' => $dia,
        'horario_id' => $horario->id,
    ]);
}

// Inserta una asesoría directo en la BD (sin pasar por la API) para armar el escenario.
// Por defecto: lunes de la próxima semana, 7:00-8:00 AM, presencial, reforzamiento.
// facultad_id sale del estudiante y va explícito: al preparar datos no hay usuario
// logueado y el trait PerteneceAFacultad no lo rellena
function edasInsertar(
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

    return Asesoria::create(array_merge([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => edasCiclo()->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => edasHorario()->id,
        'fecha_inicio' => edasLunes()->toDateString(),
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => $estatus,
    ], $extra));
}


// ---------- Acceso ----------

it('editar asesoría devuelve 401 sin sesión', function () {
    $asesoria = edasInsertar(edasEstudiante(), edasAsesor());

    $this->patchJson(edasUrl($asesoria->id), ['observaciones' => 'Hola'])
        ->assertUnauthorized();
});

it('editar asesoría rechaza con 403 a un estudiante puro', function () {
    $estudiante = edasEstudiante();
    $asesoria = edasInsertar($estudiante, edasAsesor());

    $this->withToken(edasToken($estudiante))
        ->patchJson(edasUrl($asesoria->id), ['observaciones' => 'Hola'])
        ->assertForbidden();

    expect($asesoria->fresh()->observaciones)->toBeNull();
});

it('editar asesoría bloquea con 403 a un asesor con cuenta no activa', function () {
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);
    $asesoria = edasInsertar(edasEstudiante(), $asesor);

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['observaciones' => 'Hola'])
        ->assertForbidden();

    expect($asesoria->fresh()->observaciones)->toBeNull();
});


// ---------- Permiso por registro (policy) ----------

it('editar asesoría rechaza con 403 a un asesor que no es el asignado', function () {
    $asesoria = edasInsertar(edasEstudiante(), edasAsesor());

    $this->withToken(edasToken(edasAsesor()))
        ->patchJson(edasUrl($asesoria->id), ['observaciones' => 'Hola'])
        ->assertForbidden();

    expect($asesoria->fresh()->observaciones)->toBeNull();
});

it('editar asesoría devuelve 403 y no 422 a un asesor ajeno que manda datos inválidos', function () {
    $asesoria = edasInsertar(edasEstudiante(), edasAsesor());

    // authorize() corre antes que rules(): el ajeno no llega a ver errores de validación
    $this->withToken(edasToken(edasAsesor()))
        ->patchJson(edasUrl($asesoria->id), ['modalidad' => 'xyz'])
        ->assertForbidden();
});

it('editar asesoría rechaza con 403 a un asesor par que es el estudiante de esa asesoría', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $asesoria = edasInsertar($par, edasAsesor());

    $this->withToken(edasToken($par))
        ->patchJson(edasUrl($asesoria->id), ['observaciones' => 'Hola'])
        ->assertForbidden();

    expect($asesoria->fresh()->observaciones)->toBeNull();
});

it('editar asesoría: un asesor par la edita cuando él es el asesor asignado', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $asesoria = edasInsertar(edasEstudiante(), $par);

    $this->withToken(edasToken($par))
        ->patchJson(edasUrl($asesoria->id), ['observaciones' => 'Hola'])
        ->assertOk()
        ->assertJsonPath('data.observaciones', 'Hola');
});


// ---------- 404 ----------

it('editar asesoría devuelve 404 si no existe', function () {
    $this->withToken(edasToken(edasAsesor()))
        ->patchJson(edasUrl(999999), ['observaciones' => 'Hola'])
        ->assertNotFound();
});

it('editar asesoría devuelve 404 si es de otra facultad', function () {
    $otraFacultad = UsuarioFactory::facultad('OTR');
    $estudianteAjeno = edasEstudiante(['facultad_id' => $otraFacultad->id]);
    $asesorAjeno = edasAsesor(['facultad_id' => $otraFacultad->id]);
    $asesoria = edasInsertar($estudianteAjeno, $asesorAjeno);

    $this->withToken(edasToken(edasAsesor()))
        ->patchJson(edasUrl($asesoria->id), ['observaciones' => 'Hola'])
        ->assertNotFound();

    expect($asesoria->fresh()->observaciones)->toBeNull();
});


// ---------- Validación de forma (422) ----------

it('editar asesoría rechaza con 422 un body sin ningún dato editable', function () {
    $asesor = edasAsesor();
    $asesoria = edasInsertar(edasEstudiante(), $asesor);

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fecha_inicio');
});

it('editar asesoría rechaza con 422 un body que solo trae campos no editables', function () {
    $asesor = edasAsesor();
    $asesoria = edasInsertar(edasEstudiante(), $asesor);

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['estatus' => 'completada', 'materia_id' => 1])
        ->assertUnprocessable();

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});

it('editar asesoría rechaza con 422 cada campo inválido', function () {
    $asesor = edasAsesor();
    $asesoria = edasInsertar(edasEstudiante(), $asesor);
    $token = edasToken($asesor);

    $casos = [
        'horario_id' => 999999,
        'modalidad' => 'xyz',
        'razon' => 'xyz',
        'fecha_inicio' => '26/10/2026',
        'observaciones' => str_repeat('a', 1001),
    ];

    foreach ($casos as $campo => $valor) {
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->patchJson(edasUrl($asesoria->id), [$campo => $valor])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($campo);
    }

    $actual = $asesoria->fresh();

    expect($actual->modalidad)->toBe(Modalidad::Presencial);
    expect($actual->observaciones)->toBeNull();
});

it('editar asesoría rechaza con 422 un null en campos obligatorios', function () {
    $asesor = edasAsesor();
    $asesoria = edasInsertar(edasEstudiante(), $asesor);
    $token = edasToken($asesor);

    foreach (['horario_id', 'modalidad', 'razon', 'fecha_inicio'] as $campo) {
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->patchJson(edasUrl($asesoria->id), [$campo => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($campo);
    }
});

it('editar asesoría acepta observaciones de exactamente 1000 caracteres', function () {
    $asesor = edasAsesor();
    $asesoria = edasInsertar(edasEstudiante(), $asesor);

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['observaciones' => str_repeat('a', 1000)])
        ->assertOk();
});


// ---------- Caso feliz: datos que no tocan el slot ----------

it('editar asesoría: cambia las observaciones sin que el asesor ofrezca ya ese horario', function () {
    $asesor = edasAsesor();   // sin disponibilidad a propósito
    $asesoria = edasInsertar(edasEstudiante(), $asesor);

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['observaciones' => 'Traer laptop'])
        ->assertOk()
        ->assertJsonPath('data.id', $asesoria->id)
        ->assertJsonPath('data.estatus', 'en_curso')
        ->assertJsonPath('data.observaciones', 'Traer laptop')
        ->assertJsonPath('data.horario.etiqueta', '7:00-8:00 AM')
        ->assertJsonPath('data.modalidad.valor', 'presencial')
        ->assertJsonPath('data.sesiones_count', 0)
        ->assertJsonMissingPath('data.asesor');

    $actual = $asesoria->fresh();

    expect($actual->observaciones)->toBe('Traer laptop');
    expect($actual->horario_id)->toBe(edasHorario()->id);
    expect($actual->fecha_inicio->toDateString())->toBe(edasLunes()->toDateString());
});

it('editar asesoría: "observaciones": null las borra', function () {
    $asesor = edasAsesor();
    $asesoria = edasInsertar(edasEstudiante(), $asesor, EstatusAsesoria::EnCurso, ['observaciones' => 'Algo']);

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['observaciones' => null])
        ->assertOk()
        ->assertJsonPath('data.observaciones', null);

    expect($asesoria->fresh()->observaciones)->toBeNull();
});

it('editar asesoría: cambia modalidad y razón', function () {
    $asesor = edasAsesor();
    $asesoria = edasInsertar(edasEstudiante(), $asesor);

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['modalidad' => 'virtual', 'razon' => 'parcial'])
        ->assertOk()
        ->assertJsonPath('data.modalidad.valor', 'virtual')
        ->assertJsonPath('data.razon.valor', 'parcial');

    $actual = $asesoria->fresh();

    expect($actual->modalidad)->toBe(Modalidad::Virtual);
    expect($actual->razon)->toBe(RazonAsesoria::Parcial);
});

it('editar asesoría: mandar los mismos valores que ya tiene responde 200 sin revalidar el slot', function () {
    $asesor = edasAsesor();   // sin disponibilidad: si revalidara el slot, fallaría
    $asesoria = edasInsertar(edasEstudiante(), $asesor);

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), [
            'fecha_inicio' => edasLunes()->toDateString(),
            'horario_id' => edasHorario()->id,
        ])
        ->assertOk();
});

it('editar asesoría: mover la fecha a otra semana con el mismo día no revalida el horario', function () {
    $asesor = edasAsesor();   // sin disponibilidad
    $asesoria = edasInsertar(edasEstudiante(), $asesor);
    $nueva = edasLunes()->addWeek()->toDateString();

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['fecha_inicio' => $nueva])
        ->assertOk()
        ->assertJsonPath('data.fecha_inicio', $nueva);

    expect($asesoria->fresh()->fecha_inicio->toDateString())->toBe($nueva);
});

it('editar asesoría permite poner una fecha pasada dentro del ciclo', function () {
    $asesor = edasAsesor();
    $asesoria = edasInsertar(edasEstudiante(), $asesor);
    // Lunes de hace dos semanas: sigue dentro del ciclo (empezó hace un mes)
    $pasada = Carbon::now()->previous(Carbon::MONDAY)->toDateString();

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['fecha_inicio' => $pasada])
        ->assertOk()
        ->assertJsonPath('data.fecha_inicio', $pasada);
});

it('editar asesoría ignora los campos que no son editables', function () {
    $asesor = edasAsesor();
    $estudiante = edasEstudiante();
    $otro = edasEstudiante();
    $asesoria = edasInsertar($estudiante, $asesor);
    $materiaOriginal = $asesoria->materia_id;

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), [
            'observaciones' => 'Solo esto cambia',
            'estatus' => 'completada',
            'estudiante_id' => $otro->id,
            'asesor_id' => $otro->id,
            'materia_id' => 1,
            'motivo_cancelacion' => 'colado',
            'finalizada_en' => '2000-01-01 00:00:00',
        ])
        ->assertOk()
        ->assertJsonPath('data.estatus', 'en_curso');

    $actual = $asesoria->fresh();

    expect($actual->observaciones)->toBe('Solo esto cambia');
    expect($actual->estatus)->toBe(EstatusAsesoria::EnCurso);
    expect($actual->estudiante_id)->toBe($estudiante->id);
    expect($actual->asesor_id)->toBe($asesor->id);
    expect($actual->materia_id)->toBe($materiaOriginal);
    expect($actual->motivo_cancelacion)->toBeNull();
    expect($actual->finalizada_en)->toBeNull();
});

it('editar asesoría no toca las demás asesorías del mismo asesor', function () {
    $asesor = edasAsesor();
    $estudiante = edasEstudiante();
    $objetivo = edasInsertar($estudiante, $asesor);
    $otra = edasInsertar($estudiante, $asesor);

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($objetivo->id), ['observaciones' => 'Solo a esta'])
        ->assertOk();

    expect($objetivo->fresh()->observaciones)->toBe('Solo a esta');
    expect($otra->fresh()->observaciones)->toBeNull();
});


// ---------- Cambio de slot: horario y día ----------

it('editar asesoría: cambia de horario cuando el asesor lo ofrece ese día y está libre', function () {
    $asesor = edasAsesor();
    $nuevo = edasHorario('8:00-9:00 AM');
    edasOfrecer($asesor, Dia::Lunes, $nuevo);
    $asesoria = edasInsertar(edasEstudiante(), $asesor);

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['horario_id' => $nuevo->id])
        ->assertOk()
        ->assertJsonPath('data.horario.id', $nuevo->id)
        ->assertJsonPath('data.horario.etiqueta', '8:00-9:00 AM');

    expect($asesoria->fresh()->horario_id)->toBe($nuevo->id);
});

it('editar asesoría: cambia de día de la semana cuando el asesor ofrece ese día + horario', function () {
    $asesor = edasAsesor();
    edasOfrecer($asesor, Dia::Martes, edasHorario());
    $asesoria = edasInsertar(edasEstudiante(), $asesor);
    $martes = edasLunes()->addDay()->toDateString();

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['fecha_inicio' => $martes])
        ->assertOk()
        ->assertJsonPath('data.fecha_inicio', $martes);

    expect($asesoria->fresh()->fecha_inicio->toDateString())->toBe($martes);
});

it('editar asesoría: cambia fecha y horario a la vez cuando el nuevo slot es válido', function () {
    $asesor = edasAsesor();
    $nuevo = edasHorario('9:00-10:00 AM');
    edasOfrecer($asesor, Dia::Miercoles, $nuevo);
    $asesoria = edasInsertar(edasEstudiante(), $asesor);
    $miercoles = edasLunes()->addDays(2)->toDateString();

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['fecha_inicio' => $miercoles, 'horario_id' => $nuevo->id])
        ->assertOk();

    $actual = $asesoria->fresh();

    expect($actual->fecha_inicio->toDateString())->toBe($miercoles);
    expect($actual->horario_id)->toBe($nuevo->id);
});

it('editar asesoría rechaza con 422 un horario que el asesor no ofrece', function () {
    $asesor = edasAsesor();
    $noOfrecido = edasHorario('10:00-11:00 AM');
    $asesoria = edasInsertar(edasEstudiante(), $asesor);

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['horario_id' => $noOfrecido->id])
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'horario_no_disponible');

    expect($asesoria->fresh()->horario_id)->toBe(edasHorario()->id);
});

it('editar asesoría rechaza con 422 un horario que el asesor ofrece, pero otro día', function () {
    $asesor = edasAsesor();
    // Ofrece 7:00 los lunes, pero no los martes
    edasOfrecer($asesor, Dia::Lunes, edasHorario());
    $asesoria = edasInsertar(edasEstudiante(), $asesor);
    $martes = edasLunes()->addDay()->toDateString();

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['fecha_inicio' => $martes])
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'horario_no_disponible');

    expect($asesoria->fresh()->fecha_inicio->toDateString())->toBe(edasLunes()->toDateString());
});

it('editar asesoría rechaza con 422 una fecha en fin de semana', function () {
    $asesor = edasAsesor();
    $asesoria = edasInsertar(edasEstudiante(), $asesor);
    $sabado = edasLunes()->addDays(5)->toDateString();

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['fecha_inicio' => $sabado])
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'dia_no_disponible');

    expect($asesoria->fresh()->fecha_inicio->toDateString())->toBe(edasLunes()->toDateString());
});

it('editar asesoría rechaza con 409 un slot ocupado por otra asesoría en curso del mismo asesor', function () {
    $asesor = edasAsesor();
    $nuevo = edasHorario('8:00-9:00 AM');
    edasOfrecer($asesor, Dia::Lunes, $nuevo);

    // Otra asesoría en curso del asesor: lunes 8:00 (el slot al que se quiere mover)
    edasInsertar(edasEstudiante(), $asesor, EstatusAsesoria::EnCurso, [
        'horario_id' => $nuevo->id,
        'fecha_inicio' => edasLunes()->addWeek()->toDateString(),   // otro lunes: mismo día de la semana
    ]);

    $asesoria = edasInsertar(edasEstudiante(), $asesor);

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['horario_id' => $nuevo->id])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'horario_ocupado');

    expect($asesoria->fresh()->horario_id)->toBe(edasHorario()->id);
});

it('editar asesoría: un slot ocupado por una asesoría completada o cancelada sí se puede usar', function () {
    $asesor = edasAsesor();
    $nuevo = edasHorario('8:00-9:00 AM');
    edasOfrecer($asesor, Dia::Lunes, $nuevo);

    foreach ([EstatusAsesoria::Completada, EstatusAsesoria::Cancelada] as $cerrada) {
        edasInsertar(edasEstudiante(), $asesor, $cerrada, ['horario_id' => $nuevo->id]);
    }

    $asesoria = edasInsertar(edasEstudiante(), $asesor);

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['horario_id' => $nuevo->id])
        ->assertOk()
        ->assertJsonPath('data.horario.id', $nuevo->id);
});

it('editar asesoría: el slot ocupado por la asesoría de OTRO asesor no estorba', function () {
    $asesor = edasAsesor();
    $otroAsesor = edasAsesor();
    $nuevo = edasHorario('8:00-9:00 AM');
    edasOfrecer($asesor, Dia::Lunes, $nuevo);

    edasInsertar(edasEstudiante(), $otroAsesor, EstatusAsesoria::EnCurso, ['horario_id' => $nuevo->id]);

    $asesoria = edasInsertar(edasEstudiante(), $asesor);

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['horario_id' => $nuevo->id])
        ->assertOk();
});


// ---------- Fecha de inicio: ciclo y sesiones ----------

it('editar asesoría rechaza con 422 una fecha después del fin del ciclo', function () {
    $asesor = edasAsesor();
    $asesoria = edasInsertar(edasEstudiante(), $asesor);
    $despues = edasCiclo()->fecha_fin->copy()->addDay()->toDateString();

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['fecha_inicio' => $despues])
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'fuera_de_ciclo');

    expect($asesoria->fresh()->fecha_inicio->toDateString())->toBe(edasLunes()->toDateString());
});

it('editar asesoría rechaza con 422 una fecha antes del inicio del ciclo', function () {
    $asesor = edasAsesor();
    $asesoria = edasInsertar(edasEstudiante(), $asesor);
    $antes = edasCiclo()->fecha_inicio->copy()->subDay()->toDateString();

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['fecha_inicio' => $antes])
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'fuera_de_ciclo');
});

it('editar asesoría rechaza con 422 una fecha posterior a la primera sesión registrada', function () {
    $asesor = edasAsesor();
    $asesoria = edasInsertar(edasEstudiante(), $asesor);

    // Primera sesión: dos semanas después de la fecha de inicio
    Sesion::create([
        'asesoria_id' => $asesoria->id,
        'fecha' => edasLunes()->addWeeks(2)->toDateString(),
        'horario_id' => $asesoria->horario_id,
        'registrada_por' => $asesor->id,
    ]);

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['fecha_inicio' => edasLunes()->addWeeks(3)->toDateString()])
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'fecha_posterior_a_sesiones');

    expect($asesoria->fresh()->fecha_inicio->toDateString())->toBe(edasLunes()->toDateString());
});

it('editar asesoría acepta una fecha igual o anterior a la primera sesión registrada', function () {
    $asesor = edasAsesor();
    $asesoria = edasInsertar(edasEstudiante(), $asesor);
    $sesion = edasLunes()->addWeeks(2);

    Sesion::create([
        'asesoria_id' => $asesoria->id,
        'fecha' => $sesion->toDateString(),
        'horario_id' => $asesoria->horario_id,
        'registrada_por' => $asesor->id,
    ]);

    $token = edasToken($asesor);

    // Igual a la primera sesión
    $this->withToken($token)
        ->patchJson(edasUrl($asesoria->id), ['fecha_inicio' => $sesion->toDateString()])
        ->assertOk();

    $this->app['auth']->forgetGuards();

    // Anterior a la primera sesión
    $this->withToken($token)
        ->patchJson(edasUrl($asesoria->id), ['fecha_inicio' => edasLunes()->addWeek()->toDateString()])
        ->assertOk()
        ->assertJsonPath('data.sesiones_count', 1);
});


// ---------- Estado (409) ----------

it('editar asesoría rechaza con 409 una que ya está completada o cancelada y no la modifica', function () {
    $asesor = edasAsesor();
    $estudiante = edasEstudiante();
    $token = edasToken($asesor);

    foreach ([EstatusAsesoria::Completada, EstatusAsesoria::Cancelada] as $cerrada) {
        $asesoria = edasInsertar($estudiante, $asesor, $cerrada);

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->patchJson(edasUrl($asesoria->id), ['observaciones' => 'Intento tardío'])
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'asesoria_no_en_curso');

        expect($asesoria->fresh()->observaciones)->toBeNull();
        expect($asesoria->fresh()->estatus)->toBe($cerrada);
    }
});

it('editar asesoría devuelve 409 y no el error de horario si la asesoría ya no está en curso', function () {
    $asesor = edasAsesor();   // sin disponibilidad: el horario nuevo sería inválido
    $asesoria = edasInsertar(edasEstudiante(), $asesor, EstatusAsesoria::Cancelada);
    $otro = edasHorario('11:00-12:00 PM');

    $this->withToken(edasToken($asesor))
        ->patchJson(edasUrl($asesoria->id), ['horario_id' => $otro->id])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'asesoria_no_en_curso');
});