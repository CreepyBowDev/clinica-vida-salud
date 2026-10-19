<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Infraestructura compartida: no se elimina al revertir la tabla.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA public');

        Schema::create('citas', function (Blueprint $table): void {
            $table->bigIncrements('id_cita');
            $table->string('codigo_cita', 50);
            $table->bigInteger('id_paciente');
            $table->bigInteger('id_medico');
            $table->bigInteger('id_especialidad');
            $table->bigInteger('id_consultorio');
            $table->bigInteger('creada_por');
            $table->timestampTz('inicio_at', 6);
            $table->timestampTz('fin_at', 6);
            $table->integer('duracion_minutos');
            $table->string('estado', 16)->default('Programada');
            $table->text('motivo_breve')->nullable();
            $table->string('modalidad', 16)->default('Presencial');
            $table->string('sede_atencion', 120);
            $table->string('consultorio_atencion', 200);
            $table->integer('version_programacion')->default(1);
            $table->timestampTz('creada_en', 6)->useCurrent();
            $table->timestampTz('actualizada_en', 6)->useCurrent();
            $table->unique('codigo_cita', 'citas_codigo_uq');
            $table->foreign('id_paciente', 'citas_paciente_fk')->references('id_paciente')->on('pacientes')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['id_medico', 'id_especialidad'], 'citas_medico_especialidad_fk')->references(['id_medico', 'id_especialidad'])->on('medico_especialidad')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_consultorio', 'citas_consultorio_fk')->references('id_consultorio')->on('consultorios')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('creada_por', 'citas_creador_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['id_paciente', 'inicio_at'], 'citas_paciente_inicio_idx');
            $table->index(['id_medico', 'id_especialidad'], 'citas_medico_especialidad_idx');
            $table->index('id_consultorio', 'citas_consultorio_idx');
            $table->index('creada_por', 'citas_creador_idx');
            $table->index('inicio_at', 'citas_inicio_idx');
        });

        DB::statement('ALTER TABLE citas RENAME CONSTRAINT citas_pkey TO citas_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE citas
            ADD CONSTRAINT citas_codigo_ck CHECK (char_length(btrim(codigo_cita)) > 0),
            ADD CONSTRAINT citas_estado_ck CHECK (estado IN ('Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia')),
            ADD CONSTRAINT citas_modalidad_ck CHECK (modalidad = 'Presencial'),
            ADD CONSTRAINT citas_duracion_ck CHECK (duracion_minutos > 0),
            ADD CONSTRAINT citas_intervalo_ck CHECK (fin_at > inicio_at),
            ADD CONSTRAINT citas_fin_duracion_ck CHECK (fin_at = inicio_at + duracion_minutos * INTERVAL '1 minute'),
            ADD CONSTRAINT citas_version_ck CHECK (version_programacion > 0),
            ADD CONSTRAINT citas_sede_ck CHECK (char_length(btrim(sede_atencion)) > 0),
            ADD CONSTRAINT citas_consultorio_texto_ck CHECK (char_length(btrim(consultorio_atencion)) > 0),
            ADD CONSTRAINT citas_medico_no_solapado_excl EXCLUDE USING gist (
                id_medico public.gist_int8_ops WITH =,
                tstzrange(inicio_at, fin_at, '[)') WITH &&
            ) WHERE (estado <> 'Cancelada'),
            ADD CONSTRAINT citas_consultorio_no_solapado_excl EXCLUDE USING gist (
                id_consultorio public.gist_int8_ops WITH =,
                tstzrange(inicio_at, fin_at, '[)') WITH &&
            ) WHERE (estado <> 'Cancelada')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('citas');
    }
};
