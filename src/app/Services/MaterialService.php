<?php

namespace App\Services;

use App\Enums\EstatusAsesoria;
use App\Exceptions\ErrorDeNegocio;
use App\Models\Asesoria;
use App\Models\MaterialAdicional;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use App\Enums\AccionBitacora;
use App\Models\Bitacora;
use Illuminate\Support\Str;

class MaterialService
{
    // Material de una asesoría, el más reciente primero.
    // Sin paginar: son pocos archivos por asesoría y el front los muestra en una lista.
    public function listar(Asesoria $asesoria): Collection
    {
        return $asesoria->material()
            // El Resource muestra el nombre de quien lo subió: se carga de golpe
            ->with('usuario')
            ->orderByDesc('created_at')
            // El id desempata: archivos subidos en el mismo segundo salen en orden estable
            ->orderByDesc('id')
            ->get();
    }

    // El asesor asignado sube de 1 a 5 archivos (ya validados por SubirMaterialRequest).
    // $archivos es la lista de UploadedFile que sale de $request->file('archivos').
    // Se permite con la asesoría en curso o completada; cancelada no.
    public function subir(Asesoria $asesoria, Usuario $asesor, array $archivos): Collection
    {
        // Disco configurado en este momento (config/filesystems.php → 'material')
        $disco = config('filesystems.material');

        // Rutas ya escritas en disco, para limpiarlas si algo falla a la mitad
        $guardados = [];

        try {
            return DB::transaction(function () use ($asesoria, $asesor, $archivos, $disco, &$guardados) {
                // Relee la asesoría y la BLOQUEA hasta que termine la transacción: si en el mismo
                // instante se está cancelando, este método espera su turno y ve el estatus ya cambiado.
                $actual = Asesoria::whereKey($asesoria->id)->lockForUpdate()->firstOrFail();

                if ($actual->estatus === EstatusAsesoria::Cancelada) {
                    throw ErrorDeNegocio::conflicto('No se puede subir material a una asesoría cancelada.', 'asesoria_cancelada');
                }

                $creados = new Collection();

                foreach ($archivos as $archivo) {
                    // store() escribe el archivo con un nombre aleatorio (el original nunca es la ruta)
                    // dentro de una carpeta por asesoría. Devuelve la ruta, o false si el disco falló.
                    $ruta = $archivo->store("materiales/{$actual->id}", $disco);

                    if ($ruta === false) {
                        throw new RuntimeException('No se pudo guardar el archivo en el disco.');
                    }

                    $guardados[] = $ruta;

                    // create() desde la relación rellena asesoria_id solo
                    $creados->push($actual->material()->create([
                        'usuario_id' => $asesor->id,
                        // Nombre que verá el usuario al descargar (tope de la columna: 255)
                        'nombre_archivo' => mb_substr($archivo->getClientOriginalName(), 0, 255),
                        'ruta' => $ruta,
                        'disco' => $disco,
                        // Tipo detectado por el contenido, no el que declara el cliente
                        'mime_type' => $archivo->getMimeType(),
                        'tamano' => $archivo->getSize(),
                    ]));
                }

                // Relación que el Resource necesita para armar la respuesta
                $actual->loadMissing('materia');

                Bitacora::registrar(AccionBitacora::MaterialSubido, $actual, [
                    'etiqueta' => "Asesoría de {$actual->materia->nombre}",
                    'detalle' => 'Subió '.$creados->count().' archivo(s): '
                        .Str::limit($creados->pluck('nombre_archivo')->implode(', '), 150),
                ]);

                return $creados->load('usuario');
            });
        } catch (Throwable $e) {
            // La transacción ya se revirtió, pero los archivos escritos en disco no:
            // se borran para no dejar huérfanos que nadie referencia.
            if ($guardados !== []) {
                Storage::disk($disco)->delete($guardados);
            }

            throw $e;
        }
    }

    // Borra el registro y su archivo. El permiso (quien lo subió) lo decide la policy.
    // Se permite con la asesoría en curso o completada; cancelada no.
    public function eliminar(MaterialAdicional $material): void
    {
        DB::transaction(function () use ($material) {
            // Misma idea que al subir: se bloquea la asesoría para ver su estatus actual
            $asesoria = Asesoria::whereKey($material->asesoria_id)->lockForUpdate()->firstOrFail();

            if ($asesoria->estatus === EstatusAsesoria::Cancelada) {
                throw ErrorDeNegocio::conflicto('No se puede eliminar material de una asesoría cancelada.', 'asesoria_cancelada');
            }

            $asesoria->loadMissing('materia');

            Bitacora::registrar(AccionBitacora::MaterialEliminado, $asesoria, [
                'etiqueta' => "Asesoría de {$asesoria->materia->nombre}",
                'detalle' => "Eliminó el archivo {$material->nombre_archivo}.",
            ]);

            $material->delete();
        });

        // El archivo se borra DESPUÉS de confirmar el borrado del registro. Si este paso fallara
        // solo quedaría un archivo suelto (inofensivo); al revés quedaría un registro que apunta
        // a un archivo que ya no existe (un error visible para el usuario).
        Storage::disk($material->disco)->delete($material->ruta);
    }

    // Devuelve el archivo para descargar con su nombre original.
    // Lee del disco que guardó la fila (no del actual): los archivos viejos siguen
    // funcionando aunque después se cambie MATERIAL_DISK.
    public function descargar(MaterialAdicional $material): StreamedResponse
    {
        $disco = Storage::disk($material->disco);

        // El registro existe pero el archivo ya no está en el disco
        if (! $disco->exists($material->ruta)) {
            throw new ErrorDeNegocio('El archivo ya no está disponible.', 404, 'archivo_no_encontrado');
        }

        return $disco->download($material->ruta, $material->nombre_archivo);
    }
}