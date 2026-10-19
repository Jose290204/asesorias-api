<?php

namespace App\Services;

use App\Enums\EstatusUsuario;
use App\Enums\TipoVerificacion;
use App\Exceptions\ErrorDeNegocio;
use App\Mail\CodigoRecuperacionMail;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class RecuperacionService
{
    public function __construct(private VerificacionService $verificaciones) {}

    // Manda el código si el correo es de una cuenta activa. No lanza errores:
    // el controller responde lo mismo exista o no el correo.
    public function enviarCodigo(string $correo): void
    {
        $usuario = $this->buscarActivo($correo);

        if (! $usuario) {
            return;
        }

        // null = se pidió otro código hace menos de 60 s: no se manda nada
        $codigo = $this->verificaciones->generar($usuario, TipoVerificacion::Recuperacion);

        if ($codigo === null) {
            return;
        }

        Mail::to($usuario->correo)->send(new CodigoRecuperacionMail($usuario->nombre, $codigo));
    }

    // Solo comprueba el código (no lo consume). Si no sirve, lanza el error de negocio.
    public function verificar(string $correo, string $codigo): void
    {
        $usuario = $this->buscarActivo($correo);

        // validar() ya terminó su propia transacción, así que lanzar aquí no revierte "intentos"
        $ok = $usuario && $this->verificaciones->validar($usuario, TipoVerificacion::Recuperacion, $codigo);

        if (! $ok) {
            $this->codigoInvalido();
        }
    }

    // Cambia la contraseña. Si el código no sirve, lanza el error de negocio.
    public function restablecer(string $correo, string $codigo, string $password): void
    {
        // La transacción devuelve true/false y se CONFIRMA siempre (aunque sea false),
        // para que el aumento de "intentos" se guarde. Por eso se guarda en $ok
        // y NO se hace "return" de la transacción.
        $ok = DB::transaction(function () use ($correo, $codigo, $password) {
            $usuario = $this->buscarActivo($correo);

            if (! $usuario) {
                return false;
            }

            if (! $this->verificaciones->validar($usuario, TipoVerificacion::Recuperacion, $codigo, consumir: true)) {
                return false;
            }

            // El cast "hashed" del modelo encripta la contraseña
            $usuario->update(['password' => $password]);

            // Cierra todas las sesiones web y revoca los tokens de la app
            DB::table('sessions')->where('user_id', $usuario->id)->delete();
            $usuario->tokens()->delete();

            return true;
        });

        // El throw va FUERA de la transacción a propósito
        if (! $ok) {
            $this->codigoInvalido();
        }
    }

    // Un solo error para código malo, vencido, agotado o correo inexistente
    private function codigoInvalido(): never
    {
        throw ErrorDeNegocio::invalido('El código es incorrecto o venció. Pide uno nuevo.', 'codigo_invalido');
    }

    // Solo cuentas activas pueden recuperar contraseña
    private function buscarActivo(string $correo): ?Usuario
    {
        return Usuario::where('correo', $correo)
            ->where('estatus', EstatusUsuario::Activo->value)
            ->first();
    }
}