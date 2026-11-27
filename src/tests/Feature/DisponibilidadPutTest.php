<?php

use App\Enums\Dia;
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

const URL_DISP_PUT = '/api/v1/asesor/disponibilidad';


// ---------- Ayudantes (prefijo "dput") ----------
// Este archivo no depende de DisponibilidadTest.php: tiene sus propias funciones y su propia constante,
// con otro prefijo para no chocar (las funciones de los archivos de prueba son globales).

function dputToken(Usuario $u): string
{
    return $u->createToken('prueba')->plainTextToken;
}

function dputMateria(string $nombre): Materia
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

function dputHorario(string $etiqueta): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Un cuerpo válido de PUT. Con $cambios se sustituye alguna clave (p. ej. 'materias' => [9999]).
function dputCuerpo(array $cambios = []): array
{
    return array_merge([
        'materias' => [dputMateria('Materia uno')->id],
        'horarios' => [['dia' => 'lunes', 'horario_id' => dputHorario('7:00-8:00 AM')->id]],
        'modalidades' => ['virtual'],
    ], $cambios);
}

// Cuántas filas de disponibilidad tiene un asesor: [materias, horarios, modalidades]
function dputConteos(Usuario $asesor): array
{
    return [
        DisponibilidadMateria::where('asesor_id', $asesor->id)->count(),
        DisponibilidadHorario::where('asesor_id', $asesor->id)->count(),
        DisponibilidadModalidad::where('asesor_id', $asesor->id)->count(),
    ];
}


// ---------- Acceso ----------

it('PUT disponibilidad devuelve 401 sin sesión', function () {
    $this->putJson(URL_DISP_PUT, dputCuerpo())->assertUnauthorized();
});

it('PUT disponibilidad rechaza con 403 a un estudiante puro', function () {
    $estudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();

    $this->withToken(dputToken($estudiante))
        ->putJson(URL_DISP_PUT, dputCuerpo())
        ->assertForbidden();

    // Y no se guardó nada
    expect(dputConteos($estudiante))->toBe([0, 0, 0]);
});

it('PUT disponibilidad bloquea con 403 a un asesor con cuenta no activa', function () {
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);

    $this->withToken(dputToken($asesor))
        ->putJson(URL_DISP_PUT, dputCuerpo())
        ->assertForbidden();

    expect(dputConteos($asesor))->toBe([0, 0, 0]);
});


// ---------- Caso feliz ----------

it('PUT guarda la disponibilidad y la responde con el mismo formato que el GET', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();
    $token = dputToken($asesor);
    $cuerpo = dputCuerpo();

    $this->withToken($token)
        ->putJson(URL_DISP_PUT, $cuerpo)
        ->assertOk()
        ->assertJsonCount(1, 'data.materias')
        ->assertJsonPath('data.materias.0.nombre', 'Materia uno')
        ->assertJsonCount(5, 'data.horarios')
        ->assertJsonPath('data.horarios.0.dia', 'lunes')
        ->assertJsonPath('data.horarios.0.horarios.0.etiqueta', '7:00-8:00 AM')
        ->assertJsonPath('data.modalidades.0.valor', 'virtual');

    // Quedó en la base, con la facultad del asesor
    $this->assertDatabaseHas('disponibilidad_materias', [
        'asesor_id' => $asesor->id,
        'materia_id' => $cuerpo['materias'][0],
        'facultad_id' => $asesor->facultad_id,
    ]);
    $this->assertDatabaseHas('disponibilidad_horarios', [
        'asesor_id' => $asesor->id,
        'dia' => 'lunes',
        'horario_id' => $cuerpo['horarios'][0]['horario_id'],
    ]);
    $this->assertDatabaseHas('disponibilidad_modalidades', [
        'asesor_id' => $asesor->id,
        'modalidad' => 'virtual',
    ]);

    // Y un GET posterior devuelve lo mismo
    $this->withToken($token)
        ->getJson(URL_DISP_PUT)
        ->assertOk()
        ->assertJsonPath('data.materias.0.nombre', 'Materia uno')
        ->assertJsonPath('data.horarios.0.horarios.0.etiqueta', '7:00-8:00 AM')
        ->assertJsonPath('data.modalidades.0.valor', 'virtual');
});

