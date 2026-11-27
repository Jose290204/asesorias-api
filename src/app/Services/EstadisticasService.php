<?php

namespace App\Services;

use App\Enums\EstatusAsesoria;
use App\Enums\EstatusSolicitud;
use App\Models\Asesoria;
use App\Models\Solicitud;
use App\Models\Usuario;
use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Support\ContextoFacultad;

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

       // Contadores de la pantalla del admin, de la facultad en la que está trabajando.
    // Asesoria y Solicitud ya se filtran solas con FacultadScope; Usuario no, por eso el where manual.
    public function delAdmin(): array
    {
        $facultadId = app(ContextoFacultad::class)->id();

        return [
            'asesorias_en_curso' => Asesoria::where('estatus', EstatusAsesoria::EnCurso)->count(),
            'solicitudes_pendientes' => Solicitud::where('estatus', EstatusSolicitud::Pendiente)->count(),
            // Sin contar al super admin: solo está anclado a la FIC por la columna NOT NULL
            'usuarios_activos' => Usuario::where('facultad_id', $facultadId)
                ->where('estatus', EstatusUsuario::Activo)
                ->whereDoesntHave('roles', fn ($q) => $q->where('rol_id', Rol::SuperAdmin->value))
                ->count(),
        ];
    }
}