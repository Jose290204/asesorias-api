<?php

use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Models\Bitacora;
use App\Models\Grupo;
use App\Models\Licenciatura;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Facades\Hash;

const URL_ACG = '/api/v1/admin/estudiantes/carga';

// ---------- Ayudantes ----------

function acgToken(Usuario $u): string
{
    return $u->createToken('prueba')->plainTextToken;
}

function acgUsuario(Rol $rol, string $siglas = 'FIC', array $extra = []): Usuario
{
    return Usuario::factory()->conRol($rol)->create(
        array_merge(['facultad_id' => UsuarioFactory::facultad($siglas)->id], $extra)
    );
}

// Petición como admin (de FIC si no se pasa otro). Limpia el guard: Sanctum cachea al usuario
function acgComoAdmin(?Usuario $admin = null)
{
    app('auth')->forgetGuards();

    return test()->withToken(acgToken($admin ?? acgUsuario(Rol::Admin)));
}

// Grupo "1-1" de INFORMATICA presencial (o virtual) de la facultad dada: se muestra como "1-1 Informática" / "1-1 Informática Virtual"
function acgGrupo(string $siglas = 'FIC', string $nombre = '1-1', string $modalidad = 'presencial', string $licenciatura = 'LICENCIATURA EN INFORMATICA'): Grupo
{
    $lic = Licenciatura::firstOrCreate([
        'facultad_id' => UsuarioFactory::facultad($siglas)->id,
        'nombre' => $licenciatura,
    ]);

    return Grupo::firstOrCreate(
        ['licenciatura_id' => $lic->id, 'nombre' => $nombre, 'modalidad' => $modalidad],
        ['anio' => (int) $nombre[0]]
    );
}

// Una fila de la plantilla ya leída por el front. $n = renglón de Excel
function acgFila(int $n = 2, array $extra = []): array
{
    return array_merge([
        'fila' => $n,
        'nombre' => 'Jose Carlos',
        'apellido_paterno' => 'Aguilar',
        'apellido_materno' => 'Corvera',
        'correo' => "alumno{$n}@info.uas.edu.mx",
        'numero_cuenta' => sprintf('23%05d-1', $n),
        'grupo' => '1-1 Informática',
        'password' => 'Rapido.25Y',
        'num_cel' => '',
    ], $extra);
}

// $cuantas filas válidas y distintas, desde el renglón 2
function acgFilas(int $cuantas, array $extra = []): array
{
    return array_map(fn ($i) => acgFila($i + 2, $extra), range(0, $cuantas - 1));
}

function acgCarga(array $filas, ?bool $confirmar = null, ?Usuario $admin = null)
{
    $cuerpo = ['filas' => $filas];
    if ($confirmar !== null) {
        $cuerpo['confirmar'] = $confirmar;
    }

    return acgComoAdmin($admin)->postJson(URL_ACG, $cuerpo);
}

// ---------- Acceso ----------

it('acg: sin token recibe 401', function () {
    $this->postJson(URL_ACG, ['filas' => acgFilas(1)])->assertUnauthorized();
});

it('acg: asesor y estudiante reciben 403', function () {
    acgGrupo();

    foreach ([acgUsuario(Rol::Asesor), acgUsuario(Rol::Estudiante)] as $usuario) {
        app('auth')->forgetGuards();
        $this->withToken(acgToken($usuario))
            ->postJson(URL_ACG, ['filas' => acgFilas(1), 'confirmar' => true])->assertForbidden();
    }

    expect(Usuario::where('correo', 'alumno2@info.uas.edu.mx')->exists())->toBeFalse();
});

it('acg: el super admin sin X-Facultad-Id recibe 422', function () {
    acgGrupo();

    $this->withToken(acgToken(Usuario::factory()->conRol(Rol::SuperAdmin)->create()))
        ->postJson(URL_ACG, ['filas' => acgFilas(1)])->assertStatus(422);
});

// ---------- Forma de la petición ----------

