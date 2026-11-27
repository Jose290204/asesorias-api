<?php

use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Models\Bitacora;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Facades\Hash;

const URL_ACA = '/api/v1/admin/asesores-disciplinares/carga';

// ---------- Ayudantes ----------

function acaToken(Usuario $u): string
{
    return $u->createToken('prueba')->plainTextToken;
}

function acaUsuario(Rol $rol, string $siglas = 'FIC', array $extra = []): Usuario
{
    return Usuario::factory()->conRol($rol)->create(
        array_merge(['facultad_id' => UsuarioFactory::facultad($siglas)->id], $extra)
    );
}

// Petición como admin (de FIC si no se pasa otro). Limpia el guard: Sanctum cachea al usuario
function acaComoAdmin(?Usuario $admin = null)
{
    app('auth')->forgetGuards();

    return test()->withToken(acaToken($admin ?? acaUsuario(Rol::Admin)));
}

// Una fila de la plantilla ya leída por el front. $n = renglón de Excel
function acaFila(int $n = 2, array $extra = []): array
{
    return array_merge([
        'fila' => $n,
        'nombre' => 'Marco Antonio',
        'apellido_paterno' => 'Valdez',
        'apellido_materno' => 'Rios',
        'correo' => "asesor{$n}@info.uas.edu.mx",
        'numero_cuenta' => '',
        'num_cel' => '',
        'password' => 'Rapido.25Y',
    ], $extra);
}

// $cuantas filas válidas y distintas, desde el renglón 2
function acaFilas(int $cuantas, array $extra = []): array
{
    return array_map(fn ($i) => acaFila($i + 2, $extra), range(0, $cuantas - 1));
}

function acaCarga(array $filas, ?bool $confirmar = null, ?Usuario $admin = null)
{
    $cuerpo = ['filas' => $filas];
    if ($confirmar !== null) {
        $cuerpo['confirmar'] = $confirmar;
    }

    return acaComoAdmin($admin)->postJson(URL_ACA, $cuerpo);
}

function acaCreados(): int
{
    return Usuario::where('correo', 'like', 'asesor%@info.uas.edu.mx')->count();
}

// ---------- Acceso ----------

it('aca: sin token recibe 401', function () {
    $this->postJson(URL_ACA, ['filas' => acaFilas(1)])->assertUnauthorized();
});

it('aca: asesor y estudiante reciben 403', function () {
    foreach ([acaUsuario(Rol::Asesor), acaUsuario(Rol::Estudiante)] as $usuario) {
        app('auth')->forgetGuards();
        $this->withToken(acaToken($usuario))
            ->postJson(URL_ACA, ['filas' => acaFilas(1), 'confirmar' => true])->assertForbidden();
    }

    expect(acaCreados())->toBe(0);
});

it('aca: el super admin sin X-Facultad-Id recibe 422', function () {
    $this->withToken(acaToken(Usuario::factory()->conRol(Rol::SuperAdmin)->create()))
        ->postJson(URL_ACA, ['filas' => acaFilas(1)])->assertStatus(422);
});

// ---------- Forma de la petición ----------

it('aca forma: 422 sin filas, con filas vacías, con más de 1000 o con una fila que no es objeto', function () {
    acaComoAdmin()->postJson(URL_ACA, [])->assertStatus(422)->assertJsonValidationErrors('filas');
    acaComoAdmin()->postJson(URL_ACA, ['filas' => []])->assertStatus(422)->assertJsonValidationErrors('filas');
    acaComoAdmin()->postJson(URL_ACA, ['filas' => array_fill(0, 1001, acaFila())])
        ->assertStatus(422)->assertJsonValidationErrors('filas');
    acaComoAdmin()->postJson(URL_ACA, ['filas' => ['texto suelto']])
        ->assertStatus(422)->assertJsonValidationErrors('filas.0');
});

it('aca forma: confirmar debe ser booleano', function () {
    acaComoAdmin()->postJson(URL_ACA, ['filas' => acaFilas(1), 'confirmar' => 'tal vez'])
        ->assertStatus(422)->assertJsonValidationErrors('confirmar');
});

it('aca forma: acepta exactamente 1000 filas al validar', function () {
    acaCarga(acaFilas(1000), false)->assertOk()
        ->assertJsonPath('data.total', 1000)
        ->assertJsonPath('data.validas', 1000);
});

