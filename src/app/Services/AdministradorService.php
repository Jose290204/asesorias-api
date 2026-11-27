<?php

namespace App\Services;

use App\Enums\AccionBitacora;
use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Models\Bitacora;
use App\Models\Facultad;
use App\Models\Usuario;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdministradorService
{
    public function __construct(private CierreDeSesiones $sesiones) {}

    // Sin paginar: son pocos administradores y la pantalla los muestra todos
    public function listar(array $filtros): Collection
    {
        return Usuario::administradores()
            ->when($filtros['facultad_id'] ?? null, fn ($q, $id) => $q->where('facultad_id', $id))
            ->when($filtros['estatus'] ?? null, fn ($q, $estatus) => $q->where('estatus', $estatus))
            ->when($filtros['buscar'] ?? null, function ($q, $texto) {
                $q->where(function ($g) use ($texto) {
                    $g->where('nombre', 'like', "%{$texto}%")
                        ->orWhere('apellido_paterno', 'like', "%{$texto}%")
                        ->orWhere('apellido_materno', 'like', "%{$texto}%")
                        ->orWhere('correo', 'like', "%{$texto}%");
                });
            })
            ->orderBy('nombre')
            ->orderBy('apellido_paterno')
            ->get();
    }

    // El super admin define la contraseña: la cuenta nace activa
    public function crear(array $datos): Usuario
    {
        return DB::transaction(function () use ($datos) {
            $admin = Usuario::create([
                'facultad_id' => $datos['facultad_id'],
                'estatus' => EstatusUsuario::Activo,
                'nombre' => $datos['nombre'],
                'apellido_paterno' => $datos['apellido_paterno'],
                'apellido_materno' => $datos['apellido_materno'] ?? null,
                'correo' => $datos['correo'],
                'password' => $datos['password'],
            ]);

            $admin->asignarRol(Rol::Admin);

            Bitacora::registrar(AccionBitacora::AdministradorCreado, $admin, [
                'etiqueta' => $this->nombreCompleto($admin),
                'detalle' => "Se creó al administrador {$admin->correo}.",
            ], $admin->facultad_id);

            return $admin;
        });
    }

    // Si cambia de facultad o de contraseña se cierran sus sesiones
    public function actualizar(Usuario $admin, array $datos): Usuario
    {
        return DB::transaction(function () use ($admin, $datos) {
            $facultadAnterior = $admin->facultad_id;
            $cambiaPassword = ! empty($datos['password']);

            $admin->fill(Arr::only($datos, [
                'facultad_id', 'nombre', 'apellido_paterno', 'apellido_materno', 'correo',
            ]));

            if ($cambiaPassword) {
                $admin->password = $datos['password'];
            }

            $movido = (int) $admin->facultad_id !== (int) $facultadAnterior;

            $admin->save();

            if ($movido || $cambiaPassword) {
                $this->sesiones->cerrar([$admin->id]);
            }

            // Nunca se guarda la contraseña en la bitácora: solo que se cambió
            $detalle = $movido
                ? 'Se movió al administrador a la facultad '.Facultad::find($admin->facultad_id)?->siglas.'.'
                : "Se editó al administrador {$admin->correo}.";

            if ($cambiaPassword) {
                $detalle .= ' Se cambió su contraseña.';
            }

            Bitacora::registrar($movido ? AccionBitacora::AdministradorMovido : AccionBitacora::AdministradorEditado, $admin, [
                'etiqueta' => $this->nombreCompleto($admin),
                'detalle' => $detalle,
            ], $admin->facultad_id);

            return $admin;
        });
    }

    // Si ya está en ese estado no hace nada (ni bitácora)
    public function cambiarEstado(Usuario $admin, EstatusUsuario $estatus): Usuario
    {
        if ($admin->estatus === $estatus) {
            return $admin;
        }

        return DB::transaction(function () use ($admin, $estatus) {
            $admin->update(['estatus' => $estatus]);

            $activo = $estatus === EstatusUsuario::Activo;

            if (! $activo) {
                $this->sesiones->cerrar([$admin->id]);
            }

            Bitacora::registrar($activo ? AccionBitacora::AdministradorActivado : AccionBitacora::AdministradorDesactivado, $admin, [
                'etiqueta' => $this->nombreCompleto($admin),
                'detalle' => $activo
                    ? "Se activó al administrador {$admin->correo}."
                    : "Se desactivó al administrador {$admin->correo}.",
            ], $admin->facultad_id);

            return $admin;
        });
    }

    // Borrado lógico: se conservan su historial y su correo queda reservado
    public function eliminar(Usuario $admin): void
    {
        DB::transaction(function () use ($admin) {
            $this->sesiones->cerrar([$admin->id]);

            // Se registra antes de borrar, mientras la entidad aún existe
            Bitacora::registrar(AccionBitacora::AdministradorEliminado, $admin, [
                'etiqueta' => $this->nombreCompleto($admin),
                'detalle' => "Se eliminó al administrador {$admin->correo}.",
            ], $admin->facultad_id);

            $admin->delete();
        });
    }

    private function nombreCompleto(Usuario $admin): string
    {
        return implode(' ', array_filter([$admin->nombre, $admin->apellido_paterno, $admin->apellido_materno]));
    }
}