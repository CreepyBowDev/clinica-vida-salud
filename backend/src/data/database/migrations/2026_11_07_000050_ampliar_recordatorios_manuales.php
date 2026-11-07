<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recordatorios', function (Blueprint $table): void {
            $table->string('origen', 12)->default('Automatico');
            $table->bigInteger('id_administrador')->nullable();
            $table->uuid('operacion_manual')->nullable()->unique();
            $table->char('revision_manual', 64)->nullable();
            $table->string('resultado_push', 32)->nullable();
            $table->foreign('id_administrador')->references('id_usuario')->on('usuarios')->restrictOnDelete();
            $table->dropUnique('recordatorios_cita_version_uq');
            // Uno de cada origen; el manual nunca consume la unicidad del automático.
            $table->unique(['id_cita', 'version_programacion', 'origen'], 'recordatorios_cita_version_origen_uq');
        });
        DB::statement("ALTER TABLE recordatorios ADD CONSTRAINT recordatorios_origen_ck CHECK (
            (origen = 'Automatico' AND id_administrador IS NULL AND operacion_manual IS NULL AND revision_manual IS NULL AND resultado_push IS NULL)
            OR (origen = 'Manual' AND id_administrador IS NOT NULL AND operacion_manual IS NOT NULL AND revision_manual IS NOT NULL
                AND resultado_push IS NOT NULL AND resultado_push IN ('Pendiente', 'Sin_dispositivos', 'No_disponible')))");
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            // No perder manuales que se confirmen entre la comprobación y el DDL.
            DB::statement('LOCK TABLE recordatorios IN ACCESS EXCLUSIVE MODE');
            if (DB::table('recordatorios')->where('origen', 'Manual')->exists()) {
                throw new RuntimeException('No se puede revertir mientras existan recordatorios manuales.');
            }
            DB::statement('ALTER TABLE recordatorios DROP CONSTRAINT recordatorios_origen_ck');
            Schema::table('recordatorios', function (Blueprint $table): void {
                $table->dropUnique('recordatorios_cita_version_origen_uq');
                $table->dropForeign(['id_administrador']);
                $table->dropUnique(['operacion_manual']);
                $table->dropColumn(['origen', 'id_administrador', 'operacion_manual', 'revision_manual', 'resultado_push']);
                $table->unique(['id_cita', 'version_programacion'], 'recordatorios_cita_version_uq');
            });
        });
    }
};
