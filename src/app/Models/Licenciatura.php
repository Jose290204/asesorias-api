<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAFacultad;
use Illuminate\Database\Eloquent\Model;

class Licenciatura extends Model
{
    use PerteneceAFacultad;

    protected $table = 'licenciaturas';

    protected $fillable = ['facultad_id', 'nombre'];
}