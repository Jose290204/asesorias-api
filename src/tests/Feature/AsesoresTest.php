<?php

use App\Enums\Dia;
use App\Enums\EstatusUsuario;
use App\Enums\Modalidad;
use App\Enums\Rol;
use App\Models\DisponibilidadHorario;
use App\Models\DisponibilidadMateria;
use App\Models\DisponibilidadModalidad;
use App\Models\Facultad;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;

// GET /estudiante/asesores. Constante con nombre propio: las de un archivo de Pest son globales
const URL_ASESORES = '/api/v1/estudiante/asesores';


// ---------- Ayudantes (prefijo "ases" para no chocar con otros archivos) ----------

function asesToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function asesEstudiante(): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create();
}

// Crea (o reutiliza) una materia en una licenciatura de prueba de la facultad FIC
function asesMateria(string $nombre): Materia
{
    $lic = Licenciatura::firstOrCreate([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => 'LICENCIATURA PRUEBA',
    ]);

    return Materia::firstOrCreate(
        ['licenciatura_id' => $lic->id, 'nombre' => $nombre],
        ['semestre' => 1]
    );
}

// Crea (o reutiliza) un horario: el mismo texto devuelve siempre el mismo id
function asesHorario(string $etiqueta): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Las tres funciones siguientes dan de alta una fila de disponibilidad.
// facultad_id va explícito: al preparar datos no hay usuario logueado, así que el trait no lo rellena
function asesDarMateria(Usuario $usuario, Materia $materia): void
{
    DisponibilidadMateria::create([
        'facultad_id' => $usuario->facultad_id,
        'asesor_id' => $usuario->id,
        'materia_id' => $materia->id,
    ]);
}

function asesDarHorario(Usuario $usuario, Dia $dia, Horario $horario): void
{
    DisponibilidadHorario::create([
        'facultad_id' => $usuario->facultad_id,
        'asesor_id' => $usuario->id,
        'dia' => $dia,
        'horario_id' => $horario->id,
    ]);
}

function asesDarModalidad(Usuario $usuario, Modalidad $modalidad): void
{
    DisponibilidadModalidad::create([
        'facultad_id' => $usuario->facultad_id,
        'asesor_id' => $usuario->id,
        'modalidad' => $modalidad,
    ]);
}

// Un asesor visible y completo: rol asesor, activo, y una materia, un horario y una modalidad.
// $datos sobrescribe columnas del usuario (nombre, facultad_id, estatus...)
function asesAsesor(
    array $datos = [],
    string $materia = 'Estructura de Datos',
    Dia $dia = Dia::Lunes,
    string $horario = '7:00-8:00 AM',
    Modalidad $modalidad = Modalidad::Presencial,
): Usuario {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create($datos);

    asesDarMateria($asesor, asesMateria($materia));
    asesDarHorario($asesor, $dia, asesHorario($horario));
    asesDarModalidad($asesor, $modalidad);

    return $asesor;
}

// Pide la lista con el token dado y devuelve solo los ids, en el orden recibido
function asesIds(string $token, string $query = ''): array
{
    $respuesta = test()->withToken($token)->getJson(URL_ASESORES.$query)->assertOk();

    return collect($respuesta->json('data'))->pluck('id')->all();
}

// Próxima fecha (siempre futura) que cae en el día de la semana pedido, como "2026-10-26"
function asesFecha(int $diaSemana): string
{
    return Carbon::now()->next($diaSemana)->toDateString();
}


// ---------- Acceso ----------

it('asesores devuelve 401 sin sesión', function () {
    $this->getJson(URL_ASESORES)->assertUnauthorized();
});

it('asesores rechaza con 403 a un asesor puro', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();

    $this->withToken(asesToken($asesor))
        ->getJson(URL_ASESORES)
        ->assertForbidden();
});

it('asesores bloquea con 403 a un estudiante con cuenta no activa', function () {
    $estudiante = Usuario::factory()->pendiente()->conRol(Rol::Estudiante)->create(['password' => 'secreto123']);

    $this->withToken(asesToken($estudiante))
        ->getJson(URL_ASESORES)
        ->assertForbidden();
});


// ---------- Contenido ----------

it('asesores lista a un asesor con su disponibilidad completa', function () {
    $asesor = asesAsesor(
        ['nombre' => 'Marco Antonio', 'apellido_paterno' => 'Valdez', 'apellido_materno' => 'Ríos'],
        'Estructura de Datos',
        Dia::Martes,
        '11:00-12:00 PM',
        Modalidad::Virtual,
    );

    $this->withToken(asesToken(asesEstudiante()))
        ->getJson(URL_ASESORES)
        ->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $asesor->id)
        ->assertJsonPath('data.0.nombre', 'Marco Antonio')
        ->assertJsonPath('data.0.apellido_paterno', 'Valdez')
        ->assertJsonPath('data.0.correo', $asesor->correo)
        ->assertJsonPath('data.0.disponibilidad.materias.0.nombre', 'Estructura de Datos')
        ->assertJsonPath('data.0.disponibilidad.modalidades.0.valor', 'virtual')
        // Los 5 días siempre salen: martes es el segundo
        ->assertJsonCount(5, 'data.0.disponibilidad.horarios')
        ->assertJsonPath('data.0.disponibilidad.horarios.1.dia', 'martes')
        ->assertJsonPath('data.0.disponibilidad.horarios.1.horarios.0.etiqueta', '11:00-12:00 PM')
        ->assertJsonPath('data.0.disponibilidad.horarios.0.horarios', []);
});


