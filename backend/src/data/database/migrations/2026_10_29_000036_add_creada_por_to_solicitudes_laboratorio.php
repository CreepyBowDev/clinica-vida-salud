<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_laboratorio', function (Blueprint $table): void {
            // El legado no acredita autor: no actualizar filas publicadas/inmutables.
            $table->bigInteger('creada_por')->nullable();
            $table->foreign('creada_por', 'solicitudes_laboratorio_creador_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->index('creada_por', 'solicitudes_laboratorio_creador_idx');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_laboratorio', function (Blueprint $table): void {
            $table->dropForeign('solicitudes_laboratorio_creador_fk');
            $table->dropIndex('solicitudes_laboratorio_creador_idx');
            $table->dropColumn('creada_por');
        });
    }
};
