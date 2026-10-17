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

    // Convierte el texto de la ruta ("asesor") en el caso del enum (Rol::Asesor).
    // Devuelve null si el texto no corresponde a ningún rol.
    public static function desdeNombre(string $nombre): ?self
    {
        foreach (self::cases() as $caso) {
            // Se compara en minúsculas para que "asesor" y "Asesor" den lo mismo
            if (strtolower($caso->name) === strtolower($nombre)) {
                return $caso;
            }
        }

        return null;
    }
}