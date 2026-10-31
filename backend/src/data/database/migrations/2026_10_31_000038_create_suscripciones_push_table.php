<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suscripciones_push', function (Blueprint $table): void {
            $table->bigIncrements('id_suscripcion');
            $table->string('instalacion_hash', 64)->unique('suscripciones_push_instalacion_uq');
            $table->string('endpoint_hash', 64)->unique('suscripciones_push_endpoint_uq');
            $table->text('datos_cifrados');
            $table->bigInteger('id_usuario');
            $table->bigInteger('id_paciente');
            $table->integer('version_autorizacion');
            $table->string('huella_cuenta', 64);
            $table->timestampTz('autorizada_en', 6);
            $table->timestampTz('actualizada_en', 6);
            $table->timestampTz('revocada_en', 6)->nullable();
            $table->timestampTz('expira_en', 6);
            $table->foreign('id_usuario', 'suscripciones_push_usuario_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_paciente', 'suscripciones_push_paciente_fk')->references('id_paciente')->on('pacientes')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['id_usuario', 'id_suscripcion'], 'suscripciones_push_usuario_idx');
        });
        DB::statement('ALTER TABLE suscripciones_push RENAME CONSTRAINT suscripciones_push_pkey TO suscripciones_push_pk');
        DB::statement("ALTER TABLE suscripciones_push ADD CONSTRAINT suscripciones_push_version_ck CHECK (version_autorizacion > 0), ADD CONSTRAINT suscripciones_push_hash_ck CHECK (instalacion_hash ~ '^[0-9a-f]{64}$' AND endpoint_hash ~ '^[0-9a-f]{64}$' AND huella_cuenta ~ '^[0-9a-f]{64}$'), ADD CONSTRAINT suscripciones_push_fechas_ck CHECK (expira_en > autorizada_en AND actualizada_en >= autorizada_en AND (revocada_en IS NULL OR revocada_en >= autorizada_en))");

    }

    public function down(): void
    {
        Schema::dropIfExists('suscripciones_push');
    }
};
