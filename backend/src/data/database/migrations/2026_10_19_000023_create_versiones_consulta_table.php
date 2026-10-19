<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versiones_consulta', function (Blueprint $table): void {
            $table->bigIncrements('id_version_consulta');
            $table->bigInteger('id_consulta');
            $table->integer('numero_version');
            $table->bigInteger('id_autor');
            $table->timestampTz('registrada_en', 6);
            $table->text('motivo_cambio')->nullable();
            $table->string('tipo_consulta', 16);
            $table->text('motivo_consulta');
            $table->text('sintomas')->nullable();
            $table->text('observaciones')->nullable();
            $table->text('diagnostico')->nullable();
            $table->text('indicaciones_tratamiento')->nullable();
            $table->foreign('id_consulta', 'versiones_consulta_consulta_fk')->references('id_consulta')->on('consultas_medicas')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_autor', 'versiones_consulta_autor_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['id_consulta', 'numero_version'], 'versiones_consulta_numero_uq');
            $table->index('id_autor', 'versiones_consulta_autor_idx');
        });

        DB::statement('ALTER TABLE versiones_consulta RENAME CONSTRAINT versiones_consulta_pkey TO versiones_consulta_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE versiones_consulta
            ADD CONSTRAINT versiones_consulta_numero_ck CHECK (numero_version > 0),
            ADD CONSTRAINT versiones_consulta_tipo_ck CHECK (tipo_consulta IN ('Control', 'Enfermedad')),
            ADD CONSTRAINT versiones_consulta_motivo_ck CHECK (char_length(btrim(motivo_consulta)) > 0)
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION versiones_consulta_rechazar_mutacion() RETURNS trigger
            LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
            BEGIN
                RAISE EXCEPTION 'versiones_consulta_inmutable_ck: la version clinica es inmutable.'
                    USING ERRCODE = '23514', CONSTRAINT = 'versiones_consulta_inmutable_ck';
            END;
            $$;
            CREATE TRIGGER versiones_consulta_inmutable_trg BEFORE UPDATE OR DELETE ON versiones_consulta
                FOR EACH ROW EXECUTE FUNCTION versiones_consulta_rechazar_mutacion();
            CREATE TRIGGER versiones_consulta_no_truncate_trg BEFORE TRUNCATE ON versiones_consulta
                FOR EACH STATEMENT EXECUTE FUNCTION versiones_consulta_rechazar_mutacion();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('versiones_consulta');
        DB::statement('DROP FUNCTION IF EXISTS versiones_consulta_rechazar_mutacion()');
    }
};
