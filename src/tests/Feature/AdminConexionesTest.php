<?php

// Autocontenido (prefijo "acn"): las funciones y constantes de los archivos de Pest son globales.

use App\Enums\Rol;
use App\Models\Bitacora;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const URL_ACN = '/api/v1/admin/conexiones';

const ACN_UA_CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const ACN_UA_SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

// ---------- Ayudantes ----------

// Nombres fijos: los apellidos al azar de faker pueden traer apóstrofes y hacer pruebas intermitentes
function acnUsuario(array|Rol $roles, string $siglas = 'FIC', array $extra = []): Usuario
{
    return Usuario::factory()->conRol(...(is_array($roles) ? $roles : [$roles]))->create(array_merge([
        'facultad_id' => UsuarioFactory::facultad($siglas)->id,
        'nombre' => 'Mario',
        'apellido_paterno' => 'Ramírez',
        'apellido_materno' => 'Soto',
    ], $extra));
}

// Petición como el usuario dado (admin de FIC si no se pasa). Devuelve [peticion, id del token con el que se hace].
function acnComo(?Usuario $usuario = null): array
{
    app('auth')->forgetGuards();

    $token = ($usuario ?? acnUsuario(Rol::Admin))->createToken('prueba');

    return [test()->withToken($token->plainTextToken), $token->accessToken->id];
}

// Inserta una sesión web tal cual. Devuelve su id (sin prefijo).
function acnSesionWeb(Usuario $usuario, array $extra = []): string
{
    $id = Str::random(40);

    DB::table('sessions')->insert(array_merge([
        'id' => $id,
        'user_id' => $usuario->id,
        'ip_address' => '189.203.10.21',
        'user_agent' => ACN_UA_CHROME_WINDOWS,
        'payload' => '',
        'last_activity' => now()->timestamp,
    ], $extra));

    return $id;
}

// Crea un token de app con el nombre de dispositivo dado. Devuelve su id.
function acnTokenApp(Usuario $usuario, string $dispositivo = 'Pixel 7'): int
{
    return $usuario->createToken($dispositivo)->accessToken->id;
}

function acnIds($respuesta): array
{
    return collect($respuesta->json('data'))->pluck('id')->all();
}

// ---------- Acceso ----------

it('acn: sin token recibe 401', function () {
    $this->getJson(URL_ACN)->assertUnauthorized();
    $this->deleteJson(URL_ACN.'/web-abc')->assertUnauthorized();
});

it('acn: asesor y estudiante reciben 403', function (Rol $rol) {
    [$como] = acnComo(acnUsuario($rol));
    $como->getJson(URL_ACN)->assertForbidden();

    [$como] = acnComo(acnUsuario($rol));
    $como->deleteJson(URL_ACN.'/web-abc')->assertForbidden();
})->with([Rol::Asesor, Rol::Estudiante]);

it('acn: el super admin sin X-Facultad-Id recibe 422', function () {
    [$como] = acnComo(Usuario::factory()->conRol(Rol::SuperAdmin)->create());

    $como->getJson(URL_ACN)->assertStatus(422);
});

it('acn: no se puede crear ni editar conexiones por la API', function (string $metodo) {
    [$como] = acnComo();

    $como->json($metodo, URL_ACN)->assertStatus(405);
})->with(['post', 'put', 'patch']);

// ---------- Listado ----------

it('acn: lista las sesiones web y los tokens de la app con todos sus campos', function () {
    $estudiante = acnUsuario(Rol::Estudiante, 'FIC', ['correo' => 'est@fic.test']);
    $sesion = acnSesionWeb($estudiante);
    $token = acnTokenApp($estudiante, 'Pixel 7');

    [$como] = acnComo();
    $respuesta = $como->getJson(URL_ACN)->assertOk();

    expect(acnIds($respuesta))->toContain("web-{$sesion}", "app-{$token}");

    $web = collect($respuesta->json('data'))->firstWhere('id', "web-{$sesion}");
    expect($web)->toMatchArray([
        'tipo' => 'web',
        'usuario_id' => $estudiante->id,
        'nombre' => 'Mario Ramírez Soto',
        'correo' => 'est@fic.test',
        'rol' => 'Estudiante',
        'dispositivo' => 'Chrome en Windows',
        'ip' => '189.203.10.21',
        'es_actual' => false,
    ])->and($web['ultima_actividad'])->not->toBeNull();

    $app = collect($respuesta->json('data'))->firstWhere('id', "app-{$token}");
    expect($app)->toMatchArray([
        'tipo' => 'app',
        'usuario_id' => $estudiante->id,
        'dispositivo' => 'App móvil (Pixel 7)',
        'ip' => null,
        'es_actual' => false,
    ])->and($app['inicio_sesion'])->not->toBeNull();
});

