<?php



use App\Enums\EstatusAsesoria;
use App\Enums\EstatusSolicitud;
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
use App\Models\Solicitud;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;

const URL_ADR = '/api/v1/admin';

// ---------- Ayudantes ----------

// Nombres fijos: fake() puede traer apóstrofos y hacer ruido en los textos que se comparan
function adrUsuario(array $roles, string $nombre, string $siglas = 'FIC', array $extra = []): Usuario
{
    return Usuario::factory()->conRol(...$roles)->create(array_merge([
        'facultad_id' => UsuarioFactory::facultad($siglas)->id,
        'nombre' => $nombre,
        'apellido_paterno' => 'Prueba',
        'apellido_materno' => 'Reporte',
    ], $extra));
}

// Petición como el usuario dado (admin de FIC si no se pasa). Limpia el guard: Sanctum cachea al usuario
function adrComo(?Usuario $usuario = null)
{
    app('auth')->forgetGuards();

    return test()->withToken(($usuario ?? adrUsuario([Rol::Admin], 'Admin'))->createToken('prueba')->plainTextToken);
}

function adrCiclo(string $siglas, string $nombre, bool $vigente, string $inicio): CicloEscolar
{
    return CicloEscolar::create([
        'facultad_id' => UsuarioFactory::facultad($siglas)->id,
        'nombre' => $nombre,
        'periodo' => 1,
        'fecha_inicio' => $inicio,
        'fecha_fin' => date('Y-m-d', strtotime($inicio.' +6 months')),
        'vigente' => $vigente,
    ]);
}

function adrFila($respuesta, int $id): array
{
    return collect($respuesta->json('data'))->firstWhere('id', $id) ?? [];
}

function adrAsesoria(object $e, Usuario $asesor, Usuario $estudiante, Materia $materia, ?Grupo $grupo, EstatusAsesoria $estatus, string $fecha, array $extra = []): Asesoria
{
    return Asesoria::create(array_merge([
        'facultad_id' => $asesor->facultad_id,
        'ciclo_escolar_id' => $e->ciclo->id,
        'grupo_id' => $grupo?->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => $e->horario->id,
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Parcial,
        'estatus' => $estatus,
        'fecha_inicio' => $fecha,
    ], $extra));
}

function adrSolicitud(object $e, Usuario $asesor, Usuario $estudiante, Materia $materia, EstatusSolicitud $estatus): Solicitud
{
    return Solicitud::create([
        'facultad_id' => $asesor->facultad_id,
        'ciclo_escolar_id' => $e->ciclo->id,
        'grupo_id' => $e->g1->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => $e->horario->id,
        'fecha_inicio' => '2026-09-01',
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Parcial,
        'estatus' => $estatus,
    ]);
}

