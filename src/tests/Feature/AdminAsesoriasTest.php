<?php

use App\Enums\Dia;
use App\Enums\EstatusAsesoria;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Models\Asesoria;
use App\Models\Bitacora;
use App\Models\CicloEscolar;
use App\Models\DisponibilidadHorario;
use App\Models\DisponibilidadMateria;
use App\Models\DisponibilidadModalidad;
use App\Models\Facultad;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\MaterialAdicional;
use App\Models\Sesion;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

const URL_AAD = '/api/v1/admin/asesorias';

beforeEach(function () {
    config(['filesystems.material' => 'local']);
    Storage::fake('local');
});


// ---------- Ayudantes ----------

function aadToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function aadOtra(): Facultad
{
    return UsuarioFactory::facultad('OTR');
}

function aadAdmin(?Facultad $facultad = null): Usuario
{
    return Usuario::factory()
        ->conRol(Rol::Admin)
        ->create(['facultad_id' => ($facultad ?? UsuarioFactory::facultad('FIC'))->id]);
}

function aadCiclo(): CicloEscolar
{
    return CicloEscolar::first() ?? CicloEscolar::create([
        'facultad_id' => UsuarioFactory::facultad('FIC')->id,
        'nombre' => '2026-2027 1',
        'periodo' => 1,
        'fecha_inicio' => now()->subMonth()->toDateString(),
        'fecha_fin' => now()->addYear()->toDateString(),
        'vigente' => true,
    ]);
}

function aadLicenciatura(): Licenciatura
{
    return Licenciatura::firstOrCreate([
        'facultad_id' => UsuarioFactory::facultad('FIC')->id,
        'nombre' => 'LICENCIATURA PRUEBA',
    ]);
}

function aadGrupo(): Grupo
{
    return Grupo::firstOrCreate(
        ['licenciatura_id' => aadLicenciatura()->id, 'nombre' => '3-1'],
        ['anio' => 3]
    );
}

function aadMateria(string $nombre = 'Estructura de Datos'): Materia
{
    return Materia::firstOrCreate(
        ['licenciatura_id' => aadLicenciatura()->id, 'nombre' => $nombre],
        ['semestre' => 1]
    );
}

function aadHorario(string $etiqueta = '7:00-8:00 AM'): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Próximo lunes: día base de las pruebas de alta
function aadLunes(): Carbon
{
    return Carbon::now()->next(Carbon::MONDAY)->startOfDay();
}

function aadEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create(array_merge(
        ['grupo_id' => aadGrupo()->id],
        $datos
    ));
}

// Asesor que ofrece: "Estructura de Datos", modalidad presencial y lunes 7:00-8:00 AM
function aadAsesor(array $datos = []): Usuario
{
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create($datos);

    DisponibilidadMateria::create([
        'facultad_id' => $asesor->facultad_id,
        'asesor_id' => $asesor->id,
        'materia_id' => aadMateria()->id,
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
        'horario_id' => aadHorario()->id,
    ]);

    return $asesor;
}

// Body válido de punta a punta; $extra pisa lo que se quiera cambiar
function aadDatos(Usuario $asesor, Usuario $estudiante, array $extra = []): array
{
    return array_merge([
        'asesor_id' => $asesor->id,
        'estudiante_id' => $estudiante->id,
        'materia_id' => aadMateria()->id,
        'horario_id' => aadHorario()->id,
        'fecha_inicio' => aadLunes()->toDateString(),
        'modalidad' => 'presencial',
        'razon' => 'parcial',
    ], $extra);
}

// Asesoría insertada directo en la BD, en la facultad del estudiante (facultad_id explícito: no hay usuario logueado)
function aadInsertar(
    Usuario $estudiante,
    Usuario $asesor,
    EstatusAsesoria $estatus = EstatusAsesoria::EnCurso,
    array $extra = [],
): Asesoria {
    $licenciatura = Licenciatura::firstOrCreate([
        'facultad_id' => $estudiante->facultad_id,
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

    return Asesoria::create(array_merge([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => aadCiclo()->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => aadHorario()->id,
        'fecha_inicio' => aadLunes()->toDateString(),
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => $estatus,
    ], $extra));
}

// Admin + asesor + estudiante + una asesoría en curso que ya empezó (un lunes pasado, dentro del ciclo)
function aadEscenario(): array
{
    aadCiclo();

    $admin = aadAdmin();
    $asesor = aadAsesor();
    $estudiante = aadEstudiante();
    $asesoria = aadInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso, [
        'fecha_inicio' => Carbon::now()->previous(Carbon::MONDAY)->subWeeks(2)->toDateString(),
    ]);

    return compact('admin', 'asesor', 'estudiante', 'asesoria');
}

function aadSesionInsertar(Asesoria $asesoria): Sesion
{
    return Sesion::create([
        'asesoria_id' => $asesoria->id,
        'fecha' => Carbon::now()->subDay()->toDateString(),
        'horario_id' => aadHorario()->id,
        'registrada_por' => $asesoria->asesor_id,
    ]);
}

function aadMaterialInsertar(Asesoria $asesoria, ?Usuario $quien = null): MaterialAdicional
{
    return MaterialAdicional::create([
        'asesoria_id' => $asesoria->id,
        'usuario_id' => ($quien ?? $asesoria->asesor)->id,
        'nombre_archivo' => 'archivo.pdf',
        'ruta' => "materiales/{$asesoria->id}/guardado.pdf",
        'disco' => 'local',
        'mime_type' => 'application/pdf',
        'tamano' => 100,
    ]);
}


// =====================================================================
//  GET /admin/asesorias: acceso
// =====================================================================

it('aad listar: devuelve 401 sin sesión', function () {
    $this->getJson(URL_AAD)->assertUnauthorized();
});

it('aad listar: rechaza con 403 a un asesor y a un estudiante', function () {
    aadCiclo();

    $this->withToken(aadToken(aadAsesor()))->getJson(URL_AAD)->assertForbidden();

    $this->app['auth']->forgetGuards();

    $this->withToken(aadToken(aadEstudiante()))->getJson(URL_AAD)->assertForbidden();
});

it('aad listar: bloquea con 403 a un admin con cuenta no activa', function () {
    $admin = Usuario::factory()->pendiente()->conRol(Rol::Admin)->create();

    $this->withToken(aadToken($admin))->getJson(URL_AAD)->assertForbidden();
});

it('aad listar: el super admin sin X-Facultad-Id recibe 422 facultad_requerida', function () {
    $superAdmin = Usuario::factory()->conRol(Rol::SuperAdmin)->create();

    $this->withToken(aadToken($superAdmin))
        ->getJson(URL_AAD)
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'facultad_requerida');
});