it('acn: marca como actual la conexión desde la que se pide', function () {
    $admin = acnUsuario(Rol::Admin);
    [$como, $tokenActual] = acnComo($admin);
    $otro = acnTokenApp($admin, 'Otro teléfono');

    $respuesta = $como->getJson(URL_ACN)->assertOk();

    $filas = collect($respuesta->json('data'));
    expect($filas->firstWhere('id', "app-{$tokenActual}")['es_actual'])->toBeTrue()
        ->and($filas->firstWhere('id', "app-{$otro}")['es_actual'])->toBeFalse();
});

it('acn: etiqueta el rol: administrador, asesor par, disciplinar y estudiante', function () {
    $filas = [
        'Administrador' => acnUsuario(Rol::Admin),
        'Asesor par' => acnUsuario([Rol::Asesor, Rol::Estudiante]),
        'Asesor disciplinar' => acnUsuario(Rol::Asesor),
        'Estudiante' => acnUsuario(Rol::Estudiante),
    ];
    $ids = collect($filas)->map(fn ($u) => acnSesionWeb($u))->all();

    [$como] = acnComo();
    $respuesta = $como->getJson(URL_ACN)->assertOk();

    foreach ($filas as $etiqueta => $usuario) {
        $fila = collect($respuesta->json('data'))->firstWhere('id', 'web-'.$ids[$etiqueta]);
        expect($fila['rol'])->toBe($etiqueta);
    }
});

it('acn: reconoce el navegador y el sistema, y cae en "Navegador" si no los conoce', function () {
    $u = acnUsuario(Rol::Estudiante);
    $iphone = acnSesionWeb($u, ['user_agent' => ACN_UA_SAFARI_IPHONE]);
    $raro = acnSesionWeb($u, ['user_agent' => 'curl/8.0']);
    $vacio = acnSesionWeb($u, ['user_agent' => null]);

    [$como] = acnComo();
    $filas = collect($como->getJson(URL_ACN)->json('data'));

    expect($filas->firstWhere('id', "web-{$iphone}")['dispositivo'])->toBe('Safari en iOS')
        ->and($filas->firstWhere('id', "web-{$raro}")['dispositivo'])->toBe('Navegador')
        ->and($filas->firstWhere('id', "web-{$vacio}")['dispositivo'])->toBe('Navegador');
});

it('acn: la más reciente va primero', function () {
    $u = acnUsuario(Rol::Estudiante);
    $vieja = acnSesionWeb($u, ['last_activity' => now()->subMinutes(30)->timestamp]);
    $nueva = acnSesionWeb($u, ['last_activity' => now()->subMinute()->timestamp]);

    [$como] = acnComo();
    $ids = acnIds($como->getJson(URL_ACN));

    expect(array_search("web-{$nueva}", $ids))->toBeLessThan(array_search("web-{$vieja}", $ids));
});

it('acn: no lista sesiones web vencidas', function () {
    $u = acnUsuario(Rol::Estudiante);
    $vencida = acnSesionWeb($u, ['last_activity' => now()->subMinutes((int) config('session.lifetime') + 5)->timestamp]);

    [$como] = acnComo();

    expect(acnIds($como->getJson(URL_ACN)))->not->toContain("web-{$vencida}");
});

it('acn: solo trae usuarios de su facultad y no a los dados de baja', function () {
    $propio = acnUsuario(Rol::Estudiante);
    $ajeno = acnUsuario(Rol::Estudiante, 'OTR');
    $baja = acnUsuario(Rol::Estudiante);

    $sPropia = acnSesionWeb($propio);
    $sAjena = acnSesionWeb($ajeno);
    $sBaja = acnSesionWeb($baja);
    $tAjeno = acnTokenApp($ajeno);
    $baja->delete();

    [$como] = acnComo();
    $ids = acnIds($como->getJson(URL_ACN));

    expect($ids)->toContain("web-{$sPropia}")
        ->and($ids)->not->toContain("web-{$sAjena}", "app-{$tAjeno}", "web-{$sBaja}");
});

