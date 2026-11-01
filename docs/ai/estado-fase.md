# Estado de fase — relevo para sesión nueva

> Documento **de una página**. Se reescribe al cerrar cada fase. No sustituye a `contexto-maestro.md` ni a `analisis-clases.md`; sirve para que una sesión nueva arranque sin releer documentos extensos.

- **Actualizado:** 2026-10-31
- **Estado actual:** **primera versión funcional validada en local**; cierre y límites en [`validacion-v1.md`](validacion-v1.md). CU-01–CU-30 con implementación localizada y regresión ejecutada; CU-29 y avisos internos CU-28 ya comprobados por React→HTTP Laravel/Sanctum→PostgreSQL aislado. No certificación de producción, entrega Web Push/Brevo ni cierre documental académico completo.
- **Rama/entorno:** trabajo local, sin commit ni despliegue. PostgreSQL local en ejecución; base habitual **no** migrada.

## Cierre de validación v1 (2026-10-31) — relevo vigente

- **Regresión frontend completa:** `pnpm test --maxWorkers=4` → **3844/3844, 136 archivos**; build correcto con advertencia conocida de chunk 936,45 kB. Primer intento interrumpido por timeout; solo se acredita el posterior completo. Composer válido y requisitos PHP/extensiones correctos.
- **Regresión backend completa:** **4522 pruebas/64296 aserciones, dos fallos de inventario** en `MatrizPermisosBackendTest` por siete rutas de CU-29 ausentes del dataset. Única corrección de código: ese dataset de prueba, sin lógica productiva. Repetición dirigida de la clase completa → **751/751, 3390 aserciones** (incluye nuevos casos). No se repitió la global ni se presenta como global verde posterior a la corrección.
- **HTTP real:** **53 comprobaciones Chrome** con cuatro roles, cookies/CSRF/sesiones reales, siete reportes y totales SQL, avisos propios/admin, lectura de dispositivos con canal apagado y diez pruebas del examen. Operaciones administrativas del examen por API HTTP desde navegador, no todas mediante formularios React; ver alcance exacto en `validacion-v1.md`. Scripts externos propios, esquema efímero y correos/transporte push bloqueados; public/esquema ajeno conservados, fixture/credenciales/servidores propios retirados. La verificación nueva supera los pendientes históricos de integración de lectura CU-28/CU-29; no acredita alta/entrega Web Push real ni todos los flujos clínicos por navegador independiente.
- **Archivos/documentación:** prueba de matriz backend, `validacion-v1.md`, corrección indispensable de estados obsoletos CU-22–CU-29 en `casos-de-us.md` §3.1 y este relevo. Sin código productivo, análisis/diagramas, `.env`, dependencias, migraciones habituales, commit/push/despliegue. Evidencias `Temp/opencode/cierre-v1-*` detalladas en el informe; historial y cambios locales previos conservados.
- **Próximo paso, solo autorizado y preferentemente en sesión nueva:** preparar entorno de uso/migraciones habituales, comprobar entrega Brevo/operación scheduler y, si se incluye la ampliación, Web Push/dispositivos reales. Documentación final de análisis/diagramas sigue siendo tarea explícita. No iniciar nuevas funcionalidades ni declarar producción lista por esta validación local.

## Plan frontend CU-29 — RFE-1 a RFE-5

**Lectura de arranque:** esta sección y las partes necesarias de [`cu29-contratos-api.md`](cu29-contratos-api.md), especialmente §§2–6. No releer el historial inferior salvo un bloqueo concreto. Una fase por sesión y autorización; no iniciar automáticamente la siguiente. Trabajar en `frontend/` con React/Vite/JavaScript, Axios y pnpm 11.17.0/Vitest existentes. Base RFE-1 implementada en `features/reportes/`; Consultar reportes operativo en `/reportes`, exclusivo de Administrador.

### RFE-1 — Base funcional y citas Programadas para una fecha

- Crear `src/features/reportes/` con servicio y pantalla `/reportes`, ruta protegida y navegación exclusiva de Administrador; reutilizar estructura visual, tablas/paginación y manejo de sesión existentes.
- Entregar el primer reporte funcional: `GET /reportes/citas/programadas`, fecha obligatoria y filtros opcionales de Médico/especialidad. Reutilizar selectores/servicios existentes cuando encajen, incluyendo entidades inactivas; no usar catálogos de reserva restringidos a activos.
- Mostrar solo columnas administrativas del contrato, fecha/hora de sede, total del servidor, orden permitido y paginación. Carga, vacío, error y actualización explícita; al aplicar filtros volver a página 1 y evitar resultados viejos atribuidos a filtros nuevos.
- Pruebas dirigidas: servicio, filtros, paginación, Programada distinta de Confirmada, acceso denegado de otros roles y descarte de respuestas al salir/cambiar sesión. No pedir ni implementar todavía los restantes seis reportes.

### RFE-2 — Citas por Médico, especialidad y estado

- Ampliar la misma pantalla para los tres reportes, sin páginas duplicadas por tipo. Médico obligatorio en por-medico; especialidad en por-especialidad; estado en por-estado. Ofrecer exactamente los cinco estados del contrato.
- Admitir fecha única o rango desde/hasta, excluyentes, hasta 366 días inclusivos; enviar únicamente filtros aplicables al recurso, con IDs textuales, órdenes y paginación cerrados.
- Reutilizar controles sencillos de RFE-1; no crear un motor genérico de reportes. Cambiar tipo limpia resultados/filtros incompatibles e ignora respuestas atrasadas.
- Pruebas: tres contratos, selecciones obligatorias/inactivas, combinación de filtros, fechas/rangos inválidos, cambio de tipo y aislamiento de respuestas. Regresión de RFE-1 solo donde se modifique código compartido.

### RFE-3 — Pacientes registrados

- Integrar `GET /reportes/pacientes/registrados` en la pantalla compartida: fecha/rango, filtro opcional activo (0/1), órdenes de registro/nombre y paginación.
- Mostrar únicamente ID/código, nombres/apellidos, actividad y creación de ficha permitidos por el contrato. No mostrar CI, contactos, cuentas ni datos clínicos; explicar que el período corresponde al registro de la ficha, no al alta web.
- Usar total del servidor y conservar casos sin cuenta/CI e inactivos según filtros; no obtener listados administrativos más amplios para reconstruir este reporte.
- Pruebas: contrato/listado, actividad, orden, vacíos/páginas fuera del final, errores y ausencia de datos privados.

