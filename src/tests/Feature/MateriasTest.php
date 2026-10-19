<?php

use App\Enums\Rol;
use App\Models\CicloEscolar;
use App\Models\Facultad;
use App\Models\Grupo;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;

// Constantes con nombre propio: las de un archivo de Pest son globales
const URL_MATERIAS = '/api/v1/materias';
const URL_MATERIAS_ESTUDIANTE = '/api/v1/estudiante/materias';


// ---------- Ayudantes (prefijo "mat" para no chocar con otros archivos) ----------

function matLicenciatura(?int $facultadId = null): Licenciatura
{
    return Licenciatura::create([
        'facultad_id' => $facultadId ?? UsuarioFactory::facultad()->id,
        'nombre' => 'LICENCIATURA '.fake()->unique()->word(),
    ]);
}

function matMateria(Licenciatura $lic, int $semestre, string $nombre): Materia
{
    return Materia::create([
        'licenciatura_id' => $lic->id,
        'semestre' => $semestre,
        'nombre' => $nombre,
    ]);
}

// Ciclo vigente con el periodo dado (1 o 2)
function matCiclo(int $periodo): CicloEscolar
{
    return CicloEscolar::create([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => "2026-2027 {$periodo}",
        'periodo' => $periodo,
        'fecha_inicio' => '2026-08-10',
        'fecha_fin' => '2027-06-11',
        'vigente' => true,
    ]);
}

function matGrupo(Licenciatura $lic, int $anio): Grupo
{
    return Grupo::create([
        'licenciatura_id' => $lic->id,
        'nombre' => "{$anio}-1",
        'anio' => $anio,
    ]);
}

// Devuelve el token de un usuario nuevo con el rol dado
function matToken(Rol $rol, ?Grupo $grupo = null): string
{
    $u = Usuario::factory()->conRol($rol)->create(['grupo_id' => $grupo?->id]);

    return $u->createToken('prueba')->plainTextToken;
}

function matNombres($respuesta): array
{
    return $respuesta->json('data.*.nombre');
}


// ---------- GET /materias (compartida) ----------

it('materias devuelve 401 sin sesión', function () {
    $this->getJson(URL_MATERIAS)->assertUnauthorized();
});

it('materias bloquea con 403 a una cuenta no activa', function () {
    $u = Usuario::factory()->pendiente()->create(['password' => 'secreto123']);

    $this->withToken($u->createToken('prueba')->plainTextToken)
        ->getJson(URL_MATERIAS)
        ->assertForbidden();
});

it('materias devuelve las de mi facultad y no las de otra', function () {
    $mia = matMateria(matLicenciatura(), 1, 'Materia de mi facultad');

    $otra = Facultad::forceCreate(['nombre' => 'Otra Facultad', 'siglas' => 'OTR', 'activa' => true]);
    matMateria(matLicenciatura($otra->id), 1, 'Materia ajena');

    $r = $this->withToken(matToken(Rol::Estudiante))
        ->getJson(URL_MATERIAS)
        ->assertOk();

    expect(matNombres($r))->toContain('Materia de mi facultad')
        ->not->toContain('Materia ajena');
});

it('materias filtra por texto con buscar y sin buscar trae todas', function () {
    $lic = matLicenciatura();
    matMateria($lic, 1, 'Cálculo Integral');
    matMateria($lic, 2, 'Redes');

    $token = matToken(Rol::Asesor);

    // Hermana feliz: sin filtro trae las dos
    $todas = $this->withToken($token)->getJson(URL_MATERIAS)->assertOk();
    expect(matNombres($todas))->toHaveCount(2);

    // Con filtro (sin acento a propósito) trae solo una
    $filtradas = $this->withToken($token)->getJson(URL_MATERIAS.'?buscar=calculo')->assertOk();
    expect(matNombres($filtradas))->toBe(['Cálculo Integral']);
});


// ---------- GET /estudiante/materias ----------

it('estudiante/materias devuelve 401 sin sesión', function () {
    $this->getJson(URL_MATERIAS_ESTUDIANTE)->assertUnauthorized();
});

it('estudiante/materias rechaza con 403 a un asesor puro y /materias lo deja pasar', function () {
    $token = matToken(Rol::Asesor);

    $this->withToken($token)->getJson(URL_MATERIAS_ESTUDIANTE)->assertForbidden();
    $this->withToken($token)->getJson(URL_MATERIAS)->assertOk();
});

it('estudiante/materias trae su licenciatura hasta el semestre que cursa (año 3, periodo 1 = semestre 5)', function () {
    $lic = matLicenciatura();
    matMateria($lic, 1, 'Semestre 1');
    matMateria($lic, 5, 'Semestre 5');
    matMateria($lic, 6, 'Semestre 6');
    matMateria(matLicenciatura(), 1, 'De otra licenciatura');

    matCiclo(1);
    $token = matToken(Rol::Estudiante, matGrupo($lic, 3));

    $r = $this->withToken($token)->getJson(URL_MATERIAS_ESTUDIANTE)->assertOk();

    expect(matNombres($r))->toContain('Semestre 1', 'Semestre 5')
        ->not->toContain('Semestre 6')
        ->not->toContain('De otra licenciatura');
});

it('estudiante/materias abre el semestre 6 cuando el ciclo vigente es el periodo 2', function () {
    $lic = matLicenciatura();
    matMateria($lic, 5, 'Semestre 5');
    matMateria($lic, 6, 'Semestre 6');
    matMateria($lic, 7, 'Semestre 7');

    matCiclo(2);
    $token = matToken(Rol::Estudiante, matGrupo($lic, 3));

    $r = $this->withToken($token)->getJson(URL_MATERIAS_ESTUDIANTE)->assertOk();

    expect(matNombres($r))->toContain('Semestre 5', 'Semestre 6')
        ->not->toContain('Semestre 7');
});

it('estudiante/materias responde 409 sin_grupo si la cuenta no tiene grupo', function () {
    matCiclo(1);

    $this->withToken(matToken(Rol::Estudiante))
        ->getJson(URL_MATERIAS_ESTUDIANTE)
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'sin_grupo');
});

it('estudiante/materias responde 409 sin_ciclo si no hay ciclo vigente', function () {
    $lic = matLicenciatura();

    $this->withToken(matToken(Rol::Estudiante, matGrupo($lic, 3)))
        ->getJson(URL_MATERIAS_ESTUDIANTE)
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'sin_ciclo');
});
