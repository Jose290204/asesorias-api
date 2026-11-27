<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RestablecerPasswordRequest;
use App\Http\Requests\SolicitarCodigoRequest;
use App\Http\Requests\VerificarCodigoRequest;
use App\Services\RecuperacionService;
use Illuminate\Http\JsonResponse;

class RecuperacionController extends Controller
{
    // POST /auth/codigo
    public function solicitar(SolicitarCodigoRequest $request, RecuperacionService $service): JsonResponse
    {
        $service->enviarCodigo($request->correo);

        // Misma respuesta exista o no el correo
        return response()->json([
            'message' => 'Si el correo está registrado, te enviamos un código.',
        ]);
    }

    // POST /auth/codigo/verificar
    // Si el código no sirve, el service lanza ErrorDeNegocio y no se llega a la línea siguiente
    public function verificar(VerificarCodigoRequest $request, RecuperacionService $service): JsonResponse
    {
        $service->verificar($request->correo, $request->codigo);

        return response()->json(['message' => 'Código correcto.']);
    }

    // POST /auth/recuperar
    public function restablecer(RestablecerPasswordRequest $request, RecuperacionService $service): JsonResponse
    {
        $service->restablecer($request->correo, $request->codigo, $request->password);

        return response()->json(['message' => 'Contraseña actualizada. Ya puedes iniciar sesión.']);
    }
}