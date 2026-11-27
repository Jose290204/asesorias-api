<?php

use App\Enums\Rol;
use App\Enums\TipoNotificacion;
use App\Models\Sesion;
use App\Models\Usuario;
use App\Notifications\RecordatorioSesionNotificacion;
use App\Services\RecordatorioSesionService;
use Illuminate\Support\Carbon;

// Usa calAsesoria() de CalendarioTest.php (Pest carga todos los archivos de tests).
// Fecha de prueba: miércoles 2026-12-02. La asesoría empieza un miércoles (2026-11-18), a la 1:00 PM.

afterEach(fn () => Carbon::setTestNow());

function recPareja(string $horario = '1:00-2:00 PM'): array
{
    $estudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();

    return [$estudiante, $asesor, calAsesoria($estudiante, $asesor, [], $horario)];
}

function recAvisos(Usuario $usuario)
{
    return $usuario->notifications()->where('type', TipoNotificacion::SesionRecordatorio->value)->get();
}

function recCorrer(string $ahora): int
{
    Carbon::setTestNow($ahora);

    return app(RecordatorioSesionService::class)->enviar();
}

it('a las 9:30 avisa de la sesión de hoy al estudiante y al asesor', function () {
    [$estudiante, $asesor] = recPareja();

    expect(recCorrer('2026-12-02 09:35:00'))->toBe(2);

    $paraEstudiante = recAvisos($estudiante)->first();
    expect($paraEstudiante->data['momento'])->toBe(RecordatorioSesionNotificacion::MANANA)
        ->and($paraEstudiante->data['como'])->toBe('estudiante')
        ->and($paraEstudiante->data['mensaje'])->toBe('Hoy tienes asesoría de Estructura de Datos a la 1:00 PM con '.$asesor->nombreCompleto().'.');

    $paraAsesor = recAvisos($asesor)->first();
    expect($paraAsesor->data['como'])->toBe('asesor')
        ->and($paraAsesor->data['mensaje'])->toContain($estudiante->nombreCompleto());
});

it('no repite el aviso en la siguiente corrida', function () {
    [$estudiante] = recPareja();

    recCorrer('2026-12-02 09:35:00');
    expect(recCorrer('2026-12-02 09:40:00'))->toBe(0)
        ->and(recAvisos($estudiante))->toHaveCount(1);
});

it('una hora antes manda el aviso previo, además del de la mañana', function () {
    [$estudiante] = recPareja();

    recCorrer('2026-12-02 09:35:00');
    expect(recCorrer('2026-12-02 12:05:00'))->toBe(2);

    $momentos = recAvisos($estudiante)->pluck('data.momento')->all();
    expect($momentos)->toContain(RecordatorioSesionNotificacion::MANANA, RecordatorioSesionNotificacion::PREVIO)
        ->and(recAvisos($estudiante)->firstWhere('data.momento', RecordatorioSesionNotificacion::PREVIO)->data['mensaje'])
        ->toStartWith('En 1 hora tienes asesoría de Estructura de Datos');
});

it('no avisa antes de las 9:30 ni cuando la sesión ya empezó', function () {
    recPareja();

    expect(recCorrer('2026-12-02 09:20:00'))->toBe(0)
        ->and(recCorrer('2026-12-02 13:05:00'))->toBe(0);
});

it('no avisa si ya se registró la sesión de hoy ni en otro día de la semana', function () {
    [, $asesor, $asesoria] = recPareja();

    expect(recCorrer('2026-12-03 09:35:00'))->toBe(0); // jueves

    Sesion::create([
        'asesoria_id' => $asesoria->id,
        'fecha' => '2026-12-02',
        'horario_id' => $asesoria->horario_id,
        'registrada_por' => $asesor->id,
    ]);

    expect(recCorrer('2026-12-02 09:35:00'))->toBe(0);
});

it('una sesión temprana solo recibe el aviso previo', function () {
    [$estudiante] = recPareja('10:00-11:00 AM');

    expect(recCorrer('2026-12-02 09:35:00'))->toBe(2)
        ->and(recAvisos($estudiante)->first()->data['momento'])->toBe(RecordatorioSesionNotificacion::PREVIO);
});
