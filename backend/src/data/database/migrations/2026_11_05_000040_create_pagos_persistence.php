<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Sin backfill, fecha de corte ni habilitación: solo una futura reserva autorizada insertará true.
        DB::unprepared(<<<'SQL'
            ALTER TABLE citas ADD COLUMN incorporada_finanzas boolean NOT NULL DEFAULT false;
            ALTER TABLE citas ADD CONSTRAINT citas_incorporacion_uq UNIQUE (id_cita, incorporada_finanzas);
            ALTER TABLE cambios_cita ADD CONSTRAINT cambios_cita_identidad_uq UNIQUE (id_cambio_cita, id_cita);

            CREATE FUNCTION citas_preservar_incorporacion() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.incorporada_finanzas IS DISTINCT FROM OLD.incorporada_finanzas THEN
                    RAISE EXCEPTION 'La incorporación financiera es inmutable.'
                        USING ERRCODE = '23514', CONSTRAINT = 'citas_incorporacion_inmutable_ck';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER citas_incorporacion_inmutable_trg BEFORE UPDATE ON citas
                FOR EACH ROW EXECUTE FUNCTION citas_preservar_incorporacion();

            CREATE TABLE obligaciones_pago (
                id_obligacion uuid PRIMARY KEY,
                id_cita bigint NOT NULL UNIQUE,
                incorporada_finanzas boolean NOT NULL DEFAULT true CHECK (incorporada_finanzas),
                centavos_bob integer NOT NULL CHECK (centavos_bob BETWEEN 700 AND 700000),
                centavos_usd integer NOT NULL CHECK (centavos_usd BETWEEN 100 AND 100000),
                tasa_numerador integer NOT NULL DEFAULT 7 CHECK (tasa_numerador = 7),
                tasa_denominador integer NOT NULL DEFAULT 1 CHECK (tasa_denominador = 1),
                moneda_original varchar(3) NOT NULL DEFAULT 'BOB' CHECK (moneda_original = 'BOB'),
                moneda_cobro varchar(3) NOT NULL DEFAULT 'USD' CHECK (moneda_cobro = 'USD'),
                emitida_por bigint NOT NULL REFERENCES usuarios(id_usuario) ON UPDATE RESTRICT ON DELETE RESTRICT,
                emitida_en timestamptz(6) NOT NULL,
                vencimiento_at timestamptz(6) NOT NULL,
                exigible boolean NOT NULL DEFAULT true,
                anulada_en timestamptz(6),
                id_cambio_anulacion bigint,
                CONSTRAINT obligaciones_cita_fk FOREIGN KEY (id_cita, incorporada_finanzas)
                    REFERENCES citas(id_cita, incorporada_finanzas) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT obligaciones_anulacion_fk FOREIGN KEY (id_cambio_anulacion, id_cita)
                    REFERENCES cambios_cita(id_cambio_cita, id_cita) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT obligaciones_conversion_ck CHECK (centavos_usd = (2 * centavos_bob + 7) / 14),
                CONSTRAINT obligaciones_anulacion_ck CHECK (
                    (exigible AND anulada_en IS NULL AND id_cambio_anulacion IS NULL) OR
                    (NOT exigible AND anulada_en IS NOT NULL AND id_cambio_anulacion IS NOT NULL AND anulada_en >= emitida_en)),
                CONSTRAINT obligaciones_importe_uq UNIQUE (id_obligacion, centavos_usd)
            );
            CREATE INDEX obligaciones_vencimiento_idx ON obligaciones_pago(vencimiento_at, id_obligacion) WHERE exigible;
            CREATE INDEX obligaciones_emision_idx ON obligaciones_pago(emitida_en, id_obligacion);
            CREATE INDEX obligaciones_emisor_idx ON obligaciones_pago(emitida_por);
            CREATE INDEX obligaciones_anulacion_idx ON obligaciones_pago(id_cambio_anulacion, id_cita);

            CREATE FUNCTION obligaciones_preservar_historia() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF ROW(NEW.id_obligacion, NEW.id_cita, NEW.incorporada_finanzas, NEW.centavos_bob, NEW.centavos_usd,
                       NEW.tasa_numerador, NEW.tasa_denominador, NEW.moneda_original, NEW.moneda_cobro, NEW.emitida_por, NEW.emitida_en)
                   IS DISTINCT FROM
                   ROW(OLD.id_obligacion, OLD.id_cita, OLD.incorporada_finanzas, OLD.centavos_bob, OLD.centavos_usd,
                       OLD.tasa_numerador, OLD.tasa_denominador, OLD.moneda_original, OLD.moneda_cobro, OLD.emitida_por, OLD.emitida_en) THEN
                    RAISE EXCEPTION 'El precio emitido y su autoría son históricos.'
                        USING ERRCODE = '23514', CONSTRAINT = 'obligaciones_historia_inmutable_ck';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER obligaciones_historia_inmutable_trg BEFORE UPDATE ON obligaciones_pago
                FOR EACH ROW EXECUTE FUNCTION obligaciones_preservar_historia();

            CREATE TABLE intentos_pago (
                id_intento uuid PRIMARY KEY,
                id_obligacion uuid NOT NULL REFERENCES obligaciones_pago(id_obligacion) ON UPDATE RESTRICT ON DELETE RESTRICT,
                iniciado_por bigint NOT NULL REFERENCES usuarios(id_usuario) ON UPDATE RESTRICT ON DELETE RESTRICT,
                proveedor varchar(8) NOT NULL,
                entorno varchar(8) NOT NULL,
                cuenta_contexto varchar(128) NOT NULL CHECK (length(btrim(cuenta_contexto)) > 0),
                estado varchar(20) NOT NULL DEFAULT 'Preparando'
                    CHECK (estado IN ('Preparando','RequiereAccion','Procesando','Exitoso','Fallido','Cerrado','Incierto')),
                captura_permitida boolean NOT NULL DEFAULT false,
                referencia_inicio varchar(255) CHECK (referencia_inicio IS NULL OR length(btrim(referencia_inicio)) > 0),
                referencia_pago varchar(255) CHECK (referencia_pago IS NULL OR length(btrim(referencia_pago)) > 0),
                creado_en timestamptz(6) NOT NULL,
                actualizado_en timestamptz(6) NOT NULL,
                proxima_revision_en timestamptz(6),
                revisado_en timestamptz(6),
                CONSTRAINT intentos_contexto_ck CHECK ((proveedor = 'stripe' AND entorno = 'test') OR (proveedor = 'paypal' AND entorno = 'sandbox')),
                CONSTRAINT intentos_captura_ck CHECK (NOT captura_permitida OR (proveedor = 'paypal' AND estado IN ('Preparando','RequiereAccion','Procesando','Incierto'))),
                CONSTRAINT intentos_fechas_ck CHECK (actualizado_en >= creado_en),
                CONSTRAINT intentos_obligacion_uq UNIQUE (id_intento, id_obligacion),
                CONSTRAINT intentos_contexto_uq UNIQUE (id_intento, proveedor, entorno, cuenta_contexto),
                CONSTRAINT intentos_inicio_uq UNIQUE (proveedor, entorno, cuenta_contexto, referencia_inicio),
                CONSTRAINT intentos_pago_uq UNIQUE (proveedor, entorno, cuenta_contexto, referencia_pago)
            );
            CREATE UNIQUE INDEX intentos_uno_activo_uq ON intentos_pago(id_obligacion)
                WHERE estado IN ('Preparando','RequiereAccion','Procesando','Incierto');
            CREATE INDEX intentos_historial_idx ON intentos_pago(id_obligacion, creado_en, id_intento);
            CREATE INDEX intentos_iniciador_idx ON intentos_pago(iniciado_por);
            CREATE INDEX intentos_revision_idx ON intentos_pago(proxima_revision_en, id_intento) WHERE proxima_revision_en IS NOT NULL;

            CREATE TABLE cobros_pago (
                id_cobro uuid PRIMARY KEY,
                id_intento uuid NOT NULL,
                id_obligacion uuid NOT NULL,
                proveedor varchar(8) NOT NULL,
                entorno varchar(8) NOT NULL,
                cuenta_contexto varchar(128) NOT NULL,
                referencia_cobro varchar(255) NOT NULL CHECK (length(btrim(referencia_cobro)) > 0),
                centavos_usd integer NOT NULL,
                moneda varchar(3) NOT NULL DEFAULT 'USD' CHECK (moneda = 'USD'),
                aplicado boolean NOT NULL,
                incidencia_codigo varchar(64),
                confirmado_en timestamptz(6) NOT NULL,
                registrado_en timestamptz(6) NOT NULL,
                CONSTRAINT cobros_intento_fk FOREIGN KEY (id_intento, id_obligacion)
                    REFERENCES intentos_pago(id_intento, id_obligacion) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT cobros_contexto_fk FOREIGN KEY (id_intento, proveedor, entorno, cuenta_contexto)
                    REFERENCES intentos_pago(id_intento, proveedor, entorno, cuenta_contexto) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT cobros_importe_fk FOREIGN KEY (id_obligacion, centavos_usd)
                    REFERENCES obligaciones_pago(id_obligacion, centavos_usd) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT cobros_incidencia_ck CHECK ((aplicado AND incidencia_codigo IS NULL) OR
                    (NOT aplicado AND incidencia_codigo IS NOT NULL AND incidencia_codigo ~ '^[A-Z][A-Z0-9_]{0,63}$')),
                CONSTRAINT cobros_referencia_uq UNIQUE (proveedor, entorno, cuenta_contexto, referencia_cobro),
                CONSTRAINT cobros_aplicacion_uq UNIQUE (id_cobro, aplicado),
                CONSTRAINT cobros_total_uq UNIQUE (id_cobro, centavos_usd),
                CONSTRAINT cobros_intento_uq UNIQUE (id_cobro, id_intento)
            );
            CREATE UNIQUE INDEX cobros_una_liquidacion_uq ON cobros_pago(id_obligacion) WHERE aplicado;
            CREATE INDEX cobros_intento_idx ON cobros_pago(id_intento, id_obligacion);

            CREATE TABLE devoluciones_pago (
                id_devolucion uuid PRIMARY KEY,
                id_cobro uuid NOT NULL UNIQUE,
                cobro_aplicado boolean NOT NULL DEFAULT true CHECK (cobro_aplicado),
                centavos_usd integer NOT NULL,
                id_cambio_cancelacion bigint NOT NULL REFERENCES cambios_cita(id_cambio_cita) ON UPDATE RESTRICT ON DELETE RESTRICT,
                motivo varchar(32) NOT NULL DEFAULT 'Cancelacion' CHECK (motivo = 'Cancelacion'),
                estado varchar(16) NOT NULL DEFAULT 'Pendiente' CHECK (estado IN ('Pendiente','Procesando','Exitosa','Fallida','Incierta')),
                creada_en timestamptz(6) NOT NULL,
                actualizada_en timestamptz(6) NOT NULL,
                CONSTRAINT devoluciones_aplicado_fk FOREIGN KEY (id_cobro, cobro_aplicado)
                    REFERENCES cobros_pago(id_cobro, aplicado) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT devoluciones_total_fk FOREIGN KEY (id_cobro, centavos_usd)
                    REFERENCES cobros_pago(id_cobro, centavos_usd) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT devoluciones_fechas_ck CHECK (actualizada_en >= creada_en),
                CONSTRAINT devoluciones_cobro_uq UNIQUE (id_devolucion, id_cobro)
            );
            CREATE INDEX devoluciones_cancelacion_idx ON devoluciones_pago(id_cambio_cancelacion);

            CREATE TABLE operaciones_pago (
                id_operacion uuid PRIMARY KEY,
                id_intento uuid NOT NULL,
                id_obligacion uuid NOT NULL,
                id_devolucion uuid,
                id_cobro uuid,
                proveedor varchar(8) NOT NULL,
                entorno varchar(8) NOT NULL,
                cuenta_contexto varchar(128) NOT NULL,
                tipo varchar(16) NOT NULL CHECK (tipo IN ('Crear','Capturar','Cerrar','Consultar','Devolver')),
                solicitada_por bigint REFERENCES usuarios(id_usuario) ON UPDATE RESTRICT ON DELETE RESTRICT,
                clave_solicitud uuid,
                huella_solicitud varchar(64) NOT NULL CHECK (huella_solicitud ~ '^[a-f0-9]{64}$'),
                datos_solicitud_cifrados text,
                clave_externa uuid UNIQUE,
                referencia_externa varchar(255) CHECK (referencia_externa IS NULL OR length(btrim(referencia_externa)) > 0),
                estado varchar(16) NOT NULL DEFAULT 'Pendiente' CHECK (estado IN ('Pendiente','Procesando','Exitosa','Fallida','Incierta')),
                ejecuciones integer NOT NULL DEFAULT 0 CHECK (ejecuciones >= 0),
                proxima_ejecucion_en timestamptz(6) NOT NULL,
                reclamado_hasta timestamptz(6),
                codigo_reclamo uuid,
                resultado_codigo varchar(64) CHECK (resultado_codigo IS NULL OR resultado_codigo ~ '^[A-Z][A-Z0-9_]{0,63}$'),
                creada_en timestamptz(6) NOT NULL,
                actualizada_en timestamptz(6) NOT NULL,
                CONSTRAINT operaciones_contexto_fk FOREIGN KEY (id_intento, proveedor, entorno, cuenta_contexto)
                    REFERENCES intentos_pago(id_intento, proveedor, entorno, cuenta_contexto) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT operaciones_obligacion_fk FOREIGN KEY (id_intento, id_obligacion)
                    REFERENCES intentos_pago(id_intento, id_obligacion) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT operaciones_devolucion_fk FOREIGN KEY (id_devolucion, id_cobro)
                    REFERENCES devoluciones_pago(id_devolucion, id_cobro) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT operaciones_cobro_fk FOREIGN KEY (id_cobro, id_intento)
                    REFERENCES cobros_pago(id_cobro, id_intento) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT operaciones_tipo_ck CHECK ((tipo = 'Devolver' AND id_devolucion IS NOT NULL AND id_cobro IS NOT NULL) OR
                    (tipo <> 'Devolver' AND id_devolucion IS NULL AND id_cobro IS NULL)),
                CONSTRAINT operaciones_clave_ck CHECK ((solicitada_por IS NULL AND clave_solicitud IS NULL) OR
                    (solicitada_por IS NOT NULL AND clave_solicitud IS NOT NULL)),
                CONSTRAINT operaciones_externa_ck CHECK (tipo = 'Consultar' OR clave_externa IS NOT NULL),
                CONSTRAINT operaciones_lease_ck CHECK ((estado = 'Procesando' AND reclamado_hasta IS NOT NULL AND codigo_reclamo IS NOT NULL AND ejecuciones > 0) OR
                    (estado <> 'Procesando' AND reclamado_hasta IS NULL AND codigo_reclamo IS NULL)),
                CONSTRAINT operaciones_fechas_ck CHECK (actualizada_en >= creada_en),
                CONSTRAINT operaciones_solicitud_uq UNIQUE (solicitada_por, tipo, id_intento, clave_solicitud),
                CONSTRAINT operaciones_referencia_uq UNIQUE (proveedor, entorno, cuenta_contexto, tipo, referencia_externa)
            );
            CREATE UNIQUE INDEX operaciones_una_escritura_uq ON operaciones_pago(id_obligacion)
                WHERE tipo <> 'Consultar' AND estado IN ('Pendiente','Procesando','Incierta');
            CREATE UNIQUE INDEX operaciones_inicio_solicitud_uq ON operaciones_pago(solicitada_por, id_obligacion, clave_solicitud)
                WHERE tipo = 'Crear';
            CREATE UNIQUE INDEX operaciones_una_devolucion_exitosa_uq ON operaciones_pago(id_devolucion)
                WHERE tipo = 'Devolver' AND estado = 'Exitosa';
            CREATE INDEX operaciones_recuperacion_idx ON operaciones_pago(estado, proxima_ejecucion_en, id_operacion);
            CREATE INDEX operaciones_devolucion_idx ON operaciones_pago(id_devolucion, id_cobro);
            CREATE INDEX operaciones_cobro_idx ON operaciones_pago(id_cobro, id_intento);

            CREATE TABLE eventos_pago (
                id_evento uuid PRIMARY KEY,
                proveedor varchar(8) NOT NULL,
                entorno varchar(8) NOT NULL,
                cuenta_contexto varchar(128) NOT NULL CHECK (length(btrim(cuenta_contexto)) > 0),
                id_externo varchar(255) NOT NULL CHECK (length(btrim(id_externo)) > 0),
                tipo varchar(128) NOT NULL CHECK (length(btrim(tipo)) > 0),
                huella_contenido varchar(64) NOT NULL CHECK (huella_contenido ~ '^[a-f0-9]{64}$'),
                referencias jsonb NOT NULL CHECK (jsonb_typeof(referencias) = 'object'),
                id_intento uuid,
                evidencia_cifrada text,
                estado varchar(20) NOT NULL DEFAULT 'Pendiente'
                    CHECK (estado IN ('Pendiente','PendienteVinculo','Procesando','Aplicado','Ignorado','Cuarentena','Incierto')),
                recepcion_en timestamptz(6) NOT NULL,
                procesado_en timestamptz(6),
                proxima_ejecucion_en timestamptz(6) NOT NULL,
                ejecuciones integer NOT NULL DEFAULT 0 CHECK (ejecuciones >= 0),
                reclamado_hasta timestamptz(6),
                codigo_reclamo uuid,
                incidencia_codigo varchar(64) CHECK (incidencia_codigo IS NULL OR incidencia_codigo ~ '^[A-Z][A-Z0-9_]{0,63}$'),
                CONSTRAINT eventos_contexto_ck CHECK ((proveedor = 'stripe' AND entorno = 'test') OR (proveedor = 'paypal' AND entorno = 'sandbox')),
                CONSTRAINT eventos_intento_fk FOREIGN KEY (id_intento, proveedor, entorno, cuenta_contexto)
                    REFERENCES intentos_pago(id_intento, proveedor, entorno, cuenta_contexto) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT eventos_identidad_uq UNIQUE (proveedor, entorno, cuenta_contexto, id_externo),
                CONSTRAINT eventos_lease_ck CHECK ((estado = 'Procesando' AND reclamado_hasta IS NOT NULL AND codigo_reclamo IS NOT NULL AND ejecuciones > 0) OR
                    (estado <> 'Procesando' AND reclamado_hasta IS NULL AND codigo_reclamo IS NULL))
            );
            CREATE INDEX eventos_recuperacion_idx ON eventos_pago(estado, proxima_ejecucion_en, id_evento);
            CREATE INDEX eventos_intento_idx ON eventos_pago(id_intento);

            CREATE FUNCTION pagos_rechazar_borrado() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'La evidencia financiera se conserva.'
                    USING ERRCODE = '23514', CONSTRAINT = 'pagos_evidencia_inmutable_ck';
            END;
            $$;
            CREATE TRIGGER cobros_inmutables_trg BEFORE UPDATE ON cobros_pago
                FOR EACH ROW EXECUTE FUNCTION pagos_rechazar_borrado();
            SQL);

        foreach (['obligaciones_pago', 'intentos_pago', 'cobros_pago', 'devoluciones_pago', 'operaciones_pago', 'eventos_pago'] as $tabla) {
            DB::unprepared("CREATE TRIGGER {$tabla}_no_borrar_trg BEFORE DELETE ON {$tabla} FOR EACH ROW EXECUTE FUNCTION pagos_rechazar_borrado();
                CREATE TRIGGER {$tabla}_no_truncate_trg BEFORE TRUNCATE ON {$tabla} FOR EACH STATEMENT EXECUTE FUNCTION pagos_rechazar_borrado();");
        }

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION eventos_preservar_recepcion() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF ROW(NEW.id_evento, NEW.proveedor, NEW.entorno, NEW.cuenta_contexto, NEW.id_externo, NEW.tipo,
                       NEW.huella_contenido, NEW.referencias, NEW.evidencia_cifrada, NEW.recepcion_en)
                   IS DISTINCT FROM
                   ROW(OLD.id_evento, OLD.proveedor, OLD.entorno, OLD.cuenta_contexto, OLD.id_externo, OLD.tipo,
                       OLD.huella_contenido, OLD.referencias, OLD.evidencia_cifrada, OLD.recepcion_en) THEN
                    RAISE EXCEPTION 'La recepción original se conserva.'
                        USING ERRCODE = '23514', CONSTRAINT = 'eventos_recepcion_inmutable_ck';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER eventos_recepcion_inmutable_trg BEFORE UPDATE ON eventos_pago
                FOR EACH ROW EXECUTE FUNCTION eventos_preservar_recepcion();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE eventos_pago;
            DROP TABLE operaciones_pago;
            DROP TABLE devoluciones_pago;
            DROP TABLE cobros_pago;
            DROP TABLE intentos_pago;
            DROP TABLE obligaciones_pago;
            DROP FUNCTION eventos_preservar_recepcion();
            DROP FUNCTION pagos_rechazar_borrado();
            DROP FUNCTION obligaciones_preservar_historia();
            DROP TRIGGER citas_incorporacion_inmutable_trg ON citas;
            DROP FUNCTION citas_preservar_incorporacion();
            ALTER TABLE cambios_cita DROP CONSTRAINT cambios_cita_identidad_uq;
            ALTER TABLE citas DROP CONSTRAINT citas_incorporacion_uq;
            ALTER TABLE citas DROP COLUMN incorporada_finanzas;
            SQL);
    }
};
