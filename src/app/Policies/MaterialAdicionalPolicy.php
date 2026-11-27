<?php

namespace App\Policies;

use App\Models\MaterialAdicional;
use App\Models\Usuario;

class MaterialAdicionalPolicy
{
   
    public function descargar(Usuario $usuario, MaterialAdicional $material): bool
    {
        return $usuario->can('ver', $material->asesoria);
    }

      // ¿Puede borrar este archivo? Quien lo subió (por id, no por rol)
    // y quien administra la facultad.
    public function eliminar(Usuario $usuario, MaterialAdicional $material): bool
    {
        return $usuario->puedeAdministrar()
            || $material->usuario_id === $usuario->id;
    }
}