// Escenario de FIC (ciclo vigente):
//   licenciaturas Informática (m1 Base de Datos, m2 Cálculo; grupos g1 "3-1" y g2 "4-1" virtual) y Ciencia de Datos (m3 Base de Datos)
//   asesores: $disc (disciplinar), $par (asesor y estudiante), $libre (sin actividad)
//   A1 disc + e1 + m1 + g1 completada (2 sesiones)   A2 disc + e2 + m1 + g2 en curso (1 sesión)
//   A3 par  + e1 + m2 + g1 cancelada                 A4 par  + e2 + m3 + g2 en curso
//   solicitudes: S1 y S2 de e1 a disc (m1), S3 de e1 a par (m2), S4 de e4 a disc (m1)
//   una asesoría de un ciclo viejo (no debe contarse en el vigente)
function adrEscenario(): object
{
    $e = new stdClass;
    $fac = UsuarioFactory::facultad('FIC');

    $e->ciclo = adrCiclo('FIC', '2026-2027 1', true, '2026-08-01');
    $e->viejo = adrCiclo('FIC', '2025-2026 2', false, '2026-01-01');
    $e->horario = Horario::firstOrCreate(['etiqueta' => '7:00-8:00 AM']);

    $e->lic1 = Licenciatura::create(['facultad_id' => $fac->id, 'nombre' => 'Informática']);
    $e->lic2 = Licenciatura::create(['facultad_id' => $fac->id, 'nombre' => 'Ciencia de Datos']);
    $e->m1 = Materia::create(['licenciatura_id' => $e->lic1->id, 'nombre' => 'Base de Datos', 'semestre' => 3]);
    $e->m2 = Materia::create(['licenciatura_id' => $e->lic1->id, 'nombre' => 'Cálculo', 'semestre' => 1]);
    $e->m3 = Materia::create(['licenciatura_id' => $e->lic2->id, 'nombre' => 'Base de Datos', 'semestre' => 3]);
    $e->g1 = Grupo::create(['licenciatura_id' => $e->lic1->id, 'nombre' => '3-1', 'anio' => 3, 'modalidad' => 'presencial']);
    $e->g2 = Grupo::create(['licenciatura_id' => $e->lic1->id, 'nombre' => '4-1', 'anio' => 4, 'modalidad' => 'virtual']);

    $e->disc = adrUsuario([Rol::Asesor], 'Diego');
    $e->par = adrUsuario([Rol::Asesor, Rol::Estudiante], 'Paola');
    $e->libre = adrUsuario([Rol::Asesor], 'Libre');
    $e->e1 = adrUsuario([Rol::Estudiante], 'Elena', 'FIC', ['grupo_id' => $e->g1->id]);
    $e->e2 = adrUsuario([Rol::Estudiante], 'Emilio', 'FIC', ['grupo_id' => $e->g2->id]);
    $e->e3 = adrUsuario([Rol::Estudiante], 'Sinactividad', 'FIC', ['grupo_id' => $e->g1->id]);
    $e->e4 = adrUsuario([Rol::Estudiante], 'Soloticitud', 'FIC', ['grupo_id' => $e->g1->id]);

    $e->a1 = adrAsesoria($e, $e->disc, $e->e1, $e->m1, $e->g1, EstatusAsesoria::Completada, '2026-09-01', ['finalizada_en' => '2026-09-20 10:00:00']);
    $e->a2 = adrAsesoria($e, $e->disc, $e->e2, $e->m1, $e->g2, EstatusAsesoria::EnCurso, '2026-09-10', ['modalidad' => Modalidad::Virtual, 'razon' => RazonAsesoria::Ordinario]);
    $e->a3 = adrAsesoria($e, $e->par, $e->e1, $e->m2, $e->g1, EstatusAsesoria::Cancelada, '2026-09-05');
    $e->a4 = adrAsesoria($e, $e->par, $e->e2, $e->m3, $e->g2, EstatusAsesoria::EnCurso, '2026-09-07');

    // Ciclo viejo: no debe aparecer en los reportes del vigente
    $e->a5 = Asesoria::create([
        'facultad_id' => $fac->id, 'ciclo_escolar_id' => $e->viejo->id, 'grupo_id' => $e->g1->id,
        'estudiante_id' => $e->e1->id, 'asesor_id' => $e->disc->id, 'materia_id' => $e->m1->id,
        'horario_id' => $e->horario->id, 'modalidad' => Modalidad::Presencial, 'razon' => RazonAsesoria::Parcial,
        'estatus' => EstatusAsesoria::Completada, 'fecha_inicio' => '2026-02-01',
    ]);

    foreach (['2026-09-02', '2026-09-03'] as $fecha) {
        Sesion::create(['asesoria_id' => $e->a1->id, 'fecha' => $fecha, 'horario_id' => $e->horario->id, 'registrada_por' => $e->disc->id]);
    }
    Sesion::create(['asesoria_id' => $e->a2->id, 'fecha' => '2026-09-11', 'horario_id' => $e->horario->id, 'registrada_por' => $e->disc->id]);
    // Sesión del ciclo viejo: tampoco cuenta
    Sesion::create(['asesoria_id' => $e->a5->id, 'fecha' => '2026-02-02', 'horario_id' => $e->horario->id, 'registrada_por' => $e->disc->id]);

    adrSolicitud($e, $e->disc, $e->e1, $e->m1, EstatusSolicitud::Pendiente);
    adrSolicitud($e, $e->disc, $e->e1, $e->m1, EstatusSolicitud::Rechazada);
    adrSolicitud($e, $e->par, $e->e1, $e->m2, EstatusSolicitud::Pendiente);
    adrSolicitud($e, $e->disc, $e->e4, $e->m1, EstatusSolicitud::Pendiente);

    return $e;
}

