# Pagos — Fase 8: integración y verificación local (2026-11-06)

## 1. Resultado y alcance

**Aceptación local demostrada con proveedores simulados; aceptación externa pendiente.** React/Axios → HTTPS loopback → Laravel/Sanctum → PostgreSQL reales. Stripe/PayPal/OAuth/postback simulados; no activación habitual, producción, dinero real, operaciones externas autenticadas ni credenciales privadas. Las notas F1–F7 conservan cronología: ni los mocks ni esta entrega acreditan Stripe test/PayPal Sandbox externos.

No se detectaron defectos de producto que exigieran cambiar reglas, contratos, persistencia o interfaces. Único código ampliado: cuatro casos parametrizados en `backend/tests/Feature/PagosStripeTest.php`, para ambos proveedores: CSRF/origen sin efectos y throttle/Retry-After/replay estable sin creación duplicada. Sin clases productivas, rutas, Resources, FormRequests, CSS, dependencias, SDKs ni migraciones nuevas. No modificación de interfaces ni necesidad de skill de diseño.

## 2. Aislamiento y conservación

- Git inspeccionado antes de actuar: **156 archivos previos modificados/nuevos** registrados por ruta/SHA-256 fuera del repositorio. Comparación final permite únicamente el test y los cinco documentos anteriores ampliados/relevados por F8; este documento es nuevo. RF/RN oficiales, Word/PDF, diagramas académicos, AGENTS y locks quedan como se recibieron, incluidos sus cambios previos ajenos. Sin descartes/commit/push/ramas/despliegue.
- Antes de escribir SQL: `APP_ENV=testing`, pgsql loopback, PostgreSQL18.6, base/rol/propietario `clinica_vida_salud_test`; sin superusuario/CREATEDB/CREATEROLE/replicación/BYPASSRLS/membresías, DB_URL alternativa o configuración cacheada. No bases/roles creados. Inventario inicial completo: `public` sin tablas y `prueba_auth_sx1lpauqw780c1tw` con sus doce tablas, sin leer su contenido.
- Dos generaciones propias, `prueba_pagos_f8_<16 hex>` aleatorios/manifiestos; migraciones existentes y `search_path` propio en cada conexión. Cuentas, fichas, médicos, citas y obligaciones iniciales son **fixtures ficticias**, no altas/reservas acreditadas por navegador. Reserva mediante API real se acredita en PHPUnit. Sesiones database con commits reales entre peticiones; caché/logs propios externos.
- PHP/Vite internos loopback8018/5218; proxies HTTPS5448/5449. Certificado localhost descartable, excepción Chrome limitada a su SPKI; sin instalar certificados ni cambiar confianza del sistema. `APP_ENV` sigue testing: PHP `cli-server` ejecuta CSRF real, comprobado con419; distintos puertos verifican CORS con credenciales/token.
- `StripeSimulado` y `PayPalHttpSimulado` reutilizados, incluido OAuth/postback; estado ficticio compartido por archivo/lock, control solo CLI fuera del repo, sin endpoints públicos de control. Laravel HTTP bloquea solicitudes no simuladas y Stripe real rechaza transporte en testing. Chrome solo permite los dos orígenes loopback; no se siguió Checkout/Sandbox real. Esto no acredita red distribuida/proveedores remotos.
- True únicamente en memoria del arnés; también se verificó apagado temporal. `.env` habitual/testing y defaults intactos. Lectura final habitual **local**, sin SQL ni instanciar gateways: incorporación=false, Stripe=false, PayPal=false.
- Ambas generaciones retiradas eliminando solo su esquema explícito: inventarios finales completos idénticos a sus iniciales; `public` vacío y esquema ajeno conservado. Procesos propios detenidos; perfiles Chrome/cookies, claves TLS y estado ficticio retirados. Se retienen evidencias seguras/manifiestos/scripts externos, no secretos reales.

## 3. Matriz de aceptación

