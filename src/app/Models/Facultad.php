<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Facultad extends Model
{
    protected $table = 'facultades';

    protected $fillable = ['nombre', 'siglas', 'logo', 'activa'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }
}