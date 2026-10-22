<?php

namespace App\Policies;

use App\Models\Solicitud;
use App\Models\Usuario;

class SolicitudPolicy
{
    // ¿Este usuario puede cancelar esta solicitud? Devuelve true o false.
    // false → Laravel responde 403 solo.
    public function cancelar(Usuario $usuario, Solicitud $solicitud): bool
    {
        // Solo el estudiante que la creó. Se compara por id, no por rol,
        // así un asesor par no puede tocar solicitudes de otros.
        return $solicitud->estudiante_id === $usuario->id;
    }

    
    public function editar(Usuario $usuario, Solicitud $solicitud): bool
    {
        return $solicitud->estudiante_id === $usuario->id;
    }
}