### RFE-4 — Cantidades por período y agenda diaria

- Integrar `GET /reportes/citas/cantidades`: fecha/rango y Médico/especialidad opcionales; mostrar Atendidas, Canceladas, Inasistencias y total del backend, incluidos ceros. Sin paginación, filtro estado ni sumatorios de páginas en React.
- Explicar que las cantidades corresponden al inicio programado vigente y estado actual, no a fechas reales de transición ni a un corte histórico inmutable.
- Integrar `GET /reportes/agenda-diaria` con Médico/fecha obligatorios, estado opcional, orden fijo y paginación. Reutilizar presentación de agenda si encaja, pero consultar la ruta administrativa de reportes; sin copiar sus acciones operativas.
- Diferenciar agenda por intersección del día frente a reportes por inicio de cita; no sumar agendas para calcular cantidades. Preservar ubicación/duración devueltas de Cita.
- Pruebas: agregado sin paginación/ceros, filtros aplicables, agenda diaria, fechas de sede y retiro de datos al cambiar reporte/sesión. Con esto quedan los siete mínimos RF13 representados.

### RFE-5 — Integración y cierre frontend proporcional

- Verificar recorridos de los siete tipos con Router/Auth/Axios existentes, errores 401/403/404/419/422/429/red/500 pertinentes, cambios de filtro/tipo/sesión y ausencia de datos obsoletos. No duplicar todas las combinaciones ya verificadas en fases anteriores.
- Revisar responsive, teclado/foco, etiquetas, anuncios de carga/error/vacío y fechas America/La_Paz, conservando identidad visual. Ejecutar pruebas del módulo e integraciones realmente modificadas y `pnpm build`; no suite global rutinaria.
- Distinguir API simulada de integración HTTP real React→Laravel/Sanctum/PostgreSQL. Si no existe entorno aislado preparado, registrar ese pendiente; no migrar base habitual, cambiar .env ni declarar integración real por mocks.
- Cierre local no acredita producción. CSV/PDF, gráficas, nuevas dependencias, dashboards, caché persistente y cambios al backend quedan fuera salvo autorización independiente. Análisis/diagramas siguen aplazados para tarea final explícita, no se actualizan en cada fase.

### Estado y relevo entre sesiones

| Fase | Estado |
| --- | --- |
| RFE-1 | Cerrada localmente; 139/139 pruebas dirigidas y build correcto, API simulada |
| RFE-2 | Cerrada localmente; 99/99 pruebas del módulo y build correcto, API simulada |
| RFE-3 | Cerrada localmente; 158/158 pruebas del módulo y build correcto, API simulada |
| RFE-4 | Cerrada localmente; 232/232 pruebas del módulo y build correcto, API simulada |
| RFE-5 | Cerrada proporcionalmente en local; evidencia dirigida original conservada y posterior HTTP real acreditado en el cierre v1 superior |

- Reutilizar seis campos de pacientes y proyección administrativa de citas del contrato; totales calculados por servidor, IDs bigint como texto. Sin escrituras ni persistencia local de datos personales.
- Backend bajo `/api/reportes/`; respetar baseURL de Axios sin duplicar `/api`. Selección histórica de Médico: `/api/agenda/medicos`; especialidades: `/api/especialidades` sin filtrar activa. Confirmar firmas de los servicios existentes solo al utilizarlos.
- Pruebas en `src/features/reportes/tests/`, subcarpetas únicamente con archivos. Una batería dirigida por cambio; salida extensa en Temp/opencode y resumen en chat. Repetir solo por fallos/riesgos concretos; no reejecutar backend cerrado sin necesidad.
- Al cerrar cada RFE actualizar esta tabla y un resumen corto de archivos, comando/resultado, límites y próximo paso. Otra documentación solo indispensable; sin análisis de clases/diagramas por fase, sin nuevos archivos documentales para este plan.
- Conservar cambios locales ajenos. Sin dependencias, migraciones habituales, envíos externos, commit/push/despliegue ni reapertura automática de CU-28.
- **Siguiente tarea:** la integración HTTP aislada de los siete reportes ya se verificó en el cierre v1 superior. No migrar base habitual ni cambiar `.env` unilateralmente. RFE-1–RFE-5 no dejan otra fase automática; análisis/diagramas y preparación del entorno de entrega requieren autorización. Recomendada sesión nueva y lectura de `validacion-v1.md`.

### Resultado RFE-1 (2026-10-31)

- **Archivos:** `frontend/src/features/reportes/{pages/ReportesPage.jsx,services/reporte.service.js,components/FiltroHistorico.jsx,components/CitasProgramadasTable.jsx}` y pruebas en `tests/{pages,services,utils}/`; integración mínima en `src/app/router.jsx`, `src/constants/{navigation.js,navigation.test.js,roles.js}`. Reutiliza Auth/Axios, `useAgendaQuery`, componentes/estilos, fechas de sede y catálogos históricos existentes. Fecha obligatoria, Médico/especialidad opcionales con inactivos, orden cerrado, total del servidor, tamaño/paginación, consulta/actualización explícitas, vacío/error/carga y retiro de respuestas antiguas al cambiar filtros, salir o cambiar sesión. Programada no incluye Confirmada; sin acciones operativas ni otros reportes.
- **Verificaciones:** `pnpm test src/features/reportes src/constants/navigation.test.js src/layouts/ModuleNavigation.test.jsx src/layouts/Navigation.test.jsx` → **139/139, seis archivos**. `pnpm build` correcto; advertencia de chunk >500 kB (923,20 kB). `git diff --check` correcto (avisos LF/CRLF). Evidencia: `C:/Users/jesus/AppData/Local/Temp/opencode/reportes-rfe1-{tests,build}.log`.
- **Límites/siguiente paso:** servicios/Axios/Router/Auth reales con adapter API simulado y pruebas DOM; no integración HTTP React→Laravel/PostgreSQL ni aceptación visual en navegador/dispositivo real. Sin backend, migraciones, dependencias, análisis/diagramas, commit/push/despliegue; cambios locales previos conservados. RFE-2 **no iniciada**, requiere autorización, preferentemente en sesión nueva. No declarar CU-29 completo.

### Resultado RFE-2 (2026-10-31)