it('un segundo PUT reemplaza al primero sin acumular filas', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();
    $token = dputToken($asesor);
    $primero = dputCuerpo();

    $this->withToken($token)->putJson(URL_DISP_PUT, $primero)->assertOk();

    $materiaDos = dputMateria('Materia dos');
    $horarioDos = dputHorario('8:00-9:00 AM');

    $this->withToken($token)->putJson(URL_DISP_PUT, [
        'materias' => [$materiaDos->id],
        'horarios' => [['dia' => 'martes', 'horario_id' => $horarioDos->id]],
        'modalidades' => ['presencial'],
    ])->assertOk();

    // Una sola fila de cada tipo: no se acumularon las del primer PUT
    expect(dputConteos($asesor))->toBe([1, 1, 1]);

    $this->assertDatabaseHas('disponibilidad_materias', ['asesor_id' => $asesor->id, 'materia_id' => $materiaDos->id]);
    $this->assertDatabaseMissing('disponibilidad_materias', ['asesor_id' => $asesor->id, 'materia_id' => $primero['materias'][0]]);
    $this->assertDatabaseHas('disponibilidad_horarios', ['asesor_id' => $asesor->id, 'dia' => 'martes']);
    $this->assertDatabaseMissing('disponibilidad_horarios', ['asesor_id' => $asesor->id, 'dia' => 'lunes']);
    $this->assertDatabaseHas('disponibilidad_modalidades', ['asesor_id' => $asesor->id, 'modalidad' => 'presencial']);
    $this->assertDatabaseMissing('disponibilidad_modalidades', ['asesor_id' => $asesor->id, 'modalidad' => 'virtual']);
});

it('el asesor par puede guardar su disponibilidad', function () {
    $par = Usuario::factory()->conRol(Rol::Asesor, Rol::Estudiante)->create();

    $this->withToken(dputToken($par))
        ->putJson(URL_DISP_PUT, dputCuerpo())
        ->assertOk();

    expect(dputConteos($par))->toBe([1, 1, 1]);
});


// ---------- Rechazos (422). Su caso feliz son las pruebas de arriba ----------

it('rechaza con 422 si falta una de las tres claves', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();
    $token = dputToken($asesor);

    foreach (['materias', 'horarios', 'modalidades'] as $clave) {
        $cuerpo = dputCuerpo();
        unset($cuerpo[$clave]);

        $this->withToken($token)
            ->putJson(URL_DISP_PUT, $cuerpo)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($clave);
    }

    expect(dputConteos($asesor))->toBe([0, 0, 0]);
});

