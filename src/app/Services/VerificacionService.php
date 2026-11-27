<?php

namespace App\Services;

use App\Enums\TipoVerificacion;
use App\Models\Usuario;
use App\Models\Verificacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Exists;

class VerificacionService
{
   
   private const VIGENCIA_MINUTOS = 10;
   private const MAX_INTENTOS = 5;
   private const ESPERA_SEGUNDOS = 60;

   //crea un codigo nuevo y devuelve el texto plano (los 6 digitos) para mandarlo por correo
   //devuelve null si el ultimo codigo se creo hace menos de 60s(reenvio demasiado pronto)
   public function generar(Usuario $usuario, TipoVerificacion $tipo): ?string
   {

     $reciente = Verificacion::where('usuario_id', $usuario->id)
        ->where('tipo', $tipo->value)
        ->where('created_at', '>', now()->subSeconds(self::ESPERA_SEGUNDOS))
        ->exists();

        if($reciente){
            return null;
        }

        //random_int sirve para y seguro criptograficamente, a comparacion de rand() que no lo es
        //str_pad rellena con ceros: 42 -> "000042", para que siempre sean 6 digitos
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        //TRANSACCION: o se borran los viejos y se crea el nuevo o no pasa nada
        DB::transaction(function () use ($usuario, $tipo, $codigo) {
            //Borra los codigos anteriores sin usar de este usuario y tipo
            Verificacion::where('usuario_id', $usuario->id)
                ->where('tipo', $tipo->value)
                ->whereNull('usado_en')
                ->delete();

            Verificacion::create([
                'usuario_id' => $usuario->id,
                'tipo' => $tipo,
                'destino' => $usuario->correo,
                //en la bd queda solo el hash, si alguien lee la tablal no ve los codigos 
                'codigo' => Hash::make($codigo),
                'expira_en' => now()->addMinutes(self::VIGENCIA_MINUTOS)
            ]);
        });

        return $codigo;
   }

   //comprueba el codigo. Con $consumir = true lo marca como usado
   //devuelve true o false, no da excepciones para que el aumneto de intentos no se revierta

   public function validar(Usuario $usuario, TipoVerificacion $tipo, string $codigo, bool $consumir = false): bool
   {
        return DB::transaction(function () use ($usuario, $tipo, $codigo, $consumir) {
            //lockForUpdate bloquea la fila, si llegan dos peticiones a la vez con el mismo
            //codigo, la segunda espera a que termine la primera y ya lo ve como usado
            $verificacion = Verificacion::where('usuario_id', $usuario->id)
                ->where('tipo', $tipo->value)
                ->whereNull('usado_en')
                ->where('expira_en', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            //sin codigo vigente, o ya se agotaron los intentos
            if(! $verificacion || $verificacion->intentos >= self::MAX_INTENTOS){
                return false;
            }

            if(! Hash::check($codigo, $verificacion->codigo)){
                //increment hace UPDATE intentos = intentos + 1 directo en la base
                $verificacion->increment('intentos');

                return false;
            }

            if($consumir){
                $verificacion->update(['usado_en' => now()]);
            }

            return true;
        });
   }
}