- **Archivos:** ampliados `frontend/src/features/reportes/pages/ReportesPage.jsx`, `services/reporte.service.js` y `components/FiltroHistorico.jsx`; tabla compartida renombrada a `components/CitasReporteTable.jsx`; nuevo `utils/periodoReporte.js`. Pruebas/fixtures actualizados en `tests/{pages,services,utils}/`, incluido `tests/utils/periodoReporte.test.js`. Misma ruta administrativa, ahora con Programadas/por Médico/por especialidad/por estado, selecciones obligatorias y catálogos históricos, exactamente cinco estados; fecha o rango excluyentes de hasta 366 días inclusivos, filtros cerrados, total/paginación del servidor. Cambiar tipo limpia formulario/resultados y aborta consultas; sin motor genérico ni páginas por tipo.
- **Verificaciones:** única batería `pnpm test src/features/reportes` → **99/99 pruebas, cuatro archivos**, incluida regresión RFE-1 afectada, tres contratos, selecciones inactivas/obligatorias, rangos inválidos/límites, filtros combinados, paginación y aislamiento por tipo/sesión. `pnpm build` correcto; advertencia conocida de chunk >500 kB (926,48 kB). `git diff --check` correcto (avisos LF/CRLF). Salidas en `C:/Users/jesus/AppData/Local/Temp/opencode/reportes-rfe2-{tests,build}.log`.
- **Límites/siguiente paso:** API/adapter simulados y pruebas DOM; sin integración HTTP real React→Laravel/PostgreSQL ni nueva aceptación visual en navegador/dispositivo. Sin cambios a backend, dependencias, migraciones, análisis/diagramas, commit/push/despliegue. RFE-3 (Pacientes registrados) **no iniciada**: requiere autorización, preferentemente en sesión nueva. CU-29 aún no completo; trabajo previo conservado.

### Resultado RFE-3 (2026-10-31)

- **Archivos:** ampliados `frontend/src/features/reportes/{pages/ReportesPage.jsx,services/reporte.service.js}`; nueva `components/PacientesRegistradosTable.jsx`. Nuevos `tests/services/pacientesRegistrados.service.test.js`, `tests/pages/PacientesRegistradosPage.test.jsx` y `tests/utils/pacientesReporteFixtures.js`; ajustados los tests de página/rutas existentes. Cambio mínimo en `src/features/agenda/hooks/useAgendaQuery.js`: mensaje 500 de reportes ya no menciona exclusivamente citas. Misma pantalla con fecha/rango, actividad opcional 0/1, tres órdenes cerrados y total/paginación del servidor; únicamente seis campos de ficha, sin CI/contactos/cuenta/clínica. Se explica creación de ficha frente a alta web y actividad actual; no se consulta `/pacientes` ni se reconstruye el reporte en cliente.
- **Verificaciones:** única batería `pnpm test src/features/reportes` → **158/158 pruebas, seis archivos**, incluida regresión RFE-1/RFE-2 afectada, proyección mínima, inactivos/sin CI/cuenta, filtros/orden, vacíos/páginas fuera del final, errores, 401 real del interceptor con API simulada y descarte por tipo/sesión. `pnpm build` correcto; advertencia conocida de chunk >500 kB (931,19 kB). `git diff --check` correcto (avisos LF/CRLF). Salidas: `C:/Users/jesus/AppData/Local/Temp/opencode/reportes-rfe3-{tests,build}.log`.
- **Límites/siguiente paso:** adapter/API simulados y DOM; sin integración HTTP real React→Laravel/PostgreSQL ni aceptación visual nueva en navegador/dispositivo. Sin backend, dependencias, migraciones, análisis/diagramas, commit/push/despliegue; trabajo previo conservado. RFE-4 (cantidades por período y agenda diaria) **no iniciada**, requiere autorización; recomendada sesión nueva. CU-29 aún no completo.

### Resultado RFE-4 (2026-10-31)

- **Archivos:** ampliados `frontend/src/features/reportes/{pages/ReportesPage.jsx,services/reporte.service.js}`; nuevo `components/CantidadesReporte.jsx`, reutilizando tabla administrativa de citas, selectores históricos y controles existentes. Nuevos `tests/services/cantidadesAgenda.service.test.js`, `tests/pages/CantidadesAgendaPage.test.jsx`, `tests/utils/cantidadesAgendaFixtures.js`; ajustados tests de página/rutas. Cantidades consulta agregado específico sin estado/orden/paginación, muestra Atendidas/Canceladas/Inasistencias/total del servidor incluidos ceros; agenda usa `/reportes/agenda-diaria`, Médico/fecha obligatorios, estado opcional y paginación con orden fijo. Se distinguen inicio programado vigente frente a intersección diaria, sin atribuir fechas de transición, sumar agendas/páginas ni añadir acciones operativas; duración/ubicación conservadas.
- **Verificaciones:** única batería `pnpm test src/features/reportes` → **232/232 pruebas, ocho archivos**, incluida regresión previa afectada, dos contratos, agregado/ceros, filtros aplicables, agenda por intersección con inicio el día anterior, fechas de sede, paginación/vacíos, 429/errores/401 del interceptor y descarte por tipo/filtros/sesión/salida. `pnpm build` correcto; advertencia conocida de chunk >500 kB (935,99 kB). `git diff --check` correcto (avisos LF/CRLF). Evidencias: `C:/Users/jesus/AppData/Local/Temp/opencode/reportes-rfe4-{tests,build}.log`.
- **Límites/siguiente paso:** API/adapter simulados y pruebas DOM; sin integración HTTP real React→Laravel/PostgreSQL ni nueva aceptación visual en navegador/dispositivo. Sin backend, migraciones, dependencias, análisis/diagramas, commit/push/despliegue; trabajo previo conservado. RFE-5 (integración y cierre proporcional frontend) **no iniciada**, requiere autorización; recomendada sesión nueva. Los siete mínimos representados no acreditan CU-29 completo ni producción.

### Resultado RFE-5 (2026-10-31) — cierre frontend local vigente

