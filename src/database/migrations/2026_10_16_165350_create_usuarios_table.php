<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::create('usuarios', function (Blueprint $table) {
        $table->id();
        $table->foreignId('facultad_id')->nullable()->constrained('facultades');
        $table->string('estatus', 20)->default('pendiente');
        $table->string('nombre', 60);
        $table->string('apellido_paterno', 60);
        $table->string('apellido_materno', 60)->nullable();
        $table->string('correo', 150)->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password')->nullable();
        $table->string('numero_cuenta', 20)->nullable()->unique();
        $table->string('num_cel', 15)->nullable();
        $table->foreignId('grupo_id')->nullable()->constrained('grupos');
        $table->decimal('promedio', 4, 2)->nullable();
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('usuarios');
}
};
