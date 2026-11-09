<?php

use App\Enums\Dia;
use App\Enums\EstatusUsuario;
use App\Enums\Modalidad;
use App\Enums\Rol;
use App\Models\DisponibilidadHorario;
use App\Models\DisponibilidadMateria;
use App\Models\DisponibilidadModalidad;
use App\Models\Facultad;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;

const URL_ADP_ESTUDIANTES = '/api/v1/admin/estudiantes';
const URL_ADP_ASESORES = '/api/v1/admin/asesores';

// GET /admin/estudiantes, GET /admin/asesores y GET /admin/asesores/{id}/disponibilidad
// (lecturas que el admin usa para elegir persona al crear una asesoría y para ver lo que imparte un asesor)


// ---------- Ayudantes ----------

function adpToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function adpOtra(): Facultad
{
    return UsuarioFactory::facultad('OTR');
}

function adpAdmin(?Facultad $facultad = null): Usuario
{
    return Usuario::factory()
        ->conRol(Rol::Admin)
        ->create(['facultad_id' => ($facultad ?? UsuarioFactory::facultad('FIC'))->id]);
}

function adpLicenciatura(): Licenciatura
{
    return Licenciatura::firstOrCreate([
        'facultad_id' => UsuarioFactory::facultad('FIC')->id,
        'nombre' => 'LICENCIATURA PRUEBA',
    ]);
}

function adpGrupo(): Grupo
{
    return Grupo::firstOrCreate(
        ['licenciatura_id' => adpLicenciatura()->id, 'nombre' => '3-1'],
        ['anio' => 3]
    );
}

function adpMateria(string $nombre = 'Estructura de Datos'): Materia
{
    return Materia::firstOrCreate(
        ['licenciatura_id' => adpLicenciatura()->id, 'nombre' => $nombre],
        ['semestre' => 1]
    );
}

function adpHorario(string $etiqueta = '7:00-8:00 AM'): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Próximo lunes: el día que ofrecen los asesores de las pruebas
function adpLunes(): Carbon
{
    return Carbon::now()->next(Carbon::MONDAY)->startOfDay();
}

function adpEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create(array_merge(
        ['grupo_id' => adpGrupo()->id],
        $datos
    ));
}

// Asesor que ofrece "Estructura de Datos", presencial y lunes 7:00-8:00 AM
// (con $conDisponibilidad = false queda sin nada, como un asesor recién dado de alta)
function adpAsesor(array $datos = [], bool $conDisponibilidad = true): Usuario
{
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create($datos);

    if (! $conDisponibilidad) {
        return $asesor;
    }

    DisponibilidadMateria::create([
        'facultad_id' => $asesor->facultad_id,
        'asesor_id' => $asesor->id,
        'materia_id' => adpMateria()->id,
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
        'horario_id' => adpHorario()->id,
    ]);

    return $asesor;
}

function adpIds($respuesta): array
{
    return collect($respuesta->json('data'))->pluck('id')->all();
}


// =====================================================================
//  GET /admin/estudiantes
// =====================================================================

it('adp estudiantes: devuelve 401 sin sesión', function () {
    $this->getJson(URL_ADP_ESTUDIANTES)->assertUnauthorized();
});

it('adp estudiantes: rechaza con 403 a un asesor y a un estudiante', function () {
    $this->withToken(adpToken(adpAsesor()))->getJson(URL_ADP_ESTUDIANTES)->assertForbidden();

    $this->app['auth']->forgetGuards();

    $this->withToken(adpToken(adpEstudiante()))->getJson(URL_ADP_ESTUDIANTES)->assertForbidden();
});

it('adp estudiantes: el super admin sin X-Facultad-Id recibe 422 facultad_requerida', function () {
    $superAdmin = Usuario::factory()->conRol(Rol::SuperAdmin)->create();

    $this->withToken(adpToken($superAdmin))
        ->getJson(URL_ADP_ESTUDIANTES)
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'facultad_requerida');
});

it('adp estudiantes: el super admin con X-Facultad-Id ve los de esa facultad', function () {
    $estudiante = adpEstudiante();
    $superAdmin = Usuario::factory()->conRol(Rol::SuperAdmin)->create();

    $respuesta = $this->withToken(adpToken($superAdmin))
        ->withHeader('X-Facultad-Id', (string) $estudiante->facultad_id)
        ->getJson(URL_ADP_ESTUDIANTES)
        ->assertOk();

    expect(adpIds($respuesta))->toBe([$estudiante->id]);
});

