<?php

namespace App\Policies;

use App\Models\Sesion;
use App\Models\Usuario;

class SesionPolicy
{
       // Elimina sesiones el asesor asignado a la asesoría (por id, no por rol)
    // y quien administra la facultad.
    public function eliminar(Usuario $usuario, Sesion $sesion): bool
    {
        return $usuario->puedeAdministrar()
            || $usuario->id === $sesion->asesoria->asesor_id;
    }
}