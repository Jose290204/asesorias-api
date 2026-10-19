<?php

namespace App\Services;

use App\Enums\EstatusAsesoria;
use App\Enums\EstatusSolicitud;
use App\Models\Asesoria;
use App\Models\Solicitud;
use App\Models\Usuario;

class EstadisticasService
{
    // Los 3 contadores de la pantalla del estudiante
    public function delEstudiante(Usuario $estudiante): array
    {
        return [
            'completadas' => $this->asesorias('estudiante_id', $estudiante, EstatusAsesoria::Completada),
            'en_curso' => $this->asesorias('estudiante_id', $estudiante, EstatusAsesoria::EnCurso),
            'solicitudes_en_revision' => Solicitud::where('estudiante_id', $estudiante->id)
                ->where('estatus', EstatusSolicitud::Pendiente)
                ->count(),
        ];
    }

    // Los contadores de la pantalla del asesor
    public function delAsesor(Usuario $asesor): array
    {
        return [
            'completadas' => $this->asesorias('asesor_id', $asesor, EstatusAsesoria::Completada),
            'en_curso' => $this->asesorias('asesor_id', $asesor, EstatusAsesoria::EnCurso),
            'pendientes_por_responder' => Solicitud::where('asesor_id', $asesor->id)
                ->where('estatus', EstatusSolicitud::Pendiente)
                ->count(),
        ];
    }

    // Cuenta las asesorías de un usuario (como estudiante o como asesor) con cierto estatus
    private function asesorias(string $columna, Usuario $usuario, EstatusAsesoria $estatus): int
    {
        return Asesoria::where($columna, $usuario->id)
            ->where('estatus', $estatus)
            ->count();
    }
}