// ---------- Validar ----------

it('aca validar: cuenta las filas válidas y no crea a nadie', function () {
    acaCarga(acaFilas(3), false)->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.validas', 3)
        ->assertJsonPath('data.con_errores', 0)
        ->assertJsonPath('data.creados', 0)
        ->assertJsonPath('data.filas', []);

    expect(acaCreados())->toBe(0);
});

it('aca validar: sin "confirmar" también solo valida', function () {
    acaCarga(acaFilas(2))->assertOk()->assertJsonPath('data.creados', 0);

    expect(acaCreados())->toBe(0);
});

it('aca validar: solo regresa las filas con error, con su renglón de Excel y el nombre', function () {
    $filas = [
        acaFila(2),
        acaFila(3, ['nombre' => '']),
        acaFila(4),
        acaFila(5, ['correo' => 'sin-arroba']),
    ];

    acaCarga($filas, false)->assertOk()
        ->assertJsonPath('data.total', 4)
        ->assertJsonPath('data.validas', 2)
        ->assertJsonPath('data.con_errores', 2)
        ->assertJsonPath('data.filas.0.fila', 3)
        ->assertJsonPath('data.filas.0.errores.0', 'El nombre es obligatorio.')
        ->assertJsonPath('data.filas.1.fila', 5)
        ->assertJsonPath('data.filas.1.asesor', 'Marco Antonio Valdez Rios')
        ->assertJsonPath('data.filas.1.errores.0', 'Escribe un correo válido.');
});

it('aca validar: si una fila no trae "fila" se usa su posición + 2 (el renglón 1 es el encabezado)', function () {
    $fila = acaFila(9, ['nombre' => '']);
    unset($fila['fila']);

    acaCarga([acaFila(2), $fila], false)->assertOk()
        ->assertJsonPath('data.filas.0.fila', 3);
});

it('aca validar: una fila con varios problemas los lista todos', function () {
    acaCarga([acaFila(2, ['nombre' => '', 'correo' => 'x', 'password' => 'abc'])], false)->assertOk()
        ->assertJsonCount(3, 'data.filas.0.errores');
});

// ---------- Campos ----------

it('aca campos: nombres con números o símbolos, cuenta con letras y teléfono mal formado dan error', function () {
    $filas = [
        acaFila(2, ['nombre' => 'Marco4']),
        acaFila(3, ['apellido_paterno' => 'Val@dez']),
        acaFila(4, ['apellido_materno' => 'R1os']),
        acaFila(5, ['numero_cuenta' => '23AB-1']),
        acaFila(6, ['num_cel' => '12345']),
        acaFila(7, ['num_cel' => '66-ABC-1234']),
    ];

    $r = acaCarga($filas, false)->assertOk()->assertJsonPath('data.con_errores', 6);

    expect(collect($r->json('data.filas'))->pluck('errores.0')->all())->toBe([
        'El nombre solo puede tener letras.',
        'El apellido paterno solo puede tener letras.',
        'El apellido materno solo puede tener letras.',
        'El número de cuenta solo puede tener dígitos.',
        'El teléfono debe tener entre 10 y 15 dígitos.',
        'El teléfono debe tener entre 10 y 15 dígitos.',
    ]);
});

it('aca campos: la cuenta con guion se acepta y se guarda solo con dígitos', function () {
    acaCarga([acaFila(2, ['numero_cuenta' => ' 2327992-3 '])], true)->assertOk()
        ->assertJsonPath('data.creados', 1);

    expect(Usuario::where('correo', 'asesor2@info.uas.edu.mx')->value('numero_cuenta'))->toBe('23279923');
});

it('aca campos: apellido materno, cuenta y teléfono vacíos son válidos y quedan en null', function () {
    acaCarga([acaFila(2, ['apellido_materno' => '', 'numero_cuenta' => '', 'num_cel' => ''])], true)->assertOk()
        ->assertJsonPath('data.creados', 1);

    $u = Usuario::where('correo', 'asesor2@info.uas.edu.mx')->firstOrFail();

    expect($u->apellido_materno)->toBeNull()
        ->and($u->numero_cuenta)->toBeNull()
        ->and($u->num_cel)->toBeNull();
});

