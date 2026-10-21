<?php

use App\Enums\Dia;
use App\Enums\EstatusSolicitud;
use App\Enums\EstatusUsuario;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Models\CicloEscolar;
use App\Models\DisponibilidadHorario;
use App\Models\DisponibilidadMateria;
use App\Models\DisponibilidadModalidad;
use App\Models\Facultad;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Solicitud;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;

// POST /estudiante/solicitudes. Constante con nombre propio: las de un archivo de Pest son globales
const URL_SOLICITUDES = '/api/v1/estudiante/solicitudes';


// ---------- Ayudantes (prefijo "sol" para no chocar con otros archivos) ----------

function solLicenciatura(?int $facultadId = null): Licenciatura
{
    return Licenciatura::firstOrCreate([
        'facultad_id' => $facultadId ?? UsuarioFactory::facultad()->id,
        'nombre' => 'LICENCIATURA PRUEBA',
    ]);
}

function solMateria(string $nombre, ?Licenciatura $lic = null): Materia
{
    $lic ??= solLicenciatura();

    return Materia::firstOrCreate(
        ['licenciatura_id' => $lic->id, 'nombre' => $nombre],
        ['semestre' => 1]
    );
}

function solHorario(string $etiqueta): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Ciclo vigente. $finEn permite forzar una fecha_fin cercana (para el caso "fuera de ciclo")
function solCiclo(?string $finEn = null): CicloEscolar
{
    return CicloEscolar::create([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => '2026-2027 1',
        'periodo' => 1,
        'fecha_inicio' => now()->subMonth()->toDateString(),
        'fecha_fin' => $finEn ?? now()->addYear()->toDateString(),
        'vigente' => true,
    ]);
}

function solGrupo(Licenciatura $lic): Grupo
{
    return Grupo::create([
        'licenciatura_id' => $lic->id,
        'nombre' => '3-1',
        'anio' => 3,
    ]);
}

function solToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function solEstudiante(?Grupo $grupo = null): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create(['grupo_id' => $grupo?->id]);
}

// Asesor visible y completo (rol asesor, activo, con la materia/horario/modalidad dados).
// $datos sobrescribe columnas del usuario (nombre, facultad_id, estatus...)
function solAsesor(
    array $datos = [],
    string $materia = 'Estructura de Datos',
    Dia $dia = Dia::Lunes,
    string $horario = '7:00-8:00 AM',
    Modalidad $modalidad = Modalidad::Presencial,
): Usuario {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create($datos);

    DisponibilidadMateria::create(['facultad_id' => $asesor->facultad_id, 'asesor_id' => $asesor->id, 'materia_id' => solMateria($materia)->id]);
    DisponibilidadHorario::create(['facultad_id' => $asesor->facultad_id, 'asesor_id' => $asesor->id, 'dia' => $dia, 'horario_id' => solHorario($horario)->id]);
    DisponibilidadModalidad::create(['facultad_id' => $asesor->facultad_id, 'asesor_id' => $asesor->id, 'modalidad' => $modalidad]);

    return $asesor;
}

// Próxima fecha (siempre futura) que cae en el día de la semana pedido, como "2026-10-26"
function solFecha(int $diaSemana): string
{
    return Carbon::now()->next($diaSemana)->toDateString();
}

// Payload válido completo (asesor que da Estructura de Datos, lunes 7:00-8:00, presencial).
// $cambios sobrescribe lo que haga falta en cada prueba
function solPayload(Usuario $asesor, array $cambios = []): array
{
    return array_merge([
        'asesor_id' => $asesor->id,
        'materia_id' => solMateria('Estructura de Datos')->id,
        'horario_id' => solHorario('7:00-8:00 AM')->id,
        'fecha' => solFecha(Carbon::MONDAY),
        'modalidad' => Modalidad::Presencial->value,
        'razon' => RazonAsesoria::Reforzamiento->value,
        'nota' => 'Necesito ayuda con ejercicios',
    ], $cambios);
}


// ---------- Acceso ----------

it('solicitudes devuelve 401 sin sesión', function () {
    $this->postJson(URL_SOLICITUDES, [])->assertUnauthorized();
});

it('solicitudes rechaza con 403 a un asesor puro', function () {
    $this->withToken(solToken(Usuario::factory()->conRol(Rol::Asesor)->create()))
        ->postJson(URL_SOLICITUDES, [])
        ->assertForbidden();
});

it('solicitudes bloquea con 403 a un estudiante con cuenta no activa', function () {
    $estudiante = Usuario::factory()->pendiente()->conRol(Rol::Estudiante)->create(['password' => 'secreto123']);

    $this->withToken(solToken($estudiante))
        ->postJson(URL_SOLICITUDES, [])
        ->assertForbidden();
});


// ---------- Validación de forma (CrearSolicitudRequest) ----------

it('solicitudes responde 422 si faltan los campos obligatorios', function () {
    solCiclo();

    $this->withToken(solToken(solEstudiante()))
        ->postJson(URL_SOLICITUDES, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['asesor_id', 'materia_id', 'horario_id', 'fecha', 'modalidad', 'razon']);
});