- **Archivos:** nuevo `frontend/src/features/reportes/tests/pages/ReportesIntegracion.test.jsx`: AuthProvider, servicios de autenticación/reportes, Router y Axios reales con adapter simulado; recorrido de los siete tipos, login con retorno, logout/nuevo Administrador/401 atrasado, revalidación de rol, errores pertinentes sin reenvíos/filtraciones y teclado/foco. Correcciones acotadas en `pages/ReportesPage.jsx` (conservar foco al cambiar tipo; enfocar validación/actualización), `components/FiltroHistorico.jsx` (error de búsqueda por campo y foco al paginar), `components/CantidadesReporte.jsx` (anuncio polite). Sin refactor de Auth/Axios ni estilos/backend.
- **Verificaciones:** única batería `pnpm test src/features/reportes src/constants/navigation.test.js src/layouts/ModuleNavigation.test.jsx src/layouts/Navigation.test.jsx src/features/auth/tests/context/AuthContext.test.jsx src/features/auth/tests/services/auth.service.test.js` → **366/366, 14 archivos**. `pnpm build` correcto; advertencia conocida de chunk >500 kB (936,45 kB). Chrome headless real sobre build servido por preview con API interceptada/simulada → **68 comprobaciones**: siete tipos a 320/390/768/1280/1440 px sin desbordes/datos ocultos, controles etiquetados, fechas America/La_Paz, teclado/foco, actualización/vacíos/ceros y denegación de los otros tres roles sin pedir reportes. Seis capturas; revisión visual de Programadas móvil y Pacientes/Cantidades escritorio. `git diff --check` correcto (avisos LF/CRLF). Evidencias fuera del repo: `C:/Users/jesus/AppData/Local/Temp/opencode/reportes-rfe5-{tests.log,build.log,browser.mjs,browser.log,browser.json}` y capturas `reportes-rfe5-{programadas,pacientes-registrados,cantidades}-{390,1280}.png`. Preview/Chrome temporales cerrados.
- **Límites/pendientes:** no hay entorno HTTP con persistencia aislada acreditado/preparado; sin integración real React→Laravel/Sanctum/PostgreSQL, móvil/SO o lector de pantalla reales ni producción. Adapter/CDP no acredita sesiones/CSRF/SQL reales. Sin base habitual, migraciones, `.env`, servicios externos, dependencias, persistencia local de datos, CSV/PDF, gráficas/dashboards, análisis/diagramas, commit/push/despliegue. Trabajo previo conservado. CU-29 cerrado proporcionalmente en frontend local, **no completo**; siguiente trabajo solo con autorización, preferentemente en sesión nueva.

## Historial de referencia — no releer rutinariamente

Las indicaciones antiguas de próxima fase/no iniciar frontend siguientes describen su fecha de cierre; el plan activo superior sustituye únicamente ese próximo paso. Evidencia y pendientes de integración real se conservan sin declarar verificaciones nuevas.

## Plan backend CU-29 — autorización fase por fase

| Fase | Alcance | Estado |
| --- | --- | --- |
| REP-1 | Contratos API y criterios de cálculo de los siete reportes RF13; revisar reutilización de agenda | Cerrada documentalmente |
| REP-2 | Reportes de citas por fecha, Médico, especialidad y estado | Cerrada localmente; 57 pruebas/831 aserciones propias |
| REP-3 | Pacientes registrados | Cerrada localmente; 40 casos propios cubiertos con corrección de una aserción |
| REP-4 | Cantidades por período y agenda diaria reutilizada | Cerrada localmente; batería dirigida 196/2579 |
| REP-5 | Integración dirigida y cierre proporcional | Cerrada localmente; 4 pruebas/1536 aserciones |

Sin CSV/PDF (opcional), dependencias nuevas ni migraciones. REP-1–REP-5 autorizadas y cerradas proporcionalmente en local; no iniciar frontend ni otra tarea automáticamente. El cierre y los pendientes de CU-28 siguientes permanecen vigentes.

### Relevo REP-5 (2026-10-31) — cierre backend vigente

Backend CU-29 cerrado localmente según [`cu29-contratos-api.md`](cu29-contratos-api.md): siete GET bajo `/api/reportes/`, solo Administrador. Citas/conteos por inicio programado vigente y estado actual, pacientes por creación de ficha, agenda por intersección diaria delegada en AgendaService. Fechas/rangos de hasta 366 días inclusivos America/La_Paz, UTC6, datos mínimos y totales coherentes; CSV/PDF opcional excluido. No fechas de evento/cortes históricos ni permisos generales para otros roles.

REP-5 añade solo `tests/Feature/ReportesIntegracionTest.php` y sincroniza README/contrato/relevo, sin correcciones funcionales necesarias. Alta administrativa sin CI/cuenta, reservas, cancelación/llegada/atención/inasistencia, reprogramación a otro día, CI/inactivación posterior y consultas de los siete reportes por APIs reales; logout/401/nuevo login, denegación de los otros roles conservando consultas operativas, vacíos/errores/paginación y snapshots de solo lectura. Identidades ya activadas/catálogos/horarios/ficha antigua son fixtures explícitos, no nueva prueba CU-04/invitaciones. Análisis/diagramas y cierre CU-28 intactos.

**Pruebas REP-5:** única dirigida `php -d memory_limit=384M vendor/bin/phpunit tests/Feature/ReportesIntegracionTest.php --colors=never` → **4 pruebas/1536 aserciones correctas**, 2,741 s/56 MB. Commits PostgreSQL reales sin transacción exterior, base/rol de pruebas comprobados, esquemas exclusivos eliminados sin residuos/namespaces conservados; cookies/CSRF/sesiones reales en kernel. Mail fake sin envíos/colas, push prohibido y cero peticiones externas. Autoload sin scripts/Pint/sintaxis/diff dirigidos correctos. Logs `Temp/opencode/reportes-rep5-{tests.log,autoload.log,pint.log,lint.log}`. REP-4 conserva 196/2579 propios; no repetidos ni sumados a REP-5, sin suite global por no haber cambios funcionales.

**Límites/pendientes:** no servidor HTTP independiente ni integración React→Laravel/Sanctum en navegador, frontend/aceptación visual-accesible, concurrencia multiproceso nueva o producción. Sin dependencias/migraciones, base habitual sin migrar, sin commit/push/despliegue. Próxima tarea requiere autorización, preferentemente en sesión nueva; no iniciar frontend/otro módulo ni reabrir CU-28 automáticamente. Análisis/diagramas siguen aplazados hasta terminar los módulos restantes; no declarar CU-29 completo.

### Relevo REP-1 (2026-10-31) — histórico, anterior a REP-2

