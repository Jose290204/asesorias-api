<?php

// Los services viven en App\Services (igual que EstadisticasService)
namespace App\Services;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class DisponibilidadService
{
    //recube el asesor y lo devuelve con toda su disponibilidad ya cargada
    //devolver el mismo usuario dej el resource funcione
   public function obtener(Usuario $asesor): Usuario
   {
        //load()trae las relaciomes de golpe(eager loading) en vez de una cosulta
        //este punto encadena niveles: disponibiidadMaterias -> su materia -> su licenciatura
        return $asesor->load([
            'disponibilidadMaterias.materia.licenciatura', //para mostrar "licenciatura" en cada materia
            'disponibilidadHorarios.horario',
            'disponibilidadModalidades'
        ]);
   }

   public function reemplazar(Usuario $asesor, array $datos): Usuario
   {
    //DB::transaction: todo lo de dentro que se guarda junto o no se guarda nada
    //use (...) pasa variable de afuera de la funcion anonima
        DB::transaction(function () use ($asesor, $datos) {
            //bloquea la fila de este usuario hasta que termine la transaccion
            //si el front manda dos put casi a la vez por un doble clic, el segundo espera al primero
            Usuario::whereKey($asesor->id)->lockForUpdate()->first();

            // borrar todo lo anterior del user, por la relacion del modelo borra solo las filas del asesor
            $asesor->disponibilidadMaterias()->delete();
            $asesor->disponibilidadHorarios()->delete();
            $asesor->disponibilidadModalidades()->delete();

            //guarda lo nuevo. createMany por la relacion rellena asesor_id solo
            //facultad_id va explicito (viene del asesor)
            //collect()->map() convierte cada valor en un arreglo de columnas: all() lo hace arreglo de php
            $asesor->disponibilidadMaterias()->createMany(
                collect($datos['materias'])
                    ->map(fn ($id) => ['facultad_id' => $asesor->facultad_id, 'materia_id' => $id])
                    ->all()
            );

            $asesor->disponibilidadHorarios()->createMany(
                collect($datos['horarios'])
                    ->map(fn ($h) => [
                        'facultad_id' => $asesor->facultad_id,
                        'dia' => $h['dia'],//el cast del modelo lo convierte al enum
                        'horario_id' => $h['horario_id'],
                    ])
                    ->all()
            );

            $asesor->disponibilidadModalidades()->createMany(
                collect($datos['modalidades'])
                    ->map(fn ($m) => ['facultad_id' => $asesor->facultad_id, 'modalidad' => $m])
                    ->all()
            );
        });

        //con la transaccion ya terminada, devuelve el estado nuevo (load vuelve a consultar todo)
        return $this->obtener($asesor);
   }
}