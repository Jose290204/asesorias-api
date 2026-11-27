<?php

use App\Enums\EstatusAsesoria;
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
use App\Models\Usuario;
use App\Support\HorasDeHorario;
use Database\Factories\UsuarioFactory;

// ---------- Ayudantes ----------

function calAsesoria(Usuario $estudiante, Usuario $asesor, array $datos = [], string $horario = '1:00-2:00 PM', ?string $finCiclo = null): Asesoria
{
    $licenciatura = Licenciatura::firstOrCreate([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => 'LICENCIATURA PRUEBA',
    ]);
    $grupo = Grupo::firstOrCreate(['licenciatura_id' => $licenciatura->id, 'nombre' => '3-1'], ['anio' => 3]);
    $materia = Materia::firstOrCreate(
        ['licenciatura_id' => $licenciatura->id, 'nombre' => 'Estructura de Datos'],
        ['semestre' => 1]
    );
    $ciclo = CicloEscolar::create([
        'facultad_id' => $estudiante->facultad_id,
        'nombre' => 'Ciclo '.uniqid(),
        'periodo' => 1,
        'fecha_inicio' => '2026-08-01',
        'fecha_fin' => $finCiclo ?? '2027-06-30',
        'vigente' => true,
    ]);

    return Asesoria::create(array_merge([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => $ciclo->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => Horario::firstOrCreate(['etiqueta' => $horario])->id,
        'fecha_inicio' => '2026-11-18', // miércoles
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => EstatusAsesoria::EnCurso,
    ], $datos));
}

// ---------- Pruebas ----------

it('convierte la etiqueta del horario a horas de 24 h', function () {
    expect(HorasDeHorario::desde('7:00-8:00 AM'))->toBe(['07:00', '08:00'])
        ->and(HorasDeHorario::desde('11:00-12:00 PM'))->toBe(['11:00', '12:00'])
        ->and(HorasDeHorario::desde('12:00-1:00 PM'))->toBe(['12:00', '13:00'])
        ->and(HorasDeHorario::desde('1:00-2:00 PM'))->toBe(['13:00', '14:00'])
        ->and(HorasDeHorario::desde('11:00-12:00 AM'))->toBe(['23:00', '00:00']);
});

it('calendario devuelve 401 sin sesión', function () {
    $this->getJson('/api/v1/estudiante/calendario')->assertUnauthorized();
});

it('el estudiante ve cada semana de su asesoría en curso dentro del rango', function () {
    $estudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();
    $asesoria = calAsesoria($estudiante, $asesor);

    // Se registró la sesión del primer miércoles del rango
    Sesion::create([
        'asesoria_id' => $asesoria->id,
        'fecha' => '2026-12-02',
        'horario_id' => $asesoria->horario_id,
        'registrada_por' => $asesor->id,
    ]);

    $respuesta = $this->withToken($estudiante->createToken('prueba')->plainTextToken)
        ->getJson('/api/v1/estudiante/calendario?desde=2026-12-01&hasta=2026-12-14')
        ->assertOk();

    expect($respuesta->json('data'))->toHaveCount(2);
    expect($respuesta->json('data.0'))->toMatchArray([
        'asesoria_id' => $asesoria->id,
        'fecha' => '2026-12-02',
        'hora_inicio' => '13:00',
        'hora_fin' => '14:00',
        'materia' => 'Estructura de Datos',
        'con' => $asesor->nombreCompleto(),
        'registrada' => true,
    ]);
    expect($respuesta->json('data.1.fecha'))->toBe('2026-12-09');
    expect($respuesta->json('data.1.registrada'))->toBeFalse();
});

it('el asesor ve a su estudiante en "con"', function () {
    $estudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();
    calAsesoria($estudiante, $asesor);

    $this->withToken($asesor->createToken('prueba')->plainTextToken)
        ->getJson('/api/v1/asesor/calendario?desde=2026-12-01&hasta=2026-12-07')
        ->assertOk()
        ->assertJsonPath('data.0.con', $estudiante->nombreCompleto());
});

it('no incluye asesorías completadas ni días después del fin del ciclo', function () {
    $estudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();

    calAsesoria($estudiante, $asesor, ['estatus' => EstatusAsesoria::Completada]);
    calAsesoria($estudiante, $asesor, [], '8:00-9:00 AM', '2026-12-05'); // el ciclo acaba el sábado 5

    $respuesta = $this->withToken($estudiante->createToken('prueba')->plainTextToken)
        ->getJson('/api/v1/estudiante/calendario?desde=2026-12-01&hasta=2026-12-31')
        ->assertOk();

    // Solo el miércoles 2 de la asesoría en curso: la completada no sale y el ciclo corta el resto
    expect($respuesta->json('data'))->toHaveCount(1);
    expect($respuesta->json('data.0.fecha'))->toBe('2026-12-02');
});

it('valida que el rango sea coherente', function () {
    $estudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();

    $this->withToken($estudiante->createToken('prueba')->plainTextToken)
        ->getJson('/api/v1/estudiante/calendario?desde=2026-12-10&hasta=2026-12-01')
        ->assertStatus(422);
});
