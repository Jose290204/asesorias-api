<?php



use App\Enums\EstatusAsesoria;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Models\Asesoria;
use App\Models\Chat;
use App\Models\CicloEscolar;
use App\Models\Facultad;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\MaterialAdicional;
use App\Models\Sesion;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;

const URL_ADF_PERFIL = '/api/v1/admin/perfil';

// ---------- Ayudantes ----------

function adfOtraFacultad(): Facultad
{
    return UsuarioFactory::facultad('OTR');
}

function adfUsuario(Rol $rol, ?Facultad $facultad = null): Usuario
{
    return Usuario::factory()
        ->conRol($rol)
        ->create(['facultad_id' => ($facultad ?? UsuarioFactory::facultad('FIC'))->id]);
}

function adfToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

// Asesoría insertada directo en la BD, en la facultad del estudiante
function adfAsesoria(
    Usuario $estudiante,
    Usuario $asesor,
    EstatusAsesoria $estatus = EstatusAsesoria::EnCurso,
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

    $ciclo = CicloEscolar::first() ?? CicloEscolar::create([
        'facultad_id' => UsuarioFactory::facultad('FIC')->id,
        'nombre' => '2026-2027 1',
        'periodo' => 1,
        'fecha_inicio' => now()->subMonth()->toDateString(),
        'fecha_fin' => now()->addYear()->toDateString(),
        'vigente' => true,
    ]);

    return Asesoria::create([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => $ciclo->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => adfHorario()->id,
        'fecha_inicio' => Carbon::now()->subWeeks(3)->toDateString(),
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => $estatus,
    ]);
}

function adfHorario(): Horario
{
    return Horario::firstOrCreate(['etiqueta' => '7:00-8:00 AM']);
}

function adfSesion(Asesoria $asesoria): Sesion
{
    return Sesion::create([
        'asesoria_id' => $asesoria->id,
        'fecha' => Carbon::now()->subWeek()->toDateString(),
        'horario_id' => adfHorario()->id,
        'registrada_por' => $asesoria->asesor_id,
    ]);
}

function adfMaterial(Asesoria $asesoria): MaterialAdicional
{
    return MaterialAdicional::create([
        'asesoria_id' => $asesoria->id,
        'usuario_id' => $asesoria->asesor_id,
        'nombre_archivo' => 'archivo.pdf',
        'ruta' => "materiales/{$asesoria->id}/guardado.pdf",
        'disco' => 'local',
        'mime_type' => 'application/pdf',
        'tamano' => 100,
    ]);
}

// ---------- GET /admin/perfil: acceso ----------

it('adf: perfil devuelve 401 sin sesión', function () {
    $this->getJson(URL_ADF_PERFIL)->assertUnauthorized();
});

it('adf: perfil rechaza con 403 a un asesor y a un estudiante', function () {
    $this->withToken(adfToken(adfUsuario(Rol::Asesor)))
        ->getJson(URL_ADF_PERFIL)->assertForbidden();

    $this->withToken(adfToken(adfUsuario(Rol::Estudiante)))
        ->getJson(URL_ADF_PERFIL)->assertForbidden();
});

it('adf: perfil bloquea con 403 a un admin con cuenta no activa', function () {
    $admin = Usuario::factory()->pendiente()->conRol(Rol::Admin)->create();

    $this->withToken(adfToken($admin))
        ->getJson(URL_ADF_PERFIL)->assertForbidden();
});

it('adf: el super admin sin X-Facultad-Id recibe 422 facultad_requerida', function () {
    $this->withToken(adfToken(adfUsuario(Rol::SuperAdmin)))
        ->getJson(URL_ADF_PERFIL)
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'facultad_requerida');
});

it('adf: el super admin con X-Facultad-Id obtiene los contadores de esa facultad', function () {
    $otra = adfOtraFacultad();
    $estOtra = adfUsuario(Rol::Estudiante, $otra);
    $asOtra = adfUsuario(Rol::Asesor, $otra);
    adfAsesoria($estOtra, $asOtra);
    $superAdmin = adfUsuario(Rol::SuperAdmin);

    $this->withToken(adfToken($superAdmin))
        ->withHeader('X-Facultad-Id', (string) $otra->id)
        ->getJson(URL_ADF_PERFIL)
        ->assertOk()
        ->assertJsonPath('data.estadisticas.asesorias_en_curso', 1)
        ->assertJsonPath('data.estadisticas.usuarios_activos', 2);
});

// ---------- GET /admin/perfil: contenido ----------

it('adf: el admin obtiene su perfil con los contadores solo de su facultad', function () {
    $otra = adfOtraFacultad();

    // FIC: admin + estudiante + asesor activos; una asesoría en curso y otra completada
    $admin = adfUsuario(Rol::Admin);
    $estudiante = adfUsuario(Rol::Estudiante);
    $asesor = adfUsuario(Rol::Asesor);
    adfAsesoria($estudiante, $asesor);
    adfAsesoria($estudiante, $asesor, EstatusAsesoria::Completada);

    // Otra facultad: no debe contar
    $estOtra = adfUsuario(Rol::Estudiante, $otra);
    $asOtra = adfUsuario(Rol::Asesor, $otra);
    adfAsesoria($estOtra, $asOtra);

    // El super admin (ancla FIC) tampoco cuenta como usuario de la facultad
    adfUsuario(Rol::SuperAdmin);

    $this->withToken(adfToken($admin))
        ->getJson(URL_ADF_PERFIL)
        ->assertOk()
        ->assertJsonPath('data.id', $admin->id)
        ->assertJsonPath('data.correo', $admin->correo)
        ->assertJsonPath('data.estadisticas.asesorias_en_curso', 1)
        ->assertJsonPath('data.estadisticas.solicitudes_pendientes', 0)
        ->assertJsonPath('data.estadisticas.usuarios_activos', 3);
});

