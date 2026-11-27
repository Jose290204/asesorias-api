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


// ---------- Ayudantes ----------

function ematUrl(int $materialId): string
{
    return "/api/v1/asesor/material/{$materialId}";
}

function ematToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function ematEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create($datos);
}

function ematAsesor(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create($datos);
}

function ematCiclo(): CicloEscolar
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

function ematHorario(string $etiqueta = '7:00-8:00 AM'): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Asesoría insertada directo en la BD (facultad_id explícito: no hay usuario logueado)
function ematAsesoria(
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
        'ciclo_escolar_id' => ematCiclo()->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => ematHorario()->id,
        'fecha_inicio' => Carbon::now()->subWeeks(3)->toDateString(),
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => $estatus,
    ]);
}

// Material con su archivo real en el disco falso. Por defecto lo sube el asesor asignado.
function ematMaterial(Asesoria $asesoria, ?Usuario $subio = null, array $extra = [], bool $conArchivo = true): MaterialAdicional
{
    static $n = 0;
    $n++;

    $datos = array_merge([
        'asesoria_id' => $asesoria->id,
        'usuario_id' => ($subio ?? $asesoria->asesor)->id,
        'nombre_archivo' => "archivo{$n}.pdf",
        'ruta' => "materiales/{$asesoria->id}/guardado{$n}.pdf",
        'disco' => 'local',
        'mime_type' => 'application/pdf',
        'tamano' => 100,
    ], $extra);

    if ($conArchivo) {
        Storage::disk($datos['disco'])->put($datos['ruta'], 'contenido');
    }

    return MaterialAdicional::create($datos);
}


// ---------- Acceso ----------

it('eliminar material devuelve 401 sin sesión', function () {
    $material = ematMaterial(ematAsesoria(ematEstudiante(), ematAsesor()));

    $this->deleteJson(ematUrl($material->id))->assertUnauthorized();

    expect(MaterialAdicional::count())->toBe(1);
    Storage::disk('local')->assertExists($material->ruta);
});

it('eliminar material rechaza con 403 a un estudiante puro', function () {
    $estudiante = ematEstudiante();
    $material = ematMaterial(ematAsesoria($estudiante, ematAsesor()));

    $this->withToken(ematToken($estudiante))
        ->deleteJson(ematUrl($material->id))
        ->assertForbidden();

    expect(MaterialAdicional::count())->toBe(1);
});

it('eliminar material bloquea con 403 a un asesor con cuenta no activa', function () {
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);
    $material = ematMaterial(ematAsesoria(ematEstudiante(), $asesor));

    $this->withToken(ematToken($asesor))
        ->deleteJson(ematUrl($material->id))
        ->assertForbidden();

    expect(MaterialAdicional::count())->toBe(1);
});


// ---------- Permiso por registro (policy: solo quien lo subió) ----------

it('eliminar material rechaza con 403 a un asesor que no es el asignado', function () {
    $material = ematMaterial(ematAsesoria(ematEstudiante(), ematAsesor()));

    $this->withToken(ematToken(ematAsesor()))
        ->deleteJson(ematUrl($material->id))
        ->assertForbidden();

    expect(MaterialAdicional::count())->toBe(1);
    Storage::disk('local')->assertExists($material->ruta);
});

it('eliminar material rechaza con 403 al asesor asignado si el archivo lo subió otra persona', function () {
    $asesor = ematAsesor();
    $asesoria = ematAsesoria(ematEstudiante(), $asesor);
    $material = ematMaterial($asesoria, ematAsesor());

    $this->withToken(ematToken($asesor))
        ->deleteJson(ematUrl($material->id))
        ->assertForbidden();

    expect(MaterialAdicional::count())->toBe(1);
    Storage::disk('local')->assertExists($material->ruta);
});

it('eliminar material rechaza con 403 a un asesor par que es el estudiante de esa asesoría', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $material = ematMaterial(ematAsesoria($par, ematAsesor()));

    $this->withToken(ematToken($par))
        ->deleteJson(ematUrl($material->id))
        ->assertForbidden();

    expect(MaterialAdicional::count())->toBe(1);
});

it('eliminar material: un asesor par lo elimina cuando él lo subió como asesor asignado', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $material = ematMaterial(ematAsesoria(ematEstudiante(), $par));

    $this->withToken(ematToken($par))
        ->deleteJson(ematUrl($material->id))
        ->assertNoContent();

    expect(MaterialAdicional::count())->toBe(0);
});

it('eliminar material devuelve 403 antes que 409 a quien no lo subió, aunque la asesoría esté cancelada', function () {
    $material = ematMaterial(ematAsesoria(ematEstudiante(), ematAsesor(), EstatusAsesoria::Cancelada));

    $this->withToken(ematToken(ematAsesor()))
        ->deleteJson(ematUrl($material->id))
        ->assertForbidden();

    expect(MaterialAdicional::count())->toBe(1);
});


// ---------- 404 ----------

it('eliminar material devuelve 404 si no existe', function () {
    $this->withToken(ematToken(ematAsesor()))
        ->deleteJson(ematUrl(999999))
        ->assertNotFound();
});