it('aca campos: un teléfono con guiones o espacios se limpia a dígitos', function () {
    acaCarga([acaFila(2, ['num_cel' => '667 123-4567'])], true)->assertOk()
        ->assertJsonPath('data.creados', 1);

    expect(Usuario::where('correo', 'asesor2@info.uas.edu.mx')->value('num_cel'))->toBe('6671234567');
});

it('aca campos: la contraseña debe tener entre 8 y 72 caracteres', function () {
    $filas = [
        acaFila(2, ['password' => '1234567']),
        acaFila(3, ['password' => '12345678']),
        acaFila(4, ['password' => str_repeat('a', 72)]),
        acaFila(5, ['password' => str_repeat('a', 73)]),
        acaFila(6, ['password' => '']),
    ];

    $r = acaCarga($filas, false)->assertOk()->assertJsonPath('data.validas', 2)->assertJsonPath('data.con_errores', 3);

    expect(collect($r->json('data.filas'))->pluck('fila')->all())->toBe([2, 5, 6]);
});

// ---------- Duplicados ----------

it('aca duplicados: un correo que ya existe en la base da error', function () {
    acaUsuario(Rol::Asesor, 'FIC', ['correo' => 'asesor2@info.uas.edu.mx']);

    acaCarga([acaFila(2)], false)->assertOk()
        ->assertJsonPath('data.con_errores', 1)
        ->assertJsonPath('data.filas.0.errores.0', 'Ya existe un usuario con ese correo.');
});

it('aca duplicados: un número de cuenta que ya existe da error, aunque sea de un usuario dado de baja', function () {
    acaUsuario(Rol::Asesor, 'FIC', ['numero_cuenta' => '9999999', 'estatus' => EstatusUsuario::Inactivo]);

    acaCarga([acaFila(2, ['numero_cuenta' => '9999999'])], false)->assertOk()
        ->assertJsonPath('data.filas.0.errores.0', 'Ya existe un usuario con ese número de cuenta.');
});

it('aca duplicados: el mismo correo en dos renglones marca el segundo y dice cuál es el primero', function () {
    $filas = [acaFila(2), acaFila(3, ['correo' => 'asesor2@info.uas.edu.mx'])];

    acaCarga($filas, false)->assertOk()
        ->assertJsonPath('data.validas', 1)
        ->assertJsonPath('data.filas.0.fila', 3)
        ->assertJsonPath('data.filas.0.errores.0', 'El correo se repite en el renglón 2.');
});

it('aca duplicados: el mismo correo con mayúsculas distintas cuenta como repetido', function () {
    $filas = [acaFila(2, ['correo' => 'Dup@Info.uas.edu.mx']), acaFila(3, ['correo' => 'dup@info.uas.edu.mx'])];

    acaCarga($filas, false)->assertOk()
        ->assertJsonPath('data.filas.0.fila', 3)
        ->assertJsonPath('data.filas.0.errores.0', 'El correo se repite en el renglón 2.');
});

it('aca duplicados: la misma cuenta en dos renglones, con o sin guion, marca el segundo', function () {
    $filas = [
        acaFila(2, ['numero_cuenta' => '2327992-3']),
        acaFila(3, ['numero_cuenta' => '23279923']),
    ];

    acaCarga($filas, false)->assertOk()
        ->assertJsonPath('data.filas.0.fila', 3)
        ->assertJsonPath('data.filas.0.errores.0', 'El número de cuenta se repite en el renglón 2.');
});

it('aca duplicados: varios asesores sin número de cuenta no cuentan como repetidos', function () {
    acaCarga(acaFilas(3), true)->assertOk()
        ->assertJsonPath('data.validas', 3)
        ->assertJsonPath('data.creados', 3);
});

it('aca duplicados: los valores de una fila con error también cuentan para detectar repetidos', function () {
    $filas = [
        acaFila(2, ['nombre' => '']),
        acaFila(3, ['correo' => 'asesor2@info.uas.edu.mx']),
    ];

    acaCarga($filas, false)->assertOk()
        ->assertJsonPath('data.con_errores', 2)
        ->assertJsonPath('data.filas.1.errores.0', 'El correo se repite en el renglón 2.');
});

// ---------- Confirmar ----------