it('acg forma: 422 sin filas, con filas vacías, con más de 1000 o con una fila que no es objeto', function () {
    acgGrupo();

    acgComoAdmin()->postJson(URL_ACG, [])->assertStatus(422)->assertJsonValidationErrors('filas');
    acgComoAdmin()->postJson(URL_ACG, ['filas' => []])->assertStatus(422)->assertJsonValidationErrors('filas');
    acgComoAdmin()->postJson(URL_ACG, ['filas' => array_fill(0, 1001, acgFila())])
        ->assertStatus(422)->assertJsonValidationErrors('filas');
    acgComoAdmin()->postJson(URL_ACG, ['filas' => ['texto suelto']])
        ->assertStatus(422)->assertJsonValidationErrors('filas.0');
});

it('acg forma: confirmar debe ser booleano', function () {
    acgGrupo();

    acgComoAdmin()->postJson(URL_ACG, ['filas' => acgFilas(1), 'confirmar' => 'tal vez'])
        ->assertStatus(422)->assertJsonValidationErrors('confirmar');
});

it('acg forma: acepta exactamente 1000 filas al validar', function () {
    acgGrupo();

    acgCarga(acgFilas(1000), false)->assertOk()
        ->assertJsonPath('data.total', 1000)
        ->assertJsonPath('data.validas', 1000);
});

// ---------- Validar (no crea nada) ----------

it('acg validar: cuenta las filas válidas y no crea a nadie', function () {
    acgGrupo();

    acgCarga(acgFilas(3), false)->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.validas', 3)
        ->assertJsonPath('data.con_errores', 0)
        ->assertJsonPath('data.creados', 0)
        ->assertJsonPath('data.filas', []);

    expect(Usuario::where('correo', 'like', 'alumno%@info.uas.edu.mx')->count())->toBe(0);
});

it('acg validar: sin "confirmar" también solo valida', function () {
    acgGrupo();

    acgCarga(acgFilas(2))->assertOk()->assertJsonPath('data.validas', 2)->assertJsonPath('data.creados', 0);

    expect(Usuario::where('correo', 'like', 'alumno%@info.uas.edu.mx')->count())->toBe(0);
});

it('acg validar: solo regresa las filas con error, con su renglón de Excel y el nombre', function () {
    acgGrupo();
    $filas = [
        acgFila(2),
        acgFila(3, ['nombre' => 'Ana2']),
        acgFila(4),
        acgFila(9, ['password' => 'corta']),
    ];

    $r = acgCarga($filas, false)->assertOk()
        ->assertJsonPath('data.total', 4)
        ->assertJsonPath('data.validas', 2)
        ->assertJsonPath('data.con_errores', 2)
        ->assertJsonCount(2, 'data.filas')
        ->assertJsonPath('data.filas.0.fila', 3)
        ->assertJsonPath('data.filas.0.estudiante', 'Ana2 Aguilar Corvera')
        ->assertJsonPath('data.filas.0.errores.0', 'El nombre solo puede tener letras.')
        ->assertJsonPath('data.filas.1.fila', 9)
        ->assertJsonPath('data.filas.1.errores.0', 'La contraseña debe tener al menos 8 caracteres.');
});

it('acg validar: si una fila no trae "fila" se usa su posición + 2 (el renglón 1 es el encabezado)', function () {
    acgGrupo();
    $sinFila = acgFila(2, ['nombre' => 'Ana2']);
    unset($sinFila['fila']);

    acgCarga([acgFila(2), $sinFila], false)->assertOk()
        ->assertJsonPath('data.filas.0.fila', 3);
});

it('acg validar: una fila con varios problemas los lista todos', function () {
    acgGrupo();

    $r = acgCarga([acgFila(2, ['nombre' => '', 'correo' => 'no-es-correo', 'password' => ''])], false)->assertOk();

    expect($r->json('data.filas.0.errores'))
        ->toContain('El nombre es obligatorio.')
        ->toContain('Escribe un correo válido.')
        ->toContain('La contraseña es obligatoria.');
});

// ---------- Reglas de cada campo ----------

