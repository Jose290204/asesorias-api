<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::create('chats', function (Blueprint $table) {
        $table->id();
        $table->foreignId('asesoria_id')->constrained('asesorias')->cascadeOnDelete();
        $table->foreignId('usuario_id')->constrained('usuarios');
        $table->text('mensaje');
        $table->timestamp('leido_en')->nullable();
        $table->timestamps();

        $table->index(['asesoria_id', 'created_at']);
    });
}

public function down(): void
{
    Schema::dropIfExists('chats');
}
};
