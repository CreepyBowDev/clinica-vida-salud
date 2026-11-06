# Módulo de Pagos de citas — especificación aprobada

**Ampliación académica posterior aprobada (2026-11-06):** [instalación publicada dedicada `academic` y recibo PDF](pagos-academico-publicado-recibo.md), sin habilitar `production` ni dinero real. Recibo inmutable emitido junto al cobro aplicado, no factura fiscal; detalle real de cita/obligación compartido por titular/Administrador. Código y pruebas locales, no activación/migración habitual/remota ni publicación. Las evidencias inferiores son históricas.

**Decisión posterior aprobada e implementada (2026-11-06):** [pago académico simulado persistente](pagos-simulacion-persistente.md) para obligaciones elegibles de citas habituales, no solo fixture ni memoria. Sustituye la opción A/demostración exclusivamente visual y amplía «Pagada» con origen simulado confirmado localmente; no sustituye ni acredita evidencia externa Stripe/PayPal. Separación persistida, titularidad, precio histórico, exclusión de operaciones inciertas y estados asistenciales intactos. Migración000041/configuración académica apagada creadas; aplicación/activación habitual pendientes de autorización específica. Las notas siguientes conservan cronología y no revierten esta decisión.

**Estado Fase6 (2026-11-06):** [coordinación backend](pagos-fase6-coordinacion.md) implementada/verificada localmente, pendiente de revisión: final343 pruebas/3887 aserciones/código nativo0, seis carreras nuevas más ocho previas, proveedores/OAuth/postback simulados. PostgreSQL18.6 aislado revalidado antes/después; public vacío/esquema ajeno conservado. Incidencias pendientes por confirmación expresa, sin resolución nueva/compensación adicional/reintentos refund terminales. Sin dependencias/migraciones nuevas, activación, operaciones externas ni React/F7/F8; flags false. Estados inferiores conservan cronología, no revierten este avance ni acreditan integración real.

**Fecha:** 2026-11-05. **Estado:** Fases 0–1 documentales realizadas; Fase 2 de infraestructura implementada y verificada exclusivamente en PostgreSQL aislado. Sin activación, endpoints ni CU de pagos operativos. Fase 3 pendiente de autorización. Detalle: [modelo y garantías de Fase 2](pagos-fase2-modelo.md).

La fuente de esta ampliación es la aprobación expresa del responsable del proyecto en la conversación de planificación y su autorización posterior para formalizar la documentación. No procede del examen ni del PDF académico original. Sustituye la exclusión anterior de pagos únicamente para el alcance académico descrito aquí y descarta la propuesta de tarifa global. Los ejemplos previos de Bs 140,00 no son precios predeterminados.

**Catálogo vigente:** 34 CU: los treinta originales, intactos, más CU-31–CU-34. RF01–RF13, RN01–RN14, PN01–PN24 y numeración de los documentos académicos originales no se alteran. No se ha implementado ningún CU de Pagos; los contratos físicos de Fase 1 están diseñados documentalmente, no operativos.

## 1. Objetivo, alcance y exclusiones

Gestionar obligaciones individuales vinculadas a citas; permitir al Paciente pagar exclusivamente las propias mediante Stripe Checkout de prueba o PayPal Sandbox, y al Administrador consultar el historial financiero de un Paciente seleccionado. Ambos proveedores son obligatorios e independientes dentro de un único módulo React/Laravel/PostgreSQL.

Incluye pendientes, vencidas regularizables, historial, detalle de obligaciones e intentos, verificación de resultados, conciliación, trazabilidad y devoluciones totales automáticas por cancelaciones autorizadas. No incluye dinero real, producción, cuotas, suscripciones, pagos parciales, catálogo de tarifas, tarifa global, monedero, descuentos, recargos, facturación fiscal ni cobro por terceros. Un comprobante no equivale a factura fiscal.

## 2. Actores y permisos

| Actor | Acciones autorizadas | Límites |
| --- | --- | --- |
| Paciente | CU-31, CU-32 y CU-33 sobre su ficha vinculada | No introduce precios, no paga citas ajenas, no cancela/reprograma ni altera resultados. |
| Administrador | CU-34 por Paciente seleccionado; establece precio por la ampliación de CU-14 | No paga por el titular, no modifica obligaciones emitidas ni marca pagos/devoluciones como exitosos. |
| Recepcionista | Establece precio inicial o posterior por CU-14 | Acceso mínimo a la cita y asignación; sin historial financiero general, pago delegado ni nuevos permisos financieros. |
| Médico | Sin nuevos permisos financieros | Los datos financieros no se incorporan indiscriminadamente a sus respuestas de cita. |
| Stripe / PayPal | Sistemas externos de CU-32 y de devoluciones | No son roles humanos ni conceden permisos locales. |
| Sistema | Procesa eventos, conciliación y devoluciones autorizadas | Conserva iniciador del pago y responsable de la cancelación; no suplanta usuarios. |

