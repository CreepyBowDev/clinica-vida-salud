<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codigos_activacion_paciente', function (Blueprint $table): void {
            $table->bigIncrements('id_codigo_activacion');
            $table->bigInteger('id_paciente');
            $table->bigInteger('id_recepcionista');
            $table->string('codigo_hash', 255);
            $table->timestampTz('emitido_en', 6);
            $table->timestampTz('expira_en', 6);
            $table->integer('intentos_fallidos')->default(0);
            $table->timestampTz('consumido_en', 6)->nullable();
            $table->timestampTz('invalidado_en', 6)->nullable();
            $table->foreign('id_paciente', 'codigos_activacion_paciente_fk')->references('id_paciente')->on('pacientes')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_recepcionista', 'codigos_activacion_recepcionista_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->unique('codigo_hash', 'codigos_activacion_hash_uq');
            $table->index('id_paciente', 'codigos_activacion_paciente_idx');
            $table->index('id_recepcionista', 'codigos_activacion_recepcionista_idx');
        });

        DB::statement('ALTER TABLE codigos_activacion_paciente RENAME CONSTRAINT codigos_activacion_paciente_pkey TO codigos_activacion_paciente_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE codigos_activacion_paciente
            ADD CONSTRAINT codigos_activacion_hash_ck CHECK (char_length(btrim(codigo_hash)) > 0),
            ADD CONSTRAINT codigos_activacion_intentos_ck CHECK (intentos_fallidos BETWEEN 0 AND 5),
            ADD CONSTRAINT codigos_activacion_vigencia_ck CHECK (expira_en > emitido_en)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('codigos_activacion_paciente');
    }
};
