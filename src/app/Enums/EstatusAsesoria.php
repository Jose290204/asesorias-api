<?php

namespace App\Enums;

enum EstatusAsesoria: string
{
    case EnCurso = 'en_curso';
    case Completada = 'completada';
    case Cancelada = 'cancelada';
}