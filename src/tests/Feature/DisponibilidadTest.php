<?php

use App\Enums\Dia;
use App\Enums\Modalidad;
use App\Enums\Rol;
use App\Models\DisponibilidadHorario;
use App\Models\DisponibilidadMateria;
use App\Models\DisponibilidadModalidad;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;

const URL_DISPONIBILIDAD = '/api/v1/asesor/disponibilidad';


// ---------- Ayudantes (prefijo "disp") ----------
// Las funciones de los archivos de prueba son globales: el prefijo evita choques con los de otros archivos.

function dispToken(Usuario $u): string
{
    return $u->createToken('prueba')->plainTextToken;
}

// Crea (o reutiliza) una materia en una licenciatura de prueba de la facultad FIC
function dispMateria(string $nombre): Materia
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

// Crea (o reutiliza) un horario. Se crean en orden: el que se crea primero tiene el id menor.
function dispHorario(string $etiqueta): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Las tres funciones siguientes dan de alta una fila de disponibilidad.
// facultad_id va explícito: en una prueba no hay usuario logueado al preparar datos,
// así que el trait PerteneceAFacultad no lo rellena solo.
function dispDarMateria(Usuario $asesor, Materia $materia): void
{
    DisponibilidadMateria::create([
        'facultad_id' => $asesor->facultad_id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
    ]);
}

function dispDarHorario(Usuario $asesor, Dia $dia, Horario $horario): void
{
    DisponibilidadHorario::create([
        'facultad_id' => $asesor->facultad_id,
        'asesor_id' => $asesor->id,
        'dia' => $dia,
        'horario_id' => $horario->id,
    ]);
}

function dispDarModalidad(Usuario $asesor, Modalidad $modalidad): void
{
    DisponibilidadModalidad::create([
        'facultad_id' => $asesor->facultad_id,
        'asesor_id' => $asesor->id,
        'modalidad' => $modalidad,
    ]);
}


// ---------- Acceso ----------

it('disponibilidad devuelve 401 sin sesión', function () {
    $this->getJson(URL_DISPONIBILIDAD)->assertUnauthorized();
});

it('disponibilidad rechaza con 403 a un estudiante puro', function () {
    $estudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();

    $this->withToken(dispToken($estudiante))
        ->getJson(URL_DISPONIBILIDAD)
        ->assertForbidden();
});

it('disponibilidad bloquea con 403 a un asesor con cuenta no activa', function () {
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);

    $this->withToken(dispToken($asesor))
        ->getJson(URL_DISPONIBILIDAD)
        ->assertForbidden();
});


// ---------- Contenido ----------

it('un asesor sin datos recibe listas vacías y los 5 días presentes', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();

    $respuesta = $this->withToken(dispToken($asesor))
        ->getJson(URL_DISPONIBILIDAD)
        ->assertOk()
        ->assertJsonPath('data.materias', [])
        ->assertJsonPath('data.modalidades', [])
        ->assertJsonCount(5, 'data.horarios');

    $dias = collect($respuesta->json('data.horarios'));

    // Siempre salen los 5 días, de lunes a viernes, aunque no tengan horarios
    expect($dias->pluck('dia')->all())->toBe(['lunes', 'martes', 'miercoles', 'jueves', 'viernes']);
    expect($dias->every(fn ($d) => $d['horarios'] === []))->toBeTrue();
    // La etiqueta lleva acento; el valor no
    expect($dias[2]['etiqueta'])->toBe('Miércoles');
});

