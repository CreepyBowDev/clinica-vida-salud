<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revisiones_resultado', function (Blueprint $table): void {
            $table->bigIncrements('id_revision');
            $table->bigInteger('id_version_resultado');
            $table->bigInteger('id_medico');
            $table->string('decision', 16);
            $table->text('motivo')->nullable();
            $table->timestampTz('revisada_en', 6);
            $table->foreign('id_version_resultado', 'revisiones_resultado_version_fk')->references('id_version_resultado')->on('versiones_resultado')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_medico', 'revisiones_resultado_medico_fk')->references('id_medico')->on('medicos')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['id_revision', 'id_version_resultado'], 'revisiones_resultado_revision_version_uq');
            $table->index('id_version_resultado', 'revisiones_resultado_version_idx');
            $table->index('id_medico', 'revisiones_resultado_medico_idx');
        });
        DB::statement('ALTER TABLE revisiones_resultado RENAME CONSTRAINT revisiones_resultado_pkey TO revisiones_resultado_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE revisiones_resultado
            ADD CONSTRAINT revisiones_resultado_decision_ck CHECK (decision IN ('Autorizar', 'Devolver')),
            ADD CONSTRAINT revisiones_resultado_motivo_ck CHECK (decision <> 'Devolver' OR (motivo IS NOT NULL AND char_length(btrim(motivo)) > 0))
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION revisiones_resultado_rechazar_mutacion() RETURNS trigger
            LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
            BEGIN
                RAISE EXCEPTION 'revisiones_resultado_inmutable_ck: la revision es inmutable.'
                    USING ERRCODE = '23514', CONSTRAINT = 'revisiones_resultado_inmutable_ck';
            END;
            $$;
            CREATE TRIGGER revisiones_resultado_inmutable_trg BEFORE UPDATE OR DELETE ON revisiones_resultado
                FOR EACH ROW EXECUTE FUNCTION revisiones_resultado_rechazar_mutacion();
            CREATE TRIGGER revisiones_resultado_no_truncate_trg BEFORE TRUNCATE ON revisiones_resultado
                FOR EACH STATEMENT EXECUTE FUNCTION revisiones_resultado_rechazar_mutacion();
            SQL);

        Schema::table('solicitudes_laboratorio', function (Blueprint $table): void {
            $table->foreign(['id_solicitud', 'id_version_actual'], 'solicitudes_laboratorio_version_fk')->references(['id_solicitud', 'id_version_resultado'])->on('versiones_resultado')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['id_revision_publicada', 'id_version_actual'], 'solicitudes_laboratorio_revision_fk')->references(['id_revision', 'id_version_resultado'])->on('revisiones_resultado')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_laboratorio', function (Blueprint $table): void {
            $table->dropForeign('solicitudes_laboratorio_revision_fk');
            $table->dropForeign('solicitudes_laboratorio_version_fk');
        });
        Schema::dropIfExists('revisiones_resultado');
        DB::statement('DROP FUNCTION IF EXISTS revisiones_resultado_rechazar_mutacion()');
    }
};