it('aad listar: el super admin con X-Facultad-Id ve las asesorías de esa facultad', function () {
    ['asesoria' => $asesoria] = aadEscenario();
    $superAdmin = Usuario::factory()->conRol(Rol::SuperAdmin)->create();

    $this->withToken(aadToken($superAdmin))
        ->withHeader('X-Facultad-Id', (string) $asesoria->facultad_id)
        ->getJson(URL_AAD)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $asesoria->id);
});


// =====================================================================
//  GET /admin/asesorias: contenido y filtros
// =====================================================================

it('aad listar: trae a las dos personas, la materia, el horario y el conteo de sesiones', function () {
    aadCiclo();
    $admin = aadAdmin();
    $asesor = aadAsesor(['nombre' => 'Patricia', 'apellido_paterno' => 'Rentería', 'apellido_materno' => 'Beltrán']);
    $estudiante = aadEstudiante(['nombre' => 'Luis', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'Gámez']);
    $asesoria = aadInsertar($estudiante, $asesor);
    aadSesionInsertar($asesoria);

    $this->withToken(aadToken($admin))
        ->getJson(URL_AAD)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $asesoria->id)
        ->assertJsonPath('data.0.estatus', 'en_curso')
        ->assertJsonPath('data.0.asesor.id', $asesor->id)
        ->assertJsonPath('data.0.asesor.nombre', 'Patricia Rentería Beltrán')
        ->assertJsonPath('data.0.estudiante.id', $estudiante->id)
        ->assertJsonPath('data.0.estudiante.nombre', 'Luis Pérez Gámez')
        ->assertJsonPath('data.0.materia.nombre', 'Estructura de Datos')
        ->assertJsonPath('data.0.horario.etiqueta', '7:00-8:00 AM')
        ->assertJsonPath('data.0.sesiones_count', 1);
});

it('aad listar: no muestra asesorías de otra facultad', function () {
    aadCiclo();
    $otra = aadOtra();

    $propia = aadInsertar(aadEstudiante(), aadAsesor());

    $estudianteAjeno = Usuario::factory()->conRol(Rol::Estudiante)->create(['facultad_id' => $otra->id]);
    $asesorAjeno = Usuario::factory()->conRol(Rol::Asesor)->create(['facultad_id' => $otra->id]);
    aadInsertar($estudianteAjeno, $asesorAjeno);

    $this->withToken(aadToken(aadAdmin()))
        ->getJson(URL_AAD)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $propia->id);

    $this->app['auth']->forgetGuards();

    $this->withToken(aadToken(aadAdmin($otra)))
        ->getJson(URL_AAD)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.estudiante.id', $estudianteAjeno->id);
});

it('aad listar: ordena por fecha de inicio descendente', function () {
    aadCiclo();
    $asesor = aadAsesor();
    $estudiante = aadEstudiante();

    $vieja = aadInsertar($estudiante, $asesor, EstatusAsesoria::Completada, [
        'fecha_inicio' => Carbon::now()->subWeeks(3)->toDateString(),
    ]);
    $nueva = aadInsertar($estudiante, $asesor);

    $this->withToken(aadToken(aadAdmin()))
        ->getJson(URL_AAD)
        ->assertOk()
        ->assertJsonPath('data.0.id', $nueva->id)
        ->assertJsonPath('data.1.id', $vieja->id);
});

it('aad listar: pagina de 10 en 10', function () {
    aadCiclo();
    $asesor = aadAsesor();
    $estudiante = aadEstudiante();

    foreach (range(1, 12) as $i) {
        aadInsertar($estudiante, $asesor);
    }

    $admin = aadAdmin();

    $this->withToken(aadToken($admin))
        ->getJson(URL_AAD)
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 12);

    $this->app['auth']->forgetGuards();

    $this->withToken(aadToken($admin))
        ->getJson(URL_AAD.'?page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('aad listar: filtra por estatus y por el atajo historial', function () {
    aadCiclo();
    $asesor = aadAsesor();
    $estudiante = aadEstudiante();

    aadInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso);
    aadInsertar($estudiante, $asesor, EstatusAsesoria::Completada);
    aadInsertar($estudiante, $asesor, EstatusAsesoria::Cancelada, ['motivo_cancelacion' => 'Ya no hacía falta']);

    $admin = aadAdmin();

    foreach (['en_curso' => 1, 'completada' => 1, 'cancelada' => 1, 'historial' => 2] as $estatus => $cuantas) {
        $this->app['auth']->forgetGuards();

        $this->withToken(aadToken($admin))
            ->getJson(URL_AAD."?estatus={$estatus}")
            ->assertOk()
            ->assertJsonCount($cuantas, 'data');
    }
});

