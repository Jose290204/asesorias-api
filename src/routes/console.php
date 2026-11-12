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