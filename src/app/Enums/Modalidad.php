<?php

namespace App\Enums;

enum Modalidad: string
{
    case Presencial = 'presencial';
    case Virtual = 'virtual';

    // Texto listo para mostrar en pantalla
    public function etiqueta(): string
    {
        return match ($this) {
            self::Presencial => 'Presencial',
            self::Virtual => 'Virtual',
        };
    }
}