**Verificado local** = backend/SQL reales y proveedor simulado cuando interviene. PHP = batería dirigida final; Chrome = navegador conectado. No todos los casos PHP se recorrieron visualmente. Ningún caso externo se acredita por su equivalente simulado.

| Criterio | Estado | Evidencia y límite |
| --- | --- | --- |
| Paciente: obligaciones/intentos/pagos propios, rechazo de ajenos | Verificado local | Chrome dos cuentas/404; PHP roles/recursos/consultas. |
| Admin: seleccionar ficha y consulta exclusivamente de lectura | Verificado local | Selector/detalle Chrome sin operaciones; POST403, selección obligatoria422; PHP ámbito/paginación. |
| Recepción: monto/asignación, sin historial general | Verificado local | Guard/API403 Chrome; PHP reserva/asignación para ambos roles administrativos. |
| Médico: sin proyecciones/permisos financieros nuevos | Verificado local | Guard/API403/detalle permitido sin finanzas; PHP proyección y permisos. |
| Sesión, cookies, CORS/origen, CSRF y revocación | Verificado local | Login UI/recarga, cookie Secure/HttpOnly/SameSite Lax, logout/expiración SQL,401/419/origen hostil403. PHP token incorrecto/origen para ambos proveedores. |
| 200/201/202 y 401/403/404/409/419/422/429/503 | Verificado local | Todos observados HTTP real Chrome; también204.202/429 con Retry-After, no-store y errores seguros. |
| Reserva administrativa: monto obligatorio/revisión/obligación atómica | Verificado local | PHP F3 y reserva/gestión existentes; no reserva CU-14 atribuida a Chrome F8. |
| Reserva propia sin precio/deuda ficticia; legado excluido | Verificado local | PHP creación/corte/replay; Chrome lista sin obligaciones de fixtures sin precio/legado. |
| Asignación única/posterior/tardía y límites/formato | Verificado local | PHP estados/tipos/rangos/decimales; Chrome revisión,201, replay200 y precio ya asignado409. |
| BOB/tasa/USD históricos exactos y mismo USD por proveedor | Verificado local | PHP aritmética exhaustiva/históricos/payloads; Chrome Bs7,04→USD1,01/extremos mostrados. Restricciones de cuentas reales pendientes. |
| Replay, propuestas obsoletas y asignación/cancelación concurrentes | Verificado local | PHP seis carreras financieras, commits/rollback y locks observables; no concurrencia inferida de doble clic. |
| Stripe: inicio/acción corroborada/retorno sin falso éxito | Verificado local | Chrome liquida solo tras consultar evidencia ficticia pagada; PHP gateway/firma/URL. No Checkout real. |
| PayPal: aprobación no cobra/captura humana explícita | Verificado local | Chrome retorna/consulta sin captura, revisión y Enter; una captura simulada. No comprador Sandbox real. |
| Doble clic y respuesta perdida con misma clave/cuerpo | Verificado local | Chrome dos clicks síncronos/un POST; corte de respuesta POST tras commit y recuperación con hashes iguales de clave/cuerpo, sin exponerlos completos; PHP replay/exclusión. |
| Recarga/sesión vencida/202/respuestas atrasadas/sin polling | Verificado local | Chrome sesión SQL y202/espera sin nuevos POST; PHP recuperación y Vitest épocas/abort/esperas/respuestas viejas. No se promete recuperar una clave RAM perdida al recargar. |
| Cierre pendiente, Stripe↔PayPal, captura en vuelo/incierta | Verificado local | Chrome cierre Stripe→PayPal; PHP ambos sentidos/cierre insuficiente/handoff/leases/exclusión. No expiración Orders inventada. |
| Webhooks verificados/falsos/duplicados/desordenados | Verificado local | PHP bytes/firma SDK-HMAC Stripe locales, postback PayPal simulado, inbox/consulta autoritativa simulada. No recepción externa HTTPS. |
| Conciliación/recuperación sin claves nuevas por incertidumbre | Verificado local | PHP lease/takeover/ventanas/contexto/misma clave; CLI de conciliación Chrome con SQL real/proveedores ficticios. |
| Pagar no confirma llegada/atención | Verificado local | Chrome Stripe liquidado/Cita Programada; PHP evidencia sin transición clínica. |
| Impago no bloquea llegada ni atención | Verificado local | Chrome: Recepción confirma llegada/Médico responsable marca Atendida; deuda sigue exigible/vencida/impagada, sin intentos/USD histórico. Hechos presenciales declarados son ficticios. |
| Reprogramación conserva obligación, precio y pago | Verificado local | PHP gestión/reserva financiera pagada/impagada: mismos IDs/importes/pago y vencimiento según contrato. |
| Cancelación retira exigibilidad/conserva historia | Verificado local | Chrome cancelación PayPal/refund ficticio verificado; PHP auditoría/rollback/cierre/historia. |
| Cobro tardío/refund aplicado/pendiente/fallida/incierta/respuesta perdida | Verificado local | PHP cancelación durante red/cobro tardío/recuperación; sin reintento terminal ni éxito forzado. |
| Doble cobro conservado, uno aplicado e incidencia adicional | Verificado local | PHP ambas evidencias/aplicación única/refund solo aplicado. Incidencia sigue pendiente. |
| Funcionamiento con flags apagados | Verificado local | Chrome sin proveedor/503; PHP legado/reserva/conciliación sin tablas/red. Defaults/habitualfalse. |
| Concurrencia real PostgreSQL | Verificado local | Catorce carreras existentes reejecutadas: seis financieras, Stripe/PayPal y seis F6; procesos/PID distintos/barreras/pg_blocking_pids o lease avanzado. No servidor HTTP multiproceso ni red distribuida acreditados. |
| Responsive/accesibilidad/navegación | Verificado local |56 layouts/14 axe sin violaciones automáticas/Enter/guards/capturas revisadas. No certificación completa ni dispositivos físicos. |
| Stripe test/PayPal Sandbox externos/cuentas/USD/HTTPS/webhooks reales | Pendiente | Sin autorización específica ni credenciales. No aceptación externa. |
| Resolución incidencias/refunds terminales/sin referencia/reversals | Pendiente | Sin nuevas acciones/compensaciones adicionales/éxitos manuales. |
| Siete avisos previos en cuatro paquetes Symfony | Pendiente | No reevaluados/corregidos; auditoría intacta, sin actualizaciones ajenas. |

