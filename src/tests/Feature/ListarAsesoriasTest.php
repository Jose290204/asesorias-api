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

const URL_LASE_ESTUDIANTE = '/api/v1/estudiante/asesorias';
const URL_LASE_ASESOR = '/api/v1/asesor/asesorias';


// ---------- Ayudantes ----------

function laseToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function laseEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create($datos);
}

// Para listar no hace falta disponibilidad: basta un usuario con rol asesor
function laseAsesor(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create($datos);
}

// Reutiliza el ciclo si ya existe en la prueba
function laseCiclo(): CicloEscolar
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
// facultad_id va explícito: al preparar datos no hay usuario logueado y el trait
// PerteneceAFacultad no lo rellena
function laseInsertar(
    Usuario $estudiante,
    Usuario $asesor,
    EstatusAsesoria $estatus = EstatusAsesoria::EnCurso,
    array $extra = [],
    string $materiaNombre = 'Estructura de Datos',
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
        ['licenciatura_id' => $lic->id, 'nombre' => $materiaNombre],
        ['semestre' => 1]
    );

    $horario = Horario::firstOrCreate(['etiqueta' => '7:00-8:00 AM']);

    return Asesoria::create(array_merge([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => laseCiclo()->id,
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

it('listar asesorías devuelve 401 sin sesión en ambas rutas', function () {
    $this->getJson(URL_LASE_ESTUDIANTE)->assertUnauthorized();
    $this->getJson(URL_LASE_ASESOR)->assertUnauthorized();
});

it('listar asesorías rechaza con 403 a un asesor puro en la ruta del estudiante', function () {
    $this->withToken(laseToken(laseAsesor()))
        ->getJson(URL_LASE_ESTUDIANTE)
        ->assertForbidden();
});

it('listar asesorías rechaza con 403 a un estudiante puro en la ruta del asesor', function () {
    $this->withToken(laseToken(laseEstudiante()))
        ->getJson(URL_LASE_ASESOR)
        ->assertForbidden();
});

it('listar asesorías bloquea con 403 a un estudiante con cuenta no activa', function () {
    $estudiante = Usuario::factory()->pendiente()->conRol(Rol::Estudiante)->create(['password' => 'secreto123']);

    $this->withToken(laseToken($estudiante))
        ->getJson(URL_LASE_ESTUDIANTE)
        ->assertForbidden();
});


// ---------- Visibilidad y forma de la respuesta ----------

it('listar asesorías: el estudiante ve solo las suyas, con el asesor y sin el campo estudiante', function () {
    $asesor = laseAsesor();
    $yo = laseEstudiante();
    $otro = laseEstudiante();

    $mia = laseInsertar($yo, $asesor);
    laseInsertar($otro, $asesor);

    $this->withToken(laseToken($yo))
        ->getJson(URL_LASE_ESTUDIANTE)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mia->id)
        ->assertJsonPath('data.0.estatus', 'en_curso')
        ->assertJsonPath('data.0.asesor.id', $asesor->id)
        ->assertJsonPath('data.0.materia.nombre', 'Estructura de Datos')
        ->assertJsonPath('data.0.horario.etiqueta', '7:00-8:00 AM')
        ->assertJsonPath('data.0.modalidad.valor', 'presencial')
        ->assertJsonPath('data.0.razon.valor', 'reforzamiento')
        ->assertJsonPath('data.0.motivo_cancelacion', null)
        ->assertJsonPath('data.0.finalizada_en', null)
        ->assertJsonPath('data.0.sesiones_count', 0)
        ->assertJsonMissingPath('data.0.estudiante');
});

it('listar asesorías: el asesor ve solo las suyas, con el estudiante y sin el campo asesor', function () {
    $yo = laseAsesor();
    $otroAsesor = laseAsesor();
    $estudiante = laseEstudiante();

    $mia = laseInsertar($estudiante, $yo);
    laseInsertar($estudiante, $otroAsesor);

    $this->withToken(laseToken($yo))
        ->getJson(URL_LASE_ASESOR)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mia->id)
        ->assertJsonPath('data.0.estudiante.id', $estudiante->id)
        ->assertJsonMissingPath('data.0.asesor');
});

it('listar asesorías: un usuario sin asesorías recibe la lista vacía', function () {
    $this->withToken(laseToken(laseEstudiante()))
        ->getJson(URL_LASE_ESTUDIANTE)
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

it('listar asesorías: un asesor par ve en cada ruta solo lo que le corresponde en ese rol', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $otroAsesor = laseAsesor();
    $otroEstudiante = laseEstudiante();

    $comoEstudiante = laseInsertar($par, $otroAsesor);
    $comoAsesor = laseInsertar($otroEstudiante, $par);

    $token = laseToken($par);

    $this->withToken($token)
        ->getJson(URL_LASE_ESTUDIANTE)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $comoEstudiante->id);

    // Sanctum recuerda al primer usuario dentro de la misma prueba
    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson(URL_LASE_ASESOR)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $comoAsesor->id);
});