Contrato indispensable: [`cu29-contratos-api.md`](cu29-contratos-api.md), cotejado con RF13, CU-29, VS-REP-01–03 y agenda existente. Siete GET exclusivos de Administrador bajo `/api/reportes/`: `citas/programadas`, `citas/por-medico`, `citas/por-especialidad`, `citas/por-estado`, `pacientes/registrados`, `citas/cantidades`, `agenda-diaria`; **aún no existen**. Listados paginados 20/100, orden cerrado y total del conjunto; cantidades terminales agregadas sin paginación. Campos administrativos mínimos, sin clínica/CI/contactos.

Decisiones REP-1 (diseño, no exigencias nuevas del examen): fecha o rango civil inclusivo de hasta 366 días en America/La_Paz, convertido a intervalo UTC semiabierto. Citas/conteos por inicio programado vigente y estado actual, **no fecha real de atención/cancelación ni corte histórico**; pacientes por creación de ficha, no cuenta web. Agenda conserva intersección diaria de CU-20 mediante `ReporteService → AgendaService → AgendaRepository`, con autorización adicional exclusiva de Administrador y Resource existente. Vacíos/cero no son error; inactivos y fichas sin cuenta conservan historia.

Verificación REP-1: revisión estática de fuentes, modelos, rutas, Request/Service/Repository/Resource y referencias de pruebas de agenda; sin ejecutar pruebas ni acceder a BD. Solo cambian este relevo y el contrato; análisis/diagramas y cierre CU-28 intactos. Pendiente REP-2 previa autorización en sesión nueva: implementar cuatro reportes de citas y pruebas dirigidas; cotejar selector histórico de especialidades. REP-3–REP-5 pendientes, sin inicio automático. Sin dependencias, migraciones, commit/push/despliegue.

## Fases backend CU-28 (cerradas localmente)

| Fase | Alcance | Estado |
| --- | --- | --- |
| F1 | Diseño/contratos de CU-28 y Web Push (documental) | Cerrada |
| F2 | Suscripciones push, consentimiento, revocación, cifrado, auditoría | Cerrada localmente (187 pruebas) |
| F3 | Generación automática + scheduler + outbox recuperable | Cerrada localmente (79 pruebas / 950 aserciones) |
| F4 | Consulta autorizada de avisos (`GET /api/recordatorios`) | Cerrada localmente (111 pruebas/883 aserciones + 9/123 de permisos) |
| F5 | Transporte Web Push, VAPID, endurecimiento de red | Cerrada localmente con DNS/HTTP simulados (194 pruebas/1161 aserciones) |
| F6 | Despacho concurrente, leases, reintentos | Cerrada localmente con transporte simulado (201 pruebas/1354 aserciones) |
| F7 | Integración y cierre proporcional del backend | Cerrada localmente (43 pruebas/453 aserciones; transporte simulado) |

## Plan frontend aprobado — FE-1 a FE-6

Trabajar desde `frontend/`, una fase por sesión y autorización, sin iniciar automáticamente otra tarea. **FE-1–FE-6 cerradas localmente; permanecen pendientes las acreditaciones reales indicadas abajo.** CU-29/reportes fuera de este alcance. Se reutilizan identidad visual, componentes, Axios y autenticación existentes, sin dependencias nuevas. Stack: React/Vite/JavaScript, pnpm 11.17.0 y Vitest; Recordatorios operativo para Paciente y consulta administrativa, Service Worker con recepción/clic seguros.

| Fase | Implementación y criterio de cierre | Estado |
| --- | --- | --- |
| FE-1 | Avisos del Paciente: feature `src/features/recordatorios/`, servicio, pantalla `/recordatorios`, ruta y navegación. Cita, fecha/hora de sede, Médico, especialidad, consultorio y estado; filtros de fechas, paginación, carga/vacío/error y actualización manual. Servicio/pantalla/permisos y descarte de respuestas antiguas al cambiar sesión. Lectura sin generación ni marcado de leído. | Cerrada localmente |
| FE-2 | Consulta administrativa reutilizando pantalla y selector existente cuando encaje. Administrador selecciona paciente y envía `id_paciente`; limpiar resultados al cambiar selección e ignorar respuestas atrasadas. Sin modificar preferencias/push del titular; Médico/Recepcionista denegados. Probar selección, aislamiento y rutas por rol. | Cerrada localmente |
| FE-3 | Alta push del dispositivo: servicios de configuración/consentimiento y Service Worker mínimo. Detectar contexto seguro/soporte; pedir permiso solo por acción explícita del Paciente, suscribir con clave pública y registrar por API. Diferenciar permiso del navegador de autorización SQL; manejar incompatibilidad, denegación, canal apagado y fallo de alta sin bloquear avisos internos. Probar navegador/API simulados, sin alta automática ni envío real. | Cerrada localmente |
| FE-4 | Gestión de dispositivos propios: listar campos públicos disponibles, desactivar navegador actual y revocar otro dispositivo. Coordinar baja local/servidor y comunicar resultados parciales; respetar hooks backend de logout/cambio de cuenta, sin duplicar lógica. Expiración natural/cerrar pestaña no revocan; no renovar ni reasignar automáticamente. Probar revocación, red y cambios de titular; regresión auth solo donde haya cambios. | Cerrada localmente |
| FE-5 | Recepción/clic seguros: Service Worker procesa push con mensaje genérico aprobado y abre/enfoca `/recordatorios` del mismo origen. Login si falta sesión y consulta vigente al entrar; notificación no concede acceso ni prueba vigencia. Sin caché de API/sesiones/clínica, modo offline o polling innecesario. Probar eventos simulados, destino seguro y aviso obsoleto. | Cerrada localmente |
| FE-6 | Integración y cierre: recorridos Paciente/Administrador, responsive/teclado/foco/fechas, `pnpm build` y pruebas del módulo e integraciones modificadas. Separar simulaciones de comprobaciones reales; prueba de entrega requiere autorización/entorno/dispositivo preparados. Registrar límites pendientes sin declarar entrega acreditada. | Cerrada proporcionalmente en local; HTTP/push real pendientes |

### Resultado FE-6 — cierre local proporcional (2026-10-31)

Contratos cotejados con rutas/controllers/FormRequests/Resource de Laravel; sin nuevos endpoints/payloads. Recorridos de Paciente/Administrador, selección/aislamiento, filtros/paginación/actualización, alta/baja explícitas, parcial servidor/local, errores, login desde notificación antigua, 401 de dispositivos y cambio de cuenta con 401 atrasado verificados con servicios/Axios/AuthProvider/Router reales y **adapter/navegador simulados**. SW conservado y probado mediante eventos/ventanas simulados; no concede acceso ni prueba vigencia. Sin automatizar altas, renovaciones, reasignaciones o bajas.

