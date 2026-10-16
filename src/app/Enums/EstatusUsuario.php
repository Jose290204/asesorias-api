<?php

namespace App\Enums;

enum EstatusUsuario: string
{
    case Pendiente = 'pendiente';
    case Activo = 'activo';
    case Inactivo = 'inactivo';
}