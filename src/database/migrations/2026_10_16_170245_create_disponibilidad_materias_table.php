<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up(): void
{
    Schema::create('disponibilidad_materias', function (Blueprint $table) {
        $table->id();
        $table->foreignId('facultad_id')->constrained('facultades');
        $table->foreignId('asesor_id')->constrained('usuarios');
        $table->foreignId('materia_id')->constrained('materias');
        $table->timestamps();

        $table->unique(['asesor_id', 'materia_id']);
    });
}

public function down(): void
{
    Schema::dropIfExists('disponibilidad_materias');
}
};
