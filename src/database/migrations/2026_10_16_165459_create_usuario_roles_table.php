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
        $table->string('rol', 20)->index();
        $table->timestamps();

        $table->unique(['usuario_id', 'rol']);
    });
}

public function down(): void
{
    Schema::dropIfExists('usuario_roles');
}
};
