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
            // Ej. "4-1", "5-2"
            $table->string('nombre', 20);
            $table->timestamps();

            // Un mismo nombre no debe repetirse dentro de la misma licenciatura
            $table->unique(['licenciatura_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupos');
    }
};