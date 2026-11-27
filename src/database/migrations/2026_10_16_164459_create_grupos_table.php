<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grupos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('licenciatura_id')->constrained('licenciaturas');
            // Ej. "4-1": año 4, grupo 1
            $table->string('nombre', 20);
            // Año que cursa el grupo (el número antes del guion). Cada año = 2 semestres
            $table->unsignedTinyInteger('anio');
            // presencial o virtual: Informática tiene grupos de ambos tipos
            $table->string('modalidad', 20)->default('presencial');
            $table->timestamps();

            // El mismo nombre puede existir una vez en presencial y otra en virtual
            $table->unique(['licenciatura_id', 'modalidad', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupos');
    }
};