<?php

use Illuminate\Support\Facades\Route;

// Temporal: confirma que la API responde y que la zona horaria es la correcta
Route::get('ping', fn () => response()->json([
    'ok' => true,
    'hora' => now()->toDateTimeString(),
    'zona' => config('app.timezone'),
]));