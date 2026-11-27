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
use App\Models\MaterialAdicional;
use App\Models\Materia;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;


// ---------- Ayudantes ----------

function lmatUrl(int $asesoriaId): string
{
    return "/api/v1/asesorias/{$asesoriaId}/material";
}

function lmatToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function lmatEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create($datos);
}

function lmatAsesor(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create($datos);
}

function lmatCiclo(): CicloEscolar
{
    return CicloEscolar::first() ?? CicloEscolar::create([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => '2026-2027 1',
        'periodo' => 1,
        'fecha_inicio' => now()->subMonth()->toDateString(),
        'fecha_fin' => now()->addYear()->toDateString(),
        'vigente' => true,
    ]);
}

function lmatHorario(string $etiqueta = '7:00-8:00 AM'): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Asesoría insertada directo en la BD (facultad_id explícito: no hay usuario logueado)
function lmatAsesoria(
    Usuario $estudiante,
    Usuario $asesor,
    EstatusAsesoria $estatus = EstatusAsesoria::EnCurso,
): Asesoria {
    $licenciatura = Licenciatura::firstOrCreate([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => 'LICENCIATURA PRUEBA',
    ]);

    $grupo = Grupo::firstOrCreate(
        ['licenciatura_id' => $licenciatura->id, 'nombre' => '3-1'],
        ['anio' => 3]
    );

    $materia = Materia::firstOrCreate(
        ['licenciatura_id' => $licenciatura->id, 'nombre' => 'Estructura de Datos'],
        ['semestre' => 1]
    );

    return Asesoria::create([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => lmatCiclo()->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => lmatHorario()->id,
        'fecha_inicio' => Carbon::now()->subWeeks(3)->toDateString(),
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => $estatus,
    ]);
}

// Registro de material insertado directo (el listado no toca el disco, solo la tabla)
function lmatMaterial(Asesoria $asesoria, ?Usuario $subio = null, array $extra = []): MaterialAdicional
{
    static $n = 0;
    $n++;

    return MaterialAdicional::create(array_merge([
        'asesoria_id' => $asesoria->id,
        'usuario_id' => ($subio ?? $asesoria->asesor)->id,
        'nombre_archivo' => "archivo{$n}.pdf",
        'ruta' => "materiales/{$asesoria->id}/archivo{$n}.pdf",
        'disco' => 'local',
        'mime_type' => 'application/pdf',
        'tamano' => 1000 + $n,
    ], $extra));
}

function lmatListar($prueba, Usuario $usuario, int $asesoriaId)
{
    return $prueba->withToken(lmatToken($usuario))->getJson(lmatUrl($asesoriaId));
}


// ---------- Acceso ----------

it('listar material devuelve 401 sin sesión', function () {
    $asesoria = lmatAsesoria(lmatEstudiante(), lmatAsesor());

    $this->getJson(lmatUrl($asesoria->id))->assertUnauthorized();
});

it('listar material bloquea con 403 a un usuario con cuenta no activa', function () {
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);
    $asesoria = lmatAsesoria(lmatEstudiante(), $asesor);
    lmatMaterial($asesoria);

    lmatListar($this, $asesor, $asesoria->id)->assertForbidden();
});


// ---------- Permiso por registro (policy) ----------

it('listar material rechaza con 403 a un asesor que no es el asignado', function () {
    $asesoria = lmatAsesoria(lmatEstudiante(), lmatAsesor());
    lmatMaterial($asesoria);

    lmatListar($this, lmatAsesor(), $asesoria->id)->assertForbidden();
});

it('listar material rechaza con 403 a un estudiante que no es el de la asesoría', function () {
    $asesoria = lmatAsesoria(lmatEstudiante(), lmatAsesor());
    lmatMaterial($asesoria);

    lmatListar($this, lmatEstudiante(), $asesoria->id)->assertForbidden();
});

it('listar material rechaza con 403 a un asesor par que no participa en esa asesoría', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $asesoria = lmatAsesoria(lmatEstudiante(), lmatAsesor());

    lmatListar($this, $par, $asesoria->id)->assertForbidden();
});

it('listar material: un asesor par lo ve cuando es el estudiante de la asesoría', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $asesoria = lmatAsesoria($par, lmatAsesor());
    lmatMaterial($asesoria);

    lmatListar($this, $par, $asesoria->id)->assertOk()->assertJsonCount(1, 'data');
});


// ---------- 404 ----------

it('listar material devuelve 404 si la asesoría no existe', function () {
    lmatListar($this, lmatAsesor(), 999999)->assertNotFound();
});

it('listar material devuelve 404 si la asesoría es de otra facultad', function () {
    $otra = UsuarioFactory::facultad('OTR');
    $asesoriaAjena = lmatAsesoria(
        lmatEstudiante(['facultad_id' => $otra->id]),
        lmatAsesor(['facultad_id' => $otra->id]),
    );
    lmatMaterial($asesoriaAjena);

    lmatListar($this, lmatAsesor(), $asesoriaAjena->id)->assertNotFound();
});


