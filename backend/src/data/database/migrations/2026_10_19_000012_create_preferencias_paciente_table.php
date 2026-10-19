<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preferencias_paciente', function (Blueprint $table): void {
            $table->bigIncrements('id_preferencia');
            $table->bigInteger('id_paciente');
            $table->boolean('recordatorios_habilitados');
            $table->jsonb('preferencias_privacidad');
            $table->timestampTz('actualizado_en', 6)->useCurrent();
            $table->foreign('id_paciente', 'preferencias_paciente_paciente_fk')->references('id_paciente')->on('pacientes')->restrictOnDelete()->restrictOnUpdate();
            $table->unique('id_paciente', 'preferencias_paciente_paciente_uq');
        });

        DB::statement('ALTER TABLE preferencias_paciente RENAME CONSTRAINT preferencias_paciente_pkey TO preferencias_paciente_pk');
        DB::statement("ALTER TABLE preferencias_paciente ADD CONSTRAINT preferencias_paciente_objeto_ck CHECK (jsonb_typeof(preferencias_privacidad) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('preferencias_paciente');
    }
};
