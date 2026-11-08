<?php

use App\Enums\EstatusAsesoria;
use App\Enums\Modalidad;
use App\Enums\RazonAsesoria;
use App\Enums\Rol;
use App\Models\Asesoria;
use App\Models\Chat;
use App\Models\CicloEscolar;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Licenciatura;
use App\Models\Materia;
use App\Models\Usuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Support\Carbon;


// ---------- Ayudantes ----------

function chatUrl(int $asesoriaId, string $extra = ''): string
{
    return "/api/v1/asesorias/{$asesoriaId}/mensajes{$extra}";
}

function chatToken(Usuario $usuario): string
{
    return $usuario->createToken('prueba')->plainTextToken;
}

function chatEstudiante(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Estudiante)->create($datos);
}

function chatAsesor(array $datos = []): Usuario
{
    return Usuario::factory()->conRol(Rol::Asesor)->create($datos);
}

function chatCiclo(): CicloEscolar
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

function chatHorario(string $etiqueta = '7:00-8:00 AM'): Horario
{
    return Horario::firstOrCreate(['etiqueta' => $etiqueta]);
}

// Asesoría insertada directo en la BD (facultad_id explícito: no hay usuario logueado)
function chatAsesoria(
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
        'ciclo_escolar_id' => chatCiclo()->id,
        'grupo_id' => $grupo->id,
        'estudiante_id' => $estudiante->id,
        'asesor_id' => $asesor->id,
        'materia_id' => $materia->id,
        'horario_id' => chatHorario()->id,
        'fecha_inicio' => Carbon::now()->subWeeks(3)->toDateString(),
        'modalidad' => Modalidad::Presencial,
        'razon' => RazonAsesoria::Reforzamiento,
        'estatus' => $estatus,
    ]);
}

function chatMensaje(Asesoria $asesoria, Usuario $autor, string $texto = 'Hola', array $extra = []): Chat
{
    return Chat::create(array_merge([
        'asesoria_id' => $asesoria->id,
        'usuario_id' => $autor->id,
        'mensaje' => $texto,
    ], $extra));
}


// ---------- Acceso: listar ----------

it('listar mensajes devuelve 401 sin sesión', function () {
    $asesoria = chatAsesoria(chatEstudiante(), chatAsesor());

    $this->getJson(chatUrl($asesoria->id))->assertUnauthorized();
});

it('listar mensajes bloquea con 403 a un asesor con cuenta no activa', function () {
    $asesor = Usuario::factory()->pendiente()->conRol(Rol::Asesor)->create(['password' => 'secreto123']);
    $asesoria = chatAsesoria(chatEstudiante(), $asesor);

    $this->withToken(chatToken($asesor))
        ->getJson(chatUrl($asesoria->id))
        ->assertForbidden();
});

it('listar mensajes rechaza con 403 a un usuario que no participa en la asesoría', function () {
    $asesoria = chatAsesoria(chatEstudiante(), chatAsesor());

    $this->withToken(chatToken(chatEstudiante()))
        ->getJson(chatUrl($asesoria->id))
        ->assertForbidden();

    $this->app['auth']->forgetGuards();

    $this->withToken(chatToken(chatAsesor()))
        ->getJson(chatUrl($asesoria->id))
        ->assertForbidden();
});

it('listar mensajes devuelve 404 si la asesoría no existe', function () {
    $this->withToken(chatToken(chatAsesor()))
        ->getJson(chatUrl(999999))
        ->assertNotFound();
});

it('listar mensajes devuelve 404 si la asesoría es de otra facultad', function () {
    $otra = UsuarioFactory::facultad('OTR');
    $ajena = chatAsesoria(
        chatEstudiante(['facultad_id' => $otra->id]),
        chatAsesor(['facultad_id' => $otra->id]),
    );

    $this->withToken(chatToken(chatAsesor()))
        ->getJson(chatUrl($ajena->id))
        ->assertNotFound();
});


// ---------- Acceso: enviar ----------

it('enviar mensaje devuelve 401 sin sesión', function () {
    $asesoria = chatAsesoria(chatEstudiante(), chatAsesor());

    $this->postJson(chatUrl($asesoria->id), ['mensaje' => 'Hola'])->assertUnauthorized();

    expect(Chat::count())->toBe(0);
});