Correcciones acotadas: `AltaPush.jsx` retira texto obsoleto de recepción/clic pendientes; `DispositivosPush.jsx` enfoca confirmación y restaura foco al conservar o al encabezado durante baja; `RecordatoriosPage.jsx` identifica región de resultados y anuncia ocupación. Pruebas en `tests/components/DispositivosPush.test.jsx` y nuevo `tests/pages/RecordatoriosIntegracion.test.jsx`. Sin cambios en backend/auth/Axios/SW/CSS ni documentos de análisis/diagramas; trabajo local previo conservado.

**Verificaciones:** una batería `pnpm test src/features/recordatorios src/features/auth/tests/context/AuthContext.test.jsx src/features/auth/tests/services/auth.service.test.js src/constants/navigation.test.js src/layouts/ModuleNavigation.test.jsx src/layouts/Navigation.test.jsx src/features/pacientes/tests/pages` → **560/560, 22 archivos**. `pnpm build` correcto (advertencia conocida de chunk >500 kB, 913,04 kB). Chrome headless real con build servido por Vite preview, perfil temporal y API interceptada/simulada: **22 comprobaciones finales**, Paciente/Administrador a 320/390/768/1280/1440 px sin desborde ni celdas ocultas; teclado/foco, fechas America/La_Paz y retiro de avisos al actualizar. Cuatro capturas; revisión visual de móvil Paciente y escritorio Administrador. Sonda CDP inicial ajustada para enviar Enter completo; sin fallo funcional adicional. No es navegador/dispositivo push real ni HTTP a Laravel.

**Evidencias externas:** `C:/Users/jesus/AppData/Local/Temp/opencode/recordatorios-fe6-{tests.log,build.log,browser.mjs,browser.log,browser.json}` y `recordatorios-fe6-{paciente,administrador}-{390,1280}.png`. `git diff --check` dirigido correcto. Procesos temporales Chrome/preview cerrados.

**Límites y pendientes:** entorno HTTP con persistencia aislada no preparado/comprobado en esta fase: sin integración real React→Laravel/Sanctum/PostgreSQL. Recepción/clic SW, compatibilidad móvil/SO, HTTPS/proveedor/entrega push y lector de pantalla reales siguen sin verificar; Chrome headless con emulación de tamaños no equivale a dispositivo móvil real. Sin envíos/correos reales, migraciones, datos reales, cambios `.env`, claves privadas, dependencias, caché persistente de API/sesión/clínica, offline o polling añadido. No acreditar entrega/lectura/sonido/encendido/retiro ni producción; **CU-28 no completo**. Próximo paso solo con autorización/entorno seguro: integración HTTP aislada y comprobaciones de navegador/dispositivo push. No iniciar CU-29 automáticamente; recomendada sesión nueva.

### Evidencia previa FE-5 (relevo histórico, superado por FE-6 arriba)

`public/push/sw.js` atiende `push` y `notificationclick`, sin fetch/caché/almacenamiento ni activación forzada. Texto fijo cotejado con `backend/src/shared/utils/TransportePush.php`: «Clínica Vida Salud» / «Tienes un aviso disponible. Consulta el sistema.». No interpreta el payload: también ausente, inválido o malicioso muestra exclusivamente ese texto, sin datos clínicos ni destinos recibidos. Clic cierra la notificación y usa solo `/recordatorios` del mismo origen; incluye ventanas no controladas por el scope `/push/`. Si ya existe exactamente ese destino, navega de nuevo y enfoca; si no existe o desaparece al navegar, abre el destino fijo. Otras pantallas/URLs con parámetros no se alteran. Fallos del navegador se resuelven sin logs privados ni reintentos.

La navegación completa reutiliza restauración de sesión, protección de ruta y GET vigente por Axios al entrar, incluso si la lista ya estaba abierta; no se confía en datos previos ni en la vigencia de la notificación. `destinoSeguro` admite el retorno exacto tras login solo para Paciente/Administrador. Sin cambios en auth, consultas, alta/baja, estilos o backend; gestión de dispositivos y consulta administrativa conservadas. Sin altas/renovaciones/reasignaciones/revocaciones automáticas.

Verificación dirigida única: `pnpm test src/features/recordatorios` → **233/233 pruebas, 11 archivos**. `pushWorker.test.js` ejecuta el SW en VM con eventos/ventanas/notificaciones simulados; rutas prueban login/roles, nueva consulta tras recarga, aviso retirado y sesión expirada con API simulada. Se actualizó la antigua aserción de SW vacío de FE-3. Evidencia: `C:/Users/jesus/AppData/Local/Temp/opencode/recordatorios-fe5-tests.log`. Sin build ni cierre global FE-6 en esta fase; análisis/diagramas intactos, cambios locales previos conservados.

Límites: sin recepción/clic en navegador o dispositivo real ni integración HTTP real. Sin envío real, migraciones, cambios `.env`, claves privadas, dependencias, caché persistente, modo offline o polling añadido. No se acredita entrega/lectura/sonido/encendido de pantalla ni retiro de notificaciones transmitidas. Pendientes: integración y verificaciones globales FE-6, compatibilidad/entrega real con autorización y entorno preparados. Recomendada sesión nueva; **FE-6 no iniciada ni autorizada automáticamente**.

### Evidencia previa FE-4

`DispositivosPush.jsx` integra el alta existente y gestión solo del Paciente en `RecordatoriosPage`; reutiliza botones, confirmación, paginación y fechas/estilos. `push.service.js` lista/proyecta exclusivamente ID, actual, activa, versión y fechas públicas, valida paginación y revoca con CSRF sin cuerpo ni titular. Confirmación explícita: navegador actual por cookie de instalación → DELETE confirmado en servidor → baja local mediante `pushBrowser.js`, sin registrar SW ni modificar scopes ajenos. Si falla servidor no toca la suscripción local y comunica incertidumbre; si servidor confirma y falla navegador informa resultado parcial y permite reintento explícito. Otro dispositivo se revoca por ID sin tocar el navegador actual. Reconsulta de lista sin replay de escrituras; alta/baja se excluyen y se retira la confirmación de alta previa al desactivar este navegador.

