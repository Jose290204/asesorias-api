<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::create('asesorias', function (Blueprint $table) {
        $table->id();
        $table->foreignId('facultad_id')->constrained('facultades');
        $table->foreignId('ciclo_escolar_id')->constrained('ciclos_escolares');
        $table->foreignId('grupo_id')->nullable()->constrained('grupos');
        $table->foreignId('solicitud_id')->nullable()->unique()->constrained('solicitudes');
        $table->foreignId('estudiante_id')->constrained('usuarios');
        $table->foreignId('asesor_id')->constrained('usuarios');
        $table->foreignId('materia_id')->constrained('materias');
        $table->foreignId('horario_id')->constrained('horarios');
        $table->string('modalidad', 20);
        $table->string('razon', 20);
        $table->string('estatus', 20)->default('en_curso');
        $table->date('fecha_inicio');
        $table->timestamp('finalizada_en')->nullable();
        $table->text('observaciones')->nullable();
        $table->text('motivo_cancelacion')->nullable();
        $table->timestamps();

        $table->index(['asesor_id', 'estatus']);
        $table->index(['estudiante_id', 'estatus']);
        $table->index(['facultad_id', 'estatus']);
        $table->index(['facultad_id', 'ciclo_escolar_id']);
    });
}

public function down(): void
{
    Schema::dropIfExists('asesorias');
}
};
