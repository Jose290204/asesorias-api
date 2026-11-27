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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

// Disco falso para que las pruebas no escriban en storage/app/private.
// El service lee el disco de config('filesystems.material'); aquí se fija a 'local'
// para que no dependa de lo que diga el .env.
beforeEach(function () {
    config(['filesystems.material' => 'local']);
    Storage::fake('local');
});


// ---------- Ayudantes ----------

function smatUrl(int $asesoriaId): string
{
    return "/api/v1/asesor/asesorias/{$asesoriaId}/material";
}

function smatToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function smatEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create($datos);
}

function smatAsesor(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create($datos);
}

function smatCiclo(): CicloEscolar
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

function smatHorario(string $etiqueta = '7:00-8:00 AM'): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Asesoría insertada directo en la BD (facultad_id explícito: no hay usuario logueado)
function smatAsesoria(
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
        'ciclo_escolar_id' => smatCiclo()->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => smatHorario()->id,
        'fecha_inicio' => Carbon::now()->subWeeks(3)->toDateString(),
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => $estatus,
    ]);
}

// Archivo falso de prueba: nombre, tamaño en KB (por defecto 100)
function smatArchivo(string $nombre = 'practica.pdf', int $kb = 100): UploadedFile
{
    return UploadedFile::fake()->create($nombre, $kb);
}

// Sube archivos como multipart (postJson no sirve: convierte el cuerpo a JSON)
function smatSubir($prueba, Usuario $usuario, int $asesoriaId, array $archivos)
{
    return $prueba->withToken(smatToken($usuario))
        ->post(smatUrl($asesoriaId), ['archivos' => $archivos], ['Accept' => 'application/json']);
}


// ---------- Acceso ----------

it('subir material devuelve 401 sin sesión', function () {
    $asesoria = smatAsesoria(smatEstudiante(), smatAsesor());

    $this->post(smatUrl($asesoria->id), ['archivos' => [smatArchivo()]], ['Accept' => 'application/json'])
        ->assertUnauthorized();

    expect(MaterialAdicional::count())->toBe(0);
});

it('subir material rechaza con 403 a un estudiante puro', function () {
    $estudiante = smatEstudiante();
    $asesoria = smatAsesoria($estudiante, smatAsesor());

    smatSubir($this, $estudiante, $asesoria->id, [smatArchivo()])->assertForbidden();

    expect(MaterialAdicional::count())->toBe(0);
});

it('subir material bloquea con 403 a un asesor con cuenta no activa', function () {
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);
    $asesoria = smatAsesoria(smatEstudiante(), $asesor);

    smatSubir($this, $asesor, $asesoria->id, [smatArchivo()])->assertForbidden();

    expect(MaterialAdicional::count())->toBe(0);
});


// ---------- Permiso por registro (policy) ----------