Administrador conserva 28 de los **30 originales**, excluidos CU-04/CU-08. En los cuatro nuevos solo ejecuta CU-34. La autorización siempre corresponde a Laravel por rol, cuenta, sesión y recurso. No se modifica el mecanismo de autenticación para integrar pagos.

## 3. Requerimientos de la ampliación

Estos identificadores son propios del módulo, no RF oficiales nuevos.

| ID | Requerimiento aprobado |
| --- | --- |
| PAG-RF-01 | Incorporar las citas nuevas al esquema financiero sin cobrar retroactivamente las anteriores. |
| PAG-RF-02 | Solicitar monto BOB obligatorio en la reserva administrativa y mostrarlo antes de confirmar. |
| PAG-RF-03 | Registrar reservas propias sin precio y permitir su asignación posterior por personal autorizado. |
| PAG-RF-04 | Emitir exactamente una obligación por cita cuando exista monto válido y autorizado. |
| PAG-RF-05 | Conservar importe BOB, tasa, USD, emisor y fecha históricamente. |
| PAG-RF-06 | Consultar obligaciones propias, incluidos vencimiento y regularización. |
| PAG-RF-07 | Revisar BOB/tasa/USD y elegir Stripe o PayPal antes de iniciar un pago propio. |
| PAG-RF-08 | Verificar resultados en backend y registrar intentos exitosos, fallidos o pendientes de resolución. |
| PAG-RF-09 | Impedir operaciones cobrables simultáneas y aplicar una sola liquidación por obligación. |
| PAG-RF-10 | Consultar historial y detalle propios, con filtros y paginación. |
| PAG-RF-11 | Consultar obligaciones e historial por Paciente como Administrador, en solo lectura. |
| PAG-RF-12 | Coordinar reprogramación, cancelación e inasistencia sin alterar los estados asistenciales. |
| PAG-RF-13 | Ejecutar devoluciones totales verificables por cancelación, incluido cobro confirmado tardíamente. |
| PAG-RF-14 | Recuperar operaciones inciertas, deduplicar eventos y conservar auditoría segura. |
| PAG-RF-15 | Rechazar producción y utilizar únicamente pruebas académicas sin dinero real. |

Calidad transversal: exactitud monetaria sin float, restricciones SQL, idempotencia, privacidad, accesibilidad, responsive, errores HTTP seguros y ausencia de información clínica o credenciales en proveedores/logs. Las pruebas serán ficticias y aisladas.

## 4. Precio individual y emisión

### 4.1. Registro administrativo

Administrador/Recepcionista introduce **Monto de la consulta (BOB)**, obligatorio, entre **Bs 7,00 y Bs 7.000,00 inclusive**, positivo y con máximo dos decimales. React solo ayuda a validar; Laravel comprueba formato, límites, actor y permiso. El importe revisado debe quedar ligado a la propuesta de reserva para impedir cambios entre revisión y confirmación.

Al confirmar se guardan cita, obligación y auditoría en una transacción local, conservando idempotencia de reservas y control de disponibilidad. Repetir la confirmación no emite otra obligación. No se llama al proveedor dentro de los locks/transacción de reserva.

### 4.2. Reserva del Paciente y asignación posterior

La reserva propia sigue siendo una cita **Programada**, válida y ocupante del intervalo, sin obligación inicial ni importe ficticio. Laravel rechaza campos monetarios no autorizados enviados por el Paciente. Mostrar **«Pendiente de asignación de precio»**; no es un sexto estado de cita.

Administrador/Recepcionista utiliza un filtro administrativo sin precio y la acción **«Asignar monto»** desde el detalle. Revisa y confirma una sola vez. Laravel revalida sesión/rol, incorporación financiera, cita no cancelada, importe y ausencia de obligación, y emite esta con auditoría atómicamente. Un lock de cita y unicidad por cita protegen dos asignaciones concurrentes y la carrera con cancelación. No hay aprobación multinivel ni nuevo CU humano.

