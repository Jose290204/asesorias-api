<?php


use App\Enums\EstatusSolicitud;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Models\CicloEscolar;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Solicitud;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;

const URL_LSOL_ESTUDIANTE = '/api/v1/estudiante/solicitudes';
const URL_LSOL_ASESOR = '/api/v1/asesor/solicitudes';


// ---------- Ayudantes ----------

function lsolToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function lsolEstudiante(): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create();
}

// Para listar no hace falta disponibilidad: basta un usuario con rol asesor
function lsolAsesor(): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create();
}

function lsolCiclo(): CicloEscolar
{
    return CicloEscolar::create([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => '2026-2027 1',
        'periodo' => 1,
        'fecha_inicio' => now()->subMonth()->toDateString(),
        'fecha_fin' => now()->addYear()->toDateString(),
        'vigente' => true,
    ]);
}

// Inserta una solicitud directo en la BD (sin pasar por la API) para armar el escenario.
// Requiere haber llamado antes a lsolCiclo(). facultad_id va explícito: al preparar datos
// no hay usuario logueado y el trait PerteneceAFacultad no lo rellena
function lsolInsertar(Usuario $estudiante, Usuario $asesor, EstatusSolicitud $estatus = EstatusSolicitud::Pendiente, array $extra = []): Solicitud
{
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

    return Solicitud::create(array_merge([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => CicloEscolar::first()->id,
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

it('listar solicitudes devuelve 401 sin sesión en ambas rutas', function () {
    $this->getJson(URL_LSOL_ESTUDIANTE)->assertUnauthorized();
    $this->getJson(URL_LSOL_ASESOR)->assertUnauthorized();
});

it('listar solicitudes rechaza con 403 a un asesor puro en la ruta del estudiante', function () {
    $this->withToken(lsolToken(lsolAsesor()))
        ->getJson(URL_LSOL_ESTUDIANTE)
        ->assertForbidden();
});

it('listar solicitudes rechaza con 403 a un estudiante puro en la ruta del asesor', function () {
    $this->withToken(lsolToken(lsolEstudiante()))
        ->getJson(URL_LSOL_ASESOR)
        ->assertForbidden();
});

it('listar solicitudes bloquea con 403 a un estudiante con cuenta no activa', function () {
    $estudiante = Usuario::factory()->pendiente()->conRol(Rol::Estudiante)->create(['password' => 'secreto123']);

    $this->withToken(lsolToken($estudiante))
        ->getJson(URL_LSOL_ESTUDIANTE)
        ->assertForbidden();
});


// ---------- Visibilidad ----------

it('listar solicitudes: el estudiante ve solo las suyas', function () {
    lsolCiclo();
    $asesor = lsolAsesor();
    $yo = lsolEstudiante();
    $otro = lsolEstudiante();

    $mia = lsolInsertar($yo, $asesor);
    lsolInsertar($otro, $asesor);

    $this->withToken(lsolToken($yo))
        ->getJson(URL_LSOL_ESTUDIANTE)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mia->id)
        ->assertJsonPath('data.0.estatus', 'pendiente')
        ->assertJsonPath('data.0.asesor.id', $asesor->id)
        ->assertJsonPath('data.0.materia.nombre', 'Estructura de Datos')
        ->assertJsonPath('data.0.motivo_rechazo', null);
});

it('listar solicitudes: el asesor ve solo las que le pidieron a él e incluye al estudiante', function () {
    lsolCiclo();
    $yo = lsolAsesor();
    $otroAsesor = lsolAsesor();
    $estudiante = lsolEstudiante();

    $mia = lsolInsertar($estudiante, $yo);
    lsolInsertar($estudiante, $otroAsesor);

    $this->withToken(lsolToken($yo))
        ->getJson(URL_LSOL_ASESOR)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mia->id)
        ->assertJsonPath('data.0.estudiante.id', $estudiante->id);
});

it('listar solicitudes: un asesor par ve en cada ruta solo lo de ese rol', function () {
    lsolCiclo();
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $asesorAjeno = lsolAsesor();
    $estudianteAjeno = lsolEstudiante();

    // Como estudiante, el par le pide a otro asesor
    $comoEstudiante = lsolInsertar($par, $asesorAjeno);
    // Como asesor, un estudiante le pide al par
    $comoAsesor = lsolInsertar($estudianteAjeno, $par);

    $token = lsolToken($par);

    $this->withToken($token)->getJson(URL_LSOL_ESTUDIANTE)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $comoEstudiante->id);

    // Sanctum recuerda al primer usuario dentro de la misma prueba; se limpia antes de la segunda petición
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson(URL_LSOL_ASESOR)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $comoAsesor->id);
});


// ---------- Filtro por estatus ----------

it('listar solicitudes filtra por estatus y sin filtro trae todas', function () {
    lsolCiclo();
    $asesor = lsolAsesor();
    $estudiante = lsolEstudiante();

    lsolInsertar($estudiante, $asesor, EstatusSolicitud::Pendiente);
    lsolInsertar($estudiante, $asesor, EstatusSolicitud::Aceptada);
    lsolInsertar($estudiante, $asesor, EstatusSolicitud::Rechazada);

    $token = lsolToken($estudiante);

    $this->withToken($token)->getJson(URL_LSOL_ESTUDIANTE)
        ->assertOk()
        ->assertJsonCount(3, 'data');

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson(URL_LSOL_ESTUDIANTE.'?estatus=pendiente')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.estatus', 'pendiente');

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson(URL_LSOL_ESTUDIANTE.'?estatus=rechazada')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.estatus', 'rechazada');
});

it('listar solicitudes responde 422 con un estatus inválido', function () {
    $this->withToken(lsolToken(lsolEstudiante()))
        ->getJson(URL_LSOL_ESTUDIANTE.'?estatus=xyz')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('estatus');
});


// ---------- Contenido, orden y paginación ----------

it('listar solicitudes muestra el motivo de rechazo y la fecha de respuesta', function () {
    lsolCiclo();
    $asesor = lsolAsesor();
    $estudiante = lsolEstudiante();

    lsolInsertar($estudiante, $asesor, EstatusSolicitud::Rechazada, [
        'motivo_rechazo' => 'Ese día no puedo atender',
        'respondida_en' => now(),
    ]);

    $this->withToken(lsolToken($estudiante))
        ->getJson(URL_LSOL_ESTUDIANTE)
        ->assertOk()
        ->assertJsonPath('data.0.motivo_rechazo', 'Ese día no puedo atender')
        ->assertJsonStructure(['data' => [['respondida_en']]]);
});

it('listar solicitudes ordena las más recientes primero', function () {
    lsolCiclo();
    $asesor = lsolAsesor();
    $estudiante = lsolEstudiante();

    $vieja = lsolInsertar($estudiante, $asesor);
    $nueva = lsolInsertar($estudiante, $asesor);

    $this->withToken(lsolToken($estudiante))
        ->getJson(URL_LSOL_ESTUDIANTE)
        ->assertOk()
        ->assertJsonPath('data.0.id', $nueva->id)
        ->assertJsonPath('data.1.id', $vieja->id);
});

it('listar solicitudes pagina de 10 en 10', function () {
    lsolCiclo();
    $asesor = lsolAsesor();
    $estudiante = lsolEstudiante();

    foreach (range(1, 12) as $i) {
        lsolInsertar($estudiante, $asesor);
    }

    $token = lsolToken($estudiante);

    $this->withToken($token)->getJson(URL_LSOL_ESTUDIANTE)
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 12);

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson(URL_LSOL_ESTUDIANTE.'?page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});