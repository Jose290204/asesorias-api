<?php

use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Models\Grupo;
use App\Models\Licenciatura;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;

const URL_LEST = '/api/v1/asesor/estudiantes';


// ---------- Ayudantes ----------

function lestToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function lestAsesor(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create($datos);
}

function lestEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create($datos);
}

function lestGrupo(): Grupo
{
    $licenciatura = Licenciatura::firstOrCreate([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => 'LICENCIATURA PRUEBA',
    ]);

    return Grupo::firstOrCreate(
        ['licenciatura_id' => $licenciatura->id, 'nombre' => '3-1'],
        ['anio' => 3]
    );
}


// ---------- Acceso ----------

it('listar estudiantes devuelve 401 sin sesión', function () {
    $this->getJson(URL_LEST)->assertUnauthorized();
});

it('listar estudiantes rechaza con 403 a un estudiante puro', function () {
    $this->withToken(lestToken(lestEstudiante()))
        ->getJson(URL_LEST)
        ->assertForbidden();
});

it('listar estudiantes bloquea con 403 a un asesor con cuenta no activa', function () {
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);

    $this->withToken(lestToken($asesor))
        ->getJson(URL_LEST)
        ->assertForbidden();
});


// ---------- Caso feliz ----------

it('listar estudiantes: devuelve id, nombre completo, número de cuenta y grupo, sin datos de contacto', function () {
    $asesor = lestAsesor();
    $estudiante = lestEstudiante([
        'nombre' => 'Luis',
        'apellido_paterno' => 'Pérez',
        'apellido_materno' => 'Gámez',
        'numero_cuenta' => '20230001',
        'grupo_id' => lestGrupo()->id,
    ]);

    $this->withToken(lestToken($asesor))
        ->getJson(URL_LEST)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $estudiante->id)
        ->assertJsonPath('data.0.nombre', 'Luis Pérez Gámez')
        ->assertJsonPath('data.0.numero_cuenta', '20230001')
        ->assertJsonPath('data.0.grupo', '3-1')
        ->assertJsonMissingPath('data.0.correo')
        ->assertJsonMissingPath('data.0.num_cel')
        ->assertJsonMissingPath('data.0.password');
});

it('listar estudiantes: un estudiante sin grupo sale con grupo null', function () {
    $asesor = lestAsesor();
    lestEstudiante(['grupo_id' => null]);

    $this->withToken(lestToken($asesor))
        ->getJson(URL_LEST)
        ->assertOk()
        ->assertJsonPath('data.0.grupo', null);
});

it('listar estudiantes: sin estudiantes la lista sale vacía', function () {
    $asesor = lestAsesor();

    $this->withToken(lestToken($asesor))
        ->getJson(URL_LEST)
        ->assertOk()
        ->assertJsonCount(0, 'data');
});


// ---------- Quién aparece y quién no ----------

it('listar estudiantes: solo salen estudiantes activos de la misma facultad', function () {
    $asesor = lestAsesor();
    $valido = lestEstudiante();

    // Ninguno de estos debe aparecer
    lestAsesor();                                                                                  // asesor puro
    lestEstudiante(['facultad_id' => UsuarioFactory::facultad('OTR')->id]);                        // otra facultad
    Usuario::factory()->pendiente()->conRol(Rol::Estudiante)->create();                           // cuenta pendiente
    lestEstudiante(['estatus' => EstatusUsuario::Inactivo]);                                       // cuenta inactiva

    $this->withToken(lestToken($asesor))
        ->getJson(URL_LEST)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $valido->id);
});

it('listar estudiantes: el asesor par no se ve a sí mismo, pero sí a otro asesor par', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $otroPar = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();

    $this->withToken(lestToken($par))
        ->getJson(URL_LEST)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $otroPar->id);
});


// ---------- Búsqueda ----------

