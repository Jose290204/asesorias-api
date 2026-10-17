<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::create('usuario_roles', function (Blueprint $table) {
        $table->id();
        $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
        $table->unsignedTinyInteger('rol_id');
        $table->timestamps();

        $table->foreign('rol_id')->references('id')->on('roles');
        $table->unique(['usuario_id', 'rol_id']);
    });
}

public function down(): void
{
    Schema::dropIfExists('usuario_roles');
}
};
