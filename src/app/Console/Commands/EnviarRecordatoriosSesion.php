<?php

namespace App\Console\Commands;

use App\Services\RecordatorioSesionService;
use Illuminate\Console\Command;

class EnviarRecordatoriosSesion extends Command
{
    protected $signature = 'avisos:recordatorios-sesion';

    protected $description = 'Avisa al estudiante y al asesor de las sesiones de hoy (9:30 y 1 hora antes)';

    public function handle(RecordatorioSesionService $recordatorios): int
    {
        $this->info('Recordatorios enviados: '.$recordatorios->enviar());

        return self::SUCCESS;
    }
}
