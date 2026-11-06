# Pagos — Fase 5: PayPal Sandbox (2026-11-06)

## 1. Alcance y autorizaciones

Soporte backend local de Orders/aprobación/captura explícita, postback de firma, inbox, idempotencia, recuperación básica y devolución total Sandbox. Autorización adicional expresa: coordinación mínima PayPal de cancelación, revocación de nuevas capturas, recuperación de capturas en vuelo y devolución del aplicado confirmado antes/después de cancelar. No resto F6, cambio operativo de proveedor, conciliador genérico, resolución general de incidencias, React/F7/F8 ni integración externa acreditada. [Resultado de verificaciones y relevo](estado-fase.md).

**Incorporación, Stripe y PayPal continúan false por defecto.** No credenciales privadas, operaciones externas autenticadas, nuevas dependencias/migraciones, activación/migración habitual ni despliegue. Composer/lock y esquema F2 conservados. Los siete avisos previos en cuatro paquetes Symfony siguen pendientes; no se deshabilitaron controles ni actualizaron dependencias ajenas. No RF/RN, Word/PDF ni diagramas académicos modificados.

## 2. Configuración y frontera externa

- `config/pagos.php`: `PAGOS_PAYPAL_HABILITADO=false`, `PAYPAL_ENVIRONMENT=sandbox`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_APP_ID`, `PAYPAL_MERCHANT_ID`, `PAYPAL_WEBHOOK_ID`, `PAGOS_ORIGEN_RETORNO`. No valores privados en ejemplos/repositorio. APP_ID es un identificador esperado adicional de la respuesta OAuth: permite rechazar app incompatible; no se supone igual al client_id.
- `ConfiguracionPayPal`: solo Laravel local/testing, configuración completa y retorno HTTPS exactamente permitido por CORS. Contexto persistido `sandbox:SHA256(client_id|app_id|merchant_id)`, sin secreto. Cambiar contexto invalida revisión y no procesa operaciones de otra app/merchant. Identificadores configurados no acreditan cuentas reales: esa comprobación externa sigue pendiente.
- `PayPalGateway`: Laravel HTTP existente, host de todas las llamadas **fijo** `https://api-m.sandbox.paypal.com`. Sin redirects, URLs arbitrarias, fallback live, SDK adicional ni retries automáticos. Conexión5s/total20s; guard contra SQL abierto. Identificadores externos acotados antes de componer rutas.
- OAuth client_credentials/BasicAuth exclusivamente backend; token Bearer y expiración/app_id comprobados, memoria del Gateway hasta expiración con margen, sin persistencia/logs. No compartir token con React. Error OAuth/red/respuesta incompatible no fabrica rechazo financiero definitivo.
- GET públicos de especificaciones oficiales consultados sin autenticación: [Orders v2](https://github.com/paypal/paypal-rest-api-specifications/blob/main/openapi/checkout_orders_v2.json), [Payments v2](https://github.com/paypal/paypal-rest-api-specifications/blob/main/openapi/payments_payment_v2.json). No equivalen a verificar una cuenta Sandbox ni payloads reales.

## 3. HTTP humano y presentación

Rutas comunes existentes, sin duplicarlas por proveedor:

| POST, prefijo `/api` | Efecto efectivo |
| --- | --- |
| `pagos/obligaciones/{uuid}/intentos` | JSON cerrado `proveedor=stripe|paypal,revision_pago`, UUIDv4 Idempotency-Key. Solo titular. PayPal prepara orden con USD histórico y contexto revisado. |
| `pagos/intentos/{uuid}/resultado` | Objeto `{}`, sin query ni IDs del navegador; consulta durable/recuperación. Nunca captura por retorno. |
| `pagos/intentos/{uuid}/cierre` | Revoca permiso local PayPal y prepara consulta. No cancela cita, no solicita refund ni inventa expiración Orders. |
| `pagos/intentos/{uuid}/paypal/captura` | `{}` y UUIDv4; solo titular y proveedor real PayPal. Orden aprobada verificada por GET, obligación exigible y permiso local. Preparación durable antes del envío. |

`PagoController`/`IntentoPagoRequest` sustituyen los nombres específicos `StripePagoController`/`IntentoStripeRequest` de F4; conservan las URLs/contratos Stripe y agregan despacho mínimo mediante `OperacionPagoService`. No es un conciliador multiproveedor. Sesión/cuenta/rol/origen/CSRF/throttle existentes intactos. Captura se clasifica como escritura en el limiter existente:10/min usuario,3/min recurso; consulta30/6. No nuevas facultades de Administrador/Recepción/Médico.

Revisión opaca ligada a actor/obligación/BOB/USD/contexto/15min. Cuando ambos están disponibles, revisión Stripe incluye también contexto PayPal; no concede autorización ni cambia proveedor. Resource obtiene elegibilidad solo de configuraciones válidas, sin consultas al proveedor. Apagadas/inválidas → false/null; histórico de otro proveedor bloquea inicio/cambio operativo incluso si cerró. Slots activos/inciertos se comprueban **antes** de esa restricción y son comunes a ambos proveedores.

Respuestas no-store/private:201 nueva acción corroborada,200 resultado/replay,202 preparación/incertidumbre con Retry-After5/Location local. URL transitoria solamente para orden propia corroborada, HTTPS/host exacto `www.sandbox.paypal.com` y token de la orden coincidente. Orden APPROVED no tiene por qué ofrecer redirección y no implica pago. Sin URL corroborada pendiente no se devuelve acción especulativa. Datos públicos mínimos, nunca contexto, claves, OAuth, firma, comprador ni clínica.

## 4. Persistencia, exclusión y dinero

Se reutilizan las seis tablas/Models/constraints F2, sin otro sistema financiero. `StripePagoRepository` mantiene sus métodos comunes de lock/persistencia/replay/slots; `PayPalPagoRepository` contiene solo consultas específicas Sandbox/contexto/eventos y cuenta de captura. No `BaseRepository` ni migración nueva.

- Orden común Cita→obligación→operación; la comprobación final de cuenta toma Usuario antes de Cita, compatible con autenticación. Slots/indexes parciales por obligación, entre proveedores, más leases/token5min protegen procesos concurrentes.
- Crear/Capturar/Devolver tienen UUID externos independientes durables; no se envía la clave humana a PayPal. Misma clave/entrada devuelve estado actual; entrada/tipo incompatibles →409. Captura con otra clave no abre otra operación cobrable.
- Crear: intent CAPTURE, una purchase_unit, custom_id/reference_id=UUID local, payee merchant configurado, `ImportePago.montoPayPal()` histórico string USD de dos decimales. Experiencia NO_SHIPPING, retornos fijos; sin AUTHORIZE/vault/parciales/precios del navegador.
- Preparación SQL antes de red; claim corto, llamadas sin locks, aplicación en otra transacción con token vigente. Error de auditoría revierte aplicación/cobro/devolución local conjuntamente. No se borra evidencia histórica ni se degradan éxitos por eventos viejos.
- Una captura total verificada crea CobroPago, aplicado solo si no existe otro. Cobro excepcional adicional se conserva no aplicado con código seguro; nunca devolución automática por duplicación. Cobrar no cambia estados asistenciales.

## 5. Evidencia y recuperación básica

`EvidenciaPayPalService` valida GET Order y GET Capture: orden local/intent CAPTURE, única purchase_unit, IDs opacos, merchant, USD exacto, captura final/estado/bruto, ID embebido y vínculo up a la orden Sandbox. El esquema oficial GET Capture **no garantiza payee ni supplementary_data**: el destinatario deriva de la purchase_unit autenticada y el vínculo de captura; si esos campos adicionales aparecen, una contradicción se rechaza. Los enlaces up solo se comparan contra destinos Sandbox fijos; nunca se siguen como URL de red.

`RecuperacionPayPalService` consulta primero estado autoritativo. Crear perdido se recupera con mismo cuerpo/clave en ventana conservadora6h, o evento firmado que localiza el intento existente. Captura perdida se recupera por Order/Capture; solo repite mismo POST/clave dentro6h con obligación/cuenta aún habilitadas. Después de cancelar no reenvía captura, aunque conserve consulta de la ya entregada. Sin evidencia suficiente mantiene Incierto y exclusión; no clave nueva ni fallo inventado por timeout/ventana vencida.

`pagos:recuperar-paypal --lote=100` (1–1000), scheduler cada minuto/withoutOverlapping de apoyo, procesa exclusivamente contexto PayPal configurado. Apagado no consulta tablas ni hace red. Consultas automáticas se coalescen; referencias/eventos tempranos reactivan consultas específicas. No hay broker, dispatcher financiero ni conciliador genérico.

## 6. Webhook y acuse durable

`POST /api/webhooks/pagos/paypal` se registra junto al receptor Stripe, **fuera de los grupos api/web**, sin cookies/sesión/origenSPA/CSRF. No modifica excepciones CSRF ni debilita rutas humanas. JSON, cuerpo original≤1MiB, cinco cabeceras necesarias no vacías/acotadas/sin controles. SHA256withRSA y CERT-URL solo HTTPS/ruta certs/hosts Sandbox exactos; **no se descarga el certificado**.

Postback oficial `/v1/notifications/verify-webhook-signature`, OAuth y Webhook ID configurados; se inserta `webhook_event` preservando los bytes originales del objeto. Solo verification_status=SUCCESS permite persistir. Firma inválida400; verificación incierta/caída503 sin inbox ni falso acuse. Configuración inválida503. Límite413. Verificación remota simulada en pruebas, sin bypass configurable.

Inbox proveedor/sandbox/app-merchant/ID evento, advisory lock y SHA256 original: duplicado idéntico no duplica recepción; ID con contenido distinto409 sin sustituir original. Persistencia fallida503; **200 JSON `{data:{recibido:true}}` solo después del commit** (concreción F1 para PayPal, Stripe conserva204). No cuerpos completos, firmas, tokens ni datos del comprador persistidos/logueados.

APPROVED solo despierta consulta. CAPTURE.COMPLETED/PENDING/DECLINED/REFUNDED desencadenan GET verificados, nunca aplicación de payload aislado. REVERSED queda Cuarentena para revisión posterior, sin refund/reapertura automáticos. Desconocidos Ignorado durable; sin vínculo PendienteVinculo con backoff30s–1h. Evento temprano correlaciona intento ya existente por UUID/orden/captura, sin fabricar obligaciones. Evento refund proporciona solo pista de ID: GET debe comprobar estado/total/captura original.

## 7. Coordinación mínima adelantada de F6

`CoordinacionFinancieraCitaService` conserva exigibilidad/CambioCita y delega preparación a ambos procedimientos específicos; cada uno actúa únicamente sobre su proveedor real. Preparación compartida de devolución lógica en `DevolucionCancelacionService`, con auditoría PAGOS mediante `BitacoraRepository.pago`; `stripe()` conserva su entrada previa. Sin red en gestión de cita.

- Cancelar revoca captura_permitida bajo el mismo lock y prepara recuperación. Antes de primer envío, Crear/Capturar pueden finalizar sin abrir orden/cobrar. No se presupone API de expiración PayPal.
- Captura usa comprobación final bloqueada de cuenta/titular/exigibilidad/permiso y marcador durable `enviada` antes de red. Este **handoff es el punto local de serialización**, no prueba de que PayPal recibió el POST. Cancelación anterior gana; posterior trata el handoff como en vuelo/incierto. No es posible hacer atómicos commit PostgreSQL y recepción remota sin mantener locks durante red; no se promete deshacer una solicitud entregada. Cancelación observada antes de reintento impide todo nuevo POST de captura.
- Orden sin captura enviada/pending puede cerrarse tras consulta y permiso revocado. Captura en vuelo/timeout conserva exclusión y consultas; no se libera solo porque GET aún no muestre captura. Pago tardío se conserva, no reabre cita y prepara devolución del aplicado.
- Cancelación con aplicado crea una devolución lógica total durable y una operación cuando queda libre el slot; trabajo se conserva aunque se apaguen flags. Fallo de auditoría local revierte la preparación; error externo nunca revierte cancelación. Reprogramación/precio histórico y reglas asistenciales previas no cambian.

## 8. Devolución total y límites explícitos

POST `/v2/payments/captures/{capture_id}/refund` cuerpo `{}`, clave propia; solo aplicado y cancelación real. GET Order/Capture antes de enviar: contexto, total y captura original; estado parcial/refunded externo sin referencia reconciliable bloquea otra solicitud. Se devuelve USD realmente cobrado, nunca BOB reconvertido. Cobro adicional no aplicado queda fuera.

GET `/v2/payments/refunds/{id}` verifica ID, USD total, vínculo up exacto a captura original y cancelación; COMPLETED→Exitosa, PENDING→Procesando, FAILED/CANCELLED→Fallida, desconocido→Incierta. POST/HTTP200 no prueban devolución. Una devolución lógica por cobro, claves/leases/índices y no repetir éxitos impiden duplicación local.

**Limitación conservadora intencional:** no se supone para refunds la ventana Orders ni una búsqueda/listado que Payments v2 no ofrece aquí. Tras handoff perdido sin ID se conserva Incierta **sin repetir POST**; un evento REFUNDED verificado aporta la referencia para GET y recuperación. Si no llega, requiere revisión/autorización posterior; no se afirma recuperación autónoma garantizada ni se crea otra clave. Fallos terminales tampoco crean nueva devolución automáticamente. Verificación de actividad manual externa y de capacidades/eventos reales de cuentas concretas sigue pendiente, sin habilitación habitual.

## 9. Archivos de F5

- Nuevos Services: `ConfiguracionPayPal`, `PayPalGateway`, `PayPalPagoService`, `RecuperacionPayPalService`, `EvidenciaPayPalService`, `EventoPayPalService`, `OperacionPagoService`, `DevolucionCancelacionService` en `backend/src/business/services/pagos/`.
- Nuevo `backend/src/data/repositories/pagos/PayPalPagoRepository.php`; reutilizados/ampliados `StripePagoRepository`, `BitacoraRepository`, `StripePagoService`, `CoordinacionFinancieraCitaService`.
- HTTP: nombres comunes `PagoController`, `IntentoPagoRequest`; nuevo `WebhookPayPalController`; ajustes de Resource/RespuestaError/rutas/limiter; config y comando/scheduler PayPal.
- Pruebas `backend/tests/Feature/PagosPayPalTest.php`, `backend/tests/Fixtures/PayPalHttpSimulado.php`, `backend/tests/Fixtures/paypal-worker.php`. Stripe y pruebas anteriores conservadas, sin dependencias nuevas.
- Docs: esta entrega, F1/especificación, análisis/contexto/backend README y relevo compacto. Notas antiguas F1–F4 conservan cronología y nombres históricos.

## 10. Pendientes que no son parte de esta entrega

Integración real Sandbox (OAuth/app/merchant/USD extremos/aprobación/capturas/refunds/eventos/HTTPS/entrega/runtime web); credenciales y operaciones externas requieren otra autorización. Cambio operativo entre proveedores, conciliador genérico, gestión general de incidencias/dobles cobros y resto F6; React/F7 e integración académica completa/F8. Sin CU-32 anunciado operativo por superar mocks. Detenerse tras revisión de F5; no activar flags ni continuar fases automáticamente.

## 11. Verificación ejecutada

- Final dirigida: **324 pruebas/3616 aserciones correctas**, PHP8.5.11/PHPUnit12.5.40, **código nativo0**,114,147s/142MB. Son las281 pruebas de regresión F4 más43 PayPal; incluye carrera PayPal con dos procesos reales/lease/cancelación durante red simulada, carrera Stripe y seis financieras previas. No suite global rutinaria.
- OAuth/HTTP/postback/proveedores íntegramente simulados y peticiones no simuladas bloqueadas. Se probaron roles/ajenos, revisión/manipulación/históricos, idempotencia/respuesta perdida, aprobación sin captura automática, evidencia incompatible, firma inválida/verificación caída/persistencia fallida, deduplicación/eventos tempranos, timeout/ventana/exclusión, auditoría atómica, cancelación antes/durante captura, cobro tardío/refund único/respuesta perdida, doble cobro no aplicado, parcial externa, bloqueo mutuo Stripe/PayPal, no cambio operativo y flags apagadas. No resultados reales PayPal Sandbox acreditados.
- PostgreSQL local18.6: revalidación previa y final de testing/pgsql, DB/rol/propietario clinica_vida_salud_test, sin privilegios elevados/membresías/URL alternativa/config cacheada. Migraciones **existentes** solo en esquemas propios aislados; commits de pruebas/carreras y limpieza puntual. public0 tablas/funciones financieras al final; esquemas PayPal/Stripe0; antiguo ajeno `prueba_auth_sx1lpauqw780c1tw` conservado.
- Dos intentos incompletos no sumados al final: primero interrumpido por límite CLI128MiB en caso sobredimensionado; segundo por timeout del wrapper120s. Final ejecutada sin timeout de wrapper y con512MiB solo en ese proceso. Esquema propio del fatal identificado inmediatamente y eliminado individualmente al concluir; no borrado de esquemas ajenos.
- `php -l`26 PHP, Pint dirigido y `composer dump-autoload --no-scripts` correctos. Primer intento de invocación Composer desde .bat no encontró composer.phar; invocación absoluta del phar terminó0. No instalar/actualizar paquetes ni modificar composer.json/lock durante F5.
- Logs externos `Temp/opencode/pagos-fase5-*`: `final3-{stdout,stderr,exit}.log` contiene la final nativa; `final*` anteriores conservan interrupciones. Scripts/diagnóstico solo fuera del repo. Flags comprobadas false/false/false al final. Revisión pendiente del usuario antes de continuar.
