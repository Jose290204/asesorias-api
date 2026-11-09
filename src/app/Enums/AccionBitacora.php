<?php

namespace App\Enums;

// Códigos de la bitácora. Son los mismos que usa el front (ACCIONES en bitacoraService.js):
// si se agrega uno aquí, se agrega allá con su etiqueta y color.
enum AccionBitacora: string
{
    case SesionInicio = 'sesion.inicio';

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

    // Estas todavía no se registran: se usarán al construir las fases 2.1 en adelante
    case AsesoriaCreada = 'asesoria.creada';
    case AsesoriaEliminada = 'asesoria.eliminada';
    case SolicitudAceptada = 'solicitud.aceptada';
    case SolicitudRechazada = 'solicitud.rechazada';

    // Para validar filtros: ['sesion.inicio', 'facultad.creada', ...]
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}