it('acg campos: nombres con números o símbolos, cuenta con letras y teléfono mal formado dan error', function () {
    acgGrupo();

    $casos = [
        ['apellido_paterno' => 'Agu1lar', 'El apellido paterno solo puede tener letras.'],
        ['apellido_materno' => 'Cor-vera', 'El apellido materno solo puede tener letras.'],
        ['numero_cuenta' => '23AB-1', 'El número de cuenta solo puede tener dígitos.'],
        ['num_cel' => '123', 'El teléfono debe tener entre 10 y 15 dígitos.'],
    ];

    foreach ($casos as $i => $caso) {
        $mensaje = array_pop($caso);
        $r = acgCarga([acgFila(2, $caso)], false)->assertOk()->assertJsonPath('data.con_errores', 1);
        expect($r->json('data.filas.0.errores'))->toContain($mensaje);
    }
});

it('acg campos: la cuenta del padrón con guion se acepta y se limpia; los espacios sobrantes también', function () {
    acgGrupo();

    acgCarga([acgFila(2, [
        'numero_cuenta' => ' 2327992-3 ',
        'nombre' => '  Jose Carlos ',
        'correo' => '  JC.Aguilar26@INFO.UAS.EDU.MX ',
    ])], true)->assertOk()->assertJsonPath('data.creados', 1);

    $u = Usuario::where('correo', 'jc.aguilar26@info.uas.edu.mx')->firstOrFail();

    expect($u->numero_cuenta)->toBe('23279923')
        ->and($u->nombre)->toBe('Jose Carlos');
});

it('acg campos: apellido materno y teléfono vacíos son válidos y quedan en null', function () {
    acgGrupo();

    acgCarga([acgFila(2, ['apellido_materno' => '', 'num_cel' => ''])], true)
        ->assertOk()->assertJsonPath('data.creados', 1);

    $u = Usuario::where('correo', 'alumno2@info.uas.edu.mx')->firstOrFail();

    expect($u->apellido_materno)->toBeNull()->and($u->num_cel)->toBeNull();
});

it('acg campos: un teléfono con guiones o espacios se limpia a dígitos', function () {
    acgGrupo();

    acgCarga([acgFila(2, ['num_cel' => '667 123-4567'])], true)->assertOk()->assertJsonPath('data.creados', 1);

    expect(Usuario::where('correo', 'alumno2@info.uas.edu.mx')->value('num_cel'))->toBe('6671234567');
});

it('acg campos: la contraseña debe tener entre 8 y 72 caracteres', function () {
    acgGrupo();

    acgCarga([acgFila(2, ['password' => str_repeat('a', 7)])], false)->assertJsonPath('data.con_errores', 1);
    acgCarga([acgFila(2, ['password' => str_repeat('a', 73)])], false)->assertJsonPath('data.con_errores', 1);
    acgCarga([acgFila(2, ['password' => str_repeat('a', 8)]), acgFila(3, ['password' => str_repeat('a', 72)])], false)
        ->assertJsonPath('data.validas', 2);
});

// ---------- Grupo ----------

it('acg grupo: reconoce "1-1 Informática" y "1-1 Informática Virtual" como grupos distintos', function () {
    $presencial = acgGrupo();
    $virtual = acgGrupo('FIC', '1-1', 'virtual');

    acgCarga([
        acgFila(2, ['grupo' => '1-1 Informática']),
        acgFila(3, ['grupo' => '1-1 Informática Virtual']),
    ], true)->assertOk()->assertJsonPath('data.creados', 2);

    expect(Usuario::where('correo', 'alumno2@info.uas.edu.mx')->value('grupo_id'))->toBe($presencial->id)
        ->and(Usuario::where('correo', 'alumno3@info.uas.edu.mx')->value('grupo_id'))->toBe($virtual->id);
});

it('acg grupo: no importan mayúsculas, acentos ni espacios dobles', function () {
    $grupo = acgGrupo();

    acgCarga([
        acgFila(2, ['grupo' => '1-1 informatica']),
        acgFila(3, ['grupo' => '  1-1   INFORMÁTICA ']),
    ], true)->assertOk()->assertJsonPath('data.creados', 2);

    expect(Usuario::whereIn('correo', ['alumno2@info.uas.edu.mx', 'alumno3@info.uas.edu.mx'])->pluck('grupo_id')->unique()->all())
        ->toBe([$grupo->id]);
});