Se permite asignación después del inicio, incluso sobre una atención o inasistencia registradas, siempre que la cita no esté cancelada y sea del nuevo esquema. La fecha de emisión es real; el vencimiento ordinario sigue siendo el inicio de la cita. Nace vencida pero regularizable, sin emisión retroactiva ficticia.

El Paciente consulta luego el precio en el portal y revisa BOB/tasa/USD antes de pagar. No se añade un canal de correo/push financiero. Una vez emitida, no existe edición administrativa ordinaria del importe. Una futura corrección requeriría procedimiento expresamente autorizado; no cancelar/recrear automáticamente como atajo.

### 4.3. Conversión exacta

- Moneda original BOB; moneda externa USD; **7,00 BOB por 1 USD**, tasa académica, no cotización oficial.
- Emitir la obligación congela importe, tasa, USD, fecha y usuario que fijó el precio. Ambos proveedores utilizan ese mismo equivalente.
- Guardar importes en centavos enteros. Representar la tasa exactamente, no con float.
- Con tasa 7:1: `centavos_USD = HALF_UP(centavos_BOB / 7)`. Para enteros positivos puede calcularse `intdiv(2 * centavos_BOB + 7, 14)` dentro de los límites aprobados. Redondear una sola vez.
- Stripe recibe centavos enteros; PayPal un decimal con dos posiciones construido sin float y moneda USD.
- Ejemplos: Bs 70,00 → USD 10,00; Bs 140,00 → USD 20,00; Bs 210,00 → USD 30,00. Bs 7,00–7.000,00 corresponde a USD 1,00–1.000,00.
- Verificar mínimos/restricciones de las cuentas antes de integrar. Si fueran incompatibles, informar y solicitar ajuste, nunca cambiar límites o inflar cobros silenciosamente.

## 5. Exigibilidad, vencimiento y cambios de cita

La obligación conserva **exigibilidad**, **liquidación** y **fecha de vencimiento** como conceptos independientes. No persistir «Vencida» como estado independiente.

`vencida = exigible && sin_liquidacion && ahora >= vencimiento_ordinario`.

| Situación | Comportamiento aprobado |
| --- | --- |
| Pendiente de precio | No existe obligación; pago deshabilitado, cita válida. |
| Pendiente de pago | Obligación exigible sin liquidar y dentro del plazo; puede pagarse. |
| Vencida regularizable | Exigible sin liquidar después del inicio; puede pagarse, sin recargos. |
| Pagada | Cobro verificado aplicado; no puede iniciarse otro cobro. |
| Anulada/no exigible | Cancelación retira exigibilidad; no permite nuevos pagos. Puede conservar un pago histórico y devolución pendiente. |
| Operación en verificación | Intento activo/incierto bloquea otro intento aunque la obligación siga impagada. |

La condición de verificación complementa la situación de la obligación; no la reemplaza ni demuestra pago. Los cinco estados de cita permanecen **Programada, Confirmada, Atendida, Cancelada e Inasistencia**. Pagar no confirma llegada ni marca Atendida. Impago no cancela ni bloquea llegada/atención.

**Reprogramación:** misma cita y obligación, mismos importes y tasa, sin nuevo cobro si ya pagó. Si impagada, actualizar vencimiento al nuevo inicio, con valores anterior/nuevo, fecha y responsable en trazabilidad. No ampliar precondiciones de CU-15: el código actual impide reprogramar desde el inicio; no inventar una excepción para obligaciones vencidas. Una nueva programación autorizada se reflejaría en el cálculo, no mediante reapertura de un estado financiero terminal.

**Cancelación:** retirar exigibilidad sin eliminar obligación/intentos. Sin cobro confirmado, cerrar/reconciliar operaciones; con cobro confirmado, registrar devolución total autorizada. La cancelación asistencial se conserva si el proveedor falla. No bloquearla por una llamada externa.

**Inasistencia:** conserva pago o deuda vencida regularizable; sin devolución automática, nueva obligación ni penalización.

**Sin cuenta:** la obligación se relaciona con cita/Paciente, no exige usuario web. La vinculación existente de CU-04 conserva la misma ficha (`RegistroPacienteService`, rama Vinculacion); no regenerar obligaciones al vincular. Solo después de autenticación y vínculo válido consulta/paga el titular. Sin pagos delegados ni exposición pública por CI/correo.

