<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Primer cambio: la columna deja de ser "de Drive" y pasa a guardar la ruta
        // que devuelva el disco (hoy una ruta local; con Drive, lo que devuelva ese disco).
        Schema::table('material_adicional', function (Blueprint $table) {
            $table->renameColumn('drive_file_id', 'ruta');
        });

        // Segundo cambio, en otra operación para que MySQL no mezcle rename y add:
        // el disco donde quedó el archivo. Cada fila recuerda el suyo, así que al pasar
        // a Drive los archivos viejos siguen leyéndose del disco 'local'.
        Schema::table('material_adicional', function (Blueprint $table) {
            $table->string('disco', 20)->default('local')->after('ruta');
        });
    }

    public function down(): void
    {
        // Se deshace en orden inverso.
        Schema::table('material_adicional', function (Blueprint $table) {
            $table->dropColumn('disco');
        });

        Schema::table('material_adicional', function (Blueprint $table) {
            $table->renameColumn('ruta', 'drive_file_id');
        });
    }
};