No quedan criterios locales fallidos en la selección ejecutada; no equivale a cobertura exhaustiva de toda la aplicación.

## 4. Verificaciones finales y evidencia

- **Backend351 pruebas/3988 aserciones/código nativo0**,135,567s/150MB; PHP8.5.11/PHPUnit12.5.40. Única ejecución dirigida final, sin global: selección F6 + cuatro casos nuevos + `PagosCapacidadesPresentacionTest`. Su ejecución pura4/41 F7 es histórica, no otra ejecución adicional nueva.
- Filtro: `ImportePagoTest|PagosPersistenciaTest|ReservaIntegracionTest|GestionCitaIntegracionTest|PagosFaseTresTest|HistorialCitasTest|ConsultaCitaTest|AgendaPermisosAdministrativosTest|PagosStripeTest|PagosPayPalTest|PagosCapacidadesPresentacionTest|GestionCitaConcurrenteTest::test_financial_assignment_serializes`. PHPUnit directo512MiB, sin composer test/config:clear ni comandos destructivos.
- **Frontend1905 pruebas/43 archivos/código nativo0**,100,26s. `pnpm test src/features/pagos src/features/citas src/features/agenda src/constants/navigation.test.js src/layouts/ModuleNavigation.test.jsx src/features/auth/tests/pages/AdministrativeNavigation.test.jsx src/features/auth/tests/context/AuthContext.test.jsx src/features/auth/tests/pages/AuthIntegration.test.jsx src/features/auth/tests/pages/LoginPage.test.jsx src/features/auth/tests/services/auth.service.test.js --maxWorkers=4`. Vitest usa mocks, no SQL.
- **Build0**, Vite8.3.4, JS988,01kB/gzip262,27kB; warning chunk>500kB conservado. Sin lint instalado. Node24.18.0/pnpm11.17.0; locks Laravel13.37.0/Sanctum4.3.3/Stripe22.1.0 intactos.
- **Chrome final14+5+1 escenarios/56 layouts/14 axe**, cero excepciones, tres recorridos completos/código0.312 solicitudes API incluyen preflights/lecturas, no312 operaciones financieras ni pruebas únicas.1440/768/390/320px/texto100/200%, siete estados inspeccionados/capturas desktop y móvil revisadas. Códigos200/201/202/204/401/403/404/409/419/422/429/503 reales.
- Sintaxis PHP/Pint dirigido correctos. Primer control de estilo detectó import plenamente cualificado; corregido antes de la única batería final. Sin alta/movimiento de clases que exija regenerar autoload.
- Preparación `pagos-fase8-inicial`: se cortó OPTIONS en vez de POST y RateLimiter había resuelto caché array antes de elegir file. Corregido **solo el arnés** (filtrar POST/elegir file antes de boot); esquema preparatorio retirado/inventario idéntico. Recorridos finales repetidos sobre nueva generación propia. No sumar preparatorios/incompletos ni F6/F7.
- Evidencia final: `C:/Users/jesus/AppData/Local/Temp/opencode/pagos-fase8/`: `backend-command.json`, `backend-final-{stdout,stderr,exit}.log`, `frontend-final-*`, `build-*`, `pint-*`, `browser-evidence{,-complement,-clinica}.json`, logs/códigos de cada recorrido, PNG, `sql-summary-final.json`, `inventory-{before,after}.json`, `cleaned.json`, `flags-habituales.json`, `git-before.json`, `evidence-summary.json`. Scripts descartables hermanos `pagos-fase8-{boot,control,http,cert,flags,verificar}.php` y `pagos-fase8-{browser,evidencia}.cjs`. Preparación aparte, no evidencia final sustitutiva; no endpoints productivos/secretos reales.