// ---------- Acceso ----------

it('adr: sin token recibe 401', function () {
    $this->getJson(URL_ADR.'/ciclos')->assertUnauthorized();
    $this->getJson(URL_ADR.'/reportes/asesores?ciclo_id=1')->assertUnauthorized();
    $this->getJson(URL_ADR.'/reportes/asesores/1/asesorias?ciclo_id=1')->assertUnauthorized();
});

it('adr: asesor y estudiante reciben 403', function (Rol $rol) {
    $usuario = adrUsuario([$rol], 'Otro');

    adrComo($usuario)->getJson(URL_ADR.'/ciclos')->assertForbidden();
    adrComo($usuario)->getJson(URL_ADR.'/reportes/materias?ciclo_id=1')->assertForbidden();
    adrComo($usuario)->getJson(URL_ADR.'/reportes/materias/1/asesorias?ciclo_id=1')->assertForbidden();
})->with([Rol::Asesor, Rol::Estudiante]);

it('adr: el super admin sin X-Facultad-Id recibe 422', function () {
    $super = Usuario::factory()->conRol(Rol::SuperAdmin)->create();

    adrComo($super)->getJson(URL_ADR.'/reportes/asesores?ciclo_id=1')->assertUnprocessable();
});

it('adr: es de solo lectura y un tipo desconocido da 404', function () {
    $admin = adrUsuario([Rol::Admin], 'Admin');
    $e = adrEscenario();

    adrComo($admin)->postJson(URL_ADR.'/reportes/asesores', [])->assertStatus(405);
    adrComo($admin)->getJson(URL_ADR."/reportes/inventado?ciclo_id={$e->ciclo->id}")->assertNotFound();
});

// ---------- Ciclos ----------

it('adr: lista los ciclos de la facultad con el vigente primero', function () {
    $e = adrEscenario();
    adrCiclo('OTR', 'Ciclo ajeno', true, '2026-08-01');

    $r = adrComo()->getJson(URL_ADR.'/ciclos')->assertOk();

    expect(collect($r->json('data'))->pluck('id')->all())->toBe([$e->ciclo->id, $e->viejo->id]);
    $r->assertJsonPath('data.0.nombre', '2026-2027 1 (vigente)')
        ->assertJsonPath('data.1.nombre', '2025-2026 2');
});

it('adr: ciclo_id es obligatorio y debe ser de la facultad', function () {
    adrEscenario();
    $ajeno = adrCiclo('OTR', 'Ciclo ajeno', true, '2026-08-01');

    adrComo()->getJson(URL_ADR.'/reportes/asesores')->assertUnprocessable()->assertJsonValidationErrors('ciclo_id');
    adrComo()->getJson(URL_ADR.'/reportes/asesores?ciclo_id=abc')->assertUnprocessable()->assertJsonValidationErrors('ciclo_id');
    adrComo()->getJson(URL_ADR."/reportes/asesores?ciclo_id={$ajeno->id}")->assertUnprocessable()->assertJsonValidationErrors('ciclo_id');
});

// ---------- Resumen ----------

