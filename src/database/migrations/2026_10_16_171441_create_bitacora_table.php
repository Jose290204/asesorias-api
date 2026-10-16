<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up(): void
{
        Schema::create('bitacora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facultad_id')->nullable()->constrained('facultades')->nullOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('accion', 50);
            $table->nullableMorphs('entidad');
            $table->json('detalles')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['facultad_id', 'created_at']);
            $table->index(['usuario_id', 'created_at']);
            $table->index('accion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora');
    }
};
