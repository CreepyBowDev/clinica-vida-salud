# Pagos — Fase 4: Stripe test (2026-11-06)

**Backend implementado y verificado localmente con simulaciones; pendiente de revisión y de integración externa autorizada.** No pagos reales, activación habitual, PayPal, React ni cierre integral del módulo. Autorizaciones adicionales: SDK oficial exacto y coordinación Stripe indispensable de cancelación/cobro tardío; no resto de Fase6. Documentos anteriores conservan su cronología, no describen el estado actual de Stripe.

## 1. Dependencia y entorno

- Instalado `stripe/stripe-php:22.1.0`, referencia `24ffd4c5708bdd490362baedb1df8efe4bf76484`, API fija `2026-10-28.endive`. Composer: un paquete añadido, ninguno actualizado/eliminado. Laravel13.37.0/Sanctum4.3.3/PHPUnit12.5.40 conservados; SDK sin dependencia Laravel. PHP CLI8.5.11 satisface PHP>=7.4/curl/json/mbstring. No prueba del runtime web.
- Dry-run antes de instalar. Instalación sin scripts; autoload separado sin scripts. Composer señala **siete avisos existentes en cuatro paquetes Symfony** (http-foundation/http-kernel/mime/routing); no se corrigieron ni deshabilitaron controles de auditoría. La instalación terminó materialmente aunque su auditoría devolvió aviso/código no cero. `composer validate --strict --no-check-publish` advierte del pin exacto solicitado: no se presenta como strict correcto.
- `config/pagos.php`: incorporación y `PAGOS_STRIPE_HABILITADO` **false por defecto**. No archivos privados modificados, secretos configurados ni llamadas autenticadas. Solo entornos Laravel local/testing; claves `sk_test_`, cuenta propia `acct_`, secreto `whsec_`, API fija y retorno HTTPS exactamente incluido en `cors.allowed_origins`. Prefijo no sustituye comprobar cuenta SDK y objetos test. No Connect/Organizations.
- Futuras variables privadas: STRIPE_SECRET_KEY, STRIPE_ACCOUNT_ID, STRIPE_WEBHOOK_SECRET, PAGOS_ORIGEN_RETORNO. No se proporcionan credenciales en ejemplos/chat/Git. PAGOS_INCORPORACION_HABILITADA es independiente: Stripe no incorpora reservas ni reclasifica legado.

## 2. Frontera HTTP y presentación

| Ruta (prefijo /api) | Implementación efectiva |
| --- | --- |
| POST pagos/obligaciones/{uuid}/intentos | Paciente titular, JSON exclusivamente proveedor=stripe/revision_pago, Idempotency-Key UUIDv4. Token cifrado actor/obligación/precio/cuenta, 15min, revalidación bloqueada. Exigible/no Cancelada/sin aplicado ni intento o escritura activos. |
| POST pagos/intentos/{uuid}/resultado | Titular, objeto JSON vacío, UUIDv4; prepara consulta durable y recupera fuera de transacción. No parámetros externos ni efecto financiero por retorno. |
| POST pagos/intentos/{uuid}/cierre | Titular, objeto JSON vacío/UUIDv4; cierre o consulta con intención durable de cerrar si hay otra escritura. No cancela cita ni devuelve por sí solo. |
| POST webhooks/pagos/stripe | Grupo propio registrado en `withRouting(then:...)`, fuera de api/web. Sin sesión/cookies/CSRF/origen SPA. Firma SDK sobre getContent(), tolerancia300s; máximo1MiB/firma4096bytes; JSON. 204 solo después del commit durable. |