Identidad/época/abort y guardas antes/después de cada espera descartan respuestas atrasadas al cambiar titular/sesión, salir o desmontar; no se compensan operaciones ya enviadas. Hooks backend de logout/cambio de cuenta intactos, sin duplicar lógica ni modificar autenticación. Cerrar pestaña/expiración solo limpian estado, no revocan. Avisos internos y consulta administrativa conservados; Administrador sin gestión de dispositivos. Sin altas, renovaciones o reasignaciones automáticas.

Verificación dirigida única: `pnpm test src/features/recordatorios` → **204/204 pruebas, 10 archivos** (API/navegador simulados; contratos, revocación, fallos/parciales, titular/época/salida, regresiones FE-1–FE-3 y rutas por rol). `pnpm build` correcto; advertencia preexistente de chunk >500 kB. `git diff --check` dirigido correcto. Evidencias fuera del repositorio: `C:/Users/jesus/AppData/Local/Temp/opencode/recordatorios-fe4-{tests,build}.log`.

Límites: sin integración HTTP ni dispositivos reales, recepción/clic FE-5 o cierre global FE-6. Una baja local/SQL ya en vuelo no es cancelable con garantía; no se promete rollback ni retirar notificaciones transmitidas. Sin cambios backend/auth/.env, claves privadas, dependencias, caché persistente, migraciones o envíos reales. Cambios previos conservados; análisis/diagramas intactos. Recomendada sesión nueva para FE-5; **no iniciada ni autorizada automáticamente**.

### Evidencia previa FE-3

Alta opcional solo del Paciente en la pantalla existente: `components/AltaPush.jsx`, `services/push.service.js`, `utils/pushBrowser.js` y `public/push/sw.js`. GET de configuración, permiso exclusivamente al pulsar (sin await previo), activación acotada del SW, PushManager con clave pública del backend y PUT con consentimiento explícito/CSRF. Permiso del navegador y confirmación SQL separados; errores seguros, incompatibilidad, canal apagado y denegación no bloquean avisos internos. Época/identidad/abort protegen cambios de sesión, titular, salida y respuestas tardías; sin bajas compensatorias que pudieran afectar otra cuenta. Una operación ya enviada puede haberse confirmado aunque se aborte: no se promete rollback. Suscripción local existente solo se registra por nueva acción explícita y con clave coincidente; no se reemplaza ni revoca automáticamente. Administrador conserva exclusivamente consulta.

Verificación: `pnpm test src/features/recordatorios` → **117/118**, único fallo en lectura del archivo SW de una prueba (URL de Vitest/jsdom no `file:`); corregido a ruta local. Reejecución dirigida de `AltaPush.test.jsx` y `pushBrowser.test.js`, incluyendo dos casos añadidos de aborto de alta al cambiar titular y continuidad de avisos internos → **43/43**; los otros 77 casos pasaron en la primera ejecución. Pruebas exclusivamente con navegador/API simulados. `pnpm build` correcto, advertencia preexistente de chunk >500 kB. Evidencias fuera del repositorio: `C:/Users/jesus/AppData/Local/Temp/opencode/recordatorios-fe3-{tests,correccion-tests,build}.log`.

Límites al cerrar FE-3: sin entrega/recepción/clic reales ni handlers push (FE-5), gestión de dispositivos/bajas (resuelta después en FE-4) o caché persistente. Sin dependencias nuevas, cambios de backend/.env, claves privadas, migraciones o envíos reales. No se verificó compatibilidad en dispositivos reales ni integración HTTP real; revisión visual/teclado e integración final pendientes de FE-6. Cambios locales previos conservados; análisis/diagramas intactos.

### Evidencia previa FE-2

Reutilizados pantalla, tabla, servicio, hook y estilos de `src/features/recordatorios/`; `/recordatorios` y navegación compartidos por Paciente/Administrador. `PatientsPage` admite textos opcionales de selección sin cambiar sus valores anteriores. Administrador busca/selecciona ficha (también inactiva o sin cuenta), envía `id_paciente` y el servicio verifica coincidencia de `meta.paciente.id_paciente`. Cambiar paciente desmonta/aborta resultados; cambios de identidad/época y salida de página retiran selección y descartan pendientes, incluida búsqueda de fichas. GET propio del Paciente sigue sin selector; Médico/Recepcionista denegados. Sin escrituras, preferencias, push ni dependencias nuevas.

Pruebas dirigidas: `pnpm test src/features/recordatorios src/constants/navigation.test.js src/layouts/ModuleNavigation.test.jsx src/layouts/Navigation.test.jsx src/features/pacientes/tests/pages` → 348/350 correctas; dos fallos del catálogo de navegación corregidos (unificar entrada de ruta compartida y actualizar pendientes). Reejecución exclusiva `pnpm test src/constants/navigation.test.js` → **64/64 correctas**; los otros 286 casos/11 archivos pasaron en la primera ejecución. `pnpm build` final correcto, advertencia preexistente de chunk >500 kB (898,23 kB); `git diff --check` dirigido correcto. Evidencias: `C:/Users/jesus/AppData/Local/Temp/opencode/recordatorios-fe2-{tests,navigation-tests,build}.log`. API/navegador simulados; integración con servidor real y revisión visual/teclado en dispositivos pendientes de FE-6. Sin migraciones ni envíos reales; cambios locales previos conservados y análisis/diagramas intactos. Recomendada sesión nueva para FE-3; no iniciada. Evidencia FE-1 conservada en `recordatorios-fe1-{tests,build}.log` (138 pruebas/6 archivos y build correctos).

Al cerrar cada FE: anotar aquí estado, archivos relevantes, comando/resultado de pruebas y bloqueos para la siguiente sesión; preservar el cierre backend inferior. Actualizar otro documento solo si cambia un contrato/decisión indispensable. **Análisis de clases y diagramas aplazados hasta terminar los módulos restantes.** No reabrir backend F5/F6 ni repetir suites globales sin riesgo concreto.

Prompt de relevo: «Continúa FE-N de CU-28 desde `docs/ai/estado-fase.md`. Lee solo lo necesario, ejecuta pruebas dirigidas y no anticipes la siguiente fase. Documentación mínima; análisis y diagramas aplazados».

## Evidencia de cierre backend (referencia; no repetir)