it('enviar mensaje rechaza con 403 a un usuario que no participa y no guarda nada', function () {
    $asesoria = chatAsesoria(chatEstudiante(), chatAsesor());

    $this->withToken(chatToken(chatEstudiante()))
        ->postJson(chatUrl($asesoria->id), ['mensaje' => 'Hola'])
        ->assertForbidden();

    expect(Chat::count())->toBe(0);
});

it('enviar mensaje rechaza con 403 a un admin: el admin no escribe en el chat', function () {
    $asesoria = chatAsesoria(chatEstudiante(), chatAsesor());
    $admin = Usuario::factory()->conRol(Rol::Admin)->create();

    $this->withToken(chatToken($admin))
        ->postJson(chatUrl($asesoria->id), ['mensaje' => 'Hola'])
        ->assertForbidden();

    expect(Chat::count())->toBe(0);
});

it('enviar mensaje devuelve 404 si la asesoría es de otra facultad', function () {
    $otra = UsuarioFactory::facultad('OTR');
    $ajena = chatAsesoria(
        chatEstudiante(['facultad_id' => $otra->id]),
        chatAsesor(['facultad_id' => $otra->id]),
    );

    $this->withToken(chatToken(chatAsesor()))
        ->postJson(chatUrl($ajena->id), ['mensaje' => 'Hola'])
        ->assertNotFound();

    expect(Chat::count())->toBe(0);
});

it('enviar mensaje devuelve 403 antes que 409 a quien no participa, aunque la asesoría esté cerrada', function () {
    $asesoria = chatAsesoria(chatEstudiante(), chatAsesor(), EstatusAsesoria::Completada);

    $this->withToken(chatToken(chatAsesor()))
        ->postJson(chatUrl($asesoria->id), ['mensaje' => 'Hola'])
        ->assertForbidden();
});


// ---------- Enviar: caso feliz ----------

it('enviar mensaje: el estudiante escribe y responde 201 con propio:true', function () {
    $estudiante = chatEstudiante();
    $asesoria = chatAsesoria($estudiante, chatAsesor());

    $this->withToken(chatToken($estudiante))
        ->postJson(chatUrl($asesoria->id), ['mensaje' => '¿Hoy es por Zoom?'])
        ->assertCreated()
        ->assertJsonPath('data.texto', '¿Hoy es por Zoom?')
        ->assertJsonPath('data.propio', true)
        ->assertJsonPath('data.emisor.id', $estudiante->id)
        ->assertJsonStructure(['data' => ['id', 'texto', 'fecha', 'propio', 'emisor' => ['id', 'nombre']]]);

    $guardado = Chat::first();
    expect($guardado->asesoria_id)->toBe($asesoria->id);
    expect($guardado->usuario_id)->toBe($estudiante->id);
    expect($guardado->leido_en)->toBeNull();
});

it('enviar mensaje: el asesor asignado también puede escribir', function () {
    $asesor = chatAsesor();
    $asesoria = chatAsesoria(chatEstudiante(), $asesor);

    $this->withToken(chatToken($asesor))
        ->postJson(chatUrl($asesoria->id), ['mensaje' => 'Te paso el enlace'])
        ->assertCreated()
        ->assertJsonPath('data.propio', true);

    expect(Chat::count())->toBe(1);
});

it('enviar mensaje: un asesor par escribe como estudiante en su propia asesoría', function () {
    $par = Usuario::factory()->conRol(Rol::Estudiante, Rol::Asesor)->create();
    $asesoria = chatAsesoria($par, chatAsesor());

    $this->withToken(chatToken($par))
        ->postJson(chatUrl($asesoria->id), ['mensaje' => 'Hola'])
        ->assertCreated();
});


// ---------- Enviar: validación ----------

it('enviar mensaje rechaza con 422 un mensaje vacío, ausente o solo espacios', function () {
    $estudiante = chatEstudiante();
    $asesoria = chatAsesoria($estudiante, chatAsesor());
    $token = chatToken($estudiante);

    foreach ([[], ['mensaje' => ''], ['mensaje' => '   ']] as $cuerpo) {
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson(chatUrl($asesoria->id), $cuerpo)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mensaje');
    }

    expect(Chat::count())->toBe(0);
});