it('acg grupo: reconoce Ciencia de Datos e ITSE por su nombre corto', function () {
    $cd = acgGrupo('FIC', '1-1', 'presencial', 'LICENCIATURA EN INGENIERIA EN CIENCIA DE DATOS');
    $itse = acgGrupo('FIC', '3-1', 'presencial', 'LICENCIATURA EN INGENIERÍA EN TELECOMUNICACIONES, SISTEMAS Y ELECTRÓNICA');

    acgCarga([
        acgFila(2, ['grupo' => '1-1 Ciencia de Datos']),
        acgFila(3, ['grupo' => '3-1 ITSE']),
    ], true)->assertOk()->assertJsonPath('data.creados', 2);

    expect(Usuario::where('correo', 'alumno2@info.uas.edu.mx')->value('grupo_id'))->toBe($cd->id)
        ->and(Usuario::where('correo', 'alumno3@info.uas.edu.mx')->value('grupo_id'))->toBe($itse->id);
});

it('acg grupo: un grupo que no existe da error con el texto que se escribió', function () {
    acgGrupo();

    $r = acgCarga([acgFila(2, ['grupo' => '9-9 Informática'])], false)->assertOk()
        ->assertJsonPath('data.con_errores', 1);

    expect($r->json('data.filas.0.errores'))
        ->toContain('El grupo "9-9 Informática" no existe en esta facultad. Elige uno de la lista.');
});

it('acg grupo: sin grupo pide elegir uno', function () {
    acgGrupo();

    $r = acgCarga([acgFila(2, ['grupo' => ''])], false)->assertOk();

    expect($r->json('data.filas.0.errores'))->toContain('Elige un grupo.');
});

it('acg grupo: un grupo de otra facultad no sirve', function () {
    acgGrupo('FIC');
    acgGrupo('OTR', '2-1');   // solo existe en la otra facultad

    $r = acgCarga([acgFila(2, ['grupo' => '2-1 Informática'])], true)->assertOk()
        ->assertJsonPath('data.creados', 0)
        ->assertJsonPath('data.con_errores', 1);

    expect(Usuario::where('correo', 'alumno2@info.uas.edu.mx')->exists())->toBeFalse();
});

it('acg grupo: dos facultades con el mismo grupo no se mezclan: se usa el de la facultad del admin', function () {
    $fic = acgGrupo('FIC');
    $otro = acgGrupo('OTR');   // también "1-1 Informática" (otra licenciatura, de otra facultad)

    acgCarga([acgFila(2)], true)->assertOk()->assertJsonPath('data.creados', 1);

    $u = Usuario::where('correo', 'alumno2@info.uas.edu.mx')->firstOrFail();

    expect($u->grupo_id)->toBe($fic->id)->and($u->grupo_id)->not->toBe($otro->id);
});

// ---------- Duplicados ----------

it('acg duplicados: un correo que ya existe en la base da error', function () {
    acgGrupo();
    acgUsuario(Rol::Estudiante, 'FIC', ['correo' => 'alumno2@info.uas.edu.mx']);

    $r = acgCarga([acgFila(2), acgFila(3)], false)->assertOk()
        ->assertJsonPath('data.validas', 1)
        ->assertJsonPath('data.filas.0.fila', 2);

    expect($r->json('data.filas.0.errores'))->toContain('Ya existe un usuario con ese correo.');
});

it('acg duplicados: un número de cuenta que ya existe da error, aunque sea de un alumno dado de baja', function () {
    acgGrupo();
    acgUsuario(Rol::Estudiante, 'FIC', ['numero_cuenta' => '23000021', 'estatus' => EstatusUsuario::Inactivo]);

    $r = acgCarga([acgFila(2)], false)->assertOk()->assertJsonPath('data.con_errores', 1);

    expect($r->json('data.filas.0.errores'))->toContain('Ya existe un usuario con ese número de cuenta.');
});

it('acg duplicados: el mismo correo en dos renglones del archivo marca el segundo y dice cuál es el primero', function () {
    acgGrupo();

    $r = acgCarga([
        acgFila(2, ['correo' => 'repetido@info.uas.edu.mx']),
        acgFila(3, ['correo' => 'repetido@info.uas.edu.mx']),
    ], false)->assertOk()
        ->assertJsonPath('data.validas', 1)
        ->assertJsonPath('data.filas.0.fila', 3);

    expect($r->json('data.filas.0.errores'))->toContain('El correo se repite en el renglón 2.');
});

