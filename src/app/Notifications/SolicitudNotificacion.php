<?php

namespace App\Notifications;

use App\Enums\TipoNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Avisos de solicitudes: el enum decide el título y los canales.
// ShouldQueue: la campanita (y el correo, cuando se active) los escribe el contenedor "queue",
// no la petición.
// Recibe datos sueltos y no el modelo Solicitud: el worker no tiene usuario logueado y el
// FacultadScope no encontraría la solicitud al reconstruirla.
class SolicitudNotificacion extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public TipoNotificacion $tipo,
        public int $solicitudId,
        public string $materia,
        public string $persona,       // quien actuó: el estudiante (aviso al asesor) o el asesor (aviso al estudiante)
        public string $fecha,         // ya formateada d/m/Y
        public ?string $motivo = null, // motivo del rechazo, o los campos que cambió el estudiante al editar
    ) {}

    public function via(object $notifiable): array
    {
        return $this->tipo->canales();
    }

    // En la columna "type" se guarda el código ('solicitud.nueva'), no el nombre de la clase:
    // así se puede renombrar la clase sin romper lo ya guardado ni al front.
    public function databaseType(object $notifiable): string
    {
        return $this->tipo->value;
    }

    // Lo que queda en la columna "data" (lo lee NotificacionResource)
    public function toDatabase(object $notifiable): array
    {
        return [
            'solicitud_id' => $this->solicitudId,
            'titulo' => $this->tipo->titulo(),
            'mensaje' => $this->mensaje(),
        ];
    }

    // Solo se usa cuando 'mail' está en canales() del tipo
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->tipo->titulo())
            ->greeting('Hola, '.$notifiable->nombre)
            ->line($this->mensaje())
            ->line('Entra a Asesorías FIC para ver los detalles.')
            ->salutation('Facultad de Informática Culiacán');
    }

    private function mensaje(): string
    {
        return match ($this->tipo) {
            TipoNotificacion::SolicitudNueva =>
                "{$this->persona} te pidió una asesoría de {$this->materia} para el {$this->fecha}.",
            TipoNotificacion::SolicitudAceptada =>
                "{$this->persona} aceptó tu solicitud de {$this->materia} para el {$this->fecha}.",
            TipoNotificacion::SolicitudRechazada =>
                "{$this->persona} rechazó tu solicitud de {$this->materia} para el {$this->fecha}."
                .($this->motivo ? " Motivo: {$this->motivo}" : ''),
            TipoNotificacion::SolicitudRechazadaPorCupo =>
                "Tu solicitud de {$this->materia} para el {$this->fecha} se rechazó porque el horario ya fue tomado por otra solicitud aceptada. Puedes pedir otro horario.",
            TipoNotificacion::SolicitudCancelada =>
                "{$this->persona} canceló su solicitud de {$this->materia} para el {$this->fecha}.",
            TipoNotificacion::SolicitudEditada =>
                "{$this->persona} modificó su solicitud de {$this->materia} (cambió {$this->motivo}); ahora es para el {$this->fecha}.",
            default => $this->tipo->titulo(),
        };
    }
}