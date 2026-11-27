<?php

use App\Enums\Dia;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Models\CicloEscolar;
use App\Models\DisponibilidadHorario;
use App\Models\DisponibilidadMateria;
use App\Models\DisponibilidadModalidad;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Solicitud;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;

use App\Enums\EstatusAsesoria;
use App\Models\Asesoria;

// Constantes con nombre propio: las de un archivo de Pest son globales
const URL_CARGA_ASESORES = '/api/v1/estudiante/asesores';
const URL_CARGA_SOLICITUDES = '/api/v1/estudiante/solicitudes';


// ---------- Ayudantes (prefijo "carg" para no chocar con otros archivos) ----------

function cargLicenciatura(string $nombre): Licenciatura
{
    return Licenciatura::create([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => $nombre,
    ]);
}

function cargMateria(Licenciatura $lic, int $semestre, string $nombre): Materia
{
    return Materia::create(['licenciatura_id' => $lic->id, 'semestre' => $semestre, 'nombre' => $nombre]);
}

// Ciclo vigente con el periodo dado (1 o 2); termina dentro de un año para poder crear solicitudes
function cargCiclo(int $periodo = 1): CicloEscolar
{
    return CicloEscolar::create([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => "2026-2027 {$periodo}",
        'periodo' => $periodo,
        'fecha_inicio' => now()->subMonth()->toDateString(),
        'fecha_fin' => now()->addYear()->toDateString(),
        'vigente' => true,
    ]);
}

function cargGrupo(Licenciatura $lic, int $anio): Grupo
{
    return Grupo::create(['licenciatura_id' => $lic->id, 'nombre' => "{$anio}-1", 'anio' => $anio]);
}

function cargEstudiante(?Grupo $grupo): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create(['grupo_id' => $grupo?->id]);
}

function cargToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function cargHorario(): Horario
{
    return Horario::firstOrCreate(['etiqueta' => '7:00-8:00 AM']);
}

// Asesor visible y completo (lunes 7:00-8:00, presencial) que da TODAS las materias indicadas
function cargAsesor(array $materias): Usuario
{
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();

    foreach ($materias as $materia) {
        DisponibilidadMateria::create(['facultad_id' => $asesor->facultad_id, 'asesor_id' => $asesor->id, 'materia_id' => $materia->id]);
    }

    DisponibilidadHorario::create(['facultad_id' => $asesor->facultad_id, 'asesor_id' => $asesor->id, 'dia' => Dia::Lunes, 'horario_id' => cargHorario()->id]);
    DisponibilidadModalidad::create(['facultad_id' => $asesor->facultad_id, 'asesor_id' => $asesor->id, 'modalidad' => Modalidad::Presencial]);

    return $asesor;
}

// Payload válido para pedir la materia dada a ese asesor
function cargPayload(Usuario $asesor, Materia $materia): array
{
    return [
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => cargHorario()->id,
        'fecha' => Carbon::now()->next(Carbon::MONDAY)->toDateString(),
        'modalidad' => Modalidad::Presencial->value,
        'razon' => RazonAsesoria::Reforzamiento->value,
    ];
}

// Escenario base: Informática, grupo de año 2 en el periodo 1 => cursa el semestre 3.
// Su carga: materias de Informática de semestre 1 a 3. Fuera de su carga: semestre 5 y otra licenciatura.
function cargEscenario(): array
{
    cargCiclo(1);

    $info = cargLicenciatura('INFORMATICA');
    $datos = cargLicenciatura('CIENCIA DE DATOS');

    return [
        'estudiante' => cargEstudiante(cargGrupo($info, 2)),
        'info' => $info,
        'programacion' => cargMateria($info, 1, 'Programación'),        // en su carga
        'estructuras' => cargMateria($info, 3, 'Estructura de Datos'),   // en su carga (su semestre)
        'algebra' => cargMateria($info, 5, 'Álgebra Lineal'),            // semestre que aún no cursa
        'calculo' => cargMateria($datos, 1, 'Cálculo'),                  // otra licenciatura
    ];
}

// Ids de la lista de asesores del estudiante, en el orden recibido
function cargIds(Usuario $estudiante, string $query = ''): array
{
    $respuesta = test()->withToken(cargToken($estudiante))->getJson(URL_CARGA_ASESORES.$query)->assertOk();

    return collect($respuesta->json('data'))->pluck('id')->all();
}


