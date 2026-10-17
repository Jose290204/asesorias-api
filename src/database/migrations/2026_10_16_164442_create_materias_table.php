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
            $table->foreignId('licenciatura_id')->constrained('licenciaturas');
            $table->foreignId('plan_estudio_id')->constrained('plan_estudios');
            // Número de semestre en que se cursa la materia
            $table->unsignedTinyInteger('semestre');
            $table->string('nombre', 150);
            $table->timestamps();

            // Índice para las búsquedas del front (materias de un plan y licenciatura)
            $table->index(['licenciatura_id', 'plan_estudio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materias');
    }
};