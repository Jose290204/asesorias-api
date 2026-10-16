<?php

namespace App\Enums;

enum RazonAsesoria: string
{
    case Parcial = 'parcial';
    case Ordinario = 'ordinario';
    case Extraordinario = 'extraordinario';
    case Especial = 'especial';
    case Reforzamiento = 'reforzamiento';
}