**Citas anteriores:** no generar obligaciones retroactivas. Mostrar «No incorporada al módulo financiero», no «Pagada». Distinguirlas de las nuevas pendientes de precio mediante marca de incorporación controlada por el servidor; la fecha exacta de activación se determinará en la implementación autorizada.

## 6. Integraciones, idempotencia y conciliación

### 6.1. Stripe Checkout

Checkout alojado, `mode=payment`, tarjeta de prueba y USD. Crear sesión con idempotencia durable y referencias opacas, sin motivo clínico/especialidad. Conservar sesión y PaymentIntent/cargo. Verificar firma con cuerpo original y secreto del endpoint correcto; comprobar entorno, cuenta, referencias, importe, moneda y estado de pago. El evento de sesión completada no acredita por sí solo un cobro si su situación financiera no corresponde.

La vuelta al portal solo dispara consulta/reconciliación autorizada; no demuestra éxito. Un rechazo dentro de Checkout puede permitir otro intento de tarjeta en la misma sesión, por lo que no libera automáticamente la obligación para otro proveedor. Cerrar pestaña o volver por cancel_url tampoco expira la sesión.

### 6.2. PayPal Sandbox

Orders API v2 con `intent=CAPTURE`, aprobación del comprador Sandbox y captura desde Laravel. Aprobar no significa pagar. Usar `PayPal-Request-Id` durable y distinto por creación/captura; conservar orden y captura. Verificar captura completada, importe, moneda, destinatario, entorno y vínculo local. Respuesta autenticada y webhook convergen en la misma aplicación idempotente; no liquidar dos veces.

Verificar eventos con el mecanismo oficial y Webhook ID de la aplicación Sandbox. Un simulador de webhook no sustituye orden/captura real Sandbox. Si se usan certificados, restringir sus URLs y evitar SSRF; la verificación remota no permite aceptar eventos cuando falla su propia consulta.

### 6.3. Exclusión entre proveedores

Una obligación admite varios intentos históricos, pero solo uno cobrable, preparando, procesando o incierto. Reservar el intento bajo transacción corta; llamar fuera de SQL; finalizar con revalidación. La pérdida de una respuesta no libera el intento.

Cambiar Stripe→PayPal exige cerrar la sesión Stripe y comprobar si expiró sin cobro o se completó durante la carrera. Si se pagó, liquidar y rechazar el nuevo intento; si hay incertidumbre, conciliar. PayPal→Stripe exige cerrar el permiso local de capturar la orden anterior y comprobar que no hay captura en curso/incierta; no suponer una API de expiración idéntica a Stripe. Todas las vías de captura deben respetar ese permiso local.

No hay transacción distribuida PostgreSQL/proveedores. La garantía local es una liquidación por obligación y exclusión de cobros por los flujos normales. Si excepcionalmente se confirman dos cobros, conservar ambos, aplicar solo uno y registrar el segundo como incidencia **no aplicada**. No devolver automáticamente el segundo por duplicación: procedimiento posterior expresamente autorizado. Una cancelación devuelve el cobro aplicado conforme a su política; no habilita compensación automática adicional de la incidencia.

### 6.4. Recuperación y eventos

Persistir recepción verificada/deduplicada y aplicar resultados transaccionalmente. No confirmar recepción como exitosa si se perdería el evento necesario para recuperarla. Soportar eventos fuera de orden consultando estado autoritativo, sin degradar un cobro confirmado por un evento viejo.

Scheduler/procesos por lotes concilian intentos/eventos/devoluciones pendientes. Timeout no equivale a fallo; consultar antes de repetir escrituras. Las ventanas de idempotencia externas son limitadas: no reintentar ciegamente con otra clave tras vencerlas. Los webhooks siguen procesándose aunque el iniciador haya cerrado sesión o su cuenta se haya desactivado; eso no autoriza nuevos pagos.

## 7. Devoluciones totales por cancelación

Automáticas solo para el cobro aplicado de una cita cancelada, incluido pago iniciado antes de cancelar y verificado después. Usar proveedor original y **USD efectivamente cobrado**, sin nueva conversión. Conservar BOB histórico, cobro y cancelación.

1. Cancelación/resultado tardío registra trabajo local durable e idempotente.
2. Después del commit, procesar Stripe Refunds test o `POST /v2/payments/captures/{capture_id}/refund` de PayPal Sandbox.
3. Guardar referencia externa y resultado verificable; respuesta de creación no implica éxito terminal.
4. Consultar/reflejar eventos para pendientes o inciertas; no repetir a ciegas.
5. Mantener estados **Pendiente, Procesando, Exitosa, Fallida, Incierta** según correspondencia verificable del proveedor. Reintentos externos se mantienen auditables.

