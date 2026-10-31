<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envios_recordatorio_push', function (Blueprint $table): void {
            $table->bigIncrements('id_envio');
            $table->bigInteger('id_recordatorio');
            $table->bigInteger('id_suscripcion');
            $table->bigInteger('id_usuario');
            $table->bigInteger('id_paciente');
            $table->integer('version_autorizacion');
            $table->string('estado', 16)->default('Pendiente');
            $table->integer('intentos')->default(0);
            $table->timestampTz('proximo_intento_en', 6);
            $table->timestampTz('reclamado_hasta', 6)->nullable();
            $table->uuid('codigo_reclamo')->nullable();
            $table->string('resultado_seguro', 64)->nullable();
            $table->timestampTz('creado_en', 6);
            $table->timestampTz('actualizado_en', 6);
            $table->foreign('id_recordatorio', 'envios_push_recordatorio_fk')->references('id_recordatorio')->on('recordatorios')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_suscripcion', 'envios_push_suscripcion_fk')->references('id_suscripcion')->on('suscripciones_push')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_usuario', 'envios_push_usuario_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_paciente', 'envios_push_paciente_fk')->references('id_paciente')->on('pacientes')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['id_recordatorio', 'id_suscripcion'], 'envios_push_aviso_dispositivo_uq');
            $table->index(['estado', 'proximo_intento_en', 'id_envio'], 'envios_push_pendientes_idx');
        });
        DB::statement('ALTER TABLE envios_recordatorio_push RENAME CONSTRAINT envios_recordatorio_push_pkey TO envios_recordatorio_push_pk');
        DB::statement("ALTER TABLE envios_recordatorio_push ADD CONSTRAINT envios_push_estado_ck CHECK (estado IN ('Pendiente','Procesando','Aceptado','Descartado','Fallido','Incierto')), ADD CONSTRAINT envios_push_version_ck CHECK (version_autorizacion > 0 AND intentos BETWEEN 0 AND 3), ADD CONSTRAINT envios_push_lease_ck CHECK ((estado = 'Procesando' AND reclamado_hasta IS NOT NULL AND codigo_reclamo IS NOT NULL AND intentos > 0) OR (estado <> 'Procesando' AND reclamado_hasta IS NULL AND codigo_reclamo IS NULL)), ADD CONSTRAINT envios_push_fechas_ck CHECK (actualizado_en >= creado_en)");
    }

    public function down(): void
    {
        Schema::dropIfExists('envios_recordatorio_push');
    }
};
