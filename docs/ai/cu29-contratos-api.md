# CU-29 — contratos backend y criterios de cálculo

**Base histórica:** 2026-10-31, backend REP-1–REP-5. **PDF opcional 2026-11-07:** Fases 1–3 cerradas localmente (§§8–10); historial en `cu29-pdf-estado-fase-historico.md` y evidencia en `cu29-aceptacion-pdf.md`. **Ampliación estadística independiente:** EST-1 concreta el diseño aditivo de §§11–15, todavía no implementado ni probado funcionalmente. No existe una Fase 4 del plan PDF. Relevo activo en `estado-fase.md`.

## 1. Fuentes y alcance

- **Examen obligatorio:** RF13 (`requerimientos.md`, §RF13): siete reportes, fecha/rango y totales pertinentes. CU-29 (`casos-de-us.md`, §CU-29) y VS-REP-01–03 (`reglas-negocio.md`, §14): solo Administrador, filtros validados, solo lectura y resultados vacíos válidos.
- **Restricciones conservadas:** RN10/RN11 (historial y desactivación, no eliminación), RN12 (autorización), RN14 (persistencia PostgreSQL); CU-20 para agenda. No filtrar historia por actividad actual del Médico/Paciente, cuenta web, horario o habilitación actual de especialidad/consultorio.
- **Decisiones de diseño REP-1, no nuevas exigencias del examen:** URLs, límites, proyecciones, órdenes y semántica temporal siguientes. No se modifican RF/RN ni las precisiones aprobadas de CU-20. CSV/PDF opcional queda fuera: sin parámetros de exportación, archivos ni dependencias.

## 2. API y cobertura de los siete mínimos