it('aad listar: filtra por asesor', function () {
    aadCiclo();
    $estudiante = aadEstudiante();
    $asesorUno = aadAsesor();
    $asesorDos = aadAsesor();

    $deUno = aadInsertar($estudiante, $asesorUno);
    aadInsertar($estudiante, $asesorDos);

    $this->withToken(aadToken(aadAdmin()))
        ->getJson(URL_AAD."?asesor_id={$asesorUno->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $deUno->id);
});

it('aad listar: filtra por materia', function () {
    aadCiclo();
    $asesor = aadAsesor();
    $estudiante = aadEstudiante();
    $bases = aadMateria('Bases de Datos');

    aadInsertar($estudiante, $asesor);
    $deBases = aadInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso, ['materia_id' => $bases->id]);

    $this->withToken(aadToken(aadAdmin()))
        ->getJson(URL_AAD."?materia_id={$bases->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $deBases->id);
});

it('aad listar: filtra por ciclo escolar', function () {
    $actual = aadCiclo();
    $asesor = aadAsesor();
    $estudiante = aadEstudiante();

    $anterior = CicloEscolar::create([
        'facultad_id' => $actual->facultad_id,
        'nombre' => '2025-2026 2',
        'periodo' => 2,
        'fecha_inicio' => now()->subYear()->toDateString(),
        'fecha_fin' => now()->subMonths(2)->toDateString(),
        'vigente' => false,
    ]);

    aadInsertar($estudiante, $asesor);
    $vieja = aadInsertar($estudiante, $asesor, EstatusAsesoria::Completada, ['ciclo_escolar_id' => $anterior->id]);

    $this->withToken(aadToken(aadAdmin()))
        ->getJson(URL_AAD."?ciclo_id={$anterior->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $vieja->id);
});

it('aad listar: busca por materia, por asesor y por estudiante', function () {
    aadCiclo();
    $admin = aadAdmin();

    $asesorA = aadAsesor(['nombre' => 'Patricia', 'apellido_paterno' => 'Rentería', 'apellido_materno' => 'Beltrán']);
    $asesorB = aadAsesor(['nombre' => 'Marco', 'apellido_paterno' => 'Soto', 'apellido_materno' => 'Ruiz']);
    $luis = aadEstudiante(['nombre' => 'Luis', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'Gámez']);
    $zulema = aadEstudiante(['nombre' => 'Zulema', 'apellido_paterno' => 'Quiroz', 'apellido_materno' => 'Ibarra']);
    $bases = aadMateria('Bases de Datos');

    $a = aadInsertar($luis, $asesorA);
    $b = aadInsertar($zulema, $asesorB, EstatusAsesoria::EnCurso, ['materia_id' => $bases->id]);

    $casos = [
        'Bases' => $b->id,
        'Marco' => $b->id,
        'Zulema' => $b->id,
        'Patricia' => $a->id,
        'Luis' => $a->id,
        'Estructura' => $a->id,
    ];

    foreach ($casos as $texto => $esperada) {
        $this->app['auth']->forgetGuards();

        $this->withToken(aadToken($admin))
            ->getJson(URL_AAD.'?buscar='.urlencode($texto))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $esperada);
    }
});

it('aad listar: los filtros se pueden combinar', function () {
    aadCiclo();
    $estudiante = aadEstudiante();
    $asesor = aadAsesor();
    $otro = aadAsesor();

    aadInsertar($estudiante, $asesor, EstatusAsesoria::Completada);
    $buscada = aadInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso);
    aadInsertar($estudiante, $otro, EstatusAsesoria::EnCurso);

    $this->withToken(aadToken(aadAdmin()))
        ->getJson(URL_AAD."?estatus=en_curso&asesor_id={$asesor->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $buscada->id);
});

it('aad listar: rechaza con 422 un estatus o un id inválidos', function () {
    $admin = aadAdmin();

    $this->withToken(aadToken($admin))
        ->getJson(URL_AAD.'?estatus=inventado')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('estatus');

    foreach (['asesor_id', 'materia_id', 'ciclo_id'] as $campo) {
        $this->app['auth']->forgetGuards();

        $this->withToken(aadToken($admin))
            ->getJson(URL_AAD."?{$campo}=abc")
            ->assertUnprocessable()
            ->assertJsonValidationErrors($campo);
    }
});


// =====================================================================
//  POST /admin/asesorias: acceso
// =====================================================================

it('aad crear: devuelve 401 sin sesión', function () {
    $this->postJson(URL_AAD, [])->assertUnauthorized();
});

it('aad crear: rechaza con 403 a un asesor y a un estudiante', function () {
    aadCiclo();
    $asesor = aadAsesor();
    $estudiante = aadEstudiante();

    $this->withToken(aadToken($asesor))
        ->postJson(URL_AAD, aadDatos($asesor, $estudiante))
        ->assertForbidden();

    $this->app['auth']->forgetGuards();

    $this->withToken(aadToken($estudiante))
        ->postJson(URL_AAD, aadDatos($asesor, $estudiante))
        ->assertForbidden();

    expect(Asesoria::count())->toBe(0);
});

it('aad crear: bloquea con 403 a un admin con cuenta no activa', function () {
    aadCiclo();
    $admin = Usuario::factory()->pendiente()->conRol(Rol::Admin)->create();

    $this->withToken(aadToken($admin))
        ->postJson(URL_AAD, aadDatos(aadAsesor(), aadEstudiante()))
        ->assertForbidden();

    expect(Asesoria::count())->toBe(0);
});

it('aad crear: el super admin sin X-Facultad-Id recibe 422 facultad_requerida', function () {
    aadCiclo();
    $superAdmin = Usuario::factory()->conRol(Rol::SuperAdmin)->create();

    $this->withToken(aadToken($superAdmin))
        ->postJson(URL_AAD, aadDatos(aadAsesor(), aadEstudiante()))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'facultad_requerida');

    expect(Asesoria::count())->toBe(0);
});


