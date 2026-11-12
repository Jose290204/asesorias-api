<?php

namespace App\Support;

use App\Enums\TipoNotificacion;
use App\Models\Asesoria;
use App\Models\Usuario;
use App\Notifications\AsesoriaNotificacion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Un solo lugar para disparar los avisos de asesorías y chat, igual que Bitacora::registrar(...):
// en cada servicio queda una línea.
class Avisos
{
    // Aviso de una asesoría. Siempre llega al estudiante; si quien actúa no es el asesor de la
    // asesoría (un admin en su lugar), también al asesor. Quien actúa nunca se avisa a sí mismo.
    // DB::afterCommit: dentro de una transacción espera al commit (no se avisa de algo revertido).
    public static function asesoria(Asesoria $asesoria, TipoNotificacion $tipo, ?string $detalle = null): void
    {
        // Copia propia: cargar relaciones en el modelo recibido cambiaría la respuesta de la API
    $asesoria = Asesoria::with(['materia', 'estudiante', 'asesor'])->findOrFail($asesoria->id);

        // [destinatario, rol con el que lo recibe, nombre de la otra parte]
        $destinos = [[$asesoria->estudiante, 'estudiante', $asesoria->asesor->nombreCompleto()]];

        if ((int) Auth::id() !== (int) $asesoria->asesor_id) {
            $destinos[] = [$asesoria->asesor, 'asesor', $asesoria->estudiante->nombreCompleto()];
        }

        foreach ($destinos as [$destino, $como, $persona]) {
            $aviso = new AsesoriaNotificacion($tipo, $asesoria->id, $asesoria->materia->nombre, $persona, $como, $detalle);

            DB::afterCommit(fn () => $destino->notify($aviso));
        }
    }

    // Mensaje de chat: aviso a la otra persona. Mientras no lo lea, los mensajes de la misma
    // conversación se juntan en UN aviso (se actualiza y sube al principio de la lista).
    // Va sin cola (notifyNow) para que la campanita se entere en seguida; no manda correo.
    public static function mensaje(Asesoria $asesoria, Usuario $autor, string $texto): void
    {
        $asesoria->loadMissing(['estudiante', 'asesor', 'materia']);

        $escribeElEstudiante = (int) $autor->id === (int) $asesoria->estudiante_id;
        $destino = $escribeElEstudiante ? $asesoria->asesor : $asesoria->estudiante;
        $como = $escribeElEstudiante ? 'asesor' : 'estudiante';

        $previo = $destino->unreadNotifications()
            ->where('type', TipoNotificacion::MensajeNuevo->value)
            ->where('data->asesoria_id', $asesoria->id)
            ->first();

        $aviso = new AsesoriaNotificacion(
            TipoNotificacion::MensajeNuevo,
            $asesoria->id,
            $asesoria->materia->nombre,
            $autor->nombreCompleto(),
            $como,
            Str::limit($texto, 80),
            (int) ($previo?->data['cuantos'] ?? 0) + 1,
        );

        if ($previo) {
            $previo->forceFill(['data' => $aviso->toDatabase($destino), 'created_at' => now()])->save();

            return;
        }

        $destino->notifyNow($aviso, ['database']);
    }
}