<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum Dia: string
{
    case Lunes = 'lunes';
    case Martes = 'martes';
    case Miercoles = 'miercoles';
    case Jueves = 'jueves';
    case Viernes = 'viernes';

    // Devuelve el día de la semana de una fecha, o null si cae en fin de semana
    public static function desdeFecha(CarbonInterface $fecha): ?self
    {
        return match ($fecha->dayOfWeekIso) {
            1 => self::Lunes,
            2 => self::Martes,
            3 => self::Miercoles,
            4 => self::Jueves,
            5 => self::Viernes,
            default => null,
        };
    }
}