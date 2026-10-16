<?php

namespace App\Enums;

enum EstatusSolicitud: string
{
    case Pendiente = 'pendiente';
    case Aceptada = 'aceptada';
    case Rechazada = 'rechazada';
}