it('adp estudiantes: lista solo estudiantes activos de su facultad, con id, nombre, cuenta y grupo', function () {
    $propio = adpEstudiante();
    adpEstudiante(['facultad_id' => adpOtra()->id]);
    adpEstudiante(['estatus' => EstatusUsuario::Inactivo]);
    adpAsesor();
    $admin = adpAdmin();

    $respuesta = $this->withToken(adpToken($admin))
        ->getJson(URL_ADP_ESTUDIANTES)
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'nombre', 'numero_cuenta', 'grupo']], 'links', 'meta'])
        ->assertJsonPath('data.0.grupo', '3-1');

    expect(adpIds($respuesta))->toBe([$propio->id]);
});

it('adp estudiantes: busca por nombre completo y por número de cuenta', function () {
    $luis = adpEstudiante([
        'nombre' => 'Luis Fernando',
        'apellido_paterno' => 'Pérez',
        'apellido_materno' => 'Gámez',
        'numero_cuenta' => '1111111',
    ]);
    $ana = adpEstudiante([
        'nombre' => 'Ana',
        'apellido_paterno' => 'Ruiz',
        'apellido_materno' => 'Soto',
        'numero_cuenta' => '2222222',
    ]);
    $token = adpToken(adpAdmin());

    $porNombre = $this->withToken($token)->getJson(URL_ADP_ESTUDIANTES.'?buscar=Fernando Pérez')->assertOk();
    expect(adpIds($porNombre))->toBe([$luis->id]);

    $this->app['auth']->forgetGuards();

    $porCuenta = $this->withToken($token)->getJson(URL_ADP_ESTUDIANTES.'?buscar=2222')->assertOk();
    expect(adpIds($porCuenta))->toBe([$ana->id]);
});

it('adp estudiantes: pagina de 10 en 10', function () {
    foreach (range(1, 12) as $i) {
        adpEstudiante();
    }

    $token = adpToken(adpAdmin());

    $this->withToken($token)
        ->getJson(URL_ADP_ESTUDIANTES)
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 12)
        ->assertJsonPath('meta.last_page', 2);
});


// =====================================================================
//  GET /admin/asesores
// =====================================================================

it('adp asesores: devuelve 401 sin sesión', function () {
    $this->getJson(URL_ADP_ASESORES)->assertUnauthorized();
});

it('adp asesores: rechaza con 403 a un asesor y a un estudiante', function () {
    $this->withToken(adpToken(adpAsesor()))->getJson(URL_ADP_ASESORES)->assertForbidden();

    $this->app['auth']->forgetGuards();

    $this->withToken(adpToken(adpEstudiante()))->getJson(URL_ADP_ASESORES)->assertForbidden();
});

it('adp asesores: el super admin sin X-Facultad-Id recibe 422 facultad_requerida', function () {
    $superAdmin = Usuario::factory()->conRol(Rol::SuperAdmin)->create();

    $this->withToken(adpToken($superAdmin))
        ->getJson(URL_ADP_ASESORES)
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'facultad_requerida');
});

it('adp asesores: el super admin con X-Facultad-Id ve los de esa facultad', function () {
    $asesor = adpAsesor();
    $superAdmin = Usuario::factory()->conRol(Rol::SuperAdmin)->create();

    $respuesta = $this->withToken(adpToken($superAdmin))
        ->withHeader('X-Facultad-Id', (string) $asesor->facultad_id)
        ->getJson(URL_ADP_ASESORES)
        ->assertOk();

    expect(adpIds($respuesta))->toBe([$asesor->id]);
});

it('adp asesores: lista cada asesor con su disponibilidad (materias, horarios por día y modalidades)', function () {
    $asesor = adpAsesor();
    $token = adpToken(adpAdmin());

    $this->withToken($token)
        ->getJson(URL_ADP_ASESORES)
        ->assertOk()
        ->assertJsonStructure([
            'data' => [[
                'id', 'nombre', 'apellido_paterno', 'apellido_materno', 'correo',
                'disponibilidad' => ['materias', 'horarios', 'modalidades'],
            ]],
            'links',
            'meta',
        ])
        ->assertJsonPath('data.0.id', $asesor->id)
        ->assertJsonPath('data.0.disponibilidad.materias.0.nombre', 'Estructura de Datos')
        ->assertJsonPath('data.0.disponibilidad.modalidades.0.valor', 'presencial')
        ->assertJsonPath('data.0.disponibilidad.horarios.0.dia', 'lunes')
        ->assertJsonPath('data.0.disponibilidad.horarios.0.horarios.0.etiqueta', '7:00-8:00 AM');
});

