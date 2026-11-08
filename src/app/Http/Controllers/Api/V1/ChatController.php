<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnviarMensajeRequest;
use App\Http\Requests\ListarMensajesRequest;
use App\Http\Resources\ChatResource;
use App\Models\Asesoria;
use App\Models\Chat;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ChatController extends Controller
{
    // GET /asesorias/{asesoria}/mensajes?despues_de=
    public function index(ListarMensajesRequest $request, Asesoria $asesoria, ChatService $chat): AnonymousResourceCollection
    {
        // Sin despues_de → primera carga; con él → solo lo nuevo
        $despuesDe = $request->has('despues_de') ? $request->integer('despues_de') : null;

                return ChatResource::collection($chat->listar($asesoria, $despuesDe))
            // Con este número el front pone la palomita doble a los mensajes propios ya leídos.
            // Va en cada respuesta, aunque no haya mensajes nuevos, para que se actualice sola.
            ->additional(['meta' => ['leidos_hasta' => $chat->leidosHasta($asesoria, $request->user())]]);
    }

    // POST /asesorias/{asesoria}/mensajes: responde 201 con el mensaje recién creado
    public function enviar(EnviarMensajeRequest $request, Asesoria $asesoria, ChatService $chat): ChatResource
    {
        return new ChatResource(
            $chat->enviar($asesoria, $request->user(), $request->validated('mensaje'))
        );
    }

    // POST /asesorias/{asesoria}/mensajes/leidos: marca como leídos los mensajes de la otra persona.
    public function marcarLeidos(Request $request, Asesoria $asesoria, ChatService $chat): JsonResponse
    {
        Gate::authorize('leer', [Chat::class, $asesoria]);

        return response()->json([
            'marcados' => $chat->marcarLeidos($asesoria, $request->user()),
        ]);
    }
}