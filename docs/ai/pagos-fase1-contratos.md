# Pagos — Fase 1: contratos y configuración backend

**Concreción aditiva aprobada para Fase7 (2026-11-06):** [frontend y evidencia](pagos-fase7-frontend.md). `GET /reservas/disponibilidad` añade `data.incorporacion_financiera_habilitada:boolean` y `data.requiere_monto_bob:boolean` (solo personal con incorporación true), sin extender la respuesta de reprogramación. Las obligaciones añaden `proveedores_disponibles:('stripe'|'paypal')[]` según elegibilidad existente por proveedor, vacío para Admin/no elegible, conservando `puede_iniciar_pago`/`revision_pago` agregados. Ningún endpoint/regla/persistencia/flag nuevo. Recursos verificados en PHPUnit puro4/41 sin Laravel/SQL/red; frontend y Chrome solo con transporte simulado, no integración externa/activación/F8. Las notas inferiores conservan historia.

**Fase6 verificada localmente (2026-11-06):** [coordinación técnica](pagos-fase6-coordinacion.md) incorpora cambio condicionado al cierre durable sin cobro, revalidación transaccional, recuperación acotada e inbox con leases, manteniendo URLs/permisos/fronteras humanas y webhooks. Incidencias pendientes por decisión expresa, sin resolución nueva ni reintentos refund terminales. Final343 pruebas/3887 aserciones/código nativo0, seis carreras nuevas más ocho anteriores sobre PostgreSQL18.6 aislado, proveedores simulados; sintaxis20/Pint/autoload correctos. Incorporación/ambos proveedores false efectivos, no activación/integración externa/F7–8. Revisión pendiente; entradas inferiores conservan historia.

**Estado posterior Fase5 (2026-11-06):** [PayPal Sandbox backend](pagos-fase5-paypal.md) implementa órdenes/captura explícita/eventos/recuperación/refund y coordinación mínima de cancelaciones adicionalmente autorizada. POST captura y webhook PayPal efectivos, receptor verificado/postback y200 solo tras inbox durable; rutas comunes mediante PagoController/IntentoPagoRequest. App OAuth esperada fijada con PAYPAL_APP_ID; cierre por permiso local, no expiración Stripe. Refund incierto sin ID espera referencia verificada, sin repetir POST suponiendo ventana Orders. Final324/3616 correcta con simulaciones, PostgreSQL aislado; incorporación/ambos proveedores apagados. No integración externa, cambio operativo/resto F6/React/F7/F8. Notas inferiores conservan cronología.

**Estado posterior Fase4 (2026-11-06):** [Stripe backend test](pagos-fase4-stripe.md) implementa las rutas Stripe de §4.2, frontera webhook, idempotencia/recuperación y soporte de refund con coordinación mínima de cancelaciones expresamente autorizada. SDK oficial22.1.0 instalado, sin otras versiones actualizadas; ver concreciones HTTP/ratelimit y contexto Refund en F4. Banderas apagadas, sin operaciones externas ni CU-32 anunciado operativo. PayPal/resto Fase6/React pendientes. Notas inferiores conservan su estado histórico.

**Estado vigente 2026-11-06:** implementados/verificados los contratos de reserva/asignación/resumen y consultas de §3/§4.1, con control de incorporación defaultfalse. [Fase3 efectiva](pagos-fase3-backend.md) concreta corte/replay/idempotencia/compatibilidad y evidencia247/2799. Puede_iniciar_pago=false/revision_pago=null en esta entrega, sin CU-32. §4.2 en adelante (operaciones de proveedores/webhooks/recuperación) sigue como diseño futuro; no rutas/SDK ni integración externa. Las notas históricas F1/F2 inferiores no revierten este estado.

**Aclaración posterior aprobada (2026-11-05):** Fase3 fue autorizada, con punto de revisión tras coordinar localmente cancelación/reprogramación y obligaciones. Esa coordinación está implementada; contratos HTTP de este documento siguen diseñados, no operativos. En la futura entrega parcial `puede_iniciar_pago=false` y `revision_pago=null` hasta integrar procesamiento autorizado; no habilitar CU-32. Incorporación controlada por servidor/default off aprobada, todavía sin mecanismo operativo ni activación. Detalle/relevo en estado-fase.md; anotaciones de autorización pendientes inferiores conservan su contexto histórico.

**2026-11-05 · Diseño documental terminado; contratos HTTP y configuración aún no implementados.** Fase 2 posterior autorizó únicamente infraestructura de persistencia y dinero exacto, descrita en [pagos-fase2-modelo.md](pagos-fase2-modelo.md); no vuelve operativos estos contratos. Fuente funcional: [pasarela-pagos.md](pasarela-pagos.md), PAG-RF-01–15, VS-PAG-01–18 y CU-31–34. RF01–RF13/RN01–RN14 intactos. Sin instalación, credenciales, operaciones externas ni autorización de Fase 3.

## 1. Contraste con el código real