it('listar asesorías: una cancelada muestra su motivo de cancelación', function () {
    $asesor = laseAsesor();
    $estudiante = laseEstudiante();

    laseInsertar($estudiante, $asesor, EstatusAsesoria::Cancelada, [
        'motivo_cancelacion' => 'El alumno ya no la necesita',
    ]);

    $this->withToken(laseToken($estudiante))
        ->getJson(URL_LASE_ESTUDIANTE)
        ->assertOk()
        ->assertJsonPath('data.0.estatus', 'cancelada')
        ->assertJsonPath('data.0.motivo_cancelacion', 'El alumno ya no la necesita');
});

it('listar asesorías: sesiones_count cuenta las sesiones registradas', function () {
    $asesor = laseAsesor();
    $estudiante = laseEstudiante();
    $asesoria = laseInsertar($estudiante, $asesor);

    foreach (['2026-10-01', '2026-10-08'] as $fecha) {
        Sesion::create([
            'asesoria_id' => $asesoria->id,
            'fecha' => $fecha,
            'horario_id' => $asesoria->horario_id,
            'registrada_por' => $asesor->id,
        ]);
    }

    $this->withToken(laseToken($asesor))
        ->getJson(URL_LASE_ASESOR)
        ->assertOk()
        ->assertJsonPath('data.0.sesiones_count', 2);
});


// ---------- Filtro por estatus ----------

it('listar asesorías: ?estatus= filtra por un estatus concreto', function () {
    $asesor = laseAsesor();
    $estudiante = laseEstudiante();

    $enCurso = laseInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso);
    laseInsertar($estudiante, $asesor, EstatusAsesoria::Completada);
    laseInsertar($estudiante, $asesor, EstatusAsesoria::Cancelada);

    $this->withToken(laseToken($estudiante))
        ->getJson(URL_LASE_ESTUDIANTE.'?estatus=en_curso')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $enCurso->id);

    $this->withToken(laseToken($estudiante))
        ->getJson(URL_LASE_ESTUDIANTE.'?estatus=completada')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.estatus', 'completada');
});

it('listar asesorías: ?estatus=historial trae las completadas y las canceladas', function () {
    $asesor = laseAsesor();
    $estudiante = laseEstudiante();

    laseInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso);
    laseInsertar($estudiante, $asesor, EstatusAsesoria::Completada);
    laseInsertar($estudiante, $asesor, EstatusAsesoria::Cancelada);

    $respuesta = $this->withToken(laseToken($estudiante))
        ->getJson(URL_LASE_ESTUDIANTE.'?estatus=historial')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(collect($respuesta->json('data'))->pluck('estatus')->sort()->values()->all())
        ->toBe(['cancelada', 'completada']);
});

it('listar asesorías: sin ?estatus= trae todas', function () {
    $asesor = laseAsesor();
    $estudiante = laseEstudiante();

    laseInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso);
    laseInsertar($estudiante, $asesor, EstatusAsesoria::Completada);
    laseInsertar($estudiante, $asesor, EstatusAsesoria::Cancelada);

    $this->withToken(laseToken($estudiante))
        ->getJson(URL_LASE_ESTUDIANTE)
        ->assertOk()
        ->assertJsonCount(3, 'data');
});


// ---------- Búsqueda ----------

it('listar asesorías: ?buscar= encuentra por nombre de la materia', function () {
    $asesor = laseAsesor();
    $estudiante = laseEstudiante();

    laseInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso, [], 'Estructura de Datos');
    $calculo = laseInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso, [], 'Cálculo Diferencial');

    $this->withToken(laseToken($estudiante))
        ->getJson(URL_LASE_ESTUDIANTE.'?buscar=Diferencial')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $calculo->id);
});

