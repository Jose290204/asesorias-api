<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ciclos_escolares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facultad_id')->constrained('facultades');
            // Ej. "2026-2027" o "2026-B"
            $table->string('nombre', 50);
            $table->unsignedTinyInteger('periodo');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            // Solo un ciclo debería estar vigente por facultad; eso lo valida el service
            $table->boolean('vigente')->default(false);
            $table->timestamps();

            $table->unique(['facultad_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ciclos_escolares');
    }
};