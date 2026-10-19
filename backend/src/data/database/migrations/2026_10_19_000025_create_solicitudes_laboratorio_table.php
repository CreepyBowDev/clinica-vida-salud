<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_laboratorio', function (Blueprint $table): void {
            $table->bigIncrements('id_solicitud');
            $table->bigInteger('id_consulta');
            $table->string('estudio_solicitado', 255);
            $table->timestampTz('solicitada_en', 6);
            $table->string('estado_interno', 16)->default('Pendiente');
            $table->bigInteger('id_version_actual')->nullable();
            $table->bigInteger('id_revision_publicada')->nullable();
            $table->foreign('id_consulta', 'solicitudes_laboratorio_consulta_fk')->references('id_consulta')->on('consultas_medicas')->restrictOnDelete()->restrictOnUpdate();
            $table->unique('id_version_actual', 'solicitudes_laboratorio_version_uq');
            $table->unique('id_revision_publicada', 'solicitudes_laboratorio_revision_uq');
            $table->index('id_consulta', 'solicitudes_laboratorio_consulta_idx');
        });

        DB::statement('ALTER TABLE solicitudes_laboratorio RENAME CONSTRAINT solicitudes_laboratorio_pkey TO solicitudes_laboratorio_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE solicitudes_laboratorio
            ADD CONSTRAINT solicitudes_laboratorio_estudio_ck CHECK (char_length(btrim(estudio_solicitado)) > 0),
            ADD CONSTRAINT solicitudes_laboratorio_estado_ck CHECK (estado_interno IN ('Pendiente', 'En revisión', 'Disponible')),
            ADD CONSTRAINT solicitudes_laboratorio_punteros_ck CHECK (
                (estado_interno = 'Pendiente' AND id_version_actual IS NULL AND id_revision_publicada IS NULL)
                OR (estado_interno = 'En revisión' AND id_revision_publicada IS NULL)
                OR (estado_interno = 'Disponible' AND id_version_actual IS NOT NULL AND id_revision_publicada IS NOT NULL)
            )
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION solicitudes_laboratorio_proteger_publicada() RETURNS trigger
            LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
            BEGIN
                IF OLD.estado_interno = 'Disponible' THEN
                    RAISE EXCEPTION 'solicitudes_laboratorio_publicada_ck: el resultado publicado no admite cambios ordinarios.'
                        USING ERRCODE = '23514', CONSTRAINT = 'solicitudes_laboratorio_publicada_ck';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER solicitudes_laboratorio_publicada_trg BEFORE UPDATE OR DELETE ON solicitudes_laboratorio
                FOR EACH ROW EXECUTE FUNCTION solicitudes_laboratorio_proteger_publicada();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_laboratorio');
        DB::statement('DROP FUNCTION IF EXISTS solicitudes_laboratorio_proteger_publicada()');
    }
};