it('enviar mensaje rechaza con 422 un mensaje de más de 1000 caracteres y acepta uno de exactamente 1000', function () {
    $estudiante = chatEstudiante();
    $asesoria = chatAsesoria($estudiante, chatAsesor());
    $token = chatToken($estudiante);

    $this->withToken($token)
        ->postJson(chatUrl($asesoria->id), ['mensaje' => str_repeat('a', 1001)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mensaje');

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson(chatUrl($asesoria->id), ['mensaje' => str_repeat('a', 1000)])
        ->assertCreated();

    expect(Chat::count())->toBe(1);
});


// ---------- Enviar: estado (409) ----------

it('enviar mensaje rechaza con 409 si la asesoría ya está completada o cancelada y no guarda nada', function () {
    $estudiante = chatEstudiante();
    $asesor = chatAsesor();
    $token = chatToken($estudiante);

    foreach ([EstatusAsesoria::Completada, EstatusAsesoria::Cancelada] as $cerrada) {
        $asesoria = chatAsesoria($estudiante, $asesor, $cerrada);

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson(chatUrl($asesoria->id), ['mensaje' => 'Hola'])
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'asesoria_no_en_curso');
    }

    expect(Chat::count())->toBe(0);
});


// ---------- Listar: contenido y orden ----------

it('listar mensajes: devuelve la conversación en orden cronológico', function () {
    $estudiante = chatEstudiante();
    $asesor = chatAsesor();
    $asesoria = chatAsesoria($estudiante, $asesor);

    $uno = chatMensaje($asesoria, $estudiante, 'Primero');
    $dos = chatMensaje($asesoria, $asesor, 'Segundo');
    $tres = chatMensaje($asesoria, $estudiante, 'Tercero');

    $this->withToken(chatToken($estudiante))
        ->getJson(chatUrl($asesoria->id))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $uno->id)
        ->assertJsonPath('data.1.id', $dos->id)
        ->assertJsonPath('data.2.id', $tres->id);
});

it('listar mensajes: propio se calcula según quien consulta', function () {
    $estudiante = chatEstudiante();
    $asesor = chatAsesor();
    $asesoria = chatAsesoria($estudiante, $asesor);
    chatMensaje($asesoria, $estudiante, 'Del estudiante');
    chatMensaje($asesoria, $asesor, 'Del asesor');

    $this->withToken(chatToken($estudiante))
        ->getJson(chatUrl($asesoria->id))
        ->assertOk()
        ->assertJsonPath('data.0.propio', true)
        ->assertJsonPath('data.1.propio', false)
        ->assertJsonPath('data.1.emisor.id', $asesor->id);

    $this->app['auth']->forgetGuards();

    $this->withToken(chatToken($asesor))
        ->getJson(chatUrl($asesoria->id))
        ->assertOk()
        ->assertJsonPath('data.0.propio', false)
        ->assertJsonPath('data.1.propio', true);
});

it('listar mensajes: no mezcla mensajes de otras asesorías', function () {
    $estudiante = chatEstudiante();
    $asesor = chatAsesor();
    $asesoria = chatAsesoria($estudiante, $asesor);
    $otra = chatAsesoria($estudiante, $asesor);

    chatMensaje($asesoria, $estudiante, 'Aquí');
    chatMensaje($otra, $estudiante, 'Allá');

    $this->withToken(chatToken($estudiante))
        ->getJson(chatUrl($asesoria->id))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.texto', 'Aquí');
});

