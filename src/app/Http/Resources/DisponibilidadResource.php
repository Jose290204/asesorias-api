<?php

namespace App\Http\Resources;

use App\Enums\Dia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

// Este Resource envuelve un Usuario (el asesor) y decide qué parte de su disponibilidad se muestra.
// Dentro, "$this" es el Usuario: $this->disponibilidadMaterias lee la relación que cargó el service.
class DisponibilidadResource extends JsonResource
{
    // toArray() define el JSON de salida; Laravel lo mete dentro de { "data": ... }
    public function toArray(Request $request): array
    {
        return [
            // 1. Materias: de cada fila de disponibilidad tomamos la Materia (pluck),
            //    las ordenamos por nombre (sortBy) y reiniciamos los índices (values)
            //    para que salga una lista JSON y no un objeto con llaves numéricas.
            //    Reutilizamos MateriaResource, así el formato es idéntico al de GET /materias.
            'materias' => MateriaResource::collection(
                $this->disponibilidadMaterias->pluck('materia')->sortBy('nombre')->values()
            ),

            // 2. Horarios agrupados por día (la lógica está en el método privado de abajo)
            'horarios' => $this->horariosPorDia(),

            // 3. Modalidades como {valor, etiqueta}, el mismo formato que GET /catalogos.
            //    $fila->modalidad ya es el enum Modalidad gracias al cast del modelo.
            'modalidades' => $this->disponibilidadModalidades
                ->sortBy('id')
                ->map(fn ($fila) => [
                    'valor' => $fila->modalidad->value,        // "virtual": lo que el front manda de regreso
                    'etiqueta' => $fila->modalidad->etiqueta(), // "Virtual": lo que se muestra
                ])
                ->values(),
        ];
    }

    // Convierte las filas sueltas (dia + horario) en una lista con los 5 días, cada uno con sus horarios
    private function horariosPorDia(): array
    {
        // groupBy: agrupa las filas por el valor del día. Queda así: ['lunes' => [fila, fila], 'martes' => [...]]
        // ->dia es el enum Dia (cast del modelo), por eso se usa ->value para tener el texto.
        $porDia = $this->disponibilidadHorarios->groupBy(fn ($fila) => $fila->dia->value);

        // Dia::cases() devuelve los 5 días en orden (lunes a viernes).
        // Recorrer los días del enum (y no los que existen en la BD) garantiza que SIEMPRE salgan los 5,
        // aunque el asesor no tenga horarios ese día: el front no tiene que preguntar si falta alguno.
        return collect(Dia::cases())
            ->map(fn (Dia $dia) => [
                'dia' => $dia->value,          // "miercoles": lo que el front manda en el PUT
                'etiqueta' => $dia->etiqueta(), // "Miércoles": lo que se muestra
                // get() devuelve null si ese día no tiene filas; en ese caso usamos una colección vacía
                'horarios' => ($porDia->get($dia->value) ?? new Collection)
                    ->sortBy('horario_id')      // ids crecientes = horas crecientes (así está sembrado)
                    ->map(fn ($fila) => [
                        'id' => $fila->horario->id,           // lo que el front manda en el PUT
                        'etiqueta' => $fila->horario->etiqueta, // "7:00-8:00 AM"
                    ])
                    ->values(),
            ])
            ->all(); // de Collection a arreglo normal de PHP
    }
}