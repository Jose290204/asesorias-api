<?php

namespace App\Services;

use App\Enums\Rol;
use App\Models\Bitacora;
use App\Models\Facultad;
use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FacultadService
{

    public function __construct(private CierreDeSesiones $sesiones) {}

    // Crea la facultad (siempre activa). El logo es opcional.
    public function crear(array $datos, ?UploadedFile $logo): Facultad
    {
        return DB::transaction(function () use ($datos, $logo) {
            $facultad = Facultad::create([
                'nombre' => $datos['nombre'],
                'siglas' => $datos['siglas'],
                'logo' => $logo?->store('facultades', 'public'),
                'activa' => true,
            ]);

            Bitacora::registrar('facultad.creada', $facultad, [
                'etiqueta' => $facultad->nombre,
                'detalle' => "Se creó la facultad {$facultad->siglas}.",
            ], $facultad->id);

            return $facultad;
        });
    }

    // Logo: archivo = reemplaza, quitar_logo = lo borra, ninguno = se conserva
    public function actualizar(Facultad $facultad, array $datos, ?UploadedFile $logo, bool $quitarLogo): Facultad
    {
        return DB::transaction(function () use ($facultad, $datos, $logo, $quitarLogo) {
            $anterior = $facultad->logo;

            $nuevo = match (true) {
                $logo !== null => $logo->store('facultades', 'public'),
                $quitarLogo => null,
                default => $anterior,
            };

            $facultad->update([
                'nombre' => $datos['nombre'],
                'siglas' => $datos['siglas'],
                'logo' => $nuevo,
            ]);

            // El archivo viejo se borra al final, ya con la base actualizada
            if ($anterior && $anterior !== $nuevo) {
                Storage::disk('public')->delete($anterior);
            }

            Bitacora::registrar('facultad.editada', $facultad, [
                'etiqueta' => $facultad->nombre,
                'detalle' => "Se editó la facultad {$facultad->siglas}.",
            ], $facultad->id);

            return $facultad;
        });
    }

    // Si ya está en ese estado no hace nada (ni bitácora)
    public function cambiarEstado(Facultad $facultad, bool $activa): Facultad
    {
        if ($facultad->activa === $activa) {
            return $facultad;
        }

        return DB::transaction(function () use ($facultad, $activa) {
            $facultad->update(['activa' => $activa]);

            if (! $activa) {
                $this->cerrarSesiones($facultad);
            }

            Bitacora::registrar($activa ? 'facultad.activada' : 'facultad.desactivada', $facultad, [
                'etiqueta' => $facultad->nombre,
                'detalle' => $activa
                    ? "Se activó la facultad {$facultad->siglas}."
                    : "Se desactivó la facultad {$facultad->siglas}.",
            ], $facultad->id);

            return $facultad;
        });
    }

        // Revoca tokens y sesiones de los usuarios de la facultad (menos el super admin)
    private function cerrarSesiones(Facultad $facultad): void
    {
        $ids = Usuario::query()
            ->where('facultad_id', $facultad->id)
            ->whereDoesntHave('roles', fn ($q) => $q->where('rol_id', Rol::SuperAdmin->value))
            ->pluck('id');

        $this->sesiones->cerrar($ids);
    }
}