Todos son **GET /api/reportes/**; los nombres de la tabla completan ese prefijo. IDs de selección se reciben en query, no en cuerpo. Los filtros opcionales se combinan por AND.

| RF13 | Recurso | Selección obligatoria | Filtros opcionales específicos | Resultado |
| --- | --- | --- | --- | --- |
| 1 | `citas/programadas` | `fecha` | `id_medico`, `id_especialidad` | Citas cuyo estado actual es exactamente `Programada`; no incluye `Confirmada` |
| 2 | `citas/por-medico` | `id_medico` y fecha/rango | `id_especialidad`, `estado` | Citas del Médico elegido, aunque esté inactivo |
| 3 | `citas/por-especialidad` | `id_especialidad` y fecha/rango | `id_medico`, `estado` | Citas de la especialidad guardada en Cita, no la asociación vigente del Médico |
| 4 | `citas/por-estado` | `estado` y fecha/rango | `id_medico`, `id_especialidad` | Citas del estado elegido |
| 5 | `pacientes/registrados` | fecha/rango | `activo` (`0` o `1`) | Fichas creadas en el período, tengan o no CI/cuenta web; una fila por ficha |
| 6 | `citas/cantidades` | fecha/rango | `id_medico`, `id_especialidad` | Un objeto con cantidades de `Atendida`, `Cancelada`, `Inasistencia` y su total |
| 7 | `agenda-diaria` | `id_medico`, `fecha` | `estado` | Agenda de un Médico/día reutilizada de CU-20; repetir selección permite consultar cada Médico |

Los reportes 1–5 y 7 son listados paginados. El 6 es un agregado de todo el período, sin paginación ni agrupación temporal adicional. No se necesita un motor genérico, catálogo nuevo de reportes ni tablas Reporte. Los reportes 2–4 son listados de la selección con total, no listados globales agrupados de todas las entidades.

## 3. Fechas de referencia y períodos

- **Fecha/rango:** exactamente `fecha=YYYY-MM-DD` o el par `desde=YYYY-MM-DD&hasta=YYYY-MM-DD`. Sin mezcla, fechas omitidas, extremos incompletos, cadenas vacías ni fechas civiles imposibles; años de cuatro dígitos 0001–9999 sujetos a que el extremo exclusivo sea representable. Recursos 1 y 7 solo admiten `fecha`.
- `desde <= hasta`, ambos días inclusivos para el usuario. Límite técnico: **366 días civiles inclusivos** por consulta; una fecha equivale a un día. No es un requisito del examen. Se pueden consultar períodos pasados o futuros; no aplicar restricciones de reserva a lecturas históricas.
- Zona fija **America/La_Paz**, no elegible por query ni tomada de la conexión SQL. Construir medianoche local inicial y medianoche del día posterior al último, convertir ambas a UTC y consultar **[inicio, fin_exclusivo)**, preservando microsegundos. No redondear timestamps ni comparar solo su fecha UTC.
- **Reportes 1–4 y 6:** referencia `citas.inicio_at`, es decir, fecha/hora programada vigente de atención. Seleccionar `inicio_at >= inicio AND inicio_at < fin_exclusivo`. No usar `creada_en`, `actualizada_en`, fecha de cancelación o fecha de registro de consulta.
- **Cantidades:** cada Cita cuenta una vez según su **estado actual al consultar**, no por número de transiciones. Son «citas programadas en el período que actualmente están Atendidas/Canceladas/Inasistencias», **no** «eventos de atención/cancelación/inasistencia ocurridos en el período». No afirmar fecha real de atención desde `inicio_at`. Una cancelación fuera del período cuenta si el inicio programado cae dentro; una cancelación dentro con inicio fuera no cuenta. No se reconstruyen cortes históricos «como estaba al cierre» ni se usan cambios/bitácora como fuente de conteos.
- Una reprogramación cambia la pertenencia al período al cambiar `inicio_at`; una transición posterior cambia los conteos. El reporte no es una fotografía histórica inmutable. Las tres cantidades excluyen `Programada` y `Confirmada`.
- **Pacientes registrados:** referencia `pacientes.creado_en`, creación persistida de la ficha; no creación/activación/vinculación de Usuario, incorporación del CI o edición de la ficha. Registros pendientes sin ficha no cuentan. Inactivos incluidos salvo filtro `activo`; ese filtro refleja actividad actual, no actividad histórica en el día de alta.
- **Agenda diaria (excepción deliberada de CU-20):** intersección `inicio_at < fin_exclusivo AND fin_at > inicio`. Una cita que cruza medianoche puede figurar en dos agendas diarias; los conteos/listados por inicio la cuentan solo el día de inicio. No sumar agendas para calcular cantidades.
- Ejemplo: `fecha=2026-10-31` equivale a `[2026-10-31T04:00:00.000000Z, 2026-11-01T04:00:00.000000Z)`. Una cita que termina exactamente al inicio del día no integra su agenda; una que empieza exactamente en el extremo final no pertenece al día.

## 4. Validación, orden y paginación

- FormRequest: obligatoriedad, tipos/formatos, coherencia del rango y listas cerradas por recurso. Rechazar cuerpos GET, claves desconocidas y filtros no aplicables (incluidos `rol`, `id_paciente`, zona horaria, `tipo`, agrupaciones o formatos de exportación). No ignorarlos silenciosamente. No aceptar arrays para filtros escalares ni múltiples estados.
- Estados únicos y sensibles a escritura: `Programada`, `Confirmada`, `Atendida`, `Cancelada`, `Inasistencia`. En `programadas` no se admite `estado`, aunque sea Programada; en `cantidades` tampoco, pues eliminaría categorías obligatorias.
- IDs: entero decimal positivo canónico, máximo bigint PostgreSQL `9223372036854775807`, sin signo/cero inicial/exponente. Mantener como cadenas al serializar y no convertir a Number de JavaScript.
- Service: autorización y existencia de Médico/especialidad seleccionados; inexistente → 404 `RECURSO_NO_ENCONTRADO`. Recursos existentes sin citas o combinación Médico/especialidad sin coincidencias → 200 vacío, sin exigir asociación actual. No utilizar `exists` en FormRequest como sustituto de la comprobación de negocio.
- Listados: `page` entero 1–1000000, por defecto 1; `per_page` entero 1–100, por defecto 20, como agenda. Página más allá del final devuelve `data: []` **con total real**, no cero falso ni 404.
- `orden` cerrado: reportes 1–4 admiten `fecha_asc` (defecto) o `fecha_desc`, por `inicio_at` y desempate `id_cita` en igual dirección. Pacientes admite `registro_asc` (defecto), `registro_desc` o `nombre_asc` (apellidos, nombres, id_paciente ASC). Registro usa `creado_en` e id_paciente en igual dirección. Orden de nombres con colación de PostgreSQL existente, sin prometer otro orden lingüístico. Mapeos internos constantes; nunca interpolar valores HTTP en SQL.
- Agenda mantiene orden fijo `inicio_at ASC, id_cita ASC` y no admite `orden`. Cantidades no admite `page`, `per_page` ni `orden`.

## 5. Campos administrativos y respuestas

**Listados de citas y agenda:** reutilizar la proyección pública de `CitaListadoResource`, sin ampliarla: `id_cita`, `codigo_cita`, `inicio_at`, `fin_at`, `duracion_minutos`, `estado`, `modalidad`, `sede_atencion`, `consultorio_atencion`, `creada_en`; objetos `medico {id_medico,codigo_medico,nombres,apellidos}`, `especialidad {id_especialidad,nombre}`, `paciente {id_paciente,codigo_paciente,nombres,apellidos}`. Duración y ubicación conservadas de Cita, no configuración actual.

**Pacientes registrados:** únicamente `id_paciente`, `codigo_paciente`, `nombres`, `apellidos`, `activo`, `creado_en`. Sin CI, nacimiento, sexo, dirección, contacto, cuenta web ni preferencias: no son necesarios para este reporte mínimo. No leer relaciones clínicas ni contar una ficha más de una vez por joins.

**Exclusiones comunes:** motivo breve, antecedentes, diagnósticos, consultas, laboratorio, documentos, secretos, hashes, tokens y datos personales ajenos a la proyección. No serializar modelos completos.

- Éxito **200**, JSON `data` más `meta`; `Cache-Control: no-store, private` también en errores. Timestamps públicos UTC ISO-8601 con seis decimales y `Z`; fechas de filtros permanecen locales `YYYY-MM-DD`. IDs como cadenas, totales como enteros y `activo` como booleano.
- Listados: `data` es array; conservar paginación estándar Laravel (`links` y `meta.current_page`, `from`, `last_page`, `links`, `path`, `per_page`, `to`, `total`). `meta.total` cuenta **todas** las filas filtradas, no la página. No duplicar con un segundo total independiente.
- Contexto adicional en `meta`: `reporte` (nombre del recurso de la tabla), `zona_horaria`, `consultado_en` UTC6, `filtros` normalizados (selecciones aplicables, opcionales ausentes como null, orden resuelto si aplica; paginación en sus campos propios), `periodo {desde,hasta,inicio_at,fin_exclusivo_at,referencia,criterio}`. `referencia`: `citas.inicio_at` o `pacientes.creado_en`; `criterio`: `inicio_en_periodo`, `registro_en_periodo` o `interseccion_diaria`. Con `fecha`, desde/hasta son ese mismo día. `consultado_en` es contexto de consulta, no token de snapshot histórico.
- Filtros implícitos efectivos se hacen visibles: `estado: Programada` en programadas; `estados: [Atendida,Cancelada,Inasistencia]` en cantidades. La agenda mantiene además `meta.medico` y sus filtros `id_medico`, `fecha`, `estado` existentes. Los enlaces se construyen solo con filtros validados, sin credenciales ni parámetros desconocidos.
- Cantidades: `data: {atendidas: N, canceladas: N, inasistencias: N, total: N}`; `total = atendidas + canceladas + inasistencias`. Sin metadatos/enlaces de paginación. Vacío devuelve las cuatro propiedades a **0**, no un array vacío, null ni 404.
- Listado vacío real: `data: []`, `meta.total: 0`, contexto/filtros conservados. Selecciones existentes se mantienen aunque no haya filas.
- Filas y total del listado deben compartir snapshot SQL; las cuatro cantidades se calculan en una sola agregación sobre el mismo conjunto filtrado. No cargar todo para contar en PHP/React. Distintas páginas/consultas pueden cambiar por operaciones concurrentes; no prometer snapshot entre peticiones.

## 6. Autorización y reutilización de agenda

- Cadena implementada: Route → middleware → Controller/FormRequest → `ReporteService` → `ReporteRepository` → modelos existentes/PostgreSQL; agenda diaria delega de Service a AgendaService/AgendaRepository. Módulo `reportes` sin controllers con consultas/reglas/transacciones, dependencias nuevas, migraciones, modelo Reporte, repositorio base ni escrituras de dominio.
- Reutilización concretada en REP-3: `ReporteCitasRequest` y `ReportePacientesRequest` comparten validación temporal/entrada cerrada en `ReportePeriodoRequest`; cada hijo mantiene su lista de filtros. Autorización/período comunes privados en `ReporteService` y paginación SQL privada en `ReporteRepository`, sin repositorio base genérico. `PacienteRegistradoResource` expone solo los seis campos definidos, sin reutilizar respuestas administrativas más amplias del módulo Pacientes.
- Rutas de reportes: `auth:sanctum`, `VerificarCuentaHabilitada`, `ExigirSesionNormal`, `ExigirRol:Administrador` mediante clases ya existentes y controles de origen/sesión vigentes. Revalidar actor/cuenta/sesión/rol en Service con el patrón de `AutenticacionService::exigirSesionNormal(bloquear: true)` dentro de la transacción de lectura, como agenda. Una lectura puede tomar locks de autorización; no cambia fichas, citas ni estados.
- Médico, Recepcionista y Paciente reciben 403 en CU-29 aunque tengan acceso a consultas operativas; sin sesión → 401. No ampliar `/api/agenda` ni `/api/mis-citas`. Mantener 419/429 según infraestructura; criterios inválidos → 422 `VALIDACION_ERROR`, fallo inesperado → 500 seguro. Contrato existente `{"success":false,"error":{"code","message","details?"}}`, sin SQL/trazas. Sin códigos nuevos mientras los vigentes sean suficientes.
- Para `agenda-diaria`, `ReporteService` autoriza **primero al Administrador** y delega en `AgendaService::consultar()` con `id_medico`, `fecha`, `estado`, `page`, `per_page` exclusivamente. No pasar metadatos/órdenes ni invocar un controller por otro. AgendaService ya revalida sesión, obtiene Médico existente incluso inactivo y entrega `pagina`/`contexto`.
- Concretado en REP-4: `ReporteAgendaRequest` exige fecha/Médico explícitos, `ReporteCantidadesRequest` admite solo fecha/rango y selecciones opcionales; ambos reutilizan `ReportePeriodoRequest`. `CantidadesCitasResource` limita el agregado a cuatro enteros sin paginación. Agenda conserva `consultado_en`, `medico` y `filtros` del Service original y añade `reporte`/`periodo`; su paginador genera enlaces en la URL administrativa, sin duplicar la consulta. Cantidades usa COUNT FILTER en una sentencia con parámetros; existencia de selecciones y estados efectivos se deciden en Service.
- Reutilizar `CitaListadoResource` en presentación y añadir contexto REP-1 sin quitar el de agenda. `AgendaRepository::citas()` ya aplica intersección diaria y orden fijo; `paginar()` obtiene página/count en una sola sentencia PostgreSQL. No duplicar esa consulta en ReporteRepository ni agregar un sumatorio paralelo. No cambiar firmas/proyección/contrato operativo si no hay necesidad comprobada.
- Selección de Médico: reutilizable `GET /api/agenda/medicos`, ya paginado y con inactivos; no crear otro catálogo para reportes. **Cotejado en REP-2:** `GET /api/especialidades`, exclusivo del Administrador, incluye activas/inactivas omitiendo `activa`; reutilizarlo sin cambiar su contrato. `GET /api/reservas/especialidades` sigue restringido a activas y no sirve como selector histórico. No se agregan endpoints ni permisos para resolver la selección. Los nombres mostrados son los actuales de entidades conservadas; no se promete reconstrucción de versiones antiguas de sus nombres.

## 7. Criterios de aceptación por fase

| Fase | Verificación dirigida |
| --- | --- |
| REP-2 | Verificada localmente: cuatro recursos de citas; rol y revocación posterior al middleware; filtros cerrados/IDs/inexistentes; Programada distinta de Confirmada; especialidad de Cita; fechas locales/bordes/microsegundos; entidades inactivas; orden/desempate/páginas vacías y página/total en una sentencia; proyección sin clínica; lectura sin mutaciones |
| REP-3 | Verificada localmente: creación de ficha distinta de alta/vínculo de cuenta, edición o incorporación de CI; pendientes sin ficha excluidos; fichas sin CI/cuenta e inactivas; filtro activo actual; una fila por ficha aunque tenga varias citas; registro/orden/nombres/bordes; privacidad, autorización y total coherente |
| REP-4 | Verificada localmente: ceros y cantidades terminales correctas, excluyendo Programada/Confirmada y eventos de transición; cancelación en fecha distinta del inicio, reprogramación y cambio de estado mediante fixtures; agregación en una sentencia; agenda equivalente a CU-20 por intersección/estado/orden/datos históricos, delegación única y permiso adicional exclusivo de Administrador |
| REP-5 | Verificada localmente: integración de los siete recursos con altas/reservas/transiciones/reprogramación/edición de ficha por API, contratos/error/paginación/fechas, vacíos, roles, logout/nuevo login y ausencia de escrituras de dominio al leer; commits PostgreSQL reales en esquemas propios aislados, sin migrar base habitual ni servicios externos reales |

**Cierre REP-1:** fuentes y código contrastados estáticamente; sin implementación, consultas a BD ni ejecución de pruebas. Este contrato resuelve la elección técnica de fechas; no certifica eventos históricos por fecha real ni incorpora exportación. Pendientes: implementar/verificar cada fase bajo autorización, cotejar selector histórico de especialidades y mantener el cierre de CU-28 intacto. No iniciar REP-2 automáticamente.

**Cierre REP-2 (posterior al cierre REP-1 anterior):** `ReportesCitasTest` **57 pruebas/831 aserciones** correctas tras corregir una regla de validación no disponible en Laravel y dos expectativas/fixtures de pruebas; `AgendaTest` + identidad SQL de `DatabaseInfrastructureTest`: **44 casos correctos en la primera batería**, no repetidos ni sumados como una sola ejecución exitosa. Kernel Laravel, cookies/CSRF/sesiones y PostgreSQL reales mediante el runner, fixtures ficticios/esquemas transaccionales propios con inventario de namespaces conservado. Sin HTTP externo, servidor React→Laravel ni nueva prueba de concurrencia multiproceso: revocación intermedia simulada y garantía de snapshot comprobada por estructura de sentencia SQL. Autoload y Pint dirigidos correctos. Evidencias: `Temp/opencode/reportes-rep2-{tests.log,correccion-tests.log,pint.log,autoload.log}`. Sin frontend, exportación, dependencias, cambios de persistencia o migración habitual. REP-3 no iniciada; requisitos/diagramas/análisis y cierre CU-28 conservados.

**Cierre REP-3 (posterior):** `GET /api/reportes/pacientes/registrados` implementado conforme al contrato, sin cambios funcionales a los cuatro reportes de citas. Batería única de `ReportePacientesRegistradosTest` + `ReportesCitasTest`: **97 casos/1358 aserciones, con un fallo de aserción SQL** (96 casos correctos). Se comprobaba ausencia de todo JOIN en vez de ausencia de joins de dominio; se corrigió la aserción sin cambiar producto y se reejecutó solo ese caso: **1 prueba/20 aserciones correctas**. Los 40 casos de pacientes y 57 de citas quedan cubiertos entre ambas ejecuciones, sin sumar repetidos ni atribuir una ejecución completa exitosa inexistente. Misma base/rol de pruebas comprobados antes de crear esquemas transaccionales, rollback y namespaces conservados; correo simulado, sin envíos reales. No pruebas nuevas multiproceso ni HTTP externo. Autoload sin scripts, Pint, sintaxis y diff dirigidos correctos. Logs `Temp/opencode/reportes-rep3-{tests.log,correccion-tests.log,pint.log,autoload.log,lint.log}`. Sin migración habitual, nuevas dependencias, frontend, exportación o cambios en análisis/diagramas/CU-28. REP-4 no iniciada.

**Cierre REP-4 (posterior):** ambos recursos pendientes implementados; AgendaService/AgendaRepository/CitaListadoResource operativos intactos. `ReportesCantidadesAgendaTest` (56 casos), `ReportesCitasTest` (57), `ReportePacientesRegistradosTest` (40) y `AgendaTest` (43) en **una batería dirigida: 196 pruebas/2579 aserciones correctas**, 55,070 s/118 MB. Fixtures SQL de cambios/reprogramaciones explícitas, equivalencia de agenda por los cinco estados, bordes/microsegundos/zona de conexión, agregado único, totales vacíos, IDs bigint, privacidad/solo lectura y denegaciones/revocación comprobados. Base/rol aislados verificados, esquemas transaccionales propios/namespaces conservados; sin envío de correo, HTTP externo ni nueva concurrencia multiproceso. Autoload sin scripts/Pint/sintaxis/diff dirigidos correctos. Logs `Temp/opencode/reportes-rep4-{tests.log,autoload.log,pint.log,lint.log}`. No sumar estos 196 a ejecuciones previas ni confundirlos con integración/cierre REP-5. Sin nuevas dependencias, migraciones, frontend/exportación, análisis/diagramas o cambios al cierre CU-28. REP-5 no iniciada.

**Cierre REP-5 (posterior; vigente):** nuevo `ReportesIntegracionTest`, cuatro recorridos reales mediante APIs/kernel Laravel: creación de ficha sin CI/cuenta y reserva → siete reportes → cambios de estado y reconciliación → logout/401/nuevo login; reprogramación a otro día/cancelación fuera del día de inicio; CI/inactivación posterior sin cambiar referencia de registro; vacíos/errores seguros. Rol Administrador exclusivo en los siete recursos y consultas operativas de los otros tres roles conservadas. Sin sustituir Services/Repositories de negocio; identidades activadas/catálogos/horarios/ficha antigua son fixtures, no acreditación de CU-04 ni invitación de personal.

**Evidencia REP-5:** una dirigida `php -d memory_limit=384M vendor/bin/phpunit tests/Feature/ReportesIntegracionTest.php --colors=never` → **4 pruebas/1536 aserciones correctas**, 2,741 s/56 MB. Commits PostgreSQL reales sin transacción exterior, base/rol aislados comprobados antes de escribir, esquemas exclusivos y limpieza propia con namespaces conservados. Snapshots de tablas de dominio/bitácora comprueban solo lectura; cookies/sesiones/CSRF reales en el runner, no servidor HTTP independiente. Mail fake sin envíos/colas, adaptador push prohibido y cero peticiones externas. Autoload sin scripts/Pint/sintaxis/diff dirigidos correctos; logs `Temp/opencode/reportes-rep5-{tests.log,autoload.log,pint.log,lint.log}`. No repetir ni sumar la evidencia 196/2579 de REP-4: no hubo cambios funcionales que justificaran otra regresión amplia. Sin suites globales, migración habitual, dependencias o cambios de análisis/diagramas/CU-28.

**Límite del cierre:** backend REP-1–REP-5 cerrado proporcionalmente en local, no todo CU-29. Pendientes frontend, integración HTTP React→Laravel/Sanctum en navegador, aceptación visual/accesible y evaluación de destino/producción. No nueva prueba multiproceso ni nueva garantía de snapshot entre peticiones. Exportación sigue opcional/excluida; otros módulos y pendientes CU-28 no se inician ni reabren automáticamente.

## 8. Exportación PDF — Fase 1 (2026-11-07)

**Ampliación opcional solicitada, no requisito nuevo del examen.** Siete GET aditivos: `/api/reportes/citas/programadas/pdf`, `/citas/por-medico/pdf`, `/citas/por-especialidad/pdf`, `/citas/por-estado/pdf`, `/pacientes/registrados/pdf`, `/citas/cantidades/pdf` y `/agenda-diaria/pdf` (todos bajo `/api/reportes`). Sin React, Pagos/recibos, migraciones ni dependencias nuevas.

- Entrada: mismos filtros obligatorios/opcionales y órdenes del recurso JSON (§2–4), con el límite temporal de 366 días conservado. **No admite `page` ni `per_page`**, ni cuerpos, formatos, filtros desconocidos o no aplicables: 422 `VALIDACION_ERROR`. El cliente futuro enviará los filtros aplicados sin paginación; no hay token de snapshot ni requisito de consultar JSON previamente.
- Éxito: **200 `application/pdf`**, `Content-Disposition: attachment; filename="reporte-{recurso-con-guiones}-{YYYYmmdd-HHMMSS}.pdf"`, fecha local de generación, solo ASCII sin nombres/IDs personales; `Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff`, `Content-Length` de los bytes. Errores JSON centralizados con no-store/private, no binarios disfrazados. Recurso inexistente 404, sesión ausente/revocada 401, rol/sesión restringida 403, validación 422, límite de frecuencia 429 con `Retry-After`, fallo inesperado 500 seguro.
- **Máximo 500 filas** por listado, inclusive. `ReporteExportacionService` llama al mismo `ReporteService` con página interna 1 y tamaño 501; no amplía el máximo 100 del contrato HTTP JSON. `ReporteRepository`/`AgendaRepository` ya obtienen filas limitadas y total completo en **una sentencia SQL**, incluidos vacíos; no concatena páginas HTTP ni duplica consultas/criterios. Se conserva la lectura coherente de filas y total, no un snapshot entre peticiones. La agregación de cantidades sigue completa en una sentencia, **aunque cuente más de 500 citas**: no materializa cada cita ni exporta su detalle.
- Si el total del listado supera 500: **422 `VALIDACION_ERROR`**, `error.details.exportacion` contiene «El reporte supera el límite de 500 filas. Acota los filtros y vuelve a exportar. No se generó un PDF parcial.» No invoca Dompdf ni devuelve una parte de los resultados. Se limita la transferencia a PHP, no el trabajo SQL de filtrar/contar: rendimiento en volúmenes de producción no certificado.
- Límites adicionales: HTML máximo **2 MiB** antes del render, PDF máximo **8 MiB** antes de entregar. Exceso → 422 con `details.exportacion`, orientación a acotar filtros, sin truncamiento ni archivo parcial. No son garantías de tiempo/memoria del proceso ni sustituyen los límites operativos del servidor. Frecuencia compartida de **6 solicitudes/minuto por usuario** para los siete endpoints PDF; validaciones que alcanzan ese middleware consumen cuota. No se amplía el permiso de ningún otro rol.
- Vacío válido: **200 PDF**, período/filtros conservados, «Sin resultados para los filtros aplicados», total cero; cantidades muestra además sus cuatro enteros en cero. Sin 404 por ausencia de filas.
- Formato: **A4 horizontal**, DejaVu Sans, tablas de ancho fijo, texto ajustado, cabecera de columnas repetida, filas sin división deliberada y pie «Página X de Y». Clínica ficticia/proyecto universitario, título específico, período inclusivo, filtros efectivos (incluidos estados implícitos/orden resuelto), hora/offset y zona `America/La_Paz`, referencia temporal/criterio, resultados y total del conjunto completo. Cantidades incluye los tres estados y su suma; agenda informa intersección diaria/no sumabilidad y Médico seleccionado. Las horas de filas se presentan en zona de sede; las respuestas JSON siguen UTC6 sin cambios.
- PDF administrativo mínimo: citas/agenda muestran código de cita, inicio/fin, nombre de Paciente y Médico, especialidad, estado/duración y sede/consultorio históricos; pacientes muestra código, nombre, actividad y registro. Sin CI, contactos, nacimiento, motivo clínico, diagnósticos, antecedentes ni importes financieros. No serializa Models completos. Los nombres de entidades conservan su valor actual, no una versión histórica inventada.
- Generación con **nueva lectura del servidor**, indicada en el propio PDF: puede diferir de la consulta previa en pantalla y no reconstruye cortes históricos. No persiste un informe ni crea enlaces públicos/historiales de archivos. El momento de generación no es una firma ni un token de snapshot.
- Seguridad: mismos middlewares `auth:sanctum`/cuenta habilitada/sesión normal/Administrador, controles SPA existentes y autorización bloqueada en Service al consultar. **Nueva revalidación de sesión/cuenta/rol después del render y antes de devolver los bytes** mediante `ReporteService::autorizarEntrega()`. No se promete retirar bytes ya enviados ni eliminar la carrera posterior a la última comprobación.
- `ReportePdfController` solo valida/delega/arma HTTP; `ReporteExportacionService` coordina selección/límites/autorización; `ReportePdf` compone texto explícito escapado y usa Dompdf **3.1.6 instalado** (Laravel13.37.0/PHP8.5.11 comprobados). Sin acoplamiento al renderer de recibos. Recursos remotos, PHP y JavaScript deshabilitados; protocolos de recursos vacíos, chroot/cache locales. Firma `%PDF-` y cierre `%%EOF` son controles básicos, **no equivalen a validación estructural ni inspección visual**; resultados efectivamente ejecutados en el relevo.

## 9. Consumidor React — Fase 2 (2026-11-07)

Sin alterar §8/backend: `ReportesPage` monta `DescargarReportePdf` solo con consulta válida (también vacía), ligada al tipo y filtros aplicados; el borrador no cambia la exportación. `reportePdf.service.js::descargarReportePdf()` selecciona los siete recursos cerrados, omite `page`/`per_page` y opcionales vacíos; GET Axios compartido/`sessionBound`/época esperada, `responseType: blob`, cookies existentes y timeout120s sin reintento automático. No tokens ni archivos en web storage.

Errores JSON binarios se leen hasta64KiB, mensajes seguros por estado401/403/419/422/429/500; `VALIDACION_ERROR/details.exportacion` conserva orientación del servidor sin ofrecer parcial. 401 conserva invalidación central de sesión;419 pide comprobarla sin cerrar por suposición;429 espera `Retry-After` (segundos o fecha, alternativa60s). Éxito exige MIME `application/pdf`, Blob no vacío/≤8MiB, firma y cierre básicos; no certifica estructura completa. Solo nombre ASCII del patrón/recurso §8; alternativa fija sin datos personales. El enlace temporal se retira y la URL se revoca tras1s o al desmontar/cambiar sesión.

Interfaz informa nueva lectura/diferencias, límite y agregado completo; muestra carga/cancelar espera/error sin reemplazar tablas/totales. Cancelación/guardas tras esperas y época compartida descartan respuestas obsoletas; una consulta nueva/actualización/página retira el componente anterior, y tipo/limpieza/identidad/rol/estado/sesión/salida también lo invalidan. Cancelar no garantiza detener el trabajo servidor. Pruebas React con transporte simulado y revisión de CSS/teclado jsdom: evidencia/limitaciones en `estado-fase.md`. **Fase 3 integrada no iniciada ni acreditada.**

## 10. Aceptación integrada local — Fase 3 (2026-11-07)

La nota F2 anterior conserva su alcance histórico. **F3 aceptada localmente**, sin cambiar §§8–9 ni código funcional: siete reportes/siete vacíos descargados por Chrome desde React/Axios/Laravel/PostgreSQL aislado y abiertos mediante Windows.Data.Pdf; filtros/totales/orden/página2/borrador,500 inclusive/501 sin parcial, agregado504, permisos y sesión contrastados. qpdf valida21 descargados,19 útiles para aceptación; muestras visuales1440/390px y PDF multipágina examinadas.429 real y espera;419/500/PDF inválido mediante inyección CDP explícita. En dos orígenes se utiliza el nombre alternativo seguro de §9 al no exponerse Content-Disposition por CORS. [Matriz, evidencia, incidencias del arnés/revalidaciones y límites](cu29-aceptacion-pdf.md). No certificación productiva, otros lectores ni accesibilidad PDF.

## 11. EST-1 — contrato estadístico previsto (2026-11-07)

**Ampliación opcional aprobada vinculada a RF13/CU-29, no texto oficial del examen.** Alcance C: resumen, tablas estadísticas y gráficos útiles en pantalla y PDF. Especificación para EST-2–EST-5, no respuesta actual del servidor. Prevalece para esta ampliación sobre exclusiones históricas de estadísticas/gráficos; conserva §§2–6 y §§8–10 en todo lo no ampliado. No modifica RF/RN/CU, persistencia, permisos, filtros ni endpoints. Solo Administrador.

### 11.1. Forma común y tipos cerrados

Los siete GET JSON añadirán **`meta.estadisticas`**, sin sustituir `data`, `links`, contexto, paginación, `meta.total` ni las cuatro cantidades existentes. El PDF consumirá el mismo objeto del resultado interno del Service, sin otra consulta para estadísticas. No se añade a `/api/agenda`, `/api/mis-citas` u otros contratos operativos.

| Clave obligatoria de `estadisticas` | Tipo y significado exactos |
| --- | --- |
| `version` | Entero literal `1`, versión del bloque, no parámetro HTTP. |
| `unidad` | Literal `citas` o `fichas`; unidad de todos los campos `cantidad`/`cantidad_total` del bloque. |
| `estados_incluidos` | Array de estados efectivos en orden canónico; `[]` para fichas. No depende de que existan filas. |
| `resumen` | Objeto con exactamente `cantidad_total` (entero ≥0) y `duracion_total_minutos` (entero ≥0 solo por Médico/agenda; `null` en los otros cinco reportes). |
| `series` | Array ordenado según §11.2; siempre presente, incluso sin resultados. |

Cada serie contiene exactamente `id`, `dimension`, `agrupacion`, `porcentajes`, `grafico`, `categorias`:

| Clave de serie | Tipo y regla |
| --- | --- |
| `id` / `dimension` | Pares cerrados: `por_medico`/`medico`, `por_estado`/`estado`, `por_actividad`/`actividad`, `por_periodo`/`periodo`. No repetir id dentro de `series`. |
| `agrupacion` | `diaria` o `mensual` exclusivamente en dimensión `periodo`; `null` en las demás. |
| `porcentajes` | Solo cantidades: `{ "denominador": entero≥0, "decimales": 1, "redondeo": "HALF_UP" }`. `null` para toda otra serie. |
| `grafico` | `{ "tipo": "barras_horizontales", "visible": booleano, "motivo_omision": null o literal, "maximo": entero≥0 }`, determinado en Laravel según §13. |
| `categorias` | Array completo, sin top10, «Otros», paginación estadística ni dependencia del orden del detalle. |

Cada categoría tiene las cinco claves comunes **`id` (string), `etiqueta` (string), `cantidad` (entero≥0), `duracion_minutos` (entero≥0 o null), `porcentaje` (string decimal o null)** y solo la clave adicional de su dimensión:

| Dimensión | Identidad y clave adicional obligatoria |
| --- | --- |
| `medico` | `id = "medico:" + id_medico`; `medico: {id_medico,codigo_medico,nombres,apellidos}` con cuatro strings. Etiqueta exacta: `apellidos + ", " + nombres + " — " + codigo_medico`. IDs bigint canónicos como strings; nunca agrupar por nombre. |
| `estado` | `id = "estado:" + estado`; `estado` string del catálogo de cinco. `etiqueta = estado`. |
| `actividad` | `id = "actividad:activo"` o `"actividad:inactivo"`; `activo` booleano; etiquetas `Activos`/`Inactivos`. Actividad actual de la ficha. |
| `periodo` | `id = "dia:YYYY-MM-DD"` o `"mes:YYYY-MM"`; `intervalo: {desde,hasta,inicio_at,fin_exclusivo_at,parcial}`. Fechas civiles strings YYYY-MM-DD, timestamps UTC6, `parcial` booleano. Etiqueta diaria YYYY-MM-DD; mensual YYYY-MM, añadiendo ` (parcial)` cuando corresponda. |

`duracion_minutos` solo es entero en categorías de estado de por Médico/agenda; en toda otra categoría es `null`. `porcentaje` solo es decimal en cantidades con denominador positivo; en los demás casos es `null`. No omitir claves comunes ni sustituir ceros por null. Campos de dimensión distinta están ausentes, no llenos de null. Strings se escapan en presentación; no son HTML autorizado.

Enteros JSON de cantidades/minutos no negativos y representables exactamente en JavaScript (≤9007199254740991); IDs siempre strings. Laravel valida conversiones sin redondear/desbordar: resultado no representable → fallo interno seguro, nunca float aproximado, truncamiento o éxito parcial. No es un nuevo filtro ni un límite de500 para estadísticas JSON.

### 11.2. Siete variantes y conciliación

Orden canónico: `Programada`, `Confirmada`, `Atendida`, `Cancelada`, `Inasistencia`. Sin filtro de estado se incluyen los cinco excepto en programadas/cantidades. Un filtro selecciona solo ese estado efectivo; las categorías de estado mantienen sus cinco filas, las excluidas con cero explícito. No implican estados adicionales incluidos en el conjunto.

| `meta.reporte` | Unidad; resumen | `estados_incluidos`; series en orden exacto |
| --- | --- | --- |
| `citas/programadas` | citas; N, null | `[Programada]`; `por_medico` con cantidades, sin minutos ni porcentajes. Solo Programada, nunca Confirmada. |
| `citas/por-medico` | citas; N, D | Cinco estados o el seleccionado; `por_estado` con cantidad/minutos de cada uno de los cinco estados. |
| `citas/por-especialidad` | citas; N, null | Cinco estados o el seleccionado; `por_medico` con cantidades. Selección por `citas.id_especialidad`, no asociación vigente del Médico. |
| `citas/por-estado` | citas; N, null | Solo estado elegido; `por_periodo` con cantidad por inicio programado. |
| `pacientes/registrados` | fichas; P, null | `[]`; primero `por_actividad` (dos categorías), después `por_periodo` por creación de ficha. |
| `citas/cantidades` | citas; N finales, null | `[Atendida,Cancelada,Inasistencia]`; `por_estado` con solo esas tres categorías, cantidades/porcentajes, sin minutos. |
| `agenda-diaria` | citas; N, D | Cinco estados o el seleccionado; `por_estado` con cantidad/minutos de los cinco estados. Intersección diaria intacta. |

- N/P = COUNT de todas las filas filtradas; D = SUM de `citas.duracion_minutos` histórica completa, COALESCE a0. No duración actual del horario ni diferencia de horas redondeada. D incluye Cancelada/Inasistencia si están en el conjunto; no representa trabajo efectivo, ocupación ni tiempo atendido. En agenda no se recorta a medianoche; una cita puede aportar duración completa a dos días: agendas no se suman como citas/minutos únicos.
- `resumen.cantidad_total = meta.total` en listados; en cantidades = `data.total = atendidas + canceladas + inasistencias`. Cada serie particiona el mismo conjunto: suma de cantidades = resumen; por Médico/agenda, suma de minutos por estado = D. En pacientes cada serie suma P, **no sumar ambas series entre sí**. Nada se calcula desde la página visible.
- Médicos: solo IDs con al menos una cita filtrada; vacío → `categorias: []`. No introducir todos los Médicos del catálogo ni categoría ficticia para Médico seleccionado sin resultados. Orden `cantidad DESC, id_medico ASC` numérico PostgreSQL; no desempatar por nombres ni convertir IDs a Number. Nombres/códigos actuales, no reconstrucción histórica; homónimos conservan identidades distintas.
- Estados: orden canónico anterior (o sus tres finales); incluir ceros, incluso al filtrar otro estado. Actividad: siempre Activos, Inactivos, incluso si `activo` excluye uno. Períodos: cronológicos ascendentes, independiente del orden del detalle. IDs únicos; no desempates temporales adicionales.
- Vacío real: total/cantidades/minutos aplicables0; series de estado/actividad/período presentes con ceros, Médicos vacíos; ningún gráfico. Página fuera de rango con total>0: `data: []`, conserva **todas** las estadísticas reales; mostrar «Esta página no contiene filas» separado de «Sin resultados». No inferir vacío estadístico de `data.length`.
- Sin pacientes distintos, promedios, tasas asistenciales, ocupación, disponibilidad, comparativas ni indicadores financieros.

### 11.3. Períodos, intervalos vacíos y meses parciales

Reutilizar el período aplicado de §3, máximo366 días civiles inclusivos y zona America/La_Paz. Sin query/control de agrupación nuevo. Días inclusivos entre `desde` y `hasta`:1–31 → `diaria`;32–366 → `mensual`, incluso con pocos datos.

- Diario: una categoría por día civil, `desde = hasta = día`, `parcial: false`; UTC desde medianoche local hasta la siguiente. Mensual: una por mes civil intersectado (pueden ser13 para366 días); `desde = max(inicio_mes, desde_aplicado)`, `hasta = min(fin_mes, hasta_aplicado)`. `parcial: true` si no cubre el mes civil entero; ambos extremos pueden ser parciales. Mes interior completo es false.
- Ambos: `[inicio_at, fin_exclusivo_at)` a partir de extremos civiles recortados, convirtiendo medianoches locales a UTC6; no truncar por fecha UTC. Intervalos adyacentes sin huecos/solapamientos, primera frontera = `meta.periodo.inicio_at`, última = `meta.periodo.fin_exclusivo_at`. Incluir intervalos vacíos con0; no generar fuera del rango. Bisiestos/cambios de año sujetos a validación existente.
- Referencia: `citas.inicio_at` para por estado; `pacientes.creado_en` para fichas. No transición de estado, creación de Usuario, incorporación de CI ni vinculación. Cambios posteriores pueden cambiar resultados: no snapshot entre páginas/peticiones/PDF.
- Ejemplo32 días:2026-01-20 a2026-02-20 → enero20–31 y febrero1–20, ambos parciales; primer intervalo UTC `[2026-01-20T04:00:00.000000Z,2026-02-01T04:00:00.000000Z)`.

### 11.4. Porcentajes de cantidades

Único denominador = total de los tres estados finales, aunque existan Programadas/Confirmadas. Laravel calcula `100 × cantidad / denominador`, HALF_UP a un decimal. Serializar string ASCII con un decimal, sin símbolo: `"0.0"`…`"100.0"`; presentación con coma y `%` (`33,3 %`). No recalcular en React ni renderer. Cálculo racional entero PHP64: cociente/resto de `1000*cantidad / total`, aumentar cociente si `2*resto >= total` y formatear décimas sin float; el límite de §11.1 permite multiplicar por1000 sin desbordar PHP64. Conteos/sumas no tienen denominador.

Denominador0: descriptor presente con `denominador: 0`, tres `porcentaje: null`, mostrar **«No aplica»**, no0%, NaN ni infinito. Denominador positivo/categoría0 → `"0.0"`. Con total positivo mostrar siempre: «Los porcentajes pueden sumar 99,9 % o 100,1 % por redondeo». No ajustar la última categoría para forzar100%; nunca llamarlos tasas de atención/cancelación.

## 12. Lectura coherente — diseño, no implementación

**Contraste estático EST-1:** `ReporteRepository::paginar()` y `AgendaRepository::paginar()` ya usan `WITH filtradas AS MATERIALIZED`, página limitada y COUNT en una sentencia con LEFT JOIN que conserva total sin página. `cantidades()` usa una agregación COUNT FILTER. Los Services envuelven autorización/selecciones/consulta en `DB::transaction`, lo que **no garantiza** igual snapshot para nuevas sentencias separadas bajo READ COMMITTED. No se atribuye otro aislamiento a la configuración real ni se consulta PostgreSQL en EST-1.

**Diseño elegido para EST-2:** mantener una sola sentencia de datos por reporte, alimentando desde la misma CTE filtrada: página con orden/límite, resumen y grupos completos. Devolver un sobre SQL de **una fila**, con total, agregados y array JSON de filas de página (agregación ordenada y COALESCE `[]`). Grupos/total son subconsultas o CTE agregadas, no JOIN de grupos sin agregar contra detalle que multiplique filas. Producir fila exterior aun sin resultados; no confiar solo en COUNT OVER, que desaparece en página fuera de rango. Filtros como bindings; órdenes/dimensiones constantes cerradas.

Reutilizar builders actuales de filtros/proyección, extrayendo métodos privados si hace falta; no replicarlos en otro módulo. Grupos SQL sobre todas las filtradas; Laravel genera intervalos desde el período aplicado y completa ceros al mapear grupos de esa misma lectura. No cargar listado completo en PHP para agrupar. IDs dentro de JSON SQL casteados a texto antes de codificar; decodificar sin convertir bigint a float. Reconstruir paginador desde filas limitadas/total del sobre, sin campos auxiliares en Resources.

`ReporteService` construirá `contexto.estadisticas` desde agregados internos: normalización de enteros, ceros, orden, intervalos, porcentaje exacto y metadatos gráficos. Controller mantiene HTTP y `additional(['meta' => contexto])`; no calcula indicadores. Cantidades conserva exactamente cuatro enteros en `data`, deriva resumen/porcentajes desde su agregación única, sin segunda lectura.

### 12.1. Agenda reutilizada sin alterar otros roles

No consultar agenda dos veces ni reproducir intersección/proyección en ReporteRepository. Entrada **interna nueva prevista** `AgendaService::consultarParaReporte(array $filtros): array`, exclusiva Administrador, comparte con `consultar()` un núcleo privado de resolución/autorización/contexto. `ReporteService::agendaDiaria()` mantiene autorización administrativa previa y delega a esa entrada. `AgendaService::consultar()` conserva permisos/firma/respuesta HTTP operativa.

`AgendaRepository` extraerá de `citas()` el builder privado común de selección/intersección/estado y añadirá `citasConEstadisticas()` para el sobre página+agregados en una sentencia, usando ese builder. `citas()`/`paginar()` operativos siguen entregando LengthAwarePaginator sin estadísticas ni costo agregado nuevo para Recepción/Médico. Dos ramas con criterios compartidos, no dos copias de la consulta de agenda. Sin flag de estadísticas HTTP ni confianza en `reporte`/rol del cliente. Probar equivalencia del conjunto y que no afecta historial/finanzas de AgendaRepository.

La transacción conserva locks actuales de autorización; no cambiar aislamiento ni abrir REPEATABLE READ global para resolver agregados. Garantía de snapshot: **filas/total/estadísticas de la sentencia de datos**; no todas las consultas auxiliares de existencia/contexto, nombres del selector ni otra petición. Sin token de snapshot, congelación ni retiro de bytes enviados. PDF reutiliza mismo resultado interno y autorización final independiente después del render.

### 12.2. Archivos y responsabilidades previstos para fases posteriores

Rutas relativas al repositorio; no autoriza implementación en EST-1:

| Archivo(s) | Cambio posterior previsto |
| --- | --- |
| `backend/src/data/repositories/reportes/ReporteRepository.php` | Reutilizar builders/ampliar lectura única de citas/fichas a página+total+grupos; cantidades mantiene agregado único. Retorno interno de listados con paginador/agregados, sin alterar HTTP. |
| `backend/src/business/services/reportes/ReporteService.php` | Mapear agregados al contrato en contexto, porcentajes/utilidad gráfica; delegación nueva de agenda. Mantener autorización/filtros/período/autorizarEntrega. |
| `backend/src/business/services/agenda/AgendaService.php`, `backend/src/data/repositories/agenda/AgendaRepository.php` | Entrada interna administrativa y núcleo/builder compartidos de §12.1. Sin finanzas, permisos nuevos ni cambios a historial. |
| `backend/src/presentation/controllers/reportes/ReporteController.php`, `backend/src/presentation/dtos/{agenda/CitaListadoResource,reportes/PacienteRegistradoResource,reportes/CantidadesCitasResource}.php` | Revisar meta sin ampliar data: `additional` actual puede transportar contexto nuevo sin cambios productivos aquí. No serializar agregados como filas. |
| `backend/src/business/services/reportes/ReporteExportacionService.php`, `backend/src/shared/utils/ReportePdf.php` | Consumir estadísticas de misma lectura/representar resumen/tablas/barras; conservar límites/autorización final. |
| `frontend/src/features/reportes/services/reporte.service.js` | Validar siete variantes/invariantes y conservar `meta.estadisticas`: hoy reconstruye meta y lo descartaría. No sumar página para construirlo. |
| `frontend/src/features/reportes/pages/ReportesPage.jsx`, `components/`, `styles/reportes.css` de la misma feature | Presentación compartida antes del detalle, ligada a filtros aplicados/query; componentes nuevos solo dentro de feature si necesarios, sin módulos/dependencias nuevos. |
| `frontend/src/features/reportes/components/DescargarReportePdf.jsx`, `services/reportePdf.service.js` | Conservar descarga/avisos/guardas; revisar regresión sin necesidad prevista de cambiar contrato. |
| `backend/tests/Feature/{ReportesCitasTest,ReportePacientesRegistradosTest,ReportesCantidadesAgendaTest,AgendaTest,ReportesPdfTest,ReportesIntegracionTest}.php`, `frontend/src/features/reportes/tests/` | Pruebas dirigidas posteriores según matriz; no se crean/ejecutan en EST-1. |

No nuevas clases de dominio, modelos, tablas, migraciones, repositorio base ni motor genérico. Sin cambios previstos a FormRequests, rutas, autenticación, dependencias, configuración privada, Pagos/recibos ni DER.

## 13. Representación equivalente React/PDF — prevista

Laravel calcula indicadores, porcentajes, intervalos y utilidad del gráfico. React valida/representa y solo calcula geometría; renderer tampoco agrega filas. Orden: **resumen → gráfico útil y tabla estadística completa → detalle existente**. Fichas: actividad y luego períodos según `series`. Conservar totales/paginación/descarga/columnas, sin segundo total independiente ni sustituir detalle por dashboard.

- `grafico.maximo = max(categorias.cantidad)` o0 sin categorías. `visible = true` solo con al menos dos categorías de cantidad>0; entonces `motivo_omision: null`. Ninguna positiva: false/`sin_datos`; exactamente una: false/`unica_categoria_con_datos`. Conservar resumen/tabla en ambos casos, incluso con muchos intervalos y solo uno positivo. No eliminar categorías0 del contrato/tabla ni topN; con gráfico conservar todas en orden, longitud0 para ceros.
- Barras horizontales de **cantidades**, origen0 y escala común0–maximo por serie, no escala porcentual de composición ni barras de minutos. Longitud = cantidad/maximo; sin bases recortadas, mínimos decorativos o división por0. Etiqueta/cantidad visibles fuera de barra; eje/leyenda con unidad y extremos0/maximo. Identidad explícita y sin depender de color/tooltips. Minutos/porcentajes aplicables en resumen/tabla, sin cambiar tipo de barra.
- React: sección titulada, tabla con caption/encabezados/unidades; gráfico visual `aria-hidden` si duplica íntegramente tabla. Texto alternativo «Cantidades por …; datos completos en la tabla siguiente», sin anuncios redundantes por barra. Etiquetas ajustables, códigos de Médico para homónimos y valores sin superponer. Móvil: etiquetas sobre pista/valor si falta ancho; tabla completa en contenedor desplazable etiquetado/accesible por teclado solo si desborda. Escritorio sin recortar etiquetas. Sin interacción nueva que exija ratón; no prometer lector de pantalla/PDF accesible sin comprobar.
- Vincular a `query.data.meta.estadisticas`/filtros aplicados, **no borrador ni selectores actuales**. Consulta/actualización/página/sesión nuevas usan carga/error/cancelación/obsolescencia existente; no agregados antiguos con filas nuevas. Respuesta inválida → error seguro, sin gráfico fabricado. No polling. Resumen independiente de condición del componente de detalle para conservar estadísticas en página fuera de rango.

### 13.1. Dompdf sin dependencias ni ejecución React

Mecanismo: HTML estático escapado de `ReportePdf`, **una fila por categoría**, celdas para etiqueta/pista/valor. Pista de ancho fijo en pt, idéntico por serie; dentro, tabla anidada ancho100%/`table-layout: fixed`, dos celdas sin bordes/padding con anchos `100*cantidad/maximo` y complemento, relleno solo en primera. Cantidad0: pista vacía, sin celda coloreada mínima; máximo: relleno completo, sin celda restante. Anchos numéricos internos (punto decimal/cuatro decimales porcentuales), nunca CSS HTTP. Son porcentajes **geométricos**, no indicadores nuevos. Etiqueta/valor fuera de pista y origen/máximo/unidad visibles. Sin flex/grid, canvas, SVG externo, imágenes remotas, scripts ni librerías gráficas.

Separar CSS de barras del detalle (renderer actual aplica bordes/padding globales), sin deformar proporciones. No envolver gráfico/sección completos en fila indivisible: permitir saltos entre categorías, encabezados repetidos y filas sin división deliberada; tabla estadística completa después. A4 horizontal, fuente DejaVu Sans local, márgenes/pie vigentes. Etiquetas ajustables; muchas categorías continúan en páginas siguientes, sin corte silencioso. Tabla estadística incluye ceros, minutos/porcentajes aplicables y extremos/meses parciales textuales. Equivalencia de contenido, no identidad de píxeles React/PDF.

Compatibilidad **diseñada, no renderizada en EST-1**: lockfile fija Dompdf3.1.6; renderer real usa HTML/tablas locales sin red/PHP/JS. EST-4 comprobará barras, proporciones, ceros y saltos con renderer real/muestras visuales; firma PDF no basta. Ante incompatibilidad que requiera cambiar alcance/restricciones, detener y solicitar decisión, sin eliminar indicadores/tablas/gráficos útiles ni instalar dependencias unilateralmente.

Conservar500 filas inclusive por listado (lectura interna1/501), HTML2MiB antes de render y PDF8MiB antes de entrega; límites de bytes incluyen **estadísticas/barras/tablas**. Exceso422 `details.exportacion`, sin documento parcial/topN/degradación silenciosa a solo detalle o tabla. Cantidades cuenta cualquier número de citas sin materializar detalle. Alcance compatible por diseño con rechazo por exceso: no promete que todo conjunto≤500 quepa bajo límites de bytes. Médicos de un listado exportable≤500; fechas≤366 generan a lo sumo31 días o13 meses. Sin ampliar límites por gráficos.

Nueva lectura PDF respecto de pantalla, aviso de diferencias y generación conservados. Documento íntegro desde mismo sobre SQL; no consultar durante render. `autorizarEntrega()` después de Dompdf/antes de bytes, throttle6/min, errores privados/no-store y seguridad de recursos vigentes. Sin persistir informes ni tocar recibos.

## 14. Ejemplos JSON exclusivamente ilustrativos

**No son respuestas capturadas, fixtures ejecutadas ni evidencia de pruebas.** Bloques completos `meta.estadisticas`; resto de respuesta según §§5–6. Filtros/períodos mencionados son contexto explicativo externo.

### 14.1. Cantidades: una cita por cada estado final

`data` existente sería `{ "atendidas": 1, "canceladas": 1, "inasistencias": 1, "total": 3 }`. Programada/Confirmada fuera del denominador.

```json
{
  "version": 1, "unidad": "citas", "estados_incluidos": ["Atendida", "Cancelada", "Inasistencia"],
  "resumen": {"cantidad_total": 3, "duracion_total_minutos": null},
  "series": [{
    "id": "por_estado", "dimension": "estado", "agrupacion": null,
    "porcentajes": {"denominador": 3, "decimales": 1, "redondeo": "HALF_UP"},
    "grafico": {"tipo": "barras_horizontales", "visible": true, "motivo_omision": null, "maximo": 1},
    "categorias": [
      {"id": "estado:Atendida", "etiqueta": "Atendida", "cantidad": 1, "duracion_minutos": null, "porcentaje": "33.3", "estado": "Atendida"},
      {"id": "estado:Cancelada", "etiqueta": "Cancelada", "cantidad": 1, "duracion_minutos": null, "porcentaje": "33.3", "estado": "Cancelada"},
      {"id": "estado:Inasistencia", "etiqueta": "Inasistencia", "cantidad": 1, "duracion_minutos": null, "porcentaje": "33.3", "estado": "Inasistencia"}
    ]
  }]
}
```

### 14.2. Agenda o por Médico con Confirmada aplicada

Una cita de40 minutos; agenda podría incluir cruce del día anterior: suma40, no minutos recortados. Misma forma para ambos recursos. Página fuera de rango (`data: []`, `meta.total: 1`) conserva este bloque.

```json
{
  "version": 1, "unidad": "citas", "estados_incluidos": ["Confirmada"],
  "resumen": {"cantidad_total": 1, "duracion_total_minutos": 40},
  "series": [{
    "id": "por_estado", "dimension": "estado", "agrupacion": null, "porcentajes": null,
    "grafico": {"tipo": "barras_horizontales", "visible": false, "motivo_omision": "unica_categoria_con_datos", "maximo": 1},
    "categorias": [
      {"id": "estado:Programada", "etiqueta": "Programada", "cantidad": 0, "duracion_minutos": 0, "porcentaje": null, "estado": "Programada"},
      {"id": "estado:Confirmada", "etiqueta": "Confirmada", "cantidad": 1, "duracion_minutos": 40, "porcentaje": null, "estado": "Confirmada"},
      {"id": "estado:Atendida", "etiqueta": "Atendida", "cantidad": 0, "duracion_minutos": 0, "porcentaje": null, "estado": "Atendida"},
      {"id": "estado:Cancelada", "etiqueta": "Cancelada", "cantidad": 0, "duracion_minutos": 0, "porcentaje": null, "estado": "Cancelada"},
      {"id": "estado:Inasistencia", "etiqueta": "Inasistencia", "cantidad": 0, "duracion_minutos": 0, "porcentaje": null, "estado": "Inasistencia"}
    ]
  }]
}
```

### 14.3. Programadas por identidad de Médico

Dos homónimos ficticios con cantidades empatadas, desempate ID numérico2 antes de10. Por especialidad usa la misma forma con estados efectivos correspondientes y pertenencia por especialidad de Cita.

```json
{
  "version": 1, "unidad": "citas", "estados_incluidos": ["Programada"],
  "resumen": {"cantidad_total": 2, "duracion_total_minutos": null},
  "series": [{
    "id": "por_medico", "dimension": "medico", "agrupacion": null, "porcentajes": null,
    "grafico": {"tipo": "barras_horizontales", "visible": true, "motivo_omision": null, "maximo": 1},
    "categorias": [
      {"id": "medico:2", "etiqueta": "Prueba, Ana — MED-002", "cantidad": 1, "duracion_minutos": null, "porcentaje": null, "medico": {"id_medico": "2", "codigo_medico": "MED-002", "nombres": "Ana", "apellidos": "Prueba"}},
      {"id": "medico:10", "etiqueta": "Prueba, Ana — MED-010", "cantidad": 1, "duracion_minutos": null, "porcentaje": null, "medico": {"id_medico": "10", "codigo_medico": "MED-010", "nombres": "Ana", "apellidos": "Prueba"}}
    ]
  }]
}
```

### 14.4. Fichas:32 días, meses parciales y un intervalo vacío

2026-01-20 a2026-02-20; dos fichas activas actuales, ninguna inactiva. Ambos gráficos omitidos porque cada serie tiene solo una categoría positiva, manteniendo tablas completas.

```json
{
  "version": 1, "unidad": "fichas", "estados_incluidos": [],
  "resumen": {"cantidad_total": 2, "duracion_total_minutos": null},
  "series": [
    {
      "id": "por_actividad", "dimension": "actividad", "agrupacion": null, "porcentajes": null,
      "grafico": {"tipo": "barras_horizontales", "visible": false, "motivo_omision": "unica_categoria_con_datos", "maximo": 2},
      "categorias": [
        {"id": "actividad:activo", "etiqueta": "Activos", "cantidad": 2, "duracion_minutos": null, "porcentaje": null, "activo": true},
        {"id": "actividad:inactivo", "etiqueta": "Inactivos", "cantidad": 0, "duracion_minutos": null, "porcentaje": null, "activo": false}
      ]
    },
    {
      "id": "por_periodo", "dimension": "periodo", "agrupacion": "mensual", "porcentajes": null,
      "grafico": {"tipo": "barras_horizontales", "visible": false, "motivo_omision": "unica_categoria_con_datos", "maximo": 2},
      "categorias": [
        {"id": "mes:2026-01", "etiqueta": "2026-01 (parcial)", "cantidad": 2, "duracion_minutos": null, "porcentaje": null, "intervalo": {"desde": "2026-01-20", "hasta": "2026-01-31", "inicio_at": "2026-01-20T04:00:00.000000Z", "fin_exclusivo_at": "2026-02-01T04:00:00.000000Z", "parcial": true}},
        {"id": "mes:2026-02", "etiqueta": "2026-02 (parcial)", "cantidad": 0, "duracion_minutos": null, "porcentaje": null, "intervalo": {"desde": "2026-02-01", "hasta": "2026-02-20", "inicio_at": "2026-02-01T04:00:00.000000Z", "fin_exclusivo_at": "2026-02-21T04:00:00.000000Z", "parcial": true}}
      ]
    }
  ]
}
```

### 14.5. Por estado: una fecha sin resultados

Cancelada para2026-11-07, intervalo diario presente aunque vacío. Cantidades vacío conserva tres categorías de14.1 con cantidad0/porcentaje null, denominador0 y gráfico false/`sin_datos`/máximo0. Médicos vacío conserva serie con `categorias: []`.

```json
{
  "version": 1, "unidad": "citas", "estados_incluidos": ["Cancelada"],
  "resumen": {"cantidad_total": 0, "duracion_total_minutos": null},
  "series": [{
    "id": "por_periodo", "dimension": "periodo", "agrupacion": "diaria", "porcentajes": null,
    "grafico": {"tipo": "barras_horizontales", "visible": false, "motivo_omision": "sin_datos", "maximo": 0},
    "categorias": [
      {"id": "dia:2026-11-07", "etiqueta": "2026-11-07", "cantidad": 0, "duracion_minutos": null, "porcentaje": null, "intervalo": {"desde": "2026-11-07", "hasta": "2026-11-07", "inicio_at": "2026-11-07T04:00:00.000000Z", "fin_exclusivo_at": "2026-11-08T04:00:00.000000Z", "parcial": false}}
    ]
  }]
}
```

## 15. Aceptación y estado EST-1

[Matriz estadística EST-2–EST-5](cu29-aceptacion-estadisticas.md): EST-A01–EST-A16, fases y evidencia esperada. No reutiliza aceptación PDF histórica como prueba de estadísticas. EST-1 contrasta estáticamente fuentes/código/contratos/coherencia documental; no ejecuta Laravel, React, Dompdf ni PostgreSQL. No se detecta incompatibilidad esencial pendiente con indicadores/límites aprobados; comportamiento/compatibilidad visual pendientes de fases ejecutables, no verificados. Cierre real/verificaciones estáticas en `estado-fase.md`.
