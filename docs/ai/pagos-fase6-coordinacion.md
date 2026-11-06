# Pagos — Fase 6: coordinación backend (2026-11-06)

## Estado: implementada y verificada localmente con simulaciones; pendiente de revisión

F6 autorizada exclusivamente para backend y simulaciones, concluida localmente con los límites inferiores. **No se habilita CU-32 ni se acredita integración externa real.** Final dirigida **343 pruebas/3887 aserciones correctas, código nativo0**,154,018s/148MB. Incluye19 casos adicionales sobre324 de F5 y seis carreras multiproceso nuevas; no sumar ejecuciones anteriores. PostgreSQL fue iniciado por el usuario tras el bloqueo y su aislamiento se revalidó antes de pruebas y al finalizar.

**Decisión confirmada:** conservar incidencias pendientes, sin nuevas acciones de resolución, compensaciones por doble cobro, reintentos de refunds terminales ni éxitos manuales. Refund PayPal perdido sin ID, ventanas agotadas, discrepancias y reversals requieren evidencia/procedimiento posterior autorizado.

## 1. Reutilización

Seis entidades/DDL F2 intactos; emisión/asignación y BOB/USD histórico exacto F3; vencimiento calculado sin estado persistido; reprogramación con misma obligación/precio/pago y actualización de vencimiento impagado; cancelación con no exigibilidad/CambioCita/auditoría; gateways/inbox/idempotencia/recuperadores F4/F5; devolución lógica única del aplicado, también tardío. Sin ampliar permisos ni cambiar/reabrir los cinco estados asistenciales.

## 2. Protocolo implementado y garantías locales

- `SeguridadInicioPagoService.disponible`: no aplicado/intento activo/escritura pendiente o incierta; **todos** los intentos históricos Cerrados, permiso revocado y prueba durable `CIERRE_SIN_COBRO_VERIFICADO` en Crear; escrituras históricas terminales. Etiqueta Cerrado/Fallido aislada no basta. Elegibilidad y reintento usan la política; inicio la revalida dentro de Cita→obligación antes de crear intento.
- Stripe: GET corroborado expired/unpaid y ausencia de PI cobrable producen prueba de cierre; snapshot open atrasado no reabre el intento. El cierre de un intento no finaliza una escritura de otro. Expire fuera de SQL y consulta posterior; cobro durante carrera se conserva, no habilita nuevo intento.
- PayPal: cierre humano durable Consultar/solicitud Cerrar, distinto del permiso consumido por handoff. Captura Pendiente también impide cierre prematuro. Cierre/cobro aplicado impiden nuevos POST de captura, incluidos reintentos de handoff previo. GET sin capturas tras handoff incierto **no libera exclusión**. Sin API ficticia de expiración Orders.
- Handoff Crear durable `_handoff` en datos cifrados, excluido del cuerpo enviado; token/lease revalidados antes de escribir al proveedor. Misma clave y cuerpo externo. **Handoff local no demuestra recepción remota**; no locks SQL durante HTTP ni garantía de transacción distribuida.
- `CoordinacionCobroPagoService`: evidencia aplicada tardía tras cambio revoca captura PayPal o prepara cierre/consulta Stripe del otro intento activo. Stripe no vuelve a ofrecer Checkout por resultado/replay cuando ya existe aplicado, también revalida antes de entregar la URL corroborada. Operación ya entregada sigue conciliándose; si ambos cobraron, conservar ambos/aplicar uno/incidencia del adicional, sin compensación adicional automática.

## 3. Recuperación acotada

- `ProcesamientoEventoPagoService`: claim/token/lease5min del inbox, independiente del financiero; caída deja Procesando recuperable y finalización atrasada no pisa al nuevo dueño. Reutiliza columnas existentes. La aplicación exige consulta autoritativa.
- `ConciliacionPagosService` / `pagos:conciliar --lote=100` (1–1000): eventos y recuperador de cada proveedor habilitado; fallo de uno no impide turno del otro. Scheduler financiero único cada minuto; comandos específicos manuales conservados. Sin motor genérico ni resolución manual; salida solo contadores/estado seguro.
- Selecciones Stripe filtradas por cuenta; PayPal conserva contexto app/merchant. Recuperar anuladas con devolución lógica aún sin operación, aunque el intento sea terminal. No recrear refunds terminales.
- Fecha de ejecución durable y Retry-After ante errores; Incierta no se ejecuta antes. Ventanas Stripe23h/Orders PayPal6h conservadas; nunca clave nueva para despejar incertidumbre. Refund PayPal sin ID tras handoff espera referencia verificable: no ventana Orders ni búsqueda/listado inventados.
- Consultas financieras corregidas: operaciones usan **Incierta**, no Incierto, también en filtro/detalle con intento Exitoso.

## 4. Archivos F6

Nuevos `backend/src/business/services/pagos/{SeguridadInicioPagoService,ProcesamientoEventoPagoService,CoordinacionCobroPagoService,ConciliacionPagosService}.php`.

