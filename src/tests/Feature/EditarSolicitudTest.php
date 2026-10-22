<?php



use App\Enums\Dia;
use App\Enums\EstatusSolicitud;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Models\CicloEscolar;
use App\Models\DisponibilidadHorario;
use App\Models\DisponibilidadMateria;
use App\Models\DisponibilidadModalidad;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Solicitud;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;

const URL_EDSOL = '/api/v1/estudiante/solicitudes';


// ---------- Ayudantes ----------

function edsolToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function edsolEstudiante(): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create();
}

function edsolHorario(string $etiqueta = '7:00-8:00 AM'): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Próxima fecha del día de la semana pedido, más $semanas semanas
function edsolFecha(int $diaSemana = Carbon::MONDAY, int $semanas = 0): string
{
    return Carbon::now()->next($diaSemana)->addWeeks($semanas)->toDateString();
}

function edsolMateria(): Materia
{
    $lic = Licenciatura::firstOrCreate([
        'facultad_id' => UsuarioFactory::facultad()->id,
        'nombre' => 'LICENCIATURA PRUEBA',
    ]);

    return Materia::firstOrCreate(['licenciatura_id' => $lic->id, 'nombre' => 'Estructura de Datos'], ['semestre' => 1]);
}

// Asesor visible y completo: da Estructura de Datos, virtual, los lunes a las 7:00 y a las 8:00
function edsolAsesor(): Usuario
{
    $asesor = Usuario::factory()->conRol(Rol::Asesor)->create();

    DisponibilidadMateria::create(['facultad_id' => $asesor->facultad_id, 'asesor_id' => $asesor->id, 'materia_id' => edsolMateria()->id]);
    DisponibilidadModalidad::create(['facultad_id' => $asesor->facultad_id, 'asesor_id' => $asesor->id, 'modalidad' => Modalidad::Virtual]);

    foreach (['7:00-8:00 AM', '8:00-9:00 AM'] as $etiqueta) {
        DisponibilidadHorario::create([
            'facultad_id' => $asesor->facultad_id,
            'asesor_id' => $asesor->id,
            'dia' => Dia::Lunes,
            'horario_id' => edsolHorario($etiqueta)->id,
        ]);
    }

    return $asesor;
}

// Inserta una solicitud directo en la BD (próximo lunes, 7:00, virtual, con nota).
// facultad_id va explícito porque al preparar datos no hay usuario logueado
function edsolSolicitud(Usuario $estudiante, Usuario $asesor, EstatusSolicitud $estatus = EstatusSolicitud::Pendiente, array $extra = []): Solicitud
{
    $facultad = UsuarioFactory::facultad();

    $ciclo = CicloEscolar::first() ?? CicloEscolar::create([
        'facultad_id' => $facultad->id,
        'nombre' => '2026-2027 1',
        'periodo' => 1,
        'fecha_inicio' => now()->subMonth()->toDateString(),
        'fecha_fin' => now()->addYear()->toDateString(),
        'vigente' => true,
    ]);

    $lic = Licenciatura::firstOrCreate(['facultad_id' => $facultad->id, 'nombre' => 'LICENCIATURA PRUEBA']);
    $grupo = Grupo::firstOrCreate(['licenciatura_id' => $lic->id, 'nombre' => '3-1'], ['anio' => 3]);

    return Solicitud::create(array_merge([
        'facultad_id' => $estudiante->facultad_id,
        'ciclo_escolar_id' => $ciclo->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => edsolMateria()->id,
        'horario_id' => edsolHorario()->id,
        'fecha_inicio' => edsolFecha(),
        'modalidad' => Modalidad::Virtual,
        'razon' => RazonAsesoria::Parcial,
        'estatus' => $estatus,
        'nota_estudiante' => 'Nota original',
    ], $extra));
}

function edsolUrl(Solicitud|int $solicitud): string
{
    $id = $solicitud instanceof Solicitud ? $solicitud->id : $solicitud;

    return URL_EDSOL.'/'.$id;
}


// ---------- Acceso ----------

it('editar solicitud devuelve 401 sin sesión', function () {
    $this->patchJson(edsolUrl(1), ['nota' => 'x'])->assertUnauthorized();
});

it('editar solicitud rechaza con 403 a un asesor puro', function () {
    $this->withToken(edsolToken(Usuario::factory()->conRol(Rol::Asesor)->create()))
        ->patchJson(edsolUrl(1), ['nota' => 'x'])
        ->assertForbidden();
});

it('editar solicitud bloquea con 403 a un estudiante con cuenta no activa', function () {
    $estudiante = Usuario::factory()->pendiente()->conRol(Rol::Estudiante)->create(['password' => 'secreto123']);

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl(1), ['nota' => 'x'])
        ->assertForbidden();
});


// ---------- Caso feliz ----------

