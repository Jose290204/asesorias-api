<?php

use App\Enums\Dia;
use App\Enums\EstatusAsesoria;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Models\Asesoria;
use App\Models\CicloEscolar;
use App\Models\DisponibilidadHorario;
use App\Models\DisponibilidadMateria;
use App\Models\DisponibilidadModalidad;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;

const URL_CREA = '/api/v1/asesor/asesorias';


// ---------- Ayudantes ----------

function creaToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function creaCiclo(): CicloEscolar
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

function creaLicenciatura(): Licenciatura
{
    return Licenciatura::firstOrCreate([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => 'LICENCIATURA PRUEBA',
    ]);
}

function creaGrupo(): Grupo
{
    return Grupo::firstOrCreate(
        ['licenciatura_id' => creaLicenciatura()->id, 'nombre' => '3-1'],
        ['anio' => 3]
    );
}

function creaMateria(string $nombre = 'Estructura de Datos'): Materia
{
    return Materia::firstOrCreate(
        ['licenciatura_id' => creaLicenciatura()->id, 'nombre' => $nombre],
        ['semestre' => 1]
    );
}

function creaHorario(string $etiqueta = '7:00-8:00 AM'): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Próximo lunes: día base de las pruebas
function creaLunes(): Carbon
{
    return Carbon::now()->next(Carbon::MONDAY)->startOfDay();
}

function creaEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create(array_merge(
        ['grupo_id' => creaGrupo()->id],
        $datos
    ));
}

// Asesor que ofrece: la materia "Estructura de Datos", modalidad presencial y lunes 7:00-8:00 AM
function creaAsesor(array $datos = []): Usuario
{
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create($datos);

    DisponibilidadMateria::create([
        'facultad_id' => $asesor->facultad_id,
        'asesor_id' => $asesor->id,
        'materia_id' => creaMateria()->id,
    ]);

    DisponibilidadModalidad::create([
        'facultad_id' => $asesor->facultad_id,
        'asesor_id' => $asesor->id,
        'modalidad' => Modalidad::Presencial,
    ]);

    DisponibilidadHorario::create([
        'facultad_id' => $asesor->facultad_id,
        'asesor_id' => $asesor->id,
        'dia' => Dia::Lunes,
        'horario_id' => creaHorario()->id,
    ]);

    return $asesor;
}

// Body válido de punta a punta para el asesor armado arriba; $extra pisa lo que se quiera cambiar
function creaDatos(Usuario $estudiante, array $extra = []): array
{
    return array_merge([
        'estudiante_id' => $estudiante->id,
        'materia_id' => creaMateria()->id,
        'horario_id' => creaHorario()->id,
        'fecha_inicio' => creaLunes()->toDateString(),
        'modalidad' => 'presencial',
        'razon' => 'parcial',
    ], $extra);
}

// Asesoría preexistente, insertada directo en la BD (facultad_id explícito: no hay usuario logueado)
function creaInsertar(
    Usuario $estudiante,
    Usuario $asesor,
    EstatusAsesoria $estatus = EstatusAsesoria::EnCurso,
    array $extra = [],
): Asesoria {
    return Asesoria::create(array_merge([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => creaCiclo()->id,
        'grupo_id' => creaGrupo()->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => creaMateria()->id,
        'horario_id' => creaHorario()->id,
        'fecha_inicio' => creaLunes()->toDateString(),
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => $estatus,
    ], $extra));
}


// ---------- Acceso ----------

it('crear asesoría devuelve 401 sin sesión', function () {
    $this->postJson(URL_CREA, [])->assertUnauthorized();
});

it('crear asesoría rechaza con 403 a un estudiante puro', function () {
    creaCiclo();
    $estudiante = creaEstudiante();

    $this->withToken(creaToken($estudiante))
        ->postJson(URL_CREA, creaDatos(creaEstudiante()))
        ->assertForbidden();

    expect(Asesoria::count())->toBe(0);
});

it('crear asesoría bloquea con 403 a un asesor con cuenta no activa', function () {
    creaCiclo();
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos(creaEstudiante()))
        ->assertForbidden();

    expect(Asesoria::count())->toBe(0);
});


// ---------- Caso feliz ----------

