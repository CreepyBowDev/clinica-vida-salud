# Pagos — Fase 2: persistencia e importe exacto

**Estado posterior Fase4 (2026-11-06):** [Stripe test](pagos-fase4-stripe.md) reutiliza las seis entidades/restricciones de este esquema para intentos/operaciones/inbox/cobros/devoluciones; sin migraciones adicionales ni cambios del DER. Preparación durable, leases y coordinación mínima de cancelación verificados localmente; incorporación/Stripe apagados, base habitual sin migrar. Sin acreditar integración externa ni resto Fase6.

**Estado posterior 2026-11-06:** [Fase3 backend](pagos-fase3-backend.md) implementa emisión/asignación/consultas y habilitación defaultfalse sobre este mismo esquema, sin migraciones adicionales. Propuestas v2/corte sin backfill, reserva propia sin obligación y coordinación local de cancelación/reprogramación verificadas; resto de procesamiento/proveedores sigue pendiente. Los futuros mencionados abajo describen el cierre histórico de F2, no invalidan el avance de F3. Persistencia habitual sin migrar/activar.

**2026-11-05 · Infraestructura implementada y verificada en PostgreSQL de pruebas, no módulo operativo.** Fuente: autorización expresa de Fase 2, [especificación](pasarela-pagos.md), [contratos diseñados](pagos-fase1-contratos.md), VS-PAG-01–18. No nuevos RF/RN ni cambios de política. Fase 3 requiere autorización independiente.

## 1. Incorporación sin activación parcial

- Migración propia `backend/src/data/database/migrations/2026_11_05_000040_create_pagos_persistence.php`, transaccional en PostgreSQL. No backfill de obligaciones, importes ni pagos ficticios; no configuración de habilitación ni fecha de corte implícita.
- `citas.incorporada_finanzas BOOLEAN NOT NULL DEFAULT false`: existentes y todas las reservas actuales siguen excluidas, incluso si se confirman propuestas actuales después de instalar el esquema. Un trigger impide cambiar la marca después del INSERT, también false→true; no se reclasifica el legado.
- Una **futura** reserva autorizada, con activación y versión de propuesta controladas por servidor, insertará explícitamente true. El cambio operativo se hará después, no al aplicar esta migración. La fecha de despliegue/activación no está fijada por F2; no se infiere de `creada_en` ni del reloj del cliente.
- `false` = NoIncorporada; `true` sin obligación = PendienteAsignacion; `true` con obligación = Asignado. Son situaciones derivadas, no estados asistenciales ni tres columnas duplicadas.
- La obligación incluye un discriminador siempre true y FK compuesta a `(cita, incorporación)`: SQL rechaza emitir sobre citas excluidas. La columna repetida es soporte de integridad relacional, no una segunda decisión de incorporación.
- `Cita` conserva `guarded=*`, oculta la marca y la relación financiera en serialización genérica. No cambia Services, Requests, propuestas v1, Resources, rutas ni respuestas actuales. F3 deberá versionar propuestas no confirmadas; el replay de reservas ya confirmadas conserva su exclusión original.

## 2. Separación física y diccionario incremental

Seis tablas, no una tabla por cada etiqueta del contrato. No tabla independiente de liquidaciones, tarifas, claves de proveedor, jobs ni módulo genérico de incidencias. UUID locales asignados por futuro código de servidor; no confieren autorización. Fechas financieras `TIMESTAMPTZ(6)`; importes/tasa enteros, sin decimal flotante.

