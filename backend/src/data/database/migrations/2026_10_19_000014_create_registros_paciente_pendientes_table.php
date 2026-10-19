<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros_paciente_pendientes', function (Blueprint $table): void {
            $table->bigIncrements('id_registro_pendiente');
            $table->bigInteger('id_paciente')->nullable();
            $table->bigInteger('id_codigo_activacion')->nullable();
            $table->string('tipo_registro', 16);
            $table->string('documento_identidad', 50)->nullable();
            $table->string('correo_propuesto', 254);
            $table->string('password_hash_temporal', 255)->nullable();
            $table->jsonb('datos_registro')->nullable();
            $table->timestampTz('iniciado_en', 6);
            $table->timestampTz('expira_en', 6);
            $table->timestampTz('completado_en', 6)->nullable();
            $table->timestampTz('invalidado_en', 6)->nullable();
            $table->foreign('id_paciente', 'registros_pendientes_paciente_fk')->references('id_paciente')->on('pacientes')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_codigo_activacion', 'registros_pendientes_codigo_fk')->references('id_codigo_activacion')->on('codigos_activacion_paciente')->restrictOnDelete()->restrictOnUpdate();
            $table->index('id_paciente', 'registros_pendientes_paciente_idx');
            $table->index('id_codigo_activacion', 'registros_pendientes_codigo_idx');
        });

        DB::statement('ALTER TABLE registros_paciente_pendientes RENAME CONSTRAINT registros_paciente_pendientes_pkey TO registros_paciente_pendientes_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE registros_paciente_pendientes
            ADD CONSTRAINT registros_pendientes_tipo_ck CHECK (
                (tipo_registro = 'Nuevo' AND id_paciente IS NULL AND id_codigo_activacion IS NULL)
                OR (tipo_registro = 'Vinculacion' AND id_paciente IS NOT NULL AND id_codigo_activacion IS NOT NULL)
            ),
            ADD CONSTRAINT registros_pendientes_documento_ck CHECK (documento_identidad IS NULL OR char_length(btrim(documento_identidad)) > 0),
            ADD CONSTRAINT registros_pendientes_correo_ck CHECK (char_length(btrim(correo_propuesto)) > 0),
            ADD CONSTRAINT registros_pendientes_hash_ck CHECK (password_hash_temporal IS NULL OR char_length(btrim(password_hash_temporal)) > 0),
            ADD CONSTRAINT registros_pendientes_hash_abierto_ck CHECK (completado_en IS NOT NULL OR invalidado_en IS NOT NULL OR password_hash_temporal IS NOT NULL),
            ADD CONSTRAINT registros_pendientes_datos_ck CHECK (datos_registro IS NULL OR jsonb_typeof(datos_registro) = 'object'),
            ADD CONSTRAINT registros_pendientes_datos_nuevo_ck CHECK (tipo_registro <> 'Nuevo' OR completado_en IS NOT NULL OR invalidado_en IS NOT NULL OR datos_registro IS NOT NULL),
            ADD CONSTRAINT registros_pendientes_vigencia_ck CHECK (expira_en > iniciado_en)
            SQL);

        // El vencimiento se cierra explícitamente en la transacción del CU; no usar now() en un índice.
        DB::statement('CREATE UNIQUE INDEX registros_pendientes_paciente_abierto_uq ON registros_paciente_pendientes (id_paciente) WHERE completado_en IS NULL AND invalidado_en IS NULL AND id_paciente IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX registros_pendientes_codigo_abierto_uq ON registros_paciente_pendientes (id_codigo_activacion) WHERE completado_en IS NULL AND invalidado_en IS NULL AND id_codigo_activacion IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX registros_pendientes_documento_abierto_uq ON registros_paciente_pendientes (btrim(documento_identidad)) WHERE completado_en IS NULL AND invalidado_en IS NULL AND documento_identidad IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX registros_pendientes_correo_abierto_uq ON registros_paciente_pendientes (lower(btrim(correo_propuesto))) WHERE completado_en IS NULL AND invalidado_en IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_paciente_pendientes');
    }
};