it('subir material rechaza con 403 a un asesor que no es el asignado', function () {
    $asesoria = smatAsesoria(smatEstudiante(), smatAsesor());

    smatSubir($this, smatAsesor(), $asesoria->id, [smatArchivo()])->assertForbidden();

    expect(MaterialAdicional::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('subir material rechaza con 403 a un asesor par que es el estudiante de esa asesoría', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $asesoria = smatAsesoria($par, smatAsesor());

    smatSubir($this, $par, $asesoria->id, [smatArchivo()])->assertForbidden();

    expect(MaterialAdicional::count())->toBe(0);
});

it('subir material: un asesor par sube cuando él es el asesor asignado', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $asesoria = smatAsesoria(smatEstudiante(), $par);

    smatSubir($this, $par, $asesoria->id, [smatArchivo()])->assertCreated();

    expect(MaterialAdicional::count())->toBe(1);
});

it('subir material devuelve 403 antes que 422 a quien no es el asesor', function () {
    $asesoria = smatAsesoria(smatEstudiante(), smatAsesor());

    // Sin archivos: de ser el asesor correcto sería 422, pero primero se revisa el permiso
    $this->withToken(smatToken(smatAsesor()))
        ->post(smatUrl($asesoria->id), [], ['Accept' => 'application/json'])
        ->assertForbidden();
});

it('subir material devuelve 403 antes que 409 a quien no es el asesor, aunque la asesoría esté cancelada', function () {
    $asesoria = smatAsesoria(smatEstudiante(), smatAsesor(), EstatusAsesoria::Cancelada);

    smatSubir($this, smatAsesor(), $asesoria->id, [smatArchivo()])->assertForbidden();
});


// ---------- 404 ----------

it('subir material devuelve 404 si la asesoría no existe', function () {
    smatSubir($this, smatAsesor(), 999999, [smatArchivo()])->assertNotFound();
});

it('subir material devuelve 404 si la asesoría es de otra facultad', function () {
    $otra = UsuarioFactory::facultad('OTR');
    $asesoriaAjena = smatAsesoria(
        smatEstudiante(['facultad_id' => $otra->id]),
        smatAsesor(['facultad_id' => $otra->id]),
    );

    smatSubir($this, smatAsesor(), $asesoriaAjena->id, [smatArchivo()])->assertNotFound();

    expect(MaterialAdicional::count())->toBe(0);
});


// ---------- Caso feliz ----------

it('subir material: el asesor asignado sube un PDF y responde 201 con su ficha', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor);

    $respuesta = smatSubir($this, $asesor, $asesoria->id, [smatArchivo('Practica 3.pdf', 100)])
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure(['data' => [['id', 'nombre', 'tipo', 'tamano', 'subido_por', 'fecha']]])
        ->assertJsonPath('data.0.nombre', 'Practica 3.pdf')
        ->assertJsonPath('data.0.tipo', 'application/pdf')
        ->assertJsonPath('data.0.tamano', 100 * 1024);

    // La ruta interna y el disco nunca salen en el JSON
    expect($respuesta->json('data.0'))->not->toHaveKey('ruta');
    expect($respuesta->json('data.0'))->not->toHaveKey('disco');
});

it('subir material: guarda el registro y el archivo en una carpeta por asesoría con nombre aleatorio', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor);

    smatSubir($this, $asesor, $asesoria->id, [smatArchivo('Practica 3.pdf')])->assertCreated();

    $fila = MaterialAdicional::first();

    expect($fila->asesoria_id)->toBe($asesoria->id);
    expect($fila->usuario_id)->toBe($asesor->id);
    expect($fila->nombre_archivo)->toBe('Practica 3.pdf');
    expect($fila->disco)->toBe('local');
    expect($fila->mime_type)->toBe('application/pdf');

    // Carpeta por asesoría, y el nombre original nunca es la ruta
    expect($fila->ruta)->toStartWith("materiales/{$asesoria->id}/");
    expect($fila->ruta)->not->toContain('Practica');

    Storage::disk('local')->assertExists($fila->ruta);
});

it('subir material: varios archivos en una sola petición (hasta 5)', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor);

    $archivos = [
        smatArchivo('uno.pdf'),
        smatArchivo('dos.docx'),
        smatArchivo('tres.xlsx'),
        smatArchivo('cuatro.png'),
        smatArchivo('cinco.zip'),
    ];

    smatSubir($this, $asesor, $asesoria->id, $archivos)
        ->assertCreated()
        ->assertJsonCount(5, 'data');

    expect(MaterialAdicional::count())->toBe(5);
    expect(Storage::disk('local')->allFiles())->toHaveCount(5);
});

it('subir material acepta cada tipo permitido', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor);
    $token = smatToken($asesor);

    $nombres = [
        'a.pdf', 'a.doc', 'a.docx', 'a.ppt', 'a.pptx', 'a.xls',
        'a.xlsx', 'a.jpg', 'a.jpeg', 'a.png', 'a.zip',
    ];

    foreach ($nombres as $nombre) {
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->post(smatUrl($asesoria->id), ['archivos' => [smatArchivo($nombre)]], ['Accept' => 'application/json'])
            ->assertCreated();
    }

    expect(MaterialAdicional::count())->toBe(count($nombres));
});

it('subir material acepta un archivo de exactamente 20 MB', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor);

    smatSubir($this, $asesor, $asesoria->id, [smatArchivo('limite.pdf', 20480)])->assertCreated();

    expect(MaterialAdicional::count())->toBe(1);
});

