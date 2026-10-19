<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versiones_antecedente', function (Blueprint $table): void {
            $table->bigIncrements('id_version_antecedente');
            $table->bigInteger('id_paciente');
            $table->integer('numero_version');
            $table->bigInteger('id_autor');
            $table->timestampTz('registrada_en', 6);
            $table->text('motivo_cambio')->nullable();
            $table->string('tipo_sangre', 16)->nullable();
            $table->text('alergias')->nullable();
            $table->text('enfermedades_cronicas')->nullable();
            $table->text('medicamentos')->nullable();
            $table->text('enfermedades_familiares')->nullable();
            $table->text('antecedentes_relevantes')->nullable();
            $table->bigInteger('id_consulta_origen');
            $table->foreign('id_paciente', 'versiones_antecedente_paciente_fk')->references('id_paciente')->on('pacientes')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_autor', 'versiones_antecedente_autor_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_consulta_origen', 'versiones_antecedente_consulta_fk')->references('id_consulta')->on('consultas_medicas')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['id_paciente', 'numero_version'], 'versiones_antecedente_numero_uq');
            $table->index('id_autor', 'versiones_antecedente_autor_idx');
            $table->index('id_consulta_origen', 'versiones_antecedente_consulta_idx');
        });

        DB::statement('ALTER TABLE versiones_antecedente RENAME CONSTRAINT versiones_antecedente_pkey TO versiones_antecedente_pk');
        DB::statement('ALTER TABLE versiones_antecedente ADD CONSTRAINT versiones_antecedente_numero_ck CHECK (numero_version > 0)');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION versiones_antecedente_rechazar_mutacion() RETURNS trigger
            LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
            BEGIN
                RAISE EXCEPTION 'versiones_antecedente_inmutable_ck: la version clinica es inmutable.'
                    USING ERRCODE = '23514', CONSTRAINT = 'versiones_antecedente_inmutable_ck';
            END;
            $$;
            CREATE TRIGGER versiones_antecedente_inmutable_trg BEFORE UPDATE OR DELETE ON versiones_antecedente
                FOR EACH ROW EXECUTE FUNCTION versiones_antecedente_rechazar_mutacion();
            CREATE TRIGGER versiones_antecedente_no_truncate_trg BEFORE TRUNCATE ON versiones_antecedente
                FOR EACH STATEMENT EXECUTE FUNCTION versiones_antecedente_rechazar_mutacion();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('versiones_antecedente');
        DB::statement('DROP FUNCTION IF EXISTS versiones_antecedente_rechazar_mutacion()');
    }
};
