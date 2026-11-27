<?php

namespace App\Notifications;

use App\Enums\TipoNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

// Avisos de una asesoría (y de su chat). Igual que SolicitudNotificacion: recibe datos sueltos,
// no modelos, porque el worker de la cola no tiene usuario logueado.
class AsesoriaNotificacion extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public TipoNotificacion $tipo,
        public int $asesoriaId,
        public string $materia,
        public string $persona,         // la otra parte de la asesoría (en el chat, quien escribió)
        public string $como,            // rol con el que lo recibe quien lo recibe: 'estudiante' | 'asesor'
        public ?string $detalle = null, // motivo, campos cambiados, archivos, vista previa del mensaje...
        public int $cuantos = 1,        // solo el chat: mensajes juntos en este aviso
    ) {}

    public function via(object $notifiable): array
    {
        return $this->tipo->canales();
    }

    public function databaseType(object $notifiable): string
    {
        return $this->tipo->value;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'asesoria_id' => $this->asesoriaId,
            'titulo' => $this->tipo->titulo(),
            'mensaje' => $this->mensaje(),
            'como' => $this->como,
            'cuantos' => $this->cuantos,
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

    // Aviso push al dispositivo. Mismo texto que la campana.
    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->tipo->titulo())
            ->icon('/icons/icon-192.png')
            ->body($this->mensaje())
            ->tag('asesoria-'.$this->asesoriaId) // avisos de la misma asesoría se reemplazan entre sí
                        ->data([
                'tipo' => $this->tipo->value,
                'asesoria_id' => $this->asesoriaId,
                'como' => $this->como,
            ]);
    }

    private function mensaje(): string
    {
        return match ($this->tipo) {
            TipoNotificacion::AsesoriaCreada =>
                "Se creó una asesoría de {$this->materia} con {$this->persona}.",
            TipoNotificacion::AsesoriaEditada =>
                "Se modificó tu asesoría de {$this->materia} con {$this->persona}"
                .($this->detalle ? ": cambió {$this->detalle}" : '').'.',
            TipoNotificacion::AsesoriaCancelada =>
                "Se canceló tu asesoría de {$this->materia} con {$this->persona}."
                .($this->detalle ? " Motivo: {$this->detalle}" : ''),
            TipoNotificacion::MaterialSubido =>
                "Se subió material a tu asesoría de {$this->materia} con {$this->persona}"
                .($this->detalle ? ": {$this->detalle}" : '').'.',
            TipoNotificacion::MensajeNuevo => $this->cuantos > 1
                ? "{$this->persona} te escribió {$this->cuantos} mensajes en tu asesoría de {$this->materia}."
                : "{$this->persona}: {$this->detalle}",
            TipoNotificacion::AsesoriaCompletada =>
                "Tu asesoría de {$this->materia} con {$this->persona} se dio por completada.",
            TipoNotificacion::SesionRegistrada =>
                "Se registró una sesión de tu asesoría de {$this->materia} con {$this->persona}"
                .($this->detalle ? " ({$this->detalle})" : '').'.',
            default => $this->tipo->titulo(),
        };
    }
}