- Rutas humanas conservan auth:sanctum/cuenta/sesión normal/rol/origen/CSRF/no-store. Administrador consulta pero no paga; sin nuevas proyecciones para Recepción/Médico. No rutas PayPal/captura manual ni endpoints para marcar éxito.
- Acción F1: id,obligacion_id,proveedor,estado,aplicado,accion={tipo:Redirigir,url}|null. Resultado con obligación/cobro/devolución mínimos, puede_reintentar,operacion_en_verificacion,ultima_verificacion_at. 201 solo nueva acción corroborada; 200 replay/terminal verificado, 202 incierto/no listo con Retry-After5 y Location local. Nunca URL especulativa en 202; retorno no liquida.
- Throttle: inicio/cierre10 por usuario/min y3 por UUID de recurso; resultado30/6. Inicio usa UUID de obligación; cierre usa UUID de intento de su ruta, concreción técnica respecto al agrupamiento por obligación previsto en F1. No cuotas humanas en webhook. No código de negocio basado en texto externo.
- Mientras Stripe esté apagado o incompletamente configurado, `puede_iniciar_pago=false,revision_pago=null`; Administrador siempre conserva esos valores. Configuración válida no equivale a prueba de cuenta externa ni a autorización de activación habitual.

## 3. Persistencia, transacciones e idempotencia

- **Sin migraciones nuevas ni cambios de Models/DDL:** reutilizadas las seis entidades F2, sus índices/restricciones, encrypted:array y dinero histórico ImportePago. Sin float/reconversión; USD/centavos inmutables de obligación, línea genérica/cantidad1/card/payment/sin adaptive pricing. Metadata solo UUIDs locales; sin clínica, CI, nombre o diagnóstico.
- Orden Cita→obligación→intento/operación, compatible con gestión. Crear se prepara y audita antes de red con cuerpo cifrado/clave externa UUID distinta de clave de navegador. Replay durable por actor/obligación/clave y huella de revisión. Precio del cliente/URLs/moneda/proveedor ajeno rechazados.
- Operaciones reclamadas con token, lease5min, contador y próxima ejecución. Claim y aplicación en transacciones separadas; HTTP SDK fuera de SQL/locks. Finalización solo del lease vigente; evidencia convergente puede resolver Crear/Cerrar antes de su respuesta. Localizadores de respuestas SDK conocidos se conservan sin declarar éxito.
- Una escritura Pendiente/Procesando/Incierta por obligación, un intento activo y un cobro aplicado son también restricciones PostgreSQL. Timeout conserva Incierto/exclusión; no nueva clave para despejarlo. Crear sin localizador solo se repite con mismo cuerpo/clave dentro de margen23h; fuera de ventana queda incierto para revisión. Sin búsqueda ficticia por metadata ni garantías de idempotencia externa ilimitada.
- SDK: host fijo api.stripe.com, sin redirects, conexión5s/total20s por llamada, retries automáticos0; comprueba cuenta propia. Adaptador real prohíbe red bajo transacción y bajo PHPUnit/testing sin transporte explícitamente sustituido. Http::preventStrayRequests sigue vigente para Laravel; tests Stripe sustituyen también la frontera SDK.

## 4. Evidencia y eventos

- Inbox deduplicado proveedor/test/cuenta/ID, advisory lock transaccional por recepción y huella SHA256 de bytes originales. Mismo ID/contenido distinto →409 sin sustituir recepción. Persistencia fallida →503. Desconocido firmado →Ignorado durable. Se guardan referencias mínimas/hash, no payload completo, firma ni secretos.
- Rechaza livemode true, context/Connect y API distinta. Eventos admitidos: checkout.session.completed/expired, payment_intent.succeeded/payment_failed, refund.created/updated/failed. Firma válida no liquida por sí sola: evento despierta consulta autoritativa SDK.
- Session: objeto/id test/modo/payment types/contexto UUID/total USD y moneda; PI succeeded/amount_received bruto/metadata/test/sin transferencia Connect; Charge succeeded/paid/captured/importe/currency/vínculo PI/test. Exitoso no se degrada por evidencia vieja. Discrepancias quedan inciertas, sin aplicado ni liberación indebida.
- Evento anterior a respuesta de creación: referencia firmada es solo pista. Localiza intento existente, recupera sesión por ID o por PI y coteja evidencia; no crea obligación desde metadata. Sin vínculo →PendienteVinculo con backoff acotado hasta1h. Aplicación única bajo lock común; recepción original inmutable.
- Dos cobros excepcionales verificables: conservar ambos, solo uno aplicado; segundo con COBRO_ADICIONAL_NO_APLICADO y auditoría. No devolución automática adicional. No garantías frente a manipulación de SQL que omita el protocolo de Services.

