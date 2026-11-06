# Pagos — Fase 7 frontend (2026-11-06)

## Estado y alcance

Implementación React verificada localmente con transportes simulados; pendiente de revisión del usuario. No aceptación integral/Fase 8, activación habitual ni integración externa acreditada. Se reutiliza backend F1–F6. El usuario autorizó adicionalmente dos capacidades públicas aditivas tras contrastar la falta de descubrimiento de incorporación y de proveedor concreto; no autorizó cambios de negocio/persistencia ni pruebas SQL.

## Continuidad visual y navegación

Se utilizó `frontend-design` con confirmación expresa del identificador correcto. No es un rediseño: Source Sans 3 y escalas/espaciados/radios existentes; fondo `#f5f8fa`, superficie `#ffffff`, texto `#18333b`, secundario `#526871`, primario `#146b68`, bordes `#d5e0e5`. Alineación izquierda, contenido legible y composición **listado → detalle → revisión explícita**, no dashboard/KPI, nuevos colores o tipografías. Se descartaron tarjetas equivalentes y otro sidebar. Se corrigió únicamente el ajuste de palabras dentro de Pagos tras detectar un título desbordado a320px/texto200%; no se ocultó contenido.

```text
MainLayout/sidebar existente
  Recordatorios
  Pagos → Obligaciones / Historial → Detalle → Revisión / acción explícita
  Organización (solo los roles que ya la tenían)
```

Se conservan posiciones relativas de las entradas anteriores. `navigationForRole` traza CU-31–33 solo para Paciente y CU-34 solo para Administrador; no CU visibles ni nuevos permisos de Recepción/Médico. Títulos/breadcrumbs y `destinoSeguro` incluyen exclusivamente rutas locales verificadas.

Reutilizados: `MainLayout`, `ProtectedRoute`, `RoleRoute`, `useAuth`, Axios/CSRF/épocas; `PageHeading`, `FormField`, `SelectField`, `Button`, `ActionGroup`, `Alert`, `CatalogResults`, `Pagination`, `ConfirmationPanel`; selector `PatientsPage`; `ReservarCitaPage`/`DisponibilidadTurnos`/`ReservaRegistro`; historial y detalle existentes de agenda. No SDK de navegador, librería visual, dependencia ni store financiero global.

## Contratos concretados y aprobados

- `GET /reservas/disponibilidad`, respuesta `data`: `incorporacion_financiera_habilitada:boolean`, `requiere_monto_bob:boolean`. El segundo solo es true para Administrador/Recepcionista con incorporación true. Son instantáneas; propuesta/confirmación siguen revalidando. No se añaden estos campos al recurso compartido de reprogramación ni se exige monto allí. Sin ruta nueva ni secretos.
- Recursos de obligación: `proveedores_disponibles:('stripe'|'paypal')[]`, derivados de `elegibilidad` existente de cada Service. Vacío para Administrador; revisión agregada anterior se conserva, prefiriendo Stripe si ambos son elegibles. No exige llamadas externas ni configura/habilita proveedores.
- Sin cambios de rutas, FormRequests, permisos, dinero, tablas, Models, Services de negocio, conversión, coordinación o callbacks de F6. Solo dos Resources PHP y una prueba pura adicional. El contrato de retorno real se contrastó: `/pagos/retorno/{proveedor}?intento={UUID-local}`.

## Flujos por rol

**Paciente:** `/pagos` comparte obligaciones/historial, filtros de emisión/creación, proveedor/resultado/indicadores y paginación de servidor. `/pagos/obligaciones/:id` muestra BOB/tasa/USD históricos, exigibilidad/liquidación/vencimiento y verificación; colecciones de intentos/devoluciones paginadas independientemente. Cobros aplicados/no aplicados, referencias e incidencias se conservan; no acciones para resolver incidencias, compensar dobles cobros o reintentar refunds terminales.

Solo el detalle elegible permite elegir los proveedores que el servidor enumera, revisar e iniciar un intento. Se valida acción transitoria Redirigir y HTTPS/host exacto test/Sandbox antes de presentar un enlace; no se navega automáticamente ni se construye URL con parámetros externos. Se admite el fragmento de Checkout corroborado que permite el contrato Stripe; PayPal no admite fragmento. Esto es distinto de retirar parámetros/fragmento del retorno local al portal. Una consulta PayPal200 propia RequiereAccion, sin redirección ni operación pendiente, permite revisar/solicitar captura explícita desde el detalle con precio visible; Laravel revalida aprobación y permiso. Nunca GET/montaje/retorno/effect/webhook del navegador capturan. Solicitar cierre tiene revisión distinta y no cancela la cita. Cerrado/puede_reintentar no habilitan otro proveedor por sí solos: debe reconsultarse la obligación y su elegibilidad.