it('listar mensajes: una conversación vacía responde 200 con lista vacía', function () {
    $estudiante = chatEstudiante();
    $asesoria = chatAsesoria($estudiante, chatAsesor());

    $this->withToken(chatToken($estudiante))
        ->getJson(chatUrl($asesoria->id))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('listar mensajes: el historial (completada o cancelada) sigue siendo legible', function () {
    $estudiante = chatEstudiante();
    $asesor = chatAsesor();
    $token = chatToken($estudiante);

    foreach ([EstatusAsesoria::Completada, EstatusAsesoria::Cancelada] as $cerrada) {
        $asesoria = chatAsesoria($estudiante, $asesor, $cerrada);
        chatMensaje($asesoria, $asesor, 'Quedó pendiente');

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson(chatUrl($asesoria->id))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
});

it('listar mensajes: con más de 300 mensajes devuelve los 300 más recientes en orden', function () {
    $estudiante = chatEstudiante();
    $asesoria = chatAsesoria($estudiante, chatAsesor());

    $ultimo = null;
    for ($i = 1; $i <= 305; $i++) {
        $ultimo = chatMensaje($asesoria, $estudiante, "Mensaje {$i}");
    }

    $respuesta = $this->withToken(chatToken($estudiante))
        ->getJson(chatUrl($asesoria->id))
        ->assertOk()
        ->assertJsonCount(300, 'data');

    $datos = $respuesta->json('data');
    expect($datos[0]['texto'])->toBe('Mensaje 6');
    expect($datos[299]['id'])->toBe($ultimo->id);
});


// ---------- Listar: despues_de (consulta periódica) ----------

it('listar mensajes: despues_de devuelve solo los más nuevos', function () {
    $estudiante = chatEstudiante();
    $asesor = chatAsesor();
    $asesoria = chatAsesoria($estudiante, $asesor);

    chatMensaje($asesoria, $estudiante, 'Viejo');
    $corte = chatMensaje($asesoria, $asesor, 'Corte');
    $nuevo = chatMensaje($asesoria, $estudiante, 'Nuevo');

    $this->withToken(chatToken($estudiante))
        ->getJson(chatUrl($asesoria->id, "?despues_de={$corte->id}"))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $nuevo->id);
});

it('listar mensajes: despues_de con el último id responde lista vacía', function () {
    $estudiante = chatEstudiante();
    $asesoria = chatAsesoria($estudiante, chatAsesor());
    $ultimo = chatMensaje($asesoria, $estudiante);

    $this->withToken(chatToken($estudiante))
        ->getJson(chatUrl($asesoria->id, "?despues_de={$ultimo->id}"))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('listar mensajes: despues_de=0 equivale a pedir todo', function () {
    $estudiante = chatEstudiante();
    $asesoria = chatAsesoria($estudiante, chatAsesor());
    chatMensaje($asesoria, $estudiante, 'Uno');
    chatMensaje($asesoria, $estudiante, 'Dos');

    $this->withToken(chatToken($estudiante))
        ->getJson(chatUrl($asesoria->id, '?despues_de=0'))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('listar mensajes rechaza con 422 un despues_de inválido', function () {
    $estudiante = chatEstudiante();
    $asesoria = chatAsesoria($estudiante, chatAsesor());
    $token = chatToken($estudiante);

    foreach (['abc', '-1', '1.5'] as $malo) {
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson(chatUrl($asesoria->id, "?despues_de={$malo}"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('despues_de');
    }
});

it('flujo completo: lo que uno envía, el otro lo recibe con la consulta periódica', function () {
    $estudiante = chatEstudiante();
    $asesor = chatAsesor();
    $asesoria = chatAsesoria($estudiante, $asesor);
    $tokenEstudiante = chatToken($estudiante);
    $tokenAsesor = chatToken($asesor);

    $id = $this->withToken($tokenEstudiante)
        ->postJson(chatUrl($asesoria->id), ['mensaje' => '¿A qué hora?'])
        ->assertCreated()
        ->json('data.id');

    $this->app['auth']->forgetGuards();

    // El asesor ya tenía hasta el id anterior: solo le llega el nuevo
    $this->withToken($tokenAsesor)
        ->getJson(chatUrl($asesoria->id, '?despues_de='.($id - 1)))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.texto', '¿A qué hora?')
        ->assertJsonPath('data.0.propio', false);
});

// ---------- Palomitas de leído (leidos_hasta) ----------

it('listar mensajes: leidos_hasta es 0 si la otra persona no ha leído nada', function () {
    $estudiante = chatEstudiante();
    $asesoria = chatAsesoria($estudiante, chatAsesor());
    chatMensaje($asesoria, $estudiante);

    $this->withToken(chatToken($estudiante))
        ->getJson(chatUrl($asesoria->id))
        ->assertOk()
        ->assertJsonPath('meta.leidos_hasta', 0);
});

it('listar mensajes: leidos_hasta apunta al último mensaje propio que ya se leyó', function () {
    $estudiante = chatEstudiante();
    $asesor = chatAsesor();
    $asesoria = chatAsesoria($estudiante, $asesor);

    chatMensaje($asesoria, $estudiante, 'Uno', ['leido_en' => now()]);
    $leido = chatMensaje($asesoria, $estudiante, 'Dos', ['leido_en' => now()]);
    chatMensaje($asesoria, $estudiante, 'Tres');

    $this->withToken(chatToken($estudiante))
        ->getJson(chatUrl($asesoria->id))
        ->assertOk()
        ->assertJsonPath('meta.leidos_hasta', $leido->id);

    $this->app['auth']->forgetGuards();

    // El asesor no escribió nada: para él no hay nada propio leído
    $this->withToken(chatToken($asesor))
        ->getJson(chatUrl($asesoria->id))
        ->assertOk()
        ->assertJsonPath('meta.leidos_hasta', 0);
});

it('listar mensajes: leidos_hasta también llega en la consulta periódica sin mensajes nuevos', function () {
    $estudiante = chatEstudiante();
    $asesoria = chatAsesoria($estudiante, chatAsesor());
    $mensaje = chatMensaje($asesoria, $estudiante, 'Hola', ['leido_en' => now()]);

    $this->withToken(chatToken($estudiante))
        ->getJson(chatUrl($asesoria->id, "?despues_de={$mensaje->id}"))
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.leidos_hasta', $mensaje->id);
});


// ---------- Marcar leídos ----------

it('marcar leídos devuelve 401 sin sesión', function () {
    $asesoria = chatAsesoria(chatEstudiante(), chatAsesor());

    $this->postJson(chatUrl($asesoria->id, '/leidos'))->assertUnauthorized();
});

it('marcar leídos rechaza con 403 a un usuario que no participa y no toca nada', function () {
    $estudiante = chatEstudiante();
    $asesoria = chatAsesoria($estudiante, chatAsesor());
    $mensaje = chatMensaje($asesoria, $estudiante);

    $this->withToken(chatToken(chatAsesor()))
        ->postJson(chatUrl($asesoria->id, '/leidos'))
        ->assertForbidden();

    expect($mensaje->fresh()->leido_en)->toBeNull();
});

it('marcar leídos devuelve 404 si la asesoría es de otra facultad', function () {
    $otra = UsuarioFactory::facultad('OTR');
    $ajena = chatAsesoria(
        chatEstudiante(['facultad_id' => $otra->id]),
        chatAsesor(['facultad_id' => $otra->id]),
    );

    $this->withToken(chatToken(chatAsesor()))
        ->postJson(chatUrl($ajena->id, '/leidos'))
        ->assertNotFound();
});

it('marcar leídos: marca solo los mensajes de la otra persona y responde cuántos', function () {
    $estudiante = chatEstudiante();
    $asesor = chatAsesor();
    $asesoria = chatAsesoria($estudiante, $asesor);

    $delEstudiante1 = chatMensaje($asesoria, $estudiante, 'Uno');
    $delEstudiante2 = chatMensaje($asesoria, $estudiante, 'Dos');
    $delAsesor = chatMensaje($asesoria, $asesor, 'Respuesta');

    $this->withToken(chatToken($asesor))
        ->postJson(chatUrl($asesoria->id, '/leidos'))
        ->assertOk()
        ->assertExactJson(['marcados' => 2]);

    expect($delEstudiante1->fresh()->leido_en)->not->toBeNull();
    expect($delEstudiante2->fresh()->leido_en)->not->toBeNull();
    // Los propios nunca se marcan
    expect($delAsesor->fresh()->leido_en)->toBeNull();
});

it('marcar leídos: repetirlo seguido responde 0 y no cambia la fecha de lectura', function () {
    $estudiante = chatEstudiante();
    $asesor = chatAsesor();
    $asesoria = chatAsesoria($estudiante, $asesor);
    $mensaje = chatMensaje($asesoria, $estudiante);
    $token = chatToken($asesor);

    $this->withToken($token)
        ->postJson(chatUrl($asesoria->id, '/leidos'))
        ->assertOk()
        ->assertJsonPath('marcados', 1);

    $primera = $mensaje->fresh()->leido_en;

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson(chatUrl($asesoria->id, '/leidos'))
        ->assertOk()
        ->assertJsonPath('marcados', 0);

    expect($mensaje->fresh()->leido_en->equalTo($primera))->toBeTrue();
});

it('marcar leídos: no toca los mensajes de otras asesorías', function () {
    $estudiante = chatEstudiante();
    $asesor = chatAsesor();
    $asesoria = chatAsesoria($estudiante, $asesor);
    $otra = chatAsesoria($estudiante, $asesor);
    $enOtra = chatMensaje($otra, $estudiante);

    $this->withToken(chatToken($asesor))
        ->postJson(chatUrl($asesoria->id, '/leidos'))
        ->assertOk()
        ->assertJsonPath('marcados', 0);

    expect($enOtra->fresh()->leido_en)->toBeNull();
});

it('marcar leídos funciona también en el historial (asesoría completada)', function () {
    $estudiante = chatEstudiante();
    $asesor = chatAsesor();
    $asesoria = chatAsesoria($estudiante, $asesor, EstatusAsesoria::Completada);
    chatMensaje($asesoria, $asesor, 'Cierre');

    $this->withToken(chatToken($estudiante))
        ->postJson(chatUrl($asesoria->id, '/leidos'))
        ->assertOk()
        ->assertJsonPath('marcados', 1);
});


// ---------- Límite de envío ----------

it('enviar mensaje: el mensaje 31 en un minuto responde 429', function () {
    $estudiante = chatEstudiante();
    $asesoria = chatAsesoria($estudiante, chatAsesor());
    $token = chatToken($estudiante);

    for ($i = 1; $i <= 30; $i++) {
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson(chatUrl($asesoria->id), ['mensaje' => "Mensaje {$i}"])
            ->assertCreated();
    }

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson(chatUrl($asesoria->id), ['mensaje' => 'Mensaje 31'])
        ->assertStatus(429);

    expect(Chat::count())->toBe(30);
});

// ---------- Globito de mensajes sin leer en la lista de asesorías ----------

it('listar asesorías: mensajes_sin_leer cuenta solo los de la otra persona que no se han leído', function () {
    $estudiante = chatEstudiante();
    $asesor = chatAsesor();
    $asesoria = chatAsesoria($estudiante, $asesor);

    chatMensaje($asesoria, $estudiante, 'Uno');
    chatMensaje($asesoria, $estudiante, 'Dos');
    chatMensaje($asesoria, $asesor, 'Respuesta');
    chatMensaje($asesoria, $estudiante, 'Ya leído', ['leido_en' => now()]);

    // El asesor tiene 2 sin leer del estudiante; su propia respuesta no cuenta
    $this->withToken(chatToken($asesor))
        ->getJson('/api/v1/asesor/asesorias?estatus=en_curso')
        ->assertOk()
        ->assertJsonPath('data.0.mensajes_sin_leer', 2);

    $this->app['auth']->forgetGuards();

    // El estudiante tiene 1 sin leer: la respuesta del asesor
    $this->withToken(chatToken($estudiante))
        ->getJson('/api/v1/estudiante/asesorias?estatus=en_curso')
        ->assertOk()
        ->assertJsonPath('data.0.mensajes_sin_leer', 1);
});

it('listar asesorías: al marcar leídos el contador baja a 0', function () {
    $estudiante = chatEstudiante();
    $asesor = chatAsesor();
    $asesoria = chatAsesoria($estudiante, $asesor);
    chatMensaje($asesoria, $estudiante, 'Hola');
    $token = chatToken($asesor);

    $this->withToken($token)
        ->postJson(chatUrl($asesoria->id, '/leidos'))
        ->assertOk();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/v1/asesor/asesorias?estatus=en_curso')
        ->assertOk()
        ->assertJsonPath('data.0.mensajes_sin_leer', 0);
});