**F7 final:** seis integraciones nuevas en `backend/tests/Feature/RecordatoriosIntegracionTest.php`: consentimiento API → generación/despacho por Artisan → consulta autorizada/bitácora; cancelación/reprogramación API, revocación/logout/preferencia API. APIs por kernel Laravel con cookies/CSRF/sesiones SQL y comandos dentro del runner, no servidor/CLI externos. Identidad activada/cita inicial/recursos son fixtures SQL; transporte sustituido, commits PostgreSQL reales, esquemas propios/base y rol comprobados/limpieza e inventario de namespaces conservados por caso. Única dirigida `--filter 'RecordatoriosIntegracionTest|ConsultaRecordatoriosTest'` → **43/453**,14,201s/108MB,código0. No suma fases previas; no nuevas pruebas de concurrencia/red. Pint dirigido y diff correctos. Sin defectos que exigieran cambios funcionales ni razón para suite global. README actualizado; demás documentos intactos. Evidencia `C:/Users/jesus/AppData/Local/Temp/opencode/recordatorios-f7-{tests.log,result.json}`.

F5 cotejada con código/log final **194/1161**, sin pendientes que bloquearan F6. F6 final: `--filter 'DespachoPushTest|DespachoPushConcurrenteTest|TransportePushTest|GeneracionRecordatoriosTest|GeneracionRecordatoriosConcurrenteTest|SuscripcionesPushTest'` → **201/1354**,47,093s/138MB,código0. Diez casos nuevos multiproceso con PostgreSQL/commits/barreras stdin y transporte sustituido; contextos modificados como fixtures SQL, no APIs acreditadas. Inventario SQL idéntico/sin esquemas propios residuales; logs/resultados `Temp/opencode/recordatorios-f6-*`. No sumar resultados de otras fases. F4 conserva111/883 y matriz9/123; no repetir matriz completa (128MB insuficientes).

## Contratos ya fijados (no reabrir sin decisión)

- Rutas existentes: `GET /api/push/configuracion`, `GET /api/push/suscripciones`, `PUT /api/push/suscripcion`, `DELETE /api/push/suscripcion`, `DELETE /api/push/suscripciones/{id}` (solo Paciente); `GET /api/recordatorios` (Administrador con `id_paciente` obligatorio, Paciente solo lo propio).
- Scheduler cada minuto, ventana `inicio_at − 24 h ≤ ahora < fin_at`, estados `Programada`/`Confirmada`, preferencia ausente = habilitada.
- Push solo si `ahora < inicio_at`, canal habilitado y clave pública válida. Sin envío real en F4.
- `PUSH_NO_CONFIGURADO` = 503, `PUSH_SUSCRIPCION_OBSOLETA` = 409.
- Lectura de avisos: sin efectos secundarios, `Cache-Control: no-store, private`, fechas UTC6 y `America/La_Paz` en meta.
- Transporte: `TransportePush` → minishlink 11.0.0 → `ClientePushSeguro` PSR-18/cURL. Par VAPID validado; payload fijo genérico, `aes128gcm`, urgencia normal, TTL conservador menor que ventana. DNS en proceso acotado, IP pública/pinning/TLS/sin proxy/redirects/reuso/replay; guard tras TLS antes de POST. Requiere PHP CLI/procesos y `CURLOPT_PREREQFUNCTION`, falla cerrado si no disponible.
- Resultado seguro `{tipo,http,retry_after}`: Aceptado solo201; Obsoleta404/410; Fallido400/413; Configuracion401/403; Reintentable429/503; Incierto para fallo después de posible envío/otros estados; NoEnviado si rechazo previo. No cambia persistencia ni revoca dispositivos por sí mismo. Contrato/detalle en cabecera README.
- Dispatcher efectivo: `DespachoPushService`, comando `recordatorios:despachar --lote=100` (1–1000), scheduler cada minuto. Claim/token UUID/lease60s, segunda autorización antes de red, HTTP fuera de transacción; resultado solo con token/lease vigentes. Vencimiento/pérdida de resultado→Incierto sin replay; rechazo conocido/preenvío permite3 intentos,60s/300s o Retry-After mayor, siempre antes de inicio. Obsoleta revoca/audita solo autorización coincidente; Configuracion terminal detiene barrido. Sin migraciones/dependencias nuevas; no ejecutar comandos contra base habitual sin migrar ni autorización de envío.

## Reglas de trabajo ahorradoras (vigentes)

- No leer completos `analisis-clases.md`, `contexto-maestro.md`, `diagrama-clases.md` ni `README.md`: `grep` + `read` con `offset`/`limit`.
- Pruebas dirigidas al cambio, salida a archivo; repetir solo para corregir fallos o verificar riesgos concretos. Sin suites globales rutinarias.
- Cambios documentales puntuales solo cuando sean necesarios. **Decisión posterior del usuario:** análisis de clases y diagramas se completarán al finalizar los módulos restantes, no en cada fase. Evitar relecturas y sobreingeniería.
- **Autorizaciones posteriores F5:** instalar minishlink/web-push y sus dependencias (ocho añadidos, ningún paquete previo actualizado); generar claves efímeras exclusivamente en memoria para pruebas con HTTP simulado. No autoriza claves persistentes, modificar `.env` ni envíos reales. Sin migrar base habitual ni commit/push.

## Riesgos y límites conocidos

- Ningún envío push acreditado; recepción/clic del Service Worker verificados solo con eventos y navegador simulados. Chrome real de FE-6 acredita render/teclado con API simulada, no recepción/clic push real.
- Endurecimiento/dispatcher verificados sin conexión externa; TLS/DNS/proveedor/push en navegador real siguen sin acreditar. Frontend FE-1–FE-6 cerrado proporcionalmente en local; integración HTTP real pendiente y CU-28 no completo. Última autorización SQL y red no son atómicas; un envío en vuelo puede cruzarse con revocación/cambio. No entrega exactamente una vez ni retiro garantizado de lo transmitido.
- `000038`/`000039` solo se aplicaron en esquemas de prueba; los tests de autenticación con esquema parcial las incluyen.
- Composer audit detectó aviso preexistente en `symfony/mime` (CVE-2026-45070), ajeno a la dependencia nueva; corregir en tarea autorizada aparte. Para pruebas criptográficas locales se usó `OPENSSL_CONF=C:/php/extras/ssl/openssl.cnf` solo en el proceso del runner.