- `backend/composer.json`: PHP `^8.4.1`, Laravel `^13.17`, Sanctum `^4.0`; lock: Laravel **13.37.0**, Sanctum **4.3.3**. CLI observado: **PHP 8.5.11**, curl/json/mbstring/openssl/pdo_pgsql presentes (`php -v`, `php -m`); no prueba del proceso web ni integración SDK.
- `routes/api.php` incluye módulos desde `src/presentation/routes/`; no Pagos. Sin SDK Stripe/PayPal declarado. No se ejecutó Artisan ni se consultó PostgreSQL.
- `ReservaRequest`, `ReservaController`, `ReservaService`, `PropuestaReserva`: propuesta cifrada, revisión y confirmación idempotente por UUID de propuesta, **sin monto/obligación**. Confirmar crea cita/auditoría transaccionales; 201 nueva, 200 repetida con `meta.repetida`.
- Endpoints reales: `POST /api/reservas/propuesta`, `POST /api/reservas/resultado`, `POST /api/citas`. Personal añade `id_paciente`, Paciente no lo admite. No endpoint separado de revisión: se revisa la respuesta de propuesta. Proponer no reserva turno.
- Consultas reales: `GET /api/mis-citas`, `GET /api/pacientes/{id}/citas`, `GET /api/citas/{id}`, permisos en `src/presentation/routes/agenda.php`. Médico no recibirá proyecciones financieras.
- `bootstrap/app.php` activa `statefulApi()` y antepone `ExigirOrigenSpa` al grupo API. Este exige Origin/Referer permitido y clasificación stateful, crea correlación y no-store: rechazaría webhooks normales incluso sin `auth:sanctum`.
- `RespuestaError`: AppError desconocido → 500; códigos nuevos requieren mapeo futuro explícito. Listas existentes usan `data/links/meta`, tamaño 20/máximo 100, rechazo de campos extra.
- Discrepancia ajena: backend/AGENTS dice Web Push no instalado; composer/lock ya declaran minishlink/web-push 11.0.0. Se registra sin cambiar instrucciones ni inferir instalación efectiva.

## 2. Convenciones de todos los contratos diseñados

**Todos los endpoints nuevos y ampliaciones siguientes están diseñados, todavía no implementados.** Prefijo `/api`. Route → middleware → Controller/FormRequest → Service → Repository → Eloquent/PostgreSQL; adaptadores independientes. No endpoint por tabla ni nuevos roles.

### 2.1. Autorización, formatos y respuestas

- Rutas humanas conservan origen SPA, sesión/cookies/CSRF, `auth:sanctum`, `VerificarCuentaHabilitada`, `ExigirSesionNormal`, rol y throttle. No cambiar autenticación. Escrituras JSON; sintaxis inválida 400, tipo no admitido 415, campos inválidos 422. Rechazar campos extra, también anidados; GET sin cuerpo ni filtros desconocidos.
- Paciente deriva ficha del vínculo autenticado, rechaza `id_paciente`. Administrador en consultas compartidas exige `id_paciente` seleccionado, entero positivo según formato/rango vigente. Recepción solo precio/asignación y resumen de cita; Médico sin finanzas. Rol incorrecto 403; recurso ajeno/inexistente o incompatible con Paciente seleccionado 404 neutro.
- Obligación/intento nuevos usan UUID opacos, sin conceder autorización. Cita conserva ID existente. El cliente no fija CI/email, actor/estado, moneda/tasa/USD, cargo/destinatario, URL de proveedor/retorno ni orden externa.
- Respuesta humana `Cache-Control: no-store, private`, `data` objeto/lista, `links/meta` al paginar. Fechas financieras RFC3339 UTC explícito; citas conservan fecha/hora local America/La_Paz. `meta.calculado_at` indica momento de servidor para vencimiento; el navegador no decide elegibilidad.
- `monto_bob` de entrada: **string decimal**, patrón `\A(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,2})?\z`, rango **7–7000 inclusive** tras conversión exacta a centavos. Acepta `"7"`, `"7.0"`, `"7.00"`; normaliza `"7.00"`. Rechaza número JSON, signo, espacios, coma, miles, exponentes, ceros iniciales y >2 decimales. Validar antes de normalizar; no redondear entrada inválida.
- Salida `precio`: `monto_bob:"70.00"`, `centavos_bob:7000`, `moneda_original:"BOB"`, `tasa_bob_por_usd:"7.00"`, `monto_usd:"10.00"`, `centavos_usd:1000`, `moneda_cobro:"USD"`. Congelar al emitir, con emisor/fecha. `centavos_usd=intdiv(2*centavos_bob+7,14)`; BOB-centavos 700–700000, USD-centavos 100–100000. Nunca float.
- Proveedor `stripe|paypal`; Stripe recibe `currency=usd`/centavos enteros; PayPal `currency_code=USD`/`value` string dos decimales. Mismo USD, sin reconversión en captura/refund. BOB no se cobra externamente.
- Obligación: `id,cita` mínima (id/código/inicio/estado asistencial), `precio,emitida_at,vencimiento_at,exigible,liquidada,vencida,operacion_en_verificacion,puede_iniciar_pago,revision_pago,intento_actual` (mínimo o null). `vencida=exigible&&!liquidada&&ahora>=vencimiento_at`, calculada, no estado persistido ni bloqueo de regularización. Cancelada puede conservar liquidación/devolución pendiente.
- `revision_pago` es huella opaca del precio histórico y elegibilidad revisados. GET detalle permite revisión; inicio exige esa revisión y revalida hechos bajo lock. No concede permisos ni ajusta importes silenciosamente.
- Detalle: intentos/devoluciones con ID local, proveedor, estado público, fechas, USD bruto, referencia externa autorizada y `aplicado`/incidencia. No PAN/CVV, correo comprador, payload externo, credenciales, secretos/claves externas ni clínica. URL Checkout/aprobación solo transitoria al titular para continuar; no historial/admin/bitácora.
- Estados públicos de intento: `Preparando|RequiereAccion|Procesando|Exitoso|Fallido|Cerrado|Incierto`, vocabulario contractual, no enums SQL creados ni estados de Cita. Exitoso exige cobro verificado; `aplicado` distingue incidencia. Fallido/Cerrado liberan exclusión solo con prueba de que no pueden cobrar. Rechazo de tarjeta en Checkout todavía abierta no cierra intento. Devolución: Pendiente/Procesando/Exitosa/Fallida/Incierta.