Una devolución total lógica por cobro, sin superar su importe ni duplicar solicitudes activas. Ningún usuario marca manualmente éxito, cambia destinatario o solicita parciales. Fallo no revierte cancelación ni crea otra deuda. Fallos definitivos e incidencias permanecen visibles para revisión posterior, sin presentarlos como resueltos. No se garantiza un plazo de finalización externo. La ejecución con las cuentas concretas permanece sin probar.

## 8. Modelo conceptual y arquitectura previstos

**Modelo conceptual conservado.** Fase 2 concretó migración 000040, seis tablas/Models y `ImportePago`: [esquema efectivo, incorporación y límites de integridad](pagos-fase2-modelo.md). Aplicación solo transaccional en pruebas aisladas con rollback; no base habitual migrada ni flujos operativos. La tabla siguiente conserva las responsabilidades funcionales, no nombres físicos.

| Elemento | Datos/responsabilidad mínima |
| --- | --- |
| Cita: incorporación financiera | Marca inmutable controlada por servidor: existentes excluidas, nuevas incorporadas. Sin estado de pago duplicado. |
| Obligación | Cita única; BOB/centavos, tasa exacta, USD/centavos; usuario y fecha de determinación/emisión; vencimiento; exigibilidad; motivo/fecha de anulación; referencia de liquidación. Paciente deriva de la cita. |
| Intento | Obligación, iniciador, proveedor/entorno, estados local/externo, referencias, idempotencia por operación y fechas/conciliación. Hereda importes históricos de la obligación inmutable; coteja importes externos. |
| Evento | Proveedor/entorno/ID único, tipo, referencia, recepción, procesamiento y recuperación; datos mínimos, no payload sensible indiscriminado. |
| Devolución | Cobro original, importe USD, motivo, referencia/idempotencia, estado y recuperación; cancelación y responsable asociados, ejecutor SISTEMA cuando corresponda. |
| Auditoría/incidencia | Usar bitácora/referencias seguras para responsables, transiciones y cobro adicional no aplicado; no crear un módulo genérico de incidencias. |

Restricciones: FK restrictivas, unicidad obligación/cita; unicidad referencias por proveedor/entorno y eventos; una operación activa/incierta por obligación; coherencia del intento liquidador; importes/límites/monedas; una devolución total lógica por cobro. Permitir registrar ambos éxitos externos excepcionales, sin una restricción que oculte el segundo. Índices para titularidad/historial, vencimientos exigibles y conciliación. No tabla de tarifas ni estado Vencida persistido.

Arquitectura: `React → Service/Axios → Route/Middleware → Controller/FormRequest → Service → Repository → Eloquent → PostgreSQL`, con adaptadores externos independientes. Una abstracción pequeña comparte inicio/consulta/resultados; captura PayPal y verificación de cada webhook siguen específicas. Sin BaseRepository, microservicios, otra aplicación ni segunda base.

Puntos reales para implementar después: `ReservaService` (creación atómica), `GestionCitaService` (reprogramación/cancelación), autorización de consultas de agenda y proyecciones financieras. Patrones de despacho externos existentes orientan transacciones cortas y recuperación, no reutilización de tablas push.

## 9. Frontera HTTP, configuración y React

Los endpoints financieros aún no existen. El [diseño de Fase 1](pagos-fase1-contratos.md) fija métodos/rutas, permisos, dinero exacto, revisión de reserva/asignación, consultas propias/admin, inicio/captura/cierre/resultados, errores, configuración académica y frontera webhook. Es contrato diseñado, no implementado; conserva `data/meta` y proyecciones autorizadas. Incorporar códigos financieros al mapeo central en fases posteriores para no convertir conflictos en 500.

**Bloqueo conocido:** `bootstrap/app.php` aplica `ExigirOrigenSpa` al grupo API; rechazaría webhooks normales. Diseñar frontera servidor-servidor autenticada por proveedor, sin exigir sesión/CSRF SPA y sin debilitar otras rutas. Preservar cuerpo original, límites de tamaño y correlación segura.