it('crear asesoría: el asesor la da de alta sin solicitud y responde 201', function () {
    creaCiclo();
    $asesor = creaAsesor();
    $estudiante = creaEstudiante(['nombre' => 'Luis', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'Gámez']);

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos($estudiante, ['observaciones' => 'Traer laptop']))
        ->assertCreated()
        ->assertJsonPath('data.estatus', 'en_curso')
        ->assertJsonPath('data.estudiante.id', $estudiante->id)
        ->assertJsonPath('data.estudiante.nombre', 'Luis Pérez Gámez')
        ->assertJsonPath('data.materia.nombre', 'Estructura de Datos')
        ->assertJsonPath('data.horario.etiqueta', '7:00-8:00 AM')
        ->assertJsonPath('data.fecha_inicio', creaLunes()->toDateString())
        ->assertJsonPath('data.modalidad.valor', 'presencial')
        ->assertJsonPath('data.razon.valor', 'parcial')
        ->assertJsonPath('data.observaciones', 'Traer laptop')
        ->assertJsonPath('data.motivo_cancelacion', null)
        ->assertJsonPath('data.finalizada_en', null)
        ->assertJsonPath('data.sesiones_count', 0)
        ->assertJsonMissingPath('data.asesor');

    $asesoria = Asesoria::first();

    expect($asesoria->solicitud_id)->toBeNull();
    expect($asesoria->estatus)->toBe(EstatusAsesoria::EnCurso);
    expect($asesoria->asesor_id)->toBe($asesor->id);
    expect($asesoria->estudiante_id)->toBe($estudiante->id);
    expect($asesoria->ciclo_escolar_id)->toBe(CicloEscolar::first()->id);
    expect($asesoria->grupo_id)->toBe($estudiante->grupo_id);
    expect($asesoria->facultad_id)->toBe($asesor->facultad_id);
});

it('crear asesoría: las observaciones son opcionales', function () {
    creaCiclo();
    $asesor = creaAsesor();

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos(creaEstudiante()))
        ->assertCreated()
        ->assertJsonPath('data.observaciones', null);
});

it('crear asesoría: aparece en las listas del asesor y del estudiante', function () {
    creaCiclo();
    $asesor = creaAsesor();
    $estudiante = creaEstudiante();

    $id = $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos($estudiante))
        ->assertCreated()
        ->json('data.id');

    $this->app['auth']->forgetGuards();

    $this->withToken(creaToken($asesor))
        ->getJson('/api/v1/asesor/asesorias?estatus=en_curso')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id);

    $this->app['auth']->forgetGuards();

    $this->withToken(creaToken($estudiante))
        ->getJson('/api/v1/estudiante/asesorias?estatus=en_curso')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id);
});

it('crear asesoría permite una fecha pasada dentro del ciclo (asesoría ya impartida)', function () {
    creaCiclo();
    $asesor = creaAsesor();
    $pasada = Carbon::now()->previous(Carbon::MONDAY)->toDateString();

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos(creaEstudiante(), ['fecha_inicio' => $pasada]))
        ->assertCreated()
        ->assertJsonPath('data.fecha_inicio', $pasada);
});

it('crear asesoría: un asesor par la crea para otro estudiante', function () {
    creaCiclo();
    $par = creaAsesor();
    $par->asignarRol(Rol::Estudiante);
    $estudiante = creaEstudiante();

    $this->withToken(creaToken($par))
        ->postJson(URL_CREA, creaDatos($estudiante))
        ->assertCreated();

    expect(Asesoria::first()->asesor_id)->toBe($par->id);
});

