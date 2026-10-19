<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacora', function (Blueprint $table): void {
            $table->bigIncrements('id_evento');
            $table->string('tipo_evento', 80);
            $table->string('tipo_actor', 12);
            $table->bigInteger('id_usuario_responsable')->nullable();
            $table->string('rol_responsable', 24)->nullable();
            $table->bigInteger('id_usuario_objetivo')->nullable();
            $table->string('rol_objetivo', 24)->nullable();
            $table->string('modulo', 40);
            $table->string('operacion', 60);
            $table->string('resultado', 16);
            $table->string('causa_codigo', 64)->nullable();
            $table->timestampTz('ocurrido_en');
            $table->timestampTz('registrado_en')->default(DB::raw('clock_timestamp()'));
            $table->ipAddress('direccion_ip')->nullable();
            $table->string('procedencia_ip', 24)->nullable();
            $table->string('entidad_referida', 64)->nullable();
            $table->string('registro_referido', 64)->nullable();
            $table->uuid('correlacion');
            $table->bigInteger('id_evento_relacionado')->nullable();
            $table->smallInteger('version_formato')->default(1);
            $table->jsonb('cambios_seguros')->nullable();
            $table->jsonb('metadatos_seguros')->nullable();
            $table->jsonb('referencias')->nullable();
            $table->foreign('id_usuario_responsable', 'bitacora_responsable_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_usuario_objetivo', 'bitacora_objetivo_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_evento_relacionado', 'bitacora_evento_relacionado_fk')->references('id_evento')->on('bitacora')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['registrado_en', 'id_evento'], 'bitacora_orden_idx');
            $table->index('ocurrido_en', 'bitacora_fecha_idx');
            $table->index('id_usuario_responsable', 'bitacora_responsable_idx');
            $table->index('id_usuario_objetivo', 'bitacora_objetivo_idx');
            $table->index('id_evento_relacionado', 'bitacora_evento_relacionado_idx');
        });

        DB::statement('ALTER TABLE bitacora RENAME CONSTRAINT bitacora_pkey TO bitacora_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE bitacora
            ADD CONSTRAINT bitacora_actor_ck CHECK (
                (tipo_actor = 'USUARIO' AND id_usuario_responsable IS NOT NULL AND rol_responsable IS NOT NULL)
                OR (tipo_actor IN ('SISTEMA', 'ANONIMO') AND id_usuario_responsable IS NULL AND rol_responsable IS NULL)
            ),
            ADD CONSTRAINT bitacora_rol_responsable_ck CHECK (rol_responsable IS NULL OR rol_responsable IN ('Administrador', 'Recepcionista', 'Médico', 'Paciente')),
            ADD CONSTRAINT bitacora_objetivo_ck CHECK ((id_usuario_objetivo IS NULL) = (rol_objetivo IS NULL)),
            ADD CONSTRAINT bitacora_rol_objetivo_ck CHECK (rol_objetivo IS NULL OR rol_objetivo IN ('Administrador', 'Recepcionista', 'Médico', 'Paciente')),
            ADD CONSTRAINT bitacora_resultado_ck CHECK (resultado IN ('EXITO', 'FALLO', 'DENEGADO', 'ERROR')),
            ADD CONSTRAINT bitacora_procedencia_ck CHECK (procedencia_ip IS NULL OR procedencia_ip IN ('DIRECTA', 'PROXY_CONFIABLE')),
            ADD CONSTRAINT bitacora_recurso_ck CHECK (registro_referido IS NULL OR entidad_referida IS NOT NULL),
            ADD CONSTRAINT bitacora_evento_no_auto_ck CHECK (id_evento_relacionado IS NULL OR id_evento_relacionado <> id_evento),
            ADD CONSTRAINT bitacora_version_ck CHECK (version_formato > 0),
            ADD CONSTRAINT bitacora_tipo_ck CHECK (char_length(btrim(tipo_evento)) > 0),
            ADD CONSTRAINT bitacora_modulo_ck CHECK (char_length(btrim(modulo)) > 0),
            ADD CONSTRAINT bitacora_operacion_ck CHECK (char_length(btrim(operacion)) > 0),
            ADD CONSTRAINT bitacora_cambios_objeto_ck CHECK (cambios_seguros IS NULL OR jsonb_typeof(cambios_seguros) = 'object'),
            ADD CONSTRAINT bitacora_metadatos_objeto_ck CHECK (metadatos_seguros IS NULL OR jsonb_typeof(metadatos_seguros) = 'object'),
            ADD CONSTRAINT bitacora_referencias_objeto_ck CHECK (referencias IS NULL OR jsonb_typeof(referencias) = 'object')
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION bitacora_rechazar_mutacion() RETURNS trigger
            LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
            BEGIN
                RAISE EXCEPTION 'bitacora_inmutable_ck: los eventos son inmutables.'
                    USING ERRCODE = '23514', CONSTRAINT = 'bitacora_inmutable_ck';
            END;
            $$;
            CREATE TRIGGER bitacora_inmutable_trg BEFORE UPDATE OR DELETE ON bitacora
                FOR EACH ROW EXECUTE FUNCTION bitacora_rechazar_mutacion();
            CREATE TRIGGER bitacora_no_truncate_trg BEFORE TRUNCATE ON bitacora
                FOR EACH STATEMENT EXECUTE FUNCTION bitacora_rechazar_mutacion();

            CREATE FUNCTION bitacora_validar_evento_anterior() RETURNS trigger
            LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
            BEGIN
                IF NEW.id_evento_relacionado IS NOT NULL
                   AND NEW.id_evento_relacionado <> NEW.id_evento
                   AND NOT EXISTS (SELECT 1 FROM bitacora WHERE id_evento = NEW.id_evento_relacionado) THEN
                    RAISE EXCEPTION 'bitacora_evento_relacionado_fk: el evento debe existir previamente.'
                        USING ERRCODE = '23503', CONSTRAINT = 'bitacora_evento_relacionado_fk';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER bitacora_evento_anterior_trg BEFORE INSERT ON bitacora
                FOR EACH ROW EXECUTE FUNCTION bitacora_validar_evento_anterior();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora');
        DB::statement('DROP FUNCTION IF EXISTS bitacora_rechazar_mutacion()');
        DB::statement('DROP FUNCTION IF EXISTS bitacora_validar_evento_anterior()');
    }
};
