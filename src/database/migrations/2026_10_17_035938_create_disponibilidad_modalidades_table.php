<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disponibilidad_modalidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facultad_id')->constrained('facultades');
            $table->foreignId('asesor_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('modalidad', 20);
            $table->timestamps();

            $table->unique(['asesor_id', 'modalidad']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disponibilidad_modalidades');
    }
};