**Administrador/Recepcionista:** misma reserva administrativa, monto requerido exclusivamente por capacidad de disponibilidad; entrada decimal textual con coma o punto sin miles, máximo dos decimales, validación exacta BigInt/rango Bs7–7000. Se transmite string decimal canónico, sin redondear ni convertir USD en React. Propuesta incluye precio de servidor; confirmar conserva solo propuesta/motivo. Reserva propia omite monto y no fabrica obligación/precio cero. Historial existente admite `situacion_precio`; desde su detalle, permiso `puede_asignar_monto` habilita propuesta → revisión BOB/tasa/USD → confirmación idempotente de la misma propuesta. Una confirmación incierta conserva el token en memoria para recuperarla, sin nueva asignación;409 exige consulta nueva. No segunda gestión/reserva paralela.

**Administrador CU-34:** `/pagos` reutiliza selección explícita de ficha, incluidas inactivas/sin CI/sin cuenta; rutas `/pagos/pacientes/:pacienteId` y su `/obligaciones/:id` mantienen el ámbito en cada GET. Exclusivamente lectura: sin inicio, captura, cierre o resolución. Recepción no accede a estas consultas. Médico no obtiene menú/rutas/campos financieros: agenda médica conserva la proyección anterior y detalle descarta incluso extras financieros inesperados.

No hay bloqueo asistencial por deuda. Reprogramación y cancelación conservan sus reglas backend e historia; no se introduce Vencida como estado de Cita ni estado financiero persistente en el navegador. NoIncorporada y PendienteAsignacion se representan separadas de obligaciones reales.

## Retornos, incertidumbre y ciclo de vida

- Al arrancar el router se retiran query/fragmento de retornos; solo UUID local válido pasa al pathname `/pagos/retorno/{proveedor}/{uuid}`. Los retornos ya canónicos también descartan query/fragmento. `session_id`, `token`, `PayerID` no se usan, transmiten, registran ni persisten. Parámetro local no concede autorización: cada petición depende de Laravel.
- Guardas conservan pathname local para login. El retorno permite **consultar explícitamente**; no acredita pago, no captura y no tiene importes externos. Tras consulta verificada enlaza al detalle local para revisar precio antes de una eventual captura. Sin localizador válido se orienta a las obligaciones propias.
- POST con efectos usan UUIDv4 `Idempotency-Key`; bloqueo síncrono evita doble envío. Una pérdida de respuesta mantiene exactamente tipo/recurso/proveedor/revisión/clave en memoria; la recuperación humana repite esa misma solicitud contra la idempotencia/conciliación backend, nunca genera otra clave o cambia proveedor para despejarla.
- 200/201 no implican éxito financiero por su código; se muestra estado/aplicación verificados.202 mantiene incertidumbre, no tiene acción especulativa y bloquea un nuevo inicio. `Retry-After` numérico/fecha se respeta; espera mínima5s en202/incertidumbre. Consultas exclusivamente humanas, sin polling ni retry automático.503 no acredita ausencia de efectos.
- Secuencias, abort, clave de contexto y épocas descartan respuestas atrasadas. Cambiar sesión/paciente/recurso o pagehide retira datos; volver a la pestaña invalida el enlace transitorio y exige resultado actualizado. Actualización/paginación no desmontan un envío incierto desde el detalle; blocker/beforeunload advierten pérdida de recuperación al abandonar.
- **Sin persistencia nueva:** claves/revisiones solo en memoria, sin localStorage/sessionStorage/AuthContext financiero. Tras recarga se consulta la obligación/intento local con autorización nueva; no se recupera la clave de navegador perdida ni se promete que un GET sin intento demuestre ausencia de efectos. La recuperación durable sigue en backend. No se mantiene URL sensible en el historial del módulo.
- Errores401/403/404/409/419/422/429/503 seguros; CSRF anterior al POST se distingue de escritura posiblemente enviada, sin reenviar automáticamente. No HTML del proveedor, payloads completos, credenciales, comprador ni datos clínicos en logs.

## Archivos

- Nueva feature `frontend/src/features/pagos/`: `services/pago.service.js`; hooks `usePagoQuery`/`useOperacionPago`; páginas `PagosPage`/`RetornoPagoPage`; componentes `PrecioPago`, `HistorialPagos`, `OperacionPago`, `AsignarMonto`, `FinanzasCita`; utilidades `pagoFormat`/`pagoError`; estilos `pagos.css`; pruebas en `tests/{utils,services,hooks,components,pages}`.
- Integración: `src/app/router.jsx`, `src/constants/{navigation.js,navigation.test.js,roles.js}`; `agenda/{services/agenda.service.js,pages/CitaDetallePage.jsx,components/{HistorialCitas,HistorialFilters,CitasTable}.jsx}`; `citas/{services/reserva.service.js,hooks/useReservaRegistro.js,components/{DisponibilidadTurnos,ReservaRegistro}.jsx}`.
- Prueba previa `citas/tests/pages/AsistenciaCitaPage.test.jsx`: espera la región Paciente antes de buscar el enlace en el main vigente; no conserva el main transitorio de SessionGate.
- PHP: `backend/src/presentation/dtos/{citas/DisponibilidadResource.php,pagos/ObligacionPagoResource.php}`, `backend/tests/Feature/PagosCapacidadesPresentacionTest.php`.
- Docs: este documento, frontend README, análisis/contexto/relevo y concreción aprobada en F1. No RF/RN, Word/PDF/diagramas académicos, AGENTS, dependencias, configuración privada ni documentos funcionales modificados por esta fase.

