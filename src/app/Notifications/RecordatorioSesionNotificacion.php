<?php

namespace App\Notifications;

use App\Enums\TipoNotificacion;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

// Recordatorio del día de una sesión. Se manda con notifyNow desde el comando programado
// (no va por la cola): así el registro de "ya avisé" existe en cuanto termina el comando y la
// siguiente corrida no lo repite. Recibe datos sueltos, no modelos, igual que las demás.
class RecordatorioSesionNotificacion extends Notification
{
    public const MANANA = 'manana'; // aviso de la mañana (9:30)
    public const PREVIO = 'previo'; // una hora antes de la sesión

    public function __construct(
        public int $asesoriaId,
        public string $materia,
        public string $persona,   // la otra parte de la asesoría
        public string $como,      // rol con el que lo recibe: 'estudiante' | 'asesor'
        public string $fecha,     // YYYY-MM-DD de la sesión (evita repetir el aviso)
        public string $hora,      // "1:00 PM"
        public string $momento,   // MANANA | PREVIO
    ) {}

    public function via(object $notifiable): array
    {
        return TipoNotificacion::SesionRecordatorio->canales();
    }

    public function databaseType(object $notifiable): string
    {
        return TipoNotificacion::SesionRecordatorio->value;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'asesoria_id' => $this->asesoriaId,
            'titulo' => TipoNotificacion::SesionRecordatorio->titulo(),
            'mensaje' => $this->mensaje(),
            'como' => $this->como,
            'fecha' => $this->fecha,
            'momento' => $this->momento,
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(TipoNotificacion::SesionRecordatorio->titulo())
            ->icon('/icons/icon-192.png')
            ->body($this->mensaje())
            ->tag('recordatorio-'.$this->asesoriaId)
            ->data([
                'tipo' => TipoNotificacion::SesionRecordatorio->value,
                'asesoria_id' => $this->asesoriaId,
                'como' => $this->como,
            ]);
    }

    private function mensaje(): string
    {
        // "a la 1:00 PM" pero "a las 2:00 PM"
        $a = str_starts_with($this->hora, '1:') ? 'a la' : 'a las';

        return $this->momento === self::PREVIO
            ? "En 1 hora tienes asesoría de {$this->materia} {$a} {$this->hora} con {$this->persona}."
            : "Hoy tienes asesoría de {$this->materia} {$a} {$this->hora} con {$this->persona}.";
    }
}
