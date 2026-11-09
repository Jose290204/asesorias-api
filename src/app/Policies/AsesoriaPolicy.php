<?php

namespace App\Policies;

use App\Models\Asesoria;
use App\Models\Usuario;

class AsesoriaPolicy
{
    // ¿Puede ver esta asesoría (y sus sesiones, material, chat)?
    // Sus dos participantes, por id (no por rol), y quien administra la facultad.
    // El filtro de facultad lo da el scope del modelo: un admin nunca llega a una asesoría de otra facultad.
    public function ver(Usuario $usuario, Asesoria $asesoria): bool
    {
        return $usuario->puedeAdministrar()
            || $asesoria->estudiante_id === $usuario->id
            || $asesoria->asesor_id === $usuario->id;
    }

    // ¿Puede completar, cancelar o editar esta asesoría?
    // El asesor asignado (un asesor par no toca asesorías donde él es el estudiante)
    // y quien administra la facultad.
    public function gestionar(Usuario $usuario, Asesoria $asesoria): bool
    {
        return $usuario->puedeAdministrar()
            || $asesoria->asesor_id === $usuario->id;
    }
}