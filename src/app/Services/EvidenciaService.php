<?php

namespace App\Services;

use App\Enums\AccionBitacora;
use App\Enums\EstatusAsesoria;
use App\Exceptions\ErrorDeNegocio;
use App\Models\Asesoria;
use App\Models\Bitacora;
use App\Models\EvidenciaSesion;
use App\Models\Sesion;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EvidenciaService
{
    // Sube de 1 a 5 archivos como evidencia de una sesión (ya validados por SubirEvidenciasRequest).
    // Solo con la asesoría en curso, igual que registrar o eliminar sesiones.
    public function subir(Sesion $sesion, Usuario $usuario, array $archivos): Collection
    {
        $disco = config('filesystems.material');
        $guardados = [];

        try {
            return DB::transaction(function () use ($sesion, $usuario, $archivos, $disco, &$guardados) {
                // Se bloquea la asesoría: si en ese instante se completa o cancela, se ve el estatus ya cambiado
                $asesoria = Asesoria::whereKey($sesion->asesoria_id)->lockForUpdate()->firstOrFail();

                if ($asesoria->estatus !== EstatusAsesoria::EnCurso) {
                    throw ErrorDeNegocio::conflicto(
                        'Solo se pueden agregar evidencias en asesorías en curso.',
                        'asesoria_no_en_curso'
                    );
                }

                $creadas = new Collection();

                foreach ($archivos as $archivo) {
                    $ruta = $archivo->store("evidencias/{$asesoria->id}/{$sesion->id}", $disco);

                    if ($ruta === false) {
                        throw new RuntimeException('No se pudo guardar el archivo en el disco.');
                    }

                    $guardados[] = $ruta;

                    $creadas->push($sesion->evidencias()->create([
                        'usuario_id' => $usuario->id,
                        'nombre_archivo' => mb_substr($archivo->getClientOriginalName(), 0, 255),
                        'ruta' => $ruta,
                        'disco' => $disco,
                        'mime_type' => $archivo->getMimeType(),
                        'tamano' => $archivo->getSize(),
                    ]));
                }

                $asesoria->loadMissing('materia');

                Bitacora::registrar(AccionBitacora::EvidenciaSubida, $asesoria, [
                    'etiqueta' => "Asesoría de {$asesoria->materia->nombre}",
                    'detalle' => "Subió {$creadas->count()} evidencia(s) a la sesión del {$sesion->fecha->format('d/m/Y')}.",
                ]);

                return $creadas;
            });
        } catch (Throwable $e) {
            // La transacción se revirtió, pero los archivos ya escritos no: se borran para no dejar huérfanos
            if ($guardados !== []) {
                Storage::disk($disco)->delete($guardados);
            }

            throw $e;
        }
    }

    // Borra el registro y su archivo. El permiso lo decide la policy.
    public function eliminar(EvidenciaSesion $evidencia): void
    {
        DB::transaction(function () use ($evidencia) {
            $sesion = $evidencia->sesion;
            $asesoria = Asesoria::whereKey($sesion->asesoria_id)->lockForUpdate()->firstOrFail();

            if ($asesoria->estatus !== EstatusAsesoria::EnCurso) {
                throw ErrorDeNegocio::conflicto(
                    'Solo se pueden eliminar evidencias de asesorías en curso.',
                    'asesoria_no_en_curso'
                );
            }

            $asesoria->loadMissing('materia');

            Bitacora::registrar(AccionBitacora::EvidenciaEliminada, $asesoria, [
                'etiqueta' => "Asesoría de {$asesoria->materia->nombre}",
                'detalle' => "Eliminó la evidencia {$evidencia->nombre_archivo}.",
            ]);

            $evidencia->delete();
        });

        // Primero el registro y después el archivo: si esto fallara solo quedaría un archivo suelto (inofensivo)
        Storage::disk($evidencia->disco)->delete($evidencia->ruta);
    }

    // Devuelve el archivo con su nombre original, leyéndolo del disco que guardó la fila
    public function descargar(EvidenciaSesion $evidencia): StreamedResponse
    {
        $disco = Storage::disk($evidencia->disco);

        if (! $disco->exists($evidencia->ruta)) {
            throw new ErrorDeNegocio('El archivo ya no está disponible.', 404, 'archivo_no_encontrado');
        }

        return $disco->download($evidencia->ruta, $evidencia->nombre_archivo);
    }
}