// ---------- Quién es visible ----------

it('asesores no incluye al propio usuario (asesor par) pero sí a los demás', function () {
    $otro = asesAsesor();

    // Asesor par: estudiante + asesor, con disponibilidad completa
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    asesDarMateria($par, asesMateria('Estructura de Datos'));
    asesDarHorario($par, Dia::Lunes, asesHorario('7:00-8:00 AM'));
    asesDarModalidad($par, Modalidad::Virtual);

    expect(asesIds(asesToken($par)))->toBe([$otro->id]);
});

it('asesores no incluye asesores de otra facultad', function () {
    $mio = asesAsesor();

    $otra = Facultad::forceCreate(['nombre' => 'Otra Facultad', 'siglas' => 'OTR', 'activa' => true]);
    asesAsesor(['facultad_id' => $otra->id]);

    expect(asesIds(asesToken(asesEstudiante())))->toBe([$mio->id]);
});

it('asesores no incluye cuentas que no están activas', function () {
    $activo = asesAsesor();
    asesAsesor(['estatus' => EstatusUsuario::Inactivo]);
    asesAsesor(['estatus' => EstatusUsuario::Pendiente]);

    expect(asesIds(asesToken(asesEstudiante())))->toBe([$activo->id]);
});

it('asesores no incluye a un asesor con la disponibilidad incompleta', function () {
    $completo = asesAsesor();

    // Solo materia y modalidad: le faltan horarios
    $incompleto = Usuario::factory()->conRol(Rol::Asesor)->create();
    asesDarMateria($incompleto, asesMateria('Estructura de Datos'));
    asesDarModalidad($incompleto, Modalidad::Presencial);

    expect(asesIds(asesToken(asesEstudiante())))->toBe([$completo->id]);
});

it('asesores no incluye a quien tiene disponibilidad pero no es asesor', function () {
    $asesor = asesAsesor();

    $soloEstudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();
    asesDarMateria($soloEstudiante, asesMateria('Estructura de Datos'));
    asesDarHorario($soloEstudiante, Dia::Lunes, asesHorario('7:00-8:00 AM'));
    asesDarModalidad($soloEstudiante, Modalidad::Presencial);

    expect(asesIds(asesToken(asesEstudiante())))->toBe([$asesor->id]);
});


// ---------- Filtros ----------

it('asesores filtra por nombre y apellidos con la búsqueda', function () {
    $marco = asesAsesor(['nombre' => 'Marco Antonio', 'apellido_paterno' => 'Valdez', 'apellido_materno' => 'Ríos']);
    $jenifer = asesAsesor(['nombre' => 'Jenifer Guadalupe', 'apellido_paterno' => 'Tizoc', 'apellido_materno' => 'Lopez']);
    $token = asesToken(asesEstudiante());

    expect(asesIds($token, '?buscar=marco'))->toBe([$marco->id]);
    // El nombre completo junto, aunque cruce columnas
    expect(asesIds($token, '?buscar='.urlencode('Antonio Valdez')))->toBe([$marco->id]);
    expect(asesIds($token, '?buscar=tizoc'))->toBe([$jenifer->id]);
    expect(asesIds($token, '?buscar=zzzz'))->toBe([]);
});

it('asesores filtra por materia', function () {
    $algebra = asesAsesor([], 'Álgebra');
    $calculo = asesAsesor([], 'Cálculo');
    $token = asesToken(asesEstudiante());

    expect(asesIds($token, '?materia_id='.asesMateria('Álgebra')->id))->toBe([$algebra->id]);
    expect(asesIds($token, '?materia_id='.asesMateria('Cálculo')->id))->toBe([$calculo->id]);
    expect(asesIds($token, '?materia_id=999999'))->toBe([]);
});

it('asesores filtra por modalidad', function () {
    $presencial = asesAsesor([], 'Estructura de Datos', Dia::Lunes, '7:00-8:00 AM', Modalidad::Presencial);
    $virtual = asesAsesor([], 'Estructura de Datos', Dia::Lunes, '7:00-8:00 AM', Modalidad::Virtual);
    $token = asesToken(asesEstudiante());

    expect(asesIds($token, '?modalidad=presencial'))->toBe([$presencial->id]);
    expect(asesIds($token, '?modalidad=virtual'))->toBe([$virtual->id]);
});

it('asesores filtra por horario en cualquier día', function () {
    $temprano = asesAsesor([], 'Estructura de Datos', Dia::Lunes, '7:00-8:00 AM');
    $tarde = asesAsesor([], 'Estructura de Datos', Dia::Viernes, '6:00-7:00 PM');
    $token = asesToken(asesEstudiante());

    expect(asesIds($token, '?horario_id='.asesHorario('7:00-8:00 AM')->id))->toBe([$temprano->id]);
    expect(asesIds($token, '?horario_id='.asesHorario('6:00-7:00 PM')->id))->toBe([$tarde->id]);
});