| Tabla / Model | Columnas y responsabilidad efectiva |
| --- | --- |
| `obligaciones_pago` / `ObligacionPago` | `id_obligacion` UUID PK; `id_cita` BIGINT UQ; `incorporada_finanzas` bool true; `centavos_bob/centavos_usd/tasa_numerador/tasa_denominador` INTEGER; `moneda_original/moneda_cobro` VARCHAR3; `emitida_por` FK Usuario; `emitida_en/vencimiento_at` fechas obligatorias; `exigible` bool; `anulada_en/id_cambio_anulacion` opcionales y coherentes. Paciente deriva de Cita, no se duplica. |
| `intentos_pago` / `IntentoPago` | `id_intento` UUID PK; `id_obligacion` UUID FK; `iniciado_por` BIGINT FK; `proveedor/entorno/cuenta_contexto`; `estado`; `captura_permitida`; `referencia_inicio/referencia_pago` opcionales; `creado_en/actualizado_en`; `proxima_revision_en/revisado_en` opcionales. Conserva sesión Checkout/orden y PaymentIntent sin duplicar importes. |
| `cobros_pago` / `CobroPago` | `id_cobro` UUID PK; `id_intento/id_obligacion`; contexto del proveedor; `referencia_cobro`; `centavos_usd/moneda`; `aplicado`; `incidencia_codigo`; `confirmado_en/registrado_en`. Hechos de cobro verificados, múltiples por intento/obligación si hay anomalía externa. No existe una columna liquidada adicional. |
| `devoluciones_pago` / `DevolucionPago` | `id_devolucion` UUID PK; `id_cobro` UUID UQ; `cobro_aplicado` bool true; `centavos_usd`; `id_cambio_cancelacion` BIGINT FK; `motivo=Cancelacion`; `estado`; `creada_en/actualizada_en`. Una devolución total lógica, no una fila por reintento externo. |
| `operaciones_pago` / `OperacionPago` | `id_operacion` UUID PK; `id_intento/id_obligacion`; `id_devolucion/id_cobro` solo para Devolver; contexto del proveedor; `tipo`; `solicitada_por/clave_solicitud` opcionales como par; `huella_solicitud`; `datos_solicitud_cifrados` opcional; `clave_externa/referencia_externa`; `estado/ejecuciones/proxima_ejecucion_en/reclamado_hasta/codigo_reclamo/resultado_codigo/creada_en/actualizada_en`. Reúne idempotencia humana, escrituras externas y trabajo recuperable; claves externas distintas por operación. |
| `eventos_pago` / `EventoPago` | `id_evento` UUID PK; contexto del proveedor; `id_externo/tipo/huella_contenido`; `referencias` JSONB objeto; `id_intento` nullable para eventos anteriores al vínculo; `evidencia_cifrada` opcional; `estado/recepcion_en/procesado_en/proxima_ejecucion_en/ejecuciones/reclamado_hasta/codigo_reclamo/incidencia_codigo`. Bandeja durable, no receptor HTTP implementado. |

Contexto = `proveedor VARCHAR8 + entorno VARCHAR8 + cuenta_contexto VARCHAR128`. Referencias externas VARCHAR255; códigos seguros VARCHAR64; huellas SHA-256 hex64. No credenciales ni URL de aprobación/Checkout persistida. Las referencias son identificadores, no URLs de red ni permisos. El DDL es la fuente exacta de nombres/nulabilidad/constraints, sin alterar migraciones previas.

### Relaciones y serialización

- Cita 0..1 ObligacionPago; obligación N:1 Usuario emisor y 0..1 CambioCita de anulación; obligación 1:N IntentoPago/CobroPago y 0..1 liquidación filtrada por aplicado.
- Intento N:1 Usuario iniciador; Intento 1:N Cobro/Operacion/Evento. Evento puede no tener intento al recibirse.
- Cobro 0..1 Devolucion; Devolucion N:1 CambioCita y 1:N Operacion. Operacion enlaza intento, cobro/devolución cuando corresponde, y solicitante humano nullable; NULL representa trabajo de sistema, no un quinto rol.
- Los seis Models tienen PK string no incremental, timestamps explícitos, `guarded=*` y `visible` limitada al ID local. Los futuros DTO autorizados construirán el contrato humano; serializar un Model no lo implementa. Fechas immutable, importes/contadores integer, flags boolean.
- `datos_solicitud_cifrados/evidencia_cifrada` usan `encrypted:array`, sin payload externo completo por defecto. La lista permitida de referencias/datos recuperables y la minimización de PII corresponden al futuro receptor/Service, no quedan garantizadas por un JSONB ni por el cifrado. No registrar cuerpos técnicos o secretos en bitácora.

## 3. Garantías reales de PostgreSQL