it('subir material: dos archivos con el mismo nombre no se pisan', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor);
    $token = smatToken($asesor);

    $this->withToken($token)
        ->post(smatUrl($asesoria->id), ['archivos' => [smatArchivo('tarea.pdf')]], ['Accept' => 'application/json'])
        ->assertCreated();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->post(smatUrl($asesoria->id), ['archivos' => [smatArchivo('tarea.pdf')]], ['Accept' => 'application/json'])
        ->assertCreated();

    $rutas = MaterialAdicional::pluck('ruta')->all();

    expect($rutas)->toHaveCount(2);
    expect($rutas[0])->not->toBe($rutas[1]);
    expect(Storage::disk('local')->allFiles())->toHaveCount(2);
});

it('subir material: cada archivo queda en la carpeta de su asesoría', function () {
    $asesor = smatAsesor();
    $estudiante = smatEstudiante();
    $primera = smatAsesoria($estudiante, $asesor);
    $segunda = smatAsesoria($estudiante, $asesor);
    $token = smatToken($asesor);

    $this->withToken($token)
        ->post(smatUrl($primera->id), ['archivos' => [smatArchivo('a.pdf')]], ['Accept' => 'application/json'])
        ->assertCreated();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->post(smatUrl($segunda->id), ['archivos' => [smatArchivo('b.pdf')]], ['Accept' => 'application/json'])
        ->assertCreated();

    expect($primera->material()->count())->toBe(1);
    expect($segunda->material()->count())->toBe(1);
    expect($primera->material()->first()->ruta)->toStartWith("materiales/{$primera->id}/");
    expect($segunda->material()->first()->ruta)->toStartWith("materiales/{$segunda->id}/");
});

it('subir material se permite con la asesoría completada', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor, EstatusAsesoria::Completada);

    smatSubir($this, $asesor, $asesoria->id, [smatArchivo()])->assertCreated();

    expect(MaterialAdicional::count())->toBe(1);
});


// ---------- Validación (422) ----------

it('subir material rechaza con 422 si no se manda ningún archivo', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor);

    $this->withToken(smatToken($asesor))
        ->post(smatUrl($asesoria->id), [], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['archivos']);

    expect(MaterialAdicional::count())->toBe(0);
});

it('subir material rechaza con 422 si "archivos" no es una lista', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor);

    $this->withToken(smatToken($asesor))
        ->post(smatUrl($asesoria->id), ['archivos' => 'no soy un archivo'], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['archivos']);

    expect(MaterialAdicional::count())->toBe(0);
});

it('subir material rechaza con 422 si el campo se llama distinto de "archivos"', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor);

    $this->withToken(smatToken($asesor))
        ->post(smatUrl($asesoria->id), ['archivo' => [smatArchivo()]], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['archivos']);
});

it('subir material rechaza con 422 más de 5 archivos y no guarda ninguno', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor);

    $seis = array_map(fn ($n) => smatArchivo("archivo{$n}.pdf"), range(1, 6));

    smatSubir($this, $asesor, $asesoria->id, $seis)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['archivos']);

    expect(MaterialAdicional::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('subir material rechaza con 422 un archivo de texto', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor);

    smatSubir($this, $asesor, $asesoria->id, [smatArchivo('notas.txt')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['archivos.0']);

    expect(MaterialAdicional::count())->toBe(0);
});

it('subir material rechaza con 422 un ejecutable', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor);

    smatSubir($this, $asesor, $asesoria->id, [smatArchivo('virus.exe')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['archivos.0']);

    expect(MaterialAdicional::count())->toBe(0);
});

it('subir material rechaza con 422 un archivo de más de 20 MB', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor);

    smatSubir($this, $asesor, $asesoria->id, [smatArchivo('enorme.pdf', 20481)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['archivos.0']);

    expect(MaterialAdicional::count())->toBe(0);
});

it('subir material es todo o nada: un archivo inválido entre válidos no deja guardar ninguno', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor);

    smatSubir($this, $asesor, $asesoria->id, [
        smatArchivo('bueno.pdf'),
        smatArchivo('malo.exe'),
        smatArchivo('otro.png'),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['archivos.1']);

    expect(MaterialAdicional::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});


// ---------- Estado (409) ----------

it('subir material rechaza con 409 si la asesoría está cancelada y no guarda nada', function () {
    $asesor = smatAsesor();
    $asesoria = smatAsesoria(smatEstudiante(), $asesor, EstatusAsesoria::Cancelada);

    smatSubir($this, $asesor, $asesoria->id, [smatArchivo()])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'asesoria_cancelada');

    expect(MaterialAdicional::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});