Solo claves Stripe test, objetos `livemode=false` y endpoints PayPal Sandbox permitidos; rechazar producción y URLs externas arbitrarias. Secretos exclusivamente en backend, fuera de Git/logs/frontend. No PAN/CVV, credenciales PayPal, datos clínicos o tokens en almacenamiento persistente del navegador. Retornos predefinidos sin redirecciones abiertas; referencias externas no sustituyen titularidad.

Revisar compatibilidad del SDK oficial Stripe antes de solicitar instalación; PayPal REST puede usar cliente HTTP Laravel sin SDK adicional. No instalar dependencias por la autorización documental. Cuentas concretas, USD, mínimos, credenciales, HTTPS/túnel y recepción de eventos deben verificarse con autorización posterior. No crear cuentas ni operaciones en esta fase.

React: `features/pagos/` con Page/Component/Service y pruebas en su `tests/`. Reutilizar layout/sidebar declarativo (`constants/navigation.js`), router, Axios y componentes; sin CU visibles ni páginas duplicadas por rol. Paciente: pendientes/sin precio/vencidas, revisión BOB/tasa/USD, proveedor, verificación e historial/detalle. Administrador: selección de Paciente, filtros y consulta financiera sin acciones de pago. Personal: monto administrativo y filtro/acción de asignación. Médico sin nuevas proyecciones financieras. No almacenar pagos en AuthContext ni localStorage. Carga, vacío, errores por campo, sesión, conflictos e incertidumbre deben ser explícitos y accesibles.

## 10. Plan aprobado — autorización independiente por fase

**Estado vigente 2026-11-06 — Fase5:** [PayPal Sandbox backend](pagos-fase5-paypal.md) implementado/verificado localmente con HTTP Laravel existente, sin dependencias/migraciones nuevas; autorización adicional limitada a cancelación/revocación de captura/cobro tardío/devolución del aplicado PayPal. Final324 pruebas/3616 aserciones, incluidas carrera PayPal, Stripe y seis financieras previas; proveedores/OAuth/postback simulados. Stripe F4 conservado. Incorporación/Stripe/PayPal false por defecto; sin base habitual migrada/activada, credenciales ni operaciones externas autenticadas. Configuración apagada/inválida → puede_iniciar_pago=false/revision_pago=null; no anunciar CU-32 operativo. No cambio operativo entre proveedores, conciliador genérico/incidencias/resto F6/React/F7/F8. Revisión pendiente; no autorización implícita para continuar. Notas históricas anteriores conservadas.

**Actualización posterior 2026-11-05:** Fase3 autorizada; al detectar dependencia con Fase6, autorización adicional limitada a coordinación local de cancelación/reprogramación con obligaciones. Esa coordinación está implementada/verificada (83 pruebas/1079 aserciones dirigidas); emisión/asignación/consultas de Fase3 permanecen pendientes en este punto de revisión. Cancelar retira exigibilidad/enlaza CambioCita real; reprogramar ajusta vencimiento exigible impagado. No cierre/captura/refund/conciliación externa ni resto de Fase6. Incorporación controlada/default off aprobada pero sin activación ni mecanismo operativo todavía; futuras respuestas de la entrega parcial puede_iniciar_pago=false/revision_pago=null. [Relevo vigente](estado-fase.md). La tabla conserva la planificación y el estado de su formalización anterior.

| Fase | Entregable | Estado tras esta formalización |
| --- | --- | --- |
| 0 | Especificación, requisitos propios, reglas, CU, alcance y relevo documental. Sin código/migraciones. | Realizada; el usuario autorizó continuar exclusivamente con Fase 1. |
| 1 | Contratos HTTP/errores/permisos, configuración exclusiva de pruebas y diseño de frontera webhook; verificar restricciones de cuentas disponibles. | Diseño realizado, pendiente de revisión. USD general contrastado con fuentes oficiales; cuentas concretas pendientes de autorización externa específica. |
| 2 | Migraciones autorizadas, entidades/restricciones, incorporación financiera y aritmética exacta 7:1 HALF_UP. | Implementada y verificada en PostgreSQL aislado: 74 pruebas/728 aserciones dirigidas. Sin activación ni migración habitual; pendiente revisión del usuario. |
| 3 | Reserva administrativa con monto, asignación posterior, obligaciones únicas, regularización y consultas CU-31/33/34. | Implementada/verificada localmente; incorporación apagada. |
| 4 | Stripe Checkout, firma/eventos, idempotencia, recuperación básica y soporte de refund test. | Backend implementado/verificado con simulaciones; revisión/integración externa pendientes, apagado por defecto. |
| 5 | PayPal órdenes/aprobación/captura, eventos, idempotencia, recuperación básica y refund Sandbox. | Backend implementado/verificado localmente con simulaciones; revisión/integración real pendientes, apagado por defecto. |
| 6 | Exclusión entre proveedores, cambio seguro, vencimiento calculado, reprogramación/cancelación, devoluciones automáticas autorizadas e incidencias. | Backend implementado/verificado localmente:343/3887/código0, proveedores simulados. Incidencias pendientes sin nueva resolución por decisión expresa; revisión/integración externa pendientes, flagsfalse. |
| 7 | React: campos/filtro/asignación, módulo Pagos, revisión, retornos, historial/admin, regularización y devoluciones. | No iniciada. |
| 8 | Integración, concurrencia, regresiones afectadas y guion académico de ambos proveedores; documentación final. | No iniciada. |

