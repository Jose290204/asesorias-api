<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UsuarioResource;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    //login recibe dos parametros
    // - Login Request: ejecuta las reglas de validacion antes de entrar al metodo
    public function login(LoginRequest $request, AuthService $auth)
    {
        //delega la decision. si algo falla el service lanza una excepcion y el metodo se corta aqui
        //se manda a autenticar el correo y password que llegaron
        $usuario = $auth->autenticar($request->correo, $request->password);

        //trae la facultad y los roles con una consulta cada una, para que el resource los incluya
        $usuario->load('facultad', 'roles');

        //camino en la app movil: si viene "dispositivo", se entrega un token bearer
        if($request->filled('dispositivo')){
            //crea un registro en personal_access_tokens con el nombre del dispositivo.
            //plainTextToken solo se puede leer ahora: en la bse queda unicamente su hash
            $token = $usuario->createToken($request->dispositivo)->plainTextToken;
            //additional() agrega "token" al lado de "data", no dentro, Respuesta:
            //{ "data": { ...usuario... }, "token": "12|abc..." }
            return (new UsuarioResource($usuario))->additional(['token' => $token]);   
        }

        //Camino de la web: sesion por cookie. login() guarda el id del usuario en la sesion
        Auth::guard('web')->login($usuario);

        //cambia el id de la sesion recien iniciada. Si un atacante habia plantado un id
        //conocido antes del login, deja de servirle(fijacion de sesion)
        $request->session()->regenerate();

        //sin token: el navegador recibe una cookie y la manda solo en cada peticion
        return new UsuarioResource($usuario);
    }

     //GET /auth/me devuelve el usuario de la sesion o el token actual
    public function me(Request $request)
    {
        //$requesty->user() ya se resolvio con sanctum (cookie o el token del movil)
        $usuario = $request->user(); 

        //la misma relacion del login, para que el resource devuelva la misma forma
        $usuario->load('facultad','roles');

        return new UsuarioResource($usuario);
    }

    //POST /auth/logout: cierra la sesion web o revoca el token de la app
    public function logout(Request $request): Response
    {
        if($request->bearerToken()){
            //App movil: borra solo el token con el que llego esta peticion.
            //Los tokens de otros dispositivos siguen vivoss
            $request->user()->currentAccessToken()->delete();
        } else {
            //web: saca el usuario de la sesion
            Auth::guard('web')->logout();
            //destruye los datos de la sesion y le da un id nuevo
            $request->session()->regenerateToken();
            // y renueva el token CSRF para que el anterior ya no sirva
            $request->session()->regenerateToken();
        }

        //204: exito sin cuerpo
        return response()->noContent();
    }

    
    
}