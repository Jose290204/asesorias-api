<?php

namespace App\Services;

use App\Enums\AccionBitacora;
use App\Enums\Rol;
use App\Exceptions\ErrorDeNegocio;
use App\Models\Bitacora;
use App\Models\Usuario;
use App\Support\ContextoFacultad;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// "Usuarios conectados" del admin: junta las sesiones web (tabla sessions) y los tokens de la
// app (personal_access_tokens) de los usuarios de la facultad activa, y permite cerrarlos.
//
// Los dos tipos viven en tablas distintas, con ids que pueden chocar (uno es texto y el otro
// número), así que el id que sale hacia el front lleva prefijo: "web-<id de sesión>" y "app-<id del token>".
class ConexionService
{
    private const PREFIJO_WEB = 'web-';

    private const PREFIJO_APP = 'app-';

    // Columnas del usuario que se repiten en las dos consultas
    private const COLUMNAS_USUARIO = [
        'usuarios.id as usuario_id',
        'usuarios.nombre',
        'usuarios.apellido_paterno',
        'usuarios.apellido_materno',
        'usuarios.correo',
    ];

    // $sesionActual / $tokenActual: la conexión desde la que se hace esta petición (para marcar "es_actual")
    public function listar(?string $sesionActual = null, ?int $tokenActual = null): array
    {
        $web = $this->consultaWeb()->get();
        $app = $this->consultaApp()->get();

        $roles = $this->etiquetasDeRol($web->pluck('usuario_id')->merge($app->pluck('usuario_id'))->unique()->all());

        $filasWeb = $web->map(fn ($f) => $this->filaWeb($f, $roles, $sesionActual));
        $filasApp = $app->map(fn ($f) => $this->filaApp($f, $roles, $tokenActual));

        return $filasWeb->concat($filasApp)
            ->sortByDesc('ultima_actividad')
            ->values()
            ->all();
    }

    // $id llega con prefijo ("web-..." o "app-..."). No se puede cerrar la propia conexión ni la de un super admin.
    public function cerrar(string $id, ?string $sesionActual = null, ?int $tokenActual = null): void
    {
        [$fila, $dispositivo] = $this->buscar($id);

        $esPropia = str_starts_with($id, self::PREFIJO_WEB)
            ? $sesionActual !== null && substr($id, strlen(self::PREFIJO_WEB)) === $sesionActual
            : $tokenActual !== null && (int) substr($id, strlen(self::PREFIJO_APP)) === $tokenActual;

        if ($esPropia) {
            throw ErrorDeNegocio::conflicto('No puedes cerrar tu propia sesión desde aquí.', 'conexion_propia');
        }

        $usuario = Usuario::query()->with('roles')->findOrFail($fila->usuario_id);

        if ($usuario->esSuperAdmin()) {
            throw ErrorDeNegocio::conflicto('No se puede cerrar la sesión de un super administrador.', 'conexion_super_admin');
        }

        if (str_starts_with($id, self::PREFIJO_WEB)) {
            DB::table('sessions')->where('id', $fila->sid)->delete();
        } else {
            DB::table('personal_access_tokens')->where('id', $fila->tid)->delete();
        }

        Bitacora::registrar(AccionBitacora::ConexionCerrada, $usuario, [
            'etiqueta' => $usuario->nombreCompleto(),
            'detalle' => "Se cerró la sesión de {$usuario->correo} ({$dispositivo}).",
        ]);
    }

    // ---------- Consultas ----------

    // Sesiones web vigentes de usuarios de la facultad. Las filas vencidas se limpian por azar,
    // así que se descartan aquí las que ya pasaron del tiempo de vida de la sesión.
    private function consultaWeb(): Builder
    {
        $limite = now()->subMinutes((int) config('session.lifetime'))->timestamp;

        return $this->usuariosDeLaFacultad(DB::table('sessions')->join('usuarios', 'usuarios.id', '=', 'sessions.user_id'))
            ->where('sessions.last_activity', '>=', $limite)
            ->select(array_merge([
                'sessions.id as sid',
                'sessions.ip_address',
                'sessions.user_agent',
                'sessions.last_activity',
            ], self::COLUMNAS_USUARIO));
    }

    // Tokens de la app. Sanctum no guarda IP, y el nombre del token es el del dispositivo que mandó la app al iniciar sesión.
    private function consultaApp(): Builder
    {
        return $this->usuariosDeLaFacultad(
            DB::table('personal_access_tokens as t')
                ->join('usuarios', 'usuarios.id', '=', 't.tokenable_id')
                ->where('t.tokenable_type', (new Usuario)->getMorphClass())
        )->select(array_merge(['t.id as tid', 't.name', 't.last_used_at', 't.created_at'], self::COLUMNAS_USUARIO));
    }