// ---------- Caso feliz ----------

it('listar material: el asesor asignado y el estudiante ven la misma lista', function () {
    $asesor = lmatAsesor();
    $estudiante = lmatEstudiante();
    $asesoria = lmatAsesoria($estudiante, $asesor);
    lmatMaterial($asesoria);
    lmatMaterial($asesoria);

    lmatListar($this, $asesor, $asesoria->id)->assertOk()->assertJsonCount(2, 'data');

    $this->app['auth']->forgetGuards();

    lmatListar($this, $estudiante, $asesoria->id)->assertOk()->assertJsonCount(2, 'data');
});

it('listar material devuelve una lista vacía si la asesoría no tiene archivos', function () {
    $asesor = lmatAsesor();
    $asesoria = lmatAsesoria(lmatEstudiante(), $asesor);

    lmatListar($this, $asesor, $asesoria->id)
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('listar material: cada archivo trae su ficha y nunca la ruta ni el disco', function () {
    $asesor = lmatAsesor([
        'nombre' => 'Patricia',
        'apellido_paterno' => 'Rentería',
        'apellido_materno' => 'Beltrán',
    ]);
    $asesoria = lmatAsesoria(lmatEstudiante(), $asesor);
    $material = lmatMaterial($asesoria, $asesor, [
        'nombre_archivo' => 'Practica 3.pdf',
        'mime_type' => 'application/pdf',
        'tamano' => 276969,
    ]);

    $respuesta = lmatListar($this, $asesor, $asesoria->id)
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'nombre', 'tipo', 'tamano', 'subido_por', 'fecha']]])
        ->assertJsonPath('data.0.id', $material->id)
        ->assertJsonPath('data.0.nombre', 'Practica 3.pdf')
        ->assertJsonPath('data.0.tipo', 'application/pdf')
        ->assertJsonPath('data.0.tamano', 276969)
        ->assertJsonPath('data.0.subido_por', 'Patricia Rentería Beltrán');

    expect($respuesta->json('data.0'))->not->toHaveKey('ruta');
    expect($respuesta->json('data.0'))->not->toHaveKey('disco');
    expect($respuesta->json('data.0'))->not->toHaveKey('usuario_id');
});

it('listar material: solo trae los archivos de esa asesoría', function () {
    $asesor = lmatAsesor();
    $estudiante = lmatEstudiante();
    $asesoria = lmatAsesoria($estudiante, $asesor);
    $otra = lmatAsesoria($estudiante, $asesor);

    $propio = lmatMaterial($asesoria);
    lmatMaterial($otra);
    lmatMaterial($otra);

    lmatListar($this, $asesor, $asesoria->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $propio->id);
});

it('listar material: el más reciente va primero', function () {
    $asesor = lmatAsesor();
    $asesoria = lmatAsesoria(lmatEstudiante(), $asesor);

    $viejo = lmatMaterial($asesoria);
    $medio = lmatMaterial($asesoria);
    $nuevo = lmatMaterial($asesoria);

    // Fechas explícitas, y a propósito en desorden respecto al id
    DB::table('material_adicional')->where('id', $viejo->id)->update(['created_at' => now()->subDays(3)]);
    DB::table('material_adicional')->where('id', $medio->id)->update(['created_at' => now()->subDay()]);
    DB::table('material_adicional')->where('id', $nuevo->id)->update(['created_at' => now()]);

    $ids = lmatListar($this, $asesor, $asesoria->id)->assertOk()->json('data.*.id');

    expect($ids)->toBe([$nuevo->id, $medio->id, $viejo->id]);
});

it('listar material: con la misma fecha, el id más alto va primero', function () {
    $asesor = lmatAsesor();
    $asesoria = lmatAsesoria(lmatEstudiante(), $asesor);
    $misma = now()->subHour();

    $primero = lmatMaterial($asesoria);
    $segundo = lmatMaterial($asesoria);

    DB::table('material_adicional')->whereIn('id', [$primero->id, $segundo->id])->update(['created_at' => $misma]);

    $ids = lmatListar($this, $asesor, $asesoria->id)->assertOk()->json('data.*.id');

    expect($ids)->toBe([$segundo->id, $primero->id]);
});

it('listar material no pagina: devuelve todos los archivos', function () {
    $asesor = lmatAsesor();
    $asesoria = lmatAsesoria(lmatEstudiante(), $asesor);

    foreach (range(1, 12) as $i) {
        lmatMaterial($asesoria);
    }

    lmatListar($this, $asesor, $asesoria->id)->assertOk()->assertJsonCount(12, 'data');
});

it('listar material sigue funcionando con la asesoría completada o cancelada', function () {
    $asesor = lmatAsesor();
    $estudiante = lmatEstudiante();
    $token = lmatToken($asesor);

    foreach ([EstatusAsesoria::Completada, EstatusAsesoria::Cancelada] as $cerrada) {
        $asesoria = lmatAsesoria($estudiante, $asesor, $cerrada);
        lmatMaterial($asesoria);

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson(lmatUrl($asesoria->id))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
});