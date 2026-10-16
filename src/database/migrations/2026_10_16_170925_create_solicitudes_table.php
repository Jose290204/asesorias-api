<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::create('solicitudes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('facultad_id')->constrained('facultades');
        $table->foreignId('ciclo_escolar_id')->constrained('ciclos_escolares');
        $table->foreignId('grupo_id')->nullable()->constrained('grupos');
        $table->foreignId('estudiante_id')->constrained('usuarios');
        $table->foreignId('asesor_id')->constrained('usuarios');
        $table->foreignId('materia_id')->constrained('materias');
        $table->foreignId('horario_id')->constrained('horarios');
        $table->date('fecha_inicio');
        $table->string('modalidad', 20);
        $table->string('razon', 20);
        $table->string('estatus', 20)->default('pendiente');
        $table->text('nota_estudiante')->nullable();
        $table->text('motivo_rechazo')->nullable();
        $table->timestamp('respondida_en')->nullable();
        $table->timestamps();

        $table->index(['asesor_id', 'estatus']);
        $table->index(['estudiante_id', 'estatus']);
        $table->index(['facultad_id', 'estatus']);
        $table->index(['facultad_id', 'ciclo_escolar_id']);
    });
}

public function down(): void
{
    Schema::dropIfExists('solicitudes');
}
};
