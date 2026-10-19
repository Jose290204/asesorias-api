<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
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
    }
}