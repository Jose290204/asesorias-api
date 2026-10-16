<?php

namespace App\Enums;

enum TipoVerificacion: string
{
    case Activacion = 'activacion';
    case Recuperacion = 'recuperacion';
}