it('listar estudiantes: ?buscar= encuentra por nombre y por apellidos', function () {
    $asesor = lestAsesor();
    $luis = lestEstudiante(['nombre' => 'Luis', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'Gámez']);
    $ana = lestEstudiante(['nombre' => 'Ana', 'apellido_paterno' => 'Núñez', 'apellido_materno' => 'Castro']);
    $token = lestToken($asesor);

    $casos = [
        'Luis' => $luis->id,
        'Castro' => $ana->id,
        'Gámez' => $luis->id,
        // Nombre + apellido juntos: la búsqueda se hace sobre el nombre completo
        'Luis Pérez' => $luis->id,
        'Ana Núñez Castro' => $ana->id,
    ];

    foreach ($casos as $texto => $esperado) {
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson(URL_LEST.'?buscar='.urlencode($texto))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $esperado);
    }
});

it('listar estudiantes: ?buscar= encuentra por número de cuenta', function () {
    $asesor = lestAsesor();
    lestEstudiante(['numero_cuenta' => '20230001']);
    $segundo = lestEstudiante(['numero_cuenta' => '20230002']);

    $this->withToken(lestToken($asesor))
        ->getJson(URL_LEST.'?buscar=20230002')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $segundo->id);
});

it('listar estudiantes: ?buscar= sin coincidencias devuelve la lista vacía', function () {
    $asesor = lestAsesor();
    lestEstudiante(['nombre' => 'Luis']);

    $this->withToken(lestToken($asesor))
        ->getJson(URL_LEST.'?buscar=Zzzzz')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('listar estudiantes: ?buscar= no deja ver a personas fuera de lo permitido aunque coincidan', function () {
    $asesor = lestAsesor();
    $propio = lestEstudiante(['nombre' => 'Luis']);

    lestEstudiante(['nombre' => 'Luis', 'facultad_id' => UsuarioFactory::facultad('OTR')->id]);   // otra facultad
    lestEstudiante(['nombre' => 'Luis', 'estatus' => EstatusUsuario::Inactivo]);                   // inactivo
    lestAsesor(['nombre' => 'Luis']);                                                              // asesor puro

    $this->withToken(lestToken($asesor))
        ->getJson(URL_LEST.'?buscar=Luis')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $propio->id);
});

it('listar estudiantes rechaza con 422 un buscar de más de 100 caracteres y acepta uno de 100', function () {
    $asesor = lestAsesor();
    $token = lestToken($asesor);

    $this->withToken($token)
        ->getJson(URL_LEST.'?buscar='.str_repeat('a', 101))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('buscar');

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson(URL_LEST.'?buscar='.str_repeat('a', 100))
        ->assertOk();
});


// ---------- Orden y paginación ----------

it('listar estudiantes: ordena por apellido paterno, apellido materno y nombre', function () {
    $asesor = lestAsesor();

    // Se crean desordenados a propósito
    $zamora = lestEstudiante(['nombre' => 'Ana', 'apellido_paterno' => 'Zamora', 'apellido_materno' => 'Ruiz']);
    $beltranB = lestEstudiante(['nombre' => 'Beto', 'apellido_paterno' => 'Beltrán', 'apellido_materno' => 'Soto']);
    $aguirre = lestEstudiante(['nombre' => 'Carlos', 'apellido_paterno' => 'Aguirre', 'apellido_materno' => 'Lara']);
    $beltranA = lestEstudiante(['nombre' => 'Aldo', 'apellido_paterno' => 'Beltrán', 'apellido_materno' => 'Soto']);

    $ids = $this->withToken(lestToken($asesor))
        ->getJson(URL_LEST)
        ->assertOk()
        ->json('data.*.id');

    // Beltrán Soto empata en apellidos: desempata el nombre (Aldo antes que Beto)
    expect($ids)->toBe([$aguirre->id, $beltranA->id, $beltranB->id, $zamora->id]);
});

it('listar estudiantes: pagina de 10 en 10', function () {
    $asesor = lestAsesor();

    foreach (range(1, 12) as $i) {
        lestEstudiante();
    }

    $token = lestToken($asesor);

    $this->withToken($token)
        ->getJson(URL_LEST)
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 12)
        ->assertJsonPath('meta.last_page', 2);

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson(URL_LEST.'?page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});