# Backend — Clínica Vida Salud

## Agenda e historial CU-20/CU-21 — fase 6: integración y cierre backend (2026-10-22)

**Fase 6 y backend CU-20/CU-21 cerrados localmente.** AgendaIntegracionTest añade **tres pruebas/394 aserciones correctas**, 1,836 s/72 MB. No se necesitaron cambios productivos. Las secciones de fases 1–5 que siguen son historial de desarrollo: sus contratos previstos están implementados y sus pendientes de integración se resuelven en esta fase. No se declara implementado el frontend.

### Contratos vigentes para el frontend posterior

| GET /api | Roles | Uso y entrada principal |
| --- | --- | --- |
| /agenda/medicos | Administrador, Recepcionista | Selector mínimo, incluidos inactivos; nombre literal opcional, page/per_page |
| /agenda | Administrador, Recepcionista, Médico | id_medico requerido solo para Admin/Recepción; Médico propio desde sesión, fecha local/estado opcionales, page/per_page |
| /mis-citas | Paciente | Ficha propia desde sesión; periodo=proximas por defecto, estado opcional, page/per_page |
| /pacientes/{id}/citas | Recepcionista | Ficha seleccionada; periodo=todas por defecto, estado opcional, page/per_page |
| /citas/{id} | Cuatro roles | ID canónico de ruta, sin query/body; Médico/Paciente restringidos a citas propias |

Se mantienen los campos, límites, metadata, zona America/La_Paz, UTC, orden fijo, matriz de detalle y errores de fases 1–4. Los listados nunca incluyen motivo/CI/teléfono/nacimiento; detalle Admin sin motivo, Recepción motivo/CI/teléfono, Médico responsable motivo/nacimiento, Paciente motivo propio. Claves prohibidas omitidas, no null. Citas ajenas/inexistentes mismo 404 para Médico/Paciente. Sin rutas nuevas de escritura ni cambios a reserva/autenticación. CitasCollection no fue necesaria: se usa AnonymousResourceCollection nativa y metadata explícita.

### Evidencia de aceptación acumulada

| Área | Evidencia real |
| --- | --- |
| Reserva → consultas de cuatro roles | Dos recorridos de AgendaIntegracionTest: disponibilidad/propuesta/POST /citas reales, reservante Recepción o Paciente, misma cita e intervalo/duración/ubicación/estado en agenda/historial/detalle |
| Sesión y aislamiento | Logout/401/nuevo login/restauración por cookies/DB, historial por ficha solo Recepción; otro Paciente sin citas y detalle ajeno/inexistente indistinguibles; fases 2–5 cubren resto de matriz y sesión restringida |
| Historia e inactividad | Fin exactamente t pasa de Próximas a Anteriores sin cambiar Programada; inactividad de ficha/profesional y consultorio actual distinto no eliminan lectura ni ubicación conservada |
| Navegación completa | Tercer recorrido sigue links.next reales con período/estado/per_page; tres fechas 2000/2026/2099, sin omisiones/duplicados y detalle de cada elemento |
| Privacidad y consulta sin efectos | Proyección mínima por rol, dominio idéntico tras lecturas y logout/login; una cita/un evento CITA_PROGRAMADA, sin cambios/recordatorios/consultas creados por consultar. Autenticación conserva su auditoría nativa |
| Límites/errores/concurrencia | AgendaTest, HistorialCitasTest, ConsultaCitaTest, AgendaSeguridadTest y AgendaConcurrenteTest: 229/2421 de fase 5, incluidos 56 escenarios multiproceso y errores privados |
| Paginación/volumen | Fases 2/3: 30/300 filas, una consulta proyectada de página/count, total/contexto también en página vacía; no SLA ni índice nuevo sin evidencia |

Identidades ya verificadas y planificación se preparan como fixtures aislados: no acredita alta/activación/invitación ni administración real en los nuevos recorridos. Solo la reserva se registra mediante su flujo HTTP real; la inactividad/cambio actual de consultorio son datos de prueba, no nuevas acciones CU-15–CU-19. Kernel HTTP, cookies/CSRF/sesiones y PostgreSQL en base/rol aislados clinica_vida_salud_test, HTTP externo bloqueado; no navegador/Brevo/Railway/HTTPS productivos.

**Verificaciones:** regresión backend completa final **1612 pruebas/19176 aserciones correctas**, 12 min 20,196 s/200 MB, límite CLI 512 MB solo para PHPUnit. Resultado propio de fase 6, distinto de 1609/18782 de fase 5. Pint de código acumulado y pruebas, sintaxis de 23 PHP, Composer validate/autoload PSR-4/ambigüedad estrictos, 72 rutas/cinco GET del módulo sin duplicados y diff correctos. README/análisis/CU/reglas/requerimientos propios/contexto §17.109 sincronizados con el cierre local.

**Pendientes y límites:** frontend en etapa separada no iniciada, CU-15–CU-19 permanecen pendientes y obligatorios. Sin cambios de RF/RN oficiales, modelos/esquema/migraciones/índices/DER (28/244/43), dependencias o autenticación; base habitual/Railway intactas. Diagrama global y actualización PHP expresamente aplazados, no se presentan como terminados. Sin commit/push/despliegue ni aceptación integral de producción.

## Agenda e historial CU-20/CU-21 — fase 5: seguridad y casos límite (2026-10-22)

**Refuerzo de fase 5 implementado; sin cambios productivos nuevos.** Se añaden AgendaSeguridadTest (45 casos) y AgendaConcurrenteTest (56 casos) y se amplía exclusivamente el arnés de pruebas compartido autenticacion-worker.php con modo agenda-antes y middleware fixture PausarConsultaAgenda. No rutas/comandos de aplicación, dependencias, migraciones/índices/modelos, cambios de permisos/reglas/contratos ni frontend. Ningún fallo observado exigió modificar producción; integración/cierre integral conserva fase 6.

**Concurrencia real:** 56 combinaciones de selector/agenda propia/Mis citas/historial por ficha/detalle Médico/detalle Paciente frente a estado/rol/sesión/hash y pérdida de vínculo cuando corresponde; commit/rollback. Kernel HTTP con sesiones/cookies reales en procesos PHP y PID PostgreSQL distintos. Barrera explícita después de middleware de sesión/rol, luego actor bloqueado por otro proceso; se verifica wait_event_type=Lock mediante pg_stat_activity antes de resolver la transacción. Commit de revocación → 401 sin data ni motivo; rollback → 200 con proyección autorizada (motivo solo en detalle). Cita/estado/cambios/recordatorios preservados. No temporizadores usados como prueba de serialización. Fixtures usan un esquema efímero prueba_auth_* dentro de base/rol clinica_vida_salud_test, todas las migraciones y search_path acotado (incluida función de bitácora); guard del worker y limpieza del esquema propio, nunca base habitual/remota.

Estas carreras verifican revocación confirmada **antes de que el Service complete la revalidación tras esperar al actor**. No prometen retirar bytes ya entregados o impedir que una revocación posterior a una lectura autorizada afecte la siguiente petición. El modo del worker solo se aplica a los cinco patrones GET del módulo; ramas anteriores de otros módulos permanecen intactas. No se implementan los CU de escritura pospuestos para construir estas pruebas.

**Seguridad/límites:** cinco rutas rechazan POST/PUT/PATCH/DELETE sin mutar dominio; POST /citas de reserva sigue independiente. GET/HEAD con sesión/origen correctos no exigen cabecera CSRF de escritura; HEAD vacío/no-store. Fallos inyectados de Repository devuelven 500 genérico sin SQL/rutas/motivo/hash ni cambios persistidos. Búsquedas SQL/LIKE hostiles se tratan literalmente; arrays/notación exponencial/selección de rol/campos/orden no autorizados se rechazan. Fechas civiles válidas 0001-01-01, 2000-02-29 y 9999-12-31 sin horizonte arbitrario; zona PostgreSQL Pacific/Auckland no cambia día local/instantes UTC. Snapshot de una respuesta se mantiene si se inserta otra cita al terminar su SQL; la siguiente petición ve el cambio. Esta última intercalación es un callback en la misma conexión, no una carrera de escritores independientes.

**Verificación dirigida:** nuevos casos **101 pruebas/1129 aserciones** (56/756 concurrentes, 45/373 seguridad); ejecución conjunta de cinco clases del módulo **229 pruebas/2421 aserciones correctas**, 1 min 20,925 s/94 MB, PostgreSQL/base/rol aislados, HTTP externo bloqueado. Pint/sintaxis de cuatro PHP de esta fase y Composer validate/autoload PSR-4/ambigüedad estrictos correctos; 72 rutas/cinco GET del módulo conservados. Regresión backend completa en ejecución por ampliación del worker compartido; resultado final pendiente de consignar, no se atribuye la global anterior de fase 2 a esta fase.

**Cierre de regresión:** la ejecución completa por ampliación del worker compartido terminó correctamente: **1609 pruebas/18782 aserciones**, 10 min 44,239 s/198 MB, límite CLI 512 MB solo para PHPUnit. Este resultado sustituye el pendiente del párrafo anterior y corresponde al inventario acumulado hasta fase 5.

**Documentación/límites:** README/análisis/CU/reglas/requerimientos propios/contexto §17.108 sincronizados. RF/RN oficiales, código productivo de fases 2–4, esquema/DER (28/244/43), base habitual/Railway y React sin cambios nuevos. Diagrama global/PHP mantiene aplazamiento; sin dependencias/correos/commit/push/despliegue o certificación de producción/SLA. **Fase 5 cerrada localmente; fase 6 no iniciada y módulo integral aún pendiente de cierre.**

## Detalle autorizado CU-20/CU-21 — fase 4 (2026-10-22)

**Fase 4 implementada y verificada localmente:** GET `/api/citas/{id}` para los cuatro roles con sesión normal, sin cambiar POST /citas ni añadir escrituras CU-15–CU-19. Completa los cinco GET diseñados del módulo; robustez ampliada/carreras e integración/cierre conservan fases 5/6, no se declara todavía cerrado el módulo integral. Las secciones siguientes son historial de fases anteriores y sus pendientes de detalle quedan sustituidos por esta entrega.

**Capas:** ConsultaCitaController + ConsultaCitaRequest + CitaDetalleResource en presentation/dtos/controllers de agenda, ConsultaCitaService en business/services/agenda y método detalle en AgendaRepository. Request acepta únicamente ID canónico de ruta (BIGINT positivo), sin query/body; Service revalida sesión normal/actor/rol/vínculo bajo lock en transacción corta y decide scope/columnas. Repository aplica id_cita y scope propio en SQL antes de materializar; no recupera una cita ajena para autorizarla después. Datos planos materializados dentro de la transacción, sin Eloquent completo ni lazy-load clínico. CitaDetalleResource compone los campos comunes de CitaListadoResource y añade una allowlist según rol **devuelto por Service**, no un parámetro HTTP.

| Rol | Scope de lectura | Datos adicionales solo en detalle |
| --- | --- | --- |
| Administrador | Cita administrativa por CU-20 | Ninguno: motivo_breve y campos adicionales de Paciente completamente omitidos, incluso cuando tienen valor |
| Recepcionista | Cita de gestión administrativa autorizada | motivo_breve; paciente.documento_identidad (null si no existe) y paciente.telefono |
| Médico | id_medico derivado de su vínculo vigente de sesión | motivo_breve y paciente.fecha_nacimiento para identificar al Paciente dentro del detalle |
| Paciente | id_paciente derivado de su ficha vinculada de sesión | motivo_breve de la propia cita; sin otros datos personales adicionales |

**Minimización efectiva:** las columnas adicionales solo se seleccionan para el rol autorizado. Administrador ni siquiera selecciona motivo_breve; ningún listado lo incorpora. Resource vuelve a limitar claves incluso ante una fila interna con campos extra. Valores autorizados ausentes se conservan null, sin sustituir la omisión de campos prohibidos. No contacto personal del Médico, CI/teléfono/nacimiento fuera de su matriz, creador, UUID/sobre, diagnósticos, antecedentes, consultas o resultados clínicos. Conserva código/intervalo/duración/estado/modalidad/fecha de registro/ubicación efectiva e identidades mínimas acordadas. Sin snapshot nominal nuevo ni reconstrucción desde el consultorio fijo actual.

**Errores y consulta histórica:** cita ajena e inexistente devuelven exactamente el mismo 404 RECURSO_NO_ENCONTRADO para Médico/Paciente; ID no canónico/query/body inválidos 422; sesión inválida/expirada/revocada 401 y restringida/rol no autorizado 403, no-store privado/origen/HEAD existentes. Cuenta válida con ficha/profesional inactivos conserva lectura autorizada, sin exigir actividad/habilitación actual ni estado específico de Cita. Ningún cambio de estado o efecto de negocio/auditoría/correo por consultar.

**Pruebas:** ConsultaCitaTest incorpora **37 casos nuevos**. Módulo agenda/historial/detalle: **128 pruebas/1292 aserciones correctas**. Regresión final de ocho clases (esas tres, ReservaTest, ReservaIntegracionTest, MiPerfilTest, PacienteAdministrativoTest y PacienteActualizacionTest): **257 pruebas/2753 aserciones correctas**, 1 min 17,993 s/80 MB, kernel HTTP/sesiones/cookies/CSRF y PostgreSQL/base/rol aislados clinica_vida_salud_test, HTTP externo bloqueado. Cubre matriz de cuatro roles con motivo presente/null y columnas SQL exactas, omisión real/null permitido, scoped 404 indistinguibles, invocación directa/ID ajeno, ficha sin CI/cuenta, cinco estados/historia/ubicación/inactividad, motivo «0», BIGINT, entradas/privilegios inyectados, body, huésped/origen/HEAD/expiración/sesión temporal, revalidación tardía y allowlist de Resource ante campos extra. Tablas de dominio intactas; inyecciones tardías en misma conexión, no nuevas pruebas multiproceso (fase 5).

**Cierre técnico/documental:** Pint/sintaxis de 18 PHP acumulados, Composer validate/autoload PSR-4/ambigüedad estrictos, 72 rutas totales/cinco GET del módulo sin duplicados y diff correctos. No suite completa repetida en esta fase; última global 1423/16794 corresponde a fase 2, regresión de esta fase es 257/2753. README/análisis/fichas/reglas/requerimientos propios y contexto §17.107 sincronizados. RF/RN oficiales, frontend, modelos/migraciones/índices/DER (28/244/43), base habitual/Railway sin cambios. Diagrama global/PHP aplazados; sin dependencias/commit/push/despliegue/aceptación productiva. **Se detiene antes de fase 5.**

## Historial y Mis citas CU-21 — fase 3 (2026-10-22)

**Fase 3 implementada/verificada localmente:** GET `/api/mis-citas` exclusivo del Paciente y GET `/api/pacientes/{id}/citas` exclusivo de Recepción. Administrador/Médico no obtienen historial por Paciente; mantienen la agenda autorizada de CU-20. GET /citas/{id} y su matriz de detalle permanecen para fase 4, sin adelantarlos ni añadir acciones CU-15–CU-19.

**Capas:** HistorialCitasController/HistorialCitasRequest/HistorialCitasService en agenda de cada capa, con AgendaRepository/CitaListadoResource existentes. El repositorio extrae una proyección privada compartida por agenda/historial sin ampliar campos: lista mínima sin motivo/CI/teléfono/nacimiento/creador/UUID/contacto médico o contenido clínico. Continúa la CTE parametrizada de página/count en un snapshot de sentencia, sin cargar todas las filas a PHP. Rutas en agenda.php ya incorporado por el puente, sin duplicar definiciones.

**Permisos:** Service exige sesión normal vigente con bloqueo/revalidación de actor y vínculo. Paciente deriva su propia ficha (incluida inactiva), rechaza ID explícito incluso en invocación directa. Recepción selecciona ficha de ruta validada, también sin CI/cuenta o inactiva; recurso desconocido 404, ficha existente sin citas 200 vacío con contexto mínimo. Body/campos extra/ID de filtros/arrays/enum/paginación inválidos 422; rol ajeno 403, sesión inválida 401, private/no-store. No cambios a autenticación, origen/CSRF o endpoints administrativos.

**Clasificación:** t capturado una sola vez después de revalidar sesión/vínculo. Próximas (default Paciente) = Programada/Confirmada y fin_at > t, incluidas en curso, inicio/id ASC. Anteriores = fin_at <= t con cualquier estado; Todas (default Recepción) sin tiempo; ambas inicio/id DESC. Estado combina por AND, sin inferencia automática; Programada pasada conserva Programada, Cancelada futura solo en Todas, Cancelada pasada también en Anteriores. Combinación válida sin coincidencias devuelve vacío. Paginación conserva período/estado efectivos, zona/instante/contexto y total fuera de rango; no truncamiento por antigüedad ni filtros adicionales de fechas/Médico/especialidad. Ubicación/duración son de Cita, no de asignación vigente ni reglas de reserva activa.

**Pruebas:** HistorialCitasTest añade **48 casos**. Regresión final AgendaTest, HistorialCitasTest, ReservaTest, ReservaIntegracionTest, MiPerfilTest, PacienteAdministrativoTest y PacienteActualizacionTest: **220 pruebas/2273 aserciones correctas**, 1 min 20,732 s/80 MB. Kernel HTTP/sesiones/cookies/CSRF y PostgreSQL/base/rol aislados clinica_vida_salud_test, HTTP externo bloqueado. Defaults/aislamiento de otra ficha, estados/períodos/microsegundos/fin=t/en curso, antigüedad/cancelación/inactividad/ubicación, vacío/404, BIGINT/desempates/páginas/links, privacidad/body/IDs/negaciones/expiración/HEAD/origen, invocación directa, revalidación tardía de estado/rol/vínculo/sesión y ausencia de efectos de negocio comprobados. Inyecciones tardías en misma conexión, no carreras multiproceso (fase 5). 300 citas/per_page=1 mantienen una consulta proyectada de filas/count y un elemento devuelto, sin SLA.

**Resultado recibido de fase 2:** regresión completa previa **1423 pruebas/16794 aserciones correctas**, 9 min 1,466 s/180 MB. Sustituye el pendiente de la sección inferior; corresponde al inventario de fase 2, no a fase 3. Aquí se ejecutó la regresión dirigida de 220 casos; suite completa final conserva fase 6 o cambios transversales que la requieran. AgendaTest adapta la comprobación de rutas a los dos GET ahora disponibles y mantiene ausencia de detalle/escrituras futuras.

**Cierre técnico/documental:** Pint/sintaxis de 13 PHP acumulados, Composer validate/autoload PSR-4/ambigüedad estrictos y 71 rutas totales/cuatro de agenda-historial sin duplicados correctos. README/análisis/fichas/reglas/requerimientos propios/contexto §17.106 sincronizados. RF/RN oficiales, modelos/migraciones/índices/DER (28/244/43), frontend y base habitual/Railway sin cambios. Sin dependencias/correo real/commit/push/despliegue/aceptación de producción; diagrama global/actualización PHP aplazados. **Se detiene antes de fase 4; módulo integral/detalle no cerrados.**

## Agenda médica CU-20 — fase 2: selector y consulta diaria (2026-10-22)

**Implementado solo el alcance de fase 2:** GET `/api/agenda/medicos` (Administrador/Recepcionista) y GET `/api/agenda` (Administrador/Recepcionista/Médico). Se mantienen los contratos de fase 1; el Médico deriva su profesional desde la sesión y no puede enviar id_medico aunque sea el propio. CU-21 y GET /citas/{id} siguen pendientes de fases 3/4; no acciones CU-15–CU-19 ni frontend nuevo.

**Capas reales:** AgendaController/AgendaRequest/MedicoAgendaResource/CitaListadoResource en presentation/agenda, AgendaService en business/services/agenda y AgendaRepository en data/repositories/agenda; rutas en agenda.php registradas una sola vez por el puente routes/api.php. No se necesitó CitasCollection propia en esta fase: la colección paginada nativa de JsonResource combina links/meta estándar con metadata explícita, evitando una clase sin responsabilidad adicional. Datos materializados antes de abandonar la transacción, sin lazy-load ni serialización de modelos completos.

**Selector mínimo:** devuelve únicamente ID textual/código/nombres/apellidos/activo, con búsqueda literal por nombre/apellidos/código y orden apellidos/nombres/id ascendente. Incluye Médicos inactivos sin cuenta/sala/horarios, sin conceder a Recepción acceso al catálogo `/medicos` administrativo. Paginación cerrada y respuesta vacía legítima.

**Agenda:** id_medico requerido para Admin/Recepción; Médico propio y vínculo revalidado en servidor. Fecha local por defecto capturada después de exigir sesión normal vigente, fecha pasada permitida; estado opcional entre los cinco existentes, sin transiciones. Intersección semiabierta con el día local (también intervalos históricos multidía), orden inicio_at/id_cita ascendente. Contexto mínimo del profesional, zona America/La_Paz, instante UTC y filtros efectivos, incluso sin citas. Links de Médico no contienen id_medico para que sigan siendo entradas válidas. Duración/ubicación son de la Cita; no se requiere disponibilidad activa, habilitación actual, cuenta web de la ficha ni actividad de ficha/profesional para leer historia autorizada.

**Privacidad/sesión:** proyección SQL cerrada de listado, sin motivo, CI, teléfono/nacimiento, contacto del Médico, creador o UUID/sobre de reserva. Requests rechazan body no vacío, campos extra, arrays/formato/enums fuera de contrato; límites/IDs y errores centralizados existentes. Service revalida rol/sesión/identidad con AutenticacionService bajo lock del actor en transacción corta, no se limita al middleware. Se conserva `private, no-store`, origen/HEAD y distinción de vacío/404/403/401/422; sin mutar dominio, emitir correo o crear eventos de negocio por leer.

**Consistencia de página implementada:** AgendaRepository usa Eloquent/Query Builder para filtros/joins/proyección y una sentencia PostgreSQL parametrizada con CTE materializada, página ordenada y total unidos mediante LEFT JOIN. Count y filas comparten el mismo snapshot de sentencia, incluso para conjunto vacío/página posterior a la última. No establecer aislamiento snapshot antiguo antes de revalidar al actor ni bloquear todas las Citas/planificación. Orden/columnas de paginación son constantes internas, nunca entrada interpolada. Se devuelve solo la página al proceso PHP; no se promete snapshot durable entre peticiones. Sin índices ni esquema nuevos.

**Pruebas dirigidas:** AgendaTest añade **43 pruebas/433 aserciones correctas**, 11,904 s/74 MB, kernel HTTP/sesiones/cookies/CSRF reales sobre base/rol aislados clinica_vida_salud_test. Incluye tres roles permitidos, negación de Paciente/selector médico/huésped/origen/sesión restringida/expirada; revalidación de estado/rol/sesión/vínculo después del middleware; entradas cerradas; BIGINT textual; paginación/orden/desempates/vacío/404; cinco estados; límites locales/microsegundos/multidía; identidad propia/links; historia con actividad/habilitación/consultorio cambiados; snapshot de tablas de negocio intacto. Cambios tardíos se inyectan en la misma conexión de prueba: no acreditan todavía carreras multiproceso (fase 5).

**Volumen:** con 30 y 300 citas ficticias, per_page=1 conserva un único SQL proyectado para página+count, total correcto y un solo elemento transferido a PHP; se comprueba ausencia de columnas sensibles en la consulta. No benchmark de carga ni SLA. Los estados de casos pendientes se preparan como fixtures aislados y no acreditan CU-15–CU-19. La primera ejecución de la prueba de sesión temporal esperaba erróneamente 200 de emisión; se corrigió a su contrato 201, sin tocar esa funcionalidad.

**Comprobaciones técnicas:** Pint/sintaxis de los nueve PHP afectados, Composer validate y autoload optimizado PSR-4/ambigüedad estrictos correctos. Registro efectivo: 69 rutas totales, dos de agenda, sin duplicados; tres GET de historial/detalle previstos todavía no registrados. Regresión backend completa en ejecución; resultado final pendiente de consignar. No se repite frontend intacto ni se afirma producción/correo real/integración de fase 6.

**Documentación/límites:** análisis CU-20, fichas/reglas/requerimientos propios y contexto §17.105 sincronizados; RF/RN oficiales sin cambios. Sin modelos/migraciones/índices/DER nuevos: 28 entidades/244 atributos/43 relaciones, sin migración a base habitual/Railway. Diagrama global/PHP siguen aplazados. Sin dependencias, commit/push/despliegue. Fase 2 no cierra el módulo ni el detalle de CU-20; **se detiene antes de fase 3**.

## Agenda e historial CU-20/CU-21 — fase 1: diseño y contratos (2026-10-22)

**Solo diseño documentado, no implementación.** Se inicia el módulo después de CU-13/CU-14 por decisión del usuario; CU-15–CU-19 se posponen, no se eliminan del alcance. Plan: 1 diseño/contratos; 2 agenda CU-20; 3 historial CU-21; 4 detalle autorizado; 5 seguridad/casos límite; 6 integración/cierre backend. Cada fase requiere autorización. Frontend tendrá etapa posterior: no se habilitan ahora sus módulos pendientes.

**Base contrastada:** PHP 8.5.11, Laravel 13.37.0, Composer 2.10.3, Sanctum/sesiones y cuatro capas existentes. `routes/api.php` registra los archivos de `src/presentation/routes/`; hay 67 rutas actuales (65 API y dos de infraestructura), sin duplicados y ninguna de agenda/historial/detalle GET de Cita. Cita conserva inicio_at/fin_at, duracion_minutos, sede_atencion, consultorio_atencion y creada_en; FK a Paciente/Médico/especialidad/consultorio. Motivo/UUID de propuesta ocultos en serialización genérica, que no sustituye proyecciones explícitas. No nuevas tablas, columnas, índices, modelos, migraciones o dependencias previstas para estas consultas; índices y FK existentes se evaluarán con evidencia antes de plantear optimizaciones. Esquema documental existente: 28 entidades/244 atributos/43 relaciones; no conexión ni modificación de base habitual/remota en fase 1.

### Decisiones funcionales aprobadas y límites

- CU-20: Administrador/Recepción seleccionan Médico; el Médico consulta exclusivamente su propia agenda derivada de sesión. Consulta diaria America/La_Paz, fecha actual por defecto, filtros Médico/fecha/estado y orden inicio ascendente. Ningún listado expone motivo, CI, teléfono o nacimiento.
- CU-21: Paciente consulta solo su ficha vinculada, por defecto Próximas y más cercana primero; Recepción abre la ficha seleccionada y por defecto Todas, más recientes primero. Filtros iniciales únicamente período temporal y estado, con paginación del servidor que permite recorrer todo el historial autorizado; no filtros adicionales por fechas/Médico/especialidad en esta entrega.
- Con un único instante `t` capturado por el servidor en cada respuesta después de validar sesión/vínculo: Próximas = estado Programada/Confirmada y fin_at > t, incluidas en curso; Anteriores = fin_at <= t, cualquiera sea el estado; Todas = sin restricción temporal. Estado es otro filtro, combinado por AND. Cancelada futura queda en Todas, filtrable por estado; no pertenece ni a Próximas ni a Anteriores. No son tres conjuntos que particionen todas las citas. Una combinación válida sin coincidencias devuelve vacío, no cambia automáticamente de período.
- Mantener los cinco estados reales. El paso del tiempo no convierte Programada en Inasistencia ni exige Atendida para consultar. Fin exactamente igual a t pertenece a Anteriores. Cuenta válida y ficha/profesional inactivos conservan consulta autorizada; no exigir las condiciones de nueva reserva (actividad, horario, habilitación actual o consultorio fijo vigente).
- La ubicación mostrada procede de `citas.consultorio_atencion`/`sede_atencion`, nunca del consultorio fijo actual del Médico ni del nombre actual del consultorio relacionado. Duración/intervalo/estado/fecha de registro proceden de la Cita guardada. Nombres de personas/especialidad se consultan de relaciones existentes; no se prometen snapshots nominales que el esquema no guarda. No incluir versiones de CambioCita, clínica o laboratorio en este módulo.
- Detalle con motivo solo para Recepción autorizada, Médico responsable o Paciente titular. Administrador no recibe la clave motivo_breve, ni siquiera null. Sin contenido clínico, contacto personal del Médico, credenciales, creador, UUID/token de propuesta o datos de otra cita.

### Contrato HTTP previsto — prefijo /api, aún no registrado

Solo GET (HEAD implícito de Laravel), con `auth:sanctum`, VerificarCuentaHabilitada, ExigirSesionNormal y ExigirRol por operación; se conserva ExigirOrigenSpa/grupo API y protección de sesión nativa, sin exigir un POST CSRF previo a cada GET. Respuestas `Cache-Control: no-store, private`. No modificar rutas administrativas existentes, POST /citas o /reservas/*.

| Método/ruta **previstos** | Roles | Entrada cerrada | Resultado 200 previsto / fase |
| --- | --- | --- | --- |
| GET /agenda/medicos | Administrador, Recepcionista | nombre opcional, page/per_page | Selector paginado mínimo de profesionales, incluidos inactivos; fase 2 |
| GET /agenda | Administrador, Recepcionista, Médico | id_medico obligatorio solo Admin/Recepción; fecha opcional YYYY-MM-DD; estado opcional; page/per_page | Agenda diaria de un Médico; fase 2 |
| GET /mis-citas | Paciente | periodo opcional, estado opcional, page/per_page | Historial propio, periodo=proximas por defecto; fase 3 |
| GET /pacientes/{id}/citas | Recepcionista | id de ruta; periodo opcional, estado opcional, page/per_page | Historial de la ficha seleccionada, periodo=todas por defecto; fase 3 |
| GET /citas/{id} | Administrador, Recepcionista, Médico, Paciente | Solo id de ruta, sin query/body | Detalle autorizado con proyección por rol; fase 4 |

**Selector de agenda:** no reutilizar `/medicos` administrativo para Recepción ni `/reservas/medicos`, que exige especialidad/actividad y no representa historia. El nuevo selector devuelve solo id_medico/codigo_medico/nombres/apellidos/activo, con nombre como búsqueda literal sobre nombres/apellidos y código, sin contacto/cuenta/registro profesional. Orden apellidos, nombres, id_medico ascendentes; no filtrar por actividad ni por disponer hoy de horarios/citas. Recurso inexistente difiere de profesional existente sin citas (200 vacío). El Médico no necesita este selector ni puede usarlo.

**Validación y orden:**

- Campos desconocidos, arrays donde se esperan escalares y body no vacío se rechazan con 422. Sin sort libre, include, campos, IDs de actor ni parámetros clínicos. Ids de ruta/query enteros positivos canónicos hasta BIGINT 9223372036854775807; respuestas de todos los IDs como strings para evitar pérdida en JavaScript.
- page entero 1–1000000, per_page entero 1–100; defaults 1/20, conforme al patrón actual. No devolver todo el conjunto sin paginar. Página posterior a la última devuelve 200 data[] vacío con meta del conjunto, sin redirección implícita. Selector nombre no vacío, máximo 120 y sin caracteres de control, búsqueda literal sin comodines aportados por usuario.
- fecha civil real YYYY-MM-DD con año de cuatro dígitos distinto de 0000; pasadas permitidas y sin horizonte de reserva. Si se omite, usar fecha de t en America/La_Paz. Agenda incluye intersección `[inicio_at,fin_at)` con el día local `[00:00,00:00 del siguiente día)`, sin duplicar una cita dentro de esa respuesta; permite conservar intervalos históricos que crucen medianoche. Orden inicio_at ASC, id_cita ASC como desempate.
- Médico: id_medico no se acepta aunque coincida con el propio (422); se deriva de la cuenta revalidada y se comprueba vínculo recíproco. Paciente: id_paciente no es entrada de /mis-citas; se deriva de la sesión, sin tomar un ID de React. Intento de seleccionar otro recurso mediante campo no permitido → 422; acceso a detalle ajeno/inexistente → el mismo 404 genérico.
- estado opcional con uno de Programada, Confirmada, Atendida, Cancelada, Inasistencia; omitido = todos. periodo opcional con proximas/anteriores/todas en minúsculas. Próximas orden inicio_at ASC/id_cita ASC; Anteriores/Todas inicio_at DESC/id_cita DESC. Estos órdenes son fijos, no un filtro extra. No usar fecha de creación para ordenar la atención.

### Respuestas y minimización previstas

Selector: `data: [medico_minimo]`, `links: {first,last,prev,next}`, `meta: {current_page,last_page,per_page,total,from,to}`. Agenda/historial: `data: [cita_listada]`, mismos links/meta y dentro de meta `zona_horaria: America/La_Paz`, `consultado_en` (t UTC ISO-8601 con seis decimales y Z) y `filtros` efectivos. Filtros de agenda: id_medico/fecha/estado; historial: periodo/estado. Contexto adicional en meta: agenda `medico` mínimo sin activo; historial `paciente` mínimo solo con id_paciente/codigo_paciente/nombres/apellidos, siempre del recurso autorizado; útil incluso con lista vacía. En /mis-citas no expone ningún dato ajeno. Links conservan únicamente filtros permitidos/efectivos y per_page, no cuerpos ni secretos. Detalle: `data: cita_detalle`, sin paginación.

`cita_listada` (lista cerrada): id_cita, codigo_cita, inicio_at, fin_at, duracion_minutos, estado, modalidad, sede_atencion, consultorio_atencion, creada_en; medico {id_medico,codigo_medico,nombres,apellidos}; especialidad {id_especialidad,nombre}; paciente {id_paciente,codigo_paciente,nombres,apellidos}. Solo se incluyen relaciones mínimas del recurso autorizado; sin contactos/CI/nacimiento/motivo. Instantes UTC ISO-8601 con seis decimales y Z; no crear columnas de fecha/hora duplicadas. La UI derivará fecha/hora en zona de sede indicada por API, no UTC local del dispositivo.

`cita_detalle` parte de los mismos campos mínimos y aplica esta matriz estricta. Rol y titularidad se determinan en Service, no con un parámetro del cliente:

| Campo adicional **solo en detalle** | Administrador | Recepcionista | Médico responsable | Paciente titular |
| --- | --- | --- | --- | --- |
| motivo_breve | Clave omitida | Permitido | Permitido | Permitido |
| paciente.documento_identidad | Omitido | Permitido, null si no existe | Omitido | Omitido |
| paciente.telefono | Omitido | Permitido | Omitido | Omitido |
| paciente.fecha_nacimiento | Omitido | Omitido | Permitido para identificar al Paciente en el detalle | Omitido |

Campos permitidos sin dato conservan null; campo no autorizado no aparece. El nacimiento se limita al detalle médico para identificación, no al listado ni a una consulta clínica; no crear historial/diagnóstico ni añadir edad calculada. Paciente ve los mínimos de su propia ficha asociados a su cita, sin endpoint de consulta de otra ficha. Administrador accede a detalle administrativo por CU-20, no a /pacientes/{id}/citas CU-21. Recepción puede consultar citas de fichas sin CI/cuenta o inactivas, sin que ello autorice reservarles citas nuevas.

**Ejemplo ilustrativo de listado (datos ficticios; no respuesta disponible todavía):**

```json
{
  "data": [{
    "id_cita": "17", "codigo_cita": "CIT-FICTICIA", "inicio_at": "2026-11-01T12:00:00.000000Z",
    "fin_at": "2026-11-01T12:15:00.000000Z", "duracion_minutos": 15, "estado": "Programada",
    "modalidad": "Presencial", "sede_atencion": "Sede ficticia", "consultorio_atencion": "Sala original ficticia",
    "creada_en": "2026-10-22T12:00:00.000000Z",
    "medico": {"id_medico": "2", "codigo_medico": "MED-FICTICIO", "nombres": "Profesional", "apellidos": "Ficticio"},
    "especialidad": {"id_especialidad": "1", "nombre": "General ficticia"},
    "paciente": {"id_paciente": "3", "codigo_paciente": "PAC-FICTICIO", "nombres": "Paciente", "apellidos": "Ficticio"}
  }],
  "links": {"first": "/api/mis-citas?periodo=proximas&per_page=20&page=1", "last": "/api/mis-citas?periodo=proximas&per_page=20&page=1", "prev": null, "next": null},
  "meta": {"current_page": 1, "last_page": 1, "per_page": 20, "total": 1, "from": 1, "to": 1,
    "zona_horaria": "America/La_Paz", "consultado_en": "2026-10-22T13:00:00.000000Z",
    "filtros": {"periodo": "proximas", "estado": null},
    "paciente": {"id_paciente": "3", "codigo_paciente": "PAC-FICTICIO", "nombres": "Paciente", "apellidos": "Ficticio"}}
}
```

Links del ejemplo relativos solo para no inventar un dominio de despliegue; la implementación usará el paginador Laravel y la URL de la petición confiable. No depender de posición de campos ni de un nombre ficticio de sede.

### Autorización, consistencia y errores previstos

Service vuelve a exigir sesión normal vigente, rol y vínculo de recurso aunque el middleware ya haya pasado. Reutilizar `AutenticacionService::exigirSesionNormal(bloquear: true)` dentro de una transacción corta como en BitacoraService: actor y sesión revalidados después de esperar, sin exigir continuidad del último Administrador para leer ni coordinar planificación de reservas. Vínculo propio Médico/Paciente derivado de relaciones persistidas y comprobado contra el actor; falta/incompatibilidad rechaza acceso, nunca devuelve historial global/vacío como si fuera válido. Repository aplica el scope autorizado en SQL antes de proyectar/contar/paginar; el detalle ajeno no se carga primero sin scope. No lazy-load de relaciones clínicas ni modelos completos en Resources.

Listado y conteo deben observar la misma lectura de citas mediante snapshot de lectura consistente para la paginación de esa petición (p. ej. consulta única con total o snapshot local a la lectura); no mantener locks de Cita/planificación para toda la navegación. Reutilizar un único t para todos los predicados/metadata de una respuesta. La revalidación de sesión y la captura del snapshot deben coordinarse para no aceptar una revocación confirmada durante la espera del actor: no fijar snapshot antiguo antes de revalidarlo. La elección concreta de SQL se verificará en fase 2 antes de dar por acreditada esta garantía. Entre páginas/peticiones hay lectura actual, no snapshot durable: nuevas reservas o cambios autorizados pueden cambiar totales/orden; ningún dato consultado autoriza una escritura posterior. Serialización con datos materializados dentro del ámbito autorizado y recursos explícitos, sin consultas diferidas tras salir de él.

Errores uniformes existentes `success:false,error:{code,message,details?}`: 401 AUTH_NO_AUTENTICADO (sesión ausente/caducada/revocada), 403 ACCESO_DENEGADO o AUTH_CAMBIO_CLAVE_OBLIGATORIO (rol/restricción), 403 ORIGEN_NO_PERMITIDO, 404 RECURSO_NO_ENCONTRADO (ID inexistente o detalle ajeno con idéntica respuesta), 422 VALIDACION_ERROR (campos/IDs/formato/enums), 429 si aplica infraestructura de límites, 500 ERROR_INTERNO seguro. 419 se conserva si lo produce la infraestructura de sesión/CSRF; estos GET no mutan para renovarlo ni reenvían escrituras. No nuevos códigos 409 por ficha/Médico inactivos: no son errores de estas lecturas. Vacío legítimo = 200, nunca 404; falta de vínculo legítimo = 403, no lista global. Las lecturas no crean Cita/CambioCita/Recordatorio/eventos de negocio nuevos ni envían correo; escrituras técnicas normales de sesión no equivalen a mutar el dominio. No cuerpos completos/CI/motivo en logs, ni caché persistente de respuestas protegidas.

### Clases previstas y verificación por fases

No crear archivos/carpetas vacías ahora. Propiedad del módulo `agenda/` dentro de controllers/dtos/services/repositories, sin mover modelos ni clases de citas de CU-13/CU-14:

- AgendaController, AgendaRequest, MedicoAgendaResource (selector), AgendaService (CU-20) y AgendaRepository (lecturas proyectadas del módulo, reutilizable por CU-21/detalle).
- HistorialCitasController/HistorialCitasRequest/HistorialCitasService para CU-21; ConsultaCitaController/ConsultaCitaRequest/ConsultaCitaService para detalle compartido. Recursos CitaListadoResource, CitaDetalleResource y CitasCollection para proyecciones/contexto/paginación; no reutilizar respuestas de reserva que contienen motivo/sobre o requieren disponibilidad activa.
- Cita/Paciente/Medico/Especialidad existentes, AutenticacionService y middleware/RespuestaError existentes. No nueva entidad Agenda/Historial, repositorio genérico, caché clínica o modificación de CitaRepository de escritura por comodidad.
- Archivo futuro `src/presentation/routes/agenda.php`, registrado una sola vez por `routes/api.php`; GET /citas/{id} puede convivir con POST /citas sin cambiarlo. Sin ruta de actualización/cancelación/Atendida ni exportación/reportes.

Matriz de aceptación pendiente: roles/sesión normal/restringida/expirada; cambios de rol/cuenta/vínculo entre middleware y Service y mientras espera; listados sin motivo ni datos adicionales y detalles con claves omitidas por rol; IDs ajenos/inexistentes indistinguibles; Médico/Paciente inactivos con cuenta activa; profesional histórico sin horarios/par habilitado/sala actual; ficha sin CI/cuenta y paciente sin citas; fecha local vs UTC/microsegundos/fin=t/en curso/cancelada futura y Programada pasada; cinco estados sin transición automática; paginación/desempates/total/contexto vacío/consulta sin efectos; preservar consultorio_atencion tras cambio de asignación/nombre. Fixtures de estados pendientes serán sintéticos aislados, no evidencia de CU-15–CU-19 implementados. Pruebas de cada fase desde su implementación; refuerzo ampliado fase 5 y sesiones reales/kernel/PostgreSQL aislado/regresión en fase 6. No cambiar el estado de citas reales para preparar ejemplos.

**Verificación de fase 1:** versiones y registro real de rutas inspeccionados, decisiones contrastadas con RF11/RF12 y RN10–RN12/RN14; revisión documental/JSON ilustrativo y diff. No suites funcionales ejecutadas ni garantías nuevas acreditadas porque solo cambia documentación. Sin migraciones/SQL/correos/servidores/despliegue, PHP productivo o React modificados. README, reglas/CU y análisis previsto/contexto sincronizados; RF/RN oficiales intactos, DER contrastado sin cambios y diagrama global ausente/aplazado. **Se detiene antes de fase 2.**

## Integración y cierre backend CU-13/CU-14 — fase 5 (2026-10-22)

**Backend de búsqueda y reserva terminado y verificado localmente:** fases 1–5 completadas. Las secciones posteriores conservan evidencia histórica; sus indicaciones de funcionalidades pendientes de fases anteriores quedan sustituidas por este cierre. Se conservan las seis operaciones y contratos documentados, sin endpoints/dependencias/migraciones/índices adicionales en fase 5. No frontend, reprogramación/cancelación, asistencia, scheduler, feriados ni bloqueos opcionales.

**Integración automatizada:** ReservaIntegracionTest incorpora **3 pruebas/129 aserciones**. Kernel HTTP real, cookies/CSRF/origen, sesión cifrada en PostgreSQL y guard/Store renovados entre peticiones, sin actingAs. Preparación administrativa mediante API: especialidad → Médico → consultorio → impacto/asignación → horario. Recepción crea por API una ficha sin CI/cuenta, tras declarar revisión de posibles duplicados; el segundo recorrido usa fixture de Paciente ya activado (no acredita CU-04). Ambos recorren especialidades/Médicos de reserva → disponibilidad → propuesta sin escritura → resultado ausente → confirmación → turno contiguo disponible → logout/rechazo sin sesión → nuevo login → resultado y repetición sin duplicación. Administrador consulta listado/detalle CU-30 del único evento de reserva; actor correcto y sin motivo/token/UUID. No CambioCita inicial ni avisos/correos.

**Medición:** 30 y 300 citas ficticias de un minuto, separadas por un minuto y pertenecientes a la misma fecha, mantienen una sola consulta proyectada de ocupación y **31 consultas totales HTTP constantes** con per_page=1. En regresión final: **18,09/47,66 ms HTTP** respectivamente. Se valida igualdad del conteo y respuesta paginada, sin umbrales frágiles de tiempo; no benchmark de carga, SLA ni certificación productiva. La medición no justifica añadir índices ni refactorizar capas.

**Cierre verificado:** suite completa **1380 pruebas/16361 aserciones correctas**, 8 min 53,003 s/178 MB, mediante `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --colors=never`. Tres casos nuevos de esta fase, **192 acumulados del módulo** frente a las 1188 pruebas anteriores. Primera ejecución focalizada detectó que faltaba declarar revisión de duplicados al crear ficha sin CI; corregida la entrada de la prueba conforme al contrato existente, sin cambiar producción. Sintaxis/Pint, Composer validate/autoload estricto PSR-4/ambigüedad, rutas y diff/documentos comprobados al cierre. Evidencia en contexto §17.91, análisis CU-13/CU-14, fichas, reglas y DER.

**Límites y pendientes:** frontend tendrá plan separado; no se inicia automáticamente. Producción/Railway/HTTPS, aplicación habitual/remota de 000034/000035 y aceptación de navegador no verificadas. Esquema con ambas migraciones probado únicamente en base/rol aislados clinica_vida_salud_test: 28 entidades/244 atributos/43 relaciones. Diagrama global de clases y actualización PHP siguen aplazados expresamente; el cierre backend no afirma resueltos esos pendientes documentales/técnicos. Sin commit/push/despliegue ni cambios en base habitual. Se detiene al terminar esta fase.

## Integridad y concurrencia CU-13/CU-14 — fase 4 (2026-10-22)

**Implementado:** migración aditiva `2026_10_22_000035_prevent_patient_appointment_overlap.php`, sin tablas/columnas nuevas. `citas_paciente_no_solapado_excl` impide intersecciones `[inicio_at,fin_at)` del mismo Paciente **solo en Programada/Confirmada**, también en INSERT/UPDATE y escrituras SQL sin el protocolo aplicativo. Contiguas, otro Paciente y estados no ocupantes siguen permitidos por esta barrera. Médico/consultorio conservan sus exclusiones de toda cita no Cancelada, sin debilitarlas. Inventario con 000035: **28 entidades/244 atributos/43 relaciones**.

**Instalación segura:** transacción y `LOCK TABLE citas IN ACCESS EXCLUSIVE MODE` antes de buscar conflictos y añadir la exclusión. Espera escrituras anteriores y no deja una ventana entre revisión e instalación. Si existen pares incompatibles, aborta con `CITAS_PACIENTE_CONFLICTO_PREVIO` sin IDs ni contenido clínico; ninguna corrección, borrado o cambio de estado automático. Requiere revisión administrativa autorizada, no un script de saneamiento implícito. Down retira únicamente esta exclusión, preservando datos, UUID/UQ y las dos barreras anteriores. No se aplicó a base habitual/Railway: pruebas y migraciones únicamente en base/rol aislados `clinica_vida_salud_test`.

**Errores:** ReservaService traduce exclusivamente el 23P01 de la nueva restricción a 409 `CITA_PACIENTE_SOLAPADO`; Médico/sala mantienen su traducción y SQL desconocido sigue siendo 500 seguro. La pérdida de disponibilidad detectada antes de escribir puede seguir respondiendo `CITA_PROPUESTA_OBSOLETA`, sin identificar al ocupante. No nuevos contratos, endpoints ni cambios de permisos funcionales.

**Pruebas dirigidas de persistencia/API:** `PacienteSolapamientoTest` verifica matriz de estados, consecutivas, otros pacientes, reutilización tras Cancelada, actualización/reentrada de estado, preservación/reversión e instalación abortada sin corregir filas. `ReservaTest` añade inyección SQL real para la traducción del nuevo GiST; `CitasRecordatoriosTest` espera tres exclusiones y usa otro Paciente en el escenario válido de recursos distintos. Ejecución conjunta: **107 pruebas/929 aserciones correctas** antes de la regresión completa.

**Pruebas concurrentes reales:** `ReservaConcurrenteTest` crea un esquema efímero `prueba_auth_*` con todas las migraciones, adapta solo allí la función de bitácora, usa sesiones/cookies/CSRF por kernel y procesos PHP/PID PostgreSQL distintos. Barreras de pipes explícitas y espera `pg_stat_activity.wait_event_type=Lock`, no temporizadores usados como prueba de serialización. Worker valida base/rol/esquema y bloquea HTTP externo; limpieza acotada al esquema propio. `autenticacion-worker.php` y `PausarEscrituraPlanificacionRepository` se amplían solo como fixtures, sin ruta/comando de aplicación.

- **10 casos:** mismo Médico, mismo Paciente en recursos distintos, misma propuesta, resultado durante escritura pendiente y citas contiguas; commit/rollback del primer escritor, visibilidad de filas, una cita/evento por confirmación, replay de la misma identidad o resultado ausente tras rollback.
- **8 casos:** INSERT SQL que omite coordinación para Paciente, Médico y sala, más instalación de la migración esperando una escritura conflictiva anterior; commit rechaza (23P01 o marcador previo), rollback permite insertar/instalar y preserva el resto de restricciones.
- **16 casos:** estado/rol/sesión/hash de contraseña cambiados después de middleware mientras el Service espera coordinación administrativa; commit/rollback y confirmación/resultado. Revocación confirmada → 401 sin reservar; rollback conserva operación válida.
- **28 casos:** reserva real frente a horario, duración, asignación de consultorio, habilitación del par, inactividad de Médico/especialidad/Paciente, en ambos órdenes y con commit/rollback del primero. Si cambia antes, la propuesta antigua no se confirma; si reserva antes, horario sin cobertura/retiro del par/asignación con impacto cambiado se rechazan. Cambiar duración o inactivar permite conservar la cita existente, sin alterar su estado/intervalo/duración/sala ni crear CambioCita. La sala destino está libre; no se fabrican horarios incompatibles para conseguir dos propuestas válidas de la misma sala.

Resultado dirigido multiproceso: **62 pruebas/1218 aserciones correctas**. **Regresión completa final: 1377 pruebas/16232 aserciones correctas**, 10 min 15,366 s, memoria 178 MB; comando `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --colors=never`, sin cambiar php.ini ni configuración productiva. Fase 4 añade 77 casos frente a los 1300 anteriores (14 de persistencia, 62 concurrentes y uno de traducción SQL). Estas pruebas verifican escenarios locales deterministas, no constituyen benchmark/SLA ni certificación productiva. No frontend, CU-15/CU-16/CU-28, feriados ni bloqueos opcionales. Diagrama global/PHP siguen aplazados; fase 4 cerrada localmente y fase 5 requiere autorización posterior.

Las secciones siguientes conservan evidencia histórica de fases 1–3; sus pendientes de GiST/carreras quedan actualizados por esta sección y contexto §17.90/análisis/DER.

**Compatibilidad de fixtures previos:** PlanificacionGarantiasTest también usa otro Paciente ficticio en la inyección tardía de sala ocupada y en el barrido de intervalos proyectados. Esos escenarios deben probar el conflicto de consultorio, no introducir primero un solapamiento prohibido del mismo Paciente. Se conservan las expectativas de conflicto, atomicidad y contigüidad originales. La primera regresión había cargado ambos fixtures anteriores y terminó con un error y un fallo; después del ajuste pasaron las 9 pruebas/429 aserciones de esa clase y la ejecución final completa 1377/16232. No se relajaron restricciones ni se modificó producción para corregir datos de prueba.

**Cierre técnico fase 4:** Pint/sintaxis, Composer validate/autoload estricto PSR-4/ambigüedad, 65 rutas totales/seis del módulo sin duplicados y revisión documental/diff verificados. README, contexto §17.90, análisis CU-13/CU-14, ficha CU-14, reglas y DER sincronizados. El esquema habitual/remoto no se migró; no commit/push/despliegue. Se detiene sin iniciar fase 5 ni declarar cerrado el módulo integral.

## Reserva presencial CU-14 — fase 3: propuesta, confirmación y resultado (2026-10-22)

**Implementado:** POST `/api/reservas/propuesta`, `/api/citas` y `/api/reservas/resultado`, exclusivos de Recepción/Paciente con sesión normal. Completan las seis operaciones diseñadas del módulo; todavía faltan el refuerzo GiST/carreras de fase 4 y la integración/cierre de fase 5. No frontend, reprogramación, cancelación, scheduler ni correos de citas.

**Propuesta:** ReservaRequest valida campos cerrados, selección canónica/revision_turno y motivo breve opcional (trim, vacío→NULL, máximo técnico 500, sin controles). ReservaController delega a ReservaService. Bajo una transacción reutiliza DisponibilidadService para reconstruir el mismo candidato con locks y HMAC; no copia una disponibilidad calculada en React. Cambiar Paciente, hora no anclada o cualquier condición revisada incompatible produce rechazo, no una propuesta corregida silenciosamente. Devuelve UUID, sobre cifrado autenticado de Laravel y resumen mínimo de Paciente/Médico/especialidad/consultorio/inicio/fin/duración/sede/dirección/motivo. No inserta tabla ni ocupa temporalmente el turno. PropuestaReserva liga propósito/formato/actor/rol/ficha/selección/revisión/resumen y HMAC del motivo; no guarda el texto del motivo dentro del token ni lo registra. Dos revisiones pueden coexistir, pero no garantizan reserva.

**Ajuste técnico respecto del diseño de fase 1:** el cifrado usa directamente `Crypt::encryptString/decryptString`, con propósito/versión dentro del sobre autenticado, para conservar la infraestructura nativa sin encrypter paralelo; revisión y motivo sí usan subclaves HMAC derivadas por propósito. No es JWT ni credencial de autenticación. Entrada cifrada alterada, propósito/formato inválidos o motivo distinto del revisado → 422 seguro; token de otro actor/rol → 403 genérico. No se impone vencimiento comercial arbitrario; una creación nueva exige inicio estrictamente futuro y compatibilidad vigente. Rotación de APP_KEY no es parte de esta entrega ni procedimiento de recuperación de una propuesta.

**Confirmación:** devuelve 201 `data` de cita mínima vigente y `meta.repetida=false`; genera código CIT aleatorio único, usa actor real, estado Programada, Presencial y version_programacion=1. Guarda duración y ubicación efectivas, no acepta campos privilegiados del cliente. Compara revisión y resumen completo, incluida identidad mínima mostrada del Paciente; CitaRepository consulta también solapamiento de Paciente Programada/Confirmada y persiste. Disponibilidad filtra los tres recursos, por lo que una pérdida de turno suele responder CITA_PROPUESTA_OBSOLETA antes de esa segunda barrera. El servidor vuelve a comprobar sesión e inicio antes de insertar. No crea CambioCita inicial, recordatorio ni correo.

**Identidad durable:** migración aditiva `2026_10_22_000034_add_id_propuesta_reserva_to_citas.php`: `citas.id_propuesta_reserva UUID NULL` y UQ `citas_propuesta_reserva_uq`. Filas existentes conservan NULL; no backfill, tabla adicional ni sustitución de PK/código/FK. Modelo Cita oculta UUID en serialización genérica. Una misma propuesta propia ya guardada devuelve 200 y `meta.repetida=true`, la misma cita en su **estado actual**, sin otro evento ni restaurar Programada/duración/ubicación. Esquema con 000034: **28 entidades/244 atributos/43 relaciones**. Migración/reversión/preservación/unicidad probadas solo en base aislada; no se aplicó a base habitual/Railway.

**Consulta del resultado:** POST con la propuesta únicamente → `data.registrada=false` si no existe, o true con `data.cita`. Bajo coordinación de planificación espera escritores compatibles, valida actor/rol/ficha/creador e identidad de sesión; no interpreta NULL como una autorización para crear. Una ficha inactiva o Médico/horario/duración cambiados no impiden consultar/repetir una cita ya creada autorizada, pero sí una reserva nueva. Nuevo login del mismo actor puede reconciliar el comprobante conservado de forma autorizada; token ajeno/cuenta revocada no puede consultar. Red/500 incierto sigue exigiendo consulta explícita del cliente, nunca reenvío automático. Propuesta/motivo no deben persistirse en URL/history.state/Web Storage/logs.

**Transacción/concurrencia actual:** coordinación administrativa → actor → planificación → evaluación de recursos/ficha/citas de CU-13 → comparación/revalidación → inserción + bitácora. ReservaService llama unidireccionalmente a DisponibilidadService dentro de la transacción exterior; los locks ya adquiridos se conservan hasta commit. Resultado/replay no necesitan reconstruir recursos: búsqueda inicial de UUID sin lock bajo planificación, ficha bloqueada y relectura de Cita bloqueada, antes de responder. La barrera común y lock de ficha protegen escritores aplicativos participantes; la exclusión SQL del Paciente **aún no existe**, corresponde a fase 4. No se declara protección de ese recurso frente a escrituras arbitrarias que omitan el protocolo.

**Errores y atomicidad:** 409 CITA_PROPUESTA_OBSOLETA/CITA_PACIENTE_NO_DISPONIBLE/CITA_PACIENTE_SOLAPADO y CITA_TURNO_NO_DISPONIBLE; AppError admite detalles opcionales, pero RespuestaError solo publica categorías permitidas de cambios para propuesta obsoleta (sin identidad del ocupante ni valores internos). Si puede distinguir duración/sala lo indica; de lo contrario informa DISPONIBILIDAD sin inventar una causa exacta. Exclusiones GiST existentes conocidas 23P01 → 409; SQL inesperado → 500 seguro. Colisión aleatoria de código usa hasta tres intentos de inserción en savepoints, sin repetir auditoría. Colisión UUID conocida se reconcilia fuera de la transacción abortada; no repite creación por defecto. Cita y evento CITA_PROGRAMADA se confirman/revierten juntos, también ante fallo inyectado después de insertar bitácora.

**Auditoría CU-30:** productor citaProgramada con Cita como entidad principal y referencias id_cita/Paciente/Médico/especialidad/consultorio; cambios permitidos origen PROPIA/RECEPCION, estado Programada y duración. ReferenciasBitacora y BitacoraDetalleResource exponen exclusivamente esas claves, filtros existentes por recursos funcionan sin joins clínicos; solo Administrador consulta. No se registran motivo, UUID de propuesta, token, huellas, CI ni contenido clínico.

**Verificación dirigida:** **56 pruebas nuevas/678 aserciones correctas** en ReservaTest, sobre base/rol clinica_vida_salud_test, sesiones/cookies/CSRF por kernel y HTTP externo impedido. Flujos de ambos actores/revisión sin escritura, repetición/resultado durable/nuevo login/estado vigente, condiciones cambiadas, aislamiento de Paciente, motivos (incluido "0"), entradas cerradas/tokens alterados/propósito y roles, auditoría filtrada, migración con filas anteriores/UUID único/NULL; colisión de código/savepoint, exclusión GiST real y SQL inesperado con rollback. Los escritores tardíos son inyecciones en la misma conexión: no acreditan carreras multiproceso nuevas. El test previo de CU-13 que esperaba ausencia de POST ahora comprueba 422 por faltar propuesta, porque estos endpoints ya existen; CitasRecordatoriosTest incorpora la columna aditiva en su inventario exacto esperado. La regresión completa se justifica por migración/errores/bitácora: intento inicial interrumpido por memory_limit CLI de 128 MB, repetición con 512 MB exclusivamente para el proceso de pruebas, sin cambiar php.ini/proyecto/producción. Resultado final en contexto §17.89 al concluir.

**Verificación final/cierre local de fase 3:** regresión completa **1300 pruebas/14957 aserciones correctas**, 8 min 54,64 s, memoria 158 MB, mediante `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --colors=never`. El límite CLI original de 128 MB interrumpió el primer intento; el inventario anterior de CitasRecordatoriosTest se ajustó a la nueva columna y su comprobación específica pasó (1 prueba/15 aserciones) antes de la ejecución final completa. No se cambió php.ini ni configuración de producción. Acumulado del módulo: 112 pruebas nuevas frente a las 1188 previas. Pint/sintaxis de 24 PHP afectados acumulados, Composer validate/autoload PSR-4/ambigüedad estrictos, 65 rutas totales/seis de reservas sin duplicados, UTF-8/Markdown y git diff --check correctos. README/contexto §17.89/análisis CU-14/ficha/reglas/DER sincronizados. Diagrama global y actualización PHP siguen aplazados; fase 3 cerrada localmente, sin adelantar fase 4 ni afirmar cierre integral del módulo.

## Búsqueda y disponibilidad CU-13 — fase 2 (2026-10-22)

**Implementado en backend:** tres GET `/api/reservas/especialidades`, `/api/reservas/medicos` y `/api/reservas/disponibilidad`, con los contratos de fase 1, exclusivos de Recepcionista/Paciente con sesión normal. Los otros tres endpoints de propuesta/confirmación/resultado siguen **previstos**, no registrados; CU-14 no está implementado. No frontend, migración, columna, índice ni dependencia nuevos.

**Capas reales:** DisponibilidadController + DisponibilidadRequest/Resource en presentation/citas, DisponibilidadService en business/services/citas y DisponibilidadRepository en data/repositories/citas; archivo de rutas reservas.php registrado por el puente. Requests cierran campos/IDs/fechas/paginación, Controller delega, Service decide autorización/ocupación/anclaje y transacción, Repository hace consultas Eloquent y proyecciones mínimas. IntervalosAtencion incorpora candidatos por intervalo; PropuestaReserva implementa **solo revisión HMAC** con clave derivada por propósito de la clave Laravel, sin emitir todavía tokens cifrados ni persistir propuestas.

**Comportamiento:** especialidades activas, Médicos activos con asociación habilitada, sala activa y algún horario activo; filtros literales por nombre/código o nombre de consultorio, paginación y orden definidos. Catálogos no revelan contacto/cuenta/estados administrativos; los endpoints administrativos mantienen sus permisos previos. Disponibilidad exige Paciente seleccionado por Recepción o deriva exclusivamente la ficha del Paciente autenticado; un id_paciente enviado por este último es 422 y también se protege el Service contra selección ajena. Ficha sin CI/cuenta admite consulta por Recepción; ficha inactiva puede consultar con paciente_reservable=false, sin autorizar reserva futura.

**Turnos:** cuadrícula por cada horario activo y día ISO en America/La_Paz, preservando segundos/sobrante y sin fusionar intervalos contiguos. Inicio estrictamente futuro con microsegundos tras obtener locks; fecha pasada local → 422, fecha futura sin límite arbitrario, sin turnos → 200 vacío. Se excluye cualquier intersección no Cancelada de Médico/sala y Programada/Confirmada del Paciente aun con otros recursos; ocupa la sala real de la cita aunque haya cambiado la asignación actual. Consulta de ocupación por intersección del día, no solo fecha inicial, incluyendo citas multidía; franjas activas compartidas de Médicos inactivos también bloquean. Intervalos contiguos quedan libres. Proyección temporal de ocupantes una sola vez antes de recorrer candidatos, sin casts repetidos por cada turno.

**Revisión y seguridad:** revision_turno cambia con actor/rol/Paciente/actividad de ficha/selección/versiones/datos relevantes, no por página, reloj crudo u otra cita fuera del intervalo. No es autorización ni retención de espacio. Respuesta solo turno/ubicación/duración/revisión, sin identidad, IDs de citas ni motivos de ocupantes. Sesiones/cookies/origen existentes, respuestas privadas/no-store, BIGINT textual y errores seguros; nueva selección inactiva/inhabilitada/sin sala → 409 CITA_TURNO_NO_DISPONIBLE, recurso inexistente → 404. Orden coordinación administrativa → actor → planificación → configuración → Médico → especialidad/pivote → sala → horarios → Paciente → citas; revalidación de sesión/identidad al terminar esperas, sin exigir la existencia de otro Administrador para reservar. Sin modificar datos de negocio ni producir eventos de alta de citas.

**Verificación de fase 2:** **56 pruebas nuevas** en DisponibilidadTest: anclaje/segundos/intervalos/sobrantes, ocupación de tres recursos/estados/multidía, límites de inicio/fecha local/horizonte, aislamiento de Paciente, HMAC estable y cambios relevantes, filtros/proyecciones, sesión/rol/origen/entradas cerradas y ausencia de endpoints de fase 3. Inyecciones de revocación/cambio de rol después de planificación usan la misma conexión y no acreditan carreras multiproceso; estas quedan para fase 4. Regresión dirigida `DisponibilidadTest|HorariosTest|ConsultoriosTest|PlanificacionIntegracionTest|PlanificacionGarantiasTest`: **229 pruebas/2670 aserciones correctas**, 69,02 s, base/rol aislados clinica_vida_salud_test, HTTP por kernel con cookies/CSRF y correo externo impedido. Incluye asignación y pruebas de persistencia previas por coincidencia del filtro, sin repetir toda la suite 1188. Pint, sintaxis de 11 PHP afectados, Composer validate/autoload PSR-4/ambigüedad estrictos y tres rutas nuevas verificados; sin benchmark/SLA de disponibilidad ni aceptación productiva. No se ejecutaron pruebas frontend porque no cambió React.

**Cierre:** CU-13 backend implementado localmente; integración del módulo completo conserva fase 5. README/contexto §17.88/análisis CU-13/ficha/reglas sincronizados; esquema/DER efectivo sin cambios (UUID/exclusión del Paciente aún futuros). Próxima fase autorizable: 3 CU-14 propuesta/reserva/resultado e identidad durable. Base habitual/Railway sin tocar; no commit/push/despliegue. Diagrama global y actualización PHP siguen aplazados. Se detiene sin implementar fase 3.

## Búsqueda y reserva CU-13/CU-14 — fase 1: diseño y contratos (2026-10-22)

**Solo diseño documentado; no implementado:** ninguno de los seis endpoints siguientes, las clases nuevas ni las migraciones previstas existe por esta entrega. Fase 1 no acredita CU-13/CU-14 funcionales. Inspección: PHP 8.5.11, Laravel instalado 13.37.0, Composer 2.10.3, Sanctum/cookies, Cita/horarios/configuración y protocolo de planificación existentes. Sin cambios PHP, frontend, dependencias, esquema, datos ni despliegue. Requisitos: RF03/RF05/RF06/RF07 y RN03–RN08/RN12–RN14; se conservan íntegros sus textos oficiales.

### Decisiones funcionales aprobadas

1. Cada intervalo semanal activo genera `inicio_intervalo + k × duración_vigente`, k entero no negativo, únicamente si el turno entero cabe. Anclaje por intervalo, nunca por hora redonda, espacios libres o fin de otra cita. Se preservan segundos locales; intervalos contiguos no se fusionan. Ejemplo 08:00–09:00/20: 08:00, 08:20, 08:40. Una cita histórica de distinta duración elimina los candidatos que intersecta, sin desplazar la cuadrícula.
2. Paciente sin dos citas **Programada/Confirmada** superpuestas, aunque cambien Médico/especialidad/consultorio. Intervalos `[inicio, fin)`: se permiten consecutivos. Es ampliación aprobada del proyecto, no texto de RN07. Médico/consultorio mantienen su barrera existente para todas las citas **no Canceladas**, también estados finales con intervalo futuro; no se debilitan esas exclusiones para igualarlas a la del Paciente.
3. Inicio estrictamente posterior al instante del servidor capturado después de bloqueos; fecha/hora local America/La_Paz, comparación de instantes en UTC. Sin anticipación mínima ni máximo funcional de días; hoy es válido mientras el turno no haya empezado. Consulta por una fecha civil válida representable, no calendario de turnos persistidos. Límite de formato de fecha no equivale a horizonte de reservas.
4. No guardar condiciones distintas de las revisadas. Cambios relevantes en horario/duración/sala/habilitación o pérdida de disponibilidad invalidan la propuesta; respuesta segura y nueva revisión explícita. No sustituir hora, Médico, duración o consultorio automáticamente. Cambios de ocupación ajenos al turno que sigue libre no obligan a invalidar toda la fecha.

### Contrato HTTP previsto (prefijo /api)

Solo Recepcionista/Paciente con sesión normal, cuenta habilitada y controles existentes de origen, CSRF y respuestas `private, no-store`. Administrador/Médico reciben 403; no se amplían los endpoints administrativos `/especialidades`, `/medicos`, `/consultorios`, `/configuracion-citas`. No hay búsquedas públicas por CI/correo. Recepción reutiliza su selector `/pacientes` existente; ficha sin CI puede recibir reserva presencial y no necesita cuenta web. La inactividad de una cuenta de Paciente no sustituye el estado de su ficha al programar presencialmente.

| Método/ruta **previstos** | Entrada cerrada | Resultado previsto |
| --- | --- | --- |
| GET /reservas/especialidades | nombre opcional, page/per_page | 200 data[]: id_especialidad/nombre, solo activas; links/meta |
| GET /reservas/medicos | id_especialidad obligatorio; nombre y consultorio opcionales; page/per_page | 200 data[]: id_medico/codigo_medico/nombres/apellidos y consultorio mínimo; solo activos, par habilitado, especialidad/sala activas y al menos un horario activo; links/meta |
| GET /reservas/disponibilidad | id_especialidad, id_medico, fecha YYYY-MM-DD; id_paciente solo Recepción y obligatorio para ella; page/per_page | 200 data: fecha/zona_horaria/consultado_en/paciente_reservable, Médico/especialidad/sala mínimos, duracion_minutos y turnos[]; links/meta |
| POST /reservas/propuesta | los mismos IDs/fecha/id_paciente según actor, hora_inicio HH:mm:ss, revision_turno, motivo_breve opcional | 200 data: propuesta opaca, id_propuesta UUID, resumen completo autorizado de Paciente/Médico/especialidad/ubicación/inicio/fin/duración/motivo; sin persistir ni reservar |
| POST /citas | propuesta opaca y motivo_breve opcional, idéntico al revisado | 201 primera creación; 200 repetición confirmada de la misma propuesta; data: cita mínima vigente, meta.repetida booleano |
| POST /reservas/resultado | propuesta opaca | 200 data: registrada booleano y cita mínima si existe; consulta explícita tras respuesta incierta, nunca una creación |

Paginación técnica: page 1–1000000, per_page 1–100, 20 por defecto; orden especialidad nombre/ID, Médico apellidos/nombres/ID y turnos inicio/ID de intervalo. Búsquedas literales parciales sin distinguir mayúsculas, no comodines SQL: nombre 1–120 y consultorio 1–120 (código o nombre dentro de la sede única, no sedes nuevas). IDs BIGINT positivos transmitidos como cadenas, sin redondear; horas/fechas canónicas, instantes de salida UTC ISO/seis decimales más zona local. Campos extra o privilegiados producen 422. Motivo nullable/opcional, trim y máximo técnico previsto 500 caracteres para el texto breve; no es límite de anticipación ni nueva obligación del examen. No se permite enviar estado, fin, duración efectiva, código, creada_por, modalidad ni ubicación de persistencia.

Cada turno devuelve inicio_at/fin_at/hora_inicio y `revision_turno` HMAC-SHA256 hex de 64 caracteres. La revisión liga propósito/versión de formato, actor/rol, Paciente autorizado, intervalo seleccionado, versiones de Médico/especialidad/configuración/sala, habilitación y datos de ubicación/nombres mostrados. No incluye el reloj crudo ni todas las citas del día. La disponibilidad solo devuelve candidatos sin ocupación; no lista citas ajenas, motivos, identidad del ocupante, contacto profesional ni datos clínicos. Una ficha inactiva puede consultar CU-13 con `paciente_reservable=false`, pero propuesta/creación nueva fallan; consultar no habilita reservas. Catálogo sin resultados o día sin turnos devuelve lista vacía, no 404.

### Propuesta, confirmación y resultado incierto

- **Búsqueda → revisión:** POST propuesta recalcula el turno y su HMAC con recursos actuales bajo transacción. Exige misma revision_turno, anclaje, inicio futuro, disponibilidad y Paciente activo. Si cambió la base revisada o se ocupó el turno, 409 y nueva búsqueda/revisión, no una propuesta corregida silenciosamente.
- **Propuesta → guardado:** usar `Crypt` autenticado de Laravel, no JWT ni autenticación alternativa. Sobre cifrado de propósito/versión, UUID aleatorio, actor/rol/Paciente, selección y snapshot/huella de recursos, más HMAC del motivo normalizado (sin texto clínico dentro del token). Resumen público separado para que React lo muestre antes de confirmar. Revisión HMAC y cifrado usan claves derivadas por propósito de la configuración segura existente; no nuevas credenciales en frontend. El token no concede permisos y no reemplaza autenticación/CSRF/relectura de estados.
- Propuesta sin tabla propia, sin estado de cita nuevo y sin retención temporal del turno. No plazo comercial arbitrario: antes de crear exige que el turno siga futuro y las condiciones compatibles. En cada confirmación descifrar/validar propósito/formato/actor, cotejar el motivo y la selección, y recalcular las condiciones bajo locks. Token alterado/mal formado → 422 seguro; actor ajeno → 403 genérico. Propuesta incompatible → 409, sin guardar ni emitir una propuesta nueva automáticamente.
- **Identidad durable prevista:** añadir en fase 3 `citas.id_propuesta_reserva UUID NULL UNIQUE`, generado solo por el servidor en la revisión. Filas antiguas permanecen NULL; no backfill destructivo, sin tabla de turnos, sin exponer la columna en listados generales. Esa migración es necesaria para el contrato de reenvío, no se confía en caché/memoria de proceso. La clave se conserva aun si luego cambia el estado/ubicación de la cita.
- Después de autenticar/autorización actual, una propuesta propia ya registrada devuelve la **misma cita actual autorizada**, 200/repetida=true, sin volver a comprobarla como si fuera una reserva nueva, sin restaurar Programada ni duplicar bitácora. Cotejar actor/creador/Paciente/UUID y motivo revisado; no aceptar reutilizar el identificador para otra petición. Una cuenta revocada no obtiene el resultado. Ficha inactiva no impide consultar una reserva previa autorizada: no se crea otra.
- Red/timeout/500 al guardar: React no afirma rollback ni reenvía; conserva propuesta solo en memoria y ofrece consultar POST resultado. Se espera cualquier escritor compatible antes de contestar `registrada=false`; significa ausencia confirmada en ese instante, no permiso para reenviar automáticamente. Si existe, mostrar cita vigente; si no, el usuario decide revisar o confirmar expresamente la propuesta todavía válida. Sin prueba recuperable tras recargar/cerrar sesión, no reconstruir una solicitud como nueva por suposición ni buscarla públicamente por CI. URLs/history.state/Web Storage/logs/bitácora no contienen token, motivo ni cuerpos de reserva.

### Capas previstas, sin crear archivos vacíos

`src/presentation/routes/reservas.php` y `citas.php`, registrados por el puente `routes/api.php`; middlewares existentes. `controllers/citas/DisponibilidadController` (catálogos/turnos) y `ReservaController` (propuesta/guardar/resultado), con Requests/Resources concretos en `dtos/citas/`. FormRequests validan estructura, Controllers HTTP, sin Eloquent de negocio.

`services/citas/DisponibilidadService` y `ReservaService` aplicarán permisos/selección/reglas y transacciones, sin Request/Response. `repositories/citas/DisponibilidadRepository` proyectará catálogos/horarios/ocupación y `CitaRepository` encapsulará lectura bloqueada/inserción/búsqueda de propuesta. Reutilizar modelos reales, PlanificacionRepository, AutenticacionService y coordinación administrativa; añadir consulta mínima bloqueada de Paciente sin cargar emergencia/preferencias. `validators/IntervalosAtencion` podrá ampliarse para candidatos anclados; `shared/utils/PropuestaReserva` encapsulará solamente huellas/cifrado/formato, sin decidir permisos ni consultar DB. Nombres previstos, no clases existentes ni compromiso de una clase por endpoint.

### Concurrencia, ocupación y persistencia previstas

Una transacción corta por consulta/revisión/confirmación; jamás mantener un lock mientras el usuario confirma en React. Orden del escritor: coordinación administrativa `(736201,1)` → actor bloqueado/sesión normal → planificación `(736201,2)` → configuración → Médico → especialidad/pivote → consultorio → horarios por ID → Paciente autorizado bloqueado → citas relevantes por ID. Releer vigencia de sesión/actor tras esperas antes de responder/insertar. Reutilizar orden existente, no tomar locks de otros Usuarios después de los recursos. La barrera administrativa no invoca `verificar()` de continuidad para exigir un Administrador al reservar: solo coordina contra mutaciones de CU-03. Catálogos/estado de especialidad se releen bloqueados, pues su escritor actual no toma planificación; Paciente también se bloquea porque CU-07/CU-08 no toman planificación. No añadir cambios a esos módulos por uniformidad.

Las lecturas de disponibilidad deben ser coherentes con confirmación: misma coordinación/orden para la evaluación breve de recursos, sin copiar una política de autorización administrativa. Consulta de catálogos no bloquea masivamente todos los Médicos; una sala/horario inactivo no ofrece turnos. Para ocupación de día, consultar intervalos que **intersectan** `[medianoche_local, siguiente_medianoche_local)`; no filtrar solo por fecha de inicio (puede haber cita histórica multidía). Revisar citas no Canceladas del Médico/sala, aunque cambie la asignación actual de su Médico, y Programadas/Confirmadas del Paciente en cualquier recurso. Considerar además franjas compartidas activas de otros Médicos, incluso inactivos; inactivarlos no libera esa ocupación administrativa. El propio horario es cobertura, no ocupación contra sí mismo.

Revisar HMAC/snapshot y reglas con instante fresco tras locks. Insertar Cita con UUID de propuesta, código aleatorio único, actor real, duración/ubicación efectivas, version_programacion=1 y evento `CITA_PROGRAMADA` de CU-30 en la misma transacción. Evento solo referencias seguras de Cita/Paciente/Médico/especialidad/consultorio, origen RECEPCION/PROPIA, estado Programada y duración; sin motivo, CI, contraseña ni token/HMAC. Proyección y filtros de CU-30 se amplían solo al implementar. Crear no es reprogramar: no inventar CambioCita inicial ni generar recordatorios en esta fase. Recordatorio conserva integración futura por id_cita/Paciente/programación en CU-28.

**Fase 3:** UUID único y protocolo aplicativo de Paciente/planificación impiden duplicación/solapamiento entre escritores reales participantes. **Fase 4:** migración aditiva de refuerzo `citas_paciente_no_solapado_excl`: `EXCLUDE USING gist (id_paciente public.gist_int8_ops WITH =, tstzrange(inicio_at,fin_at,'[)') WITH &&) WHERE (estado IN ('Programada','Confirmada'))`. Conserva exclusiones existentes de Médico/sala. Antes de instalar, detectar pares de citas vigentes incompatibles; abortar con diagnóstico administrativo protegido si existen, sin alterar/borrar datos ni omitir la restricción. `now()` no forma parte del predicado del índice: la ocupación depende de estado/intervalo, no de un índice que envejezca. Validar también futuros CU-15/transiciones; no implementar esos CU aquí. No se anuncia garantía DB del Paciente hasta instalar/probar esa fase.

Traducir exclusivamente 23P01 de exclusiones conocidas a 409 (ocupación de recursos o Paciente). 23505 del UUID propio permite reconciliar la misma operación en transacción nueva; código de cita colisionado se maneja de forma acotada antes de devolver éxito, sin reejecutar ciegamente efectos externos. Otros errores SQL siguen como 500 seguro. No depender de una consulta previa vacía ni de bloquear solo filas existentes; planificación/Paciente y restricciones protegen también la ausencia. Ningún correo/proceso externo forma parte de rollback SQL.

**Errores previstos:** 422 VALIDACION_ERROR (contrato existente) para estructura/fecha imposible/token inválido; 401 sesión; 403 rol/titularidad sin revelar identidad; 404 recurso inexistente; 409 CITA_PROPUESTA_OBSOLETA, CITA_TURNO_NO_DISPONIBLE, CITA_PACIENTE_SOLAPADO o CITA_PACIENTE_NO_DISPONIBLE. Instante alcanzado/anclaje/base cambiada exige nueva revisión (409), no 500. `error.details.cambios` solo categorías seguras (HORARIO/DURACION/CONSULTORIO/HABILITACION/DISPONIBILIDAD); no identificadores/datos del ocupante. 419/429/Retry-After/500 mantienen manejador común. Códigos nuevos aún no registrados; valores definitivos deberán corresponder a AppError/RespuestaError y tests al implementarlos.

### Ejecución por fases y matriz de aceptación

1. **Esta fase:** decisiones/contratos/estrategia/análisis previstos, sin endpoints/migraciones/tests funcionales nuevos.
2. **CU-13:** catálogos de reserva y disponibilidad con revisión por turno; pruebas de anclaje/segundos/intervalos separados/contiguos/sobrante, ocupación parcial e histórica/multidía, estado de todos los recursos, fecha futura/hoy/pasada e inicio exactamente ahora. Listas paginadas/no enumeración/filtros y cuatro roles; no persistir propuestas ni turnos.
3. **CU-14:** propuesta cifrada, creación/resultado/UUID único, autorización de Paciente/Recepción y validación efectiva de los tres recursos; consentimiento de la información revisada, estados/sesión/vínculo, motivo opcional, duplicados/replay autorizado/resultado incierto, código/instantáneas/auditoría atómicos y rollback inyectado. Revisión válida no reserva espacio.
4. **Garantías:** refuerzo GiST del Paciente/migración con registros previos, carreras en conexiones/PID independientes y ambos órdenes commit/rollback: mismo Médico, misma sala con Médicos distintos, mismo Paciente con recursos distintos, misma propuesta; contra horario/duración/asignación/habilitación/actividad de Médico/especialidad/Paciente/sesión. Ningún éxito parcial ni evento duplicado; propuestas obsoletas nunca actualizadas automáticamente. Escritores de CU-15 aún no implementados se declaran fixtures, no funcionalidades acreditadas.
5. **Integración/cierre backend:** kernel HTTP real/cookies/CSRF/origen, recorridos de ambos actores, CU-30 y medición de consultas/tiempo con dataset representativo; regresión backend completa al cierre. Base/rol aislados y correo simulado; no ejecutar suite 1188 por esta entrega exclusivamente documental. Después, plan de frontend separado. README/contexto/reglas/CU/análisis sincronizados progresivamente y DER cuando se apliquen cambios; diagrama global/PHP aplazados expresamente. Cada fase exige autorización separada.

## Consultorios y horarios CU-11/CU-12 — fase 6: integración backend (2026-10-22)

**Backend administrativo CU-11/CU-12 terminado y verificado localmente:** fases 1–6 completadas. PlanificacionIntegracionTest añade tres recorridos completos con **183 aserciones correctas**. Peticiones por el kernel HTTP real de Laravel, CSRF/origen, cookies y sesiones PostgreSQL cifradas, guard/Store recreados en cada petición; no actingAs ni autenticación simulada. Esto es integración automatizada local, no aceptación en navegador/HTTPS/Railway.

- Alta real por API de especialidad, Médico y salas → asignación inicial → alta/edición/desactivación/reactivación de horario → registro administrativo real de Paciente por Recepción → fixtures de citas Programada/Confirmada y avisos leídos → impacto/cambio de duración → impacto/traslado expreso de ambas citas → renombrado/no-op que preserva instantáneas → desactivación de sala origen libre. Comprueba todas las columnas no afectadas de citas y avisos intactos, ubicación del aviso mediante Recordatorio.cita y CambioCita sin duplicados.
- Consulta real de listado/detalle CU-30 de los **ocho tipos de evento del módulo**, responsable y resultado correctos, sin CI/clínica; Recepción no accede a bitácora. Logout, rechazo sin sesión y nuevo login recuperan asignación persistida.
- Inactividad profesional conserva horario activo; desactivación expresa libera su franja, reactivar Médico no reactiva horario. Otro Médico puede compartir esa sala con horario propio; reactivar un horario incompatible se rechaza y un cambio autorizado a otra sala permite reactivarlo, sin crear cuentas ni citas.
- Consulta documentada con iniciada_en NULL impide traslado. Tras pasar a fecha histórica y realizar nuevo login por caducidad de sesión, cambio de asignación válido conserva byte a byte filas de cita, consulta, versiones clínicas/antecedentes, solicitud/documento/versiones/revisión de laboratorio. No mueve ubicación histórica ni crea CambioCita clínico; auditoría solo referencias y cero traslados.

**Límites:** las filas de citas/avisos/clínica/laboratorio son ficticias y se preparan con fixtures; estos recorridos no acreditan endpoints CU-14/CU-23–CU-28, entrega de archivos ni scheduler. Las operaciones de planificación/administración/CU-30 sí usan sus endpoints implementados y sesiones reales. No se ejecuta Vite ni se acredita interfaz de Consultorios/Horarios. Sin nueva migración/dependencia/endpoint/índice; 28 entidades/243 atributos/43 relaciones con migración 000033 previa. Base habitual/Railway, aplicación de 000033 y aceptación productiva siguen sin comprobarse. Sin commit/push/despliegue; diagrama global y actualización PHP conservan aplazamiento expreso.

**Verificación final/cierre local de fase 6:** **1188 pruebas/13798 aserciones correctas**, 6 min 31 s, memoria 154 MB, base/rol aislados clinica_vida_salud_test. Tres pruebas nuevas de integración; acumulado del módulo: **201 pruebas nuevas** respecto de las 987 anteriores. Pint/sintaxis de 46 PHP afectados acumulados, Composer validate/autoload estricto PSR-4/ambigüedad, 59 rutas totales/16 del módulo sin duplicados, UTF-8/Markdown y git diff --check correctos. Medición final: impacto 30/300 citas en 31,59/239,78 ms HTTP con 24 consultas, ocupación SQL 0,126 ms/310 bloques. Resultado registrado en contexto §17.81 y análisis/CU. No quedan funcionalidades administrativas backend pendientes dentro de CU-11/CU-12; RF05 íntegro sigue dependiendo de disponibilidad CU-13 y reservas posteriores, no se declara implementada búsqueda de turnos por este cierre.

## Consultorios y horarios CU-11/CU-12 — fase 5: garantías y rendimiento (2026-10-21)

**Implementado/verificado de forma dirigida:** ampliación de seguridad, carreras PostgreSQL con orden controlado y medición del impacto. Contratos de las 16 operaciones conservados; sin endpoints, dependencias, índices ni migraciones nuevos. Fase 6 mantiene la integración/cierre del módulo.

**Seguridad transversal:** PlanificacionGarantiasTest recorre las 16 operaciones con credencial temporal restringida, cuenta desactivada, sesión persistida revocada y origen ajeno; las 11 operaciones mutadoras/POST de impacto también rechazan CSRF ausente. Respuestas 401/403/419 según el control, JSON privado/no-store y snapshots de negocio/eventos sin cambios. AutenticacionConcurrenteTest añade 24 escenarios (seis lecturas/escrituras/impacto × estado/rol/sesión/clave): el proceso atraviesa los middlewares, espera dentro del Service en coordinación administrativa y relee al actor/sesión tras confirmar la revocación. Ninguno consulta un resultado protegido ni persiste cambios con el contexto anterior.

**Carreras controladas:**
- 12 escenarios de horario contra asignación de otro Médico, duración global o asignación del mismo Médico/versión compartida. Cada combinación se ejecuta con ambos órdenes de adquisición y con commit/rollback del primer escritor. El primer endpoint se pausa después del evento, antes del commit; el segundo espera en un bloqueo PostgreSQL real. Tras commit, se rechaza la segunda propuesta incompatible/obsoleta; tras rollback, puede guardar la propuesta válida. Versiones y bitácora corresponden solo al ganador.
- 8 escenarios de traslado frente a reserva o documentación de atención simuladas, también en ambos órdenes y con commit/rollback. Reserva/consulta confirmada primero invalida la huella del traslado. Traslado primero hace que una reserva con ubicación antigua sea rechazada; la atención simulada puede documentar la misma cita en su ubicación ya actualizada sin volverla a trasladar. La consulta conserva iniciada_en NULL para verificar la barrera conservadora. Los escritores de CU-14/CU-23 son **fixtures de protocolo**, sin rutas ni autorización clínica implementadas; esta evidencia no acredita esos CU.
- Los fallos inyectados después de insertar auditoría verifican reversión de estado/versiones/ubicación/CambioCita/evento antes de liberar al segundo proceso. Barreras y pausas pertenecen exclusivamente a tests/Fixtures, sin mecanismos de pausa en producción.

**Errores de persistencia:** inyección tardía de ocupación ejerce la exclusión GiST real: 23P01 conocido se traduce a 409 PLANIFICACION_CONFLICTO con rollback íntegro. Una división por cero PostgreSQL posterior al traslado se conserva como 500 ERROR_INTERNO, sin SQL/trazas/nombre de restricción en JSON y sin cambios parciales. No se convierten errores técnicos arbitrarios en conflictos.

**Medición y corrección concreta:** con 30/300 citas futuras ficticias, impacto completo/per_page=1 usa **24 consultas HTTP/SQL constantes**, una consulta con existencia de consulta médica y sin N+1 clínico. La primera medición con 300 citas costó **12407,93 ms HTTP**, frente a unos **0,127 ms SQL** para recuperar ocupación; el coste estaba en comparación de pares/casts Carbon dentro del Service. AsignacionConsultorioService ahora proyecta recursos, convierte cada intervalo una vez a UTC canónico y recorre inicios ordenados con intervalos abiertos (retira fin <= inicio). Mantiene todos los pares conflictivos de traslados, intervalos contiguos, datos mínimos, conjunto completo y huella independiente de página. La misma prueba dio **222,49 ms HTTP** para 300 citas, 24 consultas; EXPLAIN ANALYZE/BUFFERS: 300 filas, 310 bloques compartidos y 0,125 ms de ejecución local. Son mediciones del dataset local, no un SLA ni una validación de carga productiva; no hubo evidencia para añadir índices. El test comprueba semántica/conteo constante y registra tiempos, sin umbrales frágiles de milisegundos.

**Verificación final/cierre local de fase 5:** **53 pruebas nuevas: 9 de garantías/medición y 44 concurrentes**. **Suite completa: 1185 pruebas/13615 aserciones correctas**, 6 min 53,2 s, memoria 154 MB, base/rol aislados clinica_vida_salud_test; incluye las cuatro últimas carreras de versión compartida. Medición dentro de la regresión: impacto de 30/300 citas en 31,31/238,15 ms HTTP, 24 consultas en ambos; ocupación SQL 300 filas/310 bloques compartidos/0,131 ms. Pint/sintaxis de 45 PHP afectados acumulados, Composer validate/autoload estricto PSR-4/ambigüedad, 59 rutas totales/16 del módulo sin duplicados, UTF-8/Markdown y git diff --check correctos. Evidencia registrada en contexto §17.80. Próxima fase 6: integración/cierre del módulo. Diagrama global y actualización PHP siguen aplazados.

## Horarios y duración CU-12 — fase 4 (2026-10-21)

**Implementado:** ocho operaciones administrativas de intervalos semanales, actividad e impacto y duración global. Se reutilizan HorarioAtencion/ConfiguracionClinica y las versiones existentes, sin migraciones nuevas. Acceso exclusivo de Administrador con sesión normal, cookies/Sanctum, origen/CSRF y respuestas privadas/no-store.

| Endpoint con prefijo /api | Entrada / respuesta |
| --- | --- |
| GET /medicos/{id}/horarios | page/per_page y activo opcionales; data.id_medico/actualizado_en/duracion_turno_minutos/elementos, links/meta |
| POST /medicos/{id}/horarios | dia_semana, hora_inicio, hora_fin, actualizado_en_original; 201 horario activo y versión nueva del Médico |
| PATCH /medicos/{id}/horarios/{horario} | día/inicio/fin parciales (al menos uno), actualizado_en_original; 200 horario/versión |
| PATCH /medicos/{id}/horarios/{horario}/estado | activo booleano, actualizado_en_original; 200 horario/versión |
| POST /medicos/{id}/horarios/impacto | accion CREAR/EDITAR/ESTADO, id_horario para EDITAR/ESTADO, campos correspondientes y actualizado_en_original; page/per_page opcionales |
| GET /configuracion-citas | Sin entrada; data.duracion_turno_minutos/zona_horaria/actualizado_en |
| POST /configuracion-citas/impacto | duracion_turno_minutos, actualizado_en_original; page/per_page opcionales |
| PATCH /configuracion-citas | duracion_turno_minutos, actualizado_en_original; 200 configuración vigente |

Horario proyectado: id_horario/id_medico textuales, dia_semana entero 1–7 (ISO), hora_inicio/hora_fin HH:mm:ss, activo booleano, actualizado_en del Médico en UTC/seis decimales y turnos_completos (capacidad aritmética, no disponibilidad de reservas). El listado ordena por día/inicio/ID y pagina; un Médico sin horarios obtiene lista vacía y su versión. Configuración mantiene America/La_Paz. Duración entera positiva hasta 2147483647 (INTEGER), sin máximo funcional añadido; solo se permite cuando cabe en **cada intervalo activo**. Segundos históricos válidos conservados, sin paso de minutos obligatorio ni cruce de medianoche.

**Impacto y reglas:**
- Los dos POST de impacto devuelven data.puede_confirmar/totales.conflictos/elementos y links/meta. Evalúan el conjunto completo, aunque solo se muestre una página. Se repite POST con el mismo cuerpo y otra página, sin colocar la propuesta en URL. No contienen huella ni reservan/aprueban escrituras: cada escritura revalida todas las reglas bajo locks.
- CREAR propone un horario activo. EDITAR preserva su actividad; ESTADO solo modifica activo. Horario ajeno o inexistente → 404; estructura, campos extra/privilegiados, horas no canónicas, ID fuera de BIGINT o edición sin campos → 422. El ID de un PATCH solo se toma de la ruta, no del cuerpo. No existe DELETE.
- Para un horario activo exige sala fija activa, duración que quepa y ausencia de solapamiento `[inicio,fin)` con horarios propios/compartidos activos, incluidos los de Médicos inactivos. También comprueba citas reales no Canceladas del consultorio, aunque su Médico esté asignado a otra sala o no tenga horario activo. Intervalos contiguos son compatibles.
- La propuesta debe conservar cobertura **completa por un intervalo activo** de todas las citas propias Programadas/Confirmadas con fin_at > ahora, incluidas las que transcurren. No basta cubrir lo que resta. La desactivación es posible si otro intervalo activo conserva esa cobertura. Canceladas/pasadas/finales no exigen cobertura futura y conservan su ubicación/ocupación almacenada; las citas finales aún futuras de otro Médico sí se consideran ocupación de sala. No se consultan datos clínicos para esta operación.
- Inactivar un Médico no modifica horarios, y gestionar sus horarios no cambia su actividad/cuenta. Desactivar un horario libera su franja administrativa, pero no cambia intervalos de citas reales. Reactivarlo comprueba sala, duración y conflictos actuales de nuevo.
- Duración verifica todos los horarios activos sin filtrar actividad del Médico; ignora los desactivados. Ejemplo 50 minutos/15: tres turnos completos y cinco minutos sobrantes. Cambiar a 20 ofrece capacidad de dos turnos, sin cambiar los 15 minutos almacenados de una cita previa. No se implementan búsqueda de huecos por fecha/reserva CU-13/CU-14 ni scheduler CU-28 en esta fase.

**Versiones/errores:** horario usa actualizado_en_original del Médico; configuración usa su propia versión. Comparación bloqueada incluso en no-op, avance monotónico solo con cambios efectivos y sin evento duplicado. Versión antigua → 409 MEDICO_EDICION_OBSOLETA/CONFIGURACION_EDICION_OBSOLETA; reglas incompatibles → 409 HORARIO_INTERVALO_INVALIDO, DURACION_INCOMPATIBLE, CONSULTORIO_NO_DISPONIBLE, PLANIFICACION_CONFLICTO o HORARIO_CITAS_SIN_COBERTURA. El impacto incompatible es 200/puede_confirmar=false; nunca permite una escritura parcial. Diagnósticos mínimos con referencias/estado/intervalos, sin Paciente, motivo ni contenido clínico. Conflictos ordenados por fecha y referencias de Médico/día/horario/cita.

**Persistencia y concurrencia:** coordinación administrativa → actor bloqueado/revalidado → planificación (736201,2) → configuración → Médico → sala fija → horarios → citas por ID. Duración solo necesita configuración y lecturas de horarios protegidas por planificación; no bloquea masivamente todos los Médicos. Escritura de horario y avance de versión de Médico/evento se confirman juntos; duración y evento también. Rollback comprobado incluso tras insertar bitácora. No hay cambios de citas, CambioCita, recordatorios, asignaciones, cuentas ni actividad profesional. Los futuros escritores de reserva/reprogramación deben respetar la coordinación y leer la duración/cobertura vigentes, además de las exclusiones existentes.

**Auditoría CU-30:** HORARIO_CREADO/HORARIO_ACTUALIZADO/HORARIO_ESTADO_CAMBIADO en HORARIOS con referencias id_medico/id_horario y lista permitida de campos (día/inicio/fin/activo); DURACION_CITAS_CAMBIADA con id_configuracion y antes/despues.duracion_turno_minutos. Filtros/referencias/proyecciones reconocen HorarioAtencion y ConfiguracionClinica, sin exponer textos libres ni clínica.

**Verificación final/cierre local de fase 4:** **55 pruebas nuevas: 51 funcionales** en HorariosTest y **4 carreras** en AutenticacionConcurrenteTest: dos horarios incompatibles, duración contra horario corto, desactivación contra reserva con commit/rollback. La reserva es un escritor simulado del protocolo, no un endpoint CU-14. **Suite completa: 1132 pruebas/12506 aserciones correctas**, 6 min 24,9 s, memoria 144 MB, base/rol aislados clinica_vida_salud_test. Pint/sintaxis de 42 PHP afectados acumulados, Composer validate/autoload estricto PSR-4/ambigüedad, 59 rutas totales/ocho nuevas sin duplicados, UTF-8/Markdown y git diff --check correctos. Funciones administrativas CU-12 implementadas; siguiente fase 5 seguridad/concurrencia ampliadas, seguida de integración/cierre en fase 6. Diagrama global/PHP aplazados conforme a la solicitud vigente.

## Consultorios CU-11 — fase 3: asignación, impacto y traslado (2026-10-21)

**Implementado:** asignación fija inicial/cambio, diagnóstico previo y confirmación expresa. Solo Administrador normal, con las mismas protecciones de sesión, cuenta, origen y CSRF del catálogo. Mantiene separadas la actividad profesional, los horarios y la cuenta; asignar no activa ninguna de esas entidades ni genera disponibilidad por sí solo. Se permite mantener administrativamente un Médico inactivo.

| Endpoint implementado | Contrato |
| --- | --- |
| GET /api/medicos/{id}/consultorio | 200 `data`: id_medico textual, actualizado_en del Médico, consultorio mínimo o null |
| POST /api/medicos/{id}/consultorio/impacto | id_consultorio válido, actualizado_en_original del Médico, page/per_page opcionales (1–1000000/1–100, 20 por defecto); 200 diagnóstico, sin escritura de negocio |
| PUT /api/medicos/{id}/consultorio | id_consultorio, actualizado_en_original, huella_impacto (64 hex minúsculas), confirmar_traslados booleano; 200 asignación vigente y citas_trasladadas |

Consultorio mínimo: ID textual, código, nombre, activo y actualizado_en UTC/seis decimales. Los DTO rechazan campos extra, IDs fuera de BIGINT, null como destino, fechas imposibles y confirmación textual `"false"`; permisos se verifican antes de consultar recursos. 404 para Médico/consultorio inexistentes, 422 para estructura inválida, 405 para DELETE. No se añade un flujo de retirada de asignación. Respuestas privadas/no-store.

Impacto: `data.puede_confirmar`, `data.huella_impacto`, `data.totales` (conflictos/citas_trasladadas propuestas), `data.elementos`; `links` y `meta` paginan los elementos. Cada elemento tiene código de diagnóstico y referencias mínimas de horario/cita/Médico/consultorio; si es una cita incluye estado/inicio/fin UTC, nunca Paciente, motivo, diagnóstico ni contenido de consulta. Orden estable por fecha/ID cuando existen y referencias/código para conflictos semanales. `CAMBIO_UBICACION` identifica propuestas, no operaciones confirmadas; si hay conflictos no se guarda ninguna. Para paginar se repite **POST con la misma propuesta** y otra page/per_page: los enlaces solo señalan páginas, no constituyen endpoints GET ni incluyen la propuesta en URL.

**Validación del impacto completo:**
- Compara horarios activos propios y de otros médicos del destino mediante `[inicio,fin)` local en America/La_Paz. La actividad profesional no libera un horario activo; horarios contiguos son compatibles.
- Comprueba ocupación real no Cancelada con fin posterior al instante del servidor, incluyendo citas de Médicos que ya estén asignados a otra sala y registros finales que aún ocupen físicamente un intervalo futuro. Proyecta todos los traslados y valida coincidencias temporales de Médico/consultorio, franja semanal y cobertura completa de cada cita trasladable con un horario activo. Las citas reales de más de un día también bloquean las franjas que intersectan.
- Solo cambia ubicación de Programadas/Confirmadas futuras (`inicio_at >= ahora`) sin consulta documentada. Una consulta existente bloquea conservadoramente incluso si iniciada_en es NULL. Una cita de ese grupo con intervalo ya iniciado impide el cambio de asignación; no se traslada parcialmente una atención. Pasadas y estados finales preservan su ubicación; Cancelada no ocupa disponibilidad.
- Captura una huella SHA-256 del actor, destino, versiones/ubicaciones de salas/Médico, horarios relevantes, datos administrativos/versiones/existencia de consulta de todas las citas relevantes, conjunto de traslados y conflictos. No incluye contenido clínico. No depende de page/per_page ni del reloj crudo; cambios de elegibilidad por el paso del tiempo sí invalidan el impacto. La existencia de consulta es suficiente para denegar el traslado, sin leer sus versiones clínicas.
- Al confirmar recalcula/revalida bajo locks y exige la misma huella (409 ASIGNACION_IMPACTO_OBSOLETO). Una versión antigua del Médico falla antes (409 MEDICO_EDICION_OBSOLETA). La huella es control de edición, no un secreto ni autorización. Si hay traslados exige confirmar_traslados=true (409 ASIGNACION_CONFIRMACION_REQUERIDA). No-op vigente conserva versiones/eventos y no reescribe etiquetas de citas por un mero renombrado del catálogo.

**Atomicidad e historia:** coordinación administrativa → actor bloqueado/revalidado → planificación (736201,2) → Médico → salas origen/destino por ID → citas por ID. Las consultas semanales están protegidas por planificación; futuros CU-12/CU-14/CU-15/CU-23 deberán respetar ese mismo protocolo. No se bloquea configuración global porque esta operación no la modifica ni calcula turnos con una nueva duración. Bajo una sola transacción se actualiza asignación/versión del Médico, FK/instantánea/actualizada_en monotónica de todas las citas trasladadas, un CambioCita inmutable por cita y un evento funcional de bitácora. No cambia fecha/hora/duración/estado/código/Paciente/especialidad. Las exclusiones GiST existentes refuerzan ocupación; solo sus errores 23P01 conocidos se traducen a 409 PLANIFICACION_CONFLICTO, otros errores conservan el manejo seguro central.

**Avisos:** conserva version_programacion de cada cita y todas las filas/fechas/lecturas de recordatorios: no hay nueva programación temporal ni texto de ubicación materializado en el aviso. La ubicación vigente se obtiene mediante la relación con Cita, comprobada con un join real en pruebas. No se generan avisos nuevos ni se implementa el scheduler de CU-28.

**Auditoría:** MEDICO_CONSULTORIO_CAMBIADO en CONSULTORIOS, responsable/contexto, referencias id_medico/id_consultorio destino, antes/despues de id_consultorio y cantidad de citas trasladadas. CU-30 permite consultar por Médico o consultorio destino y proyecta solo esas claves; el consultorio anterior queda en los cambios seguros, sin añadir una búsqueda implícita por cualquier JSON. CambioCita guarda CAMBIO_UBICACION, estado anterior=nuevo e instantáneas administrativas anterior/nueva. Un fallo después de insertar el evento revierte toda la operación y los historiales. No hay cambios de esquema/migraciones adicionales en esta fase (28 entidades/243 atributos/43 relaciones).

**Verificación final:** AsignacionConsultoriosTest incorpora **42 pruebas** de flujo inicial, traslado con aviso leído, consentimiento, páginas/huella completa, no-op, datos obsoletos, permisos/CSRF/origen, intervalos contiguos/multidía, estados finales, conflictos y atención documentada, rollback y consulta CU-30. AutenticacionConcurrenteTest añade **5 escenarios** de asignaciones incompatibles simultáneas y reserva/consulta concurrentes con commit/rollback mediante procesos PHP/PostgreSQL distintos. Esos escritores de reserva/atención son simulaciones de protocolo, no endpoints CU-14/CU-23 implementados. **47 pruebas nuevas; suite completa: 1077 pruebas/11943 aserciones correctas**, 6 min 6,8 s, memoria 140 MB, base/rol aislados clinica_vida_salud_test. Pint y sintaxis de 31 PHP afectados acumulados, autoload estricto PSR-4/ambigüedad, 51 rutas totales/tres nuevas sin duplicados, UTF-8/Markdown y git diff --check correctos. Fase 3 cerrada localmente.

**Avance:** las operaciones de CU-11 están implementadas; el cierre integrado del módulo conserva fases 5/6. Próxima fase 4: horarios/duración CU-12. Diagrama global y actualización PHP siguen aplazados; no se aplicaron migraciones en base habitual/Railway ni se desplegó.

## Consultorios CU-11 — fase 2: catálogo administrativo (2026-10-21)

**Implementado:** catálogo exclusivo de Administrador con sesión normal. Se reutilizan sesión/Sanctum, origen/CSRF, cuenta habilitada y restricción por cambio obligatorio de contraseña. El Service revalida actor bloqueado dentro de DB::transaction, tras la coordinación administrativa de CU-03; las escrituras y el detalle adquieren luego la coordinación compartida de planificación `(736201,2)` antes del consultorio. Controllers solo adaptan HTTP, Requests validan entrada, Service decide reglas/transacciones y Repositories consultan/persisten.

| Endpoint implementado | Contrato |
| --- | --- |
| GET /api/consultorios | `q` opcional (1–120 caracteres, coincidencia parcial literal sin distinguir mayúsculas en código **o** nombre), `activo` 0/1, `page` 1–1000000, `per_page` 1–100 (20 por defecto). Filtros combinados con AND; orden ID descendente |
| POST /api/consultorios | Código (1–50) y nombre (1–120), trim, sin controles; 201, activo inicialmente |
| GET /api/consultorios/{id} | 200 detalle mínimo más `medicos_asignados` y `citas_vigentes` (cantidades, sin expandir datos personales/clínicos) |
| PATCH /api/consultorios/{id} | Código/nombre parciales y `actualizado_en_original` UTC/seis decimales; 200 |
| PATCH /api/consultorios/{id}/estado | `activo` booleano y versión original; 200 |

Campos comunes bajo `data`: `id_consultorio` textual, `codigo_consultorio`, `nombre`, `activo`, `actualizado_en` ISO UTC/seis decimales. Listado incluye links/meta de Laravel. El resumen se incluye cuando se consulta el detalle o se evalúa una desactivación. Todas las respuestas privadas/no-store. Entradas cerradas: campos inesperados/control enviados por cliente producen 422; límites de BIGINT validados sin redondeo. ID inexistente 404; DELETE no publicado (405).

**Reglas:** código único **exacto**, incluso al renombrar (409 CONSULTORIO_CODIGO_DUPLICADO); no se inventa normalización por mayúsculas ni unicidad de nombre. Versión original comparada bajo bloqueo antes de aceptar un no-op (409 CONSULTORIO_EDICION_OBSOLETA). Guardado efectivo avanza versión monotónica aun con reloj atrasado o marca futura; no-op vigente no guarda ni duplica bitácora.

Desactivar exige cero Médicos asignados (incluidos inactivos y sin horarios activos), o falla con 409 CONSULTORIO_CON_ASIGNACIONES. También exige cero citas Programadas/Confirmadas con `fin_at > ahora`, incluyendo intervalos en curso aunque el Médico esté actualmente asignado a otro consultorio; falla con 409 CONSULTORIO_CON_OBLIGACIONES. Instante del servidor obtenido tras los locks. Finales/pasadas y Canceladas no se alteran. Edición de código/nombre no modifica instantáneas de ubicación, citas, recordatorios, horarios ni asignaciones; reactivar consultorio tampoco reactiva esas entidades.

**Auditoría:** CONSULTORIO_CREADO/ACTUALIZADO/ESTADO_CAMBIADO, módulo CONSULTORIOS, actor real, contexto seguro, referencia `id_consultorio` y lista de campos modificados (sin copiar código/nombre libres). Eventos y datos se confirman juntos; fallo de auditoría revierte alta/edición/estado/versión. Consulta CU-30 admite entidad Consultorio y proyecta referencias/campos permitidos; desconocidos permanecen excluidos. Frontend específico/proyecciones visuales adicionales se incorporarán en su fase correspondiente.

**Persistencia:** migración aditiva `2026_10_21_000033_add_actualizado_en_to_consultorios.php`, TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP e inicialización de filas previas, sin alterar FK/PK/índices/exclusiones/datos históricos. Consultorio incorpora cast immutable_datetime y formato con microsegundos. PlanificacionRepository encapsula lock compartido reutilizable para fases siguientes. Esquema con 000033: **28 entidades/243 atributos/43 relaciones**. Migración ejecutada/revertida únicamente en base de pruebas aislada; pendiente aplicar en base habitual/Railway al incorporar la entrega.

**Verificación final:** ConsultoriosTest incorpora **39 pruebas** de recursos, filtros/paginación, versiones/no-op/microsegundos, restricciones, cuatro roles, sesión/origen/CSRF, entradas cerradas, migración con asignaciones/citas previas, BIGINT, rollback y proyección CU-30. AutenticacionConcurrenteTest añade **4 escenarios** con procesos PHP/PostgreSQL independientes: dos borradores, dos altas del mismo código, actor revocado y desactivación que espera una asignación confirmada. Esta última simula el protocolo del futuro CU-11, sin acreditar todavía su endpoint. **43 pruebas nuevas; suite completa: 1030 pruebas/11417 aserciones correctas**, 6 min 10 s, base/rol aislados clinica_vida_salud_test. Pint y sintaxis de 21 PHP, Composer validate/autoload PSR-4 y ambigüedad estrictos, 48 rutas totales/cinco de consultorios sin duplicados, UTF-8/Markdown y git diff --check correctos. Fase 2 cerrada localmente.

**Pendiente:** fase 3 asignación/impacto/traslados CU-11; fase 4 horarios/duración CU-12; fases 5/6 garantías e integración. No se declara completo CU-11 ni el módulo. Diagrama global y actualización PHP siguen aplazados.

## Consultorios y horarios CU-11/CU-12 — fase 1: diseño y contrato (2026-10-21)

**Registro histórico de fase 1: diseño documentado; endpoints, clases nuevas y migración todavía no implementados al cerrar esa fase.** El avance posterior está en fase 2 arriba; las referencias a esquema actual/pendientes de este apartado corresponden a ese momento. Fuentes: RF03/RF05/RF07, RN05/RN07/RN08/RN12/RN14, CU-11/CU-12, VS-HOR y decisiones aprobadas en esta sesión. RF03 mantiene su dependencia de agenda; CU-13/CU-14/CU-15/CU-23/CU-28 no se completan por diseñar sus colaboraciones aquí.

### Reglas aprobadas y límites

- Consultorio fijo válido antes de activar horarios reservables; varios intervalos por Médico/día. Horarios locales en America/La_Paz, lunes=1 a domingo=7, sin cruce de medianoche y con intervalos semiabiertos `[inicio, fin)`; horarios contiguos permitidos.
- Inactivar un Médico conserva sus horarios. Cada horario **activo**, incluso de Médico inactivo, reserva su franja administrativa del consultorio. CU-12 permite modificarlo/desactivarlo expresamente solo si las citas vigentes siguen cubiertas. Un horario desactivado deja de reservar la franja, pero no libera los intervalos de citas existentes. Reactivar al Médico no reactiva horarios. Médico inactivo nunca ofrece nuevas reservas.
- Desactivar consultorio se rechaza si tiene cualquier Médico asignado (activo o inactivo) u obligaciones futuras de atención. Resolver asignaciones mediante cambios expresos a otro consultorio válido y gestionar citas por operaciones autorizadas; sin borrados/desasignaciones automáticos.
- Duración global entera positiva, inicialmente 15 minutos. Debe caber en **cada** intervalo activo, incluidos los de Médicos inactivos. No se exige múltiplo exacto: 50 minutos/15 ofrece tres turnos y descarta cinco minutos sobrantes. Citas existentes preservan su duración.
- Editar código/nombre del catálogo no reescribe `citas.consultorio_atencion`. El cambio de ubicación de CU-11 actualiza expresamente FK e instantánea de ubicación de citas elegibles, nunca tiempos, duración, identidad, especialidad, estado o código.
- Citas futuras elegibles: Programada/Confirmada con `inicio_at >= ahora` (instante UTC del servidor obtenido después de los bloqueos), sin ConsultaMedica que documente atención. Ante una consulta existente se rechaza conservadoramente el traslado de esa cita, aunque `iniciada_en` sea NULL. Citas ya iniciadas por horario y no finalizadas conservan ubicación y ocupación; un cambio que les resulte incompatible se rechaza. Pasadas/finales no se trasladan. La cobertura de citas vigentes Programadas/Confirmadas con `fin_at > ahora` se valida sobre el intervalo completo, incluidas las que están transcurriendo.
- Ocupación real: todas las citas no Canceladas y sus intervalos almacenados, conforme a las exclusiones físicas existentes; no inferir huecos por inactividad profesional, horario desactivado o duración nueva. Citas no trasladables en el consultorio anterior también cuentan al asignar horarios de otros médicos.
- Feriados, bloqueos excepcionales y vigencias por fechas no se incorporan: el modelo actual representa semanas recurrentes sin fechas de efecto.

### Contrato HTTP previsto

Solo Administrador con sesión normal; mismos cookies/Sanctum, origen, CSRF, restricciones de cuenta/contraseña y contexto seguro existentes. Lecturas y respuestas de error/éxito con `Cache-Control: no-store, private`. Rutas registradas en `src/presentation/routes/` mediante el puente actual, sin consultas en rutas ni controllers.

Todas las rutas de esta tabla son **previstas** y tienen prefijo `/api`:

| Método/ruta | Entrada | Resultado |
| --- | --- | --- |
| GET /consultorios | `q`, `activo`, `page`, `per_page` | 200 listado paginado |
| POST /consultorios | `codigo_consultorio`, `nombre` | 201, activo inicialmente |
| GET /consultorios/{id} | ID | 200 detalle y cantidades de asignaciones/obligaciones |
| PATCH /consultorios/{id} | código y/o nombre, `actualizado_en_original` | 200 detalle vigente |
| PATCH /consultorios/{id}/estado | `activo`, `actualizado_en_original` | 200; 409 si desactivación incompatible |
| GET /medicos/{id}/consultorio | ID Médico | 200 asignación actual o null y versión del Médico |
| POST /medicos/{id}/consultorio/impacto | `id_consultorio`, `actualizado_en_original`, `page`, `per_page` | 200 diagnóstico y vista previa paginada |
| PUT /medicos/{id}/consultorio | `id_consultorio`, `actualizado_en_original`, `huella_impacto`, `confirmar_traslados` | 200 asignación vigente y total de citas trasladadas |
| GET /medicos/{id}/horarios | `page`, `per_page`, `activo` opcional | 200 intervalos paginados, versión del Médico y duración vigente |
| POST /medicos/{id}/horarios | `dia_semana`, `hora_inicio`, `hora_fin`, `actualizado_en_original` | 201 intervalo activo y versión del Médico |
| PATCH /medicos/{id}/horarios/{horario} | día/inicio/fin parciales, `actualizado_en_original` | 200 intervalo y versión del Médico |
| PATCH /medicos/{id}/horarios/{horario}/estado | `activo`, `actualizado_en_original` | 200 intervalo y versión del Médico |
| POST /medicos/{id}/horarios/impacto | `accion` CREAR/EDITAR/ESTADO, `id_horario` si corresponde, campos de esa acción/versiones, `page`, `per_page` | 200 diagnóstico previo sin escritura |
| GET /configuracion-citas | Sin entrada | 200 duración global, zona y versión |
| POST /configuracion-citas/impacto | `duracion_turno_minutos`, `actualizado_en_original`, `page`, `per_page` | 200 intervalos incompatibles paginados y totales |
| PATCH /configuracion-citas | `duracion_turno_minutos`, `actualizado_en_original` | 200 configuración; 409 si no cabe en algún intervalo activo |

Entradas cerradas: campos desconocidos, filtros/IDs inválidos o relación horario–Médico incorrecta se rechazan (422 para estructura; 404 para recurso ajeno/inexistente). No se aceptan DELETE, cambios de estado de citas, cuenta, rol, Médico de un horario ni `id_consultorio = null`. Cambio a otro consultorio resuelve las asignaciones que bloquean una desactivación; no se introduce un flujo de retirada de asignación no aprobado.

Código/nombre: trim, no vacíos, límites existentes 50/120; unicidad **exacta** del código conforme a `consultorios_codigo_uq`, sin imponer unicidad nueva por mayúsculas ni del nombre. `q` busca código/nombre parametrizadamente; orden fijo `id_consultorio DESC`. Listados/impactos: página >=1, tamaño predeterminado 20/máximo 100. Horarios ordenados por día/inicio/ID; impacto por inicio/ID de cita o Médico/día/ID de horario, sin ordenar por campos arbitrarios del cliente. Horas `HH:mm:ss` (también permite consultar valores históricos TIME(0) con segundos); no inventar un paso obligatorio de cinco o quince minutos. Duración int positiva dentro del INTEGER PostgreSQL y compatible con intervalos activos; sin máximo funcional arbitrario añadido.

Respuestas: `data`, `links`, `meta` según Resources actuales. BIGINT en cadenas decimales; booleanos reales; tiempos UTC ISO con seis decimales y Z; versión de edición `actualizado_en_original` recibida en ese mismo formato. Consultorio: ID/código/nombre/activo/actualizado_en; asignación: ID Médico, consultorio mínimo o null, actualizado_en del Médico; horario: ID/Médico/día/inicio/fin/activo más versión del Médico; configuración: duración/zona/actualizado_en. Sin serialización de modelos completos ni contenido clínico.

Vista previa: `data` contiene `puede_confirmar`, `huella_impacto` (solo traslado), `totales` y `elementos`; `links/meta` paginan elementos. Elementos son conflictos o cambios previstos con `codigo`, ID Médico/consultorio/horario/cita según corresponda, estado y tiempos administrativos mínimos; sin nombre/CI/correo/motivo del Paciente ni valores clínicos. Un diagnóstico incompatible devuelve 200 con `puede_confirmar=false`; no reserva ni autoriza guardar. Un intento de escritura incompatible devuelve 409. El diagnóstico tiene que ser completo en su evaluación aunque su presentación esté paginada.

### Impacto y confirmación de asignación

1. La previsualización recupera configuración, Médico, origen/destino, horarios activos compartidos, citas futuras/elegibles y ocupación restante. Valida también las intersecciones entre los horarios recurrentes propuestos y citas reales no trasladables (por fecha/día local).
2. Construye una huella SHA-256 de representación canónica del actor, acción/destino, versiones de recursos, horarios relevantes y **todas** las citas/consultas relevantes y sus versiones. Orden estable, sin datos clínicos, independiente de la página; el conjunto completo incluye citas que entren o salgan de los afectados. La huella no contiene SQL, secretos ni permisos; no sustituye autenticación y no necesita tabla de reservas o token persistido.
3. PUT exige vista previa y compara la huella recalculada bajo bloqueos; si cambia el impacto, devuelve 409 y obliga a revisar otra vista previa. El mero paso del tiempo no altera una huella de estado estable, pero los cambios de elegibilidad sí; siempre se reevalúa el instante actual. `confirmar_traslados=true` es obligatorio si hay citas que trasladar; sin ellas puede ser false. No admite seleccionar solo una parte de las citas para evitar una asignación incoherente.
4. En la misma transacción actualiza Médico/versión, las citas elegibles (`id_consultorio`, `consultorio_atencion`, `actualizada_en` monotónica), CambioCita por cita y bitácora. Primera asignación/no-op también revalida. Un no-op con versiones/huella vigentes no avanza versiones ni duplica eventos.
5. Cambio puramente de ubicación conserva `version_programacion`: no cambia fecha/hora ni genera otro recordatorio. `recordatorios` no tiene texto/ubicación materializados: sus lectores deben obtener la ubicación vigente mediante Cita. Por ello la actualización de Cita actualiza el dato mostrado por el aviso existente conservando generación/lectura/invalidación. Verificar esto en integración de fase 3 y en futuro CU-28; no enviar avisos externos ni implementar ahora el scheduler.

### Persistencia y concurrencia previstas

**Inspección real:** PHP 8.5.11, Composer 2.10.3, Laravel instalado 13.37.0 (composer requiere ^13.17), Sanctum ^4.0 y PSR-4 Clinica→src. Consultorio tiene código único exacto, nombre y activo, pero no versión. HorarioAtencion tiene día 1–7, TIME(0), activo, FK Médico y CHECK fin>inicio; no versión ni exclusión semanal. Medico ya tiene actualizado_en TIMESTAMPTZ(6). ConfiguracionClinica singleton=1 ya tiene duración positiva/15, zona fija y actualizado_en TIMESTAMPTZ(6). Cita tiene instantánea de ubicación, versión de programación, fechas con microsegundos y exclusiones GiST **ya implementadas** por Médico/consultorio `[inicio,fin)` donde estado <> Cancelada. CambioCita es inmutable; Recordatorio único por cita/versión. No duplicar estas estructuras.

- Migración aditiva necesaria en fase 2: agregar `consultorios.actualizado_en TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP`, inicializando filas anteriores sin cambiar códigos/estado/relaciones. No se crea/aplica en esta fase. DER físico actual conserva 28 entidades/242 atributos/43 relaciones; con esa migración serán 243 atributos si no hay otros cambios.
- Horarios/asignación comparten **la versión del Médico**: toda escritura efectiva de horario avanza actualizado_en del Médico; no necesita columna de versión en cada horario. Configuración usa su versión existente, con avance monotónico. Comparar versiones bajo lock incluso antes de aceptar un no-op. Consultorios también avanzan versión monotónica.
- Unicidad/FK/CHECK/exclusiones existentes siguen siendo última barrera. La ocupación semanal entre tablas se protege mediante protocolo transaccional, no por suponer que el índice Médico/día impide solapamientos. No agregar consultorio duplicado al horario ni rangos semanales artificiales sin necesidad. Índices adicionales solo tras medición de fase 5.
- Para una sede y volumen del proyecto, usar una coordinación transaccional **única de planificación**, `pg_advisory_xact_lock(736201, 2)` (clave propuesta libre en el código inspeccionado). Serializa escritores de asignación/horarios/duración/estado de consultorio y futuros escritores de cita/atención que afecten estas decisiones. Evita ciclos entre traslado, duración global y nuevos horarios sin bloquear masivamente todos los médicos. No es una reserva de turnos ni una garantía frente a SQL que ignore el protocolo.
- Orden obligatorio: coordinación administrativa existente `(736201,1)` cuando aplique → actor autenticado bloqueado/revalidado → coordinación de planificación `(736201,2)` → configuración → Médicos por ID ascendente → consultorios por ID ascendente → especialidades/pares si aplica → horarios → citas por ID → consultas/recordatorios. No adquirir coordinación administrativa ni otro actor después de planificación. Bloqueos de filas solo de recursos necesarios; las lecturas de conflicto de otros médicos están protegidas por la coordinación común.
- Los flujos actuales de Médico/CU-03 conservan su orden existente: no se les añade planificación desde debajo de un lock de Médico. Comparten bloqueo de Médico para actividad/habilitaciones/vínculos; el nuevo flujo lo relee después de obtenerlo. Futuros CU-14/CU-15/CU-23/CU-28 deben adquirir planificación **antes** de Médico/cita y respetar actor/recursos, además de revalidar actividad, cobertura y ocupación. Su futura integración se prueba al implementarlos; hoy solo se puede simular el protocolo de escritores inexistentes.
- Aislamiento READ COMMITTED con relectura después de locks, instante de servidor único por escritura y validación del intervalo completo. Transacción engloba configuración/ubicación/historial/bitácora; fallo necesario revierte todo. SQLSTATE 23505 de código→409; 23P01 de exclusión→409; no traducir cualquier error SQL a conflicto. Reintentos transaccionales solo para deadlock/serialización reconocidos, acotados y sin efectos externos dentro del callback.

Errores previstos para registrar centralmente al implementar: 409 `CONSULTORIO_CODIGO_DUPLICADO`, `CONSULTORIO_EDICION_OBSOLETA`, `CONSULTORIO_CON_ASIGNACIONES`, `CONSULTORIO_CON_OBLIGACIONES`, `CONSULTORIO_NO_DISPONIBLE`, `ASIGNACION_IMPACTO_OBSOLETO`, `ASIGNACION_CONFIRMACION_REQUERIDA`, `PLANIFICACION_CONFLICTO`, `HORARIO_EDICION_OBSOLETA`, `HORARIO_CON_CITAS_VIGENTES`, `DURACION_EDICION_OBSOLETA`, `DURACION_INCOMPATIBLE`, `ATENCION_NO_TRASLADABLE`. 401/403/404/419/422/429 conservan contratos existentes. `AppError` aún no admite details: conflictos de escritura llevan código/mensaje seguro; la vista previa permite consultar elementos paginados, sin extender globalmente la excepción por esta fase.

### Participantes previstos y auditoría

Crear archivos solo en su fase: módulo `consultorios/` para ConsultorioController/Service/Repository, AsignacionConsultorioService y sus Requests/Resources; módulo `horarios/` para HorarioController/Service/Repository, ConfiguracionCitasController/Service/Repository y sus Requests/Resources. Validators puros en su ubicación actual solo si aportan reutilización (intervalos/cobertura/cálculo de turnos). Coordinación de planificación compartida reutilizable en business/services, con la consulta de lock encapsulada en repository de horarios; no BaseRepository ni interfaces vacías. Cada clase tiene un único propietario.

Controllers adaptan HTTP y Resources; Requests validan estructura/listas cerradas; Services autorizan, calculan impacto/reglas y coordinan DB::transaction; Repositories consultan/persisten/bloquean modelos existentes; PostgreSQL refuerza integridad. Se reutilizan AutenticacionService, ContinuidadAdministrativaService, MedicoRepository, BitacoraRepository y RespuestaError, sin duplicarlos ni introducir HTTP en negocio.

Eventos previstos módulo CONSULTORIOS: `CONSULTORIO_CREADO`, `CONSULTORIO_ACTUALIZADO`, `CONSULTORIO_ESTADO_CAMBIADO`, `MEDICO_CONSULTORIO_CAMBIADO`; módulo HORARIOS: `HORARIO_CREADO`, `HORARIO_ACTUALIZADO`, `HORARIO_ESTADO_CAMBIADO`, `DURACION_CITAS_CAMBIADA`. IDs, campos modificados, estado/duración/ubicación administrativa anterior/nueva y cantidades, nunca contenido de consulta ni motivo de cita. CambioCita `CAMBIO_UBICACION` mantiene estado anterior=nuevo y almacena referencias administrativas. Instrumentar escritura y ampliar listas permitidas de proyección/filtros de CU-30 juntas: actualmente Consultorio/Horario/ConfiguracionClinica no tienen interpretación específica completa. Las previsualizaciones/no-op no generan eventos de modificación.

### Secuencia y criterios de verificación

| Fase | Entrega | Verificación de cierre |
| --- | --- | --- |
| 1 — diseño (actual) | Reglas/contratos, persistencia, locks, impacto y participantes previstos | Correspondencia con código/esquema y documentación revisada; sin endpoints nuevos |
| 2 — consultorios | Catálogo, estado y migración de versión | Permisos/CSRF, unicidad, no-op/obsoleto, asignaciones/obligaciones, auditoría y migración con datos previos |
| 3 — asignación CU-11 | Vista previa y confirmación de cambios de ubicación | Conflictos horarios/citas, atención con iniciada_en NULL, traslado/historia/avisos, huella completa entre páginas, rollback |
| 4 — horarios/duración CU-12 | Intervalos/actividad, impacto y configuración global | Inactivo/activo independientes, cobertura de citas, turnos contiguos/sobrantes, duración almacenada intacta |
| 5 — garantías | Seguridad, carreras PostgreSQL y rendimiento | Dos asignaciones, horario vs duración/traslado, cita/atención simuladas en ambos órdenes y rollback; medición antes de índices |
| 6 — integración/cierre | API con sesiones reales y productores CU-30 | Flujos reales existentes y fixtures aislados para citas/avisos, suite/Pint/rutas, docs sincronizadas y límites declarados |

Pruebas en PostgreSQL aislado con datos ficticios; sin migraciones destructivas en base habitual, seeders de producción, correo real ni despliegue. Análisis incremental por CU; DER físico solo cambia cuando se implementa la migración. **Diagrama global y actualización PHP conservan el aplazamiento expreso.**

## CU-30 — fase 1: contrato de consulta de Bitácora (2026-10-21)

**Estado actual: backend de consulta CU-30 completado y verificado localmente en fases 1–5.** Listado/detalle implementados, seguridad/integridad/rendimiento revisados e integración comprobada con productores reales de los módulos existentes. Esta sección es la referencia del contrato HTTP para React. Concreta CU-30, VS-AUD-01–06 y DER §3.21; es una ampliación del proyecto compatible con RN12/RN14, sin nuevos RF/RN oficiales. Frontend de Bitácora y aceptación de producción pendientes; los módulos futuros instrumentarán sus propios eventos al implementarse.

### Acceso y operaciones

| Método y ruta implementados | Operación | Respuesta |
| --- | --- | --- |
| `GET /api/bitacora` | Listado filtrado y paginado | `200`, colección `data`, `links`, `meta` de Laravel |
| `GET /api/bitacora/{id}` | Detalle de un evento | `200`, objeto bajo `data` |

Se reutilizarán `auth:sanctum`, `VerificarCuentaHabilitada`, `ExigirSesionNormal` y `ExigirRol:Administrador`, con las clases reales del proyecto. El Service volverá a exigir sesión normal y rol dentro de una transacción, bloqueando la cuenta del actor según el patrón de EspecialidadService, para coordinar la lectura con revocaciones concurrentes. La autenticación/autorización precede a la consulta del evento; no se consultan relaciones clínicas.

No se publican rutas de escritura, exportación, catálogos auxiliares ni consulta anónima. La lectura no genera un evento nuevo por cada acceso ni altera eventos históricos. Se mantienen los productores y las protecciones PostgreSQL existentes. Conservación técnica en esta entrega: preservar el historial, sin purga automática ni plazo de borrado nuevo; un plazo institucional de retención requiere decisión posterior.

### Parámetros del listado

Todos son opcionales y escalares; los presentes no admiten vacío/null/arrays. Parámetros desconocidos (incluidos `sort`, `order`, SQL o rutas JSON del cliente) producen `422`, como los Requests estrictos existentes. Filtros diferentes se combinan con **AND**. No hay búsqueda parcial ni comodines.

| Parámetro | Validación y significado |
| --- | --- |
| `fecha_desde`, `fecha_hasta` | Fechas reales de calendario, años 0001–9999, formato exacto `YYYY-MM-DD`. Extremos opcionales independientes; si ambos existen, desde ≤ hasta. Filtran `ocurrido_en`, no `registrado_en`. |
| `id_usuario_responsable` | Entero decimal positivo hasta `9223372036854775807`; coincidencia con la FK responsable. No confundir con la cuenta objetivo. |
| `id_usuario_objetivo` | Mismo rango; coincidencia con la FK objetivo. |
| `tipo_actor` | `USUARIO`, `SISTEMA` o `ANONIMO`. Permite buscar eventos sin responsable autenticado. |
| `modulo` | Texto sin controles, longitud 1–40; igualdad exacta, sensible a mayúsculas. Preservar, por ejemplo, `AUTENTICACION` e `Instalacion` sin normalizarlos a un único valor. |
| `operacion` | Texto sin controles, longitud 1–60; igualdad exacta sensible a mayúsculas. |
| `tipo_evento` | Texto sin controles, longitud 1–80; igualdad exacta sensible a mayúsculas. |
| `resultado` | `EXITO`, `FALLO`, `DENEGADO` o `ERROR`. |
| `direccion_ip` | Una IPv4/IPv6 válida; igualdad de dirección PostgreSQL INET, no subred/CIDR ni búsqueda textual. |
| `entidad_referida` | `Usuario`, `Paciente`, `Medico`, `Especialidad`, `Consultorio`, `CuentaPrueba` o `ConjuntoDemostracion`. Consultorio incorporado con CU-11 fase 2; ampliar explícitamente con módulos futuros. |
| `registro_referido` | Requiere entidad. Para Usuario/Paciente/Medico/Especialidad/Consultorio, BIGINT positivo; para CuentaPrueba/ConjuntoDemostracion, identificador de 1–64 caracteres ASCII de `[A-Za-z0-9_-]`. Igualdad exacta. |
| `page` | Entero 1–1000000; predeterminado 1. |
| `per_page` | Entero 1–100; predeterminado 20. |

Se admite entidad sin registro para buscar todos los eventos de ese tipo de recurso. No se comprueba existencia actual de cuentas/recursos para validar filtros: un ID válido sin coincidencias devuelve lista vacía. Valores de módulo/operación/tipo bien formados pero aún no existentes también devuelven vacío. Criterios textuales se recortan solo en extremos con espacios ASCII; no se cambia el caso. Los identificadores numéricos se comparan por su valor decimal y se serializan sin ceros iniciales.

**Fechas:** `fecha_desde` incluye las 00:00:00 de ese día en `America/La_Paz`; `fecha_hasta` incluye el día completo mediante un límite exclusivo en las 00:00:00 del día siguiente. Convertir ambos límites locales a UTC antes de comparar: `ocurrido_en >= inicio_utc AND ocurrido_en < siguiente_dia_utc`. Un solo día se obtiene enviando ambas fechas iguales. No usar fin de día redondeado ni `whereDate` dependiente de la zona de la conexión.

**Orden fijo:** `registrado_en DESC, id_evento DESC`, según el DER. `ocurrido_en` se muestra como fecha del hecho; puede diferir del orden de inserción. Paginación numerada con total del servidor; sin coincidencias o página posterior a la última: `data: []` conservando `meta` y filtros. Las lecturas sucesivas reflejan eventos confirmados disponibles en cada consulta; no prometen una instantánea entre páginas frente a nuevas inserciones. Los enlaces de paginación conservan únicamente filtros validados. No se aceptan columnas de orden del cliente.

### Compatibilidad del filtro por recurso

El Repository construirá un grupo **OR** entre ubicaciones fijas del backend y lo combinará con los otros filtros mediante AND. No se permite al cliente elegir una clave JSON. No se reescriben eventos ni se deduce un recurso por nombres, CI o correo.

| Entidad | Ubicaciones existentes que se consultarán |
| --- | --- |
| `Usuario` | Pareja física `entidad_referida`/`registro_referido`; también `id_usuario_objetivo`. El responsable por sí solo no convierte al usuario en recurso afectado. |
| `Paciente` | Pareja física; `referencias.id_paciente`; y `cambios_seguros.id_paciente` solo en `ACTIVACION_PACIENTE_EMITIDA`, `VINCULACION_PACIENTE_INICIADA`, `PACIENTE_CUENTA_VINCULADA`. |
| `Medico` | Pareja física; `referencias.id_medico`. No se buscan los valores antes/después de un vínculo administrativo como si fueran su recurso principal. |
| `Especialidad` | Pareja física; `referencias.id_especialidad`. No incluye IDs contenidos en listas de habilitaciones de un Médico. |
| `Consultorio` | Pareja física; `referencias.id_consultorio` (productores CU-11 fase 2). |
| `CuentaPrueba`, `ConjuntoDemostracion` | Únicamente pareja física. |

Cada rama exige entidad y, si se informó, su identificador en **la misma ubicación**. Las ramas JSON requieren identificadores decimales válidos (cadena o número entero representable), no arrays/objetos/bools. Con entidad sola se exige presencia válida, no solo existencia de una clave. Todas las comparaciones usan parámetros; claves y entidades proceden del mapa fijo. No se añade un join a fichas ni se comprueba acceso clínico por encontrar una referencia.

### Respuestas y representación

Listado: cada elemento incluye exclusivamente `id_evento`, `tipo_evento`, `tipo_actor`, `id_usuario_responsable`, `rol_responsable`, `id_usuario_objetivo`, `rol_objetivo`, `modulo`, `operacion`, `resultado`, `causa_codigo`, `ocurrido_en`, `registrado_en`, `direccion_ip`, `entidad_referida`, `registro_referido` y `referencias` proyectadas. No incluye blobs JSON de cambios/metadatos ni relaciones Eloquent. Responsable y objetivo se identifican por ID/rol histórico almacenado, sin anexar nombre, correo, CI, perfil ni rol actual.

Detalle: los mismos campos más `procedencia_ip`, `correlacion`, `id_evento_relacionado`, `version_formato`, `cambios_seguros` y `metadatos_seguros` proyectados. Un evento relacionado se entrega solo como ID, sin expandir árboles ni relaciones. Campos físicos entidad/registro conservan su significado original; no se rellenan artificialmente con otra ubicación. `referencias` expresa referencias adicionales permitidas, no URLs navegables ni acceso implícito al recurso. Ambas respuestas llevan `Cache-Control: no-store, private`, coherente con datos administrativos protegidos y errores existentes.

Reglas de tipos: todos los BIGINT (incluidos IDs dentro de JSON) se devuelven como cadenas decimales; ausencia como `null`. Roles históricos son uno de los cuatro roles fijos o null. Fechas ISO 8601 UTC con seis decimales y `Z`; React las presenta en `America/La_Paz`. IP como texto canónico o null; `version_formato` como entero. Objetos JSON vacíos se devuelven como `{}`, nunca `[]` ni el JSON bruto. `data: []`, `links` y `meta` siguen el formato paginado de JsonResource ya utilizado por el backend (`current_page`, `last_page`, `per_page`, `total`, `from`, `to` y enlaces estándar).

Ejemplo **ilustrativo del contrato**, sin datos reales, de un elemento de listado (las fracciones de registrado_en dependen de la precisión física descrita en fase 2):

```json
{
  "id_evento": "123",
  "tipo_evento": "MEDICO_ESTADO_CAMBIADO",
  "tipo_actor": "USUARIO",
  "id_usuario_responsable": "1",
  "rol_responsable": "Administrador",
  "id_usuario_objetivo": null,
  "rol_objetivo": null,
  "modulo": "MEDICOS",
  "operacion": "MEDICO_ESTADO_CAMBIADO",
  "resultado": "EXITO",
  "causa_codigo": null,
  "ocurrido_en": "2026-10-21T14:00:00.000000Z",
  "registrado_en": "2026-10-21T14:00:00.123456Z",
  "direccion_ip": "192.0.2.10",
  "entidad_referida": null,
  "registro_referido": null,
  "referencias": {"id_medico": "7"}
}
```

### Proyección segura del detalle, formato 1

La proyección usa listas permitidas por familia de evento y valida tipos/valores, no basta con que una clave se llame «segura». Cada entrada inválida o desconocida se omite; nunca se devuelve el contenido bruto como recuperación. No se recorren objetos arbitrarios recursivamente. Listas permitidas: máximo 100 elementos, sin duplicados, con validación individual; si el origen supera el límite se omite esa lista completa, sin presentarla como completa tras truncarla. Nuevas necesidades requerirán ampliar el contrato y sus pruebas.

| Familia existente | Contenido permitido |
| --- | --- |
| `PACIENTE_CREADO` | `referencias.id_paciente`; `cambios_seguros.sin_documento` booleano. |
| `PACIENTE_ACTUALIZADO`, `PACIENTE_ESTADO_CAMBIADO` | `referencias.id_paciente`; `cambios_seguros.campos`, solo nombres entre documento_identidad, nombres, apellidos, fecha_nacimiento, sexo, telefono, correo_contacto, direccion, activo, contacto_emergencia, recordatorios_habilitados. No sus valores anteriores/nuevos. |
| `MEDICO_CREADO`, `MEDICO_ACTUALIZADO`, `MEDICO_ESTADO_CAMBIADO`, `MEDICO_ESPECIALIDADES_CAMBIADAS` | `referencias.id_medico`; `cambios_seguros.campos` entre registro_profesional, nombres, apellidos, telefono, correo_contacto, activo, especialidades. `habilitaciones.habilitadas` y `habilitaciones.retiradas` como listas de IDs de Especialidad. |
| `ESPECIALIDAD_CREADA`, `ESPECIALIDAD_ACTUALIZADA`, `ESPECIALIDAD_ESTADO_CAMBIADO` | `referencias.id_especialidad`; `cambios_seguros.campos` entre nombre, descripcion, activa. |
| `CONSULTORIO_CREADO`, `CONSULTORIO_ACTUALIZADO`, `CONSULTORIO_ESTADO_CAMBIADO` | `referencias.id_consultorio`; `cambios_seguros.campos` entre codigo_consultorio, nombre, activo. Sin valores libres. |
| `MEDICO_CONSULTORIO_CAMBIADO` | `referencias.id_medico` e `id_consultorio` destino; `cambios_seguros.antes/despues.id_consultorio` (antes puede ser null) y cantidad entera no negativa `citas_trasladadas`. Sin etiquetas ni contenido clínico. |
| `HORARIO_CREADO`, `HORARIO_ACTUALIZADO`, `HORARIO_ESTADO_CAMBIADO` | `referencias.id_medico`/`id_horario`; `cambios_seguros.campos` entre dia_semana, hora_inicio, hora_fin, activo. |
| `DURACION_CITAS_CAMBIADA` | `referencias.id_configuracion`; `cambios_seguros.antes/despues.duracion_turno_minutos` (entero positivo dentro de INTEGER). |
| `CUENTA_DESACTIVADA`, `CUENTA_REACTIVADA`, `CUENTA_ROL_CAMBIADO` | En cambios `antes`/`despues`, solo `rol` (cuatro roles), `estado_cuenta` (Activa/Desactivada/Pendiente, valores reales de PostgreSQL) e `id_medico` (ID o null). |
| `ACTIVACION_PACIENTE_EMITIDA`, `VINCULACION_PACIENTE_INICIADA`, `PACIENTE_CUENTA_VINCULADA` | En cambios, `id_paciente` y, cuando exista, `id_codigo_activacion` como IDs positivos; el ID del registro no es el secreto del código. |
| `INSTALACION_ADMIN_INICIAL` | En metadatos, `verificacion_correo: AUTORIZACION_INSTALACION`, `metodo: SEEDER`. |
| `SEED_CUENTA_PRUEBA` | En metadatos, `verificacion_correo: SIMULADA_PRUEBAS`, `metodo: SEEDER`. |
| Otros eventos de autenticación/seguridad y `SEED_DEMOSTRACION` | Objetos adicionales vacíos, salvo referencias físicas permitidas. Los datos escalares de auditoría siguen disponibles. |

El texto libre `motivo_administrativo` se omite en esta API: podría contener datos personales o clínicos y no es necesario para identificar la operación. También se omiten claves desconocidas, valores de datos personales, contraseñas, hashes, códigos secretos, tokens, enlaces, diagnósticos, rutas de archivos y contenido clínico, aunque aparezcan dentro de un objeto permitido. Solo se publican parejas físicas de las entidades permitidas con identificadores válidos. Un formato distinto de 1 conserva la cabecera tipada y devuelve los tres objetos JSON vacíos hasta disponer de proyección específica.

`causa_codigo` no se utiliza como texto libre: permitir códigos estables del dominio realmente emitidos y revisados; para una causa desconocida devolver null. Lista inicial: `CLAVE_ACTUAL_INVALIDA`, `ENVIO_SIMULADO`, `ENVIO_NO_VIGENTE`, `ENVIO_NO_CONFIRMADO`, `CODIGO_INVALIDO`, `REGISTRO_NO_APLICABLE`, `ACTIVACION_CODIGO_INVALIDO`, `REGISTRO_ENVIO_LIMITADO`, `REGISTRO_CODIGO_INVALIDO`, `INVITACION_INVALIDA`, `RECUPERACION_NO_APLICABLE`, `RECUPERACION_INVALIDA`, `AUTH_NO_AUTENTICADO`, `ACCESO_DENEGADO`, `PERSONAL_ROL_INVALIDO`, `PERSONAL_VINCULO_INVALIDO`, `PERSONAL_INVITACION_NO_PERMITIDA`, `CUENTA_TRANSICION_INVALIDA` y `CUENTA_ROL_NO_PERMITIDO`. La causa general se identifica mediante este código, sin concatenar excepciones ni mensajes internos.

### Errores y criterios de aceptación de las fases siguientes

Se conserva `RespuestaError`: `401` sesión ausente/inválida o cuenta revocada conforme al flujo existente; `403 ACCESO_DENEGADO` otro rol y `403 AUTH_CAMBIO_CLAVE_OBLIGATORIO` sesión restringida; `422 VALIDACION_ERROR` filtros/identificador inválidos; `404 RECURSO_NO_ENCONTRADO` evento inexistente tras autorizar; `500 ERROR_INTERNO` seguro para fallos inesperados. ID de ruta decimal positivo BIGINT; patrón `[1-9][0-9]{0,18}`: ruta que no coincide devuelve 404 y número fuera del rango produce 422. No se inventa un 419 en un GET; se conserva CSRF para las escrituras de sesión existentes. Métodos no registrados siguen el rechazo HTTP/JSON del framework; no realizan mutaciones.

Verificaciones del plan: cuatro roles/sesión restringida/revocación, filtros solos y combinados, extremos de día en La Paz frente a UTC, orden con empate, BIGINT mayores que el entero seguro de JavaScript, vacío/página fuera de rango, IP v4/v6, todas las ubicaciones históricas del recurso, evento anónimo/sistema, formato/JSON desconocido o malicioso, claves/valores sensibles omitidos, detalle inexistente, rechazo de escrituras y regresión de productores/inmutabilidad. Evidencia acumulada en fases 2–5 a continuación, sobre PostgreSQL aislado y correo simulado; backend cerrado localmente, frontend/producción pendientes y diagrama global aplazado por decisión del usuario.

## CU-30 — fase 2: listado, filtros y paginación (2026-10-21)

**Implementado:** `GET /api/bitacora` registrado desde `routes/api.php` y `src/presentation/routes/bitacora.php`, con autenticación/cuenta habilitada/sesión normal/rol Administrador. BitacoraController recibe filtros de ListarBitacoraRequest, BitacoraService revalida actor con bloqueo transaccional y BitacoraRepository filtra/pagina desde PostgreSQL. BitacoraListadoResource proyecta únicamente la cabecera y referencias adicionales permitidas; no consulta nombres/perfiles ni entrega cambios/metadatos/correlación del detalle. El middleware transversal existente aplica `Cache-Control: no-store, private`.

**Filtros:** coincidencia exacta y combinaciones AND; grupo OR interno por recurso físico, cuenta objetivo y referencias históricas, incluidos cambios de activación/vinculación de Paciente. ReferenciasBitacora centraliza las entidades permitidas, la validación de IDs y las referencias publicables por familia; el Repository utiliza expresiones SQL internas y parámetros, sin cast numérico del JSON ni rutas recibidas del cliente. Identificadores JSON inválidos o fuera de BIGINT no coinciden ni generan errores SQL. Paginación 20/100, enlaces con filtros validados, orden registrado_en/id_evento descendente y fecha semiabierta La Paz→UTC según contrato. Filtros desconocidos o inválidos responden `422 VALIDACION_ERROR`.

**Precisión física verificada:** la migración 000009 existente declara `ocurrido_en` y `registrado_en` como TIMESTAMPTZ sin precisión explícita; Laravel genera precisión 0. PostgreSQL redondea al segundo al persistir. La API representa fielmente ese dato con seis decimales, no recupera fracciones históricas perdidas. Las pruebas de extremos utilizan el último segundo representable del día y contrastan serialización con el registro realmente almacenado. Sin modificación de tabla/migraciones/DER en esta fase.

**Verificación focalizada:** `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --colors=never tests/Feature/BitacoraListadoTest.php`: **62 pruebas / 682 aserciones**, PostgreSQL y sesiones reales en base/rol aislados `clinica_vida_salud_test`. Incluye roles, sesión ausente/caducada/desactivada/revocada/restringida, permiso revalidado en Service, filtros solos/combinados, valores literales, límites de paginación, fechas con conexión en otra zona horaria, IPv4/IPv6, recursos físicos/JSON/objetivo, proyección desconocida/malformada, BIGINT, orden con empate, no mutación por lectura y fallo inesperado seguro. Pint (9 archivos), sintaxis PHP y diff-check correctos; route:list confirma GET/HEAD con middlewares. Regresión completa de fase 2: **914 pruebas / 9988 aserciones correctas** (resultado recibido al iniciar fase 3).

**Estado al cierre de fase 2:** listado terminado y detalle pendiente entonces de fase 3, incorporado a continuación. No acredita CU-30 completo. Análisis parcial sincronizado; diagrama global expresamente aplazado.

## CU-30 — fase 3: detalle seguro del evento (2026-10-21)

**Implementado:** `GET /api/bitacora/{id}` bajo los mismos middlewares del listado. BitacoraController reutiliza `usuarios/CuentaRequest` para validar ID de ruta BIGINT y rechazar cualquier parámetro extra; no se permite sobreescribir el ID con query/body. BitacoraService comparte revalidación transaccional del Administrador entre listar/consultar, consulta mediante BitacoraRepository::porId y devuelve 404 seguro si el evento no existe. No se bloquea ni modifica el evento inmutable.

**Respuesta:** BitacoraDetalleResource extiende BitacoraListadoResource para conservar exactamente la cabecera y añadir procedencia IP, correlación, ID del evento relacionado, versión y cambios/metadatos proyectados. No expande relaciones ni obtiene identidad actual o contenido clínico. Cambios administrativos admiten solo nombres de campos, booleano sin_documento, IDs de habilitaciones/activación y estados/roles antes/después expresamente permitidos. Los metadatos de instalación/prueba solo admiten valores constantes. Motivos libres, contraseñas, códigos secretos, tokens y rutas se excluyen incluso dentro de claves permitidas. El ID del código de activación es una referencia al registro, no el secreto entregado al Paciente.

**Robustez de proyección:** comprueba estructura/tipos/valores por familia, convierte BIGINT a cadenas y elimina duplicados. Objetos donde se esperan listas, roles/estados inválidos, IDs fuera de rango y claves desconocidas se omiten; una lista mayor de 100 elementos se descarta completa. No hay fallback al JSON bruto. Una versión no reconocida devuelve referencias/cambios/metadatos como `{}` manteniendo la cabecera. Cada evento relacionado se representa solo por ID. Las lecturas no generan eventos ni modifican su contenido.

**Verificación:** `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --colors=never tests/Feature/BitacoraDetalleTest.php tests/Feature/BitacoraListadoTest.php tests/Feature/UsuariosAuditoriaTest.php`: **139 pruebas / 1264 aserciones correctas** con PostgreSQL/sesiones reales y base/rol aislados. Incluye 36 casos nuevos de detalle: familias de cambios y metadatos, tipos incorrectos, listas excesivas, exclusión de secretos, BIGINT, roles históricos y origen anónimo/sistema, igualdad de cabecera con listado, evento relacionado sin expansión, no mutación, 404/422 por ruta/rango/extra, permisos antes de consultar, sesiones inválidas/restringidas y 500 central seguro. Pint de los 6 archivos afectados correcto. La regresión completa de 914/9988 corresponde al cierre de fase 2; no se presenta como ejecución de fase 3.

**Comprobaciones finales/documentación de fase 3:** sintaxis PHP de los 6 archivos de fase 3 y `git diff --check` correctos; `artisan route:list --path=api/bitacora -v` confirma listado/detalle GET/HEAD con sus controles de acceso. README, caso, contexto y análisis parcial sincronizados. Persistencia y DER sin cambios; diagrama global expresamente aplazado. Seguridad/rendimiento se revisan en fase 4 a continuación; CU-30 aún no se declara terminado.

## CU-30 — fase 4: seguridad, integridad y rendimiento (2026-10-21)

**Acceso concurrente comprobado con PostgreSQL y procesos HTTP independientes:** se reutiliza AutenticacionConcurrenteTest y su worker de pruebas, ampliados con modos de consulta y barreras exclusivas de fixtures (sin rutas públicas auxiliares). Doce casos combinan listado/detalle, cambios de cuenta/rol/revocación de sesión y commit/rollback. Se pausa tras los middlewares y antes del Controller: el Service espera el bloqueo de Usuario y revalida contra el estado confirmado; commit de una revocación produce 401 y rollback conserva 200. Dos casos prueban el orden inverso: una lectura ya autorizada mantiene el bloqueo del actor mientras obtiene los datos, el cambio de acceso espera (SQLSTATE 55P03 con timeout controlado), y una consulta posterior a su confirmación se rechaza. El evento no está bloqueado por la lectura, comprobado desde otra conexión con `FOR UPDATE NOWAIT`. La garantía es el orden transaccional de autorización/lectura frente al cambio de acceso, no retirar una respuesta ya leída/autorizada.

**Solo lectura/integridad:** BitacoraSeguridadTest rechaza POST/PUT/PATCH/DELETE con 405 en listado y detalle, conservando el evento; HEAD responde sin cuerpo y con Cache-Control privado/no-store. Rutas auxiliares de edición/eliminación/exportación no existen. UPDATE, DELETE y TRUNCATE directos y actualización Eloquent fallan por `bitacora_inmutable_ck` (23514); tras cada rechazo la API devuelve el evento original. Pruebas dentro de transacciones de la base aislada, sin ejecutar mutaciones sobre datos habituales. Filtros con arrays, columnas/rutas JSON del cliente, intentos SQL y comodines no permiten ampliar resultados ni escribir.

**Rendimiento local medido:** fixture de 5000 eventos ficticios más un login, con JSON privado de 1 KiB por evento; `ANALYZE` y `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` de la consulta real de página. Listado de 100: 0,046 ms; listado de 1: 0,010 ms; día selectivo (24 resultados): 0,032 ms; recurso histórico (1 resultado): 6,233 ms. Son tiempos de página de esa ejecución local, excluyen count/HTTP y no constituyen SLA ni aceptación de producción. Dos consultas a Bitácora (total + página), 17 SELECT totales incluidas autenticación/sesión tanto para 1 como para 100 filas: sin N+1 dependiente de eventos. El listado excluye cambios/metadatos del SELECT y del cuerpo.

**Índices:** los planes utilizan `bitacora_orden_idx` para el listado y `bitacora_fecha_idx` para el período selectivo. El filtro OR de recursos históricos conserva recorrido sobre las formas existentes; el volumen medido no justifica agregar índices/migraciones ni reescribir eventos. Reevaluar con volumen y planes reales si cambia la carga; no se promete coste constante para filtros JSON o páginas profundas. Se conserva la precisión física de segundos documentada en fase 2.

**Evidencia de cierre de fase 4:** concurrencia focalizada **14 pruebas/130 aserciones**; seguridad focalizada inicial **20 pruebas/198 aserciones**, ampliada después para comparar SELECT totales en páginas de 1/100. Regresión completa final: `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --colors=never`, **984 pruebas/10704 aserciones correctas**, 5 min 23 s, PostgreSQL/base/rol aislados `clinica_vida_salud_test`. Incluye toda la auditoría, autenticación y los consumidores anteriores del worker. Pint de los 5 archivos de fase 4 correcto. No se encontró un fallo funcional que exigiera modificar código productivo ni índices en esta fase; se incorporaron pruebas y evidencia. README/caso/contexto/análisis parcial sincronizados; DER sin cambios, diagrama global aplazado. Fase 5 integración y cierre del backend pendientes.

## CU-30 — fase 5: integración y cierre backend local (2026-10-21)

**Alcance terminado:** consulta administrativa exclusiva de Administrador con listado/filtros/paginación y detalle seguro. Se reutiliza el almacenamiento/eventos de los módulos implementados, con inmutabilidad y controles transaccionales comprobados. El contrato de fase 1 ya puede consumirse desde el futuro frontend. Los eventos de módulos aún no desarrollados no se presentan como instrumentados.

**Integración:** BitacoraIntegracionTest recorre el Kernel HTTP de Laravel con cookies, restauración de guard por solicitud, sesiones PostgreSQL y CSRF efectivos. Cada caso utiliza un esquema efímero de la base/rol aislados, todas las migraciones y los cuatro roles fijos; solo la entrega de correo se sustituye por Mail::fake. No se ejecutan servidores externos ni se verifica Railway/Brevo real.

1. Alta de Especialidades/Médico, edición y rechazo de borrador obsoleto, retiro de especialidad y actividad profesional; creación de cuenta, invitación/aceptación/login/restauración, desactivación y rechazo de sesión anterior. El Administrador filtra por profesional/responsable/módulo y por cuenta objetivo, recorre páginas de dos eventos y consulta cada detalle; verifica campos/habilitaciones/estados históricos y exclusión de credenciales/contactos.
2. Recepción registra y modifica un Paciente, emite código presencial; vinculación web con segundo código de correo, verificación y login conservan la misma ficha. Se consulta el historial por recurso Paciente atravesando referencias y cambios históricos, con responsables/origen anónimo correctos. Se verifican rechazo de otros roles, logout e intento fallido de login, así como exclusión de ambos códigos, hashes, CI, correo, nombres y motivo administrativo libre.
3. Inyección controlada de fallo tras insertar una auditoría desde el productor real de Especialidades: respuesta 500 y rollback de catálogo/evento; luego alta correcta y conflicto por duplicado. La API devuelve exactamente los eventos exitosos persistidos, sin éxitos falsos ni alteraciones al consultar.

**Verificación final:** integración focalizada **3 pruebas/206 aserciones**; suite completa `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --colors=never`: **987 pruebas/10910 aserciones correctas**, 5 min 58 s. Pint del alcance acumulado de CU-30 y fixtures compartidos: **17 archivos correctos**. Esquemas de integración retirados por el teardown; no se aplicaron migraciones/seeders sobre la base habitual ni se enviaron correos reales. Las métricas locales de fase 4 se reprodujeron en la suite, sin añadir índices ni cambiar precisión de fechas.

**Extensión progresiva:** al incorporar eventos de otros módulos, el productor mantiene referencias/metadatos mínimos y la atomicidad con la operación real; ampliar explícitamente entidades/rutas históricas de filtro, proyección por familia/versión y sus pruebas cuando corresponda. Versiones y claves desconocidas conservan la salida restrictiva; no abrir el JSON completo por compatibilidad. La auditoría clínica general continúa limitada a metadatos.

**Comprobaciones finales/cierre documental:** sintaxis de 17 PHP y `git diff --check` correctos; Artisan confirma las dos rutas GET/HEAD con controles de sesión/cuenta/rol. README, CU-30, análisis de clases, contexto §17.71 e inventario de backend/AGENTS sincronizados. Sin cambios de persistencia: DER vigente conservado. El diagrama global permanece aplazado por decisión expresa. Backend CU-30 terminado localmente; frontend, aceptación Railway/HTTPS/cookies/Brevo real e instrumentación de módulos futuros conservan su alcance posterior. Actualización PHP sigue aplazada; sin commit/push/despliegue.

## Organización interna por módulos (2026-10-21)

Controllers, DTO, Services y Repositories se agrupan por módulo dentro de sus capas. Cada clase tiene una sola ubicación según su responsabilidad principal; otros módulos importan su namespace real. Las cuatro capas conservan el flujo Controller → Service → Repository → Model.

```text
src/
├── presentation/
│   ├── controllers/ → usuarios/, pacientes/, medicos/, bitacora/
│   └── dtos/        → usuarios/, pacientes/, medicos/, bitacora/
├── business/
│   └── services/    → usuarios/, pacientes/, medicos/, bitacora/
└── data/
    └── repositories/ → usuarios/, pacientes/, medicos/, bitacora/
```

- **usuarios:** CU-01–CU-06, cuentas, autenticación, invitaciones, contraseñas, registro/vinculación web y correo de acceso. `CuentaRequest`, `ClaveRequest` y `RespuestaError` tienen aquí su única definición reutilizable; este último sigue normalizando errores de toda la API.
- **pacientes:** CU-07/CU-08, ficha administrativa, perfil y emisión presencial del código de activación. RegistroPacienteService/Repository pertenecen a Usuarios por gestionar la activación de la cuenta web, aunque colaboren con fichas.
- **medicos:** CU-09/CU-10, profesionales y catálogo de especialidades; MedicoRepository pertenece aquí aunque CU-03 también lo use.
- **bitacora:** BitacoraRepository, compartido para escritura automática y consultas; BitacoraController/Service, ListarBitacoraRequest y Resources de listado/detalle implementan CU-30 backend, cerrado localmente en fase 5. Frontend pendiente.

Ejemplo PSR-4: `src/business/services/medicos/MedicoService.php` → `Clinica\business\services\medicos\MedicoService`. Se actualizan consumidores en Controllers, servicios, rutas, middlewares, bootstrap y pruebas/fixtures; sin duplicados ni alias antiguos. Modelos, validadores, rutas, middlewares y migraciones conservan su organización. Las secciones inferiores documentan la cronología funcional; esta reorganización no cambia contratos HTTP ni persistencia.

Distribución comprobada de **83 clases**:

| Carpeta | usuarios | pacientes | medicos | bitacora | Total |
| --- | ---: | ---: | ---: | ---: | ---: |
| services | 11 | 3 | 2 | — | 16 |
| controllers | 9 | 3 | 2 | — | 14 |
| dtos | 21 | 9 | 12 | — | 42 |
| repositories | 6 | 2 | 2 | 1 | 11 |

Autoload optimizado PSR-4/ambigüedad estrictos y carga por reflexión de las 83 clases/tipos correctos; sin clases PHP sueltas en las cuatro carpetas ni nombres duplicados. Registro Laravel conserva 43 rutas sin duplicados. Regresión completa tras el reordenamiento: **852 pruebas/9306 aserciones correctas**, 263,564 s y 130 MB, con `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --colors=never`. Pint y sintaxis correctos en 130 PHP existentes afectados (incluidos cambios acumulados); Composer validate/requisitos de plataforma, classmap sin namespaces antiguos, UTF-8/Markdown y `git diff --check` correctos. Cierre registrado en contexto §17.60.

## Médicos y especialidades — fase 5: integración backend CU-09/CU-10 (2026-10-21)

**Alcance administrativo del backend implementado:** catálogo de especialidades, registro/consulta/edición de médicos, estado profesional y habilitaciones históricas. Se conservan capas, autenticación y errores existentes; esta fase agrega pruebas integradas y documentación, sin nuevas dependencias, cambios de esquema ni ajustes de comportamiento. Los apartados inferiores conservan la cronología de las fases 0–4.

### API administrativa vigente

Todos los endpoints requieren Administrador con cuenta habilitada/sesión normal, cookies/Sanctum y origen permitido; escrituras con CSRF. IDs BIGINT se devuelven como cadenas.

| Método y ruta | Entrada / resultado |
| --- | --- |
| GET /api/especialidades | nombre literal, activa, page/per_page; listado paginado, ID descendente |
| POST /api/especialidades | nombre, descripcion nullable/opcional; 201 con especialidad activa |
| GET /api/especialidades/{id} | Detalle de catálogo y versión |
| PATCH /api/especialidades/{id} | nombre/descripcion parciales y actualizado_en_original |
| PATCH /api/especialidades/{id}/estado | activa y actualizado_en_original |
| GET /api/medicos | nombre completo literal, id_especialidad habilitada, activo, page/per_page; listado administrativo mínimo |
| POST /api/medicos | registro_profesional/nombres/apellidos/telefono/correo_contacto y especialidades; 201 con código generado, sin cuenta/consultorio automáticos |
| GET /api/medicos/{id} | Datos administrativos, cuenta_vinculada booleano, versión y asociaciones históricas con habilitada |
| PATCH /api/medicos/{id} | Datos administrativos parciales y actualizado_en_original; no cambia controles ni vínculos |
| PATCH /api/medicos/{id}/estado | activo y actualizado_en_original; cuenta/rol/sesiones independientes |
| PUT /api/medicos/{id}/especialidades | Conjunto completo deseado de IDs habilitados y actualizado_en_original; conserva pares históricos |

Versiones UTC/seis decimales, página por defecto 20/máximo 100. Éxitos con data y meta/links al paginar; errores seguros centralizados. 409 por nombre/código único conocido, borrador obsoleto, vínculo incompatible, mínimo una habilitación o retiro con citas futuras. 422 por campos/relaciones inválidos; 401/403/419 conservan semántica. No hay DELETE ni reasignación de citas.

### Integración y cierre

`MedicosEspecialidadesIntegracionTest` ejecuta dos recorridos con todos los componentes Laravel y PostgreSQL aislado: **2 pruebas/140 aserciones correctas**.

1. Alta de dos especialidades y un Médico → invitación CU-03 → verificación/contraseña y login → edición de contacto sin alterar correo de acceso → catálogo/estado/habilitaciones independientes → dos sesiones conservadas por inactivación profesional y revocadas por desactivación de cuenta → nuevo login tras reactivar cuenta → rehabilitación sin duplicar par. Auditoría con actor real, sin contactos/tokens; correo exclusivamente simulado.
2. Médico existente con cita, consulta/versiones, antecedentes y laboratorio → alta de especialidades → retiro rechazado sin agregar pares parciales → inactivación sin resolver artificialmente obligaciones → retiro después del inicio histórico y rehabilitación → conservación íntegra de ocho tablas clínicas/documentales, identidad/vínculo/código y estados de catálogo/profesional.

Regresión global correcta: `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --colors=never`, **852 pruebas/9306 aserciones**, 291,615 s y 130 MB. Pint y sintaxis correctos en 37 PHP afectados; Composer validate, requisitos de plataforma y autoload PSR-4/ambigüedad estrictos correctos. Registro de 43 rutas, 11 del módulo, sin duplicados. La suite conserva las carreras de fases 1–4 y la coordinación con CU-03. README, casos, análisis, DER y contexto §17.59 reflejan el alcance real; **diagrama global aplazado expresamente por el usuario**.

**Aplicación:** migraciones aditivas 000030–000032 disponibles y probadas; esquema resultante 28 entidades/242 atributos/43 relaciones. Base habitual/Railway pendientes de `php artisan migrate --force`; no se aplican durante esta entrega. Interfaz React específica, agenda CU-20, consultorios/horarios CU-11/CU-12 y reservas/reprogramaciones CU-14/CU-15 conservan sus incrementos. Estos últimos deberán tomar el mismo bloqueo del Médico y revalidar actividad de Médico/catálogo y habilitación del par al guardar. RF03 íntegro depende de agenda; no se acredita aceptación frontend ni despliegue/Brevo real por cerrar este backend.

## Médicos y especialidades — fase 4: habilitaciones CU-10 (2026-10-21)

Implementado `PUT /api/medicos/{id}/especialidades` para Administrador normal: `especialidades` es el conjunto completo de IDs habilitados deseados y `actualizado_en_original` la versión UTC/seis decimales. Respuesta 200 con detalle vigente; IDs inválidos/repetidos, relaciones inexistentes y campos extra producen 422. Conjunto vacío produce `409 MEDICO_SIN_ESPECIALIDADES_HABILITADAS`, también si el Médico o catálogo están inactivos. Versión antigua falla antes del no-op o de evaluar el conjunto.

- Agregar crea el par; retirar solo pone `habilitada=false`; rehabilitar actualiza el mismo par. No se borran asociaciones ni se usa sync destructivo. Se conservan PK compuesta, FK de citas e historial; actividad del catálogo y habilitación son independientes.
- Retiro rechazado con `409 MEDICO_ESPECIALIDAD_CON_CITAS_FUTURAS` si el par tiene citas Programadas/Confirmadas con `inicio_at >= ahora`. El corte UTC se captura una sola vez **después de adquirir locks**. Inicio anterior, Atendida/Cancelada/Inasistencia y citas de otros pares no bloquean por esta regla. El conflicto no cancela/reprograma ni guarda altas o retiros parciales.
- Orden de coordinación: CU-03 → actor → Médico → especialidades ordenadas → pares. Datos/estado/habilitaciones comparten versión monotónica; conjunto vigente idéntico, aunque cambie orden, no guarda ni duplica evento. Cambio/versión/evento `MEDICO_ESPECIALIDADES_CAMBIADAS` confirman juntos; fallo de auditoría revierte marcas, pares nuevos y versión. Bitácora registra IDs habilitados/retirados y responsable, sin contactos ni datos clínicos.
- Listado y filtro de médicos incluyen **solo asociaciones habilitadas**. Detalle muestra todas las históricas con `habilitada` booleano; las relaciones Eloquent históricas conservan todos los pares. No se derivan turnos por habilitar. Seeders conservan insertOrIgnore: pares nuevos usan default true; reejecución no rehabilita marcas retiradas.

**Migración aditiva 000032:** `2026_10_21_000032_add_habilitada_to_medico_especialidad.php` añade `BOOLEAN NOT NULL DEFAULT TRUE` e inicializa todos los pares anteriores sin modificar identidad/FK/citas. MedicoEspecialidad incorpora cast booleano y relaciones N:M incluyen el atributo de pivote. Esquema con 000030–000032: **28 entidades, 242 atributos, 43 relaciones**. Probada únicamente en base/rol aislados; base habitual/Railway requieren `php artisan migrate --force` al incorporar estas entregas.

**Verificación focalizada:** 110 pruebas/1126 aserciones correctas de MedicosAdministrativosTest, MedicosHorariosTest y CuentasPruebaSeederTest, más cinco carreras PostgreSQL/70 aserciones correctas. Se comprueban bordes de fecha/estados, no-op/versiones, mínimo un par, alta/retiro/rehabilitación, filtros e historia, inputs/permisos/CSRF, rollback, migración sobre pares/citas previos y preservación de marcas al repetir seeder. Retiro vs reserva se prueba en ambos órdenes con commit/rollback y dos sustituciones simultáneas no eliminan la última habilitación. El escritor de reserva es **solo una simulación del protocolo en pruebas**; CU-14/CU-15 deberán implementarlo y revalidar Médico/catálogo/par bajo bloqueo al persistir. No se acreditan esos endpoints ni agenda.

**Cierre verificado de fase 4:** suite completa **850 pruebas/9166 aserciones correctas** (325,215 s), 29 casos nuevos respecto de fase 3. El primer intento con PHP CLI de 128 MB agotó memoria antes del cierre; repetición completa correcta con `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --colors=never` (130 MB consumidos), sin cambiar configuración permanente. Pint/sintaxis de 36 PHP afectados acumulados, Composer validate/autoload optimizado PSR-4/ambigüedad estrictos, 43 rutas totales sin duplicados (seis de médicos), UTF-8/Markdown y revisión de diferencias correctos. README, CU-10, análisis, DER §§3.15/6.18 y contexto §17.58 sincronizados; diagrama global mantiene aplazamiento expreso. **Siguiente: fase 5 — integración y cierre backend CU-09/CU-10.**

## Médicos y especialidades — fase 3: estado profesional CU-10 (2026-10-21)

Implementado `PATCH /api/medicos/{id}/estado`: Administrador normal, entrada cerrada `activo` booleano y `actualizado_en_original` UTC/seis decimales; respuesta 200 con detalle administrativo. Reutiliza controles de sesión/cuenta/rol/origen/CSRF, versión de fase 2, persistencia y auditoría existentes. No requiere nueva migración.

- Cambiar actividad solo modifica `Medico.activo` y su versión monotónica. Evento `MEDICO_ESTADO_CAMBIADO` en la misma transacción, con actor/referencia/campo seguro. No-op vigente no guarda ni emite; borrador obsoleto recibe 409 antes del no-op. Fallo de auditoría revierte actividad/versión.
- Inactivar conserva citas, atenciones/revisiones pendientes, asociaciones, horarios, consultorio, cuenta, rol, bloqueos y sesiones. No cancela ni reprograma. Cuenta activa/rol Médico y vínculo legítimo permiten conservar acceso normal a funciones autorizadas; los endpoints clínicos/agenda mantienen su desarrollo posterior.
- Activar sin cuenta es válido. Con cuenta vinculada se exige rol Médico, ausencia de ficha Paciente y vínculo recíproco con el mismo profesional; en caso contrario `409 MEDICO_VINCULO_INCOMPATIBLE`. No activa cuentas Pendientes/Desactivadas ni elimina un bloqueo temporal. Una cuenta con rol retirado por CU-03 requiere primero su retorno explícito y autorizado a Médico; la actividad profesional no promueve roles.
- Coordinación administrativa compartida con CU-03 antes de actor y Médico. Consulta de cuenta sin adquirir un lock posterior al profesional: la coordinación compartida protege cambios de rol/vínculo de CU-03. Se prueba retiro de rol vs activación en ambos órdenes; no queda activo un profesional cuya cuenta perdió el rol mediante esos flujos.
- CU-03 mantiene sus reglas: para retirar el rol exige inactividad profesional expresa y ausencia comprobable de obligaciones. Inactivar no acredita cierre clínico/documental. Desactivar la cuenta revoca sesiones sin cambiar actividad profesional. Reservas/reprogramaciones futuras deberán tomar el lock del Médico y revalidar actividad al guardar; este incremento no implementa CU-14/CU-15.

**Verificación y cierre de fase 3:** regresión completa **821 pruebas/8863 aserciones correctas**, 299,87 s. Incluye 21 casos nuevos respecto de fase 2: 17 funcionales (52 acumulados en MedicosAdministrativosTest) y cuatro carreras nuevas en AutenticacionConcurrenteTest. Se prueban sesiones múltiples conservadas, cuentas activas/desactivadas/pendientes y bloqueo preservados, salida/retorno explícito de rol, versión común datos/estado, rollback, entradas/roles/CSRF y coordinación entre procesos PostgreSQL independientes. Pint/sintaxis de los 32 PHP afectados acumulados, Composer validate/autoload optimizado PSR-4/ambigüedad estrictos, 42 rutas totales sin duplicados (cinco de médicos), UTF-8/Markdown y revisión de diferencias correctos.

**Estado documental:** CU-10, análisis incremental, DER (sin cambio de esquema) y contexto §17.57 sincronizados. Esquema permanece en 28 entidades/241 atributos/43 relaciones; migraciones 000030/000031 siguen pendientes en base habitual/Railway. Diagrama global aplazado expresamente. **Siguiente: fase 4 — habilitaciones de especialidades e integridad concurrente**, conservando las asociaciones históricas.

## Médicos y especialidades — fase 2: datos administrativos CU-10 (2026-10-21)

Implementados `GET/POST /api/medicos` y `GET/PATCH /api/medicos/{id}` para **Administrador con sesión normal**. Listado por nombre completo literal, especialidad y actividad, filtros combinados, ID descendente y paginación 20/100. Detalle con registro profesional/contactos, especialidades, `cuenta_vinculada` booleano y versión; listado sin contactos ni datos de cuenta. IDs textuales y respuestas con lista explícita de campos.

- Alta con registro/nombres/apellidos/teléfono/correo de contacto y una o varias especialidades existentes, IDs distintos en una lista. Código `MED-` + 128 bits aleatorios generado exclusivamente en servidor, inicialmente activo y sin cuenta, consultorio ni horarios automáticos. Registro profesional/correo de contacto **no son únicos**. Correo normalizado en minúsculas, distinto del correo de acceso.
- Asociaciones iniciales en transacción junto al profesional y evento `MEDICO_CREADO`. Se bloquean especialidades en orden ascendente y se verifica existencia bajo lock. Actividad del catálogo y asociación son independientes; asociar una especialidad inactiva conserva esa actividad y no habilita reservas. Todos los pares mantienen la semántica existente: **no se consulta `habilitada` antes de fase 4**.
- PATCH solo acepta datos administrativos y `actualizado_en_original` UTC/seis decimales. Comparación bajo bloqueo; `409 MEDICO_EDICION_OBSOLETA` incluso para un aparente no-op antiguo. Guardado con avance monotónico de versión y evento `MEDICO_ACTUALIZADO`; no-op vigente no guarda ni duplica auditoría. No cambia código, cuenta, rol, consultorio, asociaciones, actividad o citas.
- Reutiliza coordinación administrativa de CU-03 **antes** del bloqueo de actor/profesional; actor/cuenta/sesión/rol revalidados dentro de transacción. Auditoría con responsable/referencia/campos, sin copiar contactos ni información clínica. Fallo de auditoría revierte datos, pares y versión. Solo la colisión del índice `medicos_codigo_uq` se traduce a `409 MEDICO_CONFLICTO_UNICIDAD`; otros fallos SQL conservan 500 seguro.
- Capas: MedicoController + tres Requests/dos Resources → MedicoService → ampliación de MedicoRepository/EspecialidadRepository → modelos existentes. Middleware compartido de sesión, roles, origen y CSRF. Sin duplicar modelos/repositorios ni añadir dependencias.

**Persistencia:** migración aditiva `2026_10_21_000031_add_actualizado_en_to_medicos.php`: `medicos.actualizado_en TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP`, con inicialización de filas previas. Esquema con 000030/000031: **28 entidades, 241 atributos, 43 relaciones**. Se conserva PK/FK del pivote y todas las relaciones existentes. Probada en base/rol aislados `clinica_vida_salud_test`; pendiente aplicar migraciones en base habitual/Railway mediante `php artisan migrate --force` al incorporar la entrega.

**Verificación:** MedicosAdministrativosTest, **35 pruebas/436 aserciones correctas**; incluye permisos, restricciones de sesión, CSRF/origen, validaciones, recursos mínimos, filtros, IDs, registro repetible, versión/no-op, preservación de cuentas/citas/consultorio, rollback y migración sobre filas existentes. Tres carreras nuevas de procesos PostgreSQL: dos borradores de distintos Administradores, especialidad que desaparece durante espera y actor revocado durante coordinación. **Regresión completa: 800 pruebas/8613 aserciones correctas**, 286,38 s; 38 casos nuevos respecto del cierre de fase 1. Pint/sintaxis de los 31 PHP afectados acumulados, Composer validate/plataforma/autoload estricto, 41 rutas totales sin duplicados (cuatro de médicos), UTF-8/Markdown y revisión de diferencias correctos.

README, CU-10, análisis incremental, DER §§3.13/6.17 y contexto §17.56 sincronizados. **Próxima fase: 3 — estado profesional e integración con cuentas CU-03**; asociaciones editables conservan fase 4. Consultorios/horarios/agenda e interfaz específica siguen sus incrementos. Diagrama global conserva aplazamiento expreso.

## Médicos y especialidades — fase 1: CU-09 implementado (2026-10-21)

Gestión administrativa de especialidades disponible en backend. Solo Administrador con sesión normal, cuenta habilitada y origen/CSRF válidos: `GET/POST /api/especialidades`, `GET/PATCH /api/especialidades/{id}` y `PATCH /api/especialidades/{id}/estado`. Se respetan los contratos de la fase 0 descritos debajo; los endpoints posteriores de datos de Médico/CU-10 se describen en fase 2.

- Nombre obligatorio, máximo 120; descripción opcional/nullable hasta 2000; rechazar campos extra y caracteres de control. Filtros nombre literal/activa, página desde 1, 20 elementos por defecto/100 máximo y orden ID descendente. Se conserva la normalización de PostgreSQL `lower(btrim(nombre))`, sin eliminar acentos ni espacios internos; además se aplica el saneamiento de entrada compartido de Laravel. Duplicados del índice conocido producen `409 ESPECIALIDAD_NOMBRE_DUPLICADO`; fallos SQL distintos conservan error seguro, no un conflicto ficticio.
- El Resource devuelve únicamente `id_especialidad` textual, nombre, descripción, activa y `actualizado_en` UTC con seis decimales. PATCH exige `actualizado_en_original`, compara la fila bloqueada y devuelve `409 ESPECIALIDAD_EDICION_OBSOLETA` si cambió, incluso para un aparente no-op. Cambio efectivo avanza versión monotónica; no-op válido conserva versión y evita eventos duplicados.
- Crear deja activa; editar datos no cambia estado. Desactivar/reactivar no borra, modifica ni cancela asociaciones, médicos o citas, y no genera disponibilidad. Las futuras reservas/reprogramaciones deberán revalidar actividad al guardar. No se ofrece DELETE.
- `EspecialidadController`/Requests/Resource → `EspecialidadService` → `EspecialidadRepository` → Especialidad. Actor/rol revalidados bajo bloqueo en transacción; datos/versión/evento de BitacoraRepository se confirman juntos. Eventos ESPECIALIDAD_CREADA/ACTUALIZADA/ESTADO_CAMBIADO con actor, referencia y campos, sin copiar descripción/contactos ni información clínica.

**Migración aditiva nueva:** `src/data/database/migrations/2026_10_21_000030_add_actualizado_en_to_especialidades.php`, agrega `actualizado_en TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP` e inicializa registros anteriores sin cambiar sus datos. No modifica migraciones aplicadas ni añade tablas/FK. El esquema con esta migración comprende **28 entidades, 240 atributos y 43 relaciones**; los 239 atributos citados en fases anteriores corresponden al esquema previo. `medicos.actualizado_en` y `medico_especialidad.habilitada` siguen previstos para fases 2/4. Migración aplicada/revertida únicamente en base aislada de pruebas; **base habitual y Railway aún requieren `php artisan migrate --force` al incorporar esta entrega**. No se ejecutaron seeders ni envíos reales.

**Verificación focalizada:** 30 pruebas/329 aserciones en EspecialidadesTest y cuatro carreras/57 aserciones en AutenticacionConcurrenteTest: **34 pruebas/386 aserciones correctas**. Incluyen cookies/CSRF/origen, cuatro roles, cuenta revocada y sesión restringida, entradas/IDs/paginación, duplicados/normalización, versión obsoleta/monotónica, descripción nullable, no-op, preservación de citas/asociaciones, rollback de auditoría, error SQL ajeno y migración sobre filas existentes con tipo/precisión/NOT NULL/default comprobados. Dos procesos PostgreSQL independientes con Administradores distintos prueban altas/renombrados por el mismo nombre, edición vs estado y revocación mientras se espera. La primera regresión detectó una expectativa histórica de columnas en CatalogosInicialesTest; actualizada para incluir la extensión CU-09 y verificar su cast/default. Ejecución conjunta posterior Especialidades/Catálogos: 57 pruebas/457 aserciones correctas. Regresión completa posterior pendiente de registrar al cierre. Composer validate/autoload PSR-4/ambigüedad estrictos y registro de 37 rutas totales/cinco de especialidades sin duplicados correctos.

Análisis CU-09, caso de uso, DER §6.16 y contexto §17.55 sincronizados. Diagrama global aplazado expresamente; no se declara completo el módulo CU-09/CU-10. Próxima fase: **2, datos y alta de médicos**.

**Cierre verificado de fase 1:** suite completa `php artisan test --compact --no-ansi`, **762 pruebas/8143 aserciones correctas** (304,61 s). Incluye los 34 casos nuevos y regresión de Usuarios, Pacientes, datos/seeders y concurrencia. Pint y sintaxis de los 18 PHP afectados correctos; Composer validate, autoload optimizado PSR-4/ambigüedad estrictos y requisitos de plataforma correctos; rutas y revisión documental/diff comprobadas. CU-09 backend implementado/verificado; interfaz específica y CU-10 siguen posteriores. Los estados de ejecución descritos antes corresponden a la cronología de la comprobación.

## Médicos y especialidades — fase 0: diseño backend (2026-10-21)

**Registro histórico de diseño:** fase 0 de CU-09/CU-10 diseñada; los contratos y participantes de este apartado eran **previstos, no endpoints/clases implementados** al cerrar esta fase. El avance posterior de CU-09 está en el apartado superior. La persistencia existente no completa los CU. Fuentes: RF03/RF04, RN02/RN04/RN11/RN12/RN14, VS-MED-01–07 y CU-09/CU-10. La decisión de retiro aprobada es una regla propia del proyecto. Agenda CU-20, asignación de consultorio CU-11 y horarios CU-12 conservan su alcance posterior; RF03 íntegro no se declara cerrado por este módulo.

### Secuencia de entregas

| Fase | Entrega | Criterio de cierre |
| --- | --- | --- |
| **0 — Diseño** | Regla de retiro, contratos, persistencia prevista, transacciones y pruebas | Documentos coherentes con esquema/código real; sin presentar API como implementada |
| **1 — CU-09** | Listado/detalle/alta/edición/estado de especialidades | Administrador, duplicados normalizados, estados, versionado, auditoría y carreras verificados; análisis CU-09 actualizado |
| **2 — CU-10: datos** | Listado/detalle/alta/edición administrativa de médicos y asociaciones iniciales | Alta atómica con una o varias especialidades, código único, versión y datos permitidos; sin cuenta/consultorio automáticos |
| **3 — CU-10: estado** | Activación/inactivación profesional e integración con CU-03 | Cuenta/rol/ficha independientes; historial preservado y operaciones simultáneas con cuentas verificadas |
| **4 — CU-10: asociaciones** | Agregar, retirar y rehabilitar habilitaciones | FK histórica conservada, bloqueo por citas futuras, al menos una habilitación y concurrencia comprobados |
| **5 — Integración** | Recorridos completos y regresión de módulos anteriores | Suite PostgreSQL, HTTP/JSON, rutas, Pint/autoload, auditoría, análisis/DER/contexto y README sincronizados |

El diagrama global conserva su aplazamiento expreso; su ausencia no se presenta como documento actualizado. Cada fase implementada registra su evidencia real y análisis incremental, sin esperar al cierre final.

### Contratos administrativos previstos

Todos bajo `/api`, con sesión normal de **Administrador**, cookies/Sanctum, origen/CSRF, verificación de cuenta habilitada y revalidación del actor en el Service. Reutilizar `ExigirSesionNormal`, `ExigirRol` y `VerificarCuentaHabilitada`; no crear otra autenticación. Lecturas administrativas de estos CU no se abren a otros roles para anticipar búsqueda pública de turnos.

| Método / ruta futura | Entrada permitida | Respuesta |
| --- | --- | --- |
| `GET /especialidades` | `nombre`, `activa`, `page`, `per_page` | 200, `data` paginado y `meta`/`links` del patrón actual |
| `GET /especialidades/{id}` | ID BIGINT positivo | 200, `data` administrativa y versión |
| `POST /especialidades` | `nombre`, `descripcion` opcional/nullable | 201, `data`; ID del servidor, inicialmente activa |
| `PATCH /especialidades/{id}` | `nombre` y/o `descripcion`, `actualizado_en_original` | 200, `data` vigente |
| `PATCH /especialidades/{id}/estado` | `activa`, `actualizado_en_original` | 200, `data` vigente; no modifica citas |
| `GET /medicos` | `nombre`, `id_especialidad`, `activo`, `page`, `per_page` | 200, `data` paginado; filtro por especialidad habilitada, no solo histórica |
| `GET /medicos/{id}` | ID BIGINT positivo | 200, `data` administrativa, asociaciones y versión |
| `POST /medicos` | `registro_profesional`, `nombres`, `apellidos`, `telefono`, `correo_contacto`, `especialidades` (IDs distintos, mínimo uno) | 201, `data`; código/ID del servidor, activo, sin cuenta/consultorio asignados |
| `PATCH /medicos/{id}` | Campos administrativos anteriores salvo `especialidades`, más `actualizado_en_original` | 200, `data`; no cambia vínculo/código/consultorio/estado |
| `PATCH /medicos/{id}/estado` | `activo`, `actualizado_en_original` | 200, `data`; no modifica cuenta ni citas |
| `PUT /medicos/{id}/especialidades` | `especialidades` (conjunto completo deseado de IDs habilitados), `actualizado_en_original` | 200, detalle vigente; omitidos se deshabilitan, nunca se borran |

La última operación aparece en fase 4; fase 2 solo crea asociaciones iniciales. `PUT` expresa sustitución explícita del conjunto habilitado; no equivale a `sync()` destructivo. Relaciones retiradas siguen visibles como históricas en el detalle, con `habilitada: false` desde fase 4. Rehabilitar reutiliza el mismo par. Médico y especialidad activos/habilitados no garantizan turnos sin CU-11–CU-14.

Antes de fase 4, todos los pares existentes conservan su significado actual de asociación habilitada; el código de fase 2 no consultará una columna que todavía no existe. La migración y adaptación de consultas/Resources de fase 4 se entregarán juntas.

**Campos/formato:** nombre de especialidad 120; registro profesional 80; nombres/apellidos 120; teléfono 40; correo de contacto 254; descripción nullable con límite HTTP de 2000 caracteres, siguiendo el límite de texto administrativo existente. Rechazar vacíos efectivos/caracteres de control y campos extra, arrays con claves ajenas y IDs fuera de BIGINT positivo. IDs de salida como cadenas, sin pérdida en JavaScript. No inventar unicidad del registro profesional ni del correo de contacto: el esquema solo declara código y vínculo de usuario únicos. Código `MED-` con aleatoriedad criptográfica del servidor, sin `MAX(id)+1`, como el patrón de Pacientes. Correo normalizado conforme al contacto existente; no es correo de acceso.

**Normalización de especialidades:** recorte exterior U+0020, comparación `lower(btrim(nombre))` con PostgreSQL, coherente con `especialidades_nombre_normalizado_uq`. No quitar acentos ni colapsar espacios internos arbitrariamente. Conservar nombre legible recortado. Búsqueda literal parcial sin interpretar `%`/`_` como comodines; filtros combinados con AND, orden descendente por ID, página desde 1, tamaño por defecto 20 y máximo 100, siguiendo Pacientes. En médicos, `nombre` busca nombres/apellidos y su concatenación. No devolver listados sin límites.

**Proyecciones:** especialidad: ID, nombre, descripción, activa y versión; médico listado: ID/código/nombres/apellidos/activo/especialidades habilitadas; detalle: añade registro, teléfono, correo de contacto, `cuenta_vinculada` y versión, y desde fase 4 incluye todas las asociaciones con su habilitación. No serializar Usuario ni exponer credenciales, correo de acceso, datos clínicos, agenda o información de Pacientes. Especialidades existentes inactivas conservan identidad y asociaciones: su actividad sigue siendo independiente de la habilitación del par y no se deriva disponibilidad del alta administrativa.

### Retiro aprobado y persistencia prevista

- Retiro bloqueado si el par tiene citas **Programadas o Confirmadas con `inicio_at >= ahora`**. Capturar el instante UTC del servidor después de obtener el bloqueo y usar el mismo corte para toda la comprobación. Atendida/Cancelada/Inasistencia no bloquean por esta regla; no cambiar automáticamente estados de citas pasadas o en curso. Preservar su atención/documentación autorizada aunque posteriormente se retire la habilitación.
- El rechazo no modifica nada ni decide qué cita debe cancelarse. Recepción conserva CU-15/CU-16: reprogramar mantiene Médico/especialidad, por lo que posponer no resuelve el impedimento. No crear traslado a otro Médico ni cancelación administrativa.
- Mantener **al menos un par habilitado** del Médico al sustituir el conjunto, incluso si el profesional o alguna especialidad están inactivos; habilitación y actividad del catálogo no son el mismo estado. La inactivación completa del Médico corresponde a fase 3.
- Migración **aditiva futura de fase 4**: `medico_especialidad.habilitada BOOLEAN NOT NULL DEFAULT TRUE`, preservando todos los pares existentes y FK compuesta de citas. No modificar migración 000017 aplicada, no borrar pares ni crear un nuevo ID. Los pares existentes se consideran habilitados como antes; los cambios posteriores se auditan. Mantener relaciones históricas y consultas explícitas de habilitadas; revisar consumidores, seeders y pruebas sin ocultar relaciones anteriores.
- Migraciones **aditivas futuras de fases 1/2**: `especialidades.actualizado_en` y `medicos.actualizado_en`, TIMESTAMPTZ(6), NOT NULL, con inicialización de filas existentes. Recursos emiten UTC/seis decimales; escrituras comparan `actualizado_en_original` bajo bloqueo y avanzan versión de forma monotónica, también si cambia solo una asociación. No-op con versión vigente no avanza ni duplica evento; versión obsoleta se rechaza antes de decidir no-op.
- En fase 0 no se aplica ninguna migración: DER implementado conserva **28 entidades/239 atributos/43 relaciones**. Los tres atributos nuevos son diseño pendiente y actualizarán el DER al implementarse. No se imponen nuevas unicidades de identidad profesional sin decisión funcional.

### Capas, atomicidad y coordinación

Participantes **previstos**: `EspecialidadController`/`MedicoController`, Form Requests/Resources específicos en `src/presentation/dtos/`, `EspecialidadService`/`MedicoService`, nuevo `EspecialidadRepository` y ampliación del `MedicoRepository` existente; modelos Especialidad/Medico/MedicoEspecialidad y BitacoraRepository reutilizados. Rutas nuevas en `src/presentation/routes/`, registradas una sola vez mediante `routes/api.php`. No crear carpetas por CU ni repositorios genéricos.

El Service revalida sesión/rol en transacción con el patrón de AutenticacionService; Repository consulta/escribe, no decide permisos. Reutilizar coordinación de CU-03 antes de adquirir recursos del profesional: inspeccionar el orden efectivo de locks de cuentas al implementar y no invertirlo. Después, Médico → especialidades ordenadas por ID → pares; consultar impedimentos bajo el mismo bloqueo del Médico que deberán tomar reserva/reprogramación antes de crear obligaciones. Las operaciones sobre catálogo no deben adquirir luego locks de médicos y crear un ciclo. Cuando intervengan varios registros, ordenar claves; bloquear la fila padre cubre también un par aún inexistente. El alta inserta médico, pares y evento en una transacción. Cada cambio confirma datos/versión/bitácora conjuntamente; fallo de auditoría revierte todo.

La garantía de concurrencia frente a reservas **requiere que CU-14/CU-15 participen del protocolo al implementarse**. En este módulo se prueba con dos procesos/conexiones PostgreSQL que sigan ese protocolo; no declarar implementados los endpoints de citas por esa prueba. Catálogo y par habilitados deben revalidarse al guardar la futura reserva, no solo al buscar disponibilidad. La inactivación profesional conserva cuenta, sesiones, bloqueos y rol; su reactivación debe respetar la identidad/vínculo y los cambios de rol de CU-03, sin devolver permisos clínicos automáticamente a un antiguo Médico.

Auditoría prevista: `ESPECIALIDAD_CREADA`, `ESPECIALIDAD_ACTUALIZADA`, `ESPECIALIDAD_ESTADO_CAMBIADO`, `MEDICO_CREADO`, `MEDICO_ACTUALIZADO`, `MEDICO_ESTADO_CAMBIADO`, `MEDICO_ESPECIALIDADES_CAMBIADAS`. Registrar actor real, correlación, referencias, nombres de campos y cambios de habilitación; sin copias de contactos, contraseñas, documentos ni contenido clínico. El conflicto no deja evento de éxito.

### Errores y criterios verificables

Mantener `RespuestaError`/`AppError`; agregar traducciones explícitas cuando cada operación se implemente. Códigos futuros 409: `ESPECIALIDAD_NOMBRE_DUPLICADO`, `ESPECIALIDAD_EDICION_OBSOLETA`, `MEDICO_CONFLICTO_UNICIDAD`, `MEDICO_EDICION_OBSOLETA`, `MEDICO_ESPECIALIDAD_CON_CITAS_FUTURAS`, `MEDICO_SIN_ESPECIALIDADES_HABILITADAS`, `MEDICO_VINCULO_INCOMPATIBLE`. Relaciones inválidas en datos: 422 con errores de campo seguros. Recurso objetivo ausente: 404. Mantener 401/403/419 y 429 cuando corresponda al mecanismo existente; no convertir errores SQL arbitrarios en conflictos ni cambiar el contrato de errores de otros módulos. No hay umbrales nuevos de rate limit aprobados por esta fase.

Pruebas por fase e integración final:

1. Sesión ausente/restringida/revocada, cuenta desactivada, cuatro roles, origen/CSRF, campos extra, IDs, formatos y paginación.
2. Duplicado normalizado y carrera de dos altas/renombrados; SQL inesperado conserva 500 seguro; fallo de auditoría revierte el cambio.
3. Alta de médico con conjunto válido; código único, sin relaciones parciales, sin crear cuenta/horario/consultorio; edición de contacto no cambia credenciales.
4. Dos borradores sobre datos/estado/asociaciones: uno guarda y otro recibe 409; no-op no duplica eventos; timestamp monotónico.
5. Inactivar/reactivar profesional frente a cambios de rol/vínculo de CU-03: no resucitar permisos ni alterar cuentas; obligaciones anteriores conservadas.
6. Retiro con citas futuras Programadas/Confirmadas bloqueado; estados finales y fechas anteriores no bloquean por esta regla; borde temporal, par correcto y otro par del mismo Médico diferenciados. Mantener FK/historial y mínimo un par habilitado. Reactivación del par no duplica identidad.
7. Retiro vs reserva coordinada en ambos órdenes y rollback; dos cambios de asociaciones, incluida última habilitación, no dejan datos parciales. Base aislada PostgreSQL, nunca SQLite como sustituto de las carreras.
8. Regresión de Usuarios/Pacientes y consumidores de relaciones, rutas sin duplicados, Resources sin secretos, contratos/error codes y actualización progresiva de análisis/DER.

**Comprobación de fase 0:** código y documentación inspeccionados; PHP CLI 8.5.11 y Laravel 13.37.0 comprobados. Esta entrega documental no ejecuta migraciones, seeders, envíos de correo ni pruebas funcionales que todavía no existen. Próxima entrega: fase 1, CU-09.

## Cuentas de pruebas para la demostración (Railway o local)

`CuentasPruebaSeeder` es una carga manual independiente: un Médico con cuenta/ficha por **cada especialidad activa existente**, dos Recepcionistas y diez Pacientes con cuenta vinculada. No crea Administradores ni modifica el existente. Requiere los catálogos ya cargados; no se incorpora a `DatabaseSeeder` ni a los despliegues automáticos.

En las variables privadas del backend configurar:

```dotenv
CLINICA_CUENTAS_PRUEBA_HABILITADAS=true
CLINICA_DEMO_PASSWORD=
```

Completar la segunda variable con la contraseña común elegida de **mínimo 12 caracteres**; el ejemplo la deja vacía deliberadamente. Aplicar el despliegue/configuración antes de ejecutar desde la terminal del backend:

```bash
php artisan db:seed --class='Clinica\data\database\seeders\CuentasPruebaSeeder' --force
```

Si aún faltan catálogos, ejecutar primero `php artisan db:seed --class='Clinica\data\database\seeders\DatabaseSeeder' --force`. No requiere borrar datos ni repetir las migraciones. Utilizar **CuentasPruebaSeeder**, no el antiguo `DemostracionSeeder`/`composer seed:demo`, que contiene otro conjunto local con Administrador y escenarios clínicos.

Correos de acceso (el comando imprime la lista exacta con cada especialidad):

| Rol | Correo |
| --- | --- |
| Médico | `prueba.medico.e<ID_ESPECIALIDAD>@example.test` |
| Recepción | `prueba.recepcion01@example.test` y `prueba.recepcion02@example.test` |
| Paciente | `prueba.paciente01@example.test` hasta `prueba.paciente10@example.test` |

Todas las cuentas nuevas usan la contraseña común configurada, con un hash individual. Quedan Activas, sin cambio obligatorio de clave y con verificación **simulada solo para pruebas**: no se envían correos ni se acredita posesión de esos buzones ficticios. Los registros normales conservan las verificaciones de seguridad. El Administrador conserva su contraseña independiente.

Reejecutar conserva cuentas, contraseñas, correos modificados, estados, bloqueos y fichas existentes; añade los médicos de nuevas especialidades activas. Cambiar `CLINICA_DEMO_PASSWORD` **no restablece** las cuentas ya creadas. Un correo ajeno o una ficha con vínculo incompatible detiene y revierte toda la carga. Cada cuenta nueva deja un evento SISTEMA `SEED_CUENTA_PRUEBA` sin contraseña/hash, con marca `SIMULADA_PRUEBAS`. Una transacción y el bloqueo compartido con los demás seeders protegen las reejecuciones simultáneas.

No carga citas ni documentos clínicos. Los Médicos quedan sin consultorio/horarios asignados; su carga no acredita disponibilidad para reservar. Los datos ficticios aparecerán en los listados y futuros reportes del entorno. Después de la carga puede retirarse la contraseña del entorno y dejar `CLINICA_CUENTAS_PRUEBA_HABILITADAS=false`: las cuentas persisten. Utilizarlo únicamente en el entorno destinado a demostración.

Verificación local 2026-10-21: `CuentasPruebaSeederTest` y regresión `PoblacionDatosTest`, **20 pruebas / 228 aserciones correctas** en PostgreSQL aislado con rollback; incluye cobertura dinámica de especialidades, preservación del Administrador, repetición, colisiones, contraseña común y ausencia de correo. No se ejecutó la carga en la base habitual ni en Railway.

## Requisito de PHP para despliegue

El manifiesto y el lock requieren **PHP `^8.4.1`** (8.4.1 o superior dentro de PHP 8). Las dependencias Symfony bloqueadas necesitan al menos esa versión; PHP 8.3 no es compatible con el lock actual. Configurar el constructor de Railway para respetar este requisito y comprobar la versión seleccionada en el registro del nuevo build.

Corrección del 2026-10-21: sincronizados `composer.json` y `composer.lock` sin cambiar versiones de paquetes. `composer validate --strict` y `composer check-platform-reqs --lock` correctos localmente con PHP 8.5.11. La ejecución del nuevo build en Railway queda pendiente; no usar `--ignore-platform-reqs` para eludir el requisito.

## Estado vigente de integración

Pacientes y perfil: backend fases 1–6 y plan frontend fases 1–6 completados en su alcance administrativo/perfil. Integración local real de Chrome/React/Laravel/PostgreSQL verificada con datos ficticios y correo capturado, según `../frontend/README.md` y contexto §17.47. Regresión focalizada de seis clases del módulo: **134 pruebas/1272 aserciones correctas**; cliente **377 pruebas/22 archivos**, build correcto con aviso conocido de bundle ~503 kB. Consulta de citas anteriores depende de CU-21; no se declara RF02 íntegro. Entrega Brevo real/producción y diagrama global expresamente aplazado conservan sus pendientes. La cronología siguiente registra el avance histórico de cada fase.

## Estado de esta fase

**Usuarios y autenticación — fase 8, backend cerrado:** CU-01–CU-06 implementados y revisados de forma integrada, incluidos acceso normal/restringido, administración de cuentas, ambos escenarios de registro/vinculación y credenciales. La emisión de Recepción cubre la parte de CU-07 requerida por CU-04. Análisis vigente en [`../docs/ai/analisis-clases.md`](../docs/ai/analisis-clases.md) y cierre en contexto §17.26/último apartado de este README. **`diagrama-clases.md` aplazado expresamente por el usuario.** React/AUT-21, entrega real de Brevo y CRUD administrativo restante de CU-07 siguen pendientes. Brevo HTTP está integrado y configurado privadamente; sus pruebas usan red simulada.

La población posterior mediante seeders está aplicada en desarrollo: catálogos/consultorios, conjunto ficticio de demostración y Administrador inicial activo por excepción de instalación. Su estado y comandos se describen en el último apartado; los estados anteriores son registros históricos.

Preparación local inicial verificada el **18 de octubre de 2026**. Las **fases 0–7 de datos**, completadas el **19 de octubre de 2026**, se registran al final de este documento y en `../docs/ai/diagrama-entidad-relacion.md`, §6.6–§6.13. Desarrollo contiene las **28 tablas de dominio, 239 columnas y 43 FK** del DER, además de `migrations` y dos exclusiones de citas. **Ningún CU-01 a CU-30 está implementado por este trabajo.**

| Componente | Versión comprobada | Preparación realizada |
| --- | --- | --- |
| PHP CLI | 8.5.11 | Instalación existente en `C:\php`; extensiones habilitadas |
| Composer | 2.10.3 | Instalado en el perfil del usuario y agregado a su `PATH` |
| Esqueleto `laravel/laravel` | 13.11.0 | Descargado a una carpeta temporal e integrado selectivamente |
| Laravel Framework | 13.37.0 | Instalado y fijado en `composer.lock` |
| Laravel Sanctum | 4.3.3 | Instalado; cookies, CSRF y middleware stateful preparados |
| PostgreSQL | 18.6 | Instalación existente; servicio comprobado en ejecución |
| PHPUnit | 12.5.40 | Pruebas de infraestructura |
| Laravel Pint | 1.32.1 | Comprobación de estilo |

Se instalaron **105 paquetes Composer**, incluidas dependencias de desarrollo. `vendor/` se genera localmente y está excluido de Git.

## Entorno local

- Backend: `http://localhost:8000`.
- Frontend futuro: `http://localhost:5173`.
- PostgreSQL: `localhost:5432`, base `clinica_vida_salud`.
- Zona horaria: `America/La_Paz`; locale de la aplicación: `es`.
- PHP habilitado: `curl`, `fileinfo`, `mbstring`, `openssl`, `pdo_pgsql` y `zip`; directorio de extensiones `C:/php/ext`.
- La validación HTTPS del instalador y de Composer funcionó sin desactivar TLS ni la comprobación de certificados.

### Arranque habitual

Desde `backend/`, en una terminal nueva:

```powershell
composer install
php artisan serve --host=localhost --port=8000
```

También se puede usar `composer dev`. Si la terminal todavía no reconoce Composer después de actualizar el `PATH`, abrir otra o invocar:

```powershell
php "$env:LOCALAPPDATA\Programs\Composer\bin\composer.phar" --version
```

La comprobación de arranque está en `http://localhost:8000/up`. La raíz `/` no tiene una interfaz funcional; React se preparará por separado.

El archivo `.env` local ya contiene la clave de aplicación generada y la credencial técnica de PostgreSQL. **No regenerar la clave como paso habitual de arranque.** En otra instalación, copiar `.env.example`, completar sus credenciales locales y ejecutar `php artisan key:generate` una sola vez. `composer setup` agrupa instalación, copia inicial del entorno y generación de clave; está destinado a una preparación inicial.

En una instalación nueva, ejecutar después `php artisan migrate` y `composer seed` antes de atender peticiones: el driver de sesión actual requiere la tabla `sessions`. En el entorno local existente esas migraciones y catálogos ya están aplicados.

### Base de datos y secretos

Se comprobó primero la existencia de la base. Al no existir, se creó `clinica_vida_salud` en UTF-8, con propietario técnico `clinica_vida_salud_app`. El rol tiene login, sin privilegios de superusuario, creación de bases, creación de roles ni replicación. No es un Usuario del dominio clínico.

La contraseña del rol técnico se generó aleatoriamente y quedó únicamente en `.env`. El acceso administrativo utilizado para preparar la base se obtuvo del archivo local `%APPDATA%\postgresql\pgpass.conf`, protegido para el usuario de Windows. No se incorporaron credenciales administrativas a la configuración de Laravel.

**Resultado:** conexión PDO y conexión desde Laravel correctas, **cero tablas de aplicación**, cero migraciones propias. Se compararon nombre, propietario, codificación y locale del catálogo de las otras bases antes y después, sin diferencias. No se modificaron otras bases.

`.env`, variantes privadas del entorno, `vendor/`, cachés generadas, sesiones y logs están excluidos de Git. `.env.example` conserva vacíos `APP_KEY` y `DB_PASSWORD`.

## Integración con la arquitectura acordada

- `composer.json` registra `Clinica\` → `src/`, con las cuatro capas en minúsculas y exige `ext-pdo`/`ext-pdo_pgsql`.
- `bootstrap/app.php` registra las entradas HTTP de Laravel y habilita `statefulApi()`.
- `routes/api.php` carga explícitamente `src/presentation/routes/auth.php`, `personal.php`, `usuarios.php` y `pacientes.php`, donde se declaran las rutas funcionales. Laravel aplica el prefijo `/api` y el grupo `api` desde el registro de `bootstrap/app.php`.
- `app/Providers/AppServiceProvider.php` registra `src/data/database/migrations/` mediante `loadMigrationsFrom`.
- Se conservaron los 19 archivos originales de `backend/`, incluidos `AGENTS.md`, `prueba.php` y los archivos de `src/`; se compararon sus hashes SHA-256.

Los archivos de `app/`, `bootstrap/`, `config/` y `routes/` son infraestructura del framework. Al desarrollar los CU, presentación adaptará HTTP, servicios aplicarán reglas y transacciones, repositorios accederán a Eloquent y modelos representarán las entidades aprobadas. Esta fase no agrega esas clases de negocio.

### Organización modular de rutas

```text
routes/
├── api.php                       # Carga central: auth → personal → usuarios → pacientes
├── web.php
└── console.php
src/presentation/routes/
├── auth.php                      # Acceso, contraseñas, registro y vinculación de Paciente
├── personal.php                  # Alta, reenvío y aceptación de invitaciones
├── usuarios.php                  # Consulta administrativa, estado, rol y temporal
└── pacientes.php                 # Emisión de código presencial; resto de CU-07 posterior
```

`routes/api.php` incluye cada archivo una sola vez, sin añadir otro prefijo `/api` ni repetir el grupo de middleware `api`. Cada archivo funcional conserva sus grupos, middlewares y restricciones. La aceptación pública de invitaciones está en `personal.php`, con la misma URL `/api/auth/invitaciones/aceptar`, fuera del grupo exclusivo del Administrador. El antiguo `src/presentation/routes/api.php` fue sustituido inicialmente por tres archivos; fase 7B agrega `pacientes.php` exclusivamente para la emisión autorizada de código de Recepción.

La comparación histórica antes/después del traslado verificó 13 declaraciones sin duplicados: cuatro en auth, tres en personal, cuatro en usuarios y dos de infraestructura (`/up` y `/sanctum/csrf-cookie`). Se preservaron métodos (incluido HEAD), URL, nombres, dominios, prefijos, acciones, orden de registro, middleware declarado/resuelto, parámetros, restricciones y valores predeterminados. También se comprobaron hashes idénticos de 106 archivos de lógica/infraestructura ajenos al traslado. Evidencia de regresión en contexto §17.20. La fase 5 añadió dos rutas de recuperación a auth.php, alcanzando entonces 15 declaraciones; el registro posterior se documenta en los cierres de cada fase.

Regresión posterior a la reorganización: **158 pruebas y 2062 aserciones correctas** (50,39 s) de autenticación, cambio de contraseña, invitaciones, cuentas y concurrencia; PostgreSQL aislado y correo simulado. Cambio exclusivamente estructural, sin clases, dependencias o funcionalidades nuevas.

El esqueleto se obtuvo con `--no-install --no-scripts`. Se integraron únicamente los archivos necesarios; no se copiaron sus instrucciones de agentes, modelo `User`, factories, seeders, migraciones de usuarios/cache/jobs, aplicación Vite ni pantalla Blade inicial. Se retiraron los scripts que creaban SQLite o ejecutaban migraciones y npm. **No se ejecutó `migrate`, `install:api`, `db:seed` ni publicación de migraciones de Sanctum.**

## Sanctum, CORS y sesiones

- CORS permite el origen exacto `http://localhost:5173`, con credenciales. Rutas cubiertas: `api/*` y `sanctum/csrf-cookie`.
- Dominios stateful: `localhost:5173,localhost:8000`.
- `GET /sanctum/csrf-cookie` emite la cookie de sesión y `XSRF-TOKEN`; esta última es legible por JavaScript para el mecanismo CSRF de Laravel.
- Sesión `clinica_vida_salud_session`: cookie HttpOnly, SameSite=Lax y host-only (`SESSION_DOMAIN=null`). `Secure=false` corresponde al HTTP local aprobado; HTTPS y `Secure=true` deberán configurarse para producción.
- En la instalación inicial las sesiones eran temporales en archivo; **desde fase 2 usan la tabla nativa `sessions`, con contenido cifrado**. Caché en archivo, cola síncrona y mailer `array` en memoria se conservan.
- El disco local apunta a `storage/app/private`, sin rutas automáticas de entrega/subida de archivos ni enlace público creado.

**Antecedente de fase 2 de datos:** se registraron `Usuario`, guard `web`, proveedor Eloquent `usuarios` y Sanctum; se comprobaron sesiones anónimas y vinculadas. La implementación posterior de **fase 1 de autenticación**, al final de este README, integra AuthenticateSession, login/me/logout, bloqueo y controles transversales. La recuperación, emisión de tokens y administración de cuentas permanecen en sus fases funcionales; no hay un broker paralelo de recuperación.

El futuro cliente Axios deberá usar credenciales, obtener primero la cookie CSRF y enviar la cabecera XSRF correspondiente. No se ha creado ni probado todavía ese frontend.

## Comandos de preparación ejecutados

Las rutas temporales de esta máquina se agrupan en:

```powershell
$stage = "$env:LOCALAPPDATA\Temp\opencode\clinica-laravel13-setup"
$composer = "$env:LOCALAPPDATA\Programs\Composer\bin\composer.phar"
```

1. Se inspeccionaron `git status --short`, herramientas PHP/Composer, servicio PostgreSQL, estructura del backend y `C:\php\php.ini`.
2. Se respaldó `php.ini` en `%LOCALAPPDATA%\Temp\opencode\clinica-php-ini-before-laravel13.ini`; se editaron extensiones, su directorio y zona horaria. Se ejecutó `php -m` para comprobarlas.
3. Se descargó el instalador de `getcomposer.org/installer` y su firma desde `composer.github.io/installer.sig`; se verificó SHA-384 antes de ejecutarlo:

```powershell
php "$stage\composer-setup.php" --install-dir="$env:LOCALAPPDATA\Programs\Composer\bin" --filename=composer.phar
php $composer --version
php $composer create-project 'laravel/laravel:^13.0' "$stage\skeleton" --no-install --no-scripts --no-interaction --prefer-dist
```

4. Se copió selectivamente la infraestructura, comprobando que cada destino estuviera libre. Se adaptaron scripts, autoload y configuración; se instaló desde el `composer.json` ya controlado:

```powershell
php $composer update --no-scripts --no-interaction --prefer-dist
php artisan package:discover --ansi
php artisan key:generate --ansi
php artisan vendor:publish --tag=sanctum-config --no-interaction
php $composer dump-autoload --strict-psr --no-interaction
php $composer update --lock --no-scripts --no-interaction
```

5. Se abrió el auxiliar local `guardar-pgpass.ps1` en PowerShell para la entrada oculta de la contraseña administrativa. Se verificó acceso con `psql -X -w -h localhost -p 5432 -U postgres -d postgres`. Los auxiliares temporales, sin contraseñas incrustadas, prepararon la base y verificaron Laravel:

```powershell
php "$stage\preparar-postgres.php" (Get-Location).Path
php "$stage\verificar-laravel.php" (Get-Location).Path
```

`preparar-postgres.php` consultó el catálogo y ejecutó únicamente `CREATE ROLE` y `CREATE DATABASE` al no existir los objetos correspondientes. No ejecutó DDL de tablas. Estos auxiliares son herramientas locales de esta preparación, no comandos de negocio ni parte de la aplicación versionada.

## Verificaciones y resultados finales

```powershell
php $composer diagnose
php $composer validate --strict
php $composer check-platform-reqs
php $composer audit
php artisan --version
php artisan about --only=environment,cache,drivers
php artisan route:list -v
php artisan test
php vendor/bin/pint --test app bootstrap/app.php bootstrap/providers.php config public routes src tests
git diff --check
```

| Verificación | Resultado |
| --- | --- |
| Composer: diagnóstico, manifiesto, lock y requisitos | Correctos; HTTPS operativo y requisitos de plataforma satisfechos |
| Auditoría Composer | Sin avisos de vulnerabilidades conocidos al comprobar |
| Autoload PSR-4 estricto | Correcto; prefijo `Clinica\` registrado |
| Migraciones | Ruta personalizada registrada; cero archivos de migración; ninguna ejecutada |
| Laravel → PostgreSQL | Conexión correcta a `clinica_vida_salud` con su rol técnico; cero tablas |
| PHPUnit | **4 pruebas, 23 aserciones, todas correctas** |
| Sintaxis PHP | **23 archivos**, sin errores, mediante `php -l` |
| Estilo Pint | **22 archivos de fuente**, correcto; cachés generadas excluidas de la comprobación |
| HTTP real, servidor Artisan | `/up`: **200**; cookie CSRF: **204**; preflight: **204** |
| Cookies/CORS | Cookie de sesión HttpOnly y XSRF presentes; credenciales y origen aprobado correctos; origen ajeno no autorizado por CORS |
| Preservación | 19 archivos originales con SHA-256 idéntico |
| Exclusiones Git | Entorno local, dependencias, caché y logs ignorados |

Las pruebas de `tests/Feature/InfrastructureTest.php` cubren salud, cookies, CORS y CSRF. El middleware real devuelve **419** ante un POST stateful sin token y **204** con token válido en una **ruta creada únicamente durante la prueba**. La aplicación normal solo registra `/up` y `/sanctum/csrf-cookie`.

En la preparación inicial, `phpunit.xml` reservó el nombre `clinica_vida_salud_test`, pero todavía no existían su base ni su rol; las cuatro pruebas iniciales no accedían a PostgreSQL. **La fase 0 descrita a continuación completa ese entorno y agrega pruebas reales de conexión y DDL.**

## Próximos pasos pendientes — registro de la preparación inicial

Este apartado conserva los pendientes de aquella preparación; el estado vigente de Usuarios/autenticación es el cierre de fase 8 al final del README.

- Administrador de instalación creado y comprobado por excepción aprobada; implementar las invitaciones/verificación ordinarias para las cuentas posteriores del personal.
- Iniciar los flujos funcionales sobre la persistencia preparada, priorizando usuarios y autenticación.
- Implementar autenticación, autorización y revocación de sesiones conforme a los CU aprobados.
- Preparar React/Axios, Brevo y cualquier infraestructura persistente cuando se aborde su alcance.
- Definir el programador de tareas y sus procesos cuando corresponda a CU-28.
- Configurar y verificar producción por separado.

Se actualizó `../docs/ai/contexto-maestro.md` con la preparación inicial. Actualmente el DER está disponible y sus decisiones físicas de fase 0 figuran en §6.6. `analisis-clases.md` y `diagrama-clases.md` siguen pendientes de elaboración progresiva al desarrollar los CU y módulos. No se declara cerrado ningún CU ni módulo.

## Fase 0 de datos — 2026-10-19

Registro del estado al cerrar fase 0; la implementación posterior de catálogos y configuración figura en el apartado de fase 1.

### Diseño e integración

- El DER registra los **28 nombres físicos de tablas**, criterios comunes de tipos, identificadores, FK, índices y timestamps, y los tipos/defaults/restricciones del primer bloque de cinco entidades. El diccionario lógico conserva las **28 entidades, 239 atributos y 43 relaciones**.
- Las decisiones concretas de longitudes y concurrencia de los bloques restantes se resolverán antes de sus migraciones. Continúan pendientes el retiro de habilitación de un par Médico–Especialidad y cualquier procedimiento posterior a publicación de laboratorio.
- Se comprobó el registro existente de migraciones en `src/data/database/migrations/` y el autoload `Clinica\` → `src/` con Composer estricto.
- `config/database.php` fija la zona de la conexión PostgreSQL a `UTC`; la aplicación y las reglas locales conservan `America/La_Paz`. Los instantes se enviarán con zona y precisión, según el DER.
- Se añadió el punto de entrada `src/data/database/seeders/DatabaseSeeder.php`. Aún no carga registros; coordinará los seeders de las fases siguientes.

Comandos del proyecto, desde `backend/`:

```powershell
php artisan make:migration <nombre> --path=src/data/database/migrations
php artisan migrate
composer seed
```

`composer seed` ejecuta `php artisan db:seed --class="Clinica\data\database\seeders\DatabaseSeeder"`. Laravel conserva su namespace predeterminado para `db:seed` sin clase; utilizar la invocación configurada. Las migraciones de negocio se crearán a partir de fase 1.

### PostgreSQL aislado para pruebas

Se creó `clinica_vida_salud_test` en UTF-8 con propietario `clinica_vida_salud_test`, independiente de `clinica_vida_salud_app`. El rol no tiene superusuario, creación de bases/roles, replicación, bypass RLS ni membresías; el acceso público a la base está revocado. La contraseña y una clave de aplicación independientes se generaron aleatoriamente y quedaron únicamente en `.env.testing`, excluido de Git.

Para reproducir la preparación en **otra instalación**:

1. Comprobar primero si ya existen la base y el rol; conservar cualquier entorno existente y verificar propietario/credenciales antes de cambiarlo.
2. Con una sesión administrativa de `psql`, crear los objetos que falten. Establecer la contraseña con `\password`, que solicita entrada oculta:

```sql
CREATE ROLE clinica_vida_salud_test LOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
\password clinica_vida_salud_test
CREATE DATABASE clinica_vida_salud_test OWNER clinica_vida_salud_test ENCODING 'UTF8' TEMPLATE template0;
REVOKE ALL ON DATABASE clinica_vida_salud_test FROM PUBLIC;
\connect clinica_vida_salud_test
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO clinica_vida_salud_test;
```

3. Copiar `.env.testing.example` a `.env.testing`, completar la credencial de pruebas y generar su clave una sola vez:

```powershell
Copy-Item .env.testing.example .env.testing
php artisan config:clear
php artisan key:generate --env=testing
```

No repetir la copia o generación de clave sobre el entorno local ya preparado. El ejemplo versionado mantiene vacíos `APP_KEY` y `DB_PASSWORD`.

### Verificación y ejecución de pruebas

```powershell
composer test -- --compact
composer seed -- --env=testing
```

- PHPUnit fuerza el entorno `testing`, PostgreSQL y el nombre de la base aislada; obtiene usuario/contraseña de `.env.testing`.
- `Tests\TestCase` rechaza configuración cacheada o una conexión con base, usuario o URL incompatibles **antes de ejecutar los hooks de pruebas de Laravel**. `composer test` limpia previamente la caché de configuración.
- `tests/Feature/DatabaseInfrastructureTest.php` verifica conexión real, propietario, privilegios mínimos, zona UTC, descubrimiento de la ruta de migraciones, ejecución Artisan y resolución del seeder raíz.
- La única migración auxiliar está en `tests/Fixtures/database/migrations/`; la prueba registra esa ruta temporalmente. Crea una tabla auxiliar, registra la migración y escribe un instante con zona y microsegundos **dentro de una transacción que se revierte**. Las futuras migraciones del dominio permanecerán en `src/`.
- Resultado: **6 pruebas y 39 aserciones correctas**; la comprobación negativa manual confirmó que el usuario de desarrollo es rechazado. Pint y autoload PSR-4 estrictos correctos.
- La base de desarrollo conserva **cero tablas**; la base de pruebas queda también sin tablas persistidas tras verificar el DDL. El catálogo de las otras bases se conservó.

La preparación local de la base se realizó con un auxiliar temporal sin credenciales incrustadas; la comprobación automatizada reproducible está en los tests del repositorio. No se han verificado todavía las carreras de reservas, activaciones, versiones o publicaciones: corresponden al desarrollo de sus fases y CU.

## Fase 1 de datos — 2026-10-19

### Implementación y estado de PostgreSQL

- **Cinco migraciones**, en `src/data/database/migrations/`, crean `roles`, `controles_intentos`, `consultorios`, `especialidades` y `configuracion_clinica`, con las **22 columnas** del primer bloque del DER.
- **Cinco modelos**, en `src/data/models/`, configuran los nombres de tabla/PK, asignación masiva limitada, casts y timestamps propios. No se anticipan relaciones con modelos de otras fases.
- Las PK, unicidades y CHECK tienen nombres explícitos; la especialidad usa un índice único sobre `lower(btrim(nombre))`. Se conservan tildes y presentación. Configuración admite solo ID 1, zona America/La_Paz y duración positiva.
- El seeder raíz coordina transaccionalmente `RolSeeder`, `EspecialidadSeeder` y `ConfiguracionClinicaSeeder`. La carga inserta los registros ausentes y conserva los existentes, también si están inactivos o tienen configuración modificada.

Se inspeccionó la base de desarrollo antes de aplicar las migraciones: estaba vacía. Se ejecutaron `php artisan migrate --no-interaction` y `composer seed -- --no-interaction`. Las **cinco migraciones quedaron aplicadas en el lote 1**, junto con el repositorio de infraestructura `migrations`.

| Tabla de dominio | Filas locales |
|---|---:|
| `roles` | 4 |
| `especialidades` | 3 |
| `configuracion_clinica` | 1 |
| `consultorios` | 0 |
| `controles_intentos` | 0 |

Los catálogos contienen exclusivamente los roles y especialidades iniciales aprobados. La configuración inicia con Clínica Vida Salud, duración 15 minutos y dirección NULL. Los IDs de roles/especialidades se generan en PostgreSQL; no constituyen constantes funcionales. Repetir la carga puede avanzar las secuencias, pero no cambia los IDs existentes ni duplica filas.

### Pruebas y reproducibilidad

```powershell
composer test -- --compact
php artisan migrate:status
composer seed
```

Resultado: **33 pruebas, 166 aserciones correctas**, incluyendo `tests/Feature/CatalogosInicialesTest.php`. Se verificaron restricciones mediante SQL directo, defaults, normalización con tildes, modelos/timestamps, idempotencia que preserva cambios previos y rollback completo de la carga ante un fallo provocado. También se comprobó la reversibilidad y reaplicación de migraciones en el entorno aislado.

Las pruebas de catálogos y la prueba de infraestructura ejecutan DDL dentro de transacciones revertidas; la base de pruebas queda sin tablas persistidas. No se ejecutaron `migrate:fresh`, borrados ni rollback sobre la base de desarrollo. La reejecución del seeder sobre desarrollo conservó las filas e identificadores.

Autoload PSR-4 estricto, estilo Pint y comparación del DER correctos. La documentación mantiene las 28 entidades, 239 atributos y 43 relaciones del diseño global; este primer bloque implementa cinco entidades sin FK. Los detalles de restricciones y clases están en el DER §6.7 y el avance en `contexto-maestro.md` §17.4.

Esta fase completa el bloque de persistencia inicial. Las operaciones administrativas, autenticación, permisos, APIs y reglas transaccionales de los CU se desarrollarán en sus módulos. `analisis-clases.md` y `diagrama-clases.md` siguen pendientes de elaboración al completar CU y módulos.

## Fase 2 de datos — 2026-10-19

Se implementaron cuatro migraciones, aplicadas como **lote 2**, y los modelos `Usuario`, `TokenAcceso` y `Bitacora`. `Sesion` usa directamente el manejador nativo de Laravel. El modelo `Rol` incorpora su relación con usuarios. El detalle físico y sus límites funcionales se registran en el DER §6.8.

| Tabla | Persistencia implementada |
|---|---|
| `usuarios` | Correo único normalizado, rol con FK, tres estados, hash opcional solo en Pendiente, cuenta Activa con hash/verificación, contadores y timestamps propios |
| `sessions` | Seis columnas nativas, Usuario opcional, índices por Usuario/actividad y FK restrictiva |
| `tokens_acceso` | Dos propósitos, hash único, fechas de emisión/expiración/consumo/invalidación y FK de Usuario |
| `bitacora` | Dos actores separados, evento anterior, JSONB de tipo objeto, INET/UUID y triggers que rechazan UPDATE, DELETE, TRUNCATE y referencias adelantadas/cíclicas |

Las cuatro tablas se incorporaron sin carga inicial. La base local tiene ahora **9 tablas de dominio, 72 columnas y 6 FK**, más `migrations`. Los catálogos/configuración previos se compararon antes y después, sin cambios. No se crearon cuentas ni eventos ficticios en desarrollo.

### Sesión y proveedor de usuarios

`config/auth.php` mapea el proveedor Eloquent al modelo de `src/`, con `id_usuario` y `password_hash`; Sanctum consulta el guard `web`. La asignación masiva protege IDs, roles, hashes y campos de control. Las contraseñas y hashes de tokens se ocultan en la serialización; las respuestas públicas futuras tendrán sus Resources/DTO explícitos.

El driver de sesión local, su default y `.env.example` usan **database**, conservando el cifrado. Se comprobó con el kernel HTTP local que CSRF devuelve 204 y persiste una sesión anónima cifrada; solo se eliminó la sesión auxiliar creada para esa comprobación. PHPUnit continúa con `array` en general y activa `database` en las pruebas correspondientes.

Crear las tablas y configurar el proveedor no completa el login: todavía faltan validación de estados/bloqueo, autorización, verificación de nombres y vínculos por rol, consumo único/reemisión de tokens, envío Brevo, `AuthenticateSession` y revocación automática transaccional. La emisión real de eventos validará tipos, autor y listas permitidas de metadatos seguros; un JSON de tipo objeto no demuestra por sí solo ausencia de secretos o datos clínicos.

### Verificaciones

```powershell
composer test -- --compact
php artisan migrate:status
```

Resultado: **68 pruebas y 335 aserciones correctas**. `UsuariosAuditoriaTest` comprueba esquema, proveedor, normalización, protección de campos/secretos, relaciones, FK, hash de token único, actores y correlación, triggers de inmutabilidad y soporte nativo de sesiones cifradas, expiración y eliminación por Usuario. Las pruebas de migraciones anteriores se adaptaron al conjunto disponible, siempre dentro de transacciones de la base aislada.

Reversión/reaplicación de migraciones y funciones probadas solo en el entorno de pruebas, que termina sin tablas persistidas. Autoload PSR-4 estricto, Pint y sintaxis PHP correctos. El DER conserva 28 entidades, 239 atributos y 43 relaciones; se actualizaron este README, DER §6.8 y `contexto-maestro.md` §17.5. No se completó un CU ni módulo funcional y el primer Administrador continúa pendiente de su procedimiento seguro.

## Fase 3 de datos — 2026-10-19

Se incorporaron **seis migraciones** (`000010`–`000015`, lote 3) y seis modelos en `src/data/` para Paciente, ContactoEmergencia, PreferenciaPaciente, CodigoActivacionPaciente, RegistroPacientePendiente y VerificacionCorreo. Usuario incorpora sus relaciones con ficha, códigos emitidos y verificaciones. El diseño físico detallado está en el DER §6.9.

| Persistencia | Garantía implementada |
|---|---|
| Paciente | CI único cuando exista, varios NULL permitidos; código único y cuenta opcional única; actividad independiente de la cuenta |
| Contacto/preferencia | Cero o una fila de cada tipo por ficha; preferencias explícitas sin opciones funcionales predeterminadas |
| Código de Recepción | Hash único, FK a ficha y emisor, contador 0–5, fechas explícitas y vigencia básica |
| Registro pendiente | Dos tipos compatibles con sus FK, datos/hash requeridos mientras esté abierto y cuatro barreras de exclusividad |
| Verificación | XOR compatible con ActivacionPaciente/CambioCorreo, contador 0–5 y referencias a proceso o cuenta |

**Exclusividad de reservas:** los índices parciales sobre Paciente, código, CI y correo normalizados incluyen solo procesos sin completar ni invalidar. Un vencido conserva su reserva hasta cerrarse explícitamente. El futuro Service deberá bloquear/revalidar e invalidar/reanudar antes de iniciar otro proceso. No hay índices dependientes de `now()` ni nuevas columnas para estados temporales.

El CI se recorta únicamente en espacios U+0020 exteriores; conserva signos, letras, espacios interiores y ceros iniciales. Correo propuesto/destino siguen la normalización de Usuario. Los modelos protegen campos de control y vínculos, ocultan hashes/datos temporales y usan fechas inmutables con zona/microsegundos. El nacimiento se serializa como fecha, sin hora.

### Verificaciones y estado local

- **111 pruebas, 585 aserciones correctas**. `PacientesActivacionTest` cubre esquema, unicidades, relaciones, campos protegidos, datos requeridos, defaults, XOR, FK y rollback.
- `ReservaActivacionConcurrenteTest` comprueba las cuatro claves con **dos conexiones PostgreSQL independientes**: espera, rechazo tras confirmación y aceptación tras rollback. Usa un esquema auxiliar exclusivo de pruebas y lo elimina al terminar.
- Las pruebas anteriores siguen correctas; la reversión/reaplicación de las quince migraciones ocurre únicamente dentro de la base aislada. Esta termina sin tablas ni esquemas auxiliares persistidos.
- Desarrollo: **15 tablas de dominio, 128 columnas y 15 FK**, además de `migrations`. Las seis tablas nuevas están vacías; los registros de las nueve anteriores se compararon antes/después y se conservaron. No se agregaron seeders de este bloque.
- Autoload PSR-4 estricto, Pint, sintaxis PHP y comprobación de espacios del diff correctos. El DER lógico conserva 28 entidades, 239 atributos y 43 relaciones.

Se actualizaron este README, DER §6.9 y `contexto-maestro.md` §17.6. CU-04/CU-07/CU-08 continúan pendientes: requieren sus Services, validación/autorización, contexto protegido, emisión y consumo atómico de códigos, reintentos/envíos Brevo, limpieza de vencidos y coordinación con altas administrativas simultáneas. Las preferencias de privacidad aún no tienen claves funcionales definidas. `analisis-clases.md` y `diagrama-clases.md` siguen pendientes de los CU/módulos funcionales. La siguiente fase es médicos, especialidades del médico y horarios.

## Fase 4 de datos — 2026-10-19

Se incorporaron tres migraciones (`000016`–`000018`, **lote 4**) y los modelos `Medico`, `MedicoEspecialidad` y `HorarioAtencion`, en `src/data/`. Usuario, Consultorio y Especialidad incorporan sus relaciones con médicos. El diseño físico y las restricciones se detallan en el DER §6.10.

| Tabla | Persistencia implementada |
|---|---|
| `medicos` | Código único, cuenta opcional única, consultorio opcional compartible, datos profesionales obligatorios y actividad independiente de la cuenta |
| `medico_especialidad` | PK compuesta Médico–Especialidad y dos FK restrictivas; pivote Eloquent sin ID sustituto ni timestamps |
| `horarios_atencion` | Varios intervalos por Médico/día, día 1–7, horas locales TIME(0), fin posterior al inicio y actividad |

Se conservan las nulabilidades del DER: un Médico puede registrarse sin cuenta o consultorio; ofrecer horarios reservables requerirá la asignación y validación del CU correspondiente. No se agregó unicidad al registro profesional o correo de contacto ni atributos de vigencia al pivote. La política para retirar la habilitación futura de un par Médico–Especialidad sigue pendiente.

### Verificaciones y estado local

- **140 pruebas, 752 aserciones correctas**. `MedicosHorariosTest` cubre esquema/tipos, PK compuesta, relaciones en ambas direcciones, escrituras técnicas del pivote acotadas por ambas claves, UQ/FK/CHECK, estados independientes, intervalos y rollback.
- Las cinco FK rechazan borrar o modificar claves referidas; los modelos protegen IDs, vínculos, códigos y actividad de asignación masiva.
- La reversión/reaplicación de las dieciocho migraciones se comprobó solo dentro de pruebas aisladas. La suite anterior, incluida concurrencia de activación, sigue correcta; la base de pruebas termina sin tablas ni esquemas auxiliares persistidos.
- Desarrollo: **18 tablas de dominio, 146 columnas y 20 FK**, además de `migrations`. Las tres nuevas están vacías; las quince tablas anteriores se compararon antes/después y conservaron sus registros. Sin nuevos seeders.
- Autoload PSR-4 estricto, Pint, sintaxis PHP y diff correctos. DER lógico conservado: 28 entidades, 239 atributos y 43 relaciones.

Se actualizaron este README, DER §6.10 y `contexto-maestro.md` §17.7. CU-09–CU-12 siguen pendientes de implementación funcional: roles/permisos, especialidades obligatorias, duración y consultorio válidos, exclusión concurrente de solapamientos, revisión de citas futuras y cambios de ubicación/avisos. El CHECK de intervalo no garantiza por sí solo disponibilidad ni ausencia de superposición. `analisis-clases.md` y `diagrama-clases.md` siguen pendientes de los CU/módulos funcionales. La siguiente fase comprende citas, cambios de citas y recordatorios.

## Fase 5 de datos — 2026-10-19

Se incorporaron tres migraciones (`000019`–`000021`, **lote 5**) y los modelos `Cita`, `CambioCita` y `Recordatorio`. Sus modelos relacionados incorporan las relaciones inversas. El diseño físico detallado está en el DER §6.11.

| Tabla | Persistencia implementada |
|---|---|
| `citas` | FK compuesta Médico–Especialidad, código único, cinco estados, modalidad Presencial, duración/ubicación efectivas y dos exclusiones de intervalos |
| `cambios_cita` | Autor, estados y snapshots administrativos; triggers que impiden UPDATE, DELETE y TRUNCATE |
| `recordatorios` | Cita y versión de programación únicas; generación explícita, lectura/invalidación opcionales y destinatario derivado de la cita |

### Protección de ocupación

PostgreSQL rechaza intervalos `[inicio, fin)` superpuestos por **Médico o consultorio**, incluso ante escrituras simultáneas. Los turnos contiguos son compatibles. Solo `Cancelada` queda fuera de la ocupación; Atendida e Inasistencia preservan su intervalo histórico.

La migración instala la extensión confiable **btree_gist** en `public` si falta. Se verificaron disponibilidad y privilegios antes de migrar. Una instalación nueva necesita permitir esa extensión o provisionarla previamente en ese esquema. La migración inversa conserva la extensión compartida; no se retira infraestructura ajena al revertir la tabla.

### Verificaciones y estado local

- **178 pruebas, 976 aserciones correctas**. `CitasRecordatoriosTest` cubre esquema, defaults, FK/UQ/CHECK, duración y ubicación históricas, estados, intervalos contiguos, inmutabilidad y rollback conjunto.
- `CitasConcurrentesTest` usa dos conexiones PostgreSQL y comprueba reservas/reprogramaciones incompatibles por ambos recursos, conservación del registro rechazado y unicidad concurrente de avisos. Los esquemas auxiliares y la extensión instalada solo para esas pruebas se retiran al terminar.
- La suite anterior sigue correcta y prueba reversión/reaplicación de las veintiuna migraciones solo dentro de transacciones aisladas. La base de pruebas termina sin tablas ni esquemas auxiliares persistidos.
- Desarrollo: **21 tablas de dominio, 180 columnas y 27 FK**, además de `migrations`, con dos exclusiones. Las dieciocho tablas previas conservaron sus registros; las tres nuevas quedaron vacías y no se agregaron seeders.
- Autoload PSR-4 estricto, Pint, sintaxis PHP y diff correctos. DER lógico conservado: 28 entidades, 239 atributos y 43 relaciones.

Se actualizaron este README, DER §6.11 y `contexto-maestro.md` §17.8. Los CU de citas y recordatorios siguen pendientes de Services/API: autorización, fecha/horario/actividad, transiciones, reprogramación con identidad/versión preservadas, historial y avisos atómicos, generación automática a 24 horas y preferencias. Las exclusiones no implementan por sí solas esos flujos; el error PostgreSQL `23P01` deberá normalizarse a HTTP 409 al exponer las operaciones. No se ha configurado un programador de avisos. `analisis-clases.md` y `diagrama-clases.md` siguen pendientes de los CU/módulos funcionales. La siguiente fase es consultas clínicas y sus versiones de consulta/antecedentes.

## Fase 6 de datos — 2026-10-19

Se incorporaron tres migraciones (`000022`–`000024`, **lote 6**) y los modelos `ConsultaMedica`, `VersionConsulta` y `VersionAntecedente`, con relaciones inversas en Cita, Paciente y Usuario. El diseño físico detallado está en el DER §6.12.

| Tabla | Persistencia implementada |
|---|---|
| `consultas_medicas` | Código único y una consulta como máximo por cita; Paciente/Médico derivados de Cita; inicio real opcional y creación obligatoria independientes |
| `versiones_consulta` | Número positivo único por consulta, autor/fecha, tipo Control/Enfermedad y snapshot clínico completo; textos opcionales admiten información todavía desconocida |
| `versiones_antecedente` | Número positivo único por Paciente, autor/fecha y consulta de origen obligatoria; antecedentes opcionales sin datos clínicos predeterminados |

Las dos tablas de versiones rechazan UPDATE, DELETE y TRUNCATE mediante triggers PostgreSQL. Cada corrección se inserta como versión nueva. Los modelos protegen asignación masiva y ocultan contenido clínico en serialización genérica; esto no sustituye autorización ni Resources explícitos. Las UQ impiden números repetidos, pero la asignación de la siguiente versión y la revalidación de la versión leída requerirán coordinación transaccional en el Service.

### Verificaciones y estado local

- **210 pruebas, 1214 aserciones correctas**. `ConsultasVersionesTest` verifica columnas exactas, seis FK restrictivas, UQ/CHECK/NOT NULL, relaciones, zona/microsegundos, datos desconocidos, versiones anteriores conservadas, inmutabilidad SQL/Eloquent y rollback conjunto.
- `ConsultasConcurrentesTest` comprueba con dos conexiones PostgreSQL la espera de escrituras competidoras, rechazo tras COMMIT y aceptación tras ROLLBACK para consulta por cita y número de versión por consulta/Paciente. Las versiones de antecedentes de distintas consultas de origen compiten igualmente por la secuencia del mismo Paciente.
- Guardar consulta y versiones conserva la cita Programada, no deduce `iniciada_en` ni copia datos clínicos a Bitácora. Son pruebas de persistencia; los eventos de negocio se integrarán en sus Services.
- La suite anterior verifica reversión/reaplicación de las veinticuatro migraciones dentro de una transacción aislada. Comprobación posterior: base de pruebas sin tablas en public, esquemas auxiliares ni btree_gist residual.
- Desarrollo: **24 tablas de dominio, 210 columnas y 33 FK**, además de `migrations` y las dos exclusiones de citas. Las veintiuna tablas previas conservaron sus registros; las tres nuevas quedaron vacías, sin seeders clínicos.
- Autoload PSR-4 estricto, Pint, sintaxis de los doce PHP afectados y diff correctos. Las secciones lógicas 2–4 del DER se compararon directamente en UTF-8 con HEAD, sin diferencias: 28 entidades, 239 atributos y 43 relaciones conservadas.

Se actualizaron este README, DER §6.12 y `contexto-maestro.md` §17.9. **CU-22/CU-23 siguen pendientes:** lectura autorizada, cuenta/rol/relación asistencial, coincidencia Paciente–Consulta–Cita y autor responsable, atención real, bloqueo de cambios administrativos incompatibles incluso con inicio desconocido, contenido/motivo de corrección, numeración coordinada y auditoría segura. `analisis-clases.md` y `diagrama-clases.md` siguen pendientes del desarrollo funcional. La siguiente fase de datos es laboratorio digital.

## Fase 7 de datos — 2026-10-19

Se incorporaron cuatro migraciones (`000025`–`000028`, **lote 7**) y los modelos `SolicitudLaboratorio`, `DocumentoLaboratorio`, `VersionResultado` y `RevisionResultado`, con relaciones inversas en ConsultaMedica, Usuario y Medico. Diseño físico en DER §6.13.

| Tabla | Persistencia implementada |
|---|---|
| `solicitudes_laboratorio` | Varias solicitudes por consulta, tres estados, punteros opcionales únicos y dos FK compuestas que garantizan pertenencia exacta de versión y revisión |
| `documentos_laboratorio` | Metadatos inmutables, ruta única, tamaño BIGINT positivo y SHA-256 hexadecimal de 64 caracteres; sin unicidad del contenido |
| `versiones_resultado` | Número positivo único por solicitud, documento/autor/fecha y origen opcional previo; asociaciones y sustituciones agregan versiones inmutables |
| `revisiones_resultado` | Médico, versión exacta, decisión Autorizar/Devolver y fecha; devolución con motivo obligatorio y revisiones inmutables |

Las FK circulares se añaden al finalizar la última migración; la inversa retira primero esas referencias. Cada versión actual pertenece a su solicitud y cada revisión publicada corresponde a esa misma versión. En revisión admite versión NULL para corregir una asociación sin falsear publicación. Disponible exige ambos punteros y rechaza cambios y eliminación ordinarios de su solicitud. Documentos, versiones y revisiones rechazan UPDATE/DELETE/TRUNCATE. El origen de una versión puede estar en otra solicitud, sin modificar la asociación anterior ni permitir autorreferencias o ciclos.

### Verificaciones y estado local

- **259 pruebas, 1609 aserciones correctas**, incluidas `LaboratorioPersistenciaTest`, `LaboratorioConcurrenteTest` y suite anterior. Se verificaron columnas/tipos, diez FK restrictivas, UQ/CHECK/NOT NULL, relaciones/casts, precisión temporal, serialización y asignación protegidas.
- Se probaron autorización directa sin devolución previa como escritura de persistencia, devolución/sustitución/nueva revisión, corrección de asociación con origen conservado, rechazo de versión ajena y revisión de otra versión, inmutabilidad y protección de solicitudes publicadas. Las pruebas no representan permisos o Services terminados.
- Dos conexiones/PID PostgreSQL comprueban contención por número de versión y ruta, rechazo tras COMMIT y aceptación tras ROLLBACK. Corrección/publicación compiten por la misma solicitud: escrituras SQL condicionadas a estado y versión rechazan una lectura desactualizada; las FK también rechazan publicar una revisión de la versión sustituida. El Service deberá aplicar y traducir ese protocolo.
- Fallos provocados revierten juntos metadatos nuevos, versión y punteros de corrección, o revisión y publicación. Los archivos no participan de rollback SQL: estos tests solo escriben metadatos ficticios; la coordinación de bytes privados queda pendiente de CU-25/CU-27.
- Reversión/reaplicación de las veintiocho migraciones comprobada solo dentro de transacción de pruebas. Base de pruebas posteriormente sin tablas public, esquemas auxiliares ni btree_gist residual.
- Desarrollo: **28 tablas de dominio, 239 columnas y 43 FK**, además de `migrations` y dos exclusiones. Las veinticuatro tablas previas conservaron sus registros; las cuatro nuevas están vacías. Sin seeders clínicos ni archivos de laboratorio creados.
- Autoload PSR-4 estricto, Pint sobre 77 archivos, sintaxis de catorce PHP afectados y diff correctos. Secciones lógicas 2–4 del DER comparadas directamente en UTF-8 con HEAD, sin diferencias.

Se actualizaron este README, DER §6.13 y `contexto-maestro.md` §17.10. **Se completó la preparación de persistencia prevista en fases 0–7**, conservando 28 entidades, 239 atributos y 43 relaciones. **CU-24–CU-27 siguen pendientes:** autorización del Médico solicitante, validación real del archivo y almacenamiento/entrega privada, decisión Autorizar antes de publicar, devolución previa que habilite corrección, numeración y revalidación coordinadas, publicación atómica y auditoría segura. No se han creado endpoints de carga/descarga. `analisis-clases.md` y `diagrama-clases.md` continúan pendientes del desarrollo de CU/módulos funcionales. El siguiente trabajo es la capa de negocio/API, priorizando usuarios y autenticación.

## Población de datos y demostración — 2026-10-19

### Comandos separados

Desde `backend/`:

```powershell
composer seed        # Roles, especialidades, configuración y tres consultorios iniciales
composer seed:admin  # Primer Administrador Activo, por excepción de instalación
composer seed:demo   # Conjunto ficticio, exclusivamente en la base local o de pruebas
```

`DatabaseSeeder` incorpora `ConsultorioSeeder`: `CONS-01`, `CONS-02` y `CONS-03`, con nombres Consultorio 1–3. Son datos iniciales para la clínica ficticia, no información obtenida de una sede real. Se insertan ausentes mediante `insertOrIgnore`, conservando IDs, nombres y actividad existentes. El seeder ordinario no llama a los otros dos comandos.

### Primer Administrador: excepción de instalación aprobada

Completar únicamente en `.env` privado:

```dotenv
CLINICA_ADMIN_CORREO=
CLINICA_ADMIN_NOMBRES=
CLINICA_ADMIN_APELLIDOS=
CLINICA_ADMIN_PASSWORD=
```

Después ejecutar `php artisan config:clear` y `composer seed:admin`. `config/seeders.php` centraliza la lectura del entorno; los ejemplos versionados no contienen identidades reales.

`AdministradorInicialSeeder` valida los datos y una contraseña privada de mínimo 12 caracteres, normaliza correo y coordina creación y evento SISTEMA mediante bloqueo PostgreSQL y transacción. Por **decisión explícita posterior del usuario**, sustituye la preparación Pendiente por una cuenta **Activa**, con hash Laravel, `correo_verificado_en` en la fecha de instalación y `cambio_clave_obligatorio=false`. No utiliza código, invitación ni envío de correo. El marcado es una **autorización del responsable de instalación, no una comprobación del buzón**. La contraseña de instalación es definitiva, no una credencial temporal.

El evento `INSTALACION_ADMIN_INICIAL` incluye únicamente referencias y metadatos de método SEEDER/verificación AUTORIZACION_INSTALACION; no contiene correo, contraseña ni hash. Una cuenta existente del mismo correo/rol se conserva íntegramente, incluso si sigue Pendiente o está desactivada: repetir el comando no activa, verifica ni restablece cuentas previas. Otro rol o un segundo Administrador de instalación distinto impiden crearla. En local/pruebas, `demo.admin@example.test` no cuenta como Administrador de instalación. Las cuentas posteriores del personal siguen la invitación ordinaria de 24 horas y un solo uso.

**Estado local:** cuenta creada con el correo proporcionado por el usuario y nombres Administrador / Vida Salud, configurados solo en `.env` privado. Se generó una contraseña aleatoria de 32 caracteres en `CLINICA_ADMIN_PASSWORD`; en PostgreSQL se comprobó su hash sin mostrar la clave. La segunda ejecución dejó filas y credenciales idénticas, conservando el dataset previo. Hay **7 usuarios (6 demo + 1 inicial) y 2 eventos de bitácora**. Este cambio no implementa endpoints de autenticación.

**Verificación posterior de esta excepción:** suite completa **274 pruebas y 1738 aserciones correctas**. Se comprueban hash, activación, marca de instalación, ausencia de secretos en el evento, rechazo de contraseña ausente/corta y reversión de cuenta/roles si falla la auditoría. Repetir con otra contraseña configurada no cambia credenciales ni reactiva una cuenta desactivada. Pint y sintaxis de tres PHP afectados correctos; `.env` excluido de Git y `git diff --check` correcto.

### Demostración reproducible

`DemostracionSeeder` verifica entorno y la identidad real PostgreSQL: `clinica_vida_salud`/`clinica_vida_salud_app` en local, o `clinica_vida_salud_test`/`clinica_vida_salud_test` en testing. Usa un bloqueo transaccional compartido con instalación, claves estables `DEMO-VS-*` y correos reservados `example.test`. Conserva registros existentes, contraseñas, estados, fechas y publicaciones; detecta vínculos incompatibles en claves reservadas en lugar de sobrescribirlos. Los archivos ya registrados se comprueban contra tamaño y SHA-256.

- Cuentas ficticias: `demo.admin@example.test`, `demo.recepcion@example.test`, `demo.medico1@example.test` a `demo.medico3@example.test` y `demo.paciente@example.test`. Se simulan activas/verificadas solo dentro del dataset local, sin afirmar verificación real ni enviar correo.
- `CLINICA_DEMO_PASSWORD` es opcional y privado, mínimo 12 caracteres. Si se configura **antes de la primera carga**, se guarda su hash Laravel. Si se omite, se genera un secreto aleatorio que no se muestra ni conserva en claro; no hay contraseña predeterminada conocida. Definirlo después no cambia hashes existentes: los seeders no restablecen credenciales. Los endpoints de login aún están pendientes.
- Tres consultorios adicionales `DEMO-VS-C01`–`C03`, tres médicos, una especialidad por médico y 21 intervalos de atención sin compartir consultorios. Cinco pacientes, uno con cuenta web y cuatro sin ella.
- Cinco citas con intervalos de 15 minutos y ubicación histórica explícita: una por cada estado. Nueve cambios históricos con actores de rol apropiado. La primera carga fija las fechas; repetirla después no desplaza turnos ni reconstruye historia.
- Una consulta vinculada a la cita Atendida, dos versiones de consulta y dos de antecedentes con el mismo Paciente/Médico responsable.
- Tres estudios: Pendiente, En revisión tras devolución y sustitución (nueva versión aún sin autorizar) y Disponible con revisión Autorizar de su versión exacta. Tres versiones/documentos y dos revisiones conservadas.
- Tres PDF legibles, generados sin dependencias adicionales y marcados **DOCUMENTO FICTICIO - SIN VALOR CLINICO**, bajo `storage/app/private/demo-seeders/`. Solo en disco privado; no se crean enlaces públicos. Los metadatos coinciden con los bytes. Ante fallo dentro de la carga se compensan únicamente los archivos nuevos antes de liberar el bloqueo; SQL no se presenta como rollback del sistema de archivos.
- Un evento SISTEMA identifica la carga del dataset, sin contenido clínico ni secretos. No se simulan sesiones, tokens, códigos, preferencias funcionales ni generación de recordatorios.

### Resultado comprobado

Carga ordinaria y demo aplicadas y repetidas en desarrollo. Las filas previas se conservaron y la segunda ejecución dejó filas y bytes idénticos. Dataset original: 4 roles, 3 especialidades, 1 configuración, **6 consultorios (3 iniciales + 3 demo), 6 usuarios ficticios, 3 médicos, 21 horarios, 5 pacientes, 5 citas, 9 cambios, 1 consulta, 2 versiones de consulta, 2 versiones de antecedentes, 3 solicitudes, 3 documentos/versiones de resultado, 2 revisiones y 1 evento de bitácora**. La instalación posterior agrega el Administrador y su evento: **7 usuarios y 2 eventos** en total.

- **270 pruebas, 1716 aserciones correctas**. `PoblacionDatosTest` comprueba idempotencia incluso al avanzar la fecha, conservación de ediciones/credenciales/archivos, roles y asociaciones, cronología de corrección y publicación, PDF/tamaño/hash, Administrador pendiente sin tokens, rechazo de promoción/segunda instalación y bloqueo en producción/contraseña inválida.
- Fallo SQL provocado revierte el dataset y elimina solo archivos nuevos; un archivo previo distinto se conserva. La suite anterior sigue correcta.
- Composer válido, autoload PSR-4 estricto, Pint sobre 82 archivos, sintaxis de siete PHP afectados y diff correctos. Esquema sin cambios: 28 tablas/239 columnas/43 FK, 28 migraciones aplicadas. DER lógico conservado.

Documentación sincronizada con DER §6.14 y contexto §17.11. No se completa ningún CU: los seeders son infraestructura de población, no Services ni APIs. Análisis y diagrama de clases siguen pendientes del desarrollo funcional.

## Ampliación de TokenAcceso y políticas de autenticación — 2026-10-19

Se añadió la migración incremental `src/data/database/migrations/2026_10_19_000029_allow_temporary_credentials_in_tokens_acceso.php`, aplicada en desarrollo como **lote 8**. Amplía `tokens_acceso_proposito_ck` con **`CredencialTemporal`**, conservando `InvitacionPersonal` y `RecuperacionClave`. Reutiliza hash, emisión, expiración, consumo e invalidación del modelo `TokenAcceso` existente; no requiere cambios del modelo ni columnas nuevas.

- Una sola sentencia ALTER TABLE sustituye el CHECK atómicamente. El `down` solo restaura el catálogo anterior si no existe ninguna fila `CredencialTemporal`; con historial temporal, incluso consumido o invalidado, PostgreSQL rechaza la reversión sin borrar ni recategorizar filas. Se probó reversión/reaplicación únicamente en la base aislada de pruebas.
- Comparación local antes/después: todas las filas y definiciones de columnas de las 28 tablas idénticas; se mantienen **239 columnas, 43 FK, 7 usuarios, 2 eventos y cero tokens**. No se ejecutaron seeders ni se enviaron correos en esta ampliación.
- **280 pruebas y 1772 aserciones correctas** en PostgreSQL aislado. `UsuariosAuditoriaTest` comprueba los tres propósitos, conservación, expiración obligatoria/válida, hash único entre propósitos, Eloquent y rechazo de reversión con historial temporal. Pint y sintaxis de los dos PHP afectados correctos.
- Sincronizados los cinco documentos existentes de `../docs/ai/`: CU-03/CU-05/CU-06 separados de la invitación inicial; sesión temporal restringida con cierre y nuevo login; cambios de rol con identidad e historial preservados; rechazo de obligaciones médicas incompatibles/no verificables y protección transaccional del último Administrador. Son políticas aprobadas del proyecto, no nuevos RF/RN oficiales ni controles implementados. Referencias: reglas §§4.3–4.4, DER §§3.4 y 6.15, contexto §17.14.

Quedan por definir duración, generación/entrega, intentos, reemisión/usos y consumo de la credencial, tratamiento de contraseña anterior al emitir y concurrencia con sesiones/cambios/recuperaciones. Admitir el propósito en PostgreSQL no implementa ese flujo. **Ningún CU queda completado**; análisis y diagrama de clases siguen pendientes del desarrollo funcional. CU-01/CU-02 pueden abordarse con los controles definidos, impidiendo acceso normal ante cambio obligatorio.

## Usuarios y autenticación — fase 1, backend de CU-01/CU-02 (2026-10-19)

### Contrato y uso desde la SPA

La API utiliza sesiones Laravel/Sanctum con cookies. Desarrollo está configurado para backend `http://localhost:8000` y frontend `http://localhost:5173`, con CORS de credenciales y comprobación exacta de origen. Las peticiones de la SPA necesitan Origin/Referer permitido; no hay acceso alternativo por bearer token.

| Operación | Petición | Resultado |
| --- | --- | --- |
| Preparar CSRF | GET `/sanctum/csrf-cookie` | 204; cookies de sesión HttpOnly y XSRF-TOKEN para la cabecera CSRF. |
| Iniciar sesión | POST `/api/auth/login`, JSON con `correo_acceso` y `password` | 200; sesión regenerada y `data` con `id_usuario` como string, `correo_acceso`, `nombre_visible` y `rol`. |
| Restaurar sesión | GET `/api/auth/me` | 200 con el mismo Resource; 401 sin sesión válida. No recibe un ID de usuario seleccionable. |
| Cerrar sesión | POST `/api/auth/logout` | 204 sin JSON; invalida la sesión actual y renueva CSRF. |

El cliente conserva cookies y envía `Accept: application/json`, credenciales y `X-XSRF-TOKEN` en escrituras. Resolver CSRF contra la raíz del backend, no contra `/api/sanctum/csrf-cookie`. Logout repetido es idempotente con CSRF válido; un 419 exige renovar el mecanismo sin reenvío automático de escrituras. Login desde sesión normal ya válida devuelve 409 para impedir un intercambio silencioso de identidad.

- Credenciales/estado no admitidos devuelven el mismo 401 genérico, sin revelar bloqueo o existencia de correo. Me devuelve 401 por ausencia, expiración/revocación o contexto incompatible; falta de permiso con sesión válida es 403.
- Entradas inválidas: 422 con mensajes por campo; CSRF ausente/vencido: 419; límites técnicos: 429 y Retry-After accesible desde la SPA mediante CORS. Los errores usan `success: false`, `error.code` y `error.message`, con detalles seguros solo cuando proceden. Los fallos inesperados usan 500 seguro.
- Tres contraseñas incorrectas consecutivas bloquean diez minutos. El bloqueo no se extiende por intentos posteriores; expiración/éxito reinician contador. El limitador técnico independiente usa 20 solicitudes/minuto/IP y 5/minuto/correo+IP, configurable en `config/seguridad.php`, con ámbitos HMAC persistidos en ControlIntentos.
- Sesiones database cifradas; el guard nativo regenera ID/CSRF e inicializa huella HMAC. Login guarda y comprueba la fila en la transacción del Usuario/auditoría antes de confirmar éxito. La primitiva de revocación múltiple exige transacción y debe coordinarse con el bloqueo de Usuario en los CU consumidores futuros. Un guardado tardío no recrea una sesión revocada.
- Cuenta, ficha y bloqueo permanecen independientes. Paciente/Médico con ficha inactiva y cuenta habilitada pueden autenticarse para operaciones autorizadas; el acceso clínico exige su propio rol/vínculo. Cambio obligatorio impide acceso normal mientras se desarrolla fase 6 del plan vigente.

### Verificación y alcance

Pruebas de API: `tests/Feature/AutenticacionTest.php`. Pruebas de procesos/conexiones PostgreSQL independientes: `tests/Feature/AutenticacionConcurrenteTest.php`, con fixtures de navegador, worker y barreras de respuesta. Solo se usan base/rol `clinica_vida_salud_test` y datos ficticios; las pruebas de CSRF conservan esa conexión aislada aunque ejerciten el middleware real fuera de la omisión habitual de testing.

```powershell
php artisan test --compact --no-ansi tests/Feature/AutenticacionTest.php tests/Feature/AutenticacionConcurrenteTest.php
php artisan test --compact --no-ansi
php artisan route:list --path=api/auth -v
```

Autoload PSR-4 estricto y Composer validados; Pint sobre los 26 PHP afectados y sintaxis comprobados. Documentación actualizada en análisis de clases y contexto §17.16. La API ordinaria está implementada; React/recorrido en navegador real corresponden a la **etapa frontend posterior**, según la reorganización de contexto §17.17, y el módulo backend completo sigue en desarrollo. Brevo, invitaciones, recuperación, administración de cuentas y credencial temporal se desarrollan en las fases acordadas. El esquema permanece en 28 entidades/239 atributos/43 relaciones; este bloque no requiere migraciones. El diagrama global de clases corresponde al cierre backend del módulo.

**Resultado final de fase 1:** suite completa con **324 pruebas y 2216 aserciones correctas** (106,58 s). Las tres rutas de autenticación están registradas y `git diff --check` es correcto. Desarrollo conserva siete usuarios, dos eventos y cero tokens, con 29 migraciones; no se ejecutaron seeders ni envíos de correo.

## Plan vigente — completar backend antes de frontend

Las fases siguientes sustituyen la antigua fase 2 de React. Son independientes de las fases históricas de preparación de datos de este README.

| Fase | Entrega |
| --- | --- |
| 0–1 | Diseño y API de login/me/logout; completadas. |
| 2 | Cambio de contraseña propia CU-05; implementado, detalle siguiente. |
| 3 | Alta/invitación del personal CU-03 e integración Brevo de seguridad; implementada con pruebas simuladas. |
| 4 | Administración de acceso/roles CU-03 y protección del último Administrador; implementada. |
| 5 | Recuperación por correo CU-06 para los cuatro roles; implementada con correo simulado. |
| 6 | Restablecimiento administrativo temporal del personal CU-03 implementado; política aprobada y contratos al final de este README/contexto §17.23. |
| 7A / 7B | Ambos escenarios de CU-04 implementados; 7B incluye emisión autorizada de código por CU-07. |
| 8 | Integración/pruebas y cierre backend completados; diagrama global aplazado por solicitud expresa del usuario. |

La protección del último Administrador se aplica en cada operación que lo requiera, incluidos restablecimientos. Se mantienen los rechazos aprobados al abandonar Médico con obligaciones incompatibles/no verificables. La documentación se actualiza por CU/fase; el cierre backend se distingue del recorrido completo posterior con React. AUT-21 se conserva pendiente para esa etapa frontend.

## Usuarios y autenticación — fase 2 backend, CU-05

**PUT `/api/auth/password`**, para cualquiera de los cuatro roles con cuenta habilitada y sesión normal, origen permitido y CSRF. JSON de entrada:

```json
{
  "current_password": "Contraseña actual ficticia",
  "password": "Nueva frase ficticia 456",
  "password_confirmation": "Nueva frase ficticia 456"
}
```

Al confirmar devuelve **204 sin cuerpo** y cookies renovadas: mantiene la sesión actual de forma segura con un ID y CSRF nuevos, revocando todos los IDs anteriores de ese Usuario. El cliente debe utilizar las cookies/cabecera CSRF nuevas. La identidad se deriva de la sesión; los campos ajenos como id_usuario/rol no se utilizan. No se modifica la cuenta de otro Usuario ni se duplica el hash en Paciente/Médico.

- Mínimo **12 caracteres**, confirmación exacta, conservación de espacios/caso/Unicode y máximo técnico de 1024 caracteres. Con el algoritmo bcrypt vigente se valida además máximo **72 bytes y ausencia de NUL**, para evitar truncamiento o fallo al guardar. No se cambia algoritmo ni contraseñas existentes.
- Contraseña actual incorrecta: **422 AUTH_CLAVE_ACTUAL_INVALIDA**, con evento DENEGADO y sin modificar hash/sesiones ni contador de login. Validación de campos: 422 VALIDACION_ERROR con details; errores restantes mantienen el contrato central.
- Límite técnico propio: 20 solicitudes/minuto/IP y 5/minuto/Usuario, compartidas entre dispositivos e IP de ese Usuario, con ámbitos HMAC persistentes separados de login y 429/Retry-After. No prolonga la ventana ni desactiva/bloquea la cuenta. Parámetros en config/seguridad.php.
- Cuenta/rol/vínculo/huella, condición normal y fila de sesión vigente se comprueban otra vez después del bloqueo de Usuario. Hash, revocación, persistencia de sesión renovada y auditoría de éxito confirman juntos; el fallo necesario revierte todo y limpia ambos guards web/Sanctum.
- No cambia estados administrativos, vínculos ni bloqueo temporal de login. Invitación inicial, recuperación y sesión restringida temporal mantienen sus flujos independientes en fases posteriores. No se envían correos por CU-05.

Clases nuevas: CambiarClaveRequest, ClaveController, ClaveService y LimitarCambioClave. Reutiliza y amplía los Services/Repositories de fase 1. Contrato, colaboraciones y evidencia en analisis-clases §14 y contexto §17.17. No se agregan tablas, columnas, modelos ni migraciones; DER vigente y diagrama global pendiente de fase 8.

```powershell
php artisan test --compact --no-ansi tests/Feature/CambioClaveTest.php tests/Feature/AutenticacionTest.php tests/Feature/AutenticacionConcurrenteTest.php
php artisan route:list --path=api/auth -v
```

**Verificación focalizada de fase 2:** 83 pruebas y 1015 aserciones correctas, incluidas conexiones/procesos PostgreSQL independientes y CSRF real. Composer validate y autoload PSR-4 estrictos, Pint/sintaxis de los 32 PHP acumulados, cuatro rutas y formato/JSON/referencias documentales comprobados. Desarrollo conserva siete usuarios, dos eventos, cero tokens y 29 migraciones, con bcrypt; no se ejecutaron seeders ni envíos de correo.

**Resultado final de fase 2:** suite completa con **363 pruebas y 2787 aserciones correctas** (133,65 s). Se ejecutó con margen de 300 s después de que el límite inicial de 120 s interrumpiera la primera ejecución. Backend de CU-05 completado y verificado; el módulo completo continúa con fase 3.

## Usuarios y autenticación — fase 3 backend, alta/invitación del personal (CU-03 parcial)

### API y recorrido

| Operación | Acceso/entrada | Respuesta |
| --- | --- | --- |
| POST `/api/personal` | Administrador normal con sesión/CSRF. `rol`, `correo_acceso`; `nombres_personal`/`apellidos_personal` para Administrador o Recepcionista; para Médico solo `id_medico` existente y libre, sin sustituir sus nombres. | 201: `data` con id_usuario string, correo_acceso, rol y estado_cuenta Pendiente; `meta.invitacion` con envio y expira_en. |
| POST `/api/personal/{id}/invitacion` | Mismo actor; solo personal pendiente de primera contraseña y con vínculo válido. ID BIGINT positivo de ruta. | 200 con el mismo Usuario y nueva invitación que invalida la anterior. |
| POST `/api/auth/invitaciones/aceptar` | Anónimo, origen permitido y CSRF; token, password y password_confirmation. | 204 sin cuerpo: correo verificado/contraseña establecida/cuenta Activa; exige login posterior, no autentica automáticamente. |

Ejemplo ficticio de alta administrativa (el Administrador no asigna una contraseña):

```json
{
  "rol": "Recepcionista",
  "correo_acceso": "trabajador@example.test",
  "nombres_personal": "Persona",
  "apellidos_personal": "Ficticia"
}
```

La API nunca devuelve la invitación en claro. El titular recibe por correo un enlace de 24 horas y un solo uso, generado con 256 bits aleatorios y guardado como HMAC-SHA256 en TokenAcceso/InvitacionPersonal. El enlace previsto es `FRONTEND_URL/activar-personal#token=...`: usa fragmento para que el servidor web no reciba el secreto por GET. La pantalla React todavía no existe; el contrato del backend recibe el token por POST JSON junto con la primera contraseña y confirmación. La política se comparte con CU-05 mediante ClaveRequest, sin exigir contraseña anterior para invitación.

Se revalida al Administrador bajo bloqueo de su cuenta/sesión persistida. Médico requiere el mismo registro profesional existente y sin vínculo a otra cuenta; se bloquea y vincula sin alterar nombres, actividad, consultorio u horarios. Los correos normalizados cuentan con unicidad PostgreSQL. Se rechazan alta de Pacientes, Médico inexistente/ocupado, roles adicionales y reenvío a cuentas activas/desactivadas/Paciente. La instalación del primer Administrador no se reutiliza ni se modifican sus credenciales.

### Envío y recuperación ante fallos

Alta/vínculo/token/eventos se guardan en una transacción; **el envío ocurre con DB::afterCommit después del commit exterior**, nunca dentro de un rollback SQL. La respuesta distingue:

- `meta.invitacion.envio = simulado`: transporte array local/de pruebas, sin salida externa.
- `aceptado`: el transporte aceptó el mensaje; no prueba entrega al buzón ni verificación del correo.
- `fallido`: no se confirmó el envío; la cuenta permanece Pendiente y permite reenvío administrativo controlado.
- `pendiente`: callback diferido por una transacción exterior todavía abierta.

El resultado de envío se audita por separado sin secretos. No hay reintentos SMTP automáticos ni cola/outbox nueva. Una interrupción después del commit se recupera reemitiendo sobre la misma cuenta. Un fallo de auditoría posterior al envío puede devolver 500 sin deshacer correo/cuenta ya confirmados. Reenviar invalida el enlace anterior y emite otro con su propia vigencia de 24 horas; un fallo SQL antes de confirmar la nueva emisión conserva el enlace previo. La aceptación y reemisión comparten bloqueo de Usuario antes del token, evitando doble consumo y estados parciales.

Parámetros técnicos iniciales en `config/seguridad.php`: espera de 60 segundos y cinco emisiones/hora por cuenta, incluidas inicial/fallidas; límites por acción de 20 solicitudes/minuto/IP y cinco/minuto/ámbito (Admin o token), mediante HMAC y ControlIntentos. 429/Retry-After no prolonga ventana. Estos valores no son nuevos RF/RN ni parámetros del flujo temporal todavía pendiente.

### Brevo HTTP y configuración privada

Por decisión posterior a fase 5, el mailer Laravel `brevo` usa el transporte propio `brevo-http`, registrado con Mail::extend. Envía JSON a `https://api.brevo.com/v3/smtp/email` mediante el cliente HTTP nativo de Laravel, sin instalar SDK. Verificación HTTPS obligatoria, timeout de conexión de 3 segundos y total de 10 segundos, sin redirecciones ni reintentos automáticos. Esta configuración sustituye a Brevo SMTP; los resultados históricos de fases 3/5 corresponden al transporte anterior. Las variables están declaradas sin secretos en `.env.example`; el `.env` privado no se modificó.

Para utilizar envío real, el responsable debe configurar privadamente:

- `SEGURIDAD_MAILER=brevo`.
- `BREVO_API_KEY`, una API key HTTP de Brevo, cargada en services.brevo.key; no es una clave SMTP.
- `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` con remitente autorizado por Brevo.
- `FRONTEND_URL` con el destino real de la SPA; producción exige HTTPS y su configuración de cookies/CORS correspondiente.

El valor predeterminado de seguridad sigue siendo `array`, admitido solo en local/testing y señalado como simulado. No se admite el transporte log/failover para correos de seguridad ni se simula entrega con array en producción. No se realizaron envíos reales ni se verificó entrega a buzón. La plantilla Blade es exclusivamente de correo, no una interfaz funcional. Brevo permanece reservado a seguridad, nunca citas, recordatorios o contenido clínico.

La API requiere remitente, destinatario, asunto y contenido HTML; autentica con cabecera `api-key`, sin credenciales en URL. Solo una respuesta **201 con messageId válido** confirma aceptación por Brevo; no demuestra llegada al buzón. Errores HTTP, confirmación incompleta o fallos de conexión producen errores saneados sin copiar respuestas, claves o enlaces del proveedor. La cuenta/token ya confirmados se conservan para reemisión controlada. Un timeout no demuestra si el proveedor llegó a aceptar el correo; se evita reintentarlo automáticamente.

### Verificación y alcance

`tests/Feature/InvitacionPersonalTest.php` cubre los tres roles, validaciones, correo/vínculo, hash/verificación pendientes, primera contraseña sin login automático, uso único/vigencia/reenvío/límites, conflictos, errores SQL/auditoría/SMTP, callback solo después del commit exterior y rollback sin correo, transporte Brevo simulado y exclusión de configuraciones inseguras. `AutenticacionConcurrenteTest` añade procesos independientes para doble consumo, altas con correo duplicado, dos cuentas compitiendo por el mismo Médico y reenvío frente a aceptación.

```powershell
php artisan test --compact --no-ansi tests/Feature/InvitacionPersonalTest.php tests/Feature/AutenticacionConcurrenteTest.php tests/Feature/CambioClaveTest.php tests/Feature/AutenticacionTest.php
php artisan route:list --path=personal -v
php artisan route:list --path=api/auth -v
```

Regresión focalizada de fases 1–3: **124 pruebas y 1519 aserciones correctas**, con PostgreSQL aislado y correos simulados. Análisis de clases §15 y contexto §17.18 sincronizados. No se agregan tablas, columnas, modelos ni migraciones: permanecen 28 entidades, 239 atributos y 43 relaciones; DER vigente y diagrama global pendiente de fase 8. **CU-03 completo sigue pendiente** de fases 4/6; frontend posterior. La siguiente entrega es administración de acceso/roles y último Administrador.

**Resultado final de fase 3:** suite completa con **404 pruebas y 3291 aserciones correctas** (146,07 s). Composer validate/autoload PSR-4 estrictos, Pint de 46 PHP, sintaxis de 47 PHP incluida la plantilla, siete rutas API, formato/JSON/referencias documentales y git diff --check correctos. Desarrollo conserva siete usuarios, dos eventos, cero tokens y 29 migraciones; seguridad usa transporte array y no se enviaron correos reales ni se ejecutaron seeders. Entrega real con Brevo pendiente de configuración privada y comprobación autorizada.

## Usuarios y autenticación — fase 4 backend, acceso/roles y último Administrador (CU-03 parcial)

### Contratos de cuenta

Todos requieren Administrador con sesión normal y origen permitido; los PATCH exigen CSRF.

| Endpoint | Uso / respuesta |
| --- | --- |
| GET `/api/usuarios` | Filtros opcionales rol, estado_cuenta y correo_acceso exacto normalizado; page/per_page, 20 por defecto y 100 máximo. Orden ID descendente fijo. 200 data/links/meta. |
| GET `/api/usuarios/{id}` | Datos administrativos mínimos, ID BIGINT validado. 200 data; 404 para inexistente o Paciente sin verificación/vínculo previo. |
| PATCH `/api/usuarios/{id}/estado` | Cuerpo solo estado_cuenta=Activa o Desactivada. 200 data; exige transición real desde estado opuesto y credencial/verificación/vínculo legítimos. |
| PATCH `/api/usuarios/{id}/rol` | Cuerpo rol=Administrador/Recepcionista/Médico; para Médico id_medico y, si es vínculo inicial, identidad_verificada=true. 200 data; conserva identidad y revoca sesiones. |

Ejemplos de cuerpos ficticios:

```json
{"estado_cuenta": "Desactivada"}
```

```json
{"rol": "Recepcionista"}
```

```json
{"rol": "Médico", "id_medico": "123", "identidad_verificada": true}
```

La declaración de identidad corresponde a una comprobación real del Administrador, no a una prueba automática por coincidencia de nombres. El backend también exige correspondencia de nombres/apellidos normalizados por caso/espacios, registro profesional existente/libre y vínculo histórico compatible; rechaza diferencias sin corregir identidades automáticamente. Con vínculo histórico solo acepta el mismo Médico y lo conserva al retirarle el rol. No crea horarios, consultorios, especialidades, citas o profesionales ni activa la ficha.

Para Paciente, el Resource devuelve únicamente id_usuario, correo_acceso, rol, estado_cuenta y correo_verificado_en, sin CI, ficha, clínica, hashes, contadores o tokens. Para personal agrega nombres administrativos/nombre visible e ID profesional histórico. Los Requests por cuenta rechazan campos adicionales y toman el objetivo exclusivamente de la ruta. No cambian correo, credenciales, identidad o rol del Paciente ni convierten Paciente↔personal.

Desactivar/reasignar rol revoca **todas** las sesiones en la misma transacción del cambio y auditoría. Reactivar también elimina cualquier sesión anterior: exige nuevo login, conserva hash/correo/verificación/bloqueo e identidad y no activa la ficha administrativa/profesional. No habilita ni cancela invitaciones Pendiente mediante cambio de estado. Los cambios sobre la cuenta propia cierran su contexto actual.

### Continuidad administrativa y límite clínico

Antes de modificar se toma un bloqueo advisory transaccional PostgreSQL reservado `(736201, 1)`, luego se bloquea/revalida actor y sesión vigente y después el objetivo. Tras aplicar el cambio se exige al menos un Administrador Activa/verificado/con hash/sin cambio obligatorio/con identidad administrativa utilizable. El bloqueo pasajero de login no lo excluye del cómputo. Si no queda ninguno, **409 ULTIMO_ADMINISTRADOR** y rollback, incluso de un vínculo profesional tentativo. El futuro restablecimiento temporal debe reutilizar este protocolo antes de restringir cuentas; no es un trigger para SQL externo.

Retirar Médico exige inactividad profesional expresa previa por CU-10 y ausencia comprobable de obligaciones: no admite citas Programada/Confirmada/Atendida ni consulta vinculada a cualquiera de sus citas. Atendida, consulta existente o iniciada_en=NULL no demuestran cierre documental. Una consulta con solicitudes de laboratorio también impide retiro. El modelo actual solo permite comprobar ausencia de atenciones o únicamente Cancelada/Inasistencia sin consulta; el resto devuelve **409 MEDICO_OBLIGACIONES_NO_RESUELTAS**. Conserva vínculo, historia y autores, sin cancelar, trasladar o inventar cierres. Los futuros flujos de reservas/actividad deben respetar el mismo bloqueo de Médico; esos módulos aún están pendientes.

Eventos de éxito: responsable/objetivo/roles y cambios_seguros con antes/después de rol, estado e ID profesional, sin clínica ni secretos. Conflictos autorizados se auditan como denegados tras revertir; errores SQL/auditoría devuelven 500 seguro sin cambio parcial. 401/403/419/422/404/409 conservan su semántica centralizada.

### Verificación y alcance

AdministracionCuentaTest cubre cuatro roles, sesiones múltiples/reactivación/bloqueos, cuenta propia/último Admin, identidad/vínculo histórico, rechazos clínicos, consulta mínima/filtros/paginación, campos ajenos y fallos de auditoría/revocación. AutenticacionConcurrenteTest prueba con procesos PostgreSQL independientes retiros simultáneos de dos últimos Admin, retiros cruzados, actor revocado mientras espera coordinación, respuesta tardía de login frente a desactivación y competencia por mismo vínculo profesional.

```powershell
php artisan test --compact --no-ansi tests/Feature/AdministracionCuentaTest.php tests/Feature/AutenticacionConcurrenteTest.php tests/Feature/InvitacionPersonalTest.php tests/Feature/CambioClaveTest.php tests/Feature/AutenticacionTest.php
php artisan route:list --path=api/usuarios -v
```

Regresión focalizada conjunta: **157 pruebas y 2053 aserciones correctas**, más **1 prueba/9 aserciones** de rollback del vínculo tentativo por último Administrador. **Suite completa: 438 pruebas y 3834 aserciones correctas** (135,66 s).

Comprobaciones de cierre: Composer validate/autoload PSR-4 estrictos, Pint de 55 PHP, sintaxis de 56 PHP/Blade, cuatro rutas nuevas de cuentas, formato/JSON/referencias documentales y git diff --check correctos. Desarrollo conserva siete usuarios, dos eventos, cero tokens y 29 migraciones; transporte de seguridad array. No se ejecutaron seeders, migraciones de desarrollo o correos reales ni se modificaron credenciales privadas en esta fase.

Análisis §16 y contexto §17.19 sincronizados. DER contrastado y sin cambios de esquema/modelos/migraciones: 28 entidades, 239 atributos, 43 relaciones. Diagrama global todavía pendiente de fase 8. CU-03 completo requiere el restablecimiento temporal de fase 6; la **siguiente entrega es fase 5, recuperación por correo de los cuatro roles (CU-06)**. React sigue para después del backend.

## Usuarios y autenticación — fase 5 backend, CU-06 (2026-10-19)

### API de recuperación

Ambos endpoints son públicos, exigen origen SPA permitido y CSRF y se declaran en `src/presentation/routes/auth.php`. Obtener antes `GET /sanctum/csrf-cookie` y conservar cookies/cabecera XSRF, como en login.

**POST `/api/auth/password/forgot`** recibe:

```json
{
  "correo_acceso": "persona@example.test"
}
```

Normaliza el correo conforme al acceso actual. Responde **202**, privado/no-store, con el mismo cuerpo para cuenta elegible, inexistente, pendiente, desactivada o no vinculada, incluso si falla el envío:

```json
{
  "data": {
    "message": "Si corresponde, recibirás un enlace para recuperar tu contraseña."
  }
}
```

La consulta de cuenta, emisión y envío se ejecutan en terminación mediante `defer`. El cuerpo incluye Content-Length para que el cliente pueda completarlo antes del cierre de conexión. El 202 acepta la solicitud: no confirma existencia, emisión, entrega ni cambio de contraseña. Una prueba HTTP real con transporte bloqueado comprueba la finalización del cliente antes de liberar el envío. La topología productiva, incluidos buffering/proxy y finalización FastCGI, requiere su propia verificación de despliegue.

Cuando corresponde, emite un secreto aleatorio de 256 bits, persiste solo HMAC-SHA256 con propósito `RecuperacionClave`, vigencia de 60 minutos y consumo único. Cada emisión invalida las recuperaciones anteriores bajo bloqueo del mismo Usuario. La URL usa únicamente FRONTEND_URL configurado y `/recuperar-clave#token=...`; la pantalla React está pendiente. El secreto del fragmento debe enviarse por POST/JSON, nunca consultarse por GET.

**POST `/api/auth/password/reset`** recibe:

```json
{
  "token": "<secreto hexadecimal de 64 caracteres recibido por correo>",
  "password": "Nueva frase ficticia 456",
  "password_confirmation": "Nueva frase ficticia 456"
}
```

Tras confirmar devuelve **204 sin cuerpo** y exige nuevo login; no autentica al titular. El ID/rol/estado enviados no seleccionan ni modifican la cuenta. Token mal formado o contraseña inválida: 422 VALIDACION_ERROR; enlace inexistente, vencido, consumido, invalidado, de otro propósito o cuenta no elegible: 422 RECUPERACION_INVALIDA genérico. Origen ajeno: 403; CSRF: 419; límites: 429 con Retry-After; fallo necesario de persistencia/auditoría en consumo: 500 seguro con rollback.

### Reglas, coordinación y correo

- Los cuatro roles requieren cuenta Activa, correo previamente verificado, hash y vínculo de identidad compatible. La actividad profesional/ficha y el bloqueo temporal son independientes: se conservan, y recuperar no omite el bloqueo de login. Mientras fase 6 no defina la coordinación del flujo temporal, una cuenta con cambio obligatorio no emite ni consume recuperación; no se quita esa marca ni se alteran tokens CredencialTemporal por CU-06.
- Reutiliza `ClaveRequest`: mínimo 12 caracteres, confirmación, espacios/caso/Unicode preservados; con bcrypt actual, máximo 72 bytes y sin NUL. No requiere la clave anterior.
- El Service bloquea Usuario antes de TokenAcceso y revalida elegibilidad/vigencia/uso. Hash nuevo, consumo, invalidación de otras recuperaciones, revocación de todas las sesiones y evento de éxito confirman en una sola transacción. Conserva identidad, permisos, vínculos e historial. Si el navegador pertenece al titular, cierra también su contexto actual; una sesión de otra cuenta no se sustituye por la recuperada.
- CU-05 y los cambios administrativos de estado/rol invalidan las recuperaciones anteriores en su misma transacción. Se prueba la carrera con emisión/consumo, cambio ordinario, desactivación y respuestas de login tardías. Invitaciones y CredencialTemporal conservan propósitos separados.
- Parámetros técnicos en `config/seguridad.php`, separados de login/invitaciones: solicitud y consumo, cada acción con 20 peticiones/minuto/IP y cinco/minuto/ámbito (correo o token); solicitud además cinco/hora/correo, compartidas entre orígenes y aplicadas también a correos inexistentes. Controles persistidos como HMAC, sin correo/token en claro. Los rechazos no prolongan ventanas ni modifican contador/bloqueo de login. No se hereda la espera de 60 s de invitaciones como regla nueva de CU-06.
- Correo de seguridad reutiliza el mailer Brevo, actualmente HTTP, y su configuración privada descrita arriba. `DB::afterCommit` envía solo después de confirmar token/auditoría; resultado de envío se registra después en transacción independiente. Un fallo de transporte o de auditoría posterior al commit no revierte lo ya confirmado ni cambia el 202. Logs solo con tipo de excepción/correlación; sin correo, secreto, contraseña o SQL. No hay cola/outbox persistida ni reintento automático: ante interrupción o falta de entrega se solicita otro enlace, dentro de los límites.

### Pruebas y alcance

`RecuperacionClaveTest` cubre los cuatro roles, privacidad, claves, estados/vínculos, fronteras de vigencia, propósito/reemisión, revocación multidispositivo, bloqueo preservado, fallos SMTP/auditoría y coordinación con CU-05/acceso/rol. `AutenticacionConcurrenteTest` amplía las barreras PostgreSQL para consumos/emisiones simultáneos, emisión frente a consumo, cambio ordinario, desactivación y sesión tardía. `tests/Fixtures/recuperacion-http.php` es solo el servidor loopback de pruebas, con validación de base/rol/esquema aislados y transporte simulado.

```powershell
php artisan test --compact --no-ansi tests/Feature/RecuperacionClaveTest.php tests/Feature/AutenticacionConcurrenteTest.php
php artisan test --compact --no-ansi
php artisan route:list --path=api/auth -v
```

Documentación sincronizada: análisis §18 y contexto §17.21. DER contrastado, sin tablas, atributos, relaciones o migraciones nuevas: 28 entidades, 239 atributos, 43 relaciones. Diagrama global pendiente del cierre backend en fase 8. CU-06 tiene implementación backend con correo simulado; entrega real Brevo e integración React pendientes. La próxima fase del plan es la 6, cuyos parámetros funcionales deben concretarse antes de implementarla.

Comprobaciones de fase 5: Composer validate/autoload PSR-4 estrictos, Pint de 61 PHP del alcance, sintaxis de 66 PHP/Blade, UTF-8/cercas/JSON documentales y git diff --check correctos. Registro efectivo: 15 rutas, 20 combinaciones método/dominio/URI, sin duplicados. Lectura de desarrollo conserva mailer array, siete usuarios, dos eventos, cero tokens y 29 migraciones; no se ejecutaron seeders, migraciones de desarrollo ni envíos reales.

**Suite completa de fase 5: 480 pruebas y 4445 aserciones correctas** (152,40 s, margen de 300 s), con PostgreSQL aislado y correo simulado. Incluye las 36 pruebas de recuperación y seis carreras nuevas, además de toda la regresión previa.

## Adaptación a la API HTTP de Brevo — 2026-10-19

Cambio aprobado del transporte de los correos de seguridad de CU-03/CU-06. Se conserva el flujo:

```text
Service del CU → afterCommit → CorreoSeguridadService → Mailable/plantilla
    → mailer brevo → BrevoHttpTransport → cliente HTTP Laravel → API Brevo
```

`src/shared/utils/BrevoHttpTransport.php` adapta el correo ya renderizado a sender/to/subject/htmlContent. Se registra mediante un puente de infraestructura en `app/Providers/AppServiceProvider.php`; `config/mail.php` selecciona brevo-http y `config/services.php` lee BREVO_API_KEY. Conserva InvitacionPersonalMail y RecuperacionClaveMail, sus fragmentos de enlace y las respuestas/auditoría existentes. El transporte se limita a un destinatario de seguridad, sin copias o adjuntos. No incorpora dependencias ni migraciones.

Para habilitarlo en una instalación, configurar privadamente los valores siguientes, sin copiar secretos a documentación o al frontend:

```dotenv
SEGURIDAD_MAILER=brevo
BREVO_API_KEY=
MAIL_FROM_ADDRESS=remitente_verificado@example.test
MAIL_FROM_NAME="Clínica Vida Salud"
```

BREVO_API_KEY debe completarse con una clave nueva privada y MAIL_FROM_ADDRESS con el remitente real autorizado. El ejemplo no es una cuenta configurada. Tras editar el entorno local, ejecutar `php artisan config:clear`. MAIL_FROM del proyecto anterior corresponde aquí a MAIL_FROM_ADDRESS; MAIL_PROVIDER y RECOVERY_ENABLED no se utilizan. BREVO_SMTP_USERNAME/BREVO_SMTP_KEY dejan de ser necesarios para este transporte. El valor predeterminado sigue en array hasta habilitar explícitamente Brevo; no se configuraron secretos ni se realizó una entrega real durante la adaptación.

`BrevoHttpTransportTest` usa mailer Laravel real con Http::fake: valida endpoint/cabeceras/cuerpo, tiempos y HTTPS, 201/messageId, errores 400/401/429/503, redirección rechazada, estados inesperados, confirmación inválida y excepción de conexión/timeout sin datos externos encadenados. Las pruebas de invitación y recuperación comprueban aceptación/rechazo HTTP posterior al commit y reemisión, manteniendo la respuesta pública genérica de recuperación. Tests\TestCase y los workers bloquean peticiones HTTP externas sin simulación explícita; la prueba de navegador loopback conserva su transporte controlado.

Regresión focalizada: **94 pruebas y 1103 aserciones correctas** (14,84 s), con HTTP simulado y PostgreSQL aislado donde corresponde. Comando reproducible:

```powershell
php artisan test --compact --no-ansi tests/Feature/BrevoHttpTransportTest.php tests/Feature/InvitacionPersonalTest.php tests/Feature/RecuperacionClaveTest.php
```

Documentación sincronizada: contexto §17.22 y análisis §19, con colaboraciones actualizadas en §§15/18. El DER mantiene 28 entidades, 239 atributos y 43 relaciones; diagrama global pendiente de fase 8, sin cierre nuevo del módulo. Referencia del proveedor: https://developers.brevo.com/reference/sendtransacemail.

**Suite completa tras la adaptación: 501 pruebas y 4575 aserciones correctas** (145,67 s). Composer validate y autoload PSR-4 estrictos, sintaxis/Pint de los 14 PHP afectados, UTF-8/cercas/JSON documentales y git diff --check correctos. Revisión de desarrollo de solo lectura: transporte Brevo brevo-http, seguridad todavía array y API key sin configurar; siete usuarios, dos eventos, cero tokens y 29 migraciones. Activación del envío real y entrega a buzón pendientes de configuración privada; durante esta tarea solo se realizaron envíos simulados.

## Fase 6 backend — credencial temporal del personal, CU-03 (2026-10-19)

Política aprobada por el usuario e implementada en `CredencialTemporalService`, con validación reutilizable en `business/validators/CredencialTemporalValidator.php` e integración en login, sesiones y administración de estado/rol. Detalle de clases/colaboraciones en análisis §20; reglas §4.4 y contexto §17.23. Completa el bloque backend de restablecimiento del personal; no completa CU-04 ni el cierre integrado del módulo o React.

### Emisión administrativa y entrega presencial

```http
POST /api/usuarios/{id}/credencial-temporal
Content-Type: application/json
```

```json
{
  "identidad_verificada": true
}
```

Requiere Administrador con sesión **normal**, origen SPA autorizado, CSRF válido y límites. ID BIGINT de ruta; no admite IDs/campos privilegiados alternativos en cuerpo. El Administrador debe comprobar presencialmente identidad del trabajador antes de declarar true. Solo personal Activo/previamente verificado/con vínculo legítimo: Administrador, Recepcionista o Médico; nunca Paciente, cuenta Pendiente o Desactivada. Médico profesionalmente inactivo conserva este acceso si su cuenta sigue Activa.

Respuesta **201** con `data.id_usuario` como cadena, `data.credencial_temporal` y `data.expira_en`; `Cache-Control: no-store, private`. La credencial de **20 caracteres aleatorios** se entrega **una sola vez al Administrador**, para comunicación presencial. No se puede volver a consultar en listados/detalles ni se envía por correo; no registrar, guardar en el navegador ni tratarla como contraseña permanente. El servidor conserva solo HMAC-SHA256 separado por propósito en TokenAcceso/CredencialTemporal.

- **30 minutos** desde emisión para ingresar y completar; login/reingreso no prolongan vigencia.
- Emisión conserva hash anterior pero impide usarlo, marca cambio obligatorio, revoca todas las sesiones e invalida anteriores temporales/recuperaciones. No modifica bloqueo, intentos, correo, rol, identidad ni ficha/Médico.
- Protege al último Administrador con el protocolo global existente antes de bloquear actor/objetivo. Restricción de cuenta propia solo procede si queda otro Admin habilitado y cierra su propia sesión normal.
- **60 segundos** entre emisiones y **cinco emisiones/hora/cuenta**, compartidos entre Administradores/IP. Cada nueva emisión invalida la anterior.

### Login, estado restringido y cambio definitivo

Se ingresa por el **POST `/api/auth/login` existente**, con `correo_acceso` y `password` temporal. Aplica el bloqueo aprobado de tres errores/diez minutos y respeta bloqueos vigentes. Devuelve únicamente:

```json
{
  "data": {
    "acceso": "cambio_clave",
    "cambio_clave_obligatorio": true
  }
}
```

GET `/api/auth/me` restaura ese mismo estado mínimo en sesión restringida, sin perfil/rol/permisos ordinarios. En sesión normal conserva su contrato anterior. El payload de sesión permanece cifrado y solo añade referencia técnica del token/tipo de contexto. Se admite reingreso antes de completar dentro de la vigencia, con **una sola sesión restringida vigente**: el ingreso posterior revoca la anterior. La credencial se consume únicamente al confirmar el cambio definitivo.

```http
PUT /api/auth/password/temporal
Content-Type: application/json
```

Entrada: `password` y `password_confirmation`, mínimo 12 caracteres y los mismos límites técnicos de ClaveRequest/bcrypt; espacios/caso/Unicode preservados. No requiere contraseña anterior y la cuenta procede exclusivamente de la sesión. Solo sesión restringida vigente/origen/CSRF; una sesión normal recibe 403. Al confirmar devuelve **204 sin cuerpo**: guarda definitiva, consume/invalida temporales/recuperaciones, elimina cambio obligatorio y revoca/cierra sesiones en una transacción con auditoría. Exige **nuevo login** con la contraseña definitiva; no convierte automáticamente la sesión restringida en normal.

PUT `/api/auth/password` ordinario y las operaciones normales mantienen ExigirSesionNormal: una restringida válida recibe **403 AUTH_CAMBIO_CLAVE_OBLIGATORIO**. POST `/api/auth/logout` con sesión restringida vigente invalida la credencial, conserva cambio obligatorio y exige reemisión. Vencer sin completar también exige reemisión, sin restaurar el hash anterior. Cerrar una pestaña sin pedir logout no cierra la sesión del servidor; se permite el reingreso aprobado hasta vencer.

CU-06 no emite ni consume recuperaciones durante cambio obligatorio, y no elimina esa marca. Cambios administrativos de estado/rol invalidan temporales y sesiones; reactivar no restablece credenciales. Service revalida Usuario→Token/sesión persistida tras esperar bloqueos y vigencia tras calcular hash, protegiendo contra completados dobles, CU-05/CU-06 y cambios concurrentes. Las emisiones toman global→actor→objetivo→tokens; no se invierte el protocolo del último Admin.

### Errores, límites y evidencia

409 `CREDENCIAL_TEMPORAL_NO_PERMITIDA` para cuenta no elegible, 409 `ULTIMO_ADMINISTRADOR`, 404 objetivo inexistente, 422 campos inválidos, 403 sin permiso/origen y 429/Retry-After para límites. Login inválido conserva 401 genérico. Sesiones eliminadas pueden responder 419 por CSRF antes de autenticación en escrituras; refrescar CSRF no recupera acceso y me devuelve 401. Limitador técnico separado por acción: 20 solicitudes/minuto/IP y cinco/minuto/actor, HMAC/ControlIntentos/config/seguridad.php, sin ampliar ventanas ni bloquear login.

Pruebas focalizadas **67 pruebas/1042 aserciones correctas** (36,42 s): 21 de `CredencialTemporalTest` y 46 de `AutenticacionConcurrenteTest`, incluidas regresiones. Usan cookies/CSRF reales, PostgreSQL aislado, barreras/procesos independientes y correo simulado. Cubren roles/identidad, entrega/consumo, vencimiento/logout/reingreso único, reemisión/cupo compartido, bloqueo, último Admin paralelo/cruzado, CU-05/CU-06 concurrentes, revocación entre middleware/Service, guardado tardío y rollback de auditoría sin secretos.

```powershell
php artisan test --compact --no-ansi tests/Feature/CredencialTemporalTest.php tests/Feature/AutenticacionConcurrenteTest.php
```

No se requiere migración nueva: reutiliza propósito ya aplicado, **28 entidades/239 atributos/43 relaciones**, con referencia técnica de sesión en payload cifrado nativo. Documentación funcional, análisis, contexto y nota del DER sincronizados. El diagrama global de clases sigue pendiente de fase 8, al cerrar el módulo; siguiente entrega fase 7A/CU-04 nuevo y después 7B/vinculación.

**Configuración privada posterior a la adaptación HTTP:** por autorización expresa ya se guardaron API key/remitente/nombre en `.env` ignorado, se habilitó `SEGURIDAD_MAILER=brevo` y se comprobó carga efectiva tras config:clear, sin reproducir secretos. No se ha verificado externamente remitente/entrega ni enviado correo real. El usuario aplazó esa prueba al recorrido integrado con React; las pruebas automatizadas siguen simuladas. Esta fase no envía correo ni modifica `.env`.

**Cierre verificado de fase 6:** suite completa **538 pruebas y 5234 aserciones correctas** (161,61 s). Composer validate/autoload optimizado PSR-4 estrictos, Pint y sintaxis de 21 PHP correctos. Registro efectivo de 17 rutas/22 combinaciones método/dominio/URI sin duplicados (15 API y dos de infraestructura); `.env` ignorado. Formato/JSON/referencias documentales y git diff --check revisados al finalizar. Sin migraciones/seeders de desarrollo ni envío real; fase 6 backend completada y siguiente entrega 7A.

## Fase 7A backend — registro de Paciente nuevo, escenario A de CU-04

Implementa el registro nuevo y verificación de correo, conservando CI obligatorio, proceso de 24 h y activación atómica. Esta entrega no vinculaba fichas históricas automáticamente; escenario B/código de Recepción se incorporan en fase 7B, descrita a continuación. Evidencia histórica del análisis §21, reglas §4.1, CU-04 y contexto §17.24.

### Contratos

| Endpoint | Entrada / resultado |
| --- | --- |
| POST `/api/auth/registro` | Datos de registro y contraseña/confirmación. 202 genérico; cookie de contexto cifrada/HttpOnly. |
| POST `/api/auth/registro/reenviar` | Contexto de cookie, sin ID/correo para tomar un proceso ajeno. 202 idéntico, sin confirmar emisión/entrega. |
| POST `/api/auth/registro/verificar` | codigo como string de seis dígitos y cookie protegida. 204 al crear cuenta/ficha y consumir código; exige login posterior. |

Inicio acepta:

```json
{
  "documento_identidad": "000123-A lp",
  "nombres": "Paciente",
  "apellidos": "Ficticio",
  "fecha_nacimiento": "1990-01-01",
  "telefono": "70000000",
  "sexo": null,
  "correo_acceso": "paciente@example.test",
  "password": "Frase ficticia larga 123",
  "password_confirmation": "Frase ficticia larga 123"
}
```

CI, nombres, apellidos, nacimiento no futuro, teléfono y correo/contraseña son obligatorios. Sexo, dirección y correo de contacto son opcionales; contacto no se copia automáticamente desde correo de acceso. No se admiten rol/estado/IDs/datos_registro u otros campos adicionales en inicio. Política de contraseña compartida con ClaveRequest. CI conserva caso, signos, espacios interiores y ceros; solo se recortan espacios ASCII exteriores, string máximo 50 sin caracteres de control. No se inventa un patrón civil ni catálogo de sexo/edad/país.

Todos requieren origen SPA permitido y CSRF. Sesión autenticada: 409 AUTH_SESION_EXISTENTE. La cookie `clinica_registro_paciente` es HttpOnly, cifrada por Sanctum/EncryptCookies, host-only y de 24 h; Secure/SameSite según configuración. Contiene secreto opaco de 256 bits; la base conserva solo HMAC como metadato de datos_registro. No es sesión normal ni permite consultar perfil/historia. Conservar cookies en Axios; no extraer ni guardar secretos en localStorage. La sesión CSRF puede renovarse sin perder ese contexto de registro.

### Reintentos, códigos y activación

- Inicio y reenvío siempre responden solicitud recibida y posible código, aclarando que **no confirman envío** y orientando a reenvío/Recepción/acceso/vinculación. No revelan si CI/correo pertenecen a ficha/cuenta, ni devuelven ID de proceso o código. Hash de contraseña uniforme; consultas/reserva/código/correo mediante defer después de responder y Content-Length para delimitar cuerpo.
- Antes de verificar solo existe RegistroPacientePendiente/Nuevo. Un reintento con el mismo contexto conserva datos/clave/24 h originales; CI/correo no permiten reanudar otro proceso. Si se pierde la cookie, no se transfiere identidad a otro navegador basándose en esos datos.
- Código de **seis dígitos**, **diez minutos** como máximo, recortado al fin de las 24 h; HMAC ligado al proceso, cinco errores y un solo consumo. Al quinto error invalida ese código, sin crear cuenta.
- Reenvío: **60 segundos** y **cinco emisiones/hora por proceso y correo**, incluso entre contextos/IP o ante envío fallido. Nuevo código distinto de anteriores/invalida previos; no extiende registro. Los cupos se aplican internamente sin cambiar el 202 según existencia.
- Verificar con código correcto revalida unicidad, crea una cuenta Activa de rol Paciente con hash seguro y una ficha activa vinculada, consume código, limpia hash/datos temporales y audita en una transacción. Devuelve **204**, cierra contexto anónimo/borra cookie y exige **nuevo login**, sin autenticación automática.
- Vencimiento/incompatibilidad cierra proceso y limpia secretos al acceder; un nuevo inicio puede crear otro pendiente tras cerrar reservas vencidas. No borra fichas ni historia ni crea antecedentes/preferencias ficticios.

Errores: 422 VALIDACION_ERROR por campos; 422 REGISTRO_VERIFICACION_INVALIDA por código/contexto/conflicto no válido, sin enumeración; 403 origen, 419 CSRF y 429/Retry-After por limitador técnico (20/minuto/IP y cinco/minuto/correo o contexto por acción). No se crea otro rol ni se habilita cuenta del personal.

### Capas, concurrencia y correo

RegistrarPacienteRequest/VerificarRegistroPacienteRequest → RegistroPacienteController → RegistroPacienteService → RegistroPacienteRepository/UsuarioRepository → modelos existentes. La utilidad RegistroPacienteContexto concentra HMAC y VerificacionPacienteMail renderiza la plantilla de seguridad; el transporte HTTP Brevo se reutiliza sin dependencias nuevas.

Las transacciones comparten global PostgreSQL `(736202, 1)` antes de proceso/código; el correo sale solo afterCommit y fuera del bloqueo. Futura vinculación/altas administrativas que compitan por reservas deberán reutilizar el protocolo. Las UQ de CI/correo/cuenta siguen siendo barrera final para escritores externos: colisión 23505 revierte cuenta/ficha/consumo y devuelve 422 seguro. Fallo necesario de auditoría/escritura hace rollback íntegro; envío fallido conserva pendiente reintentable, se audita y nunca se anuncia entregado. Sin cola/outbox ni reintentos HTTP automáticos; comprobar buffering/proxy/finalización en despliegue para defer.

`RegistroPacienteTest`: **31 pruebas/323 aserciones correctas**. Se verificaron también seis carreras nuevas en `AutenticacionConcurrenteTest` junto con las previas, incluyendo conflictos reales de índices con altas administrativas/personal aún no confirmadas. Cubren código/proceso/contexto, normalización/roles, fronteras/límites, reanudación, sesión expirada, consumo único, cinco errores concurrentes, reenvío, rollback de auditoría y Brevo HTTP rechazado simulado. Ningún correo real se envió.

```powershell
php artisan test --compact --no-ansi tests/Feature/RegistroPacienteTest.php tests/Feature/AutenticacionConcurrenteTest.php
```

Documentación sincronizada en contexto/análisis/nota funcional del DER y corrección de referencia antigua de fase 6 en requerimientos. Sin nueva migración ni cambio de inventario: **28 entidades/239 atributos/43 relaciones**. Diagrama global pendiente de fase 8, CU-04 todavía parcial y entrega real Brevo/React posteriores conforme a lo acordado.

**Cierre verificado de fase 7A:** suite completa **575 pruebas y 5648 aserciones correctas** (178,35 s). Composer validate/autoload optimizado PSR-4 estrictos, Pint de 18 PHP, sintaxis de 19 PHP/Blade y 20 rutas/25 combinaciones método/dominio/URI sin duplicados. Formato/JSON/referencias documentales y git diff --check correctos; `.env` ignorado, sin migraciones/seeders de desarrollo ni correos reales. Siguiente entrega 7B.

## Fase 7B backend — vinculación histórica de CU-04 y emisión parcial de CU-07

La vinculación conserva la ficha y su historial: Recepción comprueba presencialmente identidad/CI y entrega una autorización; el Paciente confirma después su propio correo antes de crear la cuenta. Ficha inactiva puede vincularse sin activarse ni habilitar nuevas reservas. El CRUD administrativo restante de CU-07 se desarrollará posteriormente.

### Contratos para la integración React posterior

| Endpoint | Entrada | Resultado |
| --- | --- | --- |
| POST `/api/pacientes/{id}/codigo-activacion` | Solo `identidad_verificada: true`; ID de ficha de la ruta. Sesión normal de Recepcionista. | 201 con `data.id_paciente` como string, `codigo_activacion` y `expira_en` ISO-8601. Secreto visible una vez para entrega presencial; no-store. |
| POST `/api/auth/registro/vincular` | `documento_identidad`, `codigo_activacion`, `correo_acceso`, `password`, `password_confirmation`. Solicitante sin login. | 202 idéntico al registro Nuevo, sin confirmar existencia ni envío; cookie protegida de contexto. |
| POST `/api/auth/registro/reenviar` | Cookie de contexto, sin selector de proceso/cuenta. | Mismo 202 genérico para A/B. |
| POST `/api/auth/registro/verificar` | `codigo` de correo de seis dígitos y cookie. | 204 al confirmar el vínculo y consumir ambos códigos atómicamente. Cierra contexto/elimina cookie; exige nuevo login. |

Los endpoints usan origen SPA permitido y CSRF; Axios debe conservar cookies. No guardar códigos ni contexto en localStorage/sessionStorage. La declaración de identidad verificada representa la comprobación presencial de Recepción, no una validación automática de identidad por el documento. Emisión no admite cuentas ya vinculadas o fichas sin CI; Administrador/Médico/Paciente no pueden emitir. El acceso público exige CI/código/correo/clave y rechaza IDs, rol/estado, datos de perfil y otros campos ajenos. CI mantiene caso/signos/interiores/ceros y solo recorta espacios ASCII exteriores; también localiza fichas históricas con esa normalización sin reescribirlas.

### Autorizaciones, reserva y conservación

- Código presencial: **12 caracteres criptográficos alfanuméricos**, **24 horas**, **cinco errores**, **un solo uso**. Solo se persiste HMAC ligado a la ficha/separado por propósito. Reemplazarlo invalida anteriores y sus reservas/verificaciones de correo, limpiando secretos temporales en transacción.
- Inicio vincula el pendiente a la ficha/código originales y reserva exclusivamente para la cookie cifrada/HttpOnly/host-only de 7A. No consume aún el código presencial ni crea cuenta. El proceso vence en el plazo original de Recepción. Otra cookie no toma la reserva; la pérdida del contexto requiere asistencia/reemplazo presencial, sin transferencia por CI/correo.
- Reintento propio conserva correo, hash de contraseña y plazo; requiere autorización correcta y solo intenta reenvío controlado. El quinto error presencial invalida la autorización. Agotar o vencer el principal exige nueva comprobación presencial.
- Código del correo: **seis dígitos**, hasta **diez minutos** recortados al plazo principal, **cinco errores**. Reenvío **60 segundos/cinco emisiones por hora por proceso y correo**, compartido entre escenarios A/B y aun tras sustituciones o envíos fallidos. Sustituye el código anterior; no amplía las 24 horas principales. Agotar el correo permite reenvío mientras el principal siga válido.
- Confirmación comprueba ficha sin cuenta, CI original, ambos códigos y unicidad de correo; crea Usuario Activa/verificada de rol Paciente y revalida vigencias tras posibles esperas de escritura. Solo cambia el vínculo id_usuario y timestamp de la ficha; consume ambos códigos, limpia proceso y registra metadatos seguros. Conserva identificador, código, datos administrativos, estado activo/inactivo y todas las relaciones clínicas/administrativas.

### Capas, errores y concurrencia

Emisión: `EmitirCodigoActivacionRequest` → `CodigoActivacionController` → `CodigoActivacionService` → `CodigoActivacionRepository`/`RegistroPacienteRepository`; el Service revalida sesión/rol con `AutenticacionService` y audita. Vinculación: `VincularPacienteRequest` → `RegistroPacienteController` → `RegistroPacienteService`, compartiendo reenvío/verificación con 7A. `CodigoActivacionValidator` conserva las comprobaciones de documento/vigencia; `RegistroPacienteContexto` agrega HMAC con propósito de Recepción. Rutas en `pacientes.php` y `auth.php`, registradas una sola vez.

El global PostgreSQL `(736202,1)` precede ficha/proceso/códigos; emisión también revalida actor bajo bloqueo después de esperar. UQ parciales de ficha/código/CI/correo y finales de cuenta evitan reservas/vínculos duplicados. Emisión simultánea deja una sola autorización vigente; reemplazo y confirmación se serializan: si se vincula primero, no se reemite; si se sustituye primero, no se consume la autorización antigua. Error de escritura/UQ/auditoría hace rollback de cuenta/vínculo/consumos e invalidaciones. Correo solo afterCommit/fuera de locks; fallo mantiene proceso reintentable. No hay envíos reales durante pruebas.

Inicio/reenvío públicos mantienen el 202 sin distinguir CI/correo/código elegibles y sin confirmar entrega. Verificación: 422 `REGISTRO_VERIFICACION_INVALIDA` genérico. Emisión: 401 sesión, 403 rol/origen/restricción, 404 ficha inexistente, 409 `ACTIVACION_NO_PERMITIDA`, 419 CSRF, 422 campos y 429 límite técnico con Retry-After. Límites técnicos reutilizados: 20/minuto/IP y cinco/minuto por CI en vincular, por contexto en reenvío/verificar o por Recepcionista en emitir; no son un nuevo plazo funcional de reemisión. Bitácora registra emisión, inicio, intentos y vínculo con referencias seguras, sin códigos, hashes, claves, CI o información clínica.

### Verificación y cierre de fase

`VinculacionPacienteTest` comprueba permisos, identidad/CI, privacidad, sesión/CSRF/origen, reserva ajena, intentos/vencimientos/reenvíos, reanudación, reemplazo y fallos de correo/auditoría. Comprueba ficha inactiva y conservación exacta de filas de citas, consultas, antecedentes/versiones, solicitudes/documentos/resultados ficticios. Incluye normalización de CI histórico y rollback si el correo vence durante la escritura de cuenta. Ocho carreras nuevas en `AutenticacionConcurrenteTest` verifican reserva/consumo únicos, errores concurrentes, reemplazo, correo compartido con Nuevo, revocación de Recepción, colisión externa y emisión paralela.

```powershell
php artisan test --compact --no-ansi tests/Feature/VinculacionPacienteTest.php tests/Feature/RegistroPacienteTest.php tests/Feature/AutenticacionConcurrenteTest.php
```

Documentación sincronizada: `docs/ai/analisis-clases.md`, `contexto-maestro.md` y nota funcional de `diagrama-entidad-relacion.md`. Tres diagramas añadidos y **16 diagramas Mermaid** contrastados con métodos/atributos reales y renderizados. Sin migraciones ni cambios de inventario: **28 entidades/239 atributos/43 relaciones**. Ambos escenarios de CU-04 implementados en backend; el cierre integrado y diagrama global `docs/ai/diagrama-clases.md` corresponden a **fase 8**. React, entrega real de Brevo y CRUD restante de CU-07 siguen pendientes.

**Cierre verificado de fase 7B:** suite completa final **614 pruebas y 6193 aserciones correctas** (207,87 s): 31 pruebas nuevas de vinculación y ocho carreras sobre las 575 previas. Composer validate/autoload optimizado PSR-4 y ambigüedad estrictos, Pint y sintaxis de 19 PHP correctos. **22 rutas/27 combinaciones método-dominio-URI sin duplicados** (20 API y dos de infraestructura). Mermaid 10.9.5/Chrome headless validan los 16 diagramas sin solapamiento de nodos; tres nuevos revisados visualmente y expediente anterior conservado. UTF-8/cercas/JSON documental y git diff --check correctos. Secretos ignorados, sin migraciones/seeders de desarrollo ni correo real. Siguiente entrega: **fase 8 de integración/cierre backend**.

## Fase 8 — cierre integrado del backend de Usuarios y autenticación (2026-10-19)

**Estado:** backend de CU-01–CU-06 implementado e integrado, incluida la emisión de código de Recepción necesaria para CU-04 B. Por decisión expresa del usuario, **`docs/ai/diagrama-clases.md` se elaborará en otro momento**; no se crea ni se declara actualizado. El cierre de React y navegador real es posterior.

### Inventario consolidado para consumir la API

Todas las rutas `/api/*` exigen origen stateful permitido; sus escrituras requieren CSRF. Acceso «público» significa sin login requerido, no sin estas protecciones. Los IDs se proyectan como strings. Contratos de entrada/campos completos, límites y alternativas se conservan en las fases anteriores y en el análisis vigente por CU.

| Método / ruta | Acceso | Resultado de éxito |
| --- | --- | --- |
| POST `/api/auth/login` | Sin sesión previa; correo/contraseña | 200 `data` con usuario público o únicamente estado restringido de cambio obligatorio. |
| GET `/api/auth/me` | Sesión habilitada normal o restringida | 200 misma proyección normal/restringida; restaura estado sin habilitar funciones de otro rol. |
| POST `/api/auth/logout` | Contexto actual, también para limpiar sesión finalizada | 204 y revocación efectiva; cierre restringido invalida su temporal. |
| PUT `/api/auth/password` | Cualquiera de los cuatro roles con sesión normal | 204; renueva sesión actual y revoca anteriores, sin cambiar identidad/rol. |
| POST `/api/auth/password/forgot` | Público | 202 genérico; no confirma existencia/envío; procesamiento diferido. |
| POST `/api/auth/password/reset` | Token de recuperación válido | 204; consume enlace, revoca sesiones y exige nuevo login. |
| PUT `/api/auth/password/temporal` | Solo sesión restringida del personal | 204; consume temporal, cierra contexto y exige nuevo login normal. |
| POST `/api/auth/invitaciones/aceptar` | Público, invitación del personal | 204; establece primera clave/verifica correo/activa, sin login automático. |
| POST `/api/auth/registro` | Anónimo, Paciente nuevo | 202 genérico y cookie de contexto; sin cuenta/ficha definitiva hasta verificar. |
| POST `/api/auth/registro/vincular` | Anónimo, autorización presencial válida | 202 idéntico y reserva exclusiva; conserva ficha original. |
| POST `/api/auth/registro/reenviar` | Anónimo, contexto de cookie | 202 genérico, con límites internos compartidos por proceso/correo. |
| POST `/api/auth/registro/verificar` | Anónimo, contexto y código de correo | 204; crea cuenta/ficha nueva o vincula ficha histórica, exige login. |
| POST `/api/personal` | Administrador normal | 201 `data` y `meta.invitacion`; cuenta Pendiente y envío `aceptado`/`simulado`/`fallido` según resultado real, sin promesa de entrega. |
| POST `/api/personal/{id}/invitacion` | Administrador normal | 200 sobre la misma cuenta pendiente; nueva emisión invalida anterior. |
| GET `/api/usuarios` | Administrador normal | 200 `data/links/meta`; filtros permitidos y paginación limitada. |
| GET `/api/usuarios/{id}` | Administrador normal | 200 proyección administrativa mínima; sin credenciales ni clínica. |
| PATCH `/api/usuarios/{id}/estado` | Administrador normal | 200 nuevo estado; revoca sesiones y conserva vínculo/bloqueo/ficha. |
| PATCH `/api/usuarios/{id}/rol` | Administrador normal; solo transiciones del personal | 200 nuevo rol; revoca sesiones y conserva identidad/vínculos. |
| POST `/api/usuarios/{id}/credencial-temporal` | Administrador normal, identidad comprobada del personal | 201 secreto de entrega presencial única y vencimiento; nunca para Paciente. |
| POST `/api/pacientes/{id}/codigo-activacion` | Recepcionista normal, identidad/CI comprobados | 201 código presencial único y vencimiento; no crea cuenta. |
| GET `/sanctum/csrf-cookie` | Infraestructura de Sanctum | 204 y cookies para las peticiones stateful. |
| GET `/up` | Infraestructura de salud | 200; no representa una operación del dominio. |

`route:list` confirma **22 declaraciones/27 combinaciones método-dominio-URI sin duplicados**; GET incluye HEAD. Los cuatro archivos funcionales se registran una sola vez. Los 20 endpoints API corresponden al alcance anterior; no existe CRUD completo de pacientes ni endpoints clínicos implementados por este cierre.

### Resultado de la revisión y pruebas integradas

Se contrastaron CU/reglas, rutas, Controllers/FormRequests/Resources, Services, Repositories y modelos. Se mantienen permisos fijos, estado de cuenta separado de ficha/profesional, bloqueo 3/10, renovación/revocación de sesiones, protección del último Administrador, propósitos/vigencias de tokens, vinculación única y auditoría sin secretos. Se revisaron CORS con credenciales/origen exacto, CSRF, sesiones database cifradas y respuesta JSON segura con no-store; 204 sin cuerpo y 202 sin promesa de envío. El rechazo aprobado de retiro Médico con obligaciones no comprobables permanece vigente.

`tests/Feature/UsuariosAutenticacionIntegracionTest.php` añade **tres recorridos HTTP integrados**, **190 aserciones**, ejecutados correctamente en 1,68 s:

- Paciente nuevo → verificación → dos sesiones → CU-05 con renovación/revocación e invalidación de recuperación anterior → CU-06 → desactivación/reactivación administrativa → nuevo login/logout.
- Mismo recorrido para una ficha histórica **inactiva**, conservando la ficha original y su estado.
- Invitación de Recepcionista → temporal y exclusión de recuperación → cambio obligatorio/login → cambio autorizado a Médico con revocación → recuperación conservando identidad/rol/vínculo. Invitación y recuperación no intercambian propósitos; el Administrador mantiene su sesión propia.

Usan el Kernel y las cookies/CSRF reales, guard/Store restaurados por petición y sesiones database cifradas en PostgreSQL aislado. Se conserva la limitación técnica de solicitudes, avanzando el reloj entre etapas; Mail/HTTP están simulados. Complementan las pruebas específicas y carreras existentes, incluidas integridad histórica y rollback. No se detectó necesidad de cambiar lógica productiva, contratos o esquema. **28 entidades/239 atributos/43 relaciones**, con DER revisado sin cambios de persistencia.

Comandos de comprobación reproducibles:

```powershell
php artisan test --compact --no-ansi tests/Feature/UsuariosAutenticacionIntegracionTest.php
php artisan test --compact --no-ansi
php vendor/bin/pint --test app bootstrap/app.php bootstrap/providers.php config routes src tests
composer validate --strict
composer dump-autoload --optimize --strict-psr --strict-ambiguous
php artisan route:list
```

**Cierre verificado de fase 8:** suite completa **617 pruebas y 6383 aserciones correctas**, **229,82 s** (margen de ejecución 300 s). Pint comprueba **192 PHP fuente**; sintaxis correcta en **193 archivos** incluyendo artisan. Las cachés PHP generadas por Composer/Laravel se excluyen de la comprobación de estilo de fuentes. Composer validate, requisitos de plataforma y autoload PSR-4/ambigüedad estrictos correctos. Los 16 diagramas existentes pasan Mermaid 10.9.5/Chrome headless, correspondencia de métodos/atributos, 29 anclas/enlaces y conservación del expediente histórico. UTF-8/cercas/JSON documental, inventario README/rutas y git diff --check correctos. Sin cambios productivos/dependencias/persistencia, migraciones/seeders de desarrollo ni envíos reales; secretos privados preservados. **Backend cerrado y diagrama global aplazado según la decisión del usuario.**

### Pendientes posteriores al cierre backend

- **Diagrama global:** aplazado por decisión expresa; los 16 diagramas de análisis por procedimiento siguen disponibles, sin sustituirlo.
- **React/AUT-21:** pantallas, Axios/contexto de sesión, navegación por rol y prueba completa en navegador real, incluidos cookies, recarga, expiración y errores.
- **Correo real:** confirmar aceptación y llegada a buzón de invitación, verificación y recuperación. Brevo HTTP integrado/configurado privadamente, sin envío real durante estas pruebas.
- **Producción:** configuración HTTPS/dominios/CORS/cookies y comprobación de buffering/finalización de respuestas diferidas en la topología real; no hay despliegue verificado.
- **Otros alcances:** CRUD restante de CU-07, módulos siguientes y alternativa del Paciente sin acceso al correo no se incluyen ni se inventan en este cierre.

Documentación actualizada en `docs/ai/contexto-maestro.md` §17.26 y `docs/ai/analisis-clases.md` (cierre integrado y estado por CU). Requisitos/reglas/CU y DER estructural conservados; `diagrama-clases.md` no elaborado. **El backend queda cerrado; el módulo completo de cara al usuario exige la etapa frontend pendiente.**

## Pacientes y perfil personal — fase 1: especificación y diseño (2026-10-20)

**Estado:** fase 1 documental/técnica completada; endpoints nuevos y clases descritos abajo **previstos, aún no implementados**. CU-07 conserva implementada únicamente la emisión de activación; CU-08 no se declara terminado. La evidencia posterior de React/AUT-21 del módulo anterior está en contexto §17.34. No se ejecutaron migraciones ni se modificó el DER en esta fase.

### Alcance aprobado

Matriz de campos explícita en CU-07/CU-08 y VS-PAC-06–10. Recepción corrige identidad presencialmente, administra contacto/emergencia y estado de ficha; el Paciente solo consulta su identidad y modifica contacto/emergencia/recordatorios. Correcciones de identidad y cambios de estado requieren motivo. Ficha inactiva no impide estas operaciones propias si cuenta/sesión son válidas; no habilita reservas. Corrección de CI invalida autorizaciones/procesos de vinculación de esa ficha, sin reasignación de cuenta. Cambiar correo de acceso requiere contraseña y verificación, revoca todas las sesiones y exige login nuevo. Estas precisiones son decisiones del proyecto, no requisitos textuales adicionales del examen.

### Modelo inspeccionado y concurrencia sin nuevo versionado

- Paciente ya tiene `actualizado_en` TIMESTAMPTZ(6), mapeado a instantes inmutables y `$dateFormat = 'Y-m-d H:i:s.uP'`; CI único por btrim, nullable, código único y vínculo Usuario único. ContactoEmergencia tiene FK/UQ por ficha y tres campos, sin timestamp; PreferenciaPaciente tiene FK/UQ, booleano de recordatorios y timestamp. No se necesita otra columna de versión.
- Diseño elegido para fases 2–4: detalle administrativo/perfil devuelve `actualizado_en` con seis decimales y zona explícita; PATCH exige `actualizado_en_original` del detalle. Es una precondición HTTP, **no un atributo persistido nuevo**. Dentro de una transacción se bloquea la fila Paciente, se compara el instante completo y se devuelve 409 si difiere, sin guardar cambios. Un lock por sí solo no detectaría un formulario obsoleto.
- Toda modificación real de ficha, contacto o preferencia por estos servicios debe avanzar el timestamp de Paciente en la misma transacción, incluso cuando solo cambie una relación. El Repository garantizará un instante estrictamente posterior al anterior (reloj actual o anterior + un microsegundo); no depender únicamente del reloj ni del guardado automático de Eloquent. Lectura del agregado bajo bloqueo breve de la misma ficha para no mezclar relaciones nuevas con un timestamp antiguo. No se convierten timestamps a milisegundos JavaScript ni IDs BIGINT a Number. PATCH parcial solo modifica campos presentes; contacto omitido conserva, null elimina el contacto opcional y objeto completo crea/reemplaza sus tres campos.
- RegistroPacienteRepository ya coordina con advisory lock `(736202,1)` y CodigoActivacionRepository bloquea la ficha. Altas/cambios de identidad y operaciones que puedan competir con registro/vinculación reutilizarán ese protocolo: coordinación global antes del actor, ficha, reservas/códigos; revalidación de sesión bajo lock. No bloquear primero ficha y después Usuario, evitando invertir el orden de autenticación. Se revisará el flujo de confirmación CU-04 y sus guardados de timestamp al implementar; las pruebas independientes de concurrencia son condición para acreditar la solución, no un resultado obtenido en fase 1.
- Altas/incorporaciones de CI deben comprobar tanto fichas definitivas como reservas web vigentes; rechazar conflicto sin apropiarse de un registro pendiente ni filtrar datos en flujos públicos. Reservas vencidas se limpian conforme a CU-04. UQ PostgreSQL sigue siendo última barrera y los fallos traducidos conservan rollback completo.

### Contratos API previstos

Todas las rutas nuevas usan cookies/Sanctum, sesión normal vigente, origen SPA exacto, JSON/no-store y CSRF en escrituras. Se mantienen respuestas `data`/`meta` y errores `success:false,error:{code,message,details}`. 401 sesión, 403 rol, 404 recurso, 409 conflicto/edición obsoleta, 419 CSRF, 422 formato/campos no permitidos, 429 con Retry-After, 500 seguro. Ninguna escritura se reintenta automáticamente.

| Método/ruta propuesta | Rol | Entrada y respuesta |
| --- | --- | --- |
| GET `/api/pacientes` | Recepcionista | Filtros opcionales documento_identidad exacto, nombres/apellidos por coincidencia parcial, activo booleano; page/per_page (20 por defecto, máximo 100); orden fijo id descendente. 200 data/meta. Proyección mínima sin credenciales, información clínica ni contacto de emergencia en listado. |
| POST `/api/pacientes` | Recepcionista | Identidad/contacto permitidos y emergencia opcional; CI nullable. Si falta CI, `posibles_duplicados_revisados:true`, declaración de revisión presencial, no prueba automática de identidad. 201 detalle con timestamp. Estado inicial activo, ID/código del servidor y ninguna cuenta creada. |
| GET `/api/pacientes/{id}` | Recepcionista | 200 ficha administrativa, contacto/emergencia, estado, indicador cuenta vinculada y recordatorios solo lectura; actualizado_en. No correo de acceso, credenciales, documentos ni contenido clínico. |
| PATCH `/api/pacientes/{id}` | Recepcionista | Campos administrativos presentes y actualizado_en_original; cambio de identidad exige identidad_verificada:true y motivo. No admite id_usuario, código, estado ni preferencias. 200 detalle actualizado. CI existente no se vuelve NULL. |
| PATCH `/api/pacientes/{id}/estado` | Recepcionista | activo booleano, motivo, actualizado_en_original. 200 detalle. Mismo estado no produce una transición ni auditoría duplicada; precondición se comprueba antes. |
| POST `/api/pacientes/{id}/codigo-activacion` | Recepcionista | **Ya implementado:** identidad_verificada:true, contrato actual conservado. No se añade la precondición nueva a este endpoint. |
| GET `/api/mi-perfil` | Paciente | Ficha derivada de la sesión, sin ID externo. 200 datos propios administrativos, correo de acceso propio solo lectura, emergencia, recordatorios y timestamp. |
| PATCH `/api/mi-perfil` | Paciente | Solo telefono, direccion, correo_contacto, contacto_emergencia, recordatorios_habilitados y actualizado_en_original. 200 perfil actualizado. Sin edición de identidad, estado o vínculo. |
| POST `/api/mi-perfil/correo` | Paciente | current_password, correo_acceso distinto/normalizado. 202 solicitud y resultado seguro del transporte; no declara correo cambiado ni buzón recibido. |
| POST `/api/mi-perfil/correo/reenviar` | Paciente | Sin destino/ID manipulable; solicitud obtenida de la cuenta. 202, o 429/Retry-After. |
| POST `/api/mi-perfil/correo/verificar` | Paciente | codigo como texto de seis dígitos; usuario/solicitud desde sesión. 204 solo tras commit y revocación; nuevo login. |

**Validación prevista:** longitudes del modelo/migraciones: CI 50, nombres/apellidos 120, sexo opcional/libre 32, teléfono 40, correo 254, dirección 2000 siguiendo CU-04; nacimiento AAAA-MM-DD no futuro según fecha de la sede. CI conserva ceros/caso/signos y normaliza solo espacios externos como los flujos existentes. Emergencia: nombre 240, parentesco 80 y teléfono 40, todos requeridos si hay objeto. Motivos administrativos 1–500 caracteres sin contenido clínico. Campos desconocidos, incluidos claves anidadas, se rechazan; no se usa request->all para persistir. Código de Paciente criptográficamente aleatorio único, sin MAX(id)+1. Semántica de búsqueda de nombres y comparación de fechas se probará en PostgreSQL. Sin documento se permiten coincidencias personales tras revisión; nunca unicidad ficticia por nombre/nacimiento/teléfono.

Preferencias expone solo `recordatorios_habilitados`: no aceptar `preferencias_privacidad` arbitrarias ni inventar opciones de privacidad. Se conserva el objeto existente al modificar el booleano; ausencia de preferencia se representa como no configurada (null), sin inventar un valor predeterminado de CU-28. Al crear por elección explícita, objeto vacío y booleano elegido. Detalle administrativo distingue elección inexistente de false.

### Cambio de correo: reutilización y coordinación previstas

VerificacionCorreo ya permite propósito CambioCorreo ligado a Usuario (XOR con registro pendiente), destino, hash, intentos y vigencias. No hace falta una tabla de solicitudes adicional para este diseño. Operaciones de contraseña/estado/rol existentes deberán invalidar verificaciones CambioCorreo en sus transacciones; esa integración aún está pendiente, no se afirma ya implementada. Reutilizar UsuarioRepository, SesionRepository, límites compartidos y CorreoSeguridadService ampliando su método de envío específico, sin reutilizar secretos de otro propósito.

Inicio, sustitución, reenvío y confirmación se serializan por Usuario dentro del protocolo global de identidades y revalidan cuenta/sesión. No reservar silenciosamente un destino compartido: comprobar usuarios definitivos y reservas web vigentes al iniciar y confirmar; un conflicto posterior conserva el correo anterior. La restricción única de Usuario resiste escritores externos; no se invalida el proceso de otra persona para ganar el correo.

El envío externo ocurre fuera de locks SQL; se debe impedir consumo de una emisión hasta aceptación confirmada y evitar que una respuesta de envío tardía reactive una solicitud sustituida. Se reutilizará el estado invalidado de VerificacionCorreo con comprobación del ID exacto/última emisión y del estado de cuenta al completar el envío, sin flag/columna nueva; tras fallo o resultado incierto permanece no consumible. La finalización también comparará una instantánea interna de seguridad del Usuario tomada antes del envío (incluido actualizado_en completo): comprobar solo que siga Activa sería insuficiente ante desactivación/reactivación durante el envío. Cambios de seguridad deben avanzar ese timestamp; no se persiste la instantánea ni se devuelve al cliente. Reenvíos cuentan también las emisiones fallidas y sustituciones (60 s y cinco/hora por usuario/destino, más origen). Pruebas de barreras deben demostrar ausencia de resurrección ante nueva solicitud, contraseña/desactivación o confirmación simultánea antes de cerrar fase 5; si el esquema no basta se informará antes de proponer cualquier cambio.

### Participantes, pruebas y siguientes fases

Previstos: PacienteController/Service/Repository y MiPerfilController/Service con FormRequests/Resources específicos; CambioCorreoController/Service/Repository para fase 5. Reutilizar modelos Paciente/ContactoEmergencia/PreferenciaPaciente/VerificacionCorreo y servicios/repositories de autenticación, vinculación, sesiones, correo y bitácora. No crear clases vacías ni duplicar el registro web. Ampliar BitacoraRepository con operación del módulo PACIENTES en lugar de etiquetar modificaciones de ficha como AUTENTICACION; listas permitidas de eventos/metadatos y proyección segura. Auditoría de modificaciones, motivo y referencias sin copia de CI/contactos/hashes/códigos ni contenido clínico.

Pruebas previstas por fase: permisos de cuatro roles, sin sesión/restringida, 401/403/419/422; alta sin CI/revisión, unicidad normalizada y código generado; límites de campos y errores por campo; conservación de ficha/cuenta/historial; conflictos con reservas CU-04; edición simultánea Recepción/Paciente y cambios solo en relaciones; rollback por auditoría; no filtración clínica/secretos. Cambio de correo añade propósitos, entrega fallida, límites, sustitución, vencimiento/consumo único, unicidad final, revocación de múltiples sesiones e invalidación por CU-03/05/06. Pruebas en PostgreSQL aislado, correo simulado y carreras con procesos independientes. La aceptación/llegada Brevo se acredita aparte.

Fases siguientes: **2** consulta/alta administrativa; **3** actualización/estado/CI; **4** perfil propio; **5** correo de acceso; **6** integración/regresión y cierre. CU-21/CU-22/CU-27 no se implementan anticipadamente ni se declaran cubiertos por este módulo. Análisis incremental y contexto actualizados en fase 1; DER intacto y diagrama global aplazado expresamente.

## Pacientes — fase 2: consulta y alta administrativa (2026-10-20)

**Implementado, solo Recepcionista con sesión normal:** GET `/api/pacientes`, POST `/api/pacientes` y GET `/api/pacientes/{id}`. Emisión `/codigo-activacion` conserva contrato y permisos. La fase 1 anterior documenta el diseño global; PATCH, perfil y cambio de correo siguen pendientes.

- Listado: filtros opcionales CI exacto, nombres/apellidos parciales sin distinguir caso, activo (`0`/`1` en query), page y per_page (20/100 máximo); orden ID descendente y meta Laravel. `%`/`_` se buscan como caracteres literales, no comodines. Cada fila solo tiene id_paciente textual, código, CI nullable, nombres/apellidos y activo.
- Detalle/alta: agrega nacimiento local AAAA-MM-DD, sexo, contacto/dirección, indicador booleano de cuenta vinculada, emergencia opcional, preferencia de recordatorios solo lectura (null si no configurada) y actualizado_en con seis decimales/zona UTC. No expone id_usuario, correo de acceso, credenciales, preferencias de privacidad arbitrarias ni información clínica. Detalle consulta el agregado bajo bloqueo breve de ficha, con actor revalidado dentro de la transacción.
- Alta: datos y límites definidos en fase 1, CI opcional (vacío normalizado a null); sin CI exige `posibles_duplicados_revisados:true`. La declaración acredita la revisión que realiza Recepción, no identifica automáticamente coincidencias ni prueba identidad. Se permite registrar personas distintas con datos semejantes; no se fusionan fichas. Emergencia null/omitida no crea relación; si se proporciona exige nombre/parentesco/teléfono y rechaza claves extra.
- ID y código `PAC-` + 32 símbolos hexadecimales se generan en servidor, ficha inicialmente activa y sin cuenta. Se conservan ceros/caso/signos del CI, normalizando espacios externos; documento definitivo o reservado por proceso web vigente devuelve 409. Reservas vencidas se invalidan dentro de la transacción y sus datos temporales se retiran siguiendo CU-04. No se reserva ni cambia un correo de acceso desde el alta administrativa.
- Coordinación: advisory lock existente de registro/vinculación → actor/sesión revalidados → comprobación de CI/reservas → ficha/emergencia y auditoría atómicas. PostgreSQL UQ sigue siendo última barrera. El conflicto no persiste cambios parciales. Bitácora PACIENTES/PACIENTE_CREADO con referencia de ficha y booleano sin_documento, sin CI/contactos ni secretos; fallo de auditoría revierte ficha/emergencia.
- Clases: PacienteController + CrearPacienteRequest/ListarPacientesRequest (CuentaRequest reutilizado para ID), PacienteListadoResource/PacienteAdministrativoResource → PacienteService → PacienteRepository. Reutilización de AutenticacionService, RegistroPacienteRepository y BitacoraRepository, con método de reserva por CI y evento del módulo. El Controller solo adapta HTTP; validación de formato en Requests, decisión de reservas/rol en Service y SQL/Eloquent en Repositories.

**Verificación focalizada:** 25 pruebas nuevas/223 aserciones correctas en ejecuciones focalizadas: 23 casos de PacienteAdministrativoTest y dos carreras nuevas en AutenticacionConcurrenteTest, reutilizando su worker aislado. Cubren cookies/CSRF real, cuatro roles, sesión revocada y restringida real, alta con/sin CI, duplicados, revisión, campos prohibidos/anidados, búsqueda/paginación/proyección mínima, timestamp completo, 404, rollback y reserva vigente/vencida. Dos procesos independientes prueban un único commit entre altas con igual CI (201/409 y una auditoría) y revalidación de reserva web tras espera por lock. Sin datos reales ni correo externo. Pint de los 14 PHP afectados correcto; route:list confirma tres rutas nuevas y la emisión existente. La regresión completa se registra al finalizar su ejecución.

**Regresión completa finalizada:** `php artisan test --compact --no-ansi` → **642 pruebas y 6606 aserciones correctas**, 190,11 s. Incluye las pruebas anteriores y las 25 nuevas de esta fase, con PostgreSQL aislado y sin envíos externos. Sintaxis de los 14 PHP afectados, UTF-8/cercas Markdown y git diff --check correctos.

**Estado:** fase 2 implementada y verificada; CU-07 sigue parcial. Siguiente fase 3: actualización de ficha, CI y estado con precondición/concurrencia; CU-08 aún pendiente. Sin nuevas migraciones, atributos de versión ni modificación del DER. Análisis/contexto sincronizados; diagrama global aplazado expresamente.

## Pacientes — fase 3: actualización, CI y estado (2026-10-20)

**Implementado, solo Recepcionista normal:** PATCH `/api/pacientes/{id}` y PATCH `/api/pacientes/{id}/estado`. Se mantienen listado/alta/detalle y emisión. No se incorpora edición clínica ni permisos de cuenta.

### Contratos de escritura

- Ambos PATCH requieren `actualizado_en_original` exactamente con el formato del detalle, por ejemplo `2026-10-20T14:00:00.123456Z` (UTC y seis decimales, sin conversión a milisegundos). ID de ruta validado como BIGINT positivo; campos desconocidos y privilegios extra se rechazan. Respuesta 200 con el mismo detalle administrativo, datos y timestamp actualizados.
- PATCH de ficha admite solo los campos administrativos de la matriz: CI, nombres/apellidos, nacimiento, sexo, teléfono, dirección, correo de contacto y contacto de emergencia. Campos omitidos se conservan; emergencia null elimina únicamente esa relación opcional, objeto exige sus tres campos. No admite estado, vínculo, código, preferencias ni credenciales. Cambio efectivo de CI/nombres/apellidos/nacimiento/sexo exige `identidad_verificada:true` y `motivo` administrativo no vacío de hasta 500 caracteres, sin controles. No puede retirarse un CI ya informado. Sin cambios efectivos devuelve la misma ficha sin avanzar timestamp o duplicar auditoría.
- PATCH de estado recibe exclusivamente `activo` booleano, `motivo` obligatorio y precondición. Desactivar/reactivar solo modifica la ficha, no cuenta/bloqueo/sesiones, citas ni autorizaciones históricas. Reactivar no restaura citas. Mismo estado y precondición vigente devuelve 200 sin transición ni evento adicional; si la precondición es obsoleta se rechaza incluso una operación aparentemente repetida.
- Nuevos errores seguros: `PACIENTE_EDICION_OBSOLETA` (409), `PACIENTE_CI_NO_RETIRABLE` (409), `PACIENTE_IDENTIDAD_NO_VERIFICADA` (422). Se conservan 409 por CI ocupado/reservado o unicidad, 401/403/404/419/422 y errores centralizados. Ante conflicto reconsultar y revisar; no reenviar automáticamente la escritura.

Ejemplo de actualización de contacto (sustituir ID/timestamp por los obtenidos del detalle):

```json
{
  "telefono": "70000000",
  "actualizado_en_original": "2026-10-20T14:00:00.123456Z"
}
```

### Concurrencia, identidad y trazabilidad

PacienteService coordina con el advisory lock existente de CU-04, revalida actor/sesión, bloquea la ficha y compara el instante completo **antes de guardar o invalidar autorizaciones**. PacienteRepository.guardar avanza `actualizado_en` al reloj UTC o al anterior + un microsegundo si el reloj no lo supera; también cuando solo cambia emergencia. No se añadió atributo de versión. CodigoActivacionRepository.vincular reutiliza ese guardado monotónico para que una confirmación web deje obsoletos formularios anteriores, sin retroceder el timestamp. Identidad, código, cuenta y resto del historial permanecen estables.

CI nuevo/corregido se comprueba contra fichas y reservas web; una reserva vigente de otra ficha/proceso no se desplaza. Cambio efectivo invalida autorizaciones de Recepción y procesos pendientes de la misma ficha, incluidos códigos de correo y datos/hash temporales. Todo ocurre en una transacción con la corrección, emergencia y auditoría; un fallo revierte también las invalidaciones. La cuenta ya vinculada conserva credenciales/identidad y sesión normal autorizada. La emisión posterior utiliza el CI corregido y conserva contrato.

Bitácora del módulo PACIENTES registra PACIENTE_ACTUALIZADO o PACIENTE_ESTADO_CAMBIADO con referencia ID, nombres de campos modificados y motivo administrativo cuando corresponde; no copia valores anteriores/nuevos de CI o contacto, documentos ni secretos. El motivo es texto administrativo proporcionado por Recepción, no un campo para diagnósticos o historia clínica. Esta regla de uso no equivale a un detector automático de contenido sensible.

**Verificación:** 25 pruebas nuevas/284 aserciones correctas focalizadas (21 de PacienteActualizacionTest y cuatro carreras en AutenticacionConcurrenteTest). Cubren actualización parcial, precondición completa/obsoleta, estado repetido, emergencia y avance monotónico incluso con timestamp futuro, teléfonos que difieren solo por ceros, CI ausente/corregido, identidad presencial/motivo, reservas, invalidación de códigos/procesos, no retirada de CI, campos prohibidos, permisos, CSRF y rollback. La prueba de ficha vinculada conserva cuenta/sesión y cita completa al corregir CI y cambiar estado. Vinculación comprueba avance monotónico y rechazo del formulario anterior. Carreras HTTP con procesos PHP/conexiones PostgreSQL independientes: emergencia frente a contacto, estado frente a contacto, corrección de CI frente a confirmación histórica y dos fichas intentando el mismo CI; sin sobrescrituras o identidades duplicadas.

**Regresión completa:** `php artisan test --compact --no-ansi` → **667 pruebas/6890 aserciones correctas**, 239,84 s. Pint de 12 PHP afectados correcto; seis rutas del módulo confirmadas (dos nuevas), sintaxis/documentación/diff comprobados al cierre. Sin correos externos ni datos reales, migraciones o cambios DER. README/análisis incremental/contexto §17.37 sincronizados; diagrama global aplazado. **Fase 3 implementada y verificada; siguiente fase 4: perfil propio de CU-08.** Consulta de citas anteriores mantiene dependencia de CU-21 y no se presenta implementada por estos endpoints.

## Pacientes — fase 4: perfil propio de CU-08 (2026-10-20)

**Implementado:** GET y PATCH `/api/mi-perfil`, exclusivamente para Paciente con cuenta activa, sesión normal y ficha vinculada. La ficha se obtiene del usuario revalidado en servidor, sin ID externo y sin exigir ficha activa. GET entrega la proyección administrativa propia, emergencia, preferencia de recordatorios, timestamp completo y `correo_acceso` propio de solo lectura. No carga relaciones clínicas ni historial de citas.

PATCH admite únicamente `telefono`, `direccion`, `correo_contacto`, `contacto_emergencia`, `recordatorios_habilitados` y la precondición obligatoria `actualizado_en_original` UTC con seis decimales. Campos omitidos se conservan. Contacto de emergencia null elimina la relación opcional; un objeto exige nombre, parentesco y teléfono sin claves adicionales. Teléfono no puede ser null; dirección y correo de contacto sí. Este último se normaliza y no modifica el correo de acceso. Identidad, estado, vínculos, credenciales y privacidad arbitraria se rechazan con 422; GET también rechaza selectores/campos enviados. Respuestas 200 en `data`, 409 `PACIENTE_EDICION_OBSOLETA` ante snapshot anterior; se mantienen errores centralizados y protección de sesión/rol/origen/CSRF.

Preferencia ausente se representa null y consultar no crea filas ni elige un predeterminado. Una elección explícita true/false crea PreferenciaPaciente con objeto de privacidad vacío; modificar una existente conserva exactamente su objeto de privacidad. La operación configura la preferencia; la generación/retirada de avisos se integra en CU-28. Sin cambios efectivos no se duplica evento ni avanza timestamp.

**Capas y atomicidad:** MiPerfilController/MiPerfilRequest/MiPerfilResource adaptan HTTP y su proyección; MiPerfilService revalida sesión/rol, obtiene y bloquea la ficha propia y compara precondición. PacienteRepository.porUsuario/guardarEmergencia/guardarRecordatorios/guardar encapsula datos. Todas las escrituras, incluidas relaciones y BitacoraRepository.pacienteModificado, se confirman juntas. Se reutiliza avance monotónico del timestamp padre incluso para preferencias/emergencia, interoperable con Recepción. El evento registra responsable/referencia y nombres de campos, sin copiar contacto ni valores de privacidad. La cuenta y sus sesiones se conservan.

**Verificación:** 25 casos de MiPerfilTest y dos carreras HTTP PHP/PostgreSQL independientes: **27 pruebas/261 aserciones focalizadas correctas**. Comprueban aislamiento del titular, ficha inactiva, correo propio solo lectura, campos/permisos/CSRF/cuenta desactivada, edición parcial, conflictos, timestamp futuro con cambio de segundo, emergencia, preferencia ausente/false/true, conservación de privacidad, no-op, auditoría segura y rollback completo. Carreras: preferencias frente a emergencia propia y frente a edición de Recepción, con una escritura 200 y otra 409, sin relaciones parciales ni duplicadas.

**Regresión completa:** `php artisan test --compact --no-ansi` → **694 pruebas/7151 aserciones correctas**, 241,65 s. Pint de nueve PHP afectados correcto; sintaxis, rutas, Markdown y diff revisados al cierre. Análisis incremental y contexto §17.38 sincronizados. Fase 4 implementada/verificada; CU-08 mantiene pendiente el cambio verificado de correo de acceso de fase 5. Sin migraciones, nueva columna de versión o cambios DER; diagrama global continúa aplazado expresamente.

## Pacientes — fase 5: cambio verificado del correo de acceso (2026-10-20)

**Implementado:** tres POST exclusivos del Paciente con cuenta activa, ficha vinculada y sesión normal. La ficha puede estar inactiva. Usuario/destino pendiente se resuelven en servidor; se rechazan campos adicionales y se aplican origen SPA, CSRF, no-store y límites por IP/usuario.

| Endpoint | Entrada permitida | Resultado |
| --- | --- | --- |
| `/api/mi-perfil/correo` | `correo_acceso` normalizado y diferente del vigente; `current_password` exacta | 202 `data:{envio,expira_en}`. Sustituye la solicitud anterior, sin cambiar todavía ningún correo de la cuenta/ficha. |
| `/api/mi-perfil/correo/reenviar` | Sin campos | 202 con el mismo contrato, destino derivado de la última solicitud. Nuevo código y nueva vigencia de diez minutos; el anterior queda invalidado. |
| `/api/mi-perfil/correo/verificar` | `codigo` como texto de seis dígitos | 204 sin cuerpo solo tras confirmar cambio, consumo, auditoría y revocación de todas las sesiones. Exige nuevo login. |

`envio` puede ser `simulado`, `aceptado`, `fallido` o `pendiente` (si la operación está dentro de una transacción superior aún no confirmada). `aceptado` solo confirma aceptación del transporte, no llegada al buzón. Un fallo o respuesta tardía descartada mantiene el código no consumible. No se devuelve el código, hash, dirección propuesta ni un enlace secreto. `expira_en` usa UTC/seis decimales. El correo anterior permite login hasta la confirmación; contacto y contraseña se conservan en todo el flujo.

Código aleatorio criptográfico de seis dígitos, HMAC separado por propósito/Usuario y sin repetir secretos históricos de esa cuenta. Se comprueban diez minutos con límite final exclusivo, máximo cinco errores y consumo único. Reenvío/inicio nuevo respetan 60 segundos y cinco emisiones por hora por usuario **y** destino, incluidas sustituciones y fallos; además, middleware configurable limita intentos por acción/IP/usuario a 20/5 por minuto como control técnico. Cambiar de destino no evita el límite por cuenta. Código agotado o solicitud invalidada por seguridad requiere nuevo inicio con contraseña; un fallo de transporte sí admite reenvío controlado. Logout ordinario no cancela una solicitud válida.

Errores: `AUTH_CLAVE_ACTUAL_INVALIDA` (422), `CAMBIO_CORREO_INVALIDO` (422), `CAMBIO_CORREO_NO_DISPONIBLE` (409, genérico sin revelar al propietario), `AUTH_LIMITE_SOLICITUDES` (429/Retry-After) y errores comunes de sesión/rol/CSRF/formato. Inicio y confirmación comprueban usuarios definitivos y reservas web vigentes sin apropiarse de ellas. Dos cuentas pueden solicitar el mismo correo respetando límites: la solicitud no reserva su propiedad; solo una puede confirmarlo. La unicidad PostgreSQL protege también frente a escritores externos y revierte el cambio parcial.

### Persistencia y coordinación sin cambiar el DER

CambioCorreoService coordina con RegistroPacienteRepository (advisory lock global de identidades) → Usuario revalidado/bloqueado → última VerificacionCorreo de propósito CambioCorreo. CambioCorreoRepository reutiliza exclusivamente columnas existentes y la FK de Usuario. Convención interna documentada: una emisión recién creada tiene `invalidado_en = enviado_en`, por lo que **no se consume** durante transporte; después de commit se envía fuera de locks. Solo al confirmar aceptación/simulación se retira esa marca, comprobando el ID exacto de última emisión, vigencia y snapshot interno de Usuario/vínculo. Snapshot HMAC incluye `actualizado_en` completo y nunca sale del servidor. Una invalidación definitiva usa un instante estrictamente posterior a `enviado_en`, incluso con reloj congelado; incluye filas todavía en transporte. Un callback sustituido/cancelado no vuelve a habilitarse.

UsuarioRepository.guardar ahora avanza `actualizado_en` estrictamente al reloj UTC o al anterior + un microsegundo, conservando precisión existente. ClaveService, RecuperacionClaveService (al completar, no al solicitar), AdministracionCuentaService (estado/rol), CredencialTemporalService e InvitacionPersonalService invalidan los cambios pendientes dentro de sus transacciones. Cambio de correo confirmado invalida recuperaciones y demás cambios pendientes, revoca todas las sesiones y cierra la actual, sin login automático ni alteración del bloqueo temporal, ficha o historial.

CorreoSeguridadService.enviarCambioCorreo utiliza CambioCorreoMail y la vista de seguridad `emails/cambio-correo`, mediante el transporte array local o Brevo ya existente. Auditoría de AUTENTICACION: CAMBIO_CORREO_SOLICITADO, CAMBIO_CORREO_ENVIO y CAMBIO_CORREO_VERIFICADO con resultado y referencias/causas seguras, sin códigos, destinos, contraseñas ni contenido clínico. Fallar auditoría de emisión revierte antes de enviar; fallar confirmación revierte correo/consumo/revocación. Un fallo de transporte/confirmación de aceptación se registra de forma segura y deja la emisión inhabilitada.

### Verificación y siguiente entrega

**26 pruebas nuevas/321 aserciones focalizadas correctas:** 21 casos de CambioCorreoTest y cinco carreras en AutenticacionConcurrenteTest. Cubren normalización, contraseña, cuenta con ficha inactiva, conservación de ficha/clave/contacto, logout/nuevo login, múltiples sesiones, recuperaciones, errores/vigencia/agotamiento, sustitución, reenvíos y límites compartidos, fallos, rollback de auditoría, propósito separado, permisos/CSRF/campos y colisión externa de unicidad. Integración verifica cancelación por contraseña/recuperación/rol. Carreras reales con procesos PHP y conexiones PostgreSQL independientes: transporte pausado frente a sustitución, contraseña o desactivación/reactivación; dos confirmaciones de una cuenta; dos cuentas confirmando igual destino. Durante transporte no hay transacción/locks y verificar no consume ni gasta errores. Un envío tardío no reactiva la emisión anterior; confirmaciones tienen un solo ganador.

**Regresión completa:** `php artisan test --compact --no-ansi` → **720 pruebas/7472 aserciones correctas**, 264,80 s. Pint 25 PHP afectados correcto; sintaxis/rutas/Markdown/diff comprobados al cierre. Los esquemas aislados de las pruebas de cuentas/credenciales incluyen ahora las dependencias reales de VerificacionCorreo. Sin migraciones, atributos nuevos, cambios DER o correo externo real. README/análisis incremental/contexto §17.39 sincronizados; diagrama global aplazado expresamente. **Fase 5 implementada/verificada; siguiente fase 6: integración y revisión de cierre del módulo.** Brevo real y producción mantienen su aceptación independiente; frontend de Pacientes sigue pendiente.

## Pacientes — fase 6: integración y cierre del plan backend (2026-10-20)

**Plan backend de fases 1–6 completado para el alcance desarrollado de CU-07/CU-08.** Se revisaron contratos, campos por actor, separación ficha/cuenta, snapshot/locks, integración con CU-04 y credenciales, transporte afterCommit, auditoría y conservación de historia. Las once rutas de Pacientes/perfil mantienen cookies/Sanctum, cuenta habilitada, sesión normal y rol fijo; las tres de correo añaden limitador de solicitudes. Frontend, aceptación real Brevo y producción conservan sus entregas independientes. La consulta de citas anteriores de RF02 sigue asignada a CU-21: no se declara RF02 íntegramente terminado por cerrar este plan.

### Ajuste encontrado en revisión

PacienteService.actualizar reconoce como **sin cambio efectivo** el mismo CI normalizado que una ficha histórica conserva con espacios externos. No choca con la propia ficha, no exige declarar otra corrección, no normaliza silenciosamente el dato almacenado y no invalida códigos/procesos ni avanza timestamp por ese campo. Una corrección a un CI distinto sigue exigiendo identidad/motivo e invalida autorizaciones pendientes. Se conserva la normalización aprobada (solo espacios externos, sin cambiar ceros/caso/signos).

### Recorridos integrados verificados

PacientesIntegracionTest ejecuta dos recorridos HTTP con CSRF/sesión reales en esquemas PostgreSQL aislados, catálogo de cuatro roles y correo simulado:

1. Alta de Recepción sin CI con revisión declarada → rechazo de emisión sin documento → incorporación del CI a la misma ficha.
2. Ficha histórica con CI que conserva espacios externos, cita/consulta/antecedentes/solicitud/documento/versiones de laboratorio ficticios persistidos.

Ambos continúan con emisión/vinculación reservada → PATCH del mismo CI normalizado sin invalidación → corrección real con invalidación → rechazo del correo/código anterior → nueva emisión/activación de la misma ficha → login y perfil propio → emergencia/contacto/preferencia → conflicto 409 de Recepción y viceversa → ficha inactiva sin perder acceso al perfil → cambio de correo con dos sesiones revocadas → recuperación anterior inutilizada → nuevo login y recuperación al correo nuevo. Se comprueban identidad/código/vínculo y cantidad de fichas estables, privacidad de proyecciones y auditoría, y conservación exacta de filas de historia. Los datos clínicos son únicamente fixtures para verificar preservación; no se acreditan nuevos endpoints clínicos por estas pruebas.

AutenticacionConcurrenteTest añade login con respuesta pausada: una sesión ya creada usando el correo anterior queda revocada al confirmar el nuevo. Al liberarse la respuesta tardía no reaparece la sesión y `/auth/me` devuelve 401, aunque la contraseña se haya conservado.

**Resultado final:** tres pruebas nuevas/185 aserciones adicionales. `php artisan test --compact --no-ansi` → **723 pruebas/7657 aserciones correctas**, 243,18 s. Pint de tres PHP de esta entrega correcto; sintaxis/rutas/UTF-8/cercas Markdown y diff revisados al cierre. Sin migraciones, nuevos atributos ni cambios DER. Se corrigió una etiqueta VS-PAC-06 duplicada en reglas-negocio conservando la precisión de citas existentes como explicación de VS-PAC-05/07, sin renumerar RF/RN ni cambiar su regla. Análisis incremental/contexto §17.40 sincronizados. Diagrama global continúa aplazado por solicitud expresa; siguiente entrega funcional del módulo: **frontend de Pacientes y perfil**, con diseño/plan propio.
