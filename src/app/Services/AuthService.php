<?php

namespace App\Services;

use App\Enums\EstatusUsuario;
use App\Exceptions\ErrorDeNegocio;
use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    //devuelve el usuario si las credenciales son validad y la cuenta esta activa
    public function autenticar(string $correo, string $password): Usuario
    {
        //Busca por correo (es unico), Devuelve el usuario o es null
        //no lleva el scope por que en el login aun no hay nadie autenticado
        $usuario = Usuario::where('correo', $correo)->first();

        //aqui hay dos casos, el correo no existe o existe pero sin contraseña
        if(! $usuario || ! $usuario->password){
            //esto ayuda para que no se pueda saber en la respuesta del error que correos existen
            Hash::make($password);
            $valida = false;
        } else {
            //compara la contraseña que vino con la contraseña guardada en la bd con hash
            //check encripta lo escrito y compara los resultados
            $valida = Hash::check($password, $usuario->password);
        }

        //una solo mensaje por correo inexistente, sin contraseña o contraseña mala
        //si se distinguieran: cualquiera puede ver quien esta registrado y quien no
        if(! $valida){
            // throw corta aquí: lo que sigue no se ejecuta. Esta excepción se
            // convierte en una respuesta 422 y el front la pinta bajo el campo "correo".
            throw ValidationException::withMessages([
                'correo' => ['Correo o contraseña incorrectos.']
            ]);
        }

        //se revisa el estatus despues de la contraseña
        if($usuario->estatus !== EstatusUsuario::Activo){
            throw new ErrorDeNegocio('Tu cuenta no esta activa', 403, 'cuenta_inactiva');
        }

        //solo se llega aqui si existe, el correo existe, la contraseña es correcta y esta activa la cuenta
       return $usuario;
    }
}
