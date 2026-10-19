<?php

use App\Mail\CodigoRecuperacionMail;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

const URL_CODIGO = '/api/v1/auth/codigo';
const URL_VERIFICAR = '/api/v1/auth/codigo/verificar';
const URL_RECUPERAR = '/api/v1/auth/recuperar';
const URL_LOGIN_REC = '/api/v1/auth/login';
const URL_ME_REC = '/api/v1/auth/me';

// Pide el código y lo recupera del correo falso (en la base solo queda el hash)
function pedirCodigo(string $correo): string
{
    $codigo = null;

    test()->postJson(URL_CODIGO, ['correo' => $correo])->assertOk();

    Mail::assertQueued(CodigoRecuperacionMail::class, function ($mail) use (&$codigo) {
        $codigo = $mail->codigo;

        return true;
    });

    return $codigo;
}

// Un código de 6 dígitos que seguro es distinto del real
function codigoEquivocado(string $real): string
{
    return $real === '000000' ? '111111' : '000000';
}

// Cuerpo estándar de /auth/recuperar
function cuerpoRecuperar(string $correo, string $codigo, string $password = 'nueva-pass-99'): array
{
    return [
        'correo' => $correo, 'codigo' => $codigo,
        'password' => $password, 'password_confirmation' => $password,
    ];
}

beforeEach(fn () => Mail::fake());


// ---------- Paso 1: pedir código ----------

it('manda el código a una cuenta activa', function () {
    $u = Usuario::factory()->create();

    $codigo = pedirCodigo($u->correo);

    expect($codigo)->toMatch('/^\d{6}$/');
    Mail::assertQueued(CodigoRecuperacionMail::class, fn ($m) => $m->hasTo($u->correo));
});

it('responde igual con un correo inexistente y no manda nada', function () {
    $existe = Usuario::factory()->create();

    $a = $this->postJson(URL_CODIGO, ['correo' => $existe->correo])->assertOk();
    $b = $this->postJson(URL_CODIGO, ['correo' => 'nadie@fic.test'])->assertOk();

    expect($a->json())->toBe($b->json());
    Mail::assertQueuedCount(1);
});

it('no manda código a una cuenta pendiente ni a una inactiva', function () {
    $p = Usuario::factory()->pendiente()->create();
    $i = Usuario::factory()->create(['estatus' => 'inactivo']);

    $this->postJson(URL_CODIGO, ['correo' => $p->correo])->assertOk();
    $this->postJson(URL_CODIGO, ['correo' => $i->correo])->assertOk();

    Mail::assertNothingQueued();
});

it('no reenvía antes de 60 segundos y sí después', function () {
    $u = Usuario::factory()->create();

    $this->postJson(URL_CODIGO, ['correo' => $u->correo])->assertOk();
    $this->postJson(URL_CODIGO, ['correo' => $u->correo])->assertOk();
    Mail::assertQueuedCount(1);

    // Hermana feliz: pasado el minuto sí manda otro
    $this->travel(61)->seconds();
    $this->postJson(URL_CODIGO, ['correo' => $u->correo])->assertOk();
    Mail::assertQueuedCount(2);
});

it('rechaza un correo con formato inválido', function () {
    $this->postJson(URL_CODIGO, ['correo' => 'esto-no-es-correo'])->assertUnprocessable();
});

it('acepta el correo con mayúsculas y espacios', function () {
    $u = Usuario::factory()->create();

    $this->postJson(URL_CODIGO, ['correo' => '  '.strtoupper($u->correo).' '])->assertOk();

    Mail::assertQueued(CodigoRecuperacionMail::class, fn ($m) => $m->hasTo($u->correo));
});

it('limita a 3 las peticiones de código por minuto', function () {
    $u = Usuario::factory()->create();

    foreach (range(1, 3) as $i) {
        $this->postJson(URL_CODIGO, ['correo' => $u->correo])->assertOk();
    }

    $this->postJson(URL_CODIGO, ['correo' => $u->correo])->assertStatus(429);
});


// ---------- Paso 2: verificar ----------

it('verifica un código correcto sin consumirlo', function () {
    $u = Usuario::factory()->create();
    $codigo = pedirCodigo($u->correo);

    $this->postJson(URL_VERIFICAR, ['correo' => $u->correo, 'codigo' => $codigo])->assertOk();
    // Sigue vivo para el paso 3
    $this->postJson(URL_VERIFICAR, ['correo' => $u->correo, 'codigo' => $codigo])->assertOk();
});

it('rechaza un código incorrecto', function () {
    $u = Usuario::factory()->create();
    $codigo = pedirCodigo($u->correo);

    $this->postJson(URL_VERIFICAR, ['correo' => $u->correo, 'codigo' => codigoEquivocado($codigo)])
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'codigo_invalido');
});

it('rechaza un código con formato inválido', function () {
    $u = Usuario::factory()->create();

    $this->postJson(URL_VERIFICAR, ['correo' => $u->correo, 'codigo' => '12ab'])
        ->assertUnprocessable();
});

it('da el mismo error con correo inexistente que con código malo', function () {
    $u = Usuario::factory()->create();
    $codigo = pedirCodigo($u->correo);
    $malo = codigoEquivocado($codigo);

    $a = $this->postJson(URL_VERIFICAR, ['correo' => $u->correo, 'codigo' => $malo])->assertUnprocessable();
    $b = $this->postJson(URL_VERIFICAR, ['correo' => 'nadie@fic.test', 'codigo' => $malo])->assertUnprocessable();

    expect($a->json('errors.codigo'))->toBe($b->json('errors.codigo'));
});

