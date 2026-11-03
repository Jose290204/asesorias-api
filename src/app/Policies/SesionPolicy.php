<?php

namespace App\Policies;

use App\Models\Sesion;
use App\Models\Usuario;

class SesionPolicy
{
    // Solo el asesor asignado a la asesoría elimina sus sesiones.
    // Se compara por id y no por rol: un asesor par solo elimina las sesiones
    // de las asesorías que él imparte, nunca las de las que recibe como estudiante.
    public function eliminar(Usuario $usuario, Sesion $sesion): bool
    {
        return $usuario->id === $sesion->asesoria->asesor_id;
    }
}