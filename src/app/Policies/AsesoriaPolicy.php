<?php

namespace App\Policies;

use App\Models\Asesoria;
use App\Models\Usuario;

class AsesoriaPolicy
{
    // ¿Este usuario puede ver esta asesoría? Solo sus dos participantes.
    // false → Laravel responde 403 solo. Se compara por id, no por rol:
    // un asesor par solo ve las asesorías donde él es una de las dos partes.
    // El chat reutilizará esta misma regla más adelante.
    public function ver(Usuario $usuario, Asesoria $asesoria): bool
    {
        return $asesoria->estudiante_id === $usuario->id
            || $asesoria->asesor_id === $usuario->id;
    }
}