it('adr: resumen por asesor con tipo, totales y sin mezclar ciclos', function () {
    $e = adrEscenario();

    $r = adrComo()->getJson(URL_ADR."/reportes/asesores?ciclo_id={$e->ciclo->id}")->assertOk();

    expect(adrFila($r, $e->disc->id))->toMatchArray([
        'nombre' => 'Diego Prueba Reporte', 'tipo' => 'Disciplinar',
        'solicitudes' => 3, 'en_curso' => 1, 'completadas' => 1, 'canceladas' => 0,
        'sesiones' => 3, 'materias' => 1, 'estudiantes' => 2,
    ]);

    expect(adrFila($r, $e->par->id))->toMatchArray([
        'tipo' => 'Par', 'solicitudes' => 1, 'en_curso' => 1, 'completadas' => 0, 'canceladas' => 1,
        'sesiones' => 0, 'materias' => 2, 'estudiantes' => 2,
    ]);

    // Un asesor sin actividad sale en ceros
    expect(adrFila($r, $e->libre->id))->toMatchArray([
        'tipo' => 'Disciplinar', 'solicitudes' => 0, 'en_curso' => 0, 'completadas' => 0, 'canceladas' => 0, 'sesiones' => 0,
    ]);

    // Los estudiantes no son asesores
    expect(adrFila($r, $e->e1->id))->toBe([]);
});

it('adr: el resumen de otro ciclo trae sus propios números', function () {
    $e = adrEscenario();

    $r = adrComo()->getJson(URL_ADR."/reportes/asesores?ciclo_id={$e->viejo->id}")->assertOk();

    expect(adrFila($r, $e->disc->id))->toMatchArray(['completadas' => 1, 'en_curso' => 0, 'sesiones' => 1, 'solicitudes' => 0]);
    expect(adrFila($r, $e->par->id))->toMatchArray(['completadas' => 0, 'canceladas' => 0, 'en_curso' => 0]);
});

it('adr: el resumen por asesor solo trae asesores de la facultad', function () {
    $e = adrEscenario();
    $ajeno = adrUsuario([Rol::Asesor], 'Ajeno', 'OTR');

    $r = adrComo()->getJson(URL_ADR."/reportes/asesores?ciclo_id={$e->ciclo->id}")->assertOk();

    expect(adrFila($r, $ajeno->id))->toBe([]);
});

it('adr: resumen por materia separa las que se llaman igual en distintas licenciaturas', function () {
    $e = adrEscenario();

    $r = adrComo()->getJson(URL_ADR."/reportes/materias?ciclo_id={$e->ciclo->id}")->assertOk();

    expect(adrFila($r, $e->m1->id))->toMatchArray([
        'nombre' => 'Base de Datos', 'licenciatura' => 'Informática',
        'solicitudes' => 3, 'en_curso' => 1, 'completadas' => 1, 'canceladas' => 0,
        'sesiones' => 3, 'asesores' => 1, 'estudiantes' => 2,
    ]);
    expect(adrFila($r, $e->m3->id))->toMatchArray([
        'nombre' => 'Base de Datos', 'licenciatura' => 'Ciencia de Datos',
        'solicitudes' => 0, 'en_curso' => 1, 'completadas' => 0, 'asesores' => 1, 'estudiantes' => 1,
    ]);
    expect(adrFila($r, $e->m2->id))->toMatchArray(['solicitudes' => 1, 'canceladas' => 1]);
});

it('adr: resumen por estudiante solo trae a los que tuvieron actividad, con su grupo', function () {
    $e = adrEscenario();

    $r = adrComo()->getJson(URL_ADR."/reportes/estudiantes?ciclo_id={$e->ciclo->id}")->assertOk();

    expect(adrFila($r, $e->e1->id))->toMatchArray([
        'nombre' => 'Elena Prueba Reporte', 'grupo' => '3-1', 'licenciatura' => 'Informática',
        'solicitudes' => 3, 'en_curso' => 0, 'completadas' => 1, 'canceladas' => 1,
        'sesiones' => 2, 'asesores' => 2, 'materias' => 2,
    ]);
    expect(adrFila($r, $e->e2->id))->toMatchArray(['en_curso' => 2, 'solicitudes' => 0, 'sesiones' => 1]);

    // Solo tiene una solicitud: aparece sin asesorías. Sin actividad: no aparece
    expect(adrFila($r, $e->e4->id))->toMatchArray(['solicitudes' => 1, 'en_curso' => 0, 'completadas' => 0]);
    expect(adrFila($r, $e->e3->id))->toBe([]);
});