it('solicitudes responde 422 con una materia, horario, modalidad o razón inválidos', function () {
    solCiclo();
    $asesor = solAsesor();
    $token = solToken(solEstudiante());

    $this->withToken($token)
        ->postJson(URL_SOLICITUDES, solPayload($asesor, ['materia_id' => 999999]))
        ->assertJsonValidationErrors(['materia_id']);

    $this->withToken($token)
        ->postJson(URL_SOLICITUDES, solPayload($asesor, ['horario_id' => 999999]))
        ->assertJsonValidationErrors(['horario_id']);

    $this->withToken($token)
        ->postJson(URL_SOLICITUDES, solPayload($asesor, ['modalidad' => 'hibrida']))
        ->assertJsonValidationErrors(['modalidad']);

    $this->withToken($token)
        ->postJson(URL_SOLICITUDES, solPayload($asesor, ['razon' => 'inventada']))
        ->assertJsonValidationErrors(['razon']);
});

it('solicitudes responde 422 con una fecha pasada, mal formada, o una nota muy larga', function () {
    solCiclo();
    $asesor = solAsesor();
    $token = solToken(solEstudiante());

    $this->withToken($token)
        ->postJson(URL_SOLICITUDES, solPayload($asesor, ['fecha' => now()->subDay()->toDateString()]))
        ->assertJsonValidationErrors(['fecha']);

    $this->withToken($token)
        ->postJson(URL_SOLICITUDES, solPayload($asesor, ['fecha' => '26/10/2026']))
        ->assertJsonValidationErrors(['fecha']);

    $this->withToken($token)
        ->postJson(URL_SOLICITUDES, solPayload($asesor, ['nota' => str_repeat('a', 501)]))
        ->assertJsonValidationErrors(['nota']);
});


// ---------- Visibilidad del asesor (mismo filtro que /estudiante/asesores) ----------

it('solicitudes responde 404 si el asesor no existe', function () {
    solCiclo();

    $this->withToken(solToken(solEstudiante()))
        ->postJson(URL_SOLICITUDES, solPayload(solAsesor(), ['asesor_id' => 999999]))
        ->assertNotFound();
});

it('solicitudes responde 404 si el "asesor" no tiene ese rol', function () {
    solCiclo();
    $soloEstudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();

    $this->withToken(solToken(solEstudiante()))
        ->postJson(URL_SOLICITUDES, solPayload($soloEstudiante))
        ->assertNotFound();
});

it('solicitudes responde 404 con un asesor de otra facultad', function () {
    solCiclo();
    $otra = Facultad::forceCreate(['nombre' => 'Otra Facultad', 'siglas' => 'OTR', 'activa' => true]);
    $ajeno = solAsesor(['facultad_id' => $otra->id]);

    $this->withToken(solToken(solEstudiante()))
        ->postJson(URL_SOLICITUDES, solPayload($ajeno))
        ->assertNotFound();
});

it('solicitudes responde 404 con un asesor inactivo', function () {
    solCiclo();
    $inactivo = solAsesor(['estatus' => EstatusUsuario::Inactivo]);

    $this->withToken(solToken(solEstudiante()))
        ->postJson(URL_SOLICITUDES, solPayload($inactivo))
        ->assertNotFound();
});

it('solicitudes responde 404 si el estudiante intenta pedirse a sí mismo (asesor par)', function () {
    solCiclo();
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    DisponibilidadMateria::create(['facultad_id' => $par->facultad_id, 'asesor_id' => $par->id, 'materia_id' => solMateria('Estructura de Datos')->id]);
    DisponibilidadHorario::create(['facultad_id' => $par->facultad_id, 'asesor_id' => $par->id, 'dia' => Dia::Lunes, 'horario_id' => solHorario('7:00-8:00 AM')->id]);
    DisponibilidadModalidad::create(['facultad_id' => $par->facultad_id, 'asesor_id' => $par->id, 'modalidad' => Modalidad::Presencial]);

    $this->withToken(solToken($par))
        ->postJson(URL_SOLICITUDES, solPayload($par))
        ->assertNotFound();
});


// ---------- El asesor debe ofrecer lo pedido ----------

it('solicitudes responde 422 materia_no_ofrecida si el asesor no da esa materia', function () {
    solCiclo();
    $asesor = solAsesor([], 'Estructura de Datos');
    $otraMateria = solMateria('Cálculo Integral');

    $this->withToken(solToken(solEstudiante()))
        ->postJson(URL_SOLICITUDES, solPayload($asesor, ['materia_id' => $otraMateria->id]))
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'materia_no_ofrecida');
});

it('solicitudes responde 422 modalidad_no_ofrecida si el asesor no da esa modalidad', function () {
    solCiclo();
    $asesor = solAsesor([], 'Estructura de Datos', Dia::Lunes, '7:00-8:00 AM', Modalidad::Presencial);

    $this->withToken(solToken(solEstudiante()))
        ->postJson(URL_SOLICITUDES, solPayload($asesor, ['modalidad' => Modalidad::Virtual->value]))
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'modalidad_no_ofrecida');
});

