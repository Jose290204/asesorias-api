<?php

namespace App\Policies;

use App\Models\Asesoria;
use App\Models\Chat;
use App\Models\Usuario;

class ChatPolicy
{
    // ¿quien puede leer el chat de esta asesoría?
    // Se delega en AsesoriaPolicy::ver para tener una sola definición de "quien ve la asesoría".
    public function leer(Usuario $usuario, Asesoria $asesoria): bool
    {
        return $usuario->can('ver', $asesoria);
    }

    // ¿Puedes escribir en el chat de esta asesoría?
    // Solo el estudiante y el asesor asignado, comparados por id (no por rol):
    public function escribir(Usuario $usuario, Asesoria $asesoria): bool
    {
        return $asesoria->estudiante_id === $usuario->id
            || $asesoria->asesor_id === $usuario->id;
    }
}