## 5. Guion académico local reproducible

No usar base habitual. Los arneses externos son descartables, no un comando operativo versionado.

1. Inspeccionar Git/hashes, instrucciones/relevo/versiones/locks. Preflight de lectura e inventario completo; discrepancia detiene. No crear bases/roles sin autorización.
2. Esquema propio/migraciones existentes/fixtures ficticias. Arrancar solo procesos loopback propios con testing, sesión SQL/origen/cookies/CSRF reales; instalar fakes/bloqueo de red antes de flags internos.
3. **Precio:** personal revisa monto textual; PHP propuesta/confirmación reales y reserva propia sin deuda; Chrome distingue legado/sin precio. Contrastar Bs7,04→USD1,01/límites Bs7–7000 y pruebas PHP de asignación tardía/cancelación concurrente.
4. **Stripe simulado:** revisar BOB/tasa/USD e iniciar; doble clic no duplica. Retorno limpia parámetros/no liquida. CLI cambia solo proveedor ficticio a pagado; consulta humana aplica evidencia. Cita conserva Programada. Cierre sin cobro verificado permite cambio seguro.
5. **PayPal simulado:** crear orden independiente; aprobar no cobra. Retorno/consulta no capturan; detalle/revisión/captura humana por Enter. Corroborar un cobro aplicado y USD histórico. Sin SDK/captura del navegador del proveedor.
6. **Incertidumbre:** respuesta ficticia perdida→202/Retry-After/exclusión. Cortar HTTP POST tras commit y recuperar misma clave/cuerpo; no cambiar UUID/proveedor. Recarga/sesión vencida exigen consulta autorizada sin inventar ausencia de efectos.
7. **Clínica/refund:** deuda vencida permite llegada de Recepción y atención del Médico responsable sin pagar. Cancelar otra cita pagada retira exigibilidad/prepara devolución; conciliación verifica refund ficticio. PHP cubre tardío/terminales/doble cobro con incidencia pendiente.
8. Verificar roles/ajenos/CSRF/origen/429/503/apagado, dirigida/build/navegador/axe/capturas. Guardar evidencia segura, detener procesos propios y retirar solo esquema/runtime propios; comparar inventarios y flags habituales sin SQL.

