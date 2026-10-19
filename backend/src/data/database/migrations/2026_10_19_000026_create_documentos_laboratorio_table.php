<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_laboratorio', function (Blueprint $table): void {
            $table->bigIncrements('id_documento');
            $table->bigInteger('cargado_por');
            $table->string('ruta_privada', 1024);
            $table->string('nombre_original', 255);
            $table->string('tipo_mime', 127);
            $table->bigInteger('tamano_bytes');
            $table->string('sha256', 64);
            $table->timestampTz('cargado_en', 6);
            $table->foreign('cargado_por', 'documentos_laboratorio_cargador_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->unique('ruta_privada', 'documentos_laboratorio_ruta_uq');
            $table->index('cargado_por', 'documentos_laboratorio_cargador_idx');
        });
        DB::statement('ALTER TABLE documentos_laboratorio RENAME CONSTRAINT documentos_laboratorio_pkey TO documentos_laboratorio_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE documentos_laboratorio
            ADD CONSTRAINT documentos_laboratorio_ruta_ck CHECK (char_length(btrim(ruta_privada)) > 0),
            ADD CONSTRAINT documentos_laboratorio_nombre_ck CHECK (char_length(btrim(nombre_original)) > 0),
            ADD CONSTRAINT documentos_laboratorio_mime_ck CHECK (char_length(btrim(tipo_mime)) > 0),
            ADD CONSTRAINT documentos_laboratorio_tamano_ck CHECK (tamano_bytes > 0),
            ADD CONSTRAINT documentos_laboratorio_sha256_ck CHECK (sha256 ~ '^[0-9a-fA-F]{64}$')
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION documentos_laboratorio_rechazar_mutacion() RETURNS trigger
            LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
            BEGIN
                RAISE EXCEPTION 'documentos_laboratorio_inmutable_ck: el documento es inmutable.'
                    USING ERRCODE = '23514', CONSTRAINT = 'documentos_laboratorio_inmutable_ck';
            END;
            $$;
            CREATE TRIGGER documentos_laboratorio_inmutable_trg BEFORE UPDATE OR DELETE ON documentos_laboratorio
                FOR EACH ROW EXECUTE FUNCTION documentos_laboratorio_rechazar_mutacion();
            CREATE TRIGGER documentos_laboratorio_no_truncate_trg BEFORE TRUNCATE ON documentos_laboratorio
                FOR EACH STATEMENT EXECUTE FUNCTION documentos_laboratorio_rechazar_mutacion();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_laboratorio');
        DB::statement('DROP FUNCTION IF EXISTS documentos_laboratorio_rechazar_mutacion()');
    }
};