### 2.2. Idempotencia y resultado incierto

- POST nuevos con efectos requieren `Idempotency-Key` UUID v4; propuestas de revisión no. Confirmaciones de reserva/asignación usan UUID de propuesta cifrada. No trasladar clave del navegador al proveedor.
- Registro durable por actor/operación/recurso/clave y huella canónica de entrada. Misma clave/entrada recupera operación, otra entrada → 409 `PAGO_IDEMPOTENCIA_CONFLICTO`. Antes de replay verificar sesión/rol/titularidad; no ejecuta nuevas acciones sobre cita cancelada/cuenta deshabilitada ni devuelve datos de otro actor.
- Replay devuelve estado actual, sin redirección antigua a operación cerrada. Inicio listo nuevo 201; repetido resuelto 200 + `meta.repetida:true`; en curso/incierto 202, `Location` local y `Retry-After: 5`. No prueba pago. Trabajo durable antes de red; timeout externo → 202/Incierto sin liberar exclusión. Sin poder registrar operación → 503; ante fallo de red cliente no presume ausencia de efectos.
- Una operación cobrable/activa/incierta por obligación bajo transacción corta; red fuera de SQL; aplicar evidencia en nueva transacción. HTTP/webhook/conciliador comparten locks, unicidad de referencias/aplicación y estados. Historia local no caduca por ventana externa. Claves/payloads externos estables, distintos para crear/capturar/cerrar/refund.
- Respuesta perdida: repetir misma clave o consultar recurso, no probar otro UUID/proveedor. Conciliar antes de repetir escritura incierta. Referencia todavía desconocida se recupera por correlación/eventos/idempotencia vigente; sin evidencia suficiente mantiene Incierto bloqueado. Nunca falso fallo por vencer timeout o clave externa.

## 3. Reserva administrativa y asignación posterior

### 3.1. Ampliación de endpoints existentes, implementada en Fase 3

| Método y ruta | Campos permitidos / actor | Respuesta y garantía diseñadas |
| --- | --- | --- |
| `POST /reservas/propuesta` | Conserva `id_medico,id_especialidad,fecha,hora_inicio,revision_turno,motivo_breve?`; Administrador/Recepción exige además `id_paciente,monto_bob`; Paciente rechaza ambos. | 200 propuesta cifrada con actor/rol, UUID, versión de esquema y precio BOB/tasa/USD para personal. `precio` revisado o null propia. No emisión/reserva de turno. |
| `POST /citas` | Solo `propuesta,motivo_breve?` actuales; sin monto libre/cambios de lo revisado. | 201/200 repetida. Administrativa: Cita+obligación+auditoría atómicas. Propia: Programada incorporada sin obligación/precio. Resumen financiero autorizado en respuesta. Sin proveedor bajo locks. |
| `POST /reservas/resultado` | Solo `propuesta`; comprobar actor/vínculo originales. | 200 `registrada:false` o cita con mismo resumen financiero autorizado; recupera confirmación incierta sin nueva reserva/pago. |

Conservar errores existentes de turno/revisión/solapamiento/permisos y motivo revisado. Nueva versión del token impide aceptar propuestas administrativas antiguas **no confirmadas** sin monto: exigir revisión nueva con `CITA_PROPUESTA_OBSOLETA` 409. Replay de reserva ya confirmada mantiene resultado original, incluso legado excluido; nunca deuda retroactiva. Corte/marca de incorporación y restricciones físicas se concretarán en Fase 2 autorizada.

### 3.2. Monto posterior: revisión y confirmación únicas

| Método y ruta | Actor / únicos campos | Respuesta / concurrencia |
| --- | --- | --- |
| `POST /citas/{id}/monto/propuesta` | Administrador/Recepcionista, cita administrativa accesible; `monto_bob`. | 200 `data:{propuesta,precio,cita}`. Token cifrado ≤16384 caracteres con UUID, actor/rol, cita, importe/tasa y versión de condiciones relevantes. Solo revisión, sin escritura financiera. |
| `POST /citas/{id}/monto` | Mismos roles; solo `propuesta`, coincide con actor/rol/cita/precio revisado. | 201 obligación; replay idéntico 200 `meta.repetida`. Lock cita + unicidad obligación/cita; obligación/auditoría atómicas. Sin PATCH/edición de precio. |

Revalidar incorporación, no Cancelada y sin obligación. Inicio pasado, Atendida o Inasistencia **no** impiden asignación. Condiciones incompatibles → `PAGO_REVISION_OBSOLETA`; obligación distinta existente → `PAGO_PRECIO_YA_ASIGNADO`. Cancelación que gana lock impide emisión; si asignación gana, cancelación posterior anula exigibilidad. Token no reserva un derecho; confirmación siempre revalida, sin plazo de negocio nuevo. No cambios asistenciales ni llamadas externas.

### 3.3. Consultar sin inventar obligaciones

Ampliación futura de `GET /mis-citas`, `GET /pacientes/{id}/citas`, `GET /citas/{id}`: resumen `incorporada_finanzas,situacion_precio` (`NoIncorporada|PendienteAsignacion|Asignado`), `puede_asignar_monto,obligacion_id`/precio cuando autorizado. NoIncorporada no significa Pagada. Médico conserva respuesta previa sin finanzas. Recepción solo precio/asignación, sin intentos/cobros/devoluciones/historial financiero general.

