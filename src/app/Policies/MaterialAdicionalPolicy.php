<?php

namespace App\Policies;

use App\Models\MaterialAdicional;
use App\Models\Usuario;

class MaterialAdicionalPolicy
{
    // ¿Puede descargar este archivo? Los dos participantes de la asesoría.
    // Se delega en AsesoriaPolicy::ver para tener una sola definición de "participante":
    // cuando el admin entre en la fase 2, basta con ampliar esa regla.
    public function descargar(Usuario $usuario, MaterialAdicional $material): bool
    {
        return $usuario->can('ver', $material->asesoria);
    }

    // ¿Puede borrar este archivo? Solo quien lo subió.
    // Se compara por id (usuario_id), no por rol: un asesor par no borra material
    // que subió otra persona ni el de asesorías donde él es el estudiante.
    public function eliminar(Usuario $usuario, MaterialAdicional $material): bool
    {
        return $material->usuario_id === $usuario->id;
    }
}