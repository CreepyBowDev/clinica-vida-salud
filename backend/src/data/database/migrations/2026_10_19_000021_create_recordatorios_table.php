<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recordatorios', function (Blueprint $table): void {
            $table->bigIncrements('id_recordatorio');
            $table->bigInteger('id_cita');
            $table->integer('version_programacion');
            $table->timestampTz('generado_en', 6);
            $table->timestampTz('leido_en', 6)->nullable();
            $table->timestampTz('invalidado_en', 6)->nullable();
            $table->foreign('id_cita', 'recordatorios_cita_fk')->references('id_cita')->on('citas')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['id_cita', 'version_programacion'], 'recordatorios_cita_version_uq');
        });

        DB::statement('ALTER TABLE recordatorios RENAME CONSTRAINT recordatorios_pkey TO recordatorios_pk');
        DB::statement('ALTER TABLE recordatorios ADD CONSTRAINT recordatorios_version_ck CHECK (version_programacion > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recordatorios');
    }
};