it('crear asesoría ignora lo que mande el cliente fuera de los campos permitidos', function () {
    creaCiclo();
    $asesor = creaAsesor();
    $otroAsesor = creaAsesor();
    $estudiante = creaEstudiante();

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos($estudiante, [
            'asesor_id' => $otroAsesor->id,
            'estatus' => 'completada',
            'solicitud_id' => 999,
            'finalizada_en' => '2000-01-01 00:00:00',
            'motivo_cancelacion' => 'colado',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.estatus', 'en_curso');

    $asesoria = Asesoria::first();

    expect($asesoria->asesor_id)->toBe($asesor->id);
    expect($asesoria->estatus)->toBe(EstatusAsesoria::EnCurso);
    expect($asesoria->solicitud_id)->toBeNull();
    expect($asesoria->finalizada_en)->toBeNull();
    expect($asesoria->motivo_cancelacion)->toBeNull();
});

it('crear asesoría: el mismo estudiante puede tener otra con el mismo asesor en otro horario', function () {
    creaCiclo();
    $asesor = creaAsesor();
    $estudiante = creaEstudiante();
    $otroHorario = creaHorario('8:00-9:00 AM');

    DisponibilidadHorario::create([
        'facultad_id' => $asesor->facultad_id,
        'asesor_id' => $asesor->id,
        'dia' => Dia::Lunes,
        'horario_id' => $otroHorario->id,
    ]);

    $token = creaToken($asesor);

    $this->withToken($token)->postJson(URL_CREA, creaDatos($estudiante))->assertCreated();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson(URL_CREA, creaDatos($estudiante, ['horario_id' => $otroHorario->id]))
        ->assertCreated();

    expect(Asesoria::count())->toBe(2);
});


// ---------- Quién es un estudiante válido (404) ----------

it('crear asesoría devuelve 404 si el estudiante no existe', function () {
    creaCiclo();
    $asesor = creaAsesor();

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos(creaEstudiante(), ['estudiante_id' => 999999]))
        ->assertNotFound();

    expect(Asesoria::count())->toBe(0);
});

it('crear asesoría devuelve 404 si el estudiante es de otra facultad', function () {
    creaCiclo();
    $asesor = creaAsesor();
    $ajeno = creaEstudiante(['facultad_id' => UsuarioFactory::facultad('OTR')->id]);

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos($ajeno))
        ->assertNotFound();

    expect(Asesoria::count())->toBe(0);
});

it('crear asesoría devuelve 404 si la persona elegida no tiene rol de estudiante', function () {
    creaCiclo();
    $asesor = creaAsesor();
    $otroAsesor = creaAsesor();

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos($otroAsesor))
        ->assertNotFound();

    expect(Asesoria::count())->toBe(0);
});

it('crear asesoría devuelve 404 si el estudiante no tiene la cuenta activa', function () {
    creaCiclo();
    $asesor = creaAsesor();
    $pendiente = Usuario::factory()->pendiente()->conRol(Rol::Estudiante)->create();

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos($pendiente))
        ->assertNotFound();

    expect(Asesoria::count())->toBe(0);
});

it('crear asesoría devuelve 404 si el asesor par se elige a sí mismo como estudiante', function () {
    creaCiclo();
    $par = creaAsesor();
    $par->asignarRol(Rol::Estudiante);

    $this->withToken(creaToken($par))
        ->postJson(URL_CREA, creaDatos($par))
        ->assertNotFound();

    expect(Asesoria::count())->toBe(0);
});


// ---------- Validación de forma (422) ----------

it('crear asesoría rechaza con 422 cada campo obligatorio que falte', function () {
    creaCiclo();
    $asesor = creaAsesor();
    $estudiante = creaEstudiante();
    $token = creaToken($asesor);

    foreach (['estudiante_id', 'materia_id', 'horario_id', 'fecha_inicio', 'modalidad', 'razon'] as $campo) {
        $datos = creaDatos($estudiante);
        unset($datos[$campo]);

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson(URL_CREA, $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($campo);
    }

    expect(Asesoria::count())->toBe(0);
});

it('crear asesoría rechaza con 422 cada campo inválido', function () {
    creaCiclo();
    $asesor = creaAsesor();
    $estudiante = creaEstudiante();
    $token = creaToken($asesor);

    $casos = [
        'estudiante_id' => 'abc',
        'materia_id' => 999999,
        'horario_id' => 999999,
        'fecha_inicio' => '26/10/2026',
        'modalidad' => 'xyz',
        'razon' => 'xyz',
        'observaciones' => str_repeat('a', 1001),
    ];

    foreach ($casos as $campo => $valor) {
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson(URL_CREA, creaDatos($estudiante, [$campo => $valor]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($campo);
    }

    expect(Asesoria::count())->toBe(0);
});

it('crear asesoría acepta observaciones de exactamente 1000 caracteres', function () {
    creaCiclo();
    $asesor = creaAsesor();

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos(creaEstudiante(), ['observaciones' => str_repeat('a', 1000)]))
        ->assertCreated();
});


// ---------- Reglas de negocio ----------

it('crear asesoría rechaza con 409 si no hay ciclo escolar vigente', function () {
    // Sin llamar a creaCiclo(): no existe ningún ciclo
    $asesor = creaAsesor();

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos(creaEstudiante()))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'sin_ciclo');

    expect(Asesoria::count())->toBe(0);
});