## 6. Guion externo preparado, NO ejecutado

**Antes de cualquier paso autenticado solicitar autorización específica:** cuenta/app/merchant concretos, credenciales por canal privado, operaciones permitidas/límites, retorno HTTPS exacto, receptor público HTTPS de webhooks y limpieza permitida. No secretos en conversación/Git/capturas/logs. Sin activación habitual ni reutilización de fixtures como cuentas reales.

### Stripe Checkout test — todos los pasos externos pendientes

1. Cuenta exclusivamente test; comprobar `acct_…`, clave test, API fijada y webhook/secreto mediante configuración privada autorizada. Prefijo sk_test_ no prueba identidad. Acordar HTTPS/CORS/receptor o reenvío local autorizado de eventos.
2. Revalidar aislamiento; obligaciones ficticias Bs7/Bs7,04/Bs7000→USD1/1,01/1000. Verificar restricciones reales de cuenta; incompatibilidad requiere consulta, nunca inflar importe.
3. Paciente revisa/inicia; backend corrobora Checkout test. Solo medios de prueba oficiales. Éxito/rechazo/abandono; retorno no acredita pago. Verificar Session/PaymentIntent/Charge, bruto USD/contexto/aplicación única; Cita inalterada.
4. Recibir evento HTTPS firmado, acuse durable204, duplicado/desorden/conciliación; solo referencias mínimas. Cerrar impagada/corroborar expiración antes de cambiar proveedor. Cancelar pagada y verificar GET refund total test al medio original; POST/HTTP200 no bastan.
5. Documentar resultados/códigos/referencias mínimas, sin secretos. Incidencias sin resolución manual. Retirar recursos externos solo si se autorizó expresamente/restaurar flags aislados.

### PayPal Sandbox — todos los pasos externos pendientes

1. App Sandbox/client_id/secreto privados, APP_ID esperado de OAuth, merchant vendedor, comprador Sandbox separado/Webhook ID. Acordar retorno/receptor HTTPS y eventos Orders/Capture/Refund; verificar token/identidad/contexto, nunca endpoints live.
2. Verificar USD1/1,01/1000 y capacidades/restricciones efectivas con las mismas obligaciones históricas. Incompatibilidad requiere consulta. OAuth solo backend, sin persistencia/logs.
3. Crear orden CAPTURE/una unidad/sin envío; aprobar Sandbox/regresar no paga. Revisar importe en portal y capturar explícitamente. Corroborar Order/Capture COMPLETED, destinatario/bruto/vínculos/aplicación única; no captura por retorno/GET/webhook APPROVED.
4. Verificar entrega HTTPS/postback oficial SUCCESS/acuse durable200/duplicados/desorden/conciliación. Cierre local sin expiración Orders inventada; captura incierta conserva exclusión. Cancelar pagada y verificar GET Refund COMPLETED total del aplicado/medio original.
5. Refund perdido sin ID queda Incierta hasta referencia/evento verificable; no clave nueva ni ventana Orders supuesta. Documentar límites/restaurar entorno conforme a autorización.

## 7. Relevo

Aceptación externa/capacidades reales de cuentas/entrega de eventos, dispositivos físicos y producción no acreditados. Incidencias/reversals/ventanas agotadas/refunds terminales o sin referencia siguen pendientes, sin compensaciones adicionales/éxitos manuales. Siete avisos Symfony y warning build conservados. **F8 local terminada para revisión; detenerse aquí.** Recomendada sesión nueva y autorización independiente antes de cualquier recorrido externo.