## Verificaciones ejecutadas

1. Batería dirigida: `pnpm test src/features/pagos src/features/citas src/features/agenda src/constants/navigation.test.js src/layouts/ModuleNavigation.test.jsx src/features/auth/tests/pages/AdministrativeNavigation.test.jsx --maxWorkers=4`: **1839 correctas/39 archivos**,99,50s/código0. Sin suite global. Primera ejecución1832/1839 detectó espera antigua de main, act no esperado y fixture PHP incorrecta; corregidas sin relajar producto. Pase focal49 correcto. No sumar ejecuciones.
2. Ampliación posterior de selección/retorno/snapshot y títulos: **97 correctas/2 archivos** (Pagos.test/navigation); proyección médica/filtro: **89 correctas/2 archivos** (servicios Pagos/agenda); última comprobación de URLs/servicios: **60 correctas/2 archivos** (conserva fragmento Stripe corroborado/rechaza fragmento PayPal). Con solapamientos respecto a la batería anterior, **no son pruebas únicas adicionales** ni otra global. La separación impide atribuir a la cifra1839 las últimas aserciones añadidas.
3. PHP puro, sin arrancar Laravel/DB/red: `php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature/PagosCapacidadesPresentacionTest.php`: **4 pruebas/41 aserciones/código0**,0,042s/30MB. Recursos con matriz flags/roles/proveedores, revisión conservada y reprogramación sin requisito nuevo. Fixtures tipadas y expectativas explícitas corregidas; dos pases previos erróneos y notices no se acreditan como finales. No ejecución de suites backend ni PostgreSQL.
4. Build Vite correcto; script lint no existe. Se conserva warning de chunk JS>500kB, sin splitting/refactor global ni desactivar avisos. Node24.18.0/pnpm11.17.0, React19.3.1, Vite8.3.4 y Vitest4.1.12 observados en entorno/lock. Sin instalar ni actualizar paquetes.
5. **Chrome real con HTTP completamente simulado:**88 layouts (1440/768/390/320px, texto100/200%),22 auditorías axe sin violaciones automáticas, cero excepciones/blockers simultáneos/web storage. Once estados/pantallas: lista, detalle, revisión/acción Stripe, retorno, revisión captura PayPal, apagado,202, cancelación/devolución, Admin lectura, Recepción denegada. Captura PayPal mediante Enter y capturas escritorio/móvil examinadas. Cinco POST simulados; todos los destinos de API/proveedor no simulados bloqueados. No se siguió un enlace de cobro externo. Un desbordamiento real corregido y ajuste del arnés de teclado (text Enter) precedieron a la ejecución final; no contar pases incompletos.
6. `git -c core.autocrlf=false diff --check` de código afectado correcto; sin cambios Git de configuración. Logs/JSON/capturas/perfil descartable solo en `C:/Users/jesus/AppData/Local/Temp/opencode/pagos-fase7-*`. Los logs de PowerShell pueden contener anotación NativeCommandError por stderr informativo de pnpm; no sustituye el código nativo ni el resumen de pruebas/build.

## Límites y relevo F8

Se preservan cambios locales previos y archivos nuevos de F1–F6/otros trabajos; no commit/push/ramas/despliegue. No configuración privada modificada: incorporación/Stripe/PayPal conservan **default false**; no se arrancó Laravel para afirmar una nueva verificación efectiva de flags del entorno habitual. Siete avisos Symfony previos no reevaluados/corregidos, auditoría intacta. Incidencias/doble cobro/refund terminal o sin ID mantienen revisión pendiente, sin nuevas acciones de resolución.

Pendiente de autorización independiente F8: integración React↔Laravel con sesión/origen/CSRF/PostgreSQL aislado reales, migraciones solo en entorno autorizado, cuentas test/Sandbox y USD extremos, retornos HTTPS y cookies entre sitios, webhooks/conciliación/refunds/carreras de extremo a extremo y guion académico integral/regresión ampliada. Las simulaciones no acreditan Stripe/PayPal/OAuth/postback externos ni un módulo operativo. **Detenerse aquí; no activar ni continuar automáticamente.**
