<?php

namespace App\Enums;

// Códigos de la bitácora. Son los mismos que usa el front (ACCIONES en bitacoraService.js):
// si se agrega uno aquí, se agrega allá con su etiqueta y color.
enum AccionBitacora: string
{
    case SesionInicio = 'sesion.inicio';
    case ContrasenaRestablecida = 'cuenta.contrasena_restablecida';

    case FacultadCreada = 'facultad.creada';
    case FacultadEditada = 'facultad.editada';
    case FacultadActivada = 'facultad.activada';
    case FacultadDesactivada = 'facultad.desactivada';

    case AdministradorCreado = 'administrador.creado';
    case AdministradorEditado = 'administrador.editado';
    case AdministradorMovido = 'administrador.movido';
    case AdministradorActivado = 'administrador.activado';
    case AdministradorDesactivado = 'administrador.desactivado';
    case AdministradorEliminado = 'administrador.eliminado';

    case SolicitudCreada = 'solicitud.creada';
    case SolicitudEditada = 'solicitud.editada';
    case SolicitudCancelada = 'solicitud.cancelada';
    case SolicitudAceptada = 'solicitud.aceptada';
    case SolicitudRechazada = 'solicitud.rechazada';
    case SolicitudRechazadaAuto = 'solicitud.rechazada_auto';

    case AsesoriaCreada = 'asesoria.creada';
    case AsesoriaEditada = 'asesoria.editada';
    case AsesoriaCompletada = 'asesoria.completada';
    case AsesoriaCancelada = 'asesoria.cancelada';

    case SesionRegistrada = 'sesion.registrada';
    case SesionEliminada = 'sesion.eliminada';

    case MaterialSubido = 'material.subido';
    case MaterialEliminado = 'material.eliminado';

    case DisponibilidadActualizada = 'disponibilidad.actualizada';

    case EstudianteCreado = 'estudiante.creado';
    case EstudianteEditado = 'estudiante.editado';
    case EstudianteDadoDeBaja = 'estudiante.baja';

    case AsesorCreado = 'asesor.creado';
    case AsesorEditado = 'asesor.editado';
    case AsesorDadoDeBaja = 'asesor.baja';
    case AsesorParAsignado = 'asesor_par.asignado';
    case AsesorParQuitado = 'asesor_par.quitado';

    // Para validar filtros: ['sesion.inicio', 'facultad.creada', ...]
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}