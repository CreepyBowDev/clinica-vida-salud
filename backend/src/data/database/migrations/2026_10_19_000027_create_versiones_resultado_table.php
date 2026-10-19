<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versiones_resultado', function (Blueprint $table): void {
            $table->bigIncrements('id_version_resultado');
            $table->bigInteger('id_solicitud');
            $table->bigInteger('id_documento');
            $table->integer('numero_version');
            $table->bigInteger('id_version_origen')->nullable();
            $table->bigInteger('id_autor');
            $table->timestampTz('registrada_en', 6);
            $table->text('motivo_cambio')->nullable();
            $table->foreign('id_solicitud', 'versiones_resultado_solicitud_fk')->references('id_solicitud')->on('solicitudes_laboratorio')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_documento', 'versiones_resultado_documento_fk')->references('id_documento')->on('documentos_laboratorio')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_version_origen', 'versiones_resultado_origen_fk')->references('id_version_resultado')->on('versiones_resultado')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_autor', 'versiones_resultado_autor_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['id_solicitud', 'numero_version'], 'versiones_resultado_numero_uq');
            $table->unique(['id_solicitud', 'id_version_resultado'], 'versiones_resultado_solicitud_id_uq');
            $table->index('id_documento', 'versiones_resultado_documento_idx');
            $table->index('id_version_origen', 'versiones_resultado_origen_idx');
            $table->index('id_autor', 'versiones_resultado_autor_idx');
        });
        DB::statement('ALTER TABLE versiones_resultado RENAME CONSTRAINT versiones_resultado_pkey TO versiones_resultado_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE versiones_resultado
            ADD CONSTRAINT versiones_resultado_numero_ck CHECK (numero_version > 0),
            ADD CONSTRAINT versiones_resultado_no_auto_ck CHECK (id_version_origen IS NULL OR id_version_origen <> id_version_resultado)
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION versiones_resultado_rechazar_mutacion() RETURNS trigger
            LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
            BEGIN
                RAISE EXCEPTION 'versiones_resultado_inmutable_ck: la version de resultado es inmutable.'
                    USING ERRCODE = '23514', CONSTRAINT = 'versiones_resultado_inmutable_ck';
            END;
            $$;
            CREATE TRIGGER versiones_resultado_inmutable_trg BEFORE UPDATE OR DELETE ON versiones_resultado
                FOR EACH ROW EXECUTE FUNCTION versiones_resultado_rechazar_mutacion();
            CREATE TRIGGER versiones_resultado_no_truncate_trg BEFORE TRUNCATE ON versiones_resultado
                FOR EACH STATEMENT EXECUTE FUNCTION versiones_resultado_rechazar_mutacion();

            CREATE FUNCTION versiones_resultado_validar_origen() RETURNS trigger
            LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
            DECLARE existe boolean;
            BEGIN
                IF NEW.id_version_origen IS NOT NULL AND NEW.id_version_origen <> NEW.id_version_resultado THEN
                    EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I.versiones_resultado WHERE id_version_resultado = $1)', TG_TABLE_SCHEMA)
                        INTO existe USING NEW.id_version_origen;
                    IF NOT existe THEN
                        RAISE EXCEPTION 'versiones_resultado_origen_fk: la version de origen debe existir previamente.'
                            USING ERRCODE = '23503', CONSTRAINT = 'versiones_resultado_origen_fk';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER versiones_resultado_origen_trg BEFORE INSERT ON versiones_resultado
                FOR EACH ROW EXECUTE FUNCTION versiones_resultado_validar_origen();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('versiones_resultado');
        DB::statement('DROP FUNCTION IF EXISTS versiones_resultado_rechazar_mutacion()');
        DB::statement('DROP FUNCTION IF EXISTS versiones_resultado_validar_origen()');
    }
};