// =====================================================================
//  POST /admin/asesorias: caso feliz
// =====================================================================

it('aad crear: el admin da de alta la asesoría a nombre del asesor elegido y responde 201', function () {
    aadCiclo();
    $admin = aadAdmin();
    $asesor = aadAsesor();
    $estudiante = aadEstudiante(['nombre' => 'Luis', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'Gámez']);

    $this->withToken(aadToken($admin))
        ->postJson(URL_AAD, aadDatos($asesor, $estudiante, ['observaciones' => 'Traer laptop']))
        ->assertCreated()
        ->assertJsonPath('data.estatus', 'en_curso')
        ->assertJsonPath('data.estudiante.id', $estudiante->id)
        ->assertJsonPath('data.estudiante.nombre', 'Luis Pérez Gámez')
        ->assertJsonPath('data.materia.nombre', 'Estructura de Datos')
        ->assertJsonPath('data.horario.etiqueta', '7:00-8:00 AM')
        ->assertJsonPath('data.fecha_inicio', aadLunes()->toDateString())
        ->assertJsonPath('data.modalidad.valor', 'presencial')
        ->assertJsonPath('data.razon.valor', 'parcial')
        ->assertJsonPath('data.observaciones', 'Traer laptop')
        ->assertJsonPath('data.sesiones_count', 0);

    $asesoria = Asesoria::first();

    expect($asesoria->solicitud_id)->toBeNull();
    expect($asesoria->estatus)->toBe(EstatusAsesoria::EnCurso);
    // El asesor de la asesoría es el elegido, nunca el admin que la crea
    expect($asesoria->asesor_id)->toBe($asesor->id);
    expect($asesoria->asesor_id)->not->toBe($admin->id);
    expect($asesoria->estudiante_id)->toBe($estudiante->id);
    expect($asesoria->ciclo_escolar_id)->toBe(CicloEscolar::first()->id);
    expect($asesoria->grupo_id)->toBe($estudiante->grupo_id);
    expect($asesoria->facultad_id)->toBe($admin->facultad_id);
});

it('aad crear: queda en la bitácora a nombre del admin', function () {
    aadCiclo();
    $admin = aadAdmin();

    $this->withToken(aadToken($admin))
        ->postJson(URL_AAD, aadDatos(aadAsesor(), aadEstudiante()))
        ->assertCreated();

    $registro = Bitacora::withoutGlobalScopes()->where('accion', 'asesoria.creada')->sole();

    expect($registro->usuario_id)->toBe($admin->id);
});

it('aad crear: aparece en la lista del admin, del asesor y del estudiante', function () {
    aadCiclo();
    $admin = aadAdmin();
    $asesor = aadAsesor();
    $estudiante = aadEstudiante();

    $id = $this->withToken(aadToken($admin))
        ->postJson(URL_AAD, aadDatos($asesor, $estudiante))
        ->assertCreated()
        ->json('data.id');

    $this->app['auth']->forgetGuards();

    $this->withToken(aadToken($admin))
        ->getJson(URL_AAD)
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);

    $this->app['auth']->forgetGuards();

    $this->withToken(aadToken($asesor))
        ->getJson('/api/v1/asesor/asesorias?estatus=en_curso')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);

    $this->app['auth']->forgetGuards();

    $this->withToken(aadToken($estudiante))
        ->getJson('/api/v1/estudiante/asesorias?estatus=en_curso')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);
});

it('aad crear: el super admin con X-Facultad-Id puede dar de alta la asesoría', function () {
    aadCiclo();
    $superAdmin = Usuario::factory()->conRol(Rol::SuperAdmin)->create();
    $asesor = aadAsesor();

    $this->withToken(aadToken($superAdmin))
        ->withHeader('X-Facultad-Id', (string) $asesor->facultad_id)
        ->postJson(URL_AAD, aadDatos($asesor, aadEstudiante()))
        ->assertCreated()
        ->assertJsonPath('data.estatus', 'en_curso');

    expect(Asesoria::count())->toBe(1);
});


// =====================================================================
//  POST /admin/asesorias: validación de forma
// =====================================================================

it('aad crear: exige todos los campos obligatorios, incluido el asesor', function () {
    aadCiclo();
    $admin = aadAdmin();
    $datos = aadDatos(aadAsesor(), aadEstudiante());

    foreach (['asesor_id', 'estudiante_id', 'materia_id', 'horario_id', 'fecha_inicio', 'modalidad', 'razon'] as $campo) {
        $this->app['auth']->forgetGuards();

        $incompleto = $datos;
        unset($incompleto[$campo]);

        $this->withToken(aadToken($admin))
            ->postJson(URL_AAD, $incompleto)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($campo);
    }

    expect(Asesoria::count())->toBe(0);
});

