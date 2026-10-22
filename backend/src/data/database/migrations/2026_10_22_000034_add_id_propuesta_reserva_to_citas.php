<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('citas', function (Blueprint $table): void {
            $table->uuid('id_propuesta_reserva')->nullable();
            $table->unique('id_propuesta_reserva', 'citas_propuesta_reserva_uq');
        });
    }

    public function down(): void
    {
        Schema::table('citas', function (Blueprint $table): void {
            $table->dropUnique('citas_propuesta_reserva_uq');
            $table->dropColumn('id_propuesta_reserva');
        });
    }
};
