<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verificaciones_correo', function (Blueprint $table): void {
            $table->bigIncrements('id_verificacion');
            $table->bigInteger('id_registro_pendiente')->nullable();
            $table->bigInteger('id_usuario')->nullable();
            $table->string('proposito', 24);
            $table->string('correo_destino', 254);
            $table->string('codigo_hash', 255);
            $table->timestampTz('enviado_en', 6);
            $table->timestampTz('expira_en', 6);
            $table->integer('intentos_fallidos')->default(0);
            $table->timestampTz('consumido_en', 6)->nullable();
            $table->timestampTz('invalidado_en', 6)->nullable();
            $table->foreign('id_registro_pendiente', 'verificaciones_correo_registro_fk')->references('id_registro_pendiente')->on('registros_paciente_pendientes')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_usuario', 'verificaciones_correo_usuario_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->index('id_registro_pendiente', 'verificaciones_correo_registro_idx');
            $table->index('id_usuario', 'verificaciones_correo_usuario_idx');
        });

        DB::statement('ALTER TABLE verificaciones_correo RENAME CONSTRAINT verificaciones_correo_pkey TO verificaciones_correo_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE verificaciones_correo
            ADD CONSTRAINT verificaciones_correo_proposito_xor_ck CHECK (
                (proposito = 'ActivacionPaciente' AND id_registro_pendiente IS NOT NULL AND id_usuario IS NULL)
                OR (proposito = 'CambioCorreo' AND id_usuario IS NOT NULL AND id_registro_pendiente IS NULL)
            ),
            ADD CONSTRAINT verificaciones_correo_destino_ck CHECK (char_length(btrim(correo_destino)) > 0),
            ADD CONSTRAINT verificaciones_correo_hash_ck CHECK (char_length(btrim(codigo_hash)) > 0),
            ADD CONSTRAINT verificaciones_correo_intentos_ck CHECK (intentos_fallidos BETWEEN 0 AND 5),
            ADD CONSTRAINT verificaciones_correo_vigencia_ck CHECK (expira_en > enviado_en)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('verificaciones_correo');
    }
};