it('aad crear: rechaza con 422 una fecha con formato inválido', function () {
    aadCiclo();

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos(aadAsesor(), aadEstudiante(), ['fecha_inicio' => '26/10/2026']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fecha_inicio');
});

it('aad crear: rechaza con 422 observaciones de más de 1000 caracteres', function () {
    aadCiclo();

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos(aadAsesor(), aadEstudiante(), ['observaciones' => str_repeat('a', 1001)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('observaciones');
});


// =====================================================================
//  POST /admin/asesorias: el asesor y el estudiante deben ser de la facultad (404)
// =====================================================================

it('aad crear: responde 404 si el asesor no existe', function () {
    aadCiclo();
    $estudiante = aadEstudiante();

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos(aadAsesor(), $estudiante, ['asesor_id' => 99999]))
        ->assertNotFound();

    expect(Asesoria::count())->toBe(0);
});

it('aad crear: responde 404 si el asesor es de otra facultad', function () {
    aadCiclo();
    $ajeno = Usuario::factory()->conRol(Rol::Asesor)->create(['facultad_id' => aadOtra()->id]);

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos($ajeno, aadEstudiante()))
        ->assertNotFound();

    expect(Asesoria::count())->toBe(0);
});

it('aad crear: responde 404 si el asesor no está activo', function () {
    aadCiclo();
    $pendiente = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create();

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos($pendiente, aadEstudiante()))
        ->assertNotFound();

    expect(Asesoria::count())->toBe(0);
});

it('aad crear: responde 404 si el usuario elegido no tiene el rol asesor', function () {
    aadCiclo();
    $otroEstudiante = aadEstudiante();

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos($otroEstudiante, aadEstudiante()))
        ->assertNotFound();

    expect(Asesoria::count())->toBe(0);
});

it('aad crear: responde 404 si el estudiante es de otra facultad', function () {
    aadCiclo();
    $ajeno = Usuario::factory()->conRol(Rol::Estudiante)->create(['facultad_id' => aadOtra()->id]);

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos(aadAsesor(), $ajeno))
        ->assertNotFound();

    expect(Asesoria::count())->toBe(0);
});

it('aad crear: responde 404 si el estudiante elegido es el propio asesor', function () {
    aadCiclo();
    $asesor = aadAsesor();

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos($asesor, $asesor))
        ->assertNotFound();

    expect(Asesoria::count())->toBe(0);
});


// =====================================================================
//  POST /admin/asesorias: las reglas siguen aplicando al asesor elegido
// =====================================================================

it('aad crear: 422 materia_no_ofrecida si el asesor no imparte esa materia', function () {
    aadCiclo();
    $asesor = aadAsesor();

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos($asesor, aadEstudiante(), ['materia_id' => aadMateria('Bases de Datos')->id]))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'materia_no_ofrecida');

    expect(Asesoria::count())->toBe(0);
});

it('aad crear: 422 modalidad_no_ofrecida si el asesor no ofrece esa modalidad', function () {
    aadCiclo();
    $asesor = aadAsesor();

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos($asesor, aadEstudiante(), ['modalidad' => 'virtual']))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'modalidad_no_ofrecida');

    expect(Asesoria::count())->toBe(0);
});

it('aad crear: 422 horario_no_disponible si el asesor no ofrece ese horario', function () {
    aadCiclo();
    $asesor = aadAsesor();

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos($asesor, aadEstudiante(), ['horario_id' => aadHorario('8:00-9:00 AM')->id]))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'horario_no_disponible');

    expect(Asesoria::count())->toBe(0);
});

it('aad crear: 422 horario_no_disponible si el asesor no ofrece ese día de la semana', function () {
    aadCiclo();
    $asesor = aadAsesor();
    $martes = aadLunes()->addDay()->toDateString();

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos($asesor, aadEstudiante(), ['fecha_inicio' => $martes]))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'horario_no_disponible');
});

it('aad crear: 422 dia_no_disponible en fin de semana', function () {
    aadCiclo();
    $asesor = aadAsesor();
    $sabado = aadLunes()->addDays(5)->toDateString();

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos($asesor, aadEstudiante(), ['fecha_inicio' => $sabado]))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'dia_no_disponible');
});

it('aad crear: 409 horario_ocupado si el asesor ya tiene una asesoría en curso en ese cupo', function () {
    aadCiclo();
    $asesor = aadAsesor();
    aadInsertar(aadEstudiante(), $asesor);

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos($asesor, aadEstudiante()))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'horario_ocupado');

    expect(Asesoria::count())->toBe(1);
});

it('aad crear: el cupo vuelve a estar libre si la otra asesoría ya está completada o cancelada', function () {
    aadCiclo();
    $admin = aadAdmin();
    $asesor = aadAsesor();

    aadInsertar(aadEstudiante(), $asesor, EstatusAsesoria::Completada);
    aadInsertar(aadEstudiante(), $asesor, EstatusAsesoria::Cancelada, ['motivo_cancelacion' => 'Ya no hacía falta']);

    $this->withToken(aadToken($admin))
        ->postJson(URL_AAD, aadDatos($asesor, aadEstudiante()))
        ->assertCreated();
});

it('aad crear: el cupo ocupado de otro asesor no bloquea al asesor elegido', function () {
    aadCiclo();
    $otro = aadAsesor();
    aadInsertar(aadEstudiante(), $otro);

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos(aadAsesor(), aadEstudiante()))
        ->assertCreated();
});

it('aad crear: 409 sin_ciclo si no hay ciclo escolar vigente', function () {
    // Sin aadCiclo(): no existe ningún ciclo
    $asesor = aadAsesor();

    $this->withToken(aadToken(aadAdmin()))
        ->postJson(URL_AAD, aadDatos($asesor, aadEstudiante()))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'sin_ciclo');

    expect(Asesoria::count())->toBe(0);
});