it('crear asesoría rechaza con 422 una fecha fuera del ciclo vigente', function () {
    $ciclo = creaCiclo();
    $asesor = creaAsesor();
    $estudiante = creaEstudiante();
    $token = creaToken($asesor);

    $fueraDelCiclo = [
        $ciclo->fecha_fin->copy()->addDay()->toDateString(),
        $ciclo->fecha_inicio->copy()->subDay()->toDateString(),
    ];

    foreach ($fueraDelCiclo as $fecha) {
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson(URL_CREA, creaDatos($estudiante, ['fecha_inicio' => $fecha]))
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'fuera_de_ciclo');
    }

    expect(Asesoria::count())->toBe(0);
});

it('crear asesoría rechaza con 422 una materia que el asesor no imparte', function () {
    creaCiclo();
    $asesor = creaAsesor();
    $otraMateria = creaMateria('Cálculo Diferencial');

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos(creaEstudiante(), ['materia_id' => $otraMateria->id]))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'materia_no_ofrecida');

    expect(Asesoria::count())->toBe(0);
});

it('crear asesoría rechaza con 422 una modalidad que el asesor no ofrece', function () {
    creaCiclo();
    $asesor = creaAsesor();   // solo ofrece presencial

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos(creaEstudiante(), ['modalidad' => 'virtual']))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'modalidad_no_ofrecida');

    expect(Asesoria::count())->toBe(0);
});

it('crear asesoría rechaza con 422 un horario que el asesor no ofrece', function () {
    creaCiclo();
    $asesor = creaAsesor();
    $noOfrecido = creaHorario('10:00-11:00 AM');

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos(creaEstudiante(), ['horario_id' => $noOfrecido->id]))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'horario_no_disponible');

    expect(Asesoria::count())->toBe(0);
});

it('crear asesoría rechaza con 422 un horario que el asesor ofrece, pero otro día', function () {
    creaCiclo();
    $asesor = creaAsesor();   // 7:00 solo los lunes
    $martes = creaLunes()->addDay()->toDateString();

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos(creaEstudiante(), ['fecha_inicio' => $martes]))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'horario_no_disponible');

    expect(Asesoria::count())->toBe(0);
});

it('crear asesoría rechaza con 422 una fecha en fin de semana', function () {
    creaCiclo();
    $asesor = creaAsesor();
    $sabado = creaLunes()->addDays(5)->toDateString();

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos(creaEstudiante(), ['fecha_inicio' => $sabado]))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'dia_no_disponible');

    expect(Asesoria::count())->toBe(0);
});

it('crear asesoría rechaza con 409 un slot ocupado por otra asesoría en curso del mismo asesor', function () {
    creaCiclo();
    $asesor = creaAsesor();

    // Otra asesoría en curso: lunes 7:00 (otro lunes: cuenta el día de la semana, no la fecha)
    creaInsertar(creaEstudiante(), $asesor, EstatusAsesoria::EnCurso, [
        'fecha_inicio' => creaLunes()->addWeek()->toDateString(),
    ]);

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos(creaEstudiante()))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'horario_ocupado');

    expect(Asesoria::count())->toBe(1);
});

it('crear asesoría: un slot ocupado por una asesoría completada o cancelada sí se puede usar', function () {
    creaCiclo();
    $asesor = creaAsesor();

    foreach ([EstatusAsesoria::Completada, EstatusAsesoria::Cancelada] as $cerrada) {
        creaInsertar(creaEstudiante(), $asesor, $cerrada);
    }

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos(creaEstudiante()))
        ->assertCreated();

    expect(Asesoria::count())->toBe(3);
});

it('crear asesoría: el slot ocupado por la asesoría de OTRO asesor no estorba', function () {
    creaCiclo();
    $asesor = creaAsesor();
    $otroAsesor = creaAsesor();

    creaInsertar(creaEstudiante(), $otroAsesor);

    $this->withToken(creaToken($asesor))
        ->postJson(URL_CREA, creaDatos(creaEstudiante()))
        ->assertCreated();
});

it('crear asesoría: la segunda alta seguida del mismo cupo da 409', function () {
    creaCiclo();
    $asesor = creaAsesor();
    $token = creaToken($asesor);

    $this->withToken($token)->postJson(URL_CREA, creaDatos(creaEstudiante()))->assertCreated();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson(URL_CREA, creaDatos(creaEstudiante()))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'horario_ocupado');

    expect(Asesoria::count())->toBe(1);
});