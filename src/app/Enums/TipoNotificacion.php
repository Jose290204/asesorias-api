<?php

namespace App\Enums;

// Códigos de las notificaciones. Son los mismos que usa el front (TIPOS en notificacionesService.js):
// si se agrega uno aquí, se agrega allá con su ruta y en CampanaNotificaciones con su ícono.
enum TipoNotificacion: string
{
    // Solicitudes
    case SolicitudNueva = 'solicitud.nueva';
    case SolicitudAceptada = 'solicitud.aceptada';
    case SolicitudRechazada = 'solicitud.rechazada';
    case SolicitudRechazadaPorCupo = 'solicitud.rechazada_por_cupo';
    case SolicitudCancelada = 'solicitud.cancelada';
    case SolicitudEditada = 'solicitud.editada';

    // Asesorías
    case AsesoriaCreada = 'asesoria.creada';
    case AsesoriaEditada = 'asesoria.editada';
    case AsesoriaCancelada = 'asesoria.cancelada';
    case MaterialSubido = 'material.subido';
    case MensajeNuevo = 'chat.mensaje';

    // Existen pero hoy no se disparan (se decidió no avisar de ellas); se dejan para no romper
    // los match de las notificaciones y por si se quieren activar después.
    case AsesoriaCompletada = 'asesoria.completada';
    case SesionRegistrada = 'sesion.registrada';

    public function titulo(): string
    {
        return match ($this) {
            self::SolicitudNueva => 'Nueva solicitud de asesoría',
            self::SolicitudAceptada => 'Solicitud aceptada',
            self::SolicitudRechazada => 'Solicitud rechazada',
            self::SolicitudRechazadaPorCupo => 'Solicitud rechazada: horario ocupado',
            self::SolicitudCancelada => 'Solicitud cancelada',
            self::SolicitudEditada => 'Solicitud modificada',
            self::AsesoriaCreada => 'Nueva asesoría',
            self::AsesoriaEditada => 'Asesoría modificada',
            self::AsesoriaCancelada => 'Asesoría cancelada',
            self::MaterialSubido => 'Material nuevo',
            self::MensajeNuevo => 'Nuevo mensaje',
            self::AsesoriaCompletada => 'Asesoría completada',
            self::SesionRegistrada => 'Nueva sesión registrada',
        };
    }

    // Por ahora solo la campanita (no se manda ningún correo). Cuando se active el correo,
    // devuelve ['database', 'mail'] para los tipos importantes: solicitud aceptada, rechazada,
    // rechazada por cupo, asesoría cancelada y modificada.
    public function canales(): array
    {
        return ['database'];
    }
}