## 5. Coordinación adelantada de cancelación y devolución

- CoordinacionFinancieraCitaService conserva el flujo asistencial y no exigibilidad/CambioCita. Además prepara localmente cierre/consulta o devolución lógica única. Se conserva trabajo incluso con Stripe deshabilitado para no perder hechos previos; no se ejecuta red mientras esté apagado.
- Cancelación antes del primer envío cancela creación sin abrir Checkout. Si se había enviado/perdido respuesta, no supone ausencia de sesión. Session open se corrobora antes de expire y se consulta después; complete se verifica sin expire. Carrera o timeout mantiene recuperación.
- Aplicador de cobro verifica cancelación bajo el mismo lock; un aplicado tardío prepara devolución con cancelación real. Si otra escritura ocupa la exclusión, permanece devolución lógica durable y se prepara su operación al resolver aquella. Cancelación nunca revierte por error externo; sí revierte toda su preparación local si falla la auditoría/transacción.
- Refund total del **aplicado**, USD efectivamente cobrado/medio original, clave propia y metadata de devolución; referencia a cobro/CambioCita. Antes de enviar: sesión/PI/Charge test corroborados y consulta de refunds existentes; devolución parcial/ajena o paginación no resuelta impide otro envío.
- Refund recuperado por SDK: importe/currency/PI/Charge/metadata/referencia. **Refund no expone livemode en el SDK**; contexto test deriva de cuenta SDK + Session/PI/Charge ya verificados, no de un campo inventado. Si llega livemode=true adicional, se rechaza.
- succeeded→Exitosa; pending/requires_action→Procesando (operación recuperable Incierta y código de acción no soportada); failed/canceled→Fallida; timeout/desconocido→Incierta. Aceptar POST o HTTP200 no basta: se recupera refund por ID. Una respuesta perdida usa localizador conservado/listado y misma clave, nunca otra devolución lógica. Fallos terminales quedan visibles, sin reintento automático con nueva clave ni éxito manual.
- Reprogramación conserva precio/tasa/pago y reglas previas; no genera devolución. Inasistencia no devuelve. Pagos no cambian llegada/atención/estados asistenciales.

## 6. Recuperación básica Stripe y límites de Fase6

`pagos:recuperar-stripe --lote=100` (1–1000), scheduler cada minuto/withoutOverlapping de apoyo; deshabilitado → no consulta tablas ni procesa red. Recupera operaciones pendientes/inciertas/leases vencidos, consultas de intentos y eventos. No broker nuevo. Cuerpos/respuestas técnicas no se imprimen; métricas solo contadores. Tokens de operación y restricciones, no el scheduler, son la exclusión real.

Pendientes: PayPal, cambio/exclusión operativa entre proveedores, conciliador genérico multiproveedor, devoluciones PayPal y resolución operativa de incidencias/dobles cobros, resto Fase6; React/Fase7 y aceptación/integración real/Fase8. Sin dispatcher financiero genérico ni permisos nuevos. Incertidumbre sin localizador tras ventana y discrepancias externas requieren revisión/autorización posterior, no se declaran resueltas.

## 7. Archivos de esta entrega