it('editar solicitud cambia solo la nota y deja fecha y horario como estaban', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['nota' => 'Nota nueva'])
        ->assertOk()
        ->assertJsonPath('data.nota', 'Nota nueva')
        ->assertJsonPath('data.estatus', 'pendiente');

    $solicitud->refresh();
    expect($solicitud->nota_estudiante)->toBe('Nota nueva');
    expect($solicitud->fecha_inicio->toDateString())->toBe(edsolFecha());
    expect($solicitud->horario_id)->toBe(edsolHorario('7:00-8:00 AM')->id);
});

it('editar solicitud cambia la fecha a otro día que el asesor ofrece', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());
    $nueva = edsolFecha(Carbon::MONDAY, 1);

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['fecha' => $nueva])
        ->assertOk()
        ->assertJsonPath('data.fecha', $nueva);

    expect($solicitud->fresh()->fecha_inicio->toDateString())->toBe($nueva);
});

it('editar solicitud cambia el horario a otro que el asesor ofrece ese día', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());
    $otro = edsolHorario('8:00-9:00 AM');

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['horario_id' => $otro->id])
        ->assertOk()
        ->assertJsonPath('data.horario.id', $otro->id);

    expect($solicitud->fresh()->horario_id)->toBe($otro->id);
});

it('editar solicitud cambia fecha, horario y nota a la vez', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());
    $otro = edsolHorario('8:00-9:00 AM');
    $nueva = edsolFecha(Carbon::MONDAY, 2);

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['fecha' => $nueva, 'horario_id' => $otro->id, 'nota' => 'Todo nuevo'])
        ->assertOk();

    $solicitud->refresh();
    expect($solicitud->fecha_inicio->toDateString())->toBe($nueva);
    expect($solicitud->horario_id)->toBe($otro->id);
    expect($solicitud->nota_estudiante)->toBe('Todo nuevo');
});

it('editar solicitud borra la nota cuando se manda null', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['nota' => null])
        ->assertOk()
        ->assertJsonPath('data.nota', null);

    expect($solicitud->fresh()->nota_estudiante)->toBeNull();
});

it('editar solicitud no choca consigo misma al mandar los mismos datos que ya tenía', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['fecha' => edsolFecha(), 'horario_id' => edsolHorario()->id])
        ->assertOk();
});

it('editar solicitud ignora los campos que no se pueden editar', function () {
    $estudiante = edsolEstudiante();
    $asesor = edsolAsesor();
    $solicitud = edsolSolicitud($estudiante, $asesor);

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), [
            'nota' => 'Solo cambia la nota',
            'asesor_id' => edsolAsesor()->id,
            'materia_id' => 999999,
            'modalidad' => 'presencial',
            'razon' => 'ordinario',
        ])
        ->assertOk();

    $solicitud->refresh();
    expect($solicitud->asesor_id)->toBe($asesor->id);
    expect($solicitud->modalidad)->toBe(Modalidad::Virtual);
    expect($solicitud->razon)->toBe(RazonAsesoria::Parcial);
    expect($solicitud->nota_estudiante)->toBe('Solo cambia la nota');
});

it('editar solicitud solo modifica la indicada y deja las demás', function () {
    $estudiante = edsolEstudiante();
    $asesor = edsolAsesor();
    $editar = edsolSolicitud($estudiante, $asesor);
    $otra = edsolSolicitud($estudiante, $asesor, EstatusSolicitud::Pendiente, ['fecha_inicio' => edsolFecha(Carbon::MONDAY, 3)]);

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($editar), ['nota' => 'Cambiada'])
        ->assertOk();

    expect($otra->fresh()->nota_estudiante)->toBe('Nota original');
});


// ---------- Validación de forma (422) ----------

it('editar solicitud responde 422 si no se manda ningún dato', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fecha');
});

it('editar solicitud responde 422 con una fecha en formato inválido', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['fecha' => '26/10/2026'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fecha');
});

it('editar solicitud responde 422 con una fecha pasada', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['fecha' => now()->subDay()->toDateString()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fecha');
});

it('editar solicitud responde 422 con un horario que no existe', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['horario_id' => 999999])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('horario_id');
});

it('editar solicitud responde 422 con una nota de más de 500 caracteres', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['nota' => str_repeat('a', 501)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('nota');
});

it('editar solicitud acepta una nota de exactamente 500 caracteres', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['nota' => str_repeat('a', 500)])
        ->assertOk();
});


// ---------- Reglas de negocio (422 / 409) ----------

it('editar solicitud responde 422 dia_no_disponible si la nueva fecha cae en fin de semana', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['fecha' => edsolFecha(Carbon::SATURDAY)])
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'dia_no_disponible');

    expect($solicitud->fresh()->fecha_inicio->toDateString())->toBe(edsolFecha());
});

it('editar solicitud responde 422 horario_no_disponible si el asesor no ofrece ese horario', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());
    $noOfrecido = edsolHorario('3:00-4:00 PM');

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['horario_id' => $noOfrecido->id])
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'horario_no_disponible');

    expect($solicitud->fresh()->horario_id)->toBe(edsolHorario()->id);
});