Filtro diseñado `situacion_precio=no_incorporada|pendiente_asignacion|asignado` en los dos listados, sin alterar filtros/paginación anteriores. PendienteAsignacion exige incorporada y obligación ausente; cancelada sin precio puede aparecer pero `puede_asignar_monto=false`. No amplía agenda global ni crea obligación al consultar. Para Paciente el permiso de asignación siempre false.

## 4. Obligaciones, historial y operación de pago

### 4.1. Consultas compartidas, proyección por permiso — implementadas en Fase 3

| Método y ruta | Actor / única entrada permitida | Respuesta |
| --- | --- | --- |
| `GET /pagos/obligaciones` | Paciente propio; Administrador exige `id_paciente`. Filtros `exigible,liquidada,vencida,operacion_en_verificacion` como `0` o `1`, `fecha_desde,fecha_hasta,page,per_page`. | 200 obligaciones reales, resumen §2.1. Sin precio/legado se consultan en citas, no como deudas ficticias. |
| `GET /pagos/obligaciones/{obligacion}` | Mismos actores; Administrador exige `id_paciente`; ambos permiten solo `intentos_page,devoluciones_page` adicionales. | 200 detalle/revisión e intentos/devoluciones/incidencias. Administrador nunca puede iniciar pago. |
| `GET /pagos/historial` | Mismos actores/titularidad. `fecha_desde,fecha_hasta,proveedor,resultado,page,per_page`; Administrador añade `id_paciente`. | 200 intentos históricos, incluidos incompletos/cerrados, cobro aplicado/no aplicado y devolución resumida, enlace local a obligación. `resultado` según estados §2.1. |

Sin cuerpo. Fechas `YYYY-MM-DD` válidas, desde ≤ hasta; días America/La_Paz convertidos a intervalo UTC semiabierto (hasta incluye día completo). Período por emisión en obligaciones, por creación del intento en historial, no por cita. `page` 1–1000000, `per_page` 1–100, predeterminado 20. Orden fijo emisión/creación descendente + ID descendente; sin `sort` libre. `vencida=1` usa fórmula, no estado. `data:[]` vacío; `links`, `meta:{current_page,last_page,per_page,total,from,to,calculado_at}`. En detalle cada colección pagina a 20, parámetros 1–1000000 y `links/meta` anidados. Sin exposición pública por CI/email, sin datos clínicos ni totales decididos por React.

### 4.2. Operaciones solo del Paciente titular

| Método y ruta | Únicos campos permitidos | Respuesta / efecto diseñado |
| --- | --- | --- |
| `POST /pagos/obligaciones/{obligacion}/intentos` | `proveedor,revision_pago`; `Idempotency-Key`. | Revalidar revisión, elegibilidad y exclusión; 201 intento con acción segura Checkout/aprobación; 200 replay o 202 preparando/incierto. Sin monto del navegador. |
| `POST /pagos/intentos/{intento}/resultado` | `{}`, sin query/id externo; `Idempotency-Key`. | Consultar/reconciliar intento propio; no iniciar/capturar por retorno. 200 resultado verificado o 202 pendiente/incierto, obligación/devolución resumidas y `ultima_verificacion_at`. |
| `POST /pagos/intentos/{intento}/paypal/captura` | `{}`; `Idempotency-Key`. | Orden propia aprobada, permiso local vigente y obligación cobrable. Reservar captura antes de red; 200 resultado verificado (también rechazo confirmado), 202 pendiente/incierto. Replay/webhook no capturan/aplican dos veces. |
| `POST /pagos/intentos/{intento}/cierre` | `{}`; `Idempotency-Key`. | 200 cerrado sin cobro o cobro ya verificado; 202 si cierre no probado. No cancela Cita ni devuelve dinero. Otro proveedor solo tras liberar exclusión. |

Acción transitoria: `data:{id,obligacion_id,proveedor,estado,aplicado,accion:{tipo:"Redirigir",url}|null}`. Resultado añade `cobro` autorizado o null, `devolucion,puede_reintentar,operacion_en_verificacion`. URL solo si sesión/orden verificada y utilizable; 202 sin URL especulativa. Captura en proveedor incorrecto → `PAGO_PROVEEDOR_INCOMPATIBLE`; sin aprobación → `PAGO_APROBACION_REQUERIDA` (409).

Inicio perdido antes de recibir ID: repetir misma clave/entrada; GET detalle muestra intento actual. Reconciliaciones coalescidas por intento; límites técnicos diseñados: resultado 30/min usuario y 6/min intento; inicio/captura/cierre 10/min usuario y 3/min obligación. 429/Retry-After, no rotar clave. Estos valores requieren pruebas futuras; S2S no hereda cuotas de usuario.

### 4.3. Flujos de proveedores y carreras

