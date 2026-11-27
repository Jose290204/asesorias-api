<?php

namespace App\Enums;

enum RazonAsesoria: string
{
    case Parcial = 'parcial';
    case Ordinario = 'ordinario';
    case Extraordinario = 'extraordinario';
    case Especial = 'especial';
    case Reforzamiento = 'reforzamiento';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Parcial => 'Parcial',
            self::Ordinario => 'Ordinario',
            self::Extraordinario => 'Extraordinario',
            self::Especial => 'Especial',
            self::Reforzamiento => 'Reforzamiento',
        };
    }
}