it('aad crear: 422 fuera_de_ciclo si la fecha cae fuera del ciclo vigente', function () {
    $ciclo = aadCiclo();
    $asesor = aadAsesor();

    foreach ([
        $ciclo->fecha_inicio->copy()->subDay()->toDateString(),
        $ciclo->fecha_fin->copy()->addDay()->toDateString(),
    ] as $fecha) {
        $this->app['auth']->forgetGuards();

        $this->withToken(aadToken(aadAdmin()))
            ->postJson(URL_AAD, aadDatos($asesor, aadEstudiante(), ['fecha_inicio' => $fecha]))
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'fuera_de_ciclo');
    }

    expect(Asesoria::count())->toBe(0);
});


// =====================================================================
//  Acciones sobre una asesoría: editar, completar y cancelar
// =====================================================================

it('aad acciones: un asesor y un estudiante reciben 403 en las rutas de admin', function () {
    ['asesor' => $asesor, 'estudiante' => $estudiante, 'asesoria' => $asesoria] = aadEscenario();

    foreach ([$asesor, $estudiante] as $usuario) {
        $this->app['auth']->forgetGuards();
        $token = aadToken($usuario);

        $this->withToken($token)->patchJson(URL_AAD."/{$asesoria->id}", ['observaciones' => 'x'])->assertForbidden();
        $this->withToken($token)->postJson(URL_AAD."/{$asesoria->id}/completar")->assertForbidden();
        $this->withToken($token)->postJson(URL_AAD."/{$asesoria->id}/cancelar", ['motivo_cancelacion' => 'x'])->assertForbidden();
    }

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});

it('aad editar: el admin cambia las observaciones de una asesoría ajena', function () {
    ['admin' => $admin, 'asesoria' => $asesoria] = aadEscenario();

    $this->withToken(aadToken($admin))
        ->patchJson(URL_AAD."/{$asesoria->id}", ['observaciones' => 'Reprogramada por el admin'])
        ->assertOk()
        ->assertJsonPath('data.observaciones', 'Reprogramada por el admin');

    expect($asesoria->fresh()->observaciones)->toBe('Reprogramada por el admin');
});

it('aad editar: sigue exigiendo que el asesor ofrezca el horario nuevo', function () {
    ['admin' => $admin, 'asesoria' => $asesoria] = aadEscenario();

    $this->withToken(aadToken($admin))
        ->patchJson(URL_AAD."/{$asesoria->id}", ['horario_id' => aadHorario('8:00-9:00 AM')->id])
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'horario_no_disponible');
});

it('aad editar: 422 si no manda ningún dato', function () {
    ['admin' => $admin, 'asesoria' => $asesoria] = aadEscenario();

    $this->withToken(aadToken($admin))
        ->patchJson(URL_AAD."/{$asesoria->id}", [])
        ->assertUnprocessable();
});

it('aad completar: el admin la completa y queda finalizada_en', function () {
    ['admin' => $admin, 'asesoria' => $asesoria] = aadEscenario();

    $this->withToken(aadToken($admin))
        ->postJson(URL_AAD."/{$asesoria->id}/completar")
        ->assertOk()
        ->assertJsonPath('data.estatus', 'completada');

    $actual = $asesoria->fresh();

    expect($actual->estatus)->toBe(EstatusAsesoria::Completada);
    expect($actual->finalizada_en)->not->toBeNull();
});

it('aad completar: 409 asesoria_no_en_curso si ya estaba completada', function () {
    ['admin' => $admin, 'estudiante' => $estudiante, 'asesor' => $asesor] = aadEscenario();
    $cerrada = aadInsertar($estudiante, $asesor, EstatusAsesoria::Completada);

    $this->withToken(aadToken($admin))
        ->postJson(URL_AAD."/{$cerrada->id}/completar")
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'asesoria_no_en_curso');
});

it('aad cancelar: el admin la cancela con motivo', function () {
    ['admin' => $admin, 'asesoria' => $asesoria] = aadEscenario();

    $this->withToken(aadToken($admin))
        ->postJson(URL_AAD."/{$asesoria->id}/cancelar", ['motivo_cancelacion' => 'El alumno cambió de grupo'])
        ->assertOk()
        ->assertJsonPath('data.estatus', 'cancelada')
        ->assertJsonPath('data.motivo_cancelacion', 'El alumno cambió de grupo');

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::Cancelada);
});