it('eliminar material devuelve 404 si es de una asesoría de otra facultad', function () {
    $otra = UsuarioFactory::facultad('OTR');
    $material = ematMaterial(ematAsesoria(
        ematEstudiante(['facultad_id' => $otra->id]),
        ematAsesor(['facultad_id' => $otra->id]),
    ));

    $this->withToken(ematToken(ematAsesor()))
        ->deleteJson(ematUrl($material->id))
        ->assertNotFound();

    expect(MaterialAdicional::count())->toBe(1);
    Storage::disk('local')->assertExists($material->ruta);
});


// ---------- Caso feliz ----------

it('eliminar material: quien lo subió lo elimina y responde 204 sin cuerpo', function () {
    $asesor = ematAsesor();
    $material = ematMaterial(ematAsesoria(ematEstudiante(), $asesor));

    $respuesta = $this->withToken(ematToken($asesor))
        ->deleteJson(ematUrl($material->id))
        ->assertNoContent();

    expect($respuesta->getContent())->toBe('');
    expect(MaterialAdicional::find($material->id))->toBeNull();
});

it('eliminar material borra también el archivo del disco', function () {
    $asesor = ematAsesor();
    $material = ematMaterial(ematAsesoria(ematEstudiante(), $asesor));
    $ruta = $material->ruta;

    Storage::disk('local')->assertExists($ruta);

    $this->withToken(ematToken($asesor))->deleteJson(ematUrl($material->id))->assertNoContent();

    Storage::disk('local')->assertMissing($ruta);
});

it('eliminar material borra solo ese archivo y no toca los demás ni la asesoría', function () {
    $asesor = ematAsesor();
    $estudiante = ematEstudiante();
    $asesoria = ematAsesoria($estudiante, $asesor);
    $otraAsesoria = ematAsesoria($estudiante, $asesor);

    $objetivo = ematMaterial($asesoria);
    $hermano = ematMaterial($asesoria);
    $ajeno = ematMaterial($otraAsesoria);

    $this->withToken(ematToken($asesor))->deleteJson(ematUrl($objetivo->id))->assertNoContent();

    expect(MaterialAdicional::find($objetivo->id))->toBeNull();
    expect(MaterialAdicional::find($hermano->id))->not->toBeNull();
    expect(MaterialAdicional::find($ajeno->id))->not->toBeNull();
    Storage::disk('local')->assertExists($hermano->ruta);
    Storage::disk('local')->assertExists($ajeno->ruta);
    expect($asesoria->fresh()->estatus)->toBe(EstatusAsesoria::EnCurso);
});

it('eliminar material: desaparece de la lista y ya no se puede descargar', function () {
    $asesor = ematAsesor();
    $asesoria = ematAsesoria(ematEstudiante(), $asesor);
    $objetivo = ematMaterial($asesoria);
    ematMaterial($asesoria);
    $token = ematToken($asesor);

    $this->withToken($token)->deleteJson(ematUrl($objetivo->id))->assertNoContent();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson("/api/v1/asesorias/{$asesoria->id}/material")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->get("/api/v1/material/{$objetivo->id}/descargar")
        ->assertNotFound();
});

it('eliminar material: la segunda llamada seguida da 404', function () {
    $asesor = ematAsesor();
    $material = ematMaterial(ematAsesoria(ematEstudiante(), $asesor));
    $token = ematToken($asesor);

    $this->withToken($token)->deleteJson(ematUrl($material->id))->assertNoContent();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->deleteJson(ematUrl($material->id))->assertNotFound();
});

it('eliminar material: se permite con la asesoría completada', function () {
    $asesor = ematAsesor();
    $material = ematMaterial(ematAsesoria(ematEstudiante(), $asesor, EstatusAsesoria::Completada));

    $this->withToken(ematToken($asesor))
        ->deleteJson(ematUrl($material->id))
        ->assertNoContent();

    expect(MaterialAdicional::count())->toBe(0);
});

it('eliminar material borra el archivo del disco que guardó la fila, aunque MATERIAL_DISK cambie después', function () {
    $asesor = ematAsesor();
    $material = ematMaterial(ematAsesoria(ematEstudiante(), $asesor));
    $ruta = $material->ruta;

    // Después se configura otro disco para los archivos nuevos
    Storage::fake('otro');
    config(['filesystems.material' => 'otro']);

    $this->withToken(ematToken($asesor))->deleteJson(ematUrl($material->id))->assertNoContent();

    Storage::disk('local')->assertMissing($ruta);
});

it('eliminar material: si el archivo ya no estaba en el disco, igual borra el registro', function () {
    $asesor = ematAsesor();
    $material = ematMaterial(ematAsesoria(ematEstudiante(), $asesor), null, [], conArchivo: false);

    $this->withToken(ematToken($asesor))
        ->deleteJson(ematUrl($material->id))
        ->assertNoContent();

    expect(MaterialAdicional::count())->toBe(0);
});


// ---------- Estado (409) ----------

it('eliminar material rechaza con 409 si la asesoría está cancelada y no lo borra', function () {
    $asesor = ematAsesor();
    $material = ematMaterial(ematAsesoria(ematEstudiante(), $asesor, EstatusAsesoria::Cancelada));

    $this->withToken(ematToken($asesor))
        ->deleteJson(ematUrl($material->id))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'asesoria_cancelada');

    expect(MaterialAdicional::find($material->id))->not->toBeNull();
    Storage::disk('local')->assertExists($material->ruta);
});