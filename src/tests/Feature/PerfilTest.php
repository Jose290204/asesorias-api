<?php

use App\Enums\EstatusAsesoria;
use App\Enums\EstatusSolicitud;
use App\Enums\Rol;
use App\Models\Asesoria;
use App\Models\CicloEscolar;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Solicitud;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;

const URL_PERFIL_ESTUDIANTE = '/api/v1/estudiante/perfil';
const URL_PERFIL_ASESOR = '/api/v1/asesor/perfil';


// ---------- Ayudantes (prefijo "perf") ----------

// Datos que toda solicitud o asesoría necesita; se reutilizan si ya existen
function perfBase(): array
{
    $facultad = UsuarioFactory::facultad()->id;

    $lic = Licenciatura::firstOrCreate(['facultad_id' => $facultad, 'nombre' => 'LICENCIATURA PRUEBA']);
    $materia = Materia::firstOrCreate(
        ['licenciatura_id' => $lic->id, 'nombre' => 'Materia de prueba'],
        ['semestre' => 1]
    );
    $ciclo = CicloEscolar::firstOrCreate(
        ['facultad_id' => $facultad, 'nombre' => '2026-2027 1'],
        ['periodo' => 1, 'fecha_inicio' => '2026-08-10', 'fecha_fin' => '2027-01-15', 'vigente' => true]
    );
    $horario = Horario::firstOrCreate(['etiqueta' => '7:00-8:00 AM']);

    return [
        'facultad_id' => $facultad,
        'ciclo_escolar_id' => $ciclo->id,
        'materia_id' => $materia->id,
        'horario_id' => $horario->id,
        'fecha_inicio' => '2026-10-20',
        'modalidad' => 'virtual',
        'razon' => 'parcial',
    ];
}

function perfSolicitud(Usuario $estudiante, Usuario $asesor, EstatusSolicitud $estatus): Solicitud
{
    return Solicitud::create(perfBase() + [
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'estatus' => $estatus,
    ]);
}

function perfAsesoria(Usuario $estudiante, Usuario $asesor, EstatusAsesoria $estatus): Asesoria
{
    return Asesoria::create(perfBase() + [
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'estatus' => $estatus,
    ]);
}

function perfToken(Usuario $u): string
{
    return $u->createToken('prueba')->plainTextToken;
}


// ---------- Sin sesión ----------

it('los perfiles devuelven 401 sin sesión', function () {
    $this->getJson(URL_PERFIL_ESTUDIANTE)->assertUnauthorized();
    $this->getJson(URL_PERFIL_ASESOR)->assertUnauthorized();
});


// ---------- Estudiante ----------

it('estudiante/perfil devuelve sus datos y sus contadores, sin sumar los de otros', function () {
    $yo = Usuario::factory()->conRol(Rol::Estudiante)->create();
    $otro = Usuario::factory()->conRol(Rol::Estudiante)->create();
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();

    perfAsesoria($yo, $asesor, EstatusAsesoria::Completada);
    perfAsesoria($yo, $asesor, EstatusAsesoria::EnCurso);
    perfAsesoria($yo, $asesor, EstatusAsesoria::Cancelada);   // no cuenta en ningún contador
    perfSolicitud($yo, $asesor, EstatusSolicitud::Pendiente);
    perfSolicitud($yo, $asesor, EstatusSolicitud::Rechazada); // no cuenta

    // De otro estudiante: no deben sumarse
    perfAsesoria($otro, $asesor, EstatusAsesoria::Completada);
    perfSolicitud($otro, $asesor, EstatusSolicitud::Pendiente);

    $this->withToken(perfToken($yo))
        ->getJson(URL_PERFIL_ESTUDIANTE)
        ->assertOk()
        ->assertJsonPath('data.correo', $yo->correo)
        ->assertJsonPath('data.estadisticas.completadas', 1)
        ->assertJsonPath('data.estadisticas.en_curso', 1)
        ->assertJsonPath('data.estadisticas.solicitudes_en_revision', 1);
});

it('estudiante/perfil rechaza con 403 a un asesor puro', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();

    $this->withToken(perfToken($asesor))->getJson(URL_PERFIL_ESTUDIANTE)->assertForbidden();
});


// ---------- Asesor ----------

it('asesor/perfil devuelve sus contadores, sin sumar los de otro asesor', function () {
    $estudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();
    $yo = Usuario::factory()->conRol(Rol::Asesor)->create();
    $otroAsesor = Usuario::factory()->conRol(Rol::Asesor)->create();

    perfAsesoria($estudiante, $yo, EstatusAsesoria::Completada);
    perfAsesoria($estudiante, $yo, EstatusAsesoria::EnCurso);
    perfSolicitud($estudiante, $yo, EstatusSolicitud::Pendiente);
    perfSolicitud($estudiante, $yo, EstatusSolicitud::Aceptada); // no cuenta

    // De otro asesor: no deben sumarse
    perfAsesoria($estudiante, $otroAsesor, EstatusAsesoria::Completada);
    perfSolicitud($estudiante, $otroAsesor, EstatusSolicitud::Pendiente);

    $this->withToken(perfToken($yo))
        ->getJson(URL_PERFIL_ASESOR)
        ->assertOk()
        ->assertJsonPath('data.estadisticas.completadas', 1)
        ->assertJsonPath('data.estadisticas.en_curso', 1)
        ->assertJsonPath('data.estadisticas.pendientes_por_responder', 1);
});

it('asesor/perfil rechaza con 403 a un estudiante puro', function () {
    $estudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();

    $this->withToken(perfToken($estudiante))->getJson(URL_PERFIL_ASESOR)->assertForbidden();
});


// ---------- Asesor par (roles 3 y 4) ----------

it('el asesor par entra a las dos rutas y cada una cuenta lo suyo', function () {
    $par = Usuario::factory()->conRol(Rol::Asesor, Rol::Estudiante)->create();
    $otro = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();

    // Como asesor, el par tiene una asesoría completada
    perfAsesoria($otro, $par, EstatusAsesoria::Completada);
    // Como estudiante, el par tiene una asesoría en curso
    perfAsesoria($par, $otro, EstatusAsesoria::EnCurso);

    $token = perfToken($par);

    $this->withToken($token)
        ->getJson(URL_PERFIL_ESTUDIANTE)
        ->assertOk()
        ->assertJsonPath('data.estadisticas.en_curso', 1)
        ->assertJsonPath('data.estadisticas.completadas', 0);

    $this->withToken($token)
        ->getJson(URL_PERFIL_ASESOR)
        ->assertOk()
        ->assertJsonPath('data.estadisticas.completadas', 1)
        ->assertJsonPath('data.estadisticas.en_curso', 0);
});