it('asesores filtra por fecha: solo quien ofrece ese día de la semana', function () {
    $delLunes = asesAsesor([], 'Estructura de Datos', Dia::Lunes);
    $delMiercoles = asesAsesor([], 'Estructura de Datos', Dia::Miercoles);
    $token = asesToken(asesEstudiante());

    expect(asesIds($token, '?fecha='.asesFecha(Carbon::MONDAY)))->toBe([$delLunes->id]);
    expect(asesIds($token, '?fecha='.asesFecha(Carbon::WEDNESDAY)))->toBe([$delMiercoles->id]);
    // Martes: nadie ofrece ese día
    expect(asesIds($token, '?fecha='.asesFecha(Carbon::TUESDAY)))->toBe([]);
});

it('asesores devuelve lista vacía si la fecha cae en fin de semana', function () {
    asesAsesor([], 'Estructura de Datos', Dia::Lunes);
    $token = asesToken(asesEstudiante());

    expect(asesIds($token, '?fecha='.asesFecha(Carbon::SATURDAY)))->toBe([]);
    expect(asesIds($token, '?fecha='.asesFecha(Carbon::SUNDAY)))->toBe([]);
});

it('asesores exige que fecha y horario coincidan en la misma fila', function () {
    // Lunes 7:00 y miércoles 9:00. NO ofrece lunes 9:00
    $asesor = asesAsesor([], 'Estructura de Datos', Dia::Lunes, '7:00-8:00 AM');
    asesDarHorario($asesor, Dia::Miercoles, asesHorario('9:00-10:00 AM'));
    $token = asesToken(asesEstudiante());

    $siete = asesHorario('7:00-8:00 AM')->id;
    $nueve = asesHorario('9:00-10:00 AM')->id;
    $lunes = asesFecha(Carbon::MONDAY);
    $miercoles = asesFecha(Carbon::WEDNESDAY);

    // Casos felices: la pareja existe
    expect(asesIds($token, "?fecha={$lunes}&horario_id={$siete}"))->toBe([$asesor->id]);
    expect(asesIds($token, "?fecha={$miercoles}&horario_id={$nueve}"))->toBe([$asesor->id]);
    // Rechazos: cada dato existe por separado, pero no juntos
    expect(asesIds($token, "?fecha={$lunes}&horario_id={$nueve}"))->toBe([]);
    expect(asesIds($token, "?fecha={$miercoles}&horario_id={$siete}"))->toBe([]);
});

it('asesores combina todos los filtros a la vez', function () {
    $lunes = asesFecha(Carbon::MONDAY);
    $horario = asesHorario('7:00-8:00 AM')->id;
    $materia = asesMateria('Estructura de Datos')->id;

    $buscado = asesAsesor(['nombre' => 'Marco'], 'Estructura de Datos', Dia::Lunes, '7:00-8:00 AM', Modalidad::Virtual);
    // Mismo todo menos la modalidad
    asesAsesor(['nombre' => 'Marco'], 'Estructura de Datos', Dia::Lunes, '7:00-8:00 AM', Modalidad::Presencial);
    // Mismo todo menos el nombre
    asesAsesor(['nombre' => 'Jenifer'], 'Estructura de Datos', Dia::Lunes, '7:00-8:00 AM', Modalidad::Virtual);

    $query = "?buscar=marco&materia_id={$materia}&modalidad=virtual&fecha={$lunes}&horario_id={$horario}";

    expect(asesIds(asesToken(asesEstudiante()), $query))->toBe([$buscado->id]);
});


// ---------- Validación de los filtros ----------

it('asesores rechaza con 422 filtros con formato inválido', function () {
    $token = asesToken(asesEstudiante());

    foreach ([
        '?modalidad=hibrida',
        '?fecha=26/10/2026',
        '?fecha='.now()->subDay()->toDateString(),
        '?horario_id=999999',
        '?materia_id=abc',
    ] as $query) {
        $this->withToken($token)->getJson(URL_ASESORES.$query)->assertUnprocessable();
    }
});


// ---------- Orden y paginación ----------

it('asesores ordena por apellido paterno', function () {
    // Se crean en orden inverso a propósito
    $zamora = asesAsesor(['apellido_paterno' => 'Zamora']);
    $beltran = asesAsesor(['apellido_paterno' => 'Beltran']);
    $aguilar = asesAsesor(['apellido_paterno' => 'Aguilar']);

    expect(asesIds(asesToken(asesEstudiante())))->toBe([$aguilar->id, $beltran->id, $zamora->id]);
});

it('asesores pagina de 10 en 10', function () {
    foreach (range(1, 12) as $i) {
        asesAsesor();
    }

    $token = asesToken(asesEstudiante());

    $this->withToken($token)->getJson(URL_ASESORES)
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 12)
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('meta.last_page', 2);

    // La segunda página trae los 2 que faltan
    $this->withToken($token)->getJson(URL_ASESORES.'?page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.current_page', 2);
});
