<?php

use App\Enums\Rol;
use App\Exceptions\ErrorDeNegocio;
use App\Models\DiaInhabil;
use App\Models\Usuario;
use App\Services\DiasHabilesService;
use Illuminate\Support\Carbon;

// Usa calAsesoria() (CalendarioTest.php) y recPareja()/recCorrer() (RecordatorioSesionTest.php).
// Fecha de prueba: miércoles 2026-12-02; la asesoría se repite los miércoles a la 1:00 PM.

function inhabil(string $inicio, ?string $fin = null, ?int $facultadId = null, string $motivo = 'Prueba'): DiaInhabil
{
    return DiaInhabil::create([
        'facultad_id' => $facultadId,
        'fecha_inicio' => $inicio,
        'fecha_fin' => $fin ?? $inicio,
        'tipo' => DiaInhabil::VACACIONES,
        'motivo' => $motivo,
    ]);
}

it('el calendario salta los días inhábiles y los devuelve para marcarlos', function () {
    $estudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();
    calAsesoria($estudiante, $asesor);

    inhabil('2026-12-07', '2026-12-11', null, 'Vacaciones de prueba'); // la semana del miércoles 9

    $respuesta = $this->withToken($estudiante->createToken('prueba')->plainTextToken)
        ->getJson('/api/v1/estudiante/calendario?desde=2026-12-01&hasta=2026-12-16')
        ->assertOk();

    // Salen el 2 y el 16; el 9 cae en vacaciones y se salta
    expect(collect($respuesta->json('data'))->pluck('fecha')->all())->toBe(['2026-12-02', '2026-12-16']);
    expect($respuesta->json('inhabiles.0'))->toMatchArray([
        'fecha_inicio' => '2026-12-07',
        'fecha_fin' => '2026-12-11',
        'motivo' => 'Vacaciones de prueba',
    ]);
});

it('no manda recordatorios en un día inhábil', function () {
    recPareja();
    inhabil('2026-12-02', null, null, 'Suspensión de prueba');

    expect(recCorrer('2026-12-02 09:35:00'))->toBe(0);
});

it('rechaza fines de semana, vacaciones y suspensiones, y deja pasar un día normal', function () {
    $facultadId = Usuario::factory()->conRol(Rol::Estudiante)->create()->facultad_id;
    inhabil('2026-12-21', '2027-01-01', null, 'Vacaciones de diciembre');
    $dias = app(DiasHabilesService::class);

    expect(fn () => $dias->validar($facultadId, Carbon::parse('2026-12-05')))->toThrow(ErrorDeNegocio::class); // sábado
    expect(fn () => $dias->validar($facultadId, Carbon::parse('2026-12-23')))
        ->toThrow(ErrorDeNegocio::class, 'Ese día no hay asesorías: Vacaciones de diciembre.');

    $dias->validar($facultadId, Carbon::parse('2026-12-02')); // miércoles normal: no lanza
    expect(true)->toBeTrue();
});

it('el endpoint devuelve los generales y los de la facultad del usuario', function () {
    $estudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();
    inhabil('2026-12-21', '2027-01-01', null, 'General');
    inhabil('2026-12-03', null, $estudiante->facultad_id, 'Solo de la facultad');

    $respuesta = $this->withToken($estudiante->createToken('prueba')->plainTextToken)
        ->getJson('/api/v1/dias-inhabiles?desde=2026-12-01&hasta=2027-01-31')
        ->assertOk();

    expect(collect($respuesta->json('data'))->pluck('motivo')->all())->toBe(['Solo de la facultad', 'General']);
});
