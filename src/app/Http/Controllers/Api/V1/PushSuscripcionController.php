<?php


namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSuscripcionController extends Controller
{
    // Clave pública que el navegador necesita para suscribirse
    public function clave(): JsonResponse
    {
        return response()->json(['clave' => config('webpush.vapid.public_key')]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        $request->user()->updatePushSubscription(
            $datos['endpoint'],
            $datos['keys']['p256dh'],
            $datos['keys']['auth'],
        );

        return response()->json(['ok' => true], 201);
    }

    public function borrar(Request $request): JsonResponse
    {
        $datos = $request->validate(['endpoint' => ['required', 'url']]);
        $request->user()->deletePushSubscription($datos['endpoint']);

        return response()->json(['ok' => true]);
    }
}