<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificacionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// Todo sale de $request->user(): nadie puede leer ni marcar las notificaciones de otro.
class NotificacionController extends Controller
{
    // GET /notificaciones?no_leidas=1 (de 10 en 10, con el total de no leídas en meta.no_leidas)
    public function index(Request $request): AnonymousResourceCollection
    {
        $usuario = $request->user();

        $consulta = $request->boolean('no_leidas')
            ? $usuario->unreadNotifications()
            : $usuario->notifications();

        return NotificacionResource::collection($consulta->paginate(10))
            ->additional(['meta' => ['no_leidas' => $usuario->unreadNotifications()->count()]]);
    }

    // POST /notificaciones/{id}/leida: 404 si la notificación no es del usuario
    public function marcarLeida(Request $request, string $id): NotificacionResource
    {
        $notificacion = $request->user()->notifications()->whereKey($id)->firstOrFail();

        $notificacion->markAsRead();

        return new NotificacionResource($notificacion);
    }

    // POST /notificaciones/leidas
    public function marcarTodas(Request $request): JsonResponse
    {
        return response()->json([
            'marcadas' => $request->user()->unreadNotifications()->update(['read_at' => now()]),
        ]);
    }

        // DELETE /notificaciones/{id}: borra una; 404 si no es del usuario
    public function borrar(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->findOrFail($id)->delete();

        return response()->json(['borrada' => true]);
    }

    // DELETE /notificaciones: borra todas las del usuario (?solo_leidas=1 deja las sin leer)
    public function borrarTodas(Request $request): JsonResponse
    {
        $consulta = $request->boolean('solo_leidas')
            ? $request->user()->readNotifications()
            : $request->user()->notifications();

        return response()->json(['borradas' => $consulta->delete()]);
    }
}