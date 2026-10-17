<?php

namespace App\Enums;

enum Rol: int
{
    case SuperAdmin = 1;
    case Admin = 2;
    case Asesor = 3;
    case Estudiante = 4;

    public function etiqueta(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super administrador',
            self::Admin      => 'Administrador',
            self::Asesor     => 'Asesor',
            self::Estudiante => 'Estudiante',
        };
    }
}