it('solicitudes responde 422 horario_no_disponible si el asesor no tiene ese horario ese día', function () {
    // Solo ofrece lunes 7:00; se pide miércoles con ese mismo horario
    $asesor = solAsesor([], 'Estructura de Datos', Dia::Lunes, '7:00-8:00 AM');
    solCiclo();

    $this->withToken(solToken(solEstudiante()))
        ->postJson(URL_SOLICITUDES, solPayload($asesor, ['fecha' => solFecha(Carbon::WEDNESDAY)]))
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'horario_no_disponible');
});

it('solicitudes responde 422 dia_no_disponible si la fecha cae en fin de semana', function () {
    solCiclo();
    $asesor = solAsesor();

    $this->withToken(solToken(solEstudiante()))
        ->postJson(URL_SOLICITUDES, solPayload($asesor, ['fecha' => solFecha(Carbon::SATURDAY)]))
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'dia_no_disponible');
});


// ---------- Ciclo escolar ----------

it('solicitudes responde 409 sin_ciclo si no hay ciclo vigente', function () {
    $asesor = solAsesor();

    $this->withToken(solToken(solEstudiante()))
        ->postJson(URL_SOLICITUDES, solPayload($asesor))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'sin_ciclo');
});

it('solicitudes responde 422 fuera_de_ciclo si la fecha pasa de fecha_fin del ciclo', function () {
    // El ciclo termina mañana; el próximo lunes queda fuera
    solCiclo(now()->addDay()->toDateString());
    $asesor = solAsesor();

    $this->withToken(solToken(solEstudiante()))
        ->postJson(URL_SOLICITUDES, solPayload($asesor, ['fecha' => solFecha(Carbon::MONDAY)]))
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'fuera_de_ciclo');
});


// ---------- Caso feliz y duplicados ----------

it('solicitudes crea la solicitud y copia ciclo y grupo del estudiante', function () {
    $ciclo = solCiclo();
    $lic = solLicenciatura();
    $grupo = solGrupo($lic);
    $asesor = solAsesor([], 'Estructura de Datos', Dia::Lunes, '7:00-8:00 AM', Modalidad::Virtual);
    $estudiante = solEstudiante($grupo);

    $r = $this->withToken(solToken($estudiante))
        ->postJson(URL_SOLICITUDES, solPayload($asesor, [
            'modalidad' => Modalidad::Virtual->value,
            'razon' => RazonAsesoria::Parcial->value,
            'nota' => 'Necesito ayuda con arboles',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.estatus', 'pendiente')
        ->assertJsonPath('data.asesor.id', $asesor->id)
        ->assertJsonPath('data.modalidad.valor', 'virtual')
        ->assertJsonPath('data.razon.valor', 'parcial')
        ->assertJsonPath('data.nota', 'Necesito ayuda con arboles');

    $this->assertDatabaseHas('solicitudes', [
        'id' => $r->json('data.id'),
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'ciclo_escolar_id' => $ciclo->id,
        'grupo_id' => $grupo->id,
        'estatus' => EstatusSolicitud::Pendiente->value,
    ]);
});

it('solicitudes responde 409 solicitud_duplicada con la misma combinación mientras siga pendiente', function () {
    solCiclo();
    $asesor = solAsesor();
    $token = solToken(solEstudiante());
    $payload = solPayload($asesor);

    $this->withToken($token)->postJson(URL_SOLICITUDES, $payload)->assertCreated();

    $this->withToken($token)
        ->postJson(URL_SOLICITUDES, $payload)
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'solicitud_duplicada');
});

it('solicitudes permite volver a pedir si la anterior ya no está pendiente', function () {
    solCiclo();
    $asesor = solAsesor();
    $estudiante = solEstudiante();
    $token = solToken($estudiante);
    $payload = solPayload($asesor);

    $this->withToken($token)->postJson(URL_SOLICITUDES, $payload)->assertCreated();

    // La anterior ya se resolvió (aceptada); no cuenta como duplicado
    Solicitud::where('estudiante_id', $estudiante->id)->update(['estatus' => EstatusSolicitud::Aceptada]);

    $this->withToken($token)->postJson(URL_SOLICITUDES, $payload)->assertCreated();

    expect(Solicitud::where('estudiante_id', $estudiante->id)->count())->toBe(2);
});

it('solicitudes no choca con una solicitud de otro estudiante en el mismo horario', function () {
    solCiclo();
    $asesor = solAsesor();
    $payload = solPayload($asesor);

    $this->withToken(solToken(solEstudiante()))->postJson(URL_SOLICITUDES, $payload)->assertCreated();

    // Mismo asesor, materia, horario y fecha, pero OTRO estudiante: no es duplicado
    $this->withToken(solToken(solEstudiante()))->postJson(URL_SOLICITUDES, $payload)->assertCreated();
});