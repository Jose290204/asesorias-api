<?php

namespace App\Services;

use App\Enums\EstatusAsesoria;
use App\Exceptions\ErrorDeNegocio;
use App\Models\Asesoria;
use App\Models\Chat;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ChatService
{
    // Máximo de mensajes que devuelve una sola consulta. Una conversación de asesoría es corta
    // (coordinar lugar, hora, enlace), así que no se pagina: este tope solo protege al servidor.
    private const TOPE = 300;

    // Mensajes de la asesoría, del más viejo al más nuevo (el id crece con cada mensaje).
    // $despuesDe es el id del último mensaje que el front ya tiene:
    //   - null → primera carga: la conversación (los últimos TOPE mensajes si fuera más larga)
    //   - número → consulta periódica: solo los mensajes con id mayor (casi siempre ninguno)
    public function listar(Asesoria $asesoria, ?int $despuesDe = null): Collection
    {
        $consulta = $asesoria->chats()
            // El Resource muestra el nombre de quien escribió: se carga de golpe
            ->with('usuario');

        if ($despuesDe !== null) {
            return $consulta->where('id', '>', $despuesDe)
                ->orderBy('id')
                ->limit(self::TOPE)
                ->get();
        }

        // Primera carga: se piden los más recientes (id descendente) para que el tope recorte
        // lo más viejo, y después se voltean para entregarlos en orden cronológico.
        return $consulta->orderByDesc('id')
            ->limit(self::TOPE)
            ->get()
            ->reverse()
            ->values();
    }

    // El estudiante o el asesor de la asesoría escribe un mensaje. Solo si está en curso.
    public function enviar(Asesoria $asesoria, Usuario $autor, string $mensaje): Chat
    {
        return DB::transaction(function () use ($asesoria, $autor, $mensaje) {
            // Relee la asesoría y la BLOQUEA hasta terminar la transacción
            $actual = Asesoria::whereKey($asesoria->id)->lockForUpdate()->firstOrFail();

            // El historial (completada o cancelada) es de solo lectura
            if ($actual->estatus !== EstatusAsesoria::EnCurso) {
                throw ErrorDeNegocio::conflicto('Solo se puede escribir en el chat de asesorías en curso.', 'asesoria_no_en_curso');
            }

            // create() desde la relación rellena asesoria_id solo
            $chat = $actual->chats()->create([
                'usuario_id' => $autor->id,
                'mensaje' => $mensaje,
            ]);

            // El Resource necesita al autor para armar el "emisor"
            return $chat->load('usuario');
        });
    }

    // Marca como leídos los mensajes que le escribió la OTRA persona a $lector.
    // Los propios nunca se marcan: "leído" significa que los vio quien los recibió.
    // Funciona con la asesoría en cualquier estatus (leer el historial no cambia nada importante),
    // por eso no bloquea ni revisa el estatus. Devuelve cuántos mensajes marcó.
    public function marcarLeidos(Asesoria $asesoria, Usuario $lector): int
    {
        return $asesoria->chats()
            ->where('usuario_id', '!=', $lector->id)
            ->whereNull('leido_en')
            ->update(['leido_en' => now()]);
    }

     public function leidosHasta(Asesoria $asesoria, Usuario $autor): int
    {
        return (int) $asesoria->chats()
            ->where('usuario_id', $autor->id)
            ->whereNotNull('leido_en')
            ->max('id');
    }
}