it('adr: resumen por grupo (el virtual lo indica en el nombre)', function () {
    $e = adrEscenario();

    $r = adrComo()->getJson(URL_ADR."/reportes/grupos?ciclo_id={$e->ciclo->id}")->assertOk();

    expect(adrFila($r, $e->g1->id))->toMatchArray([
        'nombre' => '3-1', 'licenciatura' => 'Informática',
        'solicitudes' => 4, 'en_curso' => 0, 'completadas' => 1, 'canceladas' => 1, 'sesiones' => 2,
        'asesores' => 2, 'estudiantes' => 1,
    ]);
    expect(adrFila($r, $e->g2->id))->toMatchArray([
        'nombre' => '4-1 (Virtual)', 'solicitudes' => 0, 'en_curso' => 2, 'sesiones' => 1, 'asesores' => 2,
    ]);
});

it('adr: resumen por licenciatura usa la licenciatura de la materia', function () {
    $e = adrEscenario();

    $r = adrComo()->getJson(URL_ADR."/reportes/licenciaturas?ciclo_id={$e->ciclo->id}")->assertOk();

    expect(adrFila($r, $e->lic1->id))->toMatchArray([
        'nombre' => 'Informática', 'solicitudes' => 4, 'en_curso' => 1, 'completadas' => 1, 'canceladas' => 1,
        'sesiones' => 3, 'grupos' => 2, 'materias' => 2, 'asesores' => 2, 'estudiantes' => 2,
    ]);
    expect(adrFila($r, $e->lic2->id))->toMatchArray([
        'nombre' => 'Ciencia de Datos', 'solicitudes' => 0, 'en_curso' => 1, 'grupos' => 1, 'materias' => 1,
    ]);
});

// ---------- Detalle de asesorías ----------

it('adr: asesorías de un asesor con todos sus campos, la más reciente primero', function () {
    $e = adrEscenario();

    $r = adrComo()->getJson(URL_ADR."/reportes/asesores/{$e->disc->id}/asesorias?ciclo_id={$e->ciclo->id}")->assertOk();

    expect(collect($r->json('data'))->pluck('id')->all())->toBe([$e->a2->id, $e->a1->id]);

    $r->assertJsonPath('data.1', [
        'id' => $e->a1->id,
        'asesor' => 'Diego Prueba Reporte',
        'materia' => 'Base de Datos',
        'estudiante' => 'Elena Prueba Reporte',
        'grupo' => '3-1',
        'licenciatura' => 'Informática',
        'fecha_inicio' => '2026-09-01',
        'fecha_fin' => '2026-09-20',
        'horario' => '7:00-8:00 AM',
        'modalidad' => 'Presencial',
        'razon' => 'Parcial',
        'estatus' => 'Completada',
        'sesiones' => 2,
    ]);

    // En curso: sin fecha de término y con sus etiquetas
    $r->assertJsonPath('data.0.fecha_fin', null)
        ->assertJsonPath('data.0.estatus', 'En curso')
        ->assertJsonPath('data.0.modalidad', 'Virtual')
        ->assertJsonPath('data.0.razon', 'Ordinario')
        ->assertJsonPath('data.0.sesiones', 1);
});

