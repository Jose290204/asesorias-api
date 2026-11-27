<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materias', function (Blueprint $table) {
            $table->id();
            // La facultad se deduce de la licenciatura
            $table->foreignId('licenciatura_id')->constrained('licenciaturas');
            // Número de semestre en que se cursa la materia
            $table->unsignedTinyInteger('semestre');
            $table->string('nombre', 150);
            $table->timestamps();

            // Una materia no se repite dentro de la misma licenciatura
            $table->unique(['licenciatura_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materias');
    }
};