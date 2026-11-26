<?php

namespace App\Policies;

use App\Models\EvidenciaSesion;
use App\Models\Usuario;

class EvidenciaSesionPolicy
{
    // La descargan quienes participan en la asesoría (asesor y estudiante) o la administran
    public function descargar(Usuario $usuario, EvidenciaSesion $evidencia): bool
    {
        // Solo quien administra la asesoría (asesor asignado o admin); el estudiante no
        return $usuario->can('gestionar', $evidencia->sesion->asesoria);
    }

    // La borra quien la subió (por id, no por rol) o quien administra la facultad
    public function eliminar(Usuario $usuario, EvidenciaSesion $evidencia): bool
    {
        return $usuario->puedeAdministrar()
            || $evidencia->usuario_id === $usuario->id;
    }
}