    // Con DB::table no hay scopes: la facultad y las bajas se filtran a mano
    private function usuariosDeLaFacultad(Builder $consulta): Builder
    {
        return $consulta
            ->where('usuarios.facultad_id', app(ContextoFacultad::class)->id())
            ->whereNull('usuarios.deleted_at');
    }

    // Busca una conexión de la facultad por su id con prefijo. Devuelve [fila, texto del dispositivo]. 404 si no existe.
    private function buscar(string $id): array
    {
        if (str_starts_with($id, self::PREFIJO_WEB)) {
            $fila = $this->consultaWeb()->where('sessions.id', substr($id, strlen(self::PREFIJO_WEB)))->first();
            abort_unless($fila, 404);

            return [$fila, $this->describirNavegador($fila->user_agent)];
        }

        if (str_starts_with($id, self::PREFIJO_APP)) {
            $tokenId = substr($id, strlen(self::PREFIJO_APP));
            abort_unless(ctype_digit($tokenId), 404);

            $fila = $this->consultaApp()->where('t.id', (int) $tokenId)->first();
            abort_unless($fila, 404);

            return [$fila, 'App móvil'.($fila->name ? " ({$fila->name})" : '')];
        }

        abort(404);
    }

    // ---------- Armado de filas ----------

    private function filaWeb(object $f, array $roles, ?string $sesionActual): array
    {
        return [
            'id' => self::PREFIJO_WEB.$f->sid,
            'tipo' => 'web',
            'usuario_id' => (int) $f->usuario_id,
            'nombre' => $this->nombre($f),
            'correo' => $f->correo,
            'rol' => $roles[$f->usuario_id] ?? 'Usuario',
            'dispositivo' => $this->describirNavegador($f->user_agent),
            'ip' => $f->ip_address,
            // La tabla sessions no guarda cuándo empezó la sesión, solo la última actividad
            'inicio_sesion' => null,
            'ultima_actividad' => Carbon::createFromTimestamp($f->last_activity)->toIso8601String(),
            'es_actual' => $sesionActual !== null && $f->sid === $sesionActual,
        ];
    }

    private function filaApp(object $f, array $roles, ?int $tokenActual): array
    {
        return [
            'id' => self::PREFIJO_APP.$f->tid,
            'tipo' => 'app',
            'usuario_id' => (int) $f->usuario_id,
            'nombre' => $this->nombre($f),
            'correo' => $f->correo,
            'rol' => $roles[$f->usuario_id] ?? 'Usuario',
            'dispositivo' => 'App móvil'.($f->name ? " ({$f->name})" : ''),
            'ip' => null,
            'inicio_sesion' => Carbon::parse($f->created_at)->toIso8601String(),
            // Un token nunca usado todavía no tiene last_used_at: cuenta desde que se creó
            'ultima_actividad' => Carbon::parse($f->last_used_at ?? $f->created_at)->toIso8601String(),
            'es_actual' => $tokenActual !== null && (int) $f->tid === $tokenActual,
        ];
    }

    private function nombre(object $f): string
    {
        return implode(' ', array_filter([$f->nombre, $f->apellido_paterno, $f->apellido_materno]));
    }

    // Etiqueta de rol por usuario: [usuario_id => 'Asesor par', ...]. Un par es asesor y además estudiante.
    private function etiquetasDeRol(array $usuarioIds): array
    {
        if ($usuarioIds === []) {
            return [];
        }

        return DB::table('usuario_roles')
            ->whereIn('usuario_id', $usuarioIds)
            ->get(['usuario_id', 'rol_id'])
            ->groupBy('usuario_id')
            ->map(fn (Collection $filas) => $this->etiquetaRol($filas->pluck('rol_id')->map(fn ($r) => (int) $r)->all()))
            ->all();
    }

    private function etiquetaRol(array $rolIds): string
    {
        $tiene = fn (Rol $rol) => in_array($rol->value, $rolIds, true);

        return match (true) {
            $tiene(Rol::SuperAdmin) => 'Super administrador',
            $tiene(Rol::Admin) => 'Administrador',
            $tiene(Rol::Asesor) && $tiene(Rol::Estudiante) => 'Asesor par',
            $tiene(Rol::Asesor) => 'Asesor disciplinar',
            $tiene(Rol::Estudiante) => 'Estudiante',
            default => 'Usuario',
        };
    }

    // "Chrome en Windows" a partir del user agent. Sin librería: solo lo más común, y "Navegador" si no se reconoce.
    private function describirNavegador(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Navegador';
        }

        // El orden importa: Edge y Opera también dicen "Chrome", y Chrome también dice "Safari"
        $navegador = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        // Android e iPhone también dicen "Linux" o "Mac OS": van primero
        $sistema = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $navegador && $sistema => "{$navegador} en {$sistema}",
            (bool) $navegador => $navegador,
            (bool) $sistema => "Navegador en {$sistema}",
            default => 'Navegador',
        };
    }
}