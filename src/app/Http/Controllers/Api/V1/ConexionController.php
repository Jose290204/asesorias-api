<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ConexionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ConexionController extends Controller
{
    // GET /admin/conexiones
    // Sesiones web y tokens de app de los usuarios de la facultad. Solo lectura: no se guarda nada.
    public function index(Request $request, ConexionService $conexiones): JsonResponse
    {
        return response()->json([
            'data' => $conexiones->listar($this->sesionActual($request), $this->tokenActual($request)),
        ]);
    }

    // DELETE /admin/conexiones/{id}   (id: "web-<sesión>" o "app-<token>")
    // Cierra esa conexión. 409 si es la propia o la de un super admin; 404 si no es de esta facultad.
    public function destroy(Request $request, ConexionService $conexiones, string $id): Response
    {
        $conexiones->cerrar($id, $this->sesionActual($request), $this->tokenActual($request));

        return response()->noContent();
    }

    // Id de la sesión web desde la que se hace la petición (null si viene con token de app)
    private function sesionActual(Request $request): ?string
    {
        return $request->hasSession() ? $request->session()->getId() : null;
    }

    // Id del token de app desde el que se hace la petición. Con cookie, Sanctum entrega un token "transitorio" sin id.
    private function tokenActual(Request $request): ?int
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token->getKey() : null;
    }
}