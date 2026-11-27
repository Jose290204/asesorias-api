<?php

namespace App\Support;

// Convierte la etiqueta de un horario ("11:00-12:00 PM") en horas de 24 h ("11:00", "12:00").
// Los horarios son bloques de una hora; "12:00 AM" al final es la medianoche.
class HorasDeHorario
{
    /** @return array{0: string, 1: string} [inicio, fin] en "HH:MM" */
    public static function desde(string $etiqueta): array
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})\s*(AM|PM)$/i', trim($etiqueta), $m)) {
            return ['00:00', '00:00'];
        }

        [, $h1, $m1, $h2, $m2, $meridiano] = $m;

        $fin = (((int) $h2 % 12) + (strtoupper($meridiano) === 'PM' ? 12 : 0)) * 60 + (int) $m2;
        if ($fin === 0) {
            $fin = 1440; // 12:00 AM = medianoche
        }

        // El inicio es AM o PM: se toma el más tarde que siga siendo anterior al fin
        $base = ((int) $h1 % 12) * 60 + (int) $m1;
        $inicio = $base + 720 < $fin ? $base + 720 : $base;

        return [self::formato($inicio), self::formato($fin)];
    }

    private static function formato(int $minutos): string
    {
        $minutos %= 1440;

        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }
}
