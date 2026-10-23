<?php

namespace App\Policies;

use App\Models\Asesoria;
use App\Models\Usuario;

class AsesoriaPolicy
{
    // ¿Puede ver esta asesoría (y sus sesiones, material, chat)? Solo sus dos participantes.
    // Se compara por id, no por rol.
    public function ver(Usuario $usuario, Asesoria $asesoria): bool
    {
        return $asesoria->estudiante_id === $usuario->id
            || $asesoria->asesor_id === $usuario->id;
    }

    // ¿Puede completar, cancelar o editar esta asesoría?
    // Solo el asesor asignado: un asesor par no toca asesorías donde él es el estudiante.
    // false → Laravel responde 403 solo.
    public function gestionar(Usuario $usuario, Asesoria $asesoria): bool
    {
        return $asesoria->asesor_id === $usuario->id;
    }
}