// ---------- La lista solo trae asesores que dan materias de su carga ----------

it('carga: la lista incluye al asesor que da una materia de su carga', function () {
    $e = cargEscenario();
    $asesor = cargAsesor([$e['programacion']]);

    expect(cargIds($e['estudiante']))->toBe([$asesor->id]);
});

it('carga: la lista excluye al asesor que solo da materias de un semestre que no cursa', function () {
    $e = cargEscenario();
    $visible = cargAsesor([$e['estructuras']]);
    cargAsesor([$e['algebra']]);

    expect(cargIds($e['estudiante']))->toBe([$visible->id]);
});

it('carga: la lista excluye al asesor que solo da materias de otra licenciatura', function () {
    $e = cargEscenario();
    $visible = cargAsesor([$e['programacion']]);
    cargAsesor([$e['calculo']]);

    expect(cargIds($e['estudiante']))->toBe([$visible->id]);
});

it('carga: filtrar por una materia fuera de su carga no regresa a nadie', function () {
    $e = cargEscenario();
    cargAsesor([$e['algebra']]);
    cargAsesor([$e['programacion'], $e['algebra']]);

    expect(cargIds($e['estudiante'], '?materia_id='.$e['algebra']->id))->toBe([]);
});

it('carga: la tarjeta del asesor muestra solo las materias que el estudiante puede pedirle', function () {
    $e = cargEscenario();
    cargAsesor([$e['programacion'], $e['algebra'], $e['calculo']]);

    $respuesta = $this->withToken(cargToken($e['estudiante']))
        ->getJson(URL_CARGA_ASESORES)
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($respuesta->json('data.0.disponibilidad.materias.*.nombre'))->toBe(['Programación']);
});

it('carga: al avanzar el periodo del ciclo se suman las materias del semestre siguiente', function () {
    // Año 2, periodo 2 => semestre 4: ahora su carga llega hasta ese semestre
    cargCiclo(2);
    $info = cargLicenciatura('INFORMATICA');
    $estudiante = cargEstudiante(cargGrupo($info, 2));
    $semestre4 = cargMateria($info, 4, 'Redes');
    $semestre5 = cargMateria($info, 5, 'Álgebra Lineal');

    $conRedes = cargAsesor([$semestre4]);
    cargAsesor([$semestre5]);

    expect(cargIds($estudiante))->toBe([$conRedes->id]);
});


// ---------- Crear solicitud ----------

it('carga: crea la solicitud de una materia de su carga', function () {
    $e = cargEscenario();
    $asesor = cargAsesor([$e['estructuras']]);

    $this->withToken(cargToken($e['estudiante']))
        ->postJson(URL_CARGA_SOLICITUDES, cargPayload($asesor, $e['estructuras']))
        ->assertCreated();

    expect(Solicitud::count())->toBe(1);
});

it('carga: no deja solicitar una materia de un semestre que no cursa aunque el asesor la dé', function () {
    $e = cargEscenario();
    // El asesor da una materia de su carga y otra que no: la segunda no se puede pedir
    $asesor = cargAsesor([$e['programacion'], $e['algebra']]);

    $this->withToken(cargToken($e['estudiante']))
        ->postJson(URL_CARGA_SOLICITUDES, cargPayload($asesor, $e['algebra']))
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'materia_no_ofrecida');

    expect(Solicitud::count())->toBe(0);
});

it('carga: no deja solicitar una materia de otra licenciatura', function () {
    $e = cargEscenario();
    $asesor = cargAsesor([$e['programacion'], $e['calculo']]);

    $this->withToken(cargToken($e['estudiante']))
        ->postJson(URL_CARGA_SOLICITUDES, cargPayload($asesor, $e['calculo']))
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'materia_no_ofrecida');
});

it('carga: responde 404 al pedir con un asesor que no da nada de su carga', function () {
    $e = cargEscenario();
    $asesor = cargAsesor([$e['algebra']]);

    $this->withToken(cargToken($e['estudiante']))
        ->postJson(URL_CARGA_SOLICITUDES, cargPayload($asesor, $e['algebra']))
        ->assertNotFound();
});


// ---------- Cuenta sin grupo o sin ciclo ----------