it('acn: un admin de otra facultad no ve las conexiones de esta', function () {
    $u = acnUsuario(Rol::Estudiante);
    $sesion = acnSesionWeb($u);

    [$como] = acnComo(acnUsuario(Rol::Admin, 'OTR'));

    expect(acnIds($como->getJson(URL_ACN)))->not->toContain("web-{$sesion}");
});

// ---------- Cerrar una conexión ----------

it('acn: cierra la sesión web de otro usuario y queda en bitácora', function () {
    $u = acnUsuario(Rol::Estudiante, 'FIC', ['correo' => 'est@fic.test']);
    $sesion = acnSesionWeb($u);

    [$como] = acnComo();
    $como->deleteJson(URL_ACN."/web-{$sesion}")->assertNoContent();

    expect(DB::table('sessions')->where('id', $sesion)->exists())->toBeFalse();

    $registro = Bitacora::withoutGlobalScopes()->where('accion', 'conexion.cerrada')->first();
    expect($registro)->not->toBeNull()
        ->and($registro->usuario_id)->not->toBe($u->id)
        ->and($registro->detalles['etiqueta'])->toBe('Mario Ramírez Soto')
        ->and($registro->detalles['detalle'])->toContain('est@fic.test', 'Chrome en Windows');
});

it('acn: revoca el token de la app de otro usuario', function () {
    $u = acnUsuario(Rol::Asesor);
    $token = acnTokenApp($u, 'Pixel 7');

    [$como] = acnComo();
    $como->deleteJson(URL_ACN."/app-{$token}")->assertNoContent();

    expect(DB::table('personal_access_tokens')->where('id', $token)->exists())->toBeFalse();
    expect(Bitacora::withoutGlobalScopes()->where('accion', 'conexion.cerrada')->count())->toBe(1);
});

it('acn: no deja cerrar la propia conexión', function () {
    $admin = acnUsuario(Rol::Admin);
    [$como, $tokenActual] = acnComo($admin);

    $como->deleteJson(URL_ACN."/app-{$tokenActual}")
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'conexion_propia');

    expect(DB::table('personal_access_tokens')->where('id', $tokenActual)->exists())->toBeTrue();
    expect(Bitacora::withoutGlobalScopes()->where('accion', 'conexion.cerrada')->count())->toBe(0);
});

it('acn: no deja cerrar la conexión de un super admin', function () {
    $super = acnUsuario(Rol::SuperAdmin);
    $sesion = acnSesionWeb($super);

    [$como] = acnComo();
    $como->deleteJson(URL_ACN."/web-{$sesion}")
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'conexion_super_admin');

    expect(DB::table('sessions')->where('id', $sesion)->exists())->toBeTrue();
});

it('acn: 404 si la conexión no existe, es de otra facultad o el id no es válido', function () {
    $ajeno = acnUsuario(Rol::Estudiante, 'OTR');
    $sAjena = acnSesionWeb($ajeno);
    $tAjeno = acnTokenApp($ajeno);

    [$como] = acnComo();

    $como->deleteJson(URL_ACN.'/web-noexiste')->assertNotFound();
    $como->deleteJson(URL_ACN.'/app-99999')->assertNotFound();
    $como->deleteJson(URL_ACN."/web-{$sAjena}")->assertNotFound();
    $como->deleteJson(URL_ACN."/app-{$tAjeno}")->assertNotFound();
    $como->deleteJson(URL_ACN.'/123')->assertNotFound();
    $como->deleteJson(URL_ACN.'/otro-abc')->assertNotFound();

    expect(DB::table('sessions')->where('id', $sAjena)->exists())->toBeTrue();
    expect(DB::table('personal_access_tokens')->where('id', $tAjeno)->exists())->toBeTrue();
});

it('acn: cerrar un token hace que ese token ya no sirva', function () {
    $u = acnUsuario(Rol::Estudiante);
    $nuevo = $u->createToken('Pixel 7');

    [$como] = acnComo();
    $como->deleteJson(URL_ACN.'/app-'.$nuevo->accessToken->id)->assertNoContent();

    app('auth')->forgetGuards();
    $this->withToken($nuevo->plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
});