it('adp asesores: no incluye otras facultades, inactivos, estudiantes ni asesores sin disponibilidad', function () {
    $propio = adpAsesor();
    adpAsesor(['facultad_id' => adpOtra()->id]);
    adpAsesor(['estatus' => EstatusUsuario::Inactivo]);
    adpAsesor([], false);
    adpEstudiante();

    $respuesta = $this->withToken(adpToken(adpAdmin()))->getJson(URL_ADP_ASESORES)->assertOk();

    expect(adpIds($respuesta))->toBe([$propio->id]);
});

it('adp asesores: busca por nombre completo', function () {
    $marco = adpAsesor(['nombre' => 'Marco Antonio', 'apellido_paterno' => 'Valdez', 'apellido_materno' => 'Ríos']);
    adpAsesor(['nombre' => 'Rosa', 'apellido_paterno' => 'Lara', 'apellido_materno' => 'Cota']);

    $respuesta = $this->withToken(adpToken(adpAdmin()))
        ->getJson(URL_ADP_ASESORES.'?buscar=Antonio Valdez')
        ->assertOk();

    expect(adpIds($respuesta))->toBe([$marco->id]);
});

it('adp asesores: filtra por materia', function () {
    $conEstructura = adpAsesor();
    $otro = adpAsesor();
    $otra = adpMateria('Bases de Datos');
    DisponibilidadMateria::where('asesor_id', $otro->id)->update(['materia_id' => $otra->id]);

    $token = adpToken(adpAdmin());

    $respuesta = $this->withToken($token)
        ->getJson(URL_ADP_ASESORES.'?materia_id='.adpMateria()->id)
        ->assertOk();

    expect(adpIds($respuesta))->toBe([$conEstructura->id]);
});

it('adp asesores: filtra por modalidad', function () {
    $presencial = adpAsesor();
    $virtual = adpAsesor();
    DisponibilidadModalidad::where('asesor_id', $virtual->id)->update(['modalidad' => Modalidad::Virtual]);

    $token = adpToken(adpAdmin());

    $respuesta = $this->withToken($token)->getJson(URL_ADP_ASESORES.'?modalidad=virtual')->assertOk();

    expect(adpIds($respuesta))->toBe([$virtual->id]);
});

it('adp asesores: filtra por fecha (el día de la semana) y por horario', function () {
    $asesor = adpAsesor(); // lunes 7:00-8:00 AM
    $token = adpToken(adpAdmin());

    // Un lunes: aparece
    $lunes = $this->withToken($token)
        ->getJson(URL_ADP_ASESORES.'?fecha='.adpLunes()->toDateString())
        ->assertOk();
    expect(adpIds($lunes))->toBe([$asesor->id]);

    $this->app['auth']->forgetGuards();

    // Un martes: nadie lo ofrece
    $martes = $this->withToken($token)
        ->getJson(URL_ADP_ASESORES.'?fecha='.adpLunes()->addDay()->toDateString())
        ->assertOk();
    expect(adpIds($martes))->toBe([]);

    $this->app['auth']->forgetGuards();

    // Un sábado: tampoco
    $sabado = $this->withToken($token)
        ->getJson(URL_ADP_ASESORES.'?fecha='.adpLunes()->addDays(5)->toDateString())
        ->assertOk();
    expect(adpIds($sabado))->toBe([]);

    $this->app['auth']->forgetGuards();

    // Horario: el que tiene aparece, otro no
    $conHorario = $this->withToken($token)
        ->getJson(URL_ADP_ASESORES.'?horario_id='.adpHorario()->id)
        ->assertOk();
    expect(adpIds($conHorario))->toBe([$asesor->id]);

    $this->app['auth']->forgetGuards();

    $sinHorario = $this->withToken($token)
        ->getJson(URL_ADP_ASESORES.'?horario_id='.adpHorario('8:00-9:00 AM')->id)
        ->assertOk();
    expect(adpIds($sinHorario))->toBe([]);
});

