<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class AsesorCargaService
{
    public function __construct(private AsesorAdminService $asesores)
    {
    }

    // confirmar = false: solo valida. confirmar = true: crea las filas válidas.
    // Regresa los conteos y SOLO las filas con error: [{ fila, asesor, errores: [] }]
    public function procesar(array $filas, bool $confirmar): array
    {
        $correosVistos = [];
        $cuentasVistas = [];
        $conError = [];
        $validas = 0;
        $creados = 0;

        foreach (array_values($filas) as $i => $fila) {
            $numero = $fila['fila'] ?? $i + 2;   // renglón de Excel (el 1 es el encabezado)
            $datos = $this->limpiar($fila);
            $errores = $this->errores($datos);

            // Repetidos dentro del mismo archivo (la base solo ve los que ya existen en ella)
            if ($datos['correo'] !== '') {
                if (isset($correosVistos[$datos['correo']])) {
                    $errores[] = "El correo se repite en el renglón {$correosVistos[$datos['correo']]}.";
                } else {
                    $correosVistos[$datos['correo']] = $numero;
                }
            }

            // La cuenta es opcional: varios asesores sin cuenta no cuentan como repetidos
            if ($datos['numero_cuenta'] !== null) {
                if (isset($cuentasVistas[$datos['numero_cuenta']])) {
                    $errores[] = "El número de cuenta se repite en el renglón {$cuentasVistas[$datos['numero_cuenta']]}.";
                } else {
                    $cuentasVistas[$datos['numero_cuenta']] = $numero;
                }
            }

            if ($errores === [] && $confirmar) {
                try {
                    $this->asesores->crear($datos);
                    $creados++;
                } catch (Throwable $e) {
                    report($e);
                    $errores[] = 'No se pudo crear el asesor.';
                }
            }

            if ($errores === []) {
                $validas++;
            } else {
                $conError[] = [
                    'fila' => $numero,
                    'asesor' => trim("{$datos['nombre']} {$datos['apellido_paterno']} {$datos['apellido_materno']}"),
                    'errores' => $errores,
                ];
            }
        }

        return [
            'total' => count($filas),
            'validas' => $validas,
            'con_errores' => count($conError),
            'creados' => $creados,
            'filas' => $conError,
        ];
    }

    // Normaliza una fila del Excel (todo llega como texto)
    private function limpiar(array $fila): array
    {
        $texto = fn (string $c) => trim((string) ($fila[$c] ?? ''));

        return [
            'nombre' => $texto('nombre'),
            'apellido_paterno' => $texto('apellido_paterno'),
            'apellido_materno' => $texto('apellido_materno') ?: null,
            'correo' => Str::lower($texto('correo')),
            // Solo se quitan espacios y guiones: las letras deben fallar la validación
            'numero_cuenta' => preg_replace('/[\s-]/', '', $texto('numero_cuenta')) ?: null,
            'num_cel' => preg_replace('/[\s-]/', '', $texto('num_cel')) ?: null,
            'password' => $texto('password'),
        ];
    }

    // Mismas reglas que GuardarAsesorRequest (alta), sin la confirmación de contraseña
    private function errores(array $datos): array
    {
        $validador = Validator::make($datos, [
            'nombre' => ['required', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'apellido_paterno' => ['required', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'apellido_materno' => ['nullable', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'correo' => ['required', 'string', 'email', 'max:150', Rule::unique('usuarios', 'correo')],
            'numero_cuenta' => ['nullable', 'string', 'max:20', 'regex:/^\d+$/', Rule::unique('usuarios', 'numero_cuenta')],
            'num_cel' => ['nullable', 'regex:/^\d{10,15}$/'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
        ], [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max' => 'El nombre no puede pasar de 60 caracteres.',
            'nombre.regex' => 'El nombre solo puede tener letras.',
            'apellido_paterno.required' => 'El apellido paterno es obligatorio.',
            'apellido_paterno.max' => 'El apellido paterno no puede pasar de 60 caracteres.',
            'apellido_paterno.regex' => 'El apellido paterno solo puede tener letras.',
            'apellido_materno.max' => 'El apellido materno no puede pasar de 60 caracteres.',
            'apellido_materno.regex' => 'El apellido materno solo puede tener letras.',
            'correo.required' => 'El correo es obligatorio.',
            'correo.email' => 'Escribe un correo válido.',
            'correo.max' => 'El correo es demasiado largo.',
            'correo.unique' => 'Ya existe un usuario con ese correo.',
            'numero_cuenta.max' => 'El número de cuenta no puede pasar de 20 dígitos.',
            'numero_cuenta.regex' => 'El número de cuenta solo puede tener dígitos.',
            'numero_cuenta.unique' => 'Ya existe un usuario con ese número de cuenta.',
            'num_cel.regex' => 'El teléfono debe tener entre 10 y 15 dígitos.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max' => 'La contraseña no puede pasar de 72 caracteres.',
        ]);

        return $validador->errors()->all();
    }
}