it('aad cancelar: 422 si falta el motivo y no cambia nada', function () {
    ['admin' => $admin, 'asesoria' => $asesoria] = aadEscenario();

    $this->withToken(aadToken($admin))
        ->postJson(URL_AAD."/{$asesoria->id}/cancelar", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo_cancelacion');

    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});

it('aad acciones: un admin de otra facultad recibe 404 y no cambia nada', function () {
    ['asesoria' => $asesoria] = aadEscenario();
    $ajeno = aadAdmin(aadOtra());
    $token = aadToken($ajeno);

    $this->withToken($token)->patchJson(URL_AAD."/{$asesoria->id}", ['observaciones' => 'x'])->assertNotFound();
    $this->withToken($token)->postJson(URL_AAD."/{$asesoria->id}/completar")->assertNotFound();
    $this->withToken($token)->postJson(URL_AAD."/{$asesoria->id}/cancelar", ['motivo_cancelacion' => 'x'])->assertNotFound();
    $this->withToken($token)->postJson(URL_AAD."/{$asesoria->id}/sesiones", ['fecha' => now()->toDateString(), 'horario_id' => aadHorario()->id])->assertNotFound();
    $this->withToken($token)->post(URL_AAD."/{$asesoria->id}/material", ['archivos' => [UploadedFile::fake()->create('a.pdf', 100)]], ['Accept' => 'application/json'])->assertNotFound();

    $actual = $asesoria->fresh();

    expect($actual->estatus)->toBe(EstatusAsesoria::EnCurso);
    expect($actual->observaciones)->toBeNull();
    expect(Sesion::count())->toBe(0);
    expect(MaterialAdicional::count())->toBe(0);
});


// =====================================================================
//  Sesiones como admin
// =====================================================================

it('aad sesiones: el admin registra una sesión y queda como quien la registró', function () {
    ['admin' => $admin, 'asesoria' => $asesoria] = aadEscenario();

    $this->withToken(aadToken($admin))
        ->postJson(URL_AAD."/{$asesoria->id}/sesiones", [
            'fecha' => Carbon::now()->subDay()->toDateString(),
            'horario_id' => aadHorario()->id,
            'tema' => 'Arreglos y ciclos',
        ])
        ->assertCreated()
        ->assertJsonPath('data.tema', 'Arreglos y ciclos');

    $sesion = Sesion::sole();

    expect($sesion->asesoria_id)->toBe($asesoria->id);
    expect($sesion->registrada_por)->toBe($admin->id);
});

it('aad sesiones: 409 asesoria_no_en_curso al registrar en una asesoría cerrada', function () {
    ['admin' => $admin, 'estudiante' => $estudiante, 'asesor' => $asesor] = aadEscenario();
    $cerrada = aadInsertar($estudiante, $asesor, EstatusAsesoria::Completada);

    $this->withToken(aadToken($admin))
        ->postJson(URL_AAD."/{$cerrada->id}/sesiones", [
            'fecha' => Carbon::now()->subDay()->toDateString(),
            'horario_id' => aadHorario()->id,
        ])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'asesoria_no_en_curso');
});

it('aad sesiones: el admin elimina una sesión ajena y responde 204', function () {
    ['admin' => $admin, 'asesoria' => $asesoria] = aadEscenario();
    $sesion = aadSesionInsertar($asesoria);

    $this->withToken(aadToken($admin))
        ->deleteJson("/api/v1/admin/sesiones/{$sesion->id}")
        ->assertNoContent();

    expect(Sesion::count())->toBe(0);
});

it('aad sesiones: un admin de otra facultad recibe 404 al eliminar', function () {
    ['asesoria' => $asesoria] = aadEscenario();
    $sesion = aadSesionInsertar($asesoria);

    $this->withToken(aadToken(aadAdmin(aadOtra())))
        ->deleteJson("/api/v1/admin/sesiones/{$sesion->id}")
        ->assertNotFound();

    expect(Sesion::count())->toBe(1);
});

it('aad sesiones: un asesor recibe 403 en la ruta de admin para eliminar', function () {
    ['asesor' => $asesor, 'asesoria' => $asesoria] = aadEscenario();
    $sesion = aadSesionInsertar($asesoria);

    $this->withToken(aadToken($asesor))
        ->deleteJson("/api/v1/admin/sesiones/{$sesion->id}")
        ->assertForbidden();

    expect(Sesion::count())->toBe(1);
});


// =====================================================================
//  Material como admin
// =====================================================================

it('aad material: el admin sube archivos y quedan a su nombre', function () {
    ['admin' => $admin, 'asesoria' => $asesoria] = aadEscenario();

    $this->withToken(aadToken($admin))
        ->post(
            URL_AAD."/{$asesoria->id}/material",
            ['archivos' => [UploadedFile::fake()->create('guia.pdf', 100)]],
            ['Accept' => 'application/json']
        )
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'guia.pdf');

    $material = MaterialAdicional::sole();

    expect($material->usuario_id)->toBe($admin->id);
    expect($material->asesoria_id)->toBe($asesoria->id);
    Storage::disk('local')->assertExists($material->ruta);
});

it('aad material: 409 asesoria_cancelada al subir a una asesoría cancelada', function () {
    ['admin' => $admin, 'estudiante' => $estudiante, 'asesor' => $asesor] = aadEscenario();
    $cancelada = aadInsertar($estudiante, $asesor, EstatusAsesoria::Cancelada, ['motivo_cancelacion' => 'Ya no hacía falta']);

    $this->withToken(aadToken($admin))
        ->post(
            URL_AAD."/{$cancelada->id}/material",
            ['archivos' => [UploadedFile::fake()->create('guia.pdf', 100)]],
            ['Accept' => 'application/json']
        )
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'asesoria_cancelada');

    expect(MaterialAdicional::count())->toBe(0);
});

it('aad material: el admin puede borrar un archivo que subió el asesor', function () {
    ['admin' => $admin, 'asesoria' => $asesoria, 'asesor' => $asesor] = aadEscenario();
    $material = aadMaterialInsertar($asesoria, $asesor);

    $this->withToken(aadToken($admin))
        ->deleteJson("/api/v1/admin/material/{$material->id}")
        ->assertNoContent();

    expect(MaterialAdicional::count())->toBe(0);
});

it('aad material: un admin de otra facultad recibe 404 al borrar', function () {
    ['asesoria' => $asesoria, 'asesor' => $asesor] = aadEscenario();
    $material = aadMaterialInsertar($asesoria, $asesor);

    $this->withToken(aadToken(aadAdmin(aadOtra())))
        ->deleteJson("/api/v1/admin/material/{$material->id}")
        ->assertNotFound();

    expect(MaterialAdicional::count())->toBe(1);
});

