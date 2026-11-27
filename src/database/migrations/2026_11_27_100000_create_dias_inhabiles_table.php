<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dias_inhabiles', function (Blueprint $table) {
            $table->id();
            // null = aplica a todas las facultades (calendario general de la UAS);
            // con valor = solo a esa facultad (sus propios días inhábiles)
            $table->foreignId('facultad_id')->nullable()->constrained('facultades')->cascadeOnDelete();
            $table->date('fecha_inicio');
            $table->date('fecha_fin'); // igual a fecha_inicio si es un solo día
            $table->string('tipo', 20); // vacaciones | suspension
            $table->string('motivo', 150);
            $table->timestamps();

            $table->index(['fecha_inicio', 'fecha_fin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dias_inhabiles');
    }
};