| Garantía local | Mecanismo |
| --- | --- |
| Solo una obligación por cita incorporada | UQ `id_cita`, CHECK discriminador true y FK compuesta a Cita. |
| Precio histórico exacto e inmutable | BOB 700–700000; USD 100–100000; tasa 7/1; monedas BOB/USD; CHECK `USD=(2*BOB+7)/14` con división entera; trigger preserva cita/precio/tasa/monedas/emisor/emisión. Vencimiento y exigibilidad quedan separados. |
| Solo un intento cobrable/activo/incierto | Índice parcial por obligación para Preparando/RequiereAccion/Procesando/Incierto. Permite varios Exitoso históricos para conservar anomalías. |
| Solo una escritura pendiente/en vuelo/incierta | Índice parcial de operaciones **por obligación**, entre proveedores y tipos distintos, excepto Consultar. Incluye recuperación de devoluciones. No confundir esta exclusión con un lock distribuido del proveedor. |
| Contextos académicos y referencias únicas | Intento/Evento solo stripe-test o paypal-sandbox. UQ por contexto para referencia_inicio, referencia_pago y referencia_cobro; operaciones por contexto/tipo/referencia_externa. FK compuestas impiden mezclar contexto/obligación de otro intento. |
| Idempotencia local y externa | UQ actor/tipo/intento/clave_solicitud; para Crear también actor/obligación/clave, impidiendo duplicar inicio con otro intento. Clave externa UUID única global, obligatoria en escrituras; hash de solicitud requerido. |
| Una aplicación sin ocultar doble cobro | Índice parcial de Cobro por obligación WHERE aplicado; no aplicado requiere incidencia segura. FK compuestas cotejan obligación/contexto/USD con el intento y precio. Cobros son inmutables; no se reasigna el aplicado. |
| Devolución total solo del aplicado | UQ por cobro; CHECK cobro_aplicado=true y FK compuesta a Cobro; FK compuesta por importe exige el USD efectivamente registrado, sin reconversión. Operación Devolver enlaza la misma devolución/cobro/intento. Solo una operación Devolver Exitosa por devolución lógica. |
| Eventos deduplicados sin perder recepción | UQ proveedor/entorno/cuenta/ID externo. Trigger preserva ID/contexto/tipo/huella/referencias/evidencia/recepción originales. Un evento sin vínculo puede persistir antes de recibir respuesta externa. |
| Historia conservada y recuperación representable | FK RESTRICT UPDATE/DELETE; triggers prohíben DELETE/TRUNCATE en las seis tablas. Procesando exige lease/token/contador positivo; fuera de Procesando lease/token deben ser NULL. Trabajo Pendiente/Incierto conserva claves y referencias. |

Los estados de intento, operación, devolución y evento tienen CHECK de vocabulario. Los cinco estados asistenciales no se modifican; Vencida no se persiste. La liquidación se consulta por Cobro.aplicado, incluso si luego hubo devolución: no se borra el hecho histórico.

## 4. Garantías pendientes de transacciones/Services