it('editar solicitud responde 422 horario_no_disponible si el asesor no atiende ese día de la semana', function () {
    $estudiante = edsolEstudiante();
    // El asesor solo ofrece lunes; un martes no tiene ese horario
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['fecha' => edsolFecha(Carbon::TUESDAY)])
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'horario_no_disponible');
});

it('editar solicitud responde 422 fuera_de_ciclo si la fecha pasa del fin del ciclo', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());

    // Se acorta el ciclo para que la nueva fecha (4 semanas) quede fuera
    CicloEscolar::query()->update(['fecha_fin' => now()->addDays(10)->toDateString()]);

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['fecha' => edsolFecha(Carbon::MONDAY, 4)])
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'fuera_de_ciclo');
});

it('editar solicitud responde 409 solicitud_duplicada si el cupo nuevo ya tiene una pendiente', function () {
    $estudiante = edsolEstudiante();
    $asesor = edsolAsesor();
    // A: lunes de esta semana. B: lunes de la siguiente. Se edita B hacia la fecha de A
    edsolSolicitud($estudiante, $asesor);
    $b = edsolSolicitud($estudiante, $asesor, EstatusSolicitud::Pendiente, ['fecha_inicio' => edsolFecha(Carbon::MONDAY, 1)]);

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($b), ['fecha' => edsolFecha()])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'solicitud_duplicada');

    expect($b->fresh()->fecha_inicio->toDateString())->toBe(edsolFecha(Carbon::MONDAY, 1));
});

it('editar solicitud permite el cupo si la otra solicitud ya no está pendiente', function () {
    $estudiante = edsolEstudiante();
    $asesor = edsolAsesor();
    edsolSolicitud($estudiante, $asesor, EstatusSolicitud::Cancelada);
    $b = edsolSolicitud($estudiante, $asesor, EstatusSolicitud::Pendiente, ['fecha_inicio' => edsolFecha(Carbon::MONDAY, 1)]);

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($b), ['fecha' => edsolFecha()])
        ->assertOk();
});


// ---------- Permiso (403) y existencia (404) ----------

it('editar solicitud responde 403 si la solicitud es de otro estudiante y no la toca', function () {
    $solicitud = edsolSolicitud(edsolEstudiante(), edsolAsesor());

    $this->withToken(edsolToken(edsolEstudiante()))
        ->patchJson(edsolUrl($solicitud), ['nota' => 'Intento ajeno'])
        ->assertForbidden();

    expect($solicitud->fresh()->nota_estudiante)->toBe('Nota original');
});

it('editar solicitud responde 403 y no 422 si es de otro estudiante y el body viene vacío', function () {
    $solicitud = edsolSolicitud(edsolEstudiante(), edsolAsesor());

    $this->withToken(edsolToken(edsolEstudiante()))
        ->patchJson(edsolUrl($solicitud), [])
        ->assertForbidden();
});

it('editar solicitud: un asesor par no puede editar la que le pidieron como asesor', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $solicitud = edsolSolicitud(edsolEstudiante(), $par);

    $this->withToken(edsolToken($par))
        ->patchJson(edsolUrl($solicitud), ['nota' => 'Intento'])
        ->assertForbidden();
});

it('editar solicitud responde 404 si no existe', function () {
    $this->withToken(edsolToken(edsolEstudiante()))
        ->patchJson(edsolUrl(999999), ['nota' => 'x'])
        ->assertNotFound();
});


// ---------- Estado (409): solo se editan las pendientes ----------

it('editar solicitud responde 409 si ya fue aceptada y no la modifica', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor(), EstatusSolicitud::Aceptada);

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['nota' => 'Tarde'])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'solicitud_no_pendiente');

    expect($solicitud->fresh()->nota_estudiante)->toBe('Nota original');
});

it('editar solicitud responde 409 si ya fue rechazada', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor(), EstatusSolicitud::Rechazada);

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['nota' => 'Tarde'])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'solicitud_no_pendiente');
});

it('editar solicitud responde 409 si ya la canceló', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor(), EstatusSolicitud::Cancelada);

    $this->withToken(edsolToken($estudiante))
        ->patchJson(edsolUrl($solicitud), ['nota' => 'Tarde'])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'solicitud_no_pendiente');
});


// ---------- Efecto en las listas ----------

it('editar solicitud: la lista del estudiante muestra los datos nuevos', function () {
    $estudiante = edsolEstudiante();
    $solicitud = edsolSolicitud($estudiante, edsolAsesor());
    $nueva = edsolFecha(Carbon::MONDAY, 1);
    $token = edsolToken($estudiante);

    $this->withToken($token)
        ->patchJson(edsolUrl($solicitud), ['fecha' => $nueva, 'nota' => 'Actualizada'])
        ->assertOk();

    // Sanctum recuerda al primer usuario dentro de la misma prueba; se limpia antes de la segunda petición
    $this->app['auth']->forgetGuards();
    $this->withToken($token)
        ->getJson(URL_EDSOL.'?estatus=pendiente')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.fecha', $nueva)
        ->assertJsonPath('data.0.nota', 'Actualizada');
});