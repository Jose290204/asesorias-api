<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidencias_sesiones', function (Blueprint $table) {
            $table->id();
            // Si se borra la sesión, se borran sus registros de evidencia (los archivos los limpia el service)
            $table->foreignId('sesion_id')->constrained('sesiones')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->string('nombre_archivo', 255);
            $table->string('ruta', 255);
            // Cada fila recuerda su disco, igual que el material
            $table->string('disco', 20)->default('local');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('tamano')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidencias_sesiones');
    }
};