<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAFacultad;
use Illuminate\Database\Eloquent\Model;

class PlanEstudios extends Model
{
    use PerteneceAFacultad;

    // Se indica la tabla porque Laravel podría pluralizar el nombre distinto
    protected $table = 'plan_estudios';

    protected $fillable = ['facultad_id', 'nombre'];
}