- Stripe: crear `/v1/checkout/sessions`, `mode=payment`, `payment_method_types=[card]`, línea genérica sin clínica, cantidad 1, USD histórico; sin impuestos, cupones, envío, métodos diferidos ni adaptive pricing/conversión que altere USD. `client_reference_id`/metadata y metadata PaymentIntent opacos de intento. Conservar Session/PaymentIntent/cargo, comprobar total bruto, moneda, cuenta y `livemode=false`. `checkout.session.completed` solo liquida si `payment_status=paid` y cobro corroborado. PaymentIntent succeeded converge en misma aplicación.
- PayPal: `POST /v2/checkout/orders`, `intent=CAPTURE`, una purchase_unit, USD histórico, `custom_id/reference_id` opacos y payee configurado. `payment_source.paypal.experience_context` con `NO_SHIPPING`, retornos fijos. Enlace approve/payer-action validado. Aprobar no cobra. Captura server-side `POST /v2/checkout/orders/{id}/capture`; exigir captura COMPLETED, final/total único, bruto exacto, payee y orden local. Sin AUTHORIZE, vault, parciales ni captura del navegador.
- Futuro retorno SPA `/pagos/retorno/{proveedor}` con UUID local. session_id, token/PayerID externos son entrada no confiable, no autorizan captura. Sin logs/analytics/persistencia de esos parámetros. Sesión vencida exige login, sin interrumpir webhooks/conciliación. No capturar con GET/retorno automático: POST autorizado del titular.
- Cierre Stripe solicita expire de sesión abierta con clave durable y consulta estado. Si completed durante carrera, registra cobro y no abre otro intento. Mientras pueda cobrar/procesar, mantiene exclusión. Cerrar pestaña/cancel_url no prueba expiración.
- Cierre PayPal **no inventa API de expiración Orders**: revoca permiso local de captura bajo lock, verifica que no haya captura en vuelo/incierta y consulta orden/capturas. Todas las vías de captura exigen permiso local. Orden aprobada cerrada no vuelve a activarse por webhook; captura en vuelo no libera exclusión.
- Cancelar revoca nuevas capturas, no promete deshacer captura previamente enviada. Si cobra tarde, aplica una vez y registra devolución del aplicado; conserva Cancelada/no exigible. Nunca red bajo locks asistenciales.
- `CHECKOUT.ORDER.APPROVED` registra aprobación/reconciliación, **no dispara captura automática**; evita cobros nuevos desde webhook de orden cerrada/cuenta deshabilitada. Captura ya iniciada sigue conciliándose sin sesión.
- Dos éxitos externos distintos: registrar ambos, primera aplicación transaccional ocupa liquidación única; segundo Exitoso/no aplicado con incidencia, no ocultarlo como fallo ni devolverlo automáticamente. Cancelación devuelve solo aplicado. Pagar jamás confirma llegada/atención.

## 5. Errores estables diseñados

Contrato `{"success":false,"error":{"code":"...","message":"mensaje seguro","details":{}}}`; details opcional/lista permitida, nunca respuesta externa completa. Códigos nuevos **no mapeados todavía en código**. Rechazo financiero verificado es resultado del intento 200, no HTTP 500.

| HTTP | Código | Uso |
| --- | --- | --- |
| 400 | `PETICION_INVALIDA` | JSON/sobre mal formado. |
| 401/403/404 | `AUTH_NO_AUTENTICADO` / `ACCESO_DENEGADO` / `RECURSO_NO_ENCONTRADO` | Sesión/rol/recurso existentes; origen conserva ORIGEN_NO_PERMITIDO. |
| 409 | `PAGO_PRECIO_YA_ASIGNADO`, `PAGO_CITA_NO_INCORPORADA`, `PAGO_NO_EXIGIBLE` | Emisión duplicada/legado/cancelación. |
| 409 | `PAGO_YA_LIQUIDADO`, `PAGO_OPERACION_ACTIVA` | Otro cobro bloqueado; consultar detalle autorizado. |
| 409 | `PAGO_REVISION_OBSOLETA`, `PAGO_IDEMPOTENCIA_CONFLICTO` | Revisar o recuperar original, no cambiar clave ciegamente. |
| 409 | `PAGO_PROVEEDOR_INCOMPATIBLE`, `PAGO_APROBACION_REQUERIDA`, `PAGO_CAPTURA_NO_PERMITIDA` | Tipo/estado/permiso incompatible; no niega un cobro previo. |
| 419/422/429 | `CSRF_INVALIDO` / `VALIDACION_ERROR` / `AUTH_LIMITE_SOLICITUDES` | Existentes; campos en 422, Retry-After en 429. |
| 413/415 | `PAGO_EVENTO_DEMASIADO_GRANDE` / `PAGO_CONTENIDO_NO_ADMITIDO` | Límite S2S/tipo JSON; mapear explícitamente. |
| 400 | `PAGO_EVENTO_INVALIDO`, `PAGO_ENTORNO_NO_PERMITIDO` | Firma/cabeceras/JSON inválidos o evento live/contexto incompatible. Sin detalles de firma. |
| 503 | `PAGO_NO_CONFIGURADO`, `PAGO_PROVEEDOR_NO_DISPONIBLE`, `PAGO_EVENTO_NO_PERSISTIDO` | Configuración inválida, verificación remota no disponible o sin recepción durable; Retry-After prudente. |
| 500 | `ERROR_INTERNO` | Imprevisto centralizado y seguro. |

Cabecera de idempotencia ausente/inválida → 422 campo lógico `idempotency_key`; monto inválido → 422 `monto_bob`. Evidencia auténtica con importe/moneda/payee incoherentes queda en cuarentena/incidencia sin liquidar, no como error de dinero del navegador. No endpoint humano para forzar éxito. Códigos de reserva/CU-15/CU-16 conservados. El 503 no afirma ausencia de efectos externos.

## 6. Frontera webhook servidor-servidor

### 6.1. Rutas y aislamiento

