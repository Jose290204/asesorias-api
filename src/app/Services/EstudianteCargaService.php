<?php

namespace App\Services;

use App\Models\Grupo;
use App\Models\Licenciatura;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class EstudianteCargaService
{
    // Cómo se llama cada licenciatura en la columna "grupo" de la plantilla (se busca dentro del nombre real)
    private const NOMBRES_CORTOS = [
        'INFORMATICA' => 'Informática',
        'CIENCIA DE DATOS' => 'Ciencia de Datos',
        'TELECOMUNICACIONES' => 'ITSE',
    ];

    public function __construct(private EstudianteService $estudiantes)
    {
    }

    // confirmar = false: solo valida. confirmar = true: crea las filas válidas.
    // Regresa los conteos y SOLO las filas con error: [{ fila, estudiante, errores: [] }]
    public function procesar(array $filas, bool $confirmar): array
    {
        $grupos = $this->mapaDeGrupos();
        $correosVistos = [];
        $cuentasVistas = [];
        $conError = [];
        $validas = 0;
        $creados = 0;

        foreach (array_values($filas) as $i => $fila) {
            $numero = $fila['fila'] ?? $i + 2;   // renglón de Excel (el 1 es el encabezado)
            $datos = $this->limpiar($fila, $grupos);
            $errores = $this->errores($datos);

            // Repetidos dentro del mismo archivo (la base solo ve los que ya existen en ella)
            foreach ([['correo', &$correosVistos, 'El correo'], ['numero_cuenta', &$cuentasVistas, 'El número de cuenta']] as [$campo, &$vistos, $texto]) {
                if ($datos[$campo] === '') {
                    continue;
                }
                if (isset($vistos[$datos[$campo]])) {
                    $errores[] = "{$texto} se repite en el renglón {$vistos[$datos[$campo]]}.";
                } else {
                    $vistos[$datos[$campo]] = $numero;
                }
            }

            if ($errores === [] && $confirmar) {
                try {
                    $this->estudiantes->crear($datos);
                    $creados++;
                } catch (Throwable $e) {
                    report($e);
                    $errores[] = 'No se pudo crear el alumno.';
                }
            }

            if ($errores === []) {
                $validas++;
            } else {
                $conError[] = [
                    'fila' => $numero,
                    'estudiante' => trim("{$datos['nombre']} {$datos['apellido_paterno']} {$datos['apellido_materno']}"),
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
    private function limpiar(array $fila, array $grupos): array
    {
        $texto = fn (string $c) => trim((string) ($fila[$c] ?? ''));

        return [
            'nombre' => $texto('nombre'),
            'apellido_paterno' => $texto('apellido_paterno'),
            'apellido_materno' => $texto('apellido_materno') ?: null,
            'correo' => Str::lower($texto('correo')),
            // El padrón trae guion (2327992-3); en la base va solo con dígitos
            'numero_cuenta' => preg_replace('/[\s-]/', '', $texto('numero_cuenta')),
            'grupo' => $texto('grupo'),
            'grupo_id' => $grupos[$this->clave($texto('grupo'))] ?? null,
            'password' => $texto('password'),
            'num_cel' => preg_replace('/[\s-]/', '', $texto('num_cel')) ?: null,
        ];
    }

    // Mismas reglas que GuardarEstudianteRequest (alta), sin la confirmación de contraseña
    private function errores(array $datos): array
    {
        $validador = Validator::make($datos, [
            'nombre' => ['required', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'apellido_paterno' => ['required', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'apellido_materno' => ['nullable', 'string', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'correo' => ['required', 'string', 'email', 'max:150', Rule::unique('usuarios', 'correo')],
            'numero_cuenta' => ['required', 'string', 'max:20', 'regex:/^\d+$/', Rule::unique('usuarios', 'numero_cuenta')],
            'num_cel' => ['nullable', 'regex:/^\d{10,15}$/'],
            'grupo_id' => ['required', 'integer'],
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
            'numero_cuenta.required' => 'El número de cuenta es obligatorio.',
            'numero_cuenta.max' => 'El número de cuenta no puede pasar de 20 dígitos.',
            'numero_cuenta.regex' => 'El número de cuenta solo puede tener dígitos.',
            'numero_cuenta.unique' => 'Ya existe un usuario con ese número de cuenta.',
            'num_cel.regex' => 'El teléfono debe tener entre 10 y 15 dígitos.',
            'grupo_id.required' => $datos['grupo'] === ''
                ? 'Elige un grupo.'
                : "El grupo \"{$datos['grupo']}\" no existe en esta facultad. Elige uno de la lista.",
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max' => 'La contraseña no puede pasar de 72 caracteres.',
        ]);

        return $validador->errors()->all();
    }

    // "1-1 Informática" => id del grupo. Se arma con los grupos reales de la facultad en contexto
    // (Licenciatura tiene el scope de facultad), así que otra facultad no puede usar los grupos de esta.
    private function mapaDeGrupos(): array
    {
        $licenciaturas = Licenciatura::all()->keyBy('id');
        $mapa = [];

        foreach (Grupo::whereIn('licenciatura_id', $licenciaturas->keys())->get() as $grupo) {
            $nombre = Str::upper(Str::ascii($licenciaturas[$grupo->licenciatura_id]->nombre));
            $corto = collect(self::NOMBRES_CORTOS)->first(
                fn ($valor, $llave) => str_contains($nombre, $llave),
                $licenciaturas[$grupo->licenciatura_id]->nombre
            );
            $etiqueta = "{$grupo->nombre} {$corto}".($grupo->modalidad === 'virtual' ? ' Virtual' : '');
            $mapa[$this->clave($etiqueta)] = $grupo->id;
        }

        return $mapa;
    }

    // Sin acentos, en minúsculas y con un solo espacio: "1-1  INFORMÁTICA virtual" => "1-1 informatica virtual"
    private function clave(string $texto): string
    {
        return Str::lower(Str::ascii(preg_replace('/\s+/', ' ', trim($texto))));
    }
}