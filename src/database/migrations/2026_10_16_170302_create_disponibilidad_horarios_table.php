<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
        public function up(): void
    {
        Schema::create('disponibilidad_horarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facultad_id')->constrained('facultades');
            $table->foreignId('asesor_id')->constrained('usuarios');
            $table->string('dia', 10);
            $table->foreignId('horario_id')->constrained('horarios');
            $table->timestamps();

            $table->unique(['asesor_id', 'dia', 'horario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disponibilidad_horarios');
    }
};
