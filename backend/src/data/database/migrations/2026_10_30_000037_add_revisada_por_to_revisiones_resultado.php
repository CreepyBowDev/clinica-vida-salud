<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revisiones_resultado', function (Blueprint $table): void {
            // NULL conserva autoría desconocida del legado sin mutar revisiones inmutables.
            $table->bigInteger('revisada_por')->nullable();
            $table->foreign('revisada_por', 'revisiones_resultado_revisor_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->index('revisada_por', 'revisiones_resultado_revisor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('revisiones_resultado', function (Blueprint $table): void {
            $table->dropForeign('revisiones_resultado_revisor_fk');
            $table->dropIndex('revisiones_resultado_revisor_idx');
            $table->dropColumn('revisada_por');
        });
    }
};
