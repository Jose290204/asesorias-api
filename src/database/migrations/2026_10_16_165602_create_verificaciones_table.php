<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::create('verificaciones', function (Blueprint $table) {
        $table->id();
        $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
        $table->string('tipo', 20);
        $table->string('destino', 150);
        $table->string('codigo');
        $table->timestamp('expira_en');
        $table->unsignedTinyInteger('intentos')->default(0);
        $table->timestamp('usado_en')->nullable();
        $table->timestamps();

        $table->index(['usuario_id', 'tipo']);
    });
}

public function down(): void
{
    Schema::dropIfExists('verificaciones');
}
};