`POST /api/webhooks/pagos/stripe` y `POST /api/webhooks/pagos/paypal`: actor sistema externo, sin sesión/cookies/origen SPA/CSRF; autenticación del proveedor y contexto académico configurado. Solo evento JSON/cabeceras de firma, sin query; no importes impuestos por usuario humano. Respuesta mínima, nunca obligaciones/pacientes.

Registro futuro: archivo dueño `src/presentation/routes/webhooks_pagos.php`, callback `then` de `withRouting` Laravel 13, grupo propio prefijo `api/webhooks/pagos` **sin heredar grupos api/web**. No incluirlo en `routes/api.php`, que heredaría ExigirOrigenSpa/statefulApi. Mantener `api/*` conserva normalización/reporting seguro actuales. Rutas humanas Pagos sí desde `routes/api.php` con todas sus protecciones.

Grupo S2S solo correlación segura propia, no-store, límites y verificación específica; no EnsureFrontendRequestsAreStateful aun si atacante adjunta Origin/cookies. No wildcard de exclusión CSRF/origen ni retirada de middleware del resto de API. En implementación comprobar pila efectiva y pruebas: este documento no acredita separación operativa.

Bytes originales desde `Request::getContent()`, nunca reconstruidos desde all/validated. Normalizadores globales trim/empty-to-null no deben afectar fuente firmada; excluir exactamente estas rutas si se usan campos parseados, sin cambiar SPA. Proxy no reescribe cuerpo. Límite técnico diseñado **1 MiB** JSON/cabeceras acotadas antes de red; 413/415/400 sin guardar payload no verificado. Probar con eventos reales autorizados. Sin logs de cuerpo/cabeceras completas; correlación local aleatoria.

### 6.2. Firmas, entorno, destinatario y eventos

- Stripe: SDK verifica bytes originales + Stripe-Signature + secreto whsec del listener exacto; tolerancia **300 s**, reloj sincronizado. Secreto CLI distinto de Dashboard, rotación controlada. Endpoint cuenta propia test sin Connect/Organizations, `livemode=false`. Cuenta esperada deriva de credencial/endpoint y recuperación autenticada del objeto, no de metadata/account arbitrario del evento.
- Suscribir Stripe `checkout.session.completed`, `checkout.session.expired`, `payment_intent.succeeded`, `payment_intent.payment_failed`, `refund.created`, `refund.updated`, `refund.failed`. Fallo no cierra Checkout cobrable; refund creado no prueba éxito. Tipo desconocido firmado se registra Ignorado, sin finanzas; no suscripción `*`.
- PayPal: opción elegida **postback oficial** `POST https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature`, OAuth app Sandbox y Webhook ID configurado. Requiere PAYPAL-TRANSMISSION-ID/TIME/SIG, PAYPAL-CERT-URL, PAYPAL-AUTH-ALGO. Exigir `verification_status=SUCCESS`, no solo HTTP 200. Envolver `webhook_event` conservando JSON original validado, cabeceras escapadas seguras; no parsear/re-serializar evento alterando su representación. Timeout/5xx → 503, nunca aceptar por fallo de verificación.
- No descargar CERT-URL ni seguir links del evento. Restringir certificado a HTTPS/443, hosts Sandbox exactos `api.sandbox.paypal.com|api-m.sandbox.paypal.com`, ruta `/v1/notifications/certs/`, sin usuario/contraseña/fragmento/redirect; host live rechazado. Verificación criptográfica local alternativa requeriría revisión SSRF/certificado/CRC32 de cuerpo original; no habilitada por este diseño.
- PayPal no tiene livemode equivalente: entorno fijado por ruta/configuración, OAuth Sandbox, Webhook ID de esa app, merchant esperado y recuperación por host Sandbox fijo, no query/return_url. Orden/captura deben corresponder a intento local, mismo bruto USD y payee; nunca usar links.self para decidir destino de red.
- PayPal: `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.PENDING`, `PAYMENT.CAPTURE.DECLINED`, `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.CAPTURE.REVERSED`; verificar disponibilidad exacta al suscribir app Sandbox. Reversed → incidencia/consulta, no refund autorizado ni reapertura automática. `CHECKOUT.ORDER.COMPLETED` no es evidencia general (documentado para marketplaces). Simulador no prueba captura y postback no soporta mock events; no bajar seguridad para aceptarlos.

### 6.3. Recepción durable frente a aplicación financiera

1. Validar límites/firma/entorno; **antes de 2xx** guardar bandeja de entrada y trabajo recuperable transaccional. Proyectar proveedor/entorno/cuenta-app configurada/ID evento/tipo/objeto-referencias mínimas/recepción/huella/estado. Sin payload completo por defecto; recuperar evidencia autenticada por referencias. Datos indispensables no recuperables mediante API, solo lista permitida protegida, sin clínica/PII innecesaria.
2. Unicidad `(proveedor,entorno,cuenta_o_app,id_evento)`. Durable nueva 200 `data:{recibido:true,repetido:false}`; duplicada durable 200 con repetido true. **No significa pago confirmado.** Mismo ID/contenido incompatible → anomalía sin sobrescribir/aplicar y alerta segura. transmission_id PayPal no sustituye ID evento.
3. Fallo commit → 503 `PAGO_EVENTO_NO_PERSISTIDO`, no job solo en memoria. Evento auténtico sin vínculo se conserva PendienteVinculo/Cuarentena o Ignorado según tipo, con acuse durable y recuperación; puede preceder respuesta de creación. No crear obligación desde metadata. Firma inválida 400, sin falso 2xx.
4. Worker/scheduler recupera por lease, consulta estado autoritativo **fuera de locks**, coteja referencias/entorno/cuenta/importe/moneda y aplica cobro/obligación/auditoría transaccionales. Distintos eventos del mismo cobro convergen por referencia única, no solo ID evento. Fallo tras recepción se recupera localmente aunque proveedor no reenvíe.
5. Entrega desordenada no decide verdad: evento viejo no degrada cobro confirmado; refund/reversal auténticos son hechos separados. Cobro tardío Cancelada genera devolución del aplicado, no nueva deuda. Procesar sin sesión/cuenta activa del iniciador.