// ---------- Permisos del admin sobre asesorías (policies) ----------

it('adf: el admin ve, gestiona y elimina sesiones y material de una asesoría ajena', function () {
    $asesoria = adfAsesoria(adfUsuario(Rol::Estudiante), adfUsuario(Rol::Asesor));
    $sesion = adfSesion($asesoria);
    $material = adfMaterial($asesoria);
    $admin = adfUsuario(Rol::Admin);

    expect($admin->can('ver', $asesoria))->toBeTrue()
        ->and($admin->can('gestionar', $asesoria))->toBeTrue()
        ->and($admin->can('eliminar', $sesion))->toBeTrue()
        ->and($admin->can('descargar', $material))->toBeTrue()
        ->and($admin->can('eliminar', $material))->toBeTrue();
});

it('adf: el super admin tiene los mismos permisos de administración', function () {
    $asesoria = adfAsesoria(adfUsuario(Rol::Estudiante), adfUsuario(Rol::Asesor));
    $superAdmin = adfUsuario(Rol::SuperAdmin);

    expect($superAdmin->can('ver', $asesoria))->toBeTrue()
        ->and($superAdmin->can('gestionar', $asesoria))->toBeTrue();
});

it('adf: el admin NUNCA puede escribir en el chat de una asesoría', function () {
    $asesoria = adfAsesoria(adfUsuario(Rol::Estudiante), adfUsuario(Rol::Asesor));
    $admin = adfUsuario(Rol::Admin);
    $superAdmin = adfUsuario(Rol::SuperAdmin);

    expect($admin->can('escribir', [Chat::class, $asesoria]))->toBeFalse()
        ->and($superAdmin->can('escribir', [Chat::class, $asesoria]))->toBeFalse()
        // Sus dos participantes siguen pudiendo
        ->and($asesoria->estudiante->can('escribir', [Chat::class, $asesoria]))->toBeTrue()
        ->and($asesoria->asesor->can('escribir', [Chat::class, $asesoria]))->toBeTrue();
});

it('adf: un asesor ajeno sigue sin ver ni gestionar la asesoría', function () {
    $asesoria = adfAsesoria(adfUsuario(Rol::Estudiante), adfUsuario(Rol::Asesor));
    $otroAsesor = adfUsuario(Rol::Asesor);
    $sesion = adfSesion($asesoria);
    $material = adfMaterial($asesoria);

    expect($otroAsesor->can('ver', $asesoria))->toBeFalse()
        ->and($otroAsesor->can('gestionar', $asesoria))->toBeFalse()
        ->and($otroAsesor->can('eliminar', $sesion))->toBeFalse()
        ->and($otroAsesor->can('eliminar', $material))->toBeFalse();
});

it('adf: los participantes conservan sus permisos y el estudiante no gestiona', function () {
    $asesoria = adfAsesoria(adfUsuario(Rol::Estudiante), adfUsuario(Rol::Asesor));
    $sesion = adfSesion($asesoria);

    expect($asesoria->estudiante->can('ver', $asesoria))->toBeTrue()
        ->and($asesoria->estudiante->can('gestionar', $asesoria))->toBeFalse()
        ->and($asesoria->asesor->can('ver', $asesoria))->toBeTrue()
        ->and($asesoria->asesor->can('gestionar', $asesoria))->toBeTrue()
        ->and($asesoria->asesor->can('eliminar', $sesion))->toBeTrue();
});

// ---------- Permisos del admin por HTTP (rutas compartidas) ----------

it('adf: el admin lee las sesiones y el chat de una asesoría de su facultad', function () {
    $asesoria = adfAsesoria(adfUsuario(Rol::Estudiante), adfUsuario(Rol::Asesor));
    $admin = adfUsuario(Rol::Admin);

    $this->withToken(adfToken($admin))
        ->getJson("/api/v1/asesorias/{$asesoria->id}/sesiones")
        ->assertOk();

    $this->withToken(adfToken($admin))
        ->getJson("/api/v1/asesorias/{$asesoria->id}/mensajes")
        ->assertOk();
});

it('adf: el admin recibe 403 al intentar enviar un mensaje y no se crea nada', function () {
    $asesoria = adfAsesoria(adfUsuario(Rol::Estudiante), adfUsuario(Rol::Asesor));
    $admin = adfUsuario(Rol::Admin);

    $this->withToken(adfToken($admin))
        ->postJson("/api/v1/asesorias/{$asesoria->id}/mensajes", ['mensaje' => 'Hola'])
        ->assertForbidden();

    expect(Chat::count())->toBe(0);
});

it('adf: el admin de otra facultad no encuentra la asesoría (404)', function () {
    $asesoria = adfAsesoria(adfUsuario(Rol::Estudiante), adfUsuario(Rol::Asesor));
    $adminOtra = adfUsuario(Rol::Admin, adfOtraFacultad());

    $this->withToken(adfToken($adminOtra))
        ->getJson("/api/v1/asesorias/{$asesoria->id}/sesiones")
        ->assertNotFound();
});

it('adf: un asesor que no participa recibe 403 al leer las sesiones', function () {
    $asesoria = adfAsesoria(adfUsuario(Rol::Estudiante), adfUsuario(Rol::Asesor));

    $this->withToken(adfToken(adfUsuario(Rol::Asesor)))
        ->getJson("/api/v1/asesorias/{$asesoria->id}/sesiones")
        ->assertForbidden();
});