it('adp asesores: responde 422 con una modalidad, una fecha pasada o un horario inválidos', function () {
    $token = adpToken(adpAdmin());

    $this->withToken($token)->getJson(URL_ADP_ASESORES.'?modalidad=remota')->assertUnprocessable();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson(URL_ADP_ASESORES.'?fecha='.now()->subDay()->toDateString())
        ->assertUnprocessable();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson(URL_ADP_ASESORES.'?horario_id=999999')->assertUnprocessable();
});

it('adp asesores: pagina de 10 en 10', function () {
    foreach (range(1, 12) as $i) {
        adpAsesor();
    }

    $this->withToken(adpToken(adpAdmin()))
        ->getJson(URL_ADP_ASESORES)
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 12)
        ->assertJsonPath('meta.last_page', 2);
});


// =====================================================================
//  GET /admin/asesores/{id}/disponibilidad
// =====================================================================

it('adp disponibilidad: devuelve 401 sin sesión', function () {
    $this->getJson(URL_ADP_ASESORES.'/1/disponibilidad')->assertUnauthorized();
});

it('adp disponibilidad: rechaza con 403 a un asesor y a un estudiante', function () {
    $asesor = adpAsesor();

    $this->withToken(adpToken($asesor))
        ->getJson(URL_ADP_ASESORES."/{$asesor->id}/disponibilidad")
        ->assertForbidden();

    $this->app['auth']->forgetGuards();

    $this->withToken(adpToken(adpEstudiante()))
        ->getJson(URL_ADP_ASESORES."/{$asesor->id}/disponibilidad")
        ->assertForbidden();
});

it('adp disponibilidad: muestra lo que imparte el asesor, con la misma forma que GET /asesor/disponibilidad', function () {
    $asesor = adpAsesor();

    $this->withToken(adpToken(adpAdmin()))
        ->getJson(URL_ADP_ASESORES."/{$asesor->id}/disponibilidad")
        ->assertOk()
        ->assertJsonStructure(['data' => ['materias', 'horarios', 'modalidades']])
        ->assertJsonCount(5, 'data.horarios')
        ->assertJsonPath('data.materias.0.nombre', 'Estructura de Datos')
        ->assertJsonPath('data.modalidades.0.valor', 'presencial')
        ->assertJsonPath('data.horarios.0.dia', 'lunes')
        ->assertJsonPath('data.horarios.0.etiqueta', 'Lunes')
        ->assertJsonPath('data.horarios.0.horarios.0.id', adpHorario()->id)
        ->assertJsonPath('data.horarios.1.dia', 'martes')
        ->assertJsonCount(0, 'data.horarios.1.horarios');
});

it('adp disponibilidad: un asesor sin disponibilidad responde 200 con listas vacías', function () {
    $asesor = adpAsesor([], false);

    $this->withToken(adpToken(adpAdmin()))
        ->getJson(URL_ADP_ASESORES."/{$asesor->id}/disponibilidad")
        ->assertOk()
        ->assertJsonCount(0, 'data.materias')
        ->assertJsonCount(0, 'data.modalidades')
        ->assertJsonCount(5, 'data.horarios');
});

it('adp disponibilidad: el super admin con X-Facultad-Id puede verla; sin el header recibe 422', function () {
    $asesor = adpAsesor();
    $superAdmin = Usuario::factory()->conRol(Rol::SuperAdmin)->create();
    $token = adpToken($superAdmin);

    $this->withToken($token)
        ->getJson(URL_ADP_ASESORES."/{$asesor->id}/disponibilidad")
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'facultad_requerida');

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->withHeader('X-Facultad-Id', (string) $asesor->facultad_id)
        ->getJson(URL_ADP_ASESORES."/{$asesor->id}/disponibilidad")
        ->assertOk();
});

it('adp disponibilidad: responde 404 si el asesor es de otra facultad, está inactivo, no es asesor o no existe', function () {
    $ajeno = adpAsesor(['facultad_id' => adpOtra()->id]);
    $inactivo = adpAsesor(['estatus' => EstatusUsuario::Inactivo]);
    $estudiante = adpEstudiante();
    $token = adpToken(adpAdmin());

    foreach ([$ajeno->id, $inactivo->id, $estudiante->id, 999999] as $id) {
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson(URL_ADP_ASESORES."/{$id}/disponibilidad")
            ->assertNotFound();
    }
});

it('adp disponibilidad: un id que no es número responde 404', function () {
    $this->withToken(adpToken(adpAdmin()))
        ->getJson(URL_ADP_ASESORES.'/abc/disponibilidad')
        ->assertNotFound();
});