it('listar asesorías: el estudiante busca por nombre del asesor', function () {
    $patricia = laseAsesor(['nombre' => 'Patricia', 'apellido_paterno' => 'Rentería', 'apellido_materno' => 'Beltrán']);
    $otro = laseAsesor(['nombre' => 'Roberto', 'apellido_paterno' => 'Salas', 'apellido_materno' => 'Lugo']);
    $estudiante = laseEstudiante();

    $deLaPatricia = laseInsertar($estudiante, $patricia);
    laseInsertar($estudiante, $otro);

    $this->withToken(laseToken($estudiante))
        ->getJson(URL_LASE_ESTUDIANTE.'?buscar=Patricia')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $deLaPatricia->id);
});

it('listar asesorías: el asesor busca por nombre del alumno', function () {
    $asesor = laseAsesor();
    $luis = laseEstudiante(['nombre' => 'Luis', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'Gámez']);
    $ana = laseEstudiante(['nombre' => 'Ana', 'apellido_paterno' => 'Soto', 'apellido_materno' => 'Reyes']);

    $deLuis = laseInsertar($luis, $asesor);
    laseInsertar($ana, $asesor);

    $this->withToken(laseToken($asesor))
        ->getJson(URL_LASE_ASESOR.'?buscar=Pérez Gámez')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $deLuis->id);
});

it('listar asesorías: ?buscar= sin coincidencias devuelve la lista vacía', function () {
    $estudiante = laseEstudiante();
    laseInsertar($estudiante, laseAsesor());

    $this->withToken(laseToken($estudiante))
        ->getJson(URL_LASE_ESTUDIANTE.'?buscar=ZZZNoExiste')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('listar asesorías: ?buscar= no deja ver asesorías de otro estudiante aunque coincidan', function () {
    $asesor = laseAsesor();
    $yo = laseEstudiante();
    $otro = laseEstudiante();

    laseInsertar($yo, $asesor, EstatusAsesoria::EnCurso, [], 'Estructura de Datos');
    // Misma materia, pero de otro estudiante: no debe aparecer
    laseInsertar($otro, $asesor, EstatusAsesoria::EnCurso, [], 'Estructura de Datos');

    $this->withToken(laseToken($yo))
        ->getJson(URL_LASE_ESTUDIANTE.'?buscar=Estructura')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('listar asesorías: ?buscar= se combina con ?estatus=', function () {
    $asesor = laseAsesor();
    $estudiante = laseEstudiante();

    laseInsertar($estudiante, $asesor, EstatusAsesoria::Completada, [], 'Estructura de Datos');
    $enCurso = laseInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso, [], 'Estructura de Datos');

    $this->withToken(laseToken($estudiante))
        ->getJson(URL_LASE_ESTUDIANTE.'?buscar=Estructura&estatus=en_curso')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $enCurso->id);
});


// ---------- Validación ----------

it('listar asesorías rechaza con 422 un estatus inválido', function () {
    $this->withToken(laseToken(laseEstudiante()))
        ->getJson(URL_LASE_ESTUDIANTE.'?estatus=xyz')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('estatus');
});

it('listar asesorías rechaza con 422 un buscar de más de 100 caracteres y acepta uno de 100', function () {
    $token = laseToken(laseEstudiante());

    $this->withToken($token)
        ->getJson(URL_LASE_ESTUDIANTE.'?buscar='.str_repeat('a', 101))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('buscar');

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson(URL_LASE_ESTUDIANTE.'?buscar='.str_repeat('a', 100))
        ->assertOk();
});


// ---------- Orden y paginación ----------

it('listar asesorías: ordena por fecha de inicio, la más reciente primero', function () {
    $asesor = laseAsesor();
    $estudiante = laseEstudiante();

    $vieja = laseInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso, ['fecha_inicio' => '2026-09-07']);
    $nueva = laseInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso, ['fecha_inicio' => '2026-11-02']);

    $this->withToken(laseToken($estudiante))
        ->getJson(URL_LASE_ESTUDIANTE)
        ->assertOk()
        ->assertJsonPath('data.0.id', $nueva->id)
        ->assertJsonPath('data.1.id', $vieja->id);
});

it('listar asesorías: pagina de 10 en 10', function () {
    $asesor = laseAsesor();
    $estudiante = laseEstudiante();

    for ($i = 0; $i < 11; $i++) {
        laseInsertar($estudiante, $asesor);
    }

    $token = laseToken($estudiante);

    $this->withToken($token)
        ->getJson(URL_LASE_ESTUDIANTE)
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 11)
        ->assertJsonPath('meta.last_page', 2);

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson(URL_LASE_ESTUDIANTE.'?page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});