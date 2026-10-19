<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cambios_cita', function (Blueprint $table): void {
            $table->bigIncrements('id_cambio_cita');
            $table->bigInteger('id_cita');
            $table->bigInteger('id_actor');
            $table->string('operacion', 60);
            $table->timestampTz('realizado_en', 6);
            $table->string('estado_anterior', 16)->nullable();
            $table->string('estado_nuevo', 16);
            $table->jsonb('datos_anteriores')->nullable();
            $table->jsonb('datos_nuevos');
            $table->text('motivo')->nullable();
            $table->foreign('id_cita', 'cambios_cita_cita_fk')->references('id_cita')->on('citas')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_actor', 'cambios_cita_actor_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['id_cita', 'realizado_en', 'id_cambio_cita'], 'cambios_cita_historial_idx');
            $table->index('id_actor', 'cambios_cita_actor_idx');
        });

        DB::statement('ALTER TABLE cambios_cita RENAME CONSTRAINT cambios_cita_pkey TO cambios_cita_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE cambios_cita
            ADD CONSTRAINT cambios_cita_operacion_ck CHECK (char_length(btrim(operacion)) > 0),
            ADD CONSTRAINT cambios_cita_estado_anterior_ck CHECK (estado_anterior IS NULL OR estado_anterior IN ('Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia')),
            ADD CONSTRAINT cambios_cita_estado_nuevo_ck CHECK (estado_nuevo IN ('Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia')),
            ADD CONSTRAINT cambios_cita_anterior_objeto_ck CHECK (datos_anteriores IS NULL OR jsonb_typeof(datos_anteriores) = 'object'),
            ADD CONSTRAINT cambios_cita_nuevo_objeto_ck CHECK (jsonb_typeof(datos_nuevos) = 'object')
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION cambios_cita_rechazar_mutacion() RETURNS trigger
            LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
            BEGIN
                RAISE EXCEPTION 'cambios_cita_inmutable_ck: el historial es inmutable.'
                    USING ERRCODE = '23514', CONSTRAINT = 'cambios_cita_inmutable_ck';
            END;
            $$;
            CREATE TRIGGER cambios_cita_inmutable_trg BEFORE UPDATE OR DELETE ON cambios_cita
                FOR EACH ROW EXECUTE FUNCTION cambios_cita_rechazar_mutacion();
            CREATE TRIGGER cambios_cita_no_truncate_trg BEFORE TRUNCATE ON cambios_cita
                FOR EACH STATEMENT EXECUTE FUNCTION cambios_cita_rechazar_mutacion();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios_cita');
        DB::statement('DROP FUNCTION IF EXISTS cambios_cita_rechazar_mutacion()');
    }
};