it('rechaza un código vencido', function () {
    $u = Usuario::factory()->create();
    $codigo = pedirCodigo($u->correo);

    // Hermana feliz: a los 9 minutos sirve
    $this->travel(9)->minutes();
    $this->postJson(URL_VERIFICAR, ['correo' => $u->correo, 'codigo' => $codigo])->assertOk();

    // A los 11 ya venció
    $this->travel(2)->minutes();
    $this->postJson(URL_VERIFICAR, ['correo' => $u->correo, 'codigo' => $codigo])->assertUnprocessable();
});

it('tras 5 intentos fallidos ya no acepta ni el código correcto', function () {
    $u = Usuario::factory()->create();
    $codigo = pedirCodigo($u->correo);
    $malo = codigoEquivocado($codigo);

    // Se limpia el limitador para no chocar con el 429 (10/min) durante la prueba
    foreach (range(1, 5) as $i) {
        $this->postJson(URL_VERIFICAR, ['correo' => $u->correo, 'codigo' => $malo])->assertUnprocessable();
    }

    $this->postJson(URL_VERIFICAR, ['correo' => $u->correo, 'codigo' => $codigo])->assertUnprocessable();
});


// ---------- Paso 3: restablecer ----------

it('cambia la contraseña: la nueva sirve y la vieja ya no', function () {
    $u = Usuario::factory()->create(['password' => 'vieja-pass-1']);
    $codigo = pedirCodigo($u->correo);

    $this->postJson(URL_RECUPERAR, cuerpoRecuperar($u->correo, $codigo))->assertOk();

    // Hermana feliz: la nueva sirve...
    $this->postJson(URL_LOGIN_REC, [
        'correo' => $u->correo, 'password' => 'nueva-pass-99', 'dispositivo' => 'prueba',
    ])->assertOk();

    // ...y la vieja ya no
    $this->postJson(URL_LOGIN_REC, [
        'correo' => $u->correo, 'password' => 'vieja-pass-1', 'dispositivo' => 'prueba',
    ])->assertUnprocessable();
});

it('el código sirve una sola vez', function () {
    $u = Usuario::factory()->create();
    $codigo = pedirCodigo($u->correo);

    $this->postJson(URL_RECUPERAR, cuerpoRecuperar($u->correo, $codigo))->assertOk();
    $this->postJson(URL_RECUPERAR, cuerpoRecuperar($u->correo, $codigo, 'otra-pass-123'))
        ->assertUnprocessable();
});

it('no cambia la contraseña con un código incorrecto', function () {
    $u = Usuario::factory()->create(['password' => 'vieja-pass-1']);
    $codigo = pedirCodigo($u->correo);

    $this->postJson(URL_RECUPERAR, cuerpoRecuperar($u->correo, codigoEquivocado($codigo)))
        ->assertUnprocessable();

    // La vieja sigue funcionando
    $this->postJson(URL_LOGIN_REC, [
        'correo' => $u->correo, 'password' => 'vieja-pass-1', 'dispositivo' => 'prueba',
    ])->assertOk();
});

it('rechaza una contraseña corta o sin confirmación', function () {
    $u = Usuario::factory()->create();
    $codigo = pedirCodigo($u->correo);

    $this->postJson(URL_RECUPERAR, cuerpoRecuperar($u->correo, $codigo, 'corta'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    $this->postJson(URL_RECUPERAR, [
        'correo' => $u->correo, 'codigo' => $codigo,
        'password' => 'nueva-pass-99', 'password_confirmation' => 'distinta-99',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');

    // Hermana feliz: el código no se gastó con esos rechazos de validación
    $this->postJson(URL_RECUPERAR, cuerpoRecuperar($u->correo, $codigo))->assertOk();
});

it('no restablece la contraseña de una cuenta inactiva', function () {
    $u = Usuario::factory()->create();
    $codigo = pedirCodigo($u->correo);

    // La cuenta se desactiva después de pedir el código
    $u->update(['estatus' => 'inactivo']);

    $this->postJson(URL_RECUPERAR, cuerpoRecuperar($u->correo, $codigo))->assertUnprocessable();
});

it('revoca todos los tokens de la app al restablecer', function () {
    $u = Usuario::factory()->create();
    $u->createToken('android');
    $u->createToken('iphone');
    $codigo = pedirCodigo($u->correo);

    expect($u->tokens()->count())->toBe(2);

    $this->postJson(URL_RECUPERAR, cuerpoRecuperar($u->correo, $codigo))->assertOk();

    expect($u->tokens()->count())->toBe(0);
});

it('cierra las sesiones web al restablecer sin tocar las de otros', function () {
    $u = Usuario::factory()->create();
    $otro = Usuario::factory()->create();

    // Filas de sesión simuladas (driver "database")
    foreach ([['s1', $u->id], ['s2', $u->id], ['s3', $otro->id]] as [$id, $uid]) {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $uid, 'payload' => '', 'last_activity' => time(),
        ]);
    }

    $codigo = pedirCodigo($u->correo);
    $this->postJson(URL_RECUPERAR, cuerpoRecuperar($u->correo, $codigo))->assertOk();

    expect(DB::table('sessions')->where('user_id', $u->id)->count())->toBe(0);
    // Hermana: la sesión del otro usuario sigue
    expect(DB::table('sessions')->where('user_id', $otro->id)->count())->toBe(1);
});

it('un token anterior deja de funcionar tras restablecer', function () {
    $u = Usuario::factory()->create();
    $token = $u->createToken('android')->plainTextToken;

    $this->withToken($token)->getJson(URL_ME_REC)->assertOk();

    $codigo = pedirCodigo($u->correo);
    $this->postJson(URL_RECUPERAR, cuerpoRecuperar($u->correo, $codigo))->assertOk();

    // El guard recuerda al usuario de la petición anterior; se limpia para que la prueba sea real
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson(URL_ME_REC)->assertUnauthorized();
});