<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Borra las notificaciones ya leídas con más de 60 días. Necesita el cron del servidor
// (schedule:run cada minuto); sin él simplemente no se limpia, no se rompe nada.
Schedule::call(fn () => DatabaseNotification::whereNotNull('read_at')
    ->where('read_at', '<', now()->subDays(60))
    ->delete())
    ->daily()
    ->name('limpiar-notificaciones');

// Recordatorios del día de la sesión (9:30 y 1 hora antes). Corre cada 5 minutos en horario de clases,
// en la zona horaria de la app (APP_TIMEZONE=America/Mazatlan). Necesita el scheduler encendido.
Schedule::command('avisos:recordatorios-sesion')
    ->everyFiveMinutes()
    ->between('05:00', '22:00')
    ->withoutOverlapping()
    ->name('recordatorios-sesion');
