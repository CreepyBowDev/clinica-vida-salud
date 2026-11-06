<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE intentos_pago DROP CONSTRAINT intentos_contexto_ck,
                ADD CONSTRAINT intentos_contexto_ck CHECK (
                    (origen = 'Externo' AND ((proveedor = 'stripe' AND entorno = 'test') OR
                        (proveedor = 'paypal' AND entorno = 'sandbox')) AND modalidad IS NULL AND escenario IS NULL AND aprobada_en IS NULL) OR
                    (origen = 'Simulado' AND proveedor = 'simulado' AND entorno IN ('local','academic') AND cuenta_contexto = 'academico'
                        AND modalidad IS NOT NULL AND modalidad IN ('stripe','paypal')
                        AND escenario IS NOT NULL AND escenario IN ('completado','pendiente')
                        AND referencia_inicio IS NULL AND referencia_pago IS NULL AND NOT captura_permitida
                        AND (aprobada_en IS NULL OR (modalidad = 'paypal' AND aprobada_en >= creado_en))));

            ALTER TABLE cobros_pago ADD CONSTRAINT cobros_recibo_uq UNIQUE (id_cobro, aplicado, proveedor, id_obligacion, centavos_usd);
            CREATE TABLE recibos_academicos_pago (
                id_cobro uuid PRIMARY KEY,
                numero varchar(44) NOT NULL UNIQUE CHECK (numero = 'REC-ACA-' || id_cobro::text),
                aplicado boolean NOT NULL DEFAULT true CHECK (aplicado),
                proveedor varchar(8) NOT NULL DEFAULT 'simulado' CHECK (proveedor = 'simulado'),
                id_obligacion uuid NOT NULL,
                centavos_usd integer NOT NULL,
                centavos_bob integer NOT NULL CHECK (centavos_bob BETWEEN 700 AND 700000),
                tasa_numerador integer NOT NULL CHECK (tasa_numerador = 7),
                tasa_denominador integer NOT NULL CHECK (tasa_denominador = 1),
                id_paciente bigint NOT NULL REFERENCES pacientes(id_paciente) ON UPDATE RESTRICT ON DELETE RESTRICT,
                codigo_paciente varchar(100) NOT NULL,
                nombre_titular text NOT NULL CHECK (length(btrim(nombre_titular)) BETWEEN 1 AND 500),
                codigo_cita varchar(100) NOT NULL,
                modalidad varchar(8) NOT NULL CHECK (modalidad IN ('stripe','paypal')),
                pagado_en timestamptz(6) NOT NULL,
                emitido_en timestamptz(6) NOT NULL CHECK (emitido_en = pagado_en),
                version_formato smallint NOT NULL DEFAULT 1 CHECK (version_formato = 1),
                CONSTRAINT recibo_cobro_fk FOREIGN KEY (id_cobro, aplicado, proveedor, id_obligacion, centavos_usd)
                    REFERENCES cobros_pago(id_cobro, aplicado, proveedor, id_obligacion, centavos_usd) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT recibo_conversion_ck CHECK (centavos_usd = (2 * centavos_bob + 7) / 14)
            );
            CREATE FUNCTION recibos_academicos_preservar() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'El recibo académico es histórico.' USING ERRCODE = '23514';
            END;
            $$;
            CREATE TRIGGER recibos_academicos_inmutables_trg BEFORE UPDATE OR DELETE ON recibos_academicos_pago
                FOR EACH ROW EXECUTE FUNCTION recibos_academicos_preservar();
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('La reversión de recibos históricos requiere autorización específica.');
    }
};