## 7. Configuración académica especificada, no aplicada

Futuro `config/pagos.php`, lectura de entorno allí, no env disperso por Services. **No se modifica ahora** config/.env/.env.example/credenciales. Ambos proveedores obligatorios al cierre; uno no sustituye silenciosamente al otro ni su indisponibilidad impide emitir obligación local.

| Variable diseñada | Regla / sensibilidad |
| --- | --- |
| `PAGOS_HABILITADOS` | false por defecto; habilitar solo con autorización y esquema/config validada. |
| `PAGOS_MODO` | Solo academico; rechazar live/production y habilitación con APP_ENV=production; no sustituye verificar claves/hosts. |
| `PAGOS_FRONTEND_ORIGIN` | Origen exacto SPA existente, sin path/query/credenciales, HTTPS fuera de loopback; no ampliar CORS. |
| `PAGOS_WEBHOOK_BASE_URL` | HTTPS público 443 para PayPal, dos rutas exactas §6. CLI Stripe puede reenviar a HTTP loopback. Túnel exige autorización independiente, no exponer otras rutas por comodidad. |
| `STRIPE_SECRET_KEY` | Backend `sk_test_` exclusivamente; rechazar live/otras familias no contempladas. Prefijo no prueba cuenta/entorno. Checkout alojado no requiere publishable key. |
| `STRIPE_ACCOUNT_ID` | Cuenta esperada, no dato cliente; sin Connect/Stripe-Account. |
| `STRIPE_WEBHOOK_SECRET` | Secreto whsec del endpoint test; prefijo no acredita modo. Rotación controlada. |
| `STRIPE_API_VERSION` | Fijar `2026-10-28.endive`, endpoint snapshot misma versión; no latest/preview implícita. |
| `PAYPAL_ENVIRONMENT` | Solo sandbox; host derivado fijo `https://api-m.sandbox.paypal.com`, sin URL libre/fallback live. |
| `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET` | App Sandbox, ambas backend en este flujo REST alojado; no React/logs. Prefijo no distingue live: comprobar entorno OAuth/merchant antes de operar. |
| `PAYPAL_MERCHANT_ID`, `PAYPAL_WEBHOOK_ID` | Business Sandbox y listener de misma app; no inferir del evento sin autenticar. |

BOB/USD, 7:1, límites y ausencia de parciales son constantes de dominio, **no variables editables**. Secretos privados backend fuera de Git/logs/URLs, no solicitarlos en chat. OAuth PayPal con expiración/protección solo backend, no navegador ni sesión del Paciente. Sin persistir cuerpos técnicos externos.

Retornos servidor: origen permitido + `/pagos/retorno/stripe|paypal`, UUID local; Stripe puede añadir placeholder `{CHECKOUT_SESSION_ID}`, PayPal token/PayerID no confiables. No aceptar return_url/success_url/cancel_url del cliente. Éxito/cancelación visual no demuestra nada financiero. Validar HTTPS/host exacto en redirección externa (`checkout.stripe.com`, `www.sandbox.paypal.com` para flujo diseñado), sin credenciales/puertos inesperados ni redirects automáticos del cliente HTTP. Ajustes de allowlist con evidencia/revisión, no comodines.

### 7.1. Compatibilidad, USD e idempotencia: evidencia pública

- SDK recomendado **oficial stripe/stripe-php v22.1.0**, release 2026-10-29, API `2026-10-28.endive` en archivo del tag. Requiere PHP ≥7.4/curl/json/mbstring, satisfacible por restricciones del proyecto y CLI observado; sin dependencia Laravel. **No instalado/resuelto/probado**. Pin/lock y prueba PHP web en fase con autorización de instalación, no dev-master/preview.
- PayPal **REST oficial vía cliente HTTP Laravel existente**, sin SDK nuevo: OAuth v1, Orders v2, Payments v2, Notifications v1 en Sandbox. Usar payment_source.paypal.experience_context, no depender de application_context obsoleto. Especificaciones oficiales consultadas: Orders 2.32, Payments 2.12, Notifications 1.11; versiones del documento, no nuevos sufijos URL.
- Stripe: USD dos decimales/unidades menores; mínimo general **USD 0,50** para liquidación USD. USD 1,00–1.000,00 supera mínimo y cabe en máximos generales publicados. Otra moneda de liquidación/país/cuenta puede imponer equivalentes/restricciones; no acredita cuentas concretas ni elegibilidad regional. No inflar importe/cambiar límites BOB.
- PayPal: USD soportado, Money string decimal; aquí dos posiciones. Tabla de monedas no prueba mínimo/máximo universal de cuenta ni recepción automática USD. Preferencias sin saldo USD pueden dejar pendiente; comprobar Business/moneda/país/límites/comprador Sandbox posteriormente. **No probado USD 1 ni 1000 en cuentas existentes.**
- Stripe idempotencia v1 puede podar desde **24 h**; Orders PayPal documenta **6 h**, ampliación hasta 72 h no asumida. Payments v2 tiene condiciones por endpoint: no extrapolar ventana Orders a refund, comprobar antes de operar. Historia local durable y UUID por operación; tras ventana vencida no emitir nueva escritura para despejar incertidumbre.
- PayPal requiere HTTPS 443, hasta 25 entregas en 3 días; Stripe HTTPS público/TLS ≥1.2 y CLI para loopback. Reintentos no sustituyen conciliador local. No se ejecutaron login/listen/túneles/registro webhooks/OAuth/llamadas de cuenta.

