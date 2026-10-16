<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up(): void
{
    Schema::create('sesiones', function (Blueprint $table) {
        $table->id();
        $table->foreignId('asesoria_id')->constrained('asesorias')->cascadeOnDelete();
        $table->date('fecha');
        $table->foreignId('horario_id')->constrained('horarios');
        $table->string('tema', 150)->nullable();
        $table->text('notas')->nullable();
        $table->foreignId('registrada_por')->constrained('usuarios');
        $table->timestamps();

        $table->unique(['asesoria_id', 'fecha', 'horario_id']);
    });
}

public function down(): void
{
    Schema::dropIfExists('sesiones');
}
};