it('carga: responde 409 sin_grupo si el estudiante no tiene grupo', function () {
    cargCiclo(1);

    $this->withToken(cargToken(cargEstudiante(null)))
        ->getJson(URL_CARGA_ASESORES)
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'sin_grupo');
});

it('carga: responde 409 sin_ciclo si no hay un ciclo vigente', function () {
    $info = cargLicenciatura('INFORMATICA');

    $this->withToken(cargToken(cargEstudiante(cargGrupo($info, 2))))
        ->getJson(URL_CARGA_ASESORES)
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'sin_ciclo');
});

// ---------- Horarios ocupados ----------

// Asesoría en curso del asesor el próximo lunes a las 7:00-8:00 (el horario que da cargAsesor)
function cargOcupar(array $e, Usuario $asesor, EstatusAsesoria $estatus = EstatusAsesoria::EnCurso): Asesoria
{
    return Asesoria::create([
        'facultad_id' => $e['estudiante']->facultad_id,
        'ciclo_escolar_id' => CicloEscolar::first()->id,
        'grupo_id' => $e['estudiante']->grupo_id,
        'estudiante_id' => $e['estudiante']->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $e['programacion']->id,
        'horario_id' => cargHorario()->id,
        'fecha_inicio' => Carbon::now()->next(Carbon::MONDAY)->toDateString(),
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => $estatus,
    ]);
}

// Le da al asesor también el martes 7:00-8:00 (queda libre)
function cargDarMartes(Usuario $asesor): void
{
    DisponibilidadHorario::create([
        'facultad_id' => $asesor->facultad_id,
        'asesor_id' => $asesor->id,
        'dia' => Dia::Martes,
        'horario_id' => cargHorario()->id,
    ]);
}

it('carga: el horario con una asesoría en curso no aparece en la tarjeta', function () {
    $e = cargEscenario();
    $asesor = cargAsesor([$e['programacion']]);
    cargDarMartes($asesor);
    cargOcupar($e, $asesor);

    $respuesta = $this->withToken(cargToken($e['estudiante']))
        ->getJson(URL_CARGA_ASESORES)
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Lunes (ocupado) sale vacío; martes (libre) conserva su horario
    expect($respuesta->json('data.0.disponibilidad.horarios.0.horarios'))->toBe([]);
    expect($respuesta->json('data.0.disponibilidad.horarios.1.horarios'))->toHaveCount(1);
});

it('carga: el asesor con todos sus horarios ocupados no aparece en la lista', function () {
    $e = cargEscenario();
    $asesor = cargAsesor([$e['programacion']]);
    cargOcupar($e, $asesor);

    expect(cargIds($e['estudiante']))->toBe([]);
});

it('carga: al completarse la asesoría el horario vuelve a ofrecerse', function () {
    $e = cargEscenario();
    $asesor = cargAsesor([$e['programacion']]);
    $asesoria = cargOcupar($e, $asesor);

    expect(cargIds($e['estudiante']))->toBe([]);

    $asesoria->update(['estatus' => EstatusAsesoria::Completada]);

    expect(cargIds($e['estudiante']))->toBe([$asesor->id]);
});

it('carga: filtrar por el día y la hora de un horario ocupado no regresa a ese asesor', function () {
    $e = cargEscenario();
    $asesor = cargAsesor([$e['programacion']]);
    cargDarMartes($asesor);
    cargOcupar($e, $asesor);

    $lunes = Carbon::now()->next(Carbon::MONDAY)->toDateString();
    $martes = Carbon::now()->next(Carbon::TUESDAY)->toDateString();

    expect(cargIds($e['estudiante'], '?fecha='.$lunes.'&horario_id='.cargHorario()->id))->toBe([]);
    expect(cargIds($e['estudiante'], '?fecha='.$martes.'&horario_id='.cargHorario()->id))->toBe([$asesor->id]);
});

it('carga: no deja solicitar un horario ocupado por una asesoría en curso', function () {
    $e = cargEscenario();
    $asesor = cargAsesor([$e['programacion']]);
    cargDarMartes($asesor); // sigue visible gracias al martes
    cargOcupar($e, $asesor);

    $this->withToken(cargToken($e['estudiante']))
        ->postJson(URL_CARGA_SOLICITUDES, cargPayload($asesor, $e['programacion']))
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'horario_no_disponible');

    expect(Solicitud::count())->toBe(0);
});