it('acg duplicados: el mismo correo con mayúsculas distintas cuenta como repetido', function () {
    acgGrupo();

    $r = acgCarga([
        acgFila(2, ['correo' => 'repetido@info.uas.edu.mx']),
        acgFila(3, ['correo' => 'REPETIDO@info.uas.edu.mx']),
    ], false)->assertOk()->assertJsonPath('data.con_errores', 1);

    expect($r->json('data.filas.0.errores'))->toContain('El correo se repite en el renglón 2.');
});

it('acg duplicados: la misma cuenta en dos renglones, con o sin guion, marca el segundo', function () {
    acgGrupo();

    $r = acgCarga([
        acgFila(2, ['numero_cuenta' => '2327992-3']),
        acgFila(3, ['numero_cuenta' => '23279923']),
    ], false)->assertOk()
        ->assertJsonPath('data.validas', 1)
        ->assertJsonPath('data.filas.0.fila', 3);

    expect($r->json('data.filas.0.errores'))->toContain('El número de cuenta se repite en el renglón 2.');
});

it('acg duplicados: al confirmar con el mismo correo dos veces solo se crea el primero', function () {
    acgGrupo();

    acgCarga([
        acgFila(2, ['correo' => 'repetido@info.uas.edu.mx']),
        acgFila(3, ['correo' => 'repetido@info.uas.edu.mx']),
    ], true)->assertOk()
        ->assertJsonPath('data.creados', 1)
        ->assertJsonPath('data.con_errores', 1);

    expect(Usuario::where('correo', 'repetido@info.uas.edu.mx')->count())->toBe(1)
        ->and(Usuario::where('correo', 'repetido@info.uas.edu.mx')->value('numero_cuenta'))->toBe('23000021');
});

it('acg duplicados: los valores de una fila con error también cuentan para detectar repetidos', function () {
    // El renglón 3 repite el correo del 2 (error), pero su cuenta ya quedó "vista"; el renglón 4 la repite
    acgGrupo();

    $r = acgCarga([
        acgFila(2, ['correo' => 'repetido@info.uas.edu.mx']),
        acgFila(3, ['correo' => 'repetido@info.uas.edu.mx', 'numero_cuenta' => '5555555-5']),
        acgFila(4, ['numero_cuenta' => '5555555-5']),
    ], false)->assertOk()->assertJsonPath('data.con_errores', 2);

    expect($r->json('data.filas.1.fila'))->toBe(4)
        ->and($r->json('data.filas.1.errores'))->toContain('El número de cuenta se repite en el renglón 3.');
});

// ---------- Confirmar (crea) ----------

it('acg confirmar: crea a los estudiantes activos, con su rol, en la facultad del admin y con la contraseña cifrada', function () {
    $grupo = acgGrupo();

    acgCarga(acgFilas(3), true)->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.validas', 3)
        ->assertJsonPath('data.creados', 3)
        ->assertJsonPath('data.con_errores', 0);

    $u = Usuario::where('correo', 'alumno2@info.uas.edu.mx')->firstOrFail();

    expect($u->estatus)->toBe(EstatusUsuario::Activo)
        ->and($u->facultad_id)->toBe(UsuarioFactory::facultad()->id)
        ->and($u->grupo_id)->toBe($grupo->id)
        ->and($u->nombre)->toBe('Jose Carlos')
        ->and($u->apellido_paterno)->toBe('Aguilar')
        ->and($u->apellido_materno)->toBe('Corvera')
        ->and($u->tieneRol(Rol::Estudiante))->toBeTrue()
        ->and($u->tieneRol(Rol::Asesor))->toBeFalse()
        ->and($u->password)->not->toBe('Rapido.25Y')
        ->and(Hash::check('Rapido.25Y', $u->password))->toBeTrue();
});

it('acg confirmar: el correo se guarda en minúsculas', function () {
    acgGrupo();

    acgCarga([acgFila(2, ['correo' => 'Alumno2@INFO.uas.edu.mx'])], true)->assertOk()->assertJsonPath('data.creados', 1);

    expect(Usuario::where('correo', 'alumno2@info.uas.edu.mx')->exists())->toBeTrue();
});

