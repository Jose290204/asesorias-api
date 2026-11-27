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
use Illuminate\Support\Facades\Storage;

// Disco falso para que las pruebas no escriban en storage/app/private
beforeEach(function () {
    config(['filesystems.material' => 'local']);
    Storage::fake('local');
});

const DMAT_CONTENIDO = 'contenido de prueba del archivo';


// ---------- Ayudantes ----------

function dmatUrl(int $materialId): string
{
    return "/api/v1/material/{$materialId}/descargar";
}

function dmatToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function dmatEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create($datos);
}

function dmatAsesor(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create($datos);
}

function dmatCiclo(): CicloEscolar
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

function dmatHorario(string $etiqueta = '7:00-8:00 AM'): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Asesoría insertada directo en la BD (facultad_id explícito: no hay usuario logueado)
function dmatAsesoria(
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
        'ciclo_escolar_id' => dmatCiclo()->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => dmatHorario()->id,
        'fecha_inicio' => Carbon::now()->subWeeks(3)->toDateString(),
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => $estatus,
    ]);
}

// Material con su archivo REAL en el disco falso (la descarga sí lo lee)
function dmatMaterial(Asesoria $asesoria, array $extra = [], bool $conArchivo = true): MaterialAdicional
{
    static $n = 0;
    $n++;

    $datos = array_merge([
        'asesoria_id' => $asesoria->id,
        'usuario_id' => $asesoria->asesor_id,
        'nombre_archivo' => 'Practica 3.pdf',
        'ruta' => "materiales/{$asesoria->id}/guardado{$n}.pdf",
        'disco' => 'local',
        'mime_type' => 'application/pdf',
        'tamano' => strlen(DMAT_CONTENIDO),
    ], $extra);

    if ($conArchivo) {
        Storage::disk($datos['disco'])->put($datos['ruta'], DMAT_CONTENIDO);
    }

    return MaterialAdicional::create($datos);
}

function dmatDescargar($prueba, Usuario $usuario, int $materialId)
{
    return $prueba->withToken(dmatToken($usuario))->get(dmatUrl($materialId));
}


// ---------- Acceso ----------

it('descargar material devuelve 401 sin sesión', function () {
    $material = dmatMaterial(dmatAsesoria(dmatEstudiante(), dmatAsesor()));

    $this->getJson(dmatUrl($material->id))->assertUnauthorized();
});

it('descargar material bloquea con 403 a un usuario con cuenta no activa', function () {
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);
    $material = dmatMaterial(dmatAsesoria(dmatEstudiante(), $asesor));

    dmatDescargar($this, $asesor, $material->id)->assertForbidden();
});


// ---------- Permiso por registro (policy) ----------

it('descargar material rechaza con 403 a un asesor que no es el asignado', function () {
    $material = dmatMaterial(dmatAsesoria(dmatEstudiante(), dmatAsesor()));

    dmatDescargar($this, dmatAsesor(), $material->id)->assertForbidden();
});

it('descargar material rechaza con 403 a un estudiante que no es el de la asesoría', function () {
    $material = dmatMaterial(dmatAsesoria(dmatEstudiante(), dmatAsesor()));

    dmatDescargar($this, dmatEstudiante(), $material->id)->assertForbidden();
});

it('descargar material rechaza con 403 a un asesor par que no participa en esa asesoría', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $material = dmatMaterial(dmatAsesoria(dmatEstudiante(), dmatAsesor()));

    dmatDescargar($this, $par, $material->id)->assertForbidden();
});


// ---------- 404 ----------

it('descargar material devuelve 404 si no existe', function () {
    dmatDescargar($this, dmatAsesor(), 999999)->assertNotFound();
});

it('descargar material devuelve 404 si es de una asesoría de otra facultad', function () {
    $otra = UsuarioFactory::facultad('OTR');
    $material = dmatMaterial(dmatAsesoria(
        dmatEstudiante(['facultad_id' => $otra->id]),
        dmatAsesor(['facultad_id' => $otra->id]),
    ));

    dmatDescargar($this, dmatAsesor(), $material->id)->assertNotFound();
});

