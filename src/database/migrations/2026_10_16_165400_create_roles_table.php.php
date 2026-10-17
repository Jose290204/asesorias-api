<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('nombre', 30)->unique();
            $table->timestamps();
        });

        // Catálogo fijo: los ids deben coincidir con el enum App\Enums\Rol
        DB::table('roles')->insert([
            ['id' => 1, 'nombre' => 'Super administrador', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'nombre' => 'Administrador',       'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'nombre' => 'Asesor',              'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'nombre' => 'Estudiante',          'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};