- `backend/composer.json`, `composer.lock`, `config/pagos.php`, `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `routes/console.php`.
- Nuevos `src/business/services/pagos/{ConfiguracionStripe,StripeGateway,StripePagoService,EvidenciaStripeService,RecuperacionStripeService,EventoStripeService}.php`; integración puntual en CoordinacionFinancieraCitaService.
- Nuevo `src/data/repositories/pagos/StripePagoRepository.php`; método de auditoría en `src/data/repositories/bitacora/BitacoraRepository.php`.
- Nuevos `src/presentation/controllers/pagos/{StripePagoController,WebhookStripeController}.php`, `dtos/pagos/IntentoStripeRequest.php`, `routes/webhooks_pagos.php`; ampliados rutas pagos, ObligacionPagoResource y RespuestaError.
- Nuevo `tests/Feature/PagosStripeTest.php`, fixtures StripeSimulado/StripeHttpSimulado/StripeGatewayHttpSimulado/stripe-worker; expectativas de preparación de devolución ajustadas en GestionCitaIntegracionTest, conservando reglas anteriores.
- Documentación: este archivo, F1/F2/F3/especificación, analisis-clases.md, contexto-maestro.md, backend/README.md y estado-fase.md. **Sin modificar diagramas académicos/DER, RF/RN oficiales, Word/PDF ni frontend.** Cambios locales previos preservados.

## 8. Verificación y evidencia

- Aislamiento nuevamente demostrado antes de escrituras: testing/pgsql/local PostgreSQL18.6, base/rol/propietario clinica_vida_salud_test, sin superusuario/CREATEDB/CREATEROLE/replicación/BYPASSRLS/membresías, URL alternativa ni configuración cacheada. public0 tablas pre/post. Pruebas nuevas con esquemas aleatorios propios/commits reales y limpieza limitada a cada esquema; resto usa aislamiento previo. Esquema antiguo ajeno `prueba_auth_sx1lpauqw780c1tw` conservado, sin leer sus datos ni eliminarlo. Sin crear bases/roles/migración habitual.
- **Batería final dirigida: 281 pruebas/3150 aserciones, código real0, 77,978s/94MB, PHP8.5.11/PHPUnit12.5.40.** PagosStripe, F3, persistencia, ImportePago, gestión/reservas/agenda/historial/permisos y seis carreras financieras previas. Incluye nueva carrera Stripe con procesos PostgreSQL distintos, lease excluyente y cancelación confirmada mientras otro espera red simulada. No suite global.
- Firma real mediante SDK/HMAC local, transporte SDK simulado y fake de alto nivel; roles/ajenos/entrada/dinero/configlive/duplicados/evento temprano/evidencia incompatible/timeouts/cierre/refund/ventana/rollback/doble cobro/flags cubiertos. No Stripe real test, webhook HTTPS real, credenciales, CLI, cuenta, tarjeta ni liquidación/refund externa.
- Primer pase269/2915 detectó tres expectativas de pruebas (revisión ausente tras exclusión y dos de cancelación sin devolución); corregidas. Pase posterior280/3138 correcto; no sumado al final. Wrapper .bat/PowerShell interpretó regex/ stderr como error: runner externo proc_open conservó stdout/stderr y código nativo0 de la batería final.
- Logs fuera del repositorio: `C:\Users\jesus\AppData\Local\Temp\opencode\pagos-fase4-*`, especialmente final-stdout.log/final-stderr.log/final-exit.log, composer-dry-run/install, pint-final y rutas. No se presentan avisos Composer como ausencia de vulnerabilidades.
- Controles finales: sintaxis26 PHP correcta/Pint aplicado/autoload sin scripts correcto; contraste automático de lock contra inicio confirma solo Stripe22.1.0 añadido y versiones anteriores idénticas. Siete rutas financieras listadas, webhook con middleware vacío y humanas con api/auth/rol/throttle. Git diff-check sin errores (avisos CRLF previos); Git inspeccionado al inicio/final. PostgreSQL posterior: public0 tablas/0 funciones financieras, 0 esquemas Stripe restantes, esquema ajeno presente; incorporación=false y Stripe=false efectivos en testing.

**Detenerse para revisión. No activar ni iniciar otra fase automáticamente.**