it('descargar material devuelve 404 con su código si el registro existe pero el archivo ya no', function () {
    $asesor = dmatAsesor();
    $material = dmatMaterial(dmatAsesoria(dmatEstudiante(), $asesor), [], conArchivo: false);

    dmatDescargar($this, $asesor, $material->id)
        ->assertNotFound()
        ->assertJsonPath('codigo', 'archivo_no_encontrado');
});


// ---------- Caso feliz ----------

it('descargar material: el asesor asignado recibe el archivo con su nombre original', function () {
    $asesor = dmatAsesor();
    $material = dmatMaterial(dmatAsesoria(dmatEstudiante(), $asesor));

    $respuesta = dmatDescargar($this, $asesor, $material->id)
        ->assertOk()
        ->assertDownload('Practica 3.pdf');

    expect($respuesta->streamedContent())->toBe(DMAT_CONTENIDO);
});

it('descargar material: el estudiante de la asesoría también lo descarga', function () {
    $estudiante = dmatEstudiante();
    $material = dmatMaterial(dmatAsesoria($estudiante, dmatAsesor()));

    $respuesta = dmatDescargar($this, $estudiante, $material->id)
        ->assertOk()
        ->assertDownload('Practica 3.pdf');

    expect($respuesta->streamedContent())->toBe(DMAT_CONTENIDO);
});

it('descargar material: un asesor par lo descarga en cualquiera de sus dos papeles', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();

    // Como estudiante de una asesoría
    $comoEstudiante = dmatMaterial(dmatAsesoria($par, dmatAsesor()));
    dmatDescargar($this, $par, $comoEstudiante->id)->assertOk();

    $this->app['auth']->forgetGuards();

    // Como asesor asignado de otra
    $comoAsesor = dmatMaterial(dmatAsesoria(dmatEstudiante(), $par));
    dmatDescargar($this, $par, $comoAsesor->id)->assertOk();
});

it('descargar material entrega el archivo correcto cuando hay varios', function () {
    $asesor = dmatAsesor();
    $asesoria = dmatAsesoria(dmatEstudiante(), $asesor);

    $uno = dmatMaterial($asesoria, ['nombre_archivo' => 'uno.pdf']);
    $dos = dmatMaterial($asesoria, ['nombre_archivo' => 'dos.pdf']);

    // Se cambia el contenido del segundo para distinguirlos
    Storage::disk('local')->put($dos->ruta, 'contenido del segundo');

    $respuesta = dmatDescargar($this, $asesor, $dos->id)
        ->assertOk()
        ->assertDownload('dos.pdf');

    expect($respuesta->streamedContent())->toBe('contenido del segundo');
});

it('descargar material sigue funcionando con la asesoría completada o cancelada', function () {
    $asesor = dmatAsesor();
    $estudiante = dmatEstudiante();
    $token = dmatToken($asesor);

    foreach ([EstatusAsesoria::Completada, EstatusAsesoria::Cancelada] as $cerrada) {
        $material = dmatMaterial(dmatAsesoria($estudiante, $asesor, $cerrada));

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->get(dmatUrl($material->id))->assertOk();
    }
});

it('descargar material lee del disco que guardó la fila, aunque MATERIAL_DISK cambie después', function () {
    $asesor = dmatAsesor();
    // El archivo y su fila están en el disco 'local'
    $material = dmatMaterial(dmatAsesoria(dmatEstudiante(), $asesor));

    // Después se configura otro disco para los archivos nuevos
    Storage::fake('otro');
    config(['filesystems.material' => 'otro']);

    $respuesta = dmatDescargar($this, $asesor, $material->id)->assertOk();

    expect($respuesta->streamedContent())->toBe(DMAT_CONTENIDO);
});

it('descargar material no borra ni modifica el registro ni el archivo', function () {
    $asesor = dmatAsesor();
    $material = dmatMaterial(dmatAsesoria(dmatEstudiante(), $asesor));

    dmatDescargar($this, $asesor, $material->id)->assertOk()->streamedContent();

    expect(MaterialAdicional::find($material->id))->not->toBeNull();
    Storage::disk('local')->assertExists($material->ruta);
});