**Incremento posterior autorizado (2026-11-05):** coordinación local con gestión ya implementada y verificada; [análisis](analisis-clases.md#pagos-coordinacion-local-citas). GestionCitaService coordina Cita→obligación bajo su transacción: retirar exigibilidad/vincular CambioCita al cancelar y ajustar vencimiento exigible impagado al reprogramar, con auditoría atómica. Solo esta parte de los puntos siguientes deja de ser futura. No cierre/reconciliación/refund ni trabajo externo; emisión/asignación/consultas pendientes, incorporación sin activar. Esquema F2 y garantías físicas intactos.

**No existe todavía una garantía operativa integral de pagos.** SQL no verifica firmas, dinero externo, autorización humana ni el estado autoritativo del proveedor.

- Emisión: lock de Cita, incorporación y rol, no Cancelada, propuesta revisada/idempotencia y creación Cita/Obligacion/Bitacora atómicas. El precio no se asigna por migración ni por lectura.
- Inicio/cierre: lock de obligación y operaciones; no emitir si liquidada/no exigible, titularidad real y coherencia entre estado del intento y operaciones. Los índices excluyen filas activas dentro de cada tabla, pero **no prueban** que un intento Cerrado/Fallido sea incapaz de cobrar ni impiden por sí solos que se marque terminal mientras una operación de otra tabla sigue incierta.
- Captura: permiso PayPal y cuenta habilitada vigentes; reservar antes de red, nunca capturar automáticamente por aprobación/webhook. Llamadas fuera de transacciones SQL.
- Aplicación: cotejar contexto, destinatario, USD y evidencia; actualizar intento/operación, insertar cobro aplicado o excepcional e incidencia/auditoría en una transacción. SQL puede rechazar discrepancias, pero no fabrica la evidencia ni registra automáticamente el segundo éxito.
- Cancelación: verificar que CambioCita represente cancelación real de la misma cita; actualizar exigibilidad/auditar/registrar trabajo en transacción. La FK de anulación de obligación asegura misma cita; la FK de cancelación de devolución **no verifica por sí sola** operación/estado ni correspondencia asistencial con el cobro. Ese vínculo semántico debe revalidarse bajo lock antes de crear devolución.
- Reprogramación: conservar obligación/precio/pago; ajustar vencimiento solo si impagada y auditar valores anterior/nuevo, sin ampliar reglas asistenciales. Vencida se calcula usando servidor y liquidación real.
- Recuperación: reclamar mediante lease, recuperarlo tras caída, consultar estado externo y conservar claves/cuerpo estables antes de reintentar. El esquema no implementa worker, scheduler, backoff ni política de ventanas externas. Incierto no autoriza clave nueva; campos de reintento no prueban recuperación ejecutada.
- Evento duplicado con huella incompatible: conservar recepción original, registrar anomalía segura y no aplicar. Acuse solo tras commit durable. No existe aún endpoint, validación de firma ni acuse.
- Autoría de seguridad y trazabilidad: reutilizar Bitacora/CambioCita con ejecutor real y referencias locales; códigos de incidencia no deben contener mensajes externos. No endpoint para marcar éxito manualmente.

## 5. Aritmética implementada

`Clinica\shared\utils\ImportePago` es un valor puro readonly, sin dependencias HTTP/Eloquent/configuración. No Service operativo ni proveedor implementado.

- `desdeBob(mixed)` exige **string** con el patrón exacto de F1; rechaza números JSON, float, signos, espacios, comas, exponentes, ceros iniciales, cadenas vacías y más de dos decimales. Valida antes de normalizar; sin redondear entradas inválidas.
- Convierte por separación de parte entera/decimal y padding; rango inclusivo de centavos. Calcula USD una vez: `intdiv(2*centavosBob+7,14)`, HALF_UP para positivos. Con divisor 7 no existe empate exacto de medio centavo; se prueban los restos 3/7 y 4/7 y todo el intervalo permitido.
- `desdeHistorico(bob,usd,numerador,denominador)` recupera los importes persistidos y valida el esquema académico sin reemplazar el USD. No lee una futura configuración ni actualiza históricos. Otra política futura requeriría versionado autorizado, no modificar este snapshot.
- `montoBob()` normaliza a dos decimales; `centavosStripe()` devuelve USD integer; `montoPayPal()` construye los mismos centavos como decimal string de dos posiciones, sin float ni reconversión.
- InvalidArgumentException es local a esta utilidad pura; el futuro Validator/FormRequest deberá traducir entradas inválidas a 422 conforme a F1. No se añaden códigos HTTP ni se altera el mapeo central ahora.

## 6. Verificación y límites

- PostgreSQL local **18.6**: antes de pruebas se comprobó entorno testing, DB/rol/propietario `clinica_vida_salud_test`, sin DB_URL ni configuración cacheada y sin superusuario/CREATEDB/CREATEROLE. La base ya existía; no se crearon bases/roles ni se arrancaron servicios desde el agente.
- `ImportePagoTest` y `PagosPersistenciaTest`: formatos, límites, 699301 importes posibles con oráculo cociente/resto independiente; snapshot/proveedores; cita anterior a migración; SQL UQ/FK/CHECK/triggers; slots entre proveedores, cobros múltiples, devolución total/deduplicación/leases/claves/cifrado/relaciones/privacidad.
- Regresión dirigida: `ReservaIntegracionTest` y `GestionCitaIntegracionTest`, con propuestas/sesiones/replay/reserva propia y administrativa/reprogramación/cancelación existentes. HTTP externo impedido por TestCase; sin proveedores ni correos reales.
- Batería única: **74 pruebas, 728 aserciones, correctas**, 13,232 s/96 MB. PHP 8.5.11/PHPUnit 12.5.40. `php -l` 11 archivos sin errores; Pint dirigido correcto; `composer dump-autoload --no-scripts` correcto. Logs fuera del repositorio en Temp/opencode, prefijo `pagos-fase2-`.
- Esquema completo preparado/migrado dentro de transacciones de pruebas y revertido con rollback. No migrate:fresh, borrado masivo, recreación de base ni migración habitual/remota. El método down no se ejecutó: no se acredita reversión destructiva.
- No prueba multiproceso de carreras financieras, firma/eventos reales, worker/recuperación operativa, cuentas USD, cierre/captura/refund externos, frontend ni despliegue. No CU-31–34 completado por esta infraestructura.

**Continuación:** revisar F2 y solicitar autorización expresa para F3. La instalación permanente/activación en base habitual y cualquier integración externa siguen fuera de esta autorización.