Ajustes en el mismo módulo: `StripePagoService`, `PayPalPagoService`, `RecuperacionStripeService`, `RecuperacionPayPalService`, `EvidenciaStripeService`, `EvidenciaPayPalService`, `EventoStripeService`, `EventoPayPalService`, `PayPalGateway`. Repositories: `StripePagoRepository`, `PayPalPagoRepository`, `ObligacionPagoRepository`. Comando/scheduler: `backend/routes/console.php`. Sin cambios F6 de Models/migraciones/Composer/lock/API humana/middleware/fronteras de firma.

Pruebas: ampliados `backend/tests/Feature/PagosPayPalTest.php` y `PagosStripeTest.php`; nuevo `backend/tests/Fixtures/pagos-coordinacion-worker.php`. Cambios bidireccionales, cierres no probados, captura preparada/handoff incierto/revocación, evidencia tardía/refund del aplicado, snapshot antiguo, leases/inbox, Incierta, refund sin despacho y apagado verificados. Seis carreras nuevas ejecutadas: inicio/inicio ambos órdenes, cierre/inicio ambos sentidos, webhook/inicio y takeover de lease vencido con respuesta de red del dueño anterior. Las cinco primeras usan barreras/espera real observada con `pg_blocking_pids`; takeover usa dos workers y reloj simulado avanzado6min. Proveedor simulado por proceso, no garantía de red distribuida real.

## 5. Verificaciones ejecutadas

- Final: PHP8.5.11/PHPUnit12.5.40, **343 pruebas/3887 aserciones correctas**,154,018s/148MB/código nativo0. Regresión financiera y asistencial dirigida, no suite global rutinaria. Seis escenarios multiproceso nuevos más ocho previos (Stripe/PayPal/seis financieros). Roles/ajenos/entradas/manipulación/precio/vencimiento/reprogramación, firmas/inbox/eventos, cancelación/cobro tardío/refund único, doble cobro no aplicado, ventanas/leases/rollback/apagado cubiertos por la selección.
- Sintaxis20 PHP/Pint dirigido/autoload sin scripts correctos; diff-check sin errores, advertencias CRLF previas. No instalación/actualización Composer ni cambio de Composer/lock por F6.
- PostgreSQL18.6 local/testing/pgsql/base-rol-propietario clinica_vida_salud_test comprobados antes y después, sin privilegios elevados/membresías/URL alternativa/config cacheada. Solo migraciones existentes en esquemas propios de pruebas; public0 tablas/funciones financieras y esquemas Stripe/PayPal0 al finalizar. Esquema ajeno `prueba_auth_sx1lpauqw780c1tw` conservado; no bases/roles ni migración habitual/remota.
- Bloqueo inicial: conexión rechazada en preflight, sin lanzar PHPUnit ni escribir SQL; usuario inició el servidor. Primer pase posterior342/3849, código nativo2: una expectativa de replay PayPal inmediato ignoraba ahora la espera durable; una fixture excepcional abría sesión mediante un worker que F6 bloquea tras cobro aplicado. Se corrigieron las pruebas, **sin relajar producción**: replay espera61s y anomalía externa se construye solo en el fake. Se añadió takeover multiproceso; pase343/3885/código0. Revisión final añadió protección de URL Stripe ya liquidada y dos aserciones al caso existente; final343/3887/código0. Logs conservados, sin sumar pases.
- Runner externo `C:\Users\jesus\AppData\Local\Temp\opencode\pagos-fase6-verificar.php`, modo `test`: preflight de lectura, PHPUnit512MiB solo proceso, wrapper sin timeout; logs finales `pagos-fase6-final3-{stdout,stderr,exit}.log`, pases anteriores `pagos-fase6-final-*`/`final2-*`; control posterior de solo lectura `pagos-fase6-control-final.php/.log`.
- Selección: ImportePago/Persistencia/ReservaIntegracion/GestionCitaIntegracion/F3/Historial/ConsultaCita/AgendaPermisos/Stripe/PayPal y seis `test_financial_assignment_serializes`. OAuth/postback/SDK/HTTP/proveedores simulados; peticiones no simuladas impedidas. No SQLite, credenciales ni integración externa real. Ni SQL ni mocks sustituyen comprobar cuentas, HTTPS, entrega/eventos y recepción remota de solicitudes.

## 6. Continuidad y límites

Incorporación/Stripe/PayPal mantienen defaultfalse; sin credenciales/configuración privada, activación habitual, producción, dinero real, nuevas dependencias/migraciones, bases/roles, React/F7/F8, Word/PDF/diagramas académicos, commit/push/despliegue. Cambios anteriores y esquema ajeno `prueba_auth_sx1lpauqw780c1tw` conservados. Siete avisos previos en cuatro paquetes Symfony no reevaluados ni corregidos, auditoría no deshabilitada.

**Detenerse para revisión F6.** No activar flags ni continuar automáticamente. Incertidumbres agotadas/sin localizador, refund PayPal sin ID, refunds terminales, reversals y dobles cobros excepcionales conservan sus límites y revisión pendiente; sin garantía de recuperación autónoma universal. Cuentas concretas/HTTPS/runtime web/USD extremos/operaciones externas requieren autorización específica. F7 React y F8 integración académica externa/guion/regresión ampliada siguen sin iniciar y requieren autorización independiente. Recomendada sesión nueva con el relevo.
