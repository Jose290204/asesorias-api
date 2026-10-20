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

// Prueba rápida: el front usa meta.total, meta.last_page, meta.from y meta.to para el contador y la paginación.
// Prefijo "meta" en los ayudantes porque las funciones de un archivo de Pest son globales.

const URL_META = '/api/v1/estudiante/asesores';

// Crea un asesor visible y completo (rol asesor, activo, con materia, horario y modalidad)
function metaAsesor(): Usuario
{
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();

    $lic = Licenciatura::firstOrCreate([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => 'LICENCIATURA PRUEBA',
    ]);
    $materia = Materia::firstOrCreate(['licenciatura_id' => $lic->id, 'nombre' => 'Materia Meta'], ['semestre' => 1]);
    $horario = Horario::firstOrCreate(['etiqueta' => '7:00-8:00 AM']);

    DisponibilidadMateria::create(['facultad_id' => $asesor->facultad_id, 'asesor_id' => $asesor->id, 'materia_id' => $materia->id]);
    DisponibilidadHorario::create(['facultad_id' => $asesor->facultad_id, 'asesor_id' => $asesor->id, 'dia' => Dia::Lunes, 'horario_id' => $horario->id]);
    DisponibilidadModalidad::create(['facultad_id' => $asesor->facultad_id, 'asesor_id' => $asesor->id, 'modalidad' => Modalidad::Presencial]);

    return $asesor;
}

it('asesores manda en meta total, páginas y rango de la página actual', function () {
    $estudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();
    foreach (range(1, 12) as $_) {
        metaAsesor();
    }
    $token = $estudiante->createToken('prueba')->plainTextToken;

    // Página 1: 10 de 12 (del 1 al 10)
    $this->withToken($token)->getJson(URL_META)
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 12)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.from', 1)
        ->assertJsonPath('meta.to', 10);

    // Página 2: los 2 que sobran (del 11 al 12)
    $this->withToken($token)->getJson(URL_META.'?page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.from', 11)
        ->assertJsonPath('meta.to', 12);
});

it('asesores sin resultados manda total 0 y from/to nulos', function () {
    $estudiante = Usuario::factory()->conRol(Rol::Estudiante)->create();

    $this->withToken($estudiante->createToken('prueba')->plainTextToken)
        ->getJson(URL_META)
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.from', null)
        ->assertJsonPath('meta.to', null);
});