it('un asesor con datos recibe sus materias, horarios y modalidades ordenados', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();

    $zeta = dispMateria('Zeta Cálculo');
    $alfa = dispMateria('Alfa Álgebra');
    $temprano = dispHorario('7:00-8:00 AM');   // id menor
    $tarde = dispHorario('8:00-9:00 AM');      // id mayor

    // Se insertan a propósito en orden inverso para comprobar que la respuesta los ordena
    dispDarMateria($asesor, $zeta);
    dispDarMateria($asesor, $alfa);
    dispDarHorario($asesor, Dia::Lunes, $tarde);
    dispDarHorario($asesor, Dia::Lunes, $temprano);
    dispDarHorario($asesor, Dia::Miercoles, $temprano);
    dispDarModalidad($asesor, Modalidad::Virtual);

    $this->withToken(dispToken($asesor))
        ->getJson(URL_DISPONIBILIDAD)
        ->assertOk()
        // Materias ordenadas por nombre y con su licenciatura
        ->assertJsonCount(2, 'data.materias')
        ->assertJsonPath('data.materias.0.nombre', 'Alfa Álgebra')
        ->assertJsonPath('data.materias.0.licenciatura', 'LICENCIATURA PRUEBA')
        ->assertJsonPath('data.materias.1.nombre', 'Zeta Cálculo')
        // Lunes: dos horarios, el más temprano primero
        ->assertJsonPath('data.horarios.0.dia', 'lunes')
        ->assertJsonCount(2, 'data.horarios.0.horarios')
        ->assertJsonPath('data.horarios.0.horarios.0.etiqueta', '7:00-8:00 AM')
        ->assertJsonPath('data.horarios.0.horarios.0.id', $temprano->id)
        ->assertJsonPath('data.horarios.0.horarios.1.etiqueta', '8:00-9:00 AM')
        // Martes: sin horarios
        ->assertJsonPath('data.horarios.1.dia', 'martes')
        ->assertJsonPath('data.horarios.1.horarios', [])
        // Miércoles: uno
        ->assertJsonPath('data.horarios.2.dia', 'miercoles')
        ->assertJsonCount(1, 'data.horarios.2.horarios')
        // Modalidad como {valor, etiqueta}
        ->assertJsonCount(1, 'data.modalidades')
        ->assertJsonPath('data.modalidades.0.valor', 'virtual')
        ->assertJsonPath('data.modalidades.0.etiqueta', 'Virtual');
});


// ---------- Aislamiento entre asesores ----------

it('cada asesor ve solo su disponibilidad y nunca la de otro', function () {
    $yo = Usuario::factory()->conRol(Rol::Asesor)->create();
    $otro = Usuario::factory()->conRol(Rol::Asesor)->create();

    $horario = dispHorario('7:00-8:00 AM');

    dispDarMateria($yo, dispMateria('Materia mía'));
    dispDarHorario($yo, Dia::Lunes, $horario);
    dispDarModalidad($yo, Modalidad::Presencial);

    dispDarMateria($otro, dispMateria('Materia del otro'));
    dispDarHorario($otro, Dia::Viernes, $horario);
    dispDarModalidad($otro, Modalidad::Virtual);

    // Yo: solo lo mío
    $this->withToken(dispToken($yo))
        ->getJson(URL_DISPONIBILIDAD)
        ->assertOk()
        ->assertJsonCount(1, 'data.materias')
        ->assertJsonPath('data.materias.0.nombre', 'Materia mía')
        ->assertJsonMissing(['nombre' => 'Materia del otro'])
        ->assertJsonCount(1, 'data.horarios.0.horarios')   // lunes
        ->assertJsonPath('data.horarios.4.horarios', [])   // viernes es del otro
        ->assertJsonPath('data.modalidades.0.valor', 'presencial');

    // El otro (caso feliz inverso): solo lo suyo
    // En una prueba la app es la misma entre peticiones y Sanctum recuerda al último usuario.
    // forgetGuards() lo hace olvidar, para que esta petición use el token del otro asesor.
    $this->app['auth']->forgetGuards();

    $this->withToken(dispToken($otro))
        ->getJson(URL_DISPONIBILIDAD)
        ->assertOk()
        ->assertJsonCount(1, 'data.materias')
        ->assertJsonPath('data.materias.0.nombre', 'Materia del otro')
        ->assertJsonMissing(['nombre' => 'Materia mía'])
        ->assertJsonPath('data.horarios.0.horarios', [])   // lunes es mío
        ->assertJsonCount(1, 'data.horarios.4.horarios')   // viernes
        ->assertJsonPath('data.modalidades.0.valor', 'virtual');
});


// ---------- Asesor par (roles 3 y 4) ----------

it('el asesor par entra a su disponibilidad y ve lo suyo', function () {
    $par = Usuario::factory()->conRol(Rol::Asesor, Rol::Estudiante)->create();

    dispDarMateria($par, dispMateria('Materia del par'));

    $this->withToken(dispToken($par))
        ->getJson(URL_DISPONIBILIDAD)
        ->assertOk()
        ->assertJsonCount(1, 'data.materias')
        ->assertJsonPath('data.materias.0.nombre', 'Materia del par');
});
