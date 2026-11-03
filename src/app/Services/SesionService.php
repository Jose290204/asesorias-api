<?php

namespace App\Services;

use App\Models\Asesoria;
use Illuminate\Database\Eloquent\Collection;

class SesionService
{
    // Sesiones de una asesoría, la más reciente primero.
    // Sin paginar: una asesoría tiene pocas sesiones (unas por semana, dentro de un ciclo)
    // y el modal del front las muestra todas en una lista.
    public function listar(Asesoria $asesoria): Collection
    {
        return $asesoria->sesiones()
            // El Resource muestra el horario y el nombre de quien la registró: se cargan de golpe
            ->with(['horario', 'registradaPor'])
            ->orderByDesc('fecha')
            // El id desempata: dos sesiones el mismo día (otro horario) salen en orden estable
            ->orderByDesc('id')
            ->get();
    }
}