### 7.2. Procesos futuros de conciliación y devolución

- Trabajo durable PostgreSQL, scheduler cada minuto por lotes, locks/leases, backoff acotado y recuperación de abandonados; withoutOverlapping solo apoyo. Tabla/motor definitivos Fase 2; sin instalar broker. Métricas seguras por referencias/contadores, sin clínica.
- Timeouts técnicos iniciales conexión 5 s/total 20 s, probar después. Sin retry automático de POST financiero del cliente HTTP. Lecturas con backoff; escrituras solo misma clave/cuerpo, ventana válida y conciliación previa; respetar Retry-After externo. Sin SQL abierto durante red.
- CU-16 confirma no exigibilidad/cierre/conciliación/trabajo refund localmente. CU-15 conserva obligación/precio/pago, ajusta vencimiento solo impagada y audita, sin ampliar plazo de reprogramación. Inasistencia no devuelve; fallos externos no revierten asistencia/cancelación.
- Stripe refund: `POST /v1/refunds` con PaymentIntent/cargo **aplicado**, total USD bruto original/clave propia; consultar refund por ID. succeeded → Exitosa; pending/requires_action → Procesando (incidencia por acción no soportada); failed/canceled → Fallida; timeout/desconocido → Incierta. No éxito por crear/HTTP 200/charge parcialmente refunded.
- PayPal refund: `POST /v2/payments/captures/{capture_id}/refund` cuerpo `{}` para total, sin importe parcial elegido; clave propia. `GET /v2/payments/refunds/{refund_id}`: COMPLETED → Exitosa, PENDING → Procesando, FAILED/CANCELLED → Fallida; sin evidencia → Incierta. Verificar importe/moneda/captura y ausencia de devolución previa/parcial externa; discrepancia incidencia sin sobredevolver. PAYMENT.CAPTURE.REFUNDED despierta consulta, no prueba total local.
- Una devolución total lógica por cobro aplicado; intentos externos auditados sin multiplicar monto. Reintento solo con fallo terminal sin devolución y política automática segura; incierta jamás clave nueva. Fallos definitivos visibles, sin endpoint manual ni medio alternativo. Doble cobro no aplicado no entra a devolución automática por cancelación.

## 8. Verificación y continuidad

Revisión documental de permisos, precio/token, exactitud, exclusión entre proveedores, captura/cierre, eventos duplicados/desordenados y cancelación/refund tardío. Comprobaciones ejecutables solo CLI PHP/extensiones, hashes/diferencias, UTF-8/enlaces/anclas locales y GET públicos oficiales. Sin pruebas funcionales, DB, SDK instalado ni cambios operativos/React. Evidencia/limitaciones en [estado-fase.md](estado-fase.md).

Pendiente con **autorización específica de operaciones externas**: identidad/entorno de cuentas/app, USD/extremos/preferencias, payloads/eventos reales, cierre/captura/refund y recepción HTTPS con cuentas concretas. No se simula validado. La posterior [Fase 2](pagos-fase2-modelo.md) implementó esquema/modelos/dinero, no endpoints ni configuración. Próxima fase autorizable: **Fase 3**; no comenzar por inferencia. Detenerse para revisión; recomendar sesión nueva con relevo.

## 9. Fuentes oficiales consultadas el 2026-11-05

Solo GET públicos sin autenticación/operaciones de cuenta. Algunas páginas PayPal antiguas devolvieron carcasa dinámica; se contrastaron `.md` y OpenAPI oficiales, no se tomó carcasa como evidencia.

- Stripe: [monedas/mínimos](https://docs.stripe.com/currencies), [webhooks](https://docs.stripe.com/webhooks), [idempotencia](https://docs.stripe.com/api/idempotent_requests), [refunds](https://docs.stripe.com/refunds), [crear Checkout](https://docs.stripe.com/api/checkout/sessions/create), [expirar Checkout](https://docs.stripe.com/api/checkout/sessions/expire).
- SDK: [release v22.1.0](https://github.com/stripe/stripe-php/releases/tag/v22.1.0), [requisitos del tag](https://github.com/stripe/stripe-php/blob/v22.1.0/composer.json), [API del tag](https://github.com/stripe/stripe-php/blob/v22.1.0/lib/Util/ApiVersion.php).
- PayPal: [USD/monedas](https://developer.paypal.com/reference/currency-codes.md), [idempotencia](https://developer.paypal.com/reference/guidelines/idempotency.md), [firmas/HTTPS](https://developer.paypal.com/api/rest/webhooks/rest.md), [eventos](https://developer.paypal.com/api/rest/webhooks/event-names.md).
- OpenAPI oficial PayPal: [Orders](https://github.com/paypal/paypal-rest-api-specifications/blob/main/openapi/checkout_orders_v2.json), [Payments](https://github.com/paypal/paypal-rest-api-specifications/blob/main/openapi/payments_payment_v2.json), [Notifications](https://github.com/paypal/paypal-rest-api-specifications/blob/main/openapi/notifications_webhooks_v1.json). Consultados endpoints/esquemas pertinentes; hosts live de los schemas no están permitidos aquí.
