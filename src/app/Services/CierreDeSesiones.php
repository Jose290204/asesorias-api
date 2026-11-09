<?php

namespace App\Services;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class CierreDeSesiones
{
    // Revoca tokens de app y sesiones web de los usuarios dados.
    // Aunque no se borraran, "activo" ya los rechazaría; esto cierra la sesión de verdad.
    public function cerrar(iterable $usuarioIds): void
    {
        $ids = collect($usuarioIds)->values()->all();

        if ($ids === []) {
            return;
        }

        DB::table('personal_access_tokens')
            ->where('tokenable_type', (new Usuario)->getMorphClass())
            ->whereIn('tokenable_id', $ids)
            ->delete();

        DB::table('sessions')->whereIn('user_id', $ids)->delete();
    }
}