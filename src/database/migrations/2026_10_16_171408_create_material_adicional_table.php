<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_adicional', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asesoria_id')->constrained('asesorias')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->string('nombre_archivo', 255);
            $table->string('drive_file_id', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('tamano')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_adicional');
    }
};
