<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use App\Support\ContextoFacultad;
use App\Models\Usuario;
use Illuminate\Support\Facades\Route;


class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContextoFacultad::class);
    }

    public function boot(): void
    {

        // {administrador} solo resuelve a un administrador de facultad (no a un super admin,
        // asesor o estudiante) y que no esté eliminado: cualquier otro id da 404
        Route::bind('administrador', fn (string $valor) => Usuario::administradores()->findOrFail($valor));
        // Se usa en rutas como throttle:login
        // La clave es correo + IP: frena la fuerza bruta desde una red sin que
        // alguien pueda bloquear a otra persona solo por conocer su correo
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(
                mb_strtolower((string) $request->input('correo')).'|'.$request->ip()
            );
        });

        // Se usa en rutas como throttle:codigo (envío de códigos por correo)
        RateLimiter::for('codigo', function (Request $request) {
            return Limit::perMinute(3)->by(
                mb_strtolower((string) $request->input('correo')).'|'.$request->ip()
            );
        });

         // Se usa en rutas como throttle:verificar (comprobar el código de 6 dígitos)
        RateLimiter::for('verificar', function (Request $request) {
            return Limit::perMinute(10)->by(
                mb_strtolower((string) $request->input('correo')).'|'.$request->ip()
            );
        });

         // Se usa en la ruta throttle:mensajes (enviar un mensaje al chat).
        // La clave es el usuario: 30 mensajes por minuto es mucho para una conversación real
        // y frena el spam. Si por algo no hubiera usuario, cae a la IP.
        RateLimiter::for('mensajes', function (Request $request) {
            return Limit::perMinute(30)->by((string) ($request->user()?->id ?? $request->ip()));
        });
    }
}