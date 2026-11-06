<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE intentos_pago
                ADD COLUMN origen varchar(16) NOT NULL DEFAULT 'Externo',
                ADD COLUMN modalidad varchar(8),
                ADD COLUMN escenario varchar(16),
                ADD COLUMN aprobada_en timestamptz(6),
                DROP CONSTRAINT intentos_contexto_ck,
                ADD CONSTRAINT intentos_contexto_ck CHECK (
                    (origen = 'Externo' AND ((proveedor = 'stripe' AND entorno = 'test') OR
                        (proveedor = 'paypal' AND entorno = 'sandbox')) AND modalidad IS NULL AND escenario IS NULL AND aprobada_en IS NULL) OR
                    (origen = 'Simulado' AND proveedor = 'simulado' AND entorno = 'local' AND cuenta_contexto = 'academico'
                        AND modalidad IS NOT NULL AND modalidad IN ('stripe','paypal')
                        AND escenario IS NOT NULL AND escenario IN ('completado','pendiente')
                        AND referencia_inicio IS NULL AND referencia_pago IS NULL AND NOT captura_permitida
                        AND (aprobada_en IS NULL OR (modalidad = 'paypal' AND aprobada_en >= creado_en))));

            CREATE FUNCTION intentos_preservar_origen() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF ROW(NEW.origen, NEW.modalidad, NEW.escenario, NEW.proveedor, NEW.entorno, NEW.cuenta_contexto)
                    IS DISTINCT FROM ROW(OLD.origen, OLD.modalidad, OLD.escenario, OLD.proveedor, OLD.entorno, OLD.cuenta_contexto)
                    OR (OLD.aprobada_en IS NOT NULL AND NEW.aprobada_en IS DISTINCT FROM OLD.aprobada_en) THEN
                    RAISE EXCEPTION 'El origen del intento es histórico.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER intentos_origen_inmutable_trg BEFORE UPDATE ON intentos_pago
                FOR EACH ROW EXECUTE FUNCTION intentos_preservar_origen();

            ALTER TABLE operaciones_pago DROP CONSTRAINT operaciones_pago_tipo_check,
                ADD CONSTRAINT operaciones_pago_tipo_check CHECK (tipo IN ('Crear','Capturar','Cerrar','Consultar','Devolver','Aprobar','Confirmar')),
                DROP CONSTRAINT operaciones_externa_ck,
                ADD CONSTRAINT operaciones_externa_ck CHECK (
                    (proveedor = 'simulado' AND clave_externa IS NULL AND referencia_externa IS NULL) OR
                    (proveedor IN ('stripe','paypal') AND tipo IN ('Crear','Capturar','Cerrar','Consultar','Devolver')
                        AND (tipo = 'Consultar' OR clave_externa IS NOT NULL)));
            ALTER TABLE cobros_pago ADD CONSTRAINT cobros_simulados_referencia_ck
                CHECK (proveedor <> 'simulado' OR referencia_cobro = 'SIM-' || id_cobro::text);
            SQL);
    }

    public function down(): void
    {
        // Retirar el esquema con historia simulada requiere un procedimiento autorizado, no borrados.
        throw new LogicException('La reversión financiera requiere revisión y autorización específica.');
    }
};