it('acg confirmar: las filas con error no se crean y las válidas sí', function () {
    acgGrupo();

    acgCarga([
        acgFila(2),
        acgFila(3, ['nombre' => 'Ana2']),
        acgFila(4),
    ], true)->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.creados', 2)
        ->assertJsonPath('data.con_errores', 1)
        ->assertJsonPath('data.filas.0.fila', 3);

    expect(Usuario::where('correo', 'alumno2@info.uas.edu.mx')->exists())->toBeTrue()
        ->and(Usuario::where('correo', 'alumno3@info.uas.edu.mx')->exists())->toBeFalse()
        ->and(Usuario::where('correo', 'alumno4@info.uas.edu.mx')->exists())->toBeTrue();
});

it('acg confirmar: repetir la misma carga no duplica a nadie y marca todo como existente', function () {
    acgGrupo();
    $filas = acgFilas(3);

    acgCarga($filas, true)->assertOk()->assertJsonPath('data.creados', 3);

    $r = acgCarga($filas, true)->assertOk()
        ->assertJsonPath('data.creados', 0)
        ->assertJsonPath('data.con_errores', 3);

    expect($r->json('data.filas.0.errores'))
        ->toContain('Ya existe un usuario con ese correo.')
        ->toContain('Ya existe un usuario con ese número de cuenta.')
        ->and(Usuario::where('correo', 'like', 'alumno%@info.uas.edu.mx')->count())->toBe(3);
});

it('acg confirmar: cargar por lotes de 25 funciona igual que de una sola vez', function () {
    acgGrupo();
    $filas = acgFilas(50);

    acgCarga(array_slice($filas, 0, 25), true)->assertOk()->assertJsonPath('data.creados', 25);
    acgCarga(array_slice($filas, 25), true)->assertOk()->assertJsonPath('data.creados', 25);

    expect(Usuario::where('correo', 'like', 'alumno%@info.uas.edu.mx')->count())->toBe(50);
});

it('acg confirmar: la bitácora registra cada alta con el admin como autor', function () {
    acgGrupo();
    $admin = acgUsuario(Rol::Admin);

    acgCarga(acgFilas(3), true, $admin)->assertOk()->assertJsonPath('data.creados', 3);

    expect(Bitacora::where('accion', 'estudiante.creado')->where('usuario_id', $admin->id)->count())->toBe(3);
});

it('acg confirmar: validar primero no deja nada en la bitácora ni en la base', function () {
    acgGrupo();
    $antes = Bitacora::count();

    acgCarga(acgFilas(3), false)->assertOk();

    expect(Bitacora::count())->toBe($antes)
        ->and(Usuario::where('correo', 'like', 'alumno%@info.uas.edu.mx')->count())->toBe(0);
});

it('acg confirmar: una carga hecha en una facultad no toca a los usuarios de otra', function () {
    acgGrupo('FIC');
    acgGrupo('OTR');
    $ajeno = acgUsuario(Rol::Estudiante, 'OTR', ['correo' => 'ajeno@info.uas.edu.mx']);

    acgCarga(acgFilas(2), true)->assertOk()->assertJsonPath('data.creados', 2);

    expect(Usuario::where('correo', 'like', 'alumno%@info.uas.edu.mx')->pluck('facultad_id')->unique()->all())
        ->toBe([UsuarioFactory::facultad('FIC')->id])
        ->and($ajeno->fresh()->facultad_id)->toBe(UsuarioFactory::facultad('OTR')->id);
});

it('acg confirmar: el super admin con X-Facultad-Id carga en esa facultad', function () {
    $grupo = acgGrupo('FIC');
    app('auth')->forgetGuards();

    $this->withToken(acgToken(Usuario::factory()->conRol(Rol::SuperAdmin)->create()))
        ->withHeader('X-Facultad-Id', (string) UsuarioFactory::facultad('FIC')->id)
        ->postJson(URL_ACG, ['filas' => acgFilas(1), 'confirmar' => true])
        ->assertOk()->assertJsonPath('data.creados', 1);

    expect(Usuario::where('correo', 'alumno2@info.uas.edu.mx')->value('grupo_id'))->toBe($grupo->id);
});