it('aad material: un estudiante recibe 403 en las rutas de admin', function () {
    ['estudiante' => $estudiante, 'asesoria' => $asesoria, 'asesor' => $asesor] = aadEscenario();
    $material = aadMaterialInsertar($asesoria, $asesor);

    $this->withToken(aadToken($estudiante))
        ->deleteJson("/api/v1/admin/material/{$material->id}")
        ->assertForbidden();

    expect(MaterialAdicional::count())->toBe(1);
});


// =====================================================================
//  Lectura compartida: el admin ve sesiones, material y chat; no escribe en el chat
// =====================================================================

it('aad lectura: el admin ve las sesiones, el material y el chat de cualquier asesoría de su facultad', function () {
    ['admin' => $admin, 'asesoria' => $asesoria] = aadEscenario();
    aadSesionInsertar($asesoria);
    aadMaterialInsertar($asesoria);

    $token = aadToken($admin);

    $this->withToken($token)->getJson("/api/v1/asesorias/{$asesoria->id}/sesiones")
        ->assertOk()->assertJsonCount(1, 'data');

    $this->withToken($token)->getJson("/api/v1/asesorias/{$asesoria->id}/material")
        ->assertOk()->assertJsonCount(1, 'data');

    $this->withToken($token)->getJson("/api/v1/asesorias/{$asesoria->id}/mensajes")
        ->assertOk();
});

it('aad lectura: el admin sigue sin poder escribir en el chat', function () {
    ['admin' => $admin, 'asesoria' => $asesoria] = aadEscenario();

    $this->withToken(aadToken($admin))
        ->postJson("/api/v1/asesorias/{$asesoria->id}/mensajes", ['mensaje' => 'Hola'])
        ->assertForbidden();
});

it('aad listar: filtra por licenciatura', function () {
    aadCiclo();
    $asesor = aadAsesor();
    $estudiante = aadEstudiante();

    $otraLicenciatura = Licenciatura::create([
        'facultad_id' => UsuarioFactory::facultad('FIC')->id,
        'nombre' => 'CIENCIA DE DATOS',
    ]);
    $materiaOtra = Materia::create([
        'licenciatura_id' => $otraLicenciatura->id,
        'nombre' => 'Aprendizaje Automático',
        'semestre' => 5,
    ]);

    aadInsertar($estudiante, $asesor);
    $deOtra = aadInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso, ['materia_id' => $materiaOtra->id]);

    $this->withToken(aadToken(aadAdmin()))
        ->getJson(URL_AAD."?licenciatura_id={$otraLicenciatura->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $deOtra->id);
});

it('aad listar: filtra por grupo', function () {
    aadCiclo();
    $asesor = aadAsesor();
    $estudiante = aadEstudiante();
    $otroGrupo = Grupo::create(['licenciatura_id' => aadLicenciatura()->id, 'nombre' => '3-2', 'anio' => 3]);

    aadInsertar($estudiante, $asesor);
    $deOtro = aadInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso, ['grupo_id' => $otroGrupo->id]);

    $this->withToken(aadToken(aadAdmin()))
        ->getJson(URL_AAD."?grupo_id={$otroGrupo->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $deOtro->id);
});

it('aad listar: filtra por modalidad', function () {
    aadCiclo();
    $asesor = aadAsesor();
    $estudiante = aadEstudiante();

    aadInsertar($estudiante, $asesor);
    $virtual = aadInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso, ['modalidad' => Modalidad::Virtual]);

    $this->withToken(aadToken(aadAdmin()))
        ->getJson(URL_AAD.'?modalidad=virtual')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $virtual->id);
});

it('aad listar: filtra por horario', function () {
    aadCiclo();
    $asesor = aadAsesor();
    $estudiante = aadEstudiante();
    $otroHorario = aadHorario('8:00-9:00 AM');

    aadInsertar($estudiante, $asesor);
    $deOtro = aadInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso, ['horario_id' => $otroHorario->id]);

    $this->withToken(aadToken(aadAdmin()))
        ->getJson(URL_AAD."?horario_id={$otroHorario->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $deOtro->id);
});

it('aad listar: filtra por razón', function () {
    aadCiclo();
    $asesor = aadAsesor();
    $estudiante = aadEstudiante();

    aadInsertar($estudiante, $asesor);
    $parcial = aadInsertar($estudiante, $asesor, EstatusAsesoria::EnCurso, ['razon' => RazonAsesoria::Parcial]);

    $this->withToken(aadToken(aadAdmin()))
        ->getJson(URL_AAD.'?razon=parcial')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $parcial->id);
});

it('aad listar: rechaza con 422 una modalidad, una razón o ids inválidos en los filtros nuevos', function () {
    $admin = aadAdmin();

    foreach (['modalidad=inventada', 'razon=inventada', 'licenciatura_id=abc', 'grupo_id=abc', 'horario_id=abc'] as $consulta) {
        $this->app['auth']->forgetGuards();

        $this->withToken(aadToken($admin))
            ->getJson(URL_AAD.'?'.$consulta)
            ->assertUnprocessable();
    }
});

it('aad chat: el admin no puede marcar mensajes como leídos', function () {
    ['admin' => $admin, 'asesoria' => $asesoria, 'estudiante' => $estudiante] = aadEscenario();

    $mensaje = $asesoria->chats()->create(['usuario_id' => $estudiante->id, 'mensaje' => 'Hola']);

    $this->withToken(aadToken($admin))
        ->postJson("/api/v1/asesorias/{$asesoria->id}/mensajes/leidos")
        ->assertForbidden();

    expect($mensaje->fresh()->leido_en)->toBeNull();
});