it('aca confirmar: crea asesores activos, solo con rol de asesor, en la facultad del admin y con la contraseña cifrada', function () {
    acaCarga(acaFilas(3), true)->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.validas', 3)
        ->assertJsonPath('data.creados', 3)
        ->assertJsonPath('data.con_errores', 0);

    $u = Usuario::where('correo', 'asesor2@info.uas.edu.mx')->firstOrFail();

    expect($u->estatus)->toBe(EstatusUsuario::Activo)
        ->and($u->facultad_id)->toBe(UsuarioFactory::facultad()->id)
        ->and($u->grupo_id)->toBeNull()
        ->and($u->nombre)->toBe('Marco Antonio')
        ->and($u->apellido_paterno)->toBe('Valdez')
        ->and($u->tieneRol(Rol::Asesor))->toBeTrue()
        ->and($u->tieneRol(Rol::Estudiante))->toBeFalse()
        ->and($u->password)->not->toBe('Rapido.25Y')
        ->and(Hash::check('Rapido.25Y', $u->password))->toBeTrue();
});

it('aca confirmar: el correo se guarda en minúsculas', function () {
    acaCarga([acaFila(2, ['correo' => ' Asesor2@Info.UAS.edu.mx '])], true)->assertOk()
        ->assertJsonPath('data.creados', 1);

    expect(Usuario::where('correo', 'asesor2@info.uas.edu.mx')->exists())->toBeTrue();
});

it('aca confirmar: las filas con error no se crean y las válidas sí', function () {
    $filas = [acaFila(2), acaFila(3, ['password' => 'corta']), acaFila(4)];

    acaCarga($filas, true)->assertOk()
        ->assertJsonPath('data.validas', 2)
        ->assertJsonPath('data.creados', 2)
        ->assertJsonPath('data.con_errores', 1)
        ->assertJsonPath('data.filas.0.fila', 3);

    expect(Usuario::where('correo', 'asesor3@info.uas.edu.mx')->exists())->toBeFalse()
        ->and(acaCreados())->toBe(2);
});

it('aca confirmar: repetir la misma carga no duplica a nadie y marca todo como existente', function () {
    acaCarga(acaFilas(3), true)->assertOk()->assertJsonPath('data.creados', 3);

    acaCarga(acaFilas(3), true)->assertOk()
        ->assertJsonPath('data.creados', 0)
        ->assertJsonPath('data.con_errores', 3)
        ->assertJsonPath('data.filas.0.errores.0', 'Ya existe un usuario con ese correo.');

    expect(acaCreados())->toBe(3);
});

it('aca confirmar: la bitácora registra cada alta con el admin como autor', function () {
    $admin = acaUsuario(Rol::Admin);

    acaCarga(acaFilas(3), true, $admin)->assertOk()->assertJsonPath('data.creados', 3);

    expect(Bitacora::where('accion', 'asesor.creado')->where('usuario_id', $admin->id)->count())->toBe(3);
});

it('aca confirmar: validar primero no deja nada en la bitácora ni en la base', function () {
    $antes = Bitacora::count();

    acaCarga(acaFilas(3), false)->assertOk();

    expect(Bitacora::count())->toBe($antes)
        ->and(acaCreados())->toBe(0);
});

it('aca confirmar: una carga hecha en una facultad no toca a los usuarios de otra', function () {
    $ajeno = acaUsuario(Rol::Asesor, 'OTR', ['correo' => 'ajeno@info.uas.edu.mx']);

    acaCarga(acaFilas(2), true)->assertOk()->assertJsonPath('data.creados', 2);

    expect(Usuario::where('correo', 'like', 'asesor%@info.uas.edu.mx')->pluck('facultad_id')->unique()->all())
        ->toBe([UsuarioFactory::facultad('FIC')->id])
        ->and($ajeno->fresh()->facultad_id)->toBe(UsuarioFactory::facultad('OTR')->id);
});

it('aca confirmar: el super admin con X-Facultad-Id carga en esa facultad', function () {
    app('auth')->forgetGuards();

    $this->withToken(acaToken(Usuario::factory()->conRol(Rol::SuperAdmin)->create()))
        ->withHeader('X-Facultad-Id', (string) UsuarioFactory::facultad('FIC')->id)
        ->postJson(URL_ACA, ['filas' => acaFilas(1), 'confirmar' => true])
        ->assertOk()->assertJsonPath('data.creados', 1);

    expect(Usuario::where('correo', 'asesor2@info.uas.edu.mx')->value('facultad_id'))
        ->toBe(UsuarioFactory::facultad('FIC')->id);
});