it('rechaza con 422 una lista vacía (mínimo 1 en cada una)', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();
    $token = dputToken($asesor);

    foreach (['materias', 'horarios', 'modalidades'] as $clave) {
        $this->withToken($token)
            ->putJson(URL_DISP_PUT, dputCuerpo([$clave => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($clave);
    }

    expect(dputConteos($asesor))->toBe([0, 0, 0]);
});

it('rechaza con 422 un día que no es válido', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();
    $token = dputToken($asesor);
    $horario = dputHorario('7:00-8:00 AM');

    // "Lunes" con mayúscula, "sabado" y un texto cualquiera
    foreach (['Lunes', 'sabado', 'xyz'] as $dia) {
        $this->withToken($token)
            ->putJson(URL_DISP_PUT, dputCuerpo(['horarios' => [['dia' => $dia, 'horario_id' => $horario->id]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('horarios.0.dia');
    }
});

it('rechaza con 422 un horario que no existe', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();

    $this->withToken(dputToken($asesor))
        ->putJson(URL_DISP_PUT, dputCuerpo(['horarios' => [['dia' => 'lunes', 'horario_id' => 9999]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('horarios.0.horario_id');
});

it('rechaza con 422 una modalidad que no es válida', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();

    $this->withToken(dputToken($asesor))
        ->putJson(URL_DISP_PUT, dputCuerpo(['modalidades' => ['hibrida']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('modalidades.0');
});

it('rechaza con 422 una materia que no existe', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();

    $this->withToken(dputToken($asesor))
        ->putJson(URL_DISP_PUT, dputCuerpo(['materias' => [9999]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('materias');
});

it('rechaza con 422 una materia de otra facultad', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();

    // Una materia que sí existe, pero en una licenciatura de OTRA facultad
    $otra = Facultad::forceCreate(['nombre' => 'Otra Facultad', 'siglas' => 'OTR', 'activa' => true]);
    $licAjena = Licenciatura::create(['facultad_id' => $otra->id, 'nombre' => 'LICENCIATURA AJENA']);
    $materiaAjena = Materia::create(['licenciatura_id' => $licAjena->id, 'nombre' => 'Materia ajena', 'semestre' => 1]);

    $this->withToken(dputToken($asesor))
        ->putJson(URL_DISP_PUT, dputCuerpo(['materias' => [$materiaAjena->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('materias');

    expect(dputConteos($asesor))->toBe([0, 0, 0]);
});

it('rechaza con 422 la misma pareja día + horario repetida', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();
    $horario = dputHorario('7:00-8:00 AM');

    $this->withToken(dputToken($asesor))
        ->putJson(URL_DISP_PUT, dputCuerpo(['horarios' => [
            ['dia' => 'lunes', 'horario_id' => $horario->id],
            ['dia' => 'lunes', 'horario_id' => $horario->id],
        ]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('horarios');
});

it('acepta el mismo día con horarios distintos (no es un repetido)', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();
    $uno = dputHorario('7:00-8:00 AM');
    $dos = dputHorario('8:00-9:00 AM');

    $this->withToken(dputToken($asesor))
        ->putJson(URL_DISP_PUT, dputCuerpo(['horarios' => [
            ['dia' => 'lunes', 'horario_id' => $uno->id],
            ['dia' => 'lunes', 'horario_id' => $dos->id],
        ]]))
        ->assertOk()
        ->assertJsonCount(2, 'data.horarios.0.horarios');
});


// ---------- Un PUT inválido no destruye lo anterior ----------

it('un PUT inválido no borra lo que el asesor ya tenía guardado', function () {
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();
    $token = dputToken($asesor);
    $valido = dputCuerpo();

    $this->withToken($token)->putJson(URL_DISP_PUT, $valido)->assertOk();
    expect(dputConteos($asesor))->toBe([1, 1, 1]);

    // Segundo PUT con un día inválido: debe fallar ANTES de tocar nada
    $invalido = $valido;
    $invalido['horarios'][0]['dia'] = 'sabado';

    $this->withToken($token)->putJson(URL_DISP_PUT, $invalido)->assertUnprocessable();

    // Lo anterior sigue intacto
    expect(dputConteos($asesor))->toBe([1, 1, 1]);
    $this->assertDatabaseHas('disponibilidad_materias', [
        'asesor_id' => $asesor->id,
        'materia_id' => $valido['materias'][0],
    ]);
});


// ---------- Aislamiento entre asesores ----------

it('el PUT de un asesor no toca la disponibilidad de otro', function () {
    $yo = Usuario::factory()->conRol(Rol::Asesor)->create();
    $otro = Usuario::factory()->conRol(Rol::Asesor)->create();

    // El otro ya tiene su disponibilidad
    $materiaDelOtro = dputMateria('Materia del otro');
    $horarioDelOtro = dputHorario('9:00-10:00 AM');

    DisponibilidadMateria::create([
        'facultad_id' => $otro->facultad_id, 'asesor_id' => $otro->id, 'materia_id' => $materiaDelOtro->id,
    ]);
    DisponibilidadHorario::create([
        'facultad_id' => $otro->facultad_id, 'asesor_id' => $otro->id, 'dia' => Dia::Viernes, 'horario_id' => $horarioDelOtro->id,
    ]);
    DisponibilidadModalidad::create([
        'facultad_id' => $otro->facultad_id, 'asesor_id' => $otro->id, 'modalidad' => Modalidad::Presencial,
    ]);

    // Yo guardo la mía
    $this->withToken(dputToken($yo))
        ->putJson(URL_DISP_PUT, dputCuerpo())
        ->assertOk()
        // Mi respuesta solo trae lo mío
        ->assertJsonMissing(['nombre' => 'Materia del otro']);

    // Lo del otro sigue exactamente igual
    expect(dputConteos($otro))->toBe([1, 1, 1]);
    $this->assertDatabaseHas('disponibilidad_materias', ['asesor_id' => $otro->id, 'materia_id' => $materiaDelOtro->id]);
    $this->assertDatabaseHas('disponibilidad_horarios', ['asesor_id' => $otro->id, 'dia' => 'viernes']);
    $this->assertDatabaseHas('disponibilidad_modalidades', ['asesor_id' => $otro->id, 'modalidad' => 'presencial']);

    // Y lo mío quedó en mi nombre
    expect(dputConteos($yo))->toBe([1, 1, 1]);
});