it('adr: asesorías por materia, estudiante, grupo y licenciatura', function () {
    $e = adrEscenario();
    $ciclo = $e->ciclo->id;

    $ids = fn (string $ruta) => collect(adrComo()->getJson(URL_ADR."/reportes/{$ruta}/asesorias?ciclo_id={$ciclo}")->assertOk()->json('data'))
        ->pluck('id')->all();

    // La materia "Base de Datos" de Ciencia de Datos no se mezcla con la de Informática
    expect($ids("materias/{$e->m1->id}"))->toBe([$e->a2->id, $e->a1->id]);
    expect($ids("materias/{$e->m3->id}"))->toBe([$e->a4->id]);
    expect($ids("estudiantes/{$e->e1->id}"))->toBe([$e->a3->id, $e->a1->id]);
    expect($ids("grupos/{$e->g1->id}"))->toBe([$e->a3->id, $e->a1->id]);
    expect($ids("licenciaturas/{$e->lic1->id}"))->toBe([$e->a2->id, $e->a3->id, $e->a1->id]);
    expect($ids("licenciaturas/{$e->lic2->id}"))->toBe([$e->a4->id]);
});

it('adr: una asesoría cancelada no trae fecha de término', function () {
    $e = adrEscenario();
    $e->a3->update(['finalizada_en' => '2026-09-06 09:00:00']);

    $r = adrComo()->getJson(URL_ADR."/reportes/estudiantes/{$e->e1->id}/asesorias?ciclo_id={$e->ciclo->id}")->assertOk();

    $r->assertJsonPath('data.0.estatus', 'Cancelada')->assertJsonPath('data.0.fecha_fin', null);
});

it('adr: el detalle respeta el ciclo pedido', function () {
    $e = adrEscenario();

    $r = adrComo()->getJson(URL_ADR."/reportes/asesores/{$e->disc->id}/asesorias?ciclo_id={$e->viejo->id}")->assertOk();

    expect(collect($r->json('data'))->pluck('id')->all())->toBe([$e->a5->id]);
});

it('adr: un asesor sin asesorías en el ciclo devuelve lista vacía', function () {
    $e = adrEscenario();

    adrComo()->getJson(URL_ADR."/reportes/asesores/{$e->libre->id}/asesorias?ciclo_id={$e->ciclo->id}")
        ->assertOk()->assertJsonCount(0, 'data');
});

it('adr: 404 si la entidad no existe, es de otra facultad o no corresponde al tipo', function () {
    $e = adrEscenario();
    $ajeno = adrUsuario([Rol::Asesor], 'Ajeno', 'OTR');
    $url = fn (string $ruta) => URL_ADR."/reportes/{$ruta}/asesorias?ciclo_id={$e->ciclo->id}";

    adrComo()->getJson($url('asesores/999999'))->assertNotFound();
    adrComo()->getJson($url("asesores/{$ajeno->id}"))->assertNotFound();
    // Un estudiante no es un asesor (ni al revés)
    adrComo()->getJson($url("asesores/{$e->e1->id}"))->assertNotFound();
    adrComo()->getJson($url("estudiantes/{$e->disc->id}"))->assertNotFound();
});

it('adr: un admin de otra facultad no ve datos de esta (404 en el detalle)', function () {
    $e = adrEscenario();
    $adminOtr = adrUsuario([Rol::Admin], 'AdminOtr', 'OTR');
    $cicloOtr = adrCiclo('OTR', 'Ciclo OTR', true, '2026-08-01');

    adrComo($adminOtr)->getJson(URL_ADR."/reportes/asesores/{$e->disc->id}/asesorias?ciclo_id={$cicloOtr->id}")->assertNotFound();
    adrComo($adminOtr)->getJson(URL_ADR."/reportes/materias/{$e->m1->id}/asesorias?ciclo_id={$cicloOtr->id}")->assertNotFound();
    adrComo($adminOtr)->getJson(URL_ADR."/reportes/licenciaturas/{$e->lic1->id}/asesorias?ciclo_id={$cicloOtr->id}")->assertNotFound();

    // Y su propio resumen no incluye nada de FIC
    $r = adrComo($adminOtr)->getJson(URL_ADR."/reportes/materias?ciclo_id={$cicloOtr->id}")->assertOk();
    expect(adrFila($r, $e->m1->id))->toBe([]);
});