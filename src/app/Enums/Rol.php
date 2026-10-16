<?php

namespace App\Enums;

enum Rol: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Asesor = 'asesor';
    case Estudiante = 'estudiante';
}