Cada fase incluye comprobación dirigida y documentación incremental; no posponer verificación de webhooks/recuperación hasta Fase 8. Sin autorización implícita de instalar, migrar bases habituales, crear cuentas u operar proveedores.

## 11. Criterios de aceptación y pruebas futuras

- Roles/titularidad: pago ajeno, campo monetario del Paciente, historial administrativo solo lectura, Recepción sin historial general, Médico sin datos nuevos.
- Límites exactos 7,00/7.000,00 y rechazo de cero/negativos/más de dos decimales/fuera de rango; HALF_UP y mismo USD en proveedores.
- Reserva administrativa atómica; revisión del precio; reserva propia sin obligación; asignación tardía; doble asignación/cancelación concurrente; replay sin duplicados.
- Precio inmutable; reprogramación y vencimiento calculado; atención sin pago; inasistencia regularizable; vínculo posterior a la misma ficha; citas antiguas excluidas.
- Stripe tarjeta de prueba exitosa/rechazada; PayPal comprador Sandbox y captura; abandono sin falso cierre.
- Doble clic, cobros concurrentes entre proveedores, cambio seguro, captura perdida, evento firmado/falso/duplicado/desordenado y conciliación sin nuevo cobro ciego.
- Cancelación pagada y cobro confirmado después; devolución total original verificada, pendiente/fallida/incierta y respuesta perdida; cancelación conservada; doble cobro registrado sin devolución adicional automática.
- No cambio asistencial al pagar; ausencia de producción/dinero real/secretos/contenido clínico. Datos ficticios, HTTP simulado y PostgreSQL aislado para automatizadas; sandbox para recorridos externos autorizados.

**Fase 0:** comprobar solo documentación, enlaces/anclas, coherencia de permisos/estados, diferencias y conservación de RF/RN oficiales. No se han ejecutado pruebas funcionales ni acreditado mínimos de cuentas, entrega de eventos, cobros o devoluciones.

## 12. Referencias y continuidad

- [Catálogo y fichas CU-31–CU-34](casos-de-us.md#cu-31), [reglas VS-PAG](reglas-negocio.md#15-bis-pagos--ampliación-aprobada-cu-31cu-34), [requerimientos propios](requerimientos.md#511-pagos-cu-31cu-34), [contexto](contexto-maestro.md), [relevo](estado-fase.md).
- Stripe: [Checkout y confirmación](https://docs.stripe.com/checkout/fulfillment), [pruebas](https://docs.stripe.com/testing), [expiración](https://docs.stripe.com/api/checkout/sessions/expire), [refunds](https://docs.stripe.com/refunds).
- PayPal: [órdenes/captura](https://developer.paypal.com/api/rest/integration/orders-api/api-use-cases/standard/), [monedas](https://developer.paypal.com/api/codes/currency), [idempotencia](https://developer.paypal.com/api/rest/reference/idempotency/), [webhooks](https://developer.paypal.com/api/rest/webhooks/rest/), [Payments v2/refunds](https://developer.paypal.com/docs/api/payments/v2/).

La documentación oficial respalda viabilidad general, no una prueba de las cuentas concretas. [Fase 1: contratos y configuración](pagos-fase1-contratos.md) conserva contratos HTTP no implementados. [Fase 2](pagos-fase2-modelo.md) incorpora infraestructura efectiva reflejada incrementalmente en DER/UML/análisis, sin modificar Word/PDF ni activar pagos. Detenerse y esperar revisión/autorización expresa antes de Fase 3.
