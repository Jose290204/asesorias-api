<?php

namespace Database\Factories;

use App\Enums\EstatusUsuario;
use App\Enums\Rol;
use App\Models\Facultad;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'facultad_id' => fn () => self::facultad()->id,
            'estatus' => EstatusUsuario::Activo,
            'nombre' => fake()->firstName(),
            'apellido_paterno' => fake()->lastName(),
            'apellido_materno' => fake()->lastName(),
            'correo' => fake()->unique()->userName().'@fic.test',
            'password' => 'password',                 // el cast "hashed" la encripta
            'numero_cuenta' => fake()->unique()->numerify('2023####'),
            'num_cel' => fake()->numerify('667#######'),
        ];
    }

    // Reutiliza la facultad FIC si ya existe en la base de prueba; si no, la crea.
    // forceCreate evita depender del $fillable de Facultad.
    public static function facultad(string $siglas = 'FIC'): Facultad
    {
        return Facultad::query()->where('siglas', $siglas)->first()
            ?? Facultad::forceCreate([
                'nombre' => 'Facultad de Informática Culiacán',
                'siglas' => $siglas,
                'activa' => true,
            ]);
    }

    public function pendiente(): static
    {
        return $this->state(fn () => ['estatus' => EstatusUsuario::Pendiente, 'password' => null]);
    }

    // Uso: Usuario::factory()->conRol(Rol::Asesor)->create()
    public function conRol(Rol ...$roles): static
    {
        return $this->afterCreating(function (Usuario $usuario) use ($roles) {
            foreach ($roles as $rol) {
                $usuario->asignarRol($rol);
            }
        });
    }
}