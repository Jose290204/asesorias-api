<?php

namespace Database\Seeders;

use App\Enums\Dia;
use App\Enums\Modalidad;
use App\Models\DisponibilidadHorario;
use App\Models\DisponibilidadMateria;
use App\Models\DisponibilidadModalidad;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

class DisponibilidadSeeder extends Seeder
{
    // Facultad FIC (la misma que usa UsuarioSeeder)
    private const FACULTAD_ID = 1;

    public function run(): void
    {
        // Datos de ejemplo solo para desarrollo, igual que UsuarioSeeder
        if (! app()->environment('local')) {
            $this->command?->warn('DisponibilidadSeeder omitido: solo corre en entorno local.');

            return;
        }

        // Busca al asesor de prueba por su correo (lo creó UsuarioSeeder)
        $asesor = Usuario::where('correo', 'asesor@fic.test')->first();

        // Si no existe, avisa y se detiene en lugar de fallar con un error confuso
        if (! $asesor) {
            $this->command?->warn('No existe asesor@fic.test: corre UsuarioSeeder primero.');

            return;
        }

        // Ids de materias.csv, todas de Informática (licenciatura 1)
        $materias = [
            58, // Estructura de Datos
            59, // Manejadores y Lenguajes de Consulta de Datos
            63, // Desarrollo Web del Lado del Servidor
            69, // Desarrollo Web del Lado Cliente
        ];

        // día => ids de horarios (HorarioSeeder: 1 = 7:00-8:00 AM, 2 = 8:00-9:00 AM, 3 = 9:00-10:00 AM...)
        // Se usa ->value porque en la BD el día se guarda como texto ("lunes")
        $horarios = [
            Dia::Lunes->value => [1, 2],      // lunes 7:00 a 9:00 AM
            Dia::Miercoles->value => [3, 4],  // miércoles 9:00 a 11:00 AM
            Dia::Viernes->value => [12],      // viernes 6:00-7:00 PM
        ];

        // Las dos modalidades, para probar que salen las dos en el JSON
        $modalidades = [Modalidad::Presencial, Modalidad::Virtual];

        // Materias: una fila por materia
        foreach ($materias as $materiaId) {
            // firstOrCreate: busca una fila con estos datos y, si no existe, la crea.
            // Así repetir el seeder no duplica nada (y la llave única de la tabla no se queja).
            // facultad_id va explícito: sin usuario logueado (seeder), el trait no lo rellena solo.
            DisponibilidadMateria::firstOrCreate([
                'facultad_id' => self::FACULTAD_ID,
                'asesor_id' => $asesor->id,
                'materia_id' => $materiaId,
            ]);
        }

        // Horarios: una fila por cada combinación día + horario
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

        // Modalidades: una fila por modalidad
        foreach ($modalidades as $modalidad) {
            DisponibilidadModalidad::firstOrCreate([
                'facultad_id' => self::FACULTAD_ID,
                'asesor_id' => $asesor->id,
                'modalidad' => $modalidad->value,
            ]);
        }
    }
}
