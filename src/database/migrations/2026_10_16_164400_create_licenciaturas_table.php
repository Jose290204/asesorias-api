<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenciaturas', function (Blueprint $table) {
            $table->id();
            // Multitenencia: cada licenciatura pertenece a una facultad
            $table->foreignId('facultad_id')->constrained('facultades');
            // El nombre más largo mide ~75 caracteres; 150 deja margen
            $table->string('nombre', 150);
            $table->timestamps();

            $table->unique(['facultad_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenciaturas');
    }
};