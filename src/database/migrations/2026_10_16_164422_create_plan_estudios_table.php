<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_estudios', function (Blueprint $table) {
            $table->id();
            // Facultad a la que pertenece el plan (multitenencia)
            $table->foreignId('facultad_id')->constrained('facultades');
            // Año del plan, guardado como texto: "2011", "2023"
            $table->string('nombre', 50);
            $table->timestamps();

            // Evita dos planes "2023" dentro de la misma facultad
            $table->unique(['facultad_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_estudios');
    }
};