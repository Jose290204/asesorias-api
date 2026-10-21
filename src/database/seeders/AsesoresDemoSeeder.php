<?php

namespace Database\Seeders;

use App\Enums\Dia;
use App\Enums\EstatusUsuario;
use App\Enums\Modalidad;
use App\Enums\Rol;
use App\Models\DisponibilidadHorario;
use App\Models\DisponibilidadMateria;
use App\Models\DisponibilidadModalidad;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

// Asesores de prueba adicionales, con disponibilidad variada en materias,
// horarios y modalidades. No toca a los que ya crea UsuarioSeeder.
class AsesoresDemoSeeder extends Seeder
{
    private const FACULTAD_ID = 1;

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('AsesoresDemoSeeder omitido: solo corre en entorno local.');

            return;
        }

        $asesores = [
            [
                'correo' => 'asesor.bd@fic.test',
                'nombre' => 'Patricia',
                'apellido_paterno' => 'Rentería',
                'apellido_materno' => 'Beltrán',
                'num_cel' => '6671000010',
                'materias' => [53, 65, 87],  // Bases de Datos, Bases de Datos Emergentes, Gestión de Seguridad... BD
                'horarios' => [
                    Dia::Lunes->value => [5, 6],       // 11:00 AM - 1:00 PM
                    Dia::Jueves->value => [5, 6],
                ],
                'modalidades' => [Modalidad::Virtual],
            ],
            [
                'correo' => 'asesor.ia@fic.test',
                'nombre' => 'Jorge Luis',
                'apellido_paterno' => 'Camacho',
                'apellido_materno' => 'Verdugo',
                'num_cel' => '6671000011',
                'materias' => [72, 78, 71], // Fund. de IA, Aprendizaje Automático, Minería de Datos
                'horarios' => [
                    Dia::Martes->value => [13, 14],    // 7:00 - 9:00 PM
                    Dia::Jueves->value => [13, 14],
                ],
                'modalidades' => [Modalidad::Virtual, Modalidad::Presencial],
            ],
            [
                'correo' => 'asesor.redes@fic.test',
                'nombre' => 'Mariana',
                'apellido_paterno' => 'Gaxiola',
                'apellido_materno' => 'Leyva',
                'num_cel' => '6671000012',
                'materias' => [49, 55, 61], // Fund. de Redes, Redes de Computadoras, Administración de Redes
                'horarios' => [
                    Dia::Lunes->value => [1, 2],       // 7:00 - 9:00 AM
                    Dia::Miercoles->value => [1, 2],
                    Dia::Viernes->value => [1],
                ],
                'modalidades' => [Modalidad::Presencial],
            ],
            [
                'correo' => 'asesor.calculo@fic.test',
                'nombre' => 'Héctor',
                'apellido_paterno' => 'Avendaño',
                'apellido_materno' => 'Quintero',
                'num_cel' => '6671000013',
                'materias' => [54, 60, 66], // Álgebra Lineal, Matemáticas Aplicadas, Probabilidad y Estadística
                'horarios' => [
                    Dia::Martes->value => [7, 8],      // 1:00 - 3:00 PM
                    Dia::Jueves->value => [7, 8],
                ],
                'modalidades' => [Modalidad::Presencial, Modalidad::Virtual],
            ],
            [
                'correo' => 'asesor.software@fic.test',
                'nombre' => 'Valeria',
                'apellido_paterno' => 'Moroyoqui',
                'apellido_materno' => 'Félix',
                'num_cel' => '6671000014',
                'materias' => [62, 74, 79], // Análisis y Diseño de Software, Ingeniería de Software, Modelos de Calidad
                'horarios' => [
                    Dia::Lunes->value => [10, 11],     // 4:00 - 6:00 PM
                    Dia::Viernes->value => [10, 11],
                ],
                'modalidades' => [Modalidad::Virtual],
            ],
            [
                'correo' => 'asesor.movil@fic.test',
                'nombre' => 'Ricardo',
                'apellido_paterno' => 'Tirado',
                'apellido_materno' => 'Osuna',
                'num_cel' => '6671000015',
                'materias' => [80, 86, 85], // Cómputo Móvil, Prog. Paralela y Distribuida, Comercio Electrónico
                'horarios' => [
                    Dia::Miercoles->value => [15, 16], // 9:00 - 11:00 PM
                    Dia::Viernes->value => [15, 16],
                ],
                'modalidades' => [Modalidad::Presencial],
            ],
        ];

        foreach ($asesores as $datos) {
            $materias = $datos['materias'];
            $horarios = $datos['horarios'];
            $modalidades = $datos['modalidades'];
            unset($datos['materias'], $datos['horarios'], $datos['modalidades']);

            $datos['facultad_id'] = self::FACULTAD_ID;
            $datos['numero_cuenta'] = null;
            $datos['grupo_id'] = null;
            $datos['promedio'] = null;
            $datos['estatus'] = EstatusUsuario::Activo;
            $datos['password'] = 'password';

            $asesor = Usuario::updateOrCreate(['correo' => $datos['correo']], $datos);
            $asesor->asignarRol(Rol::Asesor);

            foreach ($materias as $materiaId) {
                DisponibilidadMateria::firstOrCreate([
                    'facultad_id' => self::FACULTAD_ID,
                    'asesor_id' => $asesor->id,
                    'materia_id' => $materiaId,
                ]);
            }

            foreach ($horarios as $dia => $horarioIds) {
                foreach ($horarioIds as $horarioId) {
                    DisponibilidadHorario::firstOrCreate([
                        'facultad_id' => self::FACULTAD_ID,
                        'asesor_id' => $asesor->id,
                        'dia' => $dia,
                        'horario_id' => $horarioId,
                    ]);
                }
            }

            foreach ($modalidades as $modalidad) {
                DisponibilidadModalidad::firstOrCreate([
                    'facultad_id' => self::FACULTAD_ID,
                    'asesor_id' => $asesor->id,
                    'modalidad' => $modalidad->value,
                ]);
            }

            $this->command?->info("Asesor demo creado: {$datos['correo']}");
        }
    }
}