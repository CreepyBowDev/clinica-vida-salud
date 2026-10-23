# Frontend — Clínica Vida Salud

## Agenda e historial — fase 4: revisión integral (2026-10-23)

**Fase 4 y las cuatro fases frontend CU-20/CU-21 completadas localmente.** Las tres fases funcionales previas mantienen sus contratos/estructura y no se añade funcionalidad de negocio en esta fase. La revisión no reprodujo un defecto que exigiera cambios productivos. Se amplían HistorialCitas.test.jsx (nueve casos) y navigation.test.js (seis contextos), **15 pruebas nuevas**.

### Contratos y recorridos finales

| Interfaz | Roles / API real | Alcance |
| --- | --- | --- |
| /agenda | Admin/Recepción/Médico; GET /agenda y /agenda/medicos solo para los dos primeros | Selección expresa o Médico propio desde sesión, día inicial del backend, fecha/estado/página; tabla mínima y detalle |
| /recepcion/historial-citas | Recepción; GET /pacientes existente | Entrada real de selección que reutiliza búsqueda, abre ficha y su historial; sin endpoint global nuevo ni enlace duplicado a Gestionar pacientes |
| /recepcion/pacientes/:id/citas | Recepción; GET /pacientes/{id}/citas | Todas/DESC inicial, período/estado/página, incluso ficha inactiva/sin CI/cuenta |
| /mis-citas | Paciente; GET /mis-citas | Próximas/ASC inicial, sin ID de ficha ni exigir perfil activo; períodos/estados/página del servidor |
| /citas/:id, /mis-citas/:id, /recepcion/historial-citas/:id | Roles/guards de cada flujo; mismo GET /citas/{id} | Matriz autorizada, retorno contextual seguro y consulta nueva al volver |

**Matriz revisada:** Admin nunca conserva ni presenta motivo/CI/teléfono/nacimiento; Recepción motivo/CI/teléfono solo en detalle; Médico motivo/nacimiento solo en cita asignada; Paciente motivo propio. Ningún listado expone estos extras; proyecciones explícitas/validación de respuesta sin volcado de objetos, datos clínicos o contacto profesional. Laravel sigue autorizando cada recurso, no el estado o rol manipulable de React. Ajeno/inexistente se presenta como recurso no disponible sin distinguirlos. Cuenta activa con ficha inactiva conserva consulta; no se concede reserva por ello.

**Estados/tiempo/consistencia:** día y orden de agenda, períodos/estados de historial y paginación se consumen del backend. Sin sort/filtrado de páginas local, fecha inicial del dispositivo, conversión UTC de nacimiento o transiciones inferidas por paso del tiempo. Consultorio/sede/duración/intervalo efectivos de Cita preservados; Cancelada futura en Todas, fin=t en Anteriores y Programada pasada conserva estado. Cambios de total admiten página vacía con contexto y retorno a anterior, no se confunden con fallo HTTP.

**Estado privado y navegación:** criterios solo en Workspace local, sin copias globales/URL/history.state/Web Storage. Atrás/adelante y vuelta desde detalle reconsultan, no restauran objetos privados; detalle directo tiene retorno real sin selección previa. Cambio de ficha/usuario/rol, logout, desmontaje y pagehide impiden publicar respuestas anteriores. Error 403 tardío no reemplaza consulta actual; error de reconsulta retira tabla/contexto anteriores en vez de declarar vacío. Selector administrativo reutilizado aborta/oculta fichas al salir de página y reconsulta al restaurar. Rate limits sin reenvíos automáticos, errores 401/403/404/419/422/429/500/red/2xx malformados seguros; sin escrituras del módulo ni cambio de autenticación.

**Verificación dirigida final:** agenda/navegación/layouts/consultas de Pacientes **237 pruebas/9 archivos correctos**, 17,42 s, Axios/AuthProvider/Router reales con transporte ficticio. Los 15 casos nuevos complementan permisos/filtros/privacidad/sesión de fases anteriores. Regresión completa final en ejecución; resultado pendiente, sin atribuir los 1200/64 de fase 3 a esta ejecución. Build final correcto, aviso conocido >500 kB (**698,44 kB**, gzip 195,13 kB); no lint configurado.

**Regresión visual actual:** tres arneses Chrome/CDP de fases 1–3 ejecutados de nuevo contra interfaz final con API ficticia: **120 disposiciones sin desbordamiento, 40 axe sin infracciones automáticas, cero excepciones JS/escrituras observadas**. Detalle de cuatro roles, selector Admin/Recepción, agenda de tres roles, selección-ficha-historial, Mis citas/próximas/anteriores/canceladas/vacío/error/acceso denegado; variantes principales 320/390/768/1440 y texto 100%/200%, teclado/Enter/foco/retorno y Web Storage vacío. Capturas representativas finales inspeccionadas; evidencias externas agenda1/2/3-evidence.json y capturas renovadas, Chrome/servidores temporales terminados. No certificación exhaustiva de accesibilidad, prueba de carga/SLA ni nueva integración autenticada con Laravel/PostgreSQL/Brevo; suite backend cerrada no repetida.

**Resultado global final:** `pnpm test --maxWorkers=2`, **1215 pruebas/64 archivos correctos**, 156,64 s. Sustituye el pendiente de regresión anterior; 175 pruebas nuevas acumuladas respecto de las 1040 previas al módulo. Sin correcciones productivas necesarias en fase 4.

**Documentación/límites:** README/análisis/CU-20/CU-21/reglas (solo evidencia)/requerimientos (solo avance)/contexto §17.113 sincronizados con el cierre local. DER/modelos/esquema/backend/base habitual/Railway/Word/PDF/RF/RN oficiales/contratos/reglas aprobadas intactos; sin dependencias/commit/push/despliegue. Diagrama global y actualización PHP siguen expresamente aplazados. CU-15–CU-19 permanecen pendientes y obligatorios, sin acciones nuevas en estas consultas. **Frontend CU-20/CU-21 cerrado localmente; no se acredita producción ni nueva integración autenticada, no se inicia otro módulo sin autorización.** Las secciones inferiores son historial progresivo y sus pendientes de fases/autorización quedan resueltos por los avances posteriores.

## Agenda e historial — fase 3: historial administrativo y Mis citas CU-21 (2026-10-22)

**Fase 3 completada y verificada localmente.** MisCitasPage y HistorialCitasPage reutilizan HistorialCitas/HistorialFilters, CitasTable, servicios y detalle de fases 1/2. No cambios al backend ni a reservas/autenticación. Recepción consulta GET /pacientes/{id}/citas desde la ficha, incluso sin CI/cuenta o inactiva; Paciente GET /mis-citas sin ID explícito, consulta de perfil o condición local de ficha activa.

**Filtros/datos:** inicio Paciente Próximas/ASC, Recepción Todas/DESC, según valores predeterminados y metadata del servidor. Período (Próximas/Anteriores/Todas) separado de cinco estados; cambios solo al aplicar, página reiniciada, per_page y navegación del servidor. Sin ordenar/filtrar/reclasificar filas ni cambiar estados por fecha. Contexto mínimo del Paciente también en vacío, instante de consulta en zona clínica, datos de Médico/especialidad/intervalo/estado/sede/consultorio conservado/fecha de registro; sin motivo/CI/teléfono/nacimiento o clínica en listados. Próximas incluye en curso, fin=t queda en Anteriores; canceladas futuras accesibles en Todas, combinaciones vacías no cambian filtros. El historial completo se recorre por páginas, sin corte por antigüedad.

**Recorridos/rutas reales:** /mis-citas y /mis-citas/:id para Paciente; /recepcion/pacientes/:id/citas y /recepcion/historial-citas/:id (ID de Cita en detalle) para Recepción. La ficha incorpora enlace al historial sin exigir actividad/CI/cuenta. Sidebar/Inicio muestran «Mis citas» y «Consultar historial de citas» respectivamente, sin CU visibles. Para evitar duplicar el destino de Gestionar pacientes, el acceso de historial de Recepción tiene /recepcion/historial-citas: SeleccionarPacienteHistorialPage reutiliza PatientsPage en modo selección, sin alta nueva en esa entrada; abre ficha → historial, no consulta global. Todos los destinos tienen componente/contrato real. La búsqueda de fichas usa la API administrativa existente, sin nuevo endpoint. Detalles conservan matriz de fase 1 y contexto de navegación propio; vuelta al historial restaura criterios/página y reconsulta. Detalle directo de Recepción deriva retorno del Paciente autorizado recibido o vuelve a selección; no confía en un nombre/ID arbitrario enviado por history.state.

**Seguridad de estado:** AgendaWorkspace solo conserva criterios/período/estado/página/ID de ficha/origen, sin objetos de Paciente/cita. Otra ficha reinicia criterios y las respuestas previas no aparecen; identidad/rol/salida/pagehide limpian ámbito, pageshow reconsulta. Selector reutilizado reinicia por identidad/restauración. Sin Web Storage, filtros sensibles en URL/history.state ni caché clínica. Guards y Laravel deciden acceso; Admin/Médico sin historial por ficha, Paciente sin acceso a selección ajena. Errores seguros/reconsulta manual/422 por campo/Retry-After/401 existentes, sin escrituras ni funciones CU-15–CU-19.

**Verificación dirigida:** agenda/navegación/HomePage/consultas administrativas de Pacientes **211 pruebas/8 archivos correctos**. Incluye recorrido selección → ficha inactiva sin CI/cuenta → historial, defaults/órdenes, períodos/estados/canceladas futuras/pasada Programada/fin=t, filtros/páginas/detalle-retorno, roles/IDs/otra ficha, respuesta tardía, errores, 401/429/logout/pagehide/otra identidad y respuesta incongruente. Axios/AuthProvider/Router reales con transporte simulado. Regresión completa en ejecución; resultado final pendiente de consignar.

**Chrome/CDP/API ficticia:** selección/ficha/historial de Recepción, Mis citas/anteriores/canceladas/combinación vacía/error/Paciente autorizado y Admin denegado, ida-detalle-retorno por teclado: **34 disposiciones sin desbordamiento, 16 axe sin infracciones automáticas, cero excepciones JS/escrituras**. Principales listados a 320/390/768/1440 y texto 100%/200%, estados adicionales en escritorio/móvil. Capturas inspeccionadas, privacidad/fecha efectiva/órdenes/Web Storage vacío comprobados. Evidencia externa agenda3-evidence.json/agenda3-*.png; procesos temporales retirados. Build correcto, aviso conocido bundle >500 kB (**698,44 kB**, gzip 195,13 kB); diff-check correcto. No nueva integración autenticada Laravel/PostgreSQL ni suite backend repetida o aceptación productiva.

**Resultado final de regresión recibido:** `pnpm test --maxWorkers=2`: **1200 pruebas/64 archivos correctos**, 151,94 s. Sustituye el pendiente de regresión del párrafo anterior; 44 casos nuevos respecto del cierre de fase 2. No fue necesaria una corrección tras la suite final.

README/análisis/CU-21/contexto §17.112 sincronizados con el cierre local de fase 3. Backend/API/DER/base habitual/Railway/RF/RN/reglas/autenticación/reservas/Word/PDF sin cambios; sin dependencias/commit/push/despliegue. Diagrama global/PHP mantienen aplazamiento. CU-15–CU-19 pendientes y obligatorios. **Fase 4 no iniciada: revisión integral y cierre del frontend pendientes de autorización.**

## Agenda e historial — fase 2: agenda médica CU-20 (2026-10-22)

**Fase 2 completada localmente; fase 3 no iniciada.** Nueva ruta `/agenda` para Administrador/Recepcionista/Médico. Admin/Recepción consultan selector mínimo paginado GET /agenda/medicos, búsqueda literal por nombre/apellidos/código e incluyen inactivos; sin elección arbitraria ni consulta de agenda hasta seleccionar. Médico consulta automáticamente su agenda, sin selector ni id_medico enviado. Se reutilizan los servicios y el detalle de fase 1.

**Presentación/contrato:** fecha inicial omitida en HTTP para que Laravel determine hoy en America/La_Paz; se muestra la fecha efectiva recibida, no la del dispositivo. Filtros de fecha civil/estado/cantidad por página con aplicación explícita y reinicio de página; restablecer omite fecha y estado, conserva Médico. Paginación envía solo parámetros permitidos y conserva el día efectivo, sin seguir URL arbitraria ni ordenar/filtrar filas localmente. Tabla por hora ascendente del servidor con código/intervalo/Paciente y código/especialidad/consultorio conservado/sede/modalidad/estado, sin motivo/CI/teléfono/nacimiento/clínica. Contexto de Médico/fecha también en vacío; no afirmar disponibilidad porque no haya citas. Selector plegable, encabezados/controles/paneles y tabla adaptativa existentes, Source Sans 3/verde petróleo conservados con frontend-design cargada.

**Ámbito y navegación:** AgendaWorkspace conserva únicamente criterios/ID de Médico/página/origen en memoria durante agenda → detalle → volver; no guarda objetos de Paciente/cita, history.state o Web Storage. Se reconsultan las filas al regresar. El ámbito se reinicia al cambiar identidad/rol, salir a otra ruta o pagehide. Es Context local de estas rutas, no almacén global de dominio. ProtectedRoute/RoleRoute y matriz de detalle existentes, sin autorización nueva. Sidebar/Inicio activan «Consultar agenda médica» para tres roles; Paciente mantiene detalle directo pero no agenda. Historial sigue pendiente. La regresión reprodujo un fallo previo de HomePage al mezclar funciones disponibles y pendientes: generaba un Link sin destino para historial. Se filtran solo páginas reales en disponibles y se conserva el pendiente dentro del módulo parcialmente implementado, sin habilitarlo ni alterar guards.

**Estados/pruebas:** useAgendaQuery admite consultas deshabilitadas sin petición global, errores por tipo de consulta y allowlist de errores de campo; conserva cancelación/identidad/época/pagehide y Retry-After sin retry automático. AgendaPage.test.jsx añade **26 casos**, incluidos tres roles/autoselección prohibida, selector inactivo/búsqueda/paginación/vacío, filtros/cantidad/fecha efectiva, cambio de Médico/respuesta fuera de orden, listado-detalle-volver/página conservada, origen directo, sesión huésped/restringida/401/logout/cambio de identidad/pagehide, errores 403/404/419/422/429/500/red y privacidad. Dirigida final agenda/navegación/layouts/HomePage/AuthContext: **189 pruebas/9 archivos correctos**. Regresión final **1156 pruebas/63 archivos correctos**, 151,01 s con dos workers, tras corregir tres fallos de integración de HomePage detectados en la primera global. No pruebas relajadas para ocultarlos; se añaden aserciones del historial pendiente en Inicio.

**Visual/build:** Chrome/CDP/API ficticia, selector para Admin/Recepción y agenda de tres roles a 320/390/768/1440 y texto 100%/200%, vacío/error y acceso denegado de Paciente: **50 disposiciones sin desbordamiento, 16 axe sin infracciones automáticas, cero excepciones JS/escrituras**. Selección explícita, tabla por rol, fecha efectiva, detalle por Enter/retorno y foco, ausencia de datos privados en listados/Web Storage vacío comprobados; capturas escritorio/móvil inspeccionadas. Evidencia externa agenda2-evidence.json/agenda2-*.png; procesos temporales retirados. Build final correcto, aviso conocido >500 kB (**690,81 kB**, gzip 193,52 kB), diff-check correcto; sin lint configurado. No nueva integración autenticada con Laravel/PostgreSQL ni aceptación productiva, suite backend no repetida.

README/análisis/CU-20/contexto §17.111 sincronizados. Backend/API/DER/base habitual/Railway/RF/RN/reglas/autenticación/reservas/Word/PDF intactos; sin dependencias/commit/push/despliegue. Diagrama global/PHP mantienen aplazamiento. CU-15–CU-19 siguen pendientes y obligatorios. **Se detiene antes de fase 3** (historial de Recepción y Mis citas); cierre integral frontend conserva fase 4.

## Agenda e historial CU-20/CU-21 — fase 1: servicios y detalle autorizado (2026-10-22)

**Fase 1 completada localmente; fase 2 no iniciada.** Se contrasta el backend cerrado (contexto §17.109) y se incorpora la feature `src/features/agenda/`, sin mover ni cambiar las reservas existentes. Plan aprobado de cuatro fases: 1 servicios/detalle; 2 agenda; 3 historial de Recepción/Mis citas; 4 verificación integral/cierre frontend. Solo se implementa la primera.

**Servicios reales:** agenda.service.js consume exclusivamente GET `/agenda/medicos`, `/agenda`, `/mis-citas`, `/pacientes/{id}/citas` y `/citas/{id}` mediante Axios común, cookies, AbortSignal y sessionBound. Parámetros cerrados/IDs canónicos textuales hasta BIGINT, paginación y filtros del contrato vigente; Médico propio sin id_medico y fecha inicial omitida para usar los valores de Laravel. Mis citas nunca envía id_paciente, historial por ficha usa ID de ruta. Validación de estructura, contexto/ID/filtros efectivos, zona/instantes y página/count; proyección explícita descarta campos ajenos a la respuesta prevista. No reclasificación temporal ni ordenación/filtrado local de las filas devueltas, CSRF previo a cada GET, reintento automático o escritura nueva. Servicios de listado listos/probados, sin páginas de listado adelantadas.

**Detalle:** `/citas/:id` bajo ProtectedRoute/RoleRoute y MainLayout existentes, CitaDetallePage + CitaDetalle presentacional. Matriz: Admin sin motivo/CI/teléfono/nacimiento; Recepción motivo/CI nullable/teléfono; Médico motivo/nacimiento nullable; Paciente motivo propio. Datos mínimos de cita/personas/especialidad y ubicación conservada, sin inferir consultorio vigente ni consultar fichas/clínica/contacto profesional. Clave prohibida omitida; valor autorizado null se presenta como ausente; motivo «0» permanece visible. Texto React escapado, sin HTML arbitrario o volcado JSON. No botones clínicos/administrativos de escritura. Backend sigue autorizando rol/vínculo/titularidad: el rol del cliente solo restringe la presentación.

**Identidad visual y navegación:** skill frontend-design cargada; Source Sans 3, tokens verde petróleo #146b68/#105653, texto #18333b, fondo #f5f8fa y superficies blancas existentes, sin sistema visual nuevo. PageHeading/QueryMessage/Alert/Button, paneles y record-data responsive de consultas reutilizados. Contexto/breadcrumb/título «Detalle de cita» reconoce una ruta real sin enlace nuevo de sidebar; ambos listados continúan pendientes hasta fases 2/3. Acceso directo vuelve a Inicio en esta fase; retorno contextual al listado se integra al implementarlo, no a una ruta ficticia.

**Sesión y errores:** useAgendaQuery vincula cada resultado a usuario/rol/parámetros/revisión; aborta y descarta respuestas anteriores al cambiar cita/sesión/desmontar. pagehide oculta y descarta resultados, pageshow vuelve a consultar; sin Web Storage/caché clínica persistente. Reutiliza la invalidación 401 y época de Axios/AuthProvider, sin modificar autenticación. Errores seguros 403/404/419/422/429/500/red/2xx inválidos, sin cuerpos técnicos; 404 no distingue cita ajena/inexistente, Retry-After limita reconsulta manual y no dispara una petición al expirar. No promesa de retirar bytes ya entregados.

**Pruebas:** 90 casos nuevos (86 en tres archivos de agenda y cuatro de contexto de navegación). Dirigida inicial 112 casos correctos; regresión completa **1130 pruebas/62 archivos correctos** con `pnpm test --maxWorkers=2` (144,96 s). La primera global con paralelismo predeterminado tuvo un fallo temporal previo de ChangeEmailPage al esperar exactamente «90 s»; la clase pasó aislada (27 casos), sin tocarla, y después pasó la global. Tras reutilizar la rejilla record-data en el detalle, regresión focalizada de agenda/navegación/layouts **131 casos/6 archivos correctos**. Build final correcto, aviso conocido >500 kB (**679,53 kB**, gzip 190,69 kB); no script lint configurado.

**Chrome/CDP con API ficticia:** cuatro roles × cuatro anchos (320/390/768/1440) × texto 100%/200%, más motivo null/404 en dos anchos: **36 disposiciones sin desbordamiento**, **8 axe sin infracciones automáticas**, cero excepciones JS/escrituras observadas. Matriz de campos, contexto/título y Web Storage vacío comprobados; capturas escritorio/móvil inspeccionadas. Evidencia temporal externa agenda1-evidence.json/agenda1-*.png; servidor y Chrome temporales terminados. No nueva integración autenticada Laravel/PostgreSQL, correo real o aceptación productiva; no se repite suite backend cerrada.

**Documentación/límites:** análisis incremental y contexto §17.110 sincronizados; diff-check correcto. Backend/base habitual/Railway/DER/RF/RN/reglas/Word/PDF/autenticación y reservas intactos; sin dependencias/commit/push/despliegue. Diagrama global/actualización PHP mantienen aplazamiento. Fases 2–4 y cierre frontend de los CU pendientes; CU-15–CU-19 no se eliminan ni se implementan. **Se detiene antes de fase 2.**

## Rediseño general — fase 7: revisión integral y cierre local (2026-10-22)

**Las siete fases del rediseño quedan completadas localmente.** Se revisaron navegación por los cuatro roles, orientación, componentes compartidos, listados/detalles, formularios/confirmaciones, reserva/planificación y acceso/seguridad. Se conserva Source Sans 3, verde petróleo, rutas y comportamientos existentes; no se implementan módulos pendientes por presentarlos en la navegación.

**Correcciones comprobadas de esta fase:**

- Menú e inicio: con texto al 200% y todos los módulos desplegados se detectaban desbordamientos de etiquetas. Se permite partir palabras cuando es necesario y distribuir el distintivo Pendiente en otra línea, sin ocultar contenido ni añadir scroll horizontal.
- Orientación: una ruta existente de otro rol mostraba «Página no encontrada» como título/contexto pese al contenido correcto de acceso denegado. navigationTitle distingue ahora ruta ajena conocida de ruta inexistente; no cambia guards, permisos ni llamadas HTTP.
- Teclado: al pasar de móvil a escritorio, el foco del botón que desaparece se traslada al enlace actual visible o a Inicio si no lo hay/su módulo está contraído. No se roba foco al formulario; se conserva el comportamiento inverso y se limpia el listener al desmontar.

**Verificación final:** suite completa **1040 pruebas/59 archivos correctos**, ocho nuevas (cuatro títulos por rol y cuatro transiciones de foco), build correcto y `git diff --check` comprobado. Continúa el aviso conocido >500 kB (**670,52 kB**, gzip 188,50 kB); no lint configurado ni optimización de bundle introducida fuera de alcance.

**Chrome/CDP con API ficticia:** regresión de los seis arneses anteriores contra la UI actual y sus variantes de texto ampliado, más revisión transversal de inicio/menú expandido para los cuatro roles a 320/390/768/960/1024/1440 y texto 100%/200%. Total final agregado de doce evidencias: **438 disposiciones sin desbordamiento, 224 auditorías axe sin infracciones detectadas y cero excepciones JS**; capturas representativas inspeccionadas. Incluye carga/vacío/error, acceso denegado sin consultar datos ajenos, ayuda/Escape/foco, formularios, consecuencias, reserva/resultado incierto, consentimiento/impacto y ocultación de secretos. El arnés de reserva se ajustó para reiniciar el documento entre escenarios y evitar interacción con la pantalla anterior. Evidencia externa `rediseno7-evidence.json`, `rediseno7-findings.json`, `rediseno7-review*-evidence.json` y variantes/capturas en `%LOCALAPPDATA%/Temp/opencode/`. No nueva integración Laravel/PostgreSQL/Brevo ni certificación exhaustiva de accesibilidad, producción o SLA.

**Cierre documental y límites:** contexto §17.103 y análisis incremental sincronizados. Sin cambios backend/base/migraciones/DER/RF/RN/reglas/CU funcionales/diagramas/Word/PDF, contratos HTTP o lógica de autenticación. Diagrama global/PHP conserva aplazamiento expreso; no nuevo CU cerrado por una revisión visual. Sin dependencias, commit/push/despliegue. Las secciones inferiores conservan el historial de cada fase; sus pendientes de autorización quedaron resueltos por las entregas posteriores. Los módulos funcionales pendientes y la validación real de despliegue permanecen fuera de este cierre del rediseño.

## Rediseño general — fase 6: acceso, activación y seguridad (2026-10-22)

**Fase 6 completada localmente; fase 7 no iniciada.** Se revisaron las pantallas y servicios reales de acceso, registro/vinculación, verificación de correo, invitación, recuperación, cambio ordinario/obligatorio de contraseña, cambio verificado de correo y entrega presencial. Solo se modifica su presentación: rutas, contratos, hooks, AuthContext, guards, CSRF, plazos, permisos y limpieza de secretos conservados.

**Orientación visual:** AuthLayout mantiene Source Sans 3, verde petróleo e ilustración existentes; el texto lateral responde a cada tarea sin consultar datos ni mostrar identidades. En login, primer acceso diferencia Paciente nuevo de ficha previa con explicaciones bajo los dos enlaces originales. Registro nuevo no solicita código presencial; vinculación conserva CI + código de Recepción y la verificación posterior independiente. RegistrationProgress reutiliza WorkflowProgress, con dos etapas no interactivas, sin saltos ni marcas de envío/éxito. Formularios públicos reutilizan FormSection; recuperación/invitación/cambio definitivo conservan resultados, foco y retorno a login originales. El cambio ordinario separa identidad/nueva contraseña y mantiene renovación de la sesión actual.

**Seguridad y entrega:** ChangeEmailPage conserva destino vigente, diferencia contacto/acceso, revisión previa y verificación/reenvío de la solicitud vigente; ConfirmationPanel y ActionGroup distinguen consecuencias y acciones sin habilitar verificaciones durante revisión o incertidumbre. Emisión de código de Recepción conserva comprobación presencial y confirmación; ambas entregas destacan secreto, vigencia, siguiente tarea y ocultación definitiva. Credencial temporal del personal no se presenta como invitación ni contraseña definitiva; código de Recepción no se confunde con los seis dígitos del correo. Se mantiene tipografía monoespaciada solo en secretos para su lectura, sin copiar/descargar automáticamente ni añadir persistencia. CredentialReceiptHost sigue reemplazando el Outlet privado, incluso ante revocación propia; limpieza por ruta/pagehide intacta.

**Verificación:** suite completa **1032 pruebas/59 archivos correctos**, cinco nuevas (entradas de login, dos formularios de registro, agrupación del cambio ordinario y confirmación del nuevo correo), más aserciones de progreso/resultado y entrega en pruebas previas. Regresión de 401/403/409/419/422/429/red, bloqueo/reenvío, doble envío, invalidación de sesión, enlaces, privacidad y secretos conservada. Build correcto, aviso conocido >500 kB (**670,09 kB**); no lint configurado. `git diff --check` correcto.

**Chrome/CDP/API ficticia:** 27 escenarios × 320/390/768/1440, más diez disposiciones al 200% de texto (registro, vinculación, verificación, credencial temporal y confirmación de correo a 320/1440): **118 sin desbordamiento, 64 axe sin infracciones detectadas, cero excepciones JS**, capturas inspeccionadas. Incluye ayuda/Escape/retorno de foco, login limitado, solicitudes y respuestas neutras, código incorrecto/activación, enlaces válidos/ausentes/resultados de recuperación e invitación, acceso restringido/salida, cambio ordinario, correo revisado/aceptado/fallido/incierto y ambas entregas presenciales. No escrituras durante revisión; una emisión por entrega, ocultación sin nueva emisión y Web Storage vacío; enlaces secretos retirados de URL. No nueva integración Laravel/PostgreSQL, correo real ni certificación integral/SLA. Evidencia externa `rediseno6-evidence.json`, `rediseno6-extra-evidence.json` y capturas en `%LOCALAPPDATA%/Temp/opencode/`.

**Documentación/límites:** análisis incremental y contexto §17.102 sincronizados. Sin cambios backend/base/migraciones/DER/RF/RN/reglas/CU funcionales/diagramas/Word/PDF ni lógica de autenticación; sin dependencias, commit/push/despliegue. Diagrama global/PHP conserva aplazamiento. No nuevo CU cerrado por este rediseño. **Se detiene tras fase 6; fase 7 (revisión integral) requiere autorización.**

## Rediseño general — fase 5: reserva y planificación guiadas (2026-10-22)

**Fase 5 completada localmente; fase 6 no iniciada.** Antes de modificar interfaces se contrastaron ReservarCitaPage, ReservaCatalogo, DisponibilidadTurnos, ReservaRegistro y reserva.service reales: catálogos propios `/reservas/*`, disponibilidad, propuesta, registro `/citas` y consulta explícita `/reservas/resultado` ya existen. Se rediseña únicamente esa implementación de CU-13/CU-14 y la asignación/horarios/duración existentes de CU-11/CU-12; no se agregan pantallas de citas, cancelación, reprogramación o agenda.

**Orientación compartida:** WorkflowProgress muestra una lista ordenada no interactiva con etapa actual accesible, sin enlaces para saltar validaciones ni marcas de éxito/completado. Las etapas son presentación, no estados de Cita o permisos. Reserva distingue búsqueda → revisión/registro → resultado; planificación propuesta → impacto → confirmación. PageHeading, ActionGroup, FormSection y ConfirmationPanel se reutilizan; este último admite label opcional para conservar los nombres accesibles de confirmación existentes. `src/styles/workflows.css` y ajustes de reserva organizan contexto, propuesta, diagnóstico completo y acciones responsive.

**Reserva:** instrucciones de siguiente tarea según la selección, reserva propia del Paciente explícita y selección administrativa solo en Recepción. Catálogos/fecha/turnos en secciones claras, resumen completo sin omitir datos de consentimiento, radios nativos y motivo con acciones separadas. Conserva segundos/horario de sede, disponibilidad orientativa, cambio de criterios y paginación que retiran selecciones, ficha inactiva y permiso del backend. Resultado incierto no se presenta como éxito: el texto aclara que el resumen es una propuesta y no acredita un registro, tanto durante envío como consulta/incertidumbre/ausencia. No se habilita otra creación ni se reenvía automáticamente.

**Planificación:** contexto vigente separado de propuesta; impacto compatible/conflictivo destacado con referencias completas y paginación originales. Consentimiento de todos los traslados, revisión previa y confirmación final conservados, sin ocultar páginas ni permitir selección parcial de citas. La duración global mantiene alcance de toda la sede. El editor de horarios y su listado conservan acciones/cálculos autoritativos; listado adaptable con clases de consulta de fase 3. No cambio de hooks, services, payloads, CSRF, guards, blockers, caducidad, reintentos, datos históricos o reglas.

**Verificación:** suite completa **1027 pruebas/59 archivos correctos**, diez nuevas: cuatro de progreso/panel y seis de colaboración (dos roles de reserva, incertidumbre, asignación, horario y duración). Tras la precisión final del texto de incertidumbre se ejecutaron **51 pruebas correctas** del archivo de registro afectado, sin repetir la suite intacta. Incluye regresión previa de doble envío, consentimiento, selección invalidada, respuestas tardías, 409/419/422/429/red, logout/sesión, useBlocker y reconciliación. Build correcto, aviso conocido >500 kB (667,93 kB); sin lint configurado. `git diff --check` correcto.

**Chrome/CDP/API ficticia:** 18 escenarios × 320/390/768/1440 y cuatro adicionales a texto 200% (resumen de reserva/confirmación de asignación a 320/1440): **76 sin desbordamiento, 40 axe sin infracciones detectadas, cero excepciones JS**, capturas inspeccionadas. Búsqueda/turnos/revisión de ambos actores, cita registrada, resultado incierto/recuperado, conflicto 409, ficha inactiva y vacío; asignación compatible/consentida/conflictiva, horario y duración compatibles/conflictivos. Cero creaciones durante búsquedas/revisiones y un único POST de creación en los escenarios con registro/incertidumbre/recuperación; planificación solo consulta impacto, sin PUT/PATCH. No token de propuesta visible. Evidencia externa `rediseno5-evidence.json`, `rediseno5-extra-evidence.json` y capturas en `%LOCALAPPDATA%/Temp/opencode/`. No nueva integración Laravel/PostgreSQL ni certificación integral/SLA.

**Documentación/límites:** contexto §17.101 y análisis incremental sincronizados. Backend/base/migraciones/DER/RF/RN/reglas/CU funcionales/diagramas/Word/PDF y autenticación intactos; sin dependencias, commit/push/despliegue. Diagrama global/PHP conserva aplazamiento. **Se detiene al cerrar fase 5; fase 6 (acceso, activación y seguridad de cuenta) requiere autorización.**

## Rediseño general — fase 4: formularios y acciones administrativas (2026-10-22)

**Fase 4 completada localmente; fase 5 no iniciada.** Se conservan Source Sans 3, paleta verde petróleo, rutas y avances anteriores. `FormSection` agrupa campos con fieldset/legend nativos y conserva disabled heredado; `ConfirmationPanel` presenta las revisiones existentes como regiones con nombre, sin modales, envíos o confirmaciones adicionales. ActionGroup/form-actions separa las acciones finales. PageHeading admite titleRef opcional para mantener el foco existente en resultados de altas.

**Adopción:** alta de personal con explicación separada, cuenta/identidad/vínculo profesional claramente agrupados; Médicos separa identidad profesional/contacto/habilitación inicial; Especialidades y Consultorios conservan sus campos con secciones y pie de acciones comunes. Registro/edición de Pacientes y perfil comparten agrupación de identidad/contacto/emergencia y conservan las revisiones de duplicados, corrección de identidad y preferencias. Perfil no incorpora edición de identidad o clínica. Las habilitaciones médicas conservan selección y revisión del conjunto completo.

**Acciones sensibles:** cuenta, ficha, actividad profesional, estado de catálogo/consultorio, habilitaciones y reenvío de invitación usan bloques de revisión legibles; botones de desactivación/inactivación diferenciados por texto y variante danger. No se cambian las condiciones que exigen confirmar, campos requeridos, consultas, payloads, borradores, flags busy/blocked/confirm, recuperación de versiones o resultados inciertos. Las comparaciones de versión se distinguen de la edición y descartan borrador solo mediante el control explícito existente. Credenciales/códigos y flujos públicos conservan fase 6; asignación/horarios/reserva conservan fase 5.

**Verificación:** suite completa **1017 pruebas/58 archivos correctos**, ocho nuevas (cinco composición/semántica de controles compartidos y tres de conservación de borrador/confirmación/foco). Después de las correcciones semánticas detectadas en Chrome se ejecutaron **84 pruebas correctas en cinco archivos afectados**, sin repetir la suite intacta. Regresiones de validación, doble envío, 409/422/429/red, permisos, sesión, corrección de identidad, incertidumbre y adopción de versiones conservadas. Build correcto, aviso conocido >500 kB (666,37 kB); no lint configurado. `git diff --check` correcto.

**Chrome/CDP con API ficticia interceptada:** 18 escenarios de alta/edición/perfil/confirmaciones/habilitaciones, error de campo 422, versión obsoleta 409 y guardado pendiente × 320/390/768/1440, más cuatro de texto al 200% (alta de personal/confirmación de cuenta a 320/1440). **76 disposiciones sin desbordamiento, 40 axe sin infracciones detectadas**, cero excepciones JS; capturas inspeccionadas. En escenarios de revisión no se ejecutan escrituras; en 422/409/guardado se observa un único intento, conserva borrador y bloqueo correspondiente. No nueva integración Laravel/PostgreSQL ni certificación integral.

**Hallazgos resueltos:** guía de alta dentro de main representada como sección con nombre, no landmark complementary anidado; Selección actual de especialidades utiliza h2 para evitar salto h1→h3. El arnés corrigió esperas de campos condicionales, selección de formularios y espera del PATCH posterior a CSRF; se reanudaron escenarios pendientes sin repetir los ya válidos. Evidencia externa `rediseno4-evidence.json`, `rediseno4-extra-evidence.json` y capturas en `%LOCALAPPDATA%/Temp/opencode/`.

**Documentación/límites:** contexto §17.100 y análisis incremental sincronizados. Backend/base/migraciones/DER/RF/RN/reglas/CU funcionales/diagramas/Word/PDF, autenticación y contratos HTTP intactos; sin dependencias, commit/push/despliegue. Diagrama global/PHP conserva aplazamiento. **Se detiene tras fase 4; fase 5 requiere autorización y contrastar nuevamente las interfaces reales de disponibilidad/reserva antes de rediseñarlas.**

## Rediseño general — fase 3: consultas, listados y detalles (2026-10-22)

**Fase 3 completada localmente; fase 4 no iniciada.** Adopción del encabezado y grupos de acciones compartidos en Cuentas, Pacientes, Médicos, selector de Horarios y Bitácora, manteniendo los pilotos de Especialidades/Consultorios. Se conservan nombres, rutas, filtros y payloads, búsqueda explícita, limpieza, estado de Bitácora al regresar y paginación/meta del servidor. Pacientes reutiliza Pagination; el selector de Horarios incorpora foco de resultados al paginar, sin nuevas peticiones por esa gestión de foco.

**Presentación:** `QueryMessage` reutiliza carga textual y vacío con orientación en CatalogResults/Cuentas/Pacientes y cargas de detalles. Los fallos mantienen sus componentes y mensajes seguros específicos, ahora en una superficie consistente; no cambian reintentos, límites ni condiciones de error. Los seis detalles existentes (cuenta, ficha administrativa, Médico, especialidad, consultorio y evento) usan PageHeading/retorno uniforme. Datos administrativos, estados, relaciones y accesos existentes quedan separados visualmente; las acciones sensibles conservan su lógica y sus confirmaciones para el rediseño de fase 4.

`src/styles/queries.css` aplica **container queries** únicamente a los siete listados marcados: al faltar ancho útil las filas se apilan con etiquetas visibles y todas las celdas, conservando tabla/encabezados semánticos. La rejilla se adapta al tamaño del texto, no solo al ancho del dispositivo. Cuentas conserva la omisión del nombre de Paciente, Bitácora sus seis columnas/roles históricos y condición de solo lectura. Los datos de Médico/especialidad/consultorio usan rejilla de lectura solo dentro de detalles; formularios, previsualizaciones y cálculos no se reorganizan.

**Verificación:** **1009 pruebas/57 archivos correctos**, nueve nuevas: tres de QueryMessage y seis de integración de presentación de Cuentas, Pacientes, ambos catálogos médicos, Bitácora y foco/paginación de Horarios. Columnas, enlaces reales, CI ausente, privacidad, filtros, no escrituras y semántica conservados. La suite incluye toda regresión anterior de roles/sesión/acciones/reserva/errores. Build correcto, aviso conocido >500 kB (666,23 kB); sin script lint. `git diff --check` correcto.

**Hallazgo de texto ampliado:** la posición fija `top:-100px` del salto al contenido dejaba visible un fragmento cuando el enlace crecía al 200%. Se oculta ahora según su propia altura y se muestra al foco, sin cambiar su handler ni historial. La revisión adicional comprueba ocultación, foco visible, salto a main y ausencia de hash en las cuatro combinaciones de texto ampliado. El arnés activa el foco de la página headless antes de comprobar `:focus`, sin forzar pseudoclases ni relajar las aserciones.

**Chrome/CDP:** API ficticia interceptada; siete listados y seis detalles, vacío/error/carga y dos rutas denegadas a Médico/Paciente, a 320/390/768/1440: 72 revisiones; cuatro adicionales de texto al 200% en listado de Pacientes/detalle de Médico (320/1440). **76 sin desbordamiento ni celdas ocultas, 40 auditorías axe sin infracciones detectadas**, cero excepciones JavaScript; capturas inspeccionadas. Alineación de acceso a ficha ajustada tras revisar móvil. Evidencia externa `%LOCALAPPDATA%/Temp/opencode/rediseno3-{evidence.json,*.png}` y `rediseno3-extra-*`. No integración Laravel/PostgreSQL nueva ni certificación integral de accesibilidad.

**Documentación/límites:** contexto §17.99 y análisis incremental sincronizados. Sin backend, base de datos, migraciones, DER, reglas/RF/RN/CU funcionales/Word/PDF, dependencias, autenticación o contratos HTTP modificados. No commit/push/despliegue. Diagrama global/PHP conserva aplazamiento. Fase 4 abarcará formularios y acciones administrativas; planificación/reserva y acceso conservan fases posteriores. **Se detiene tras fase 3 hasta autorización.**

## Rediseño general — fase 2: base visual compartida (2026-10-22)

**Fase 2 completada localmente; fase 3 no iniciada.** Se conservan los cambios de fase 1, Source Sans 3 y la paleta existente. La base aplica tokens para controles, espaciado de superficies y escala secundaria; botones secundarios con borde perceptible, estados deshabilitados legibles, ajuste de textos largos y mensajes, modal con desplazamiento interno. No nueva biblioteca ni cambio de framework.

**Componentes reales reutilizados:** `PageHeading` presenta título/descripción/acciones y `ActionGroup` conserva orden de acciones con ajuste responsive. Adopción piloto únicamente en encabezados y filtros de Especialidades y Consultorios; no rediseño completo de sus tablas/formularios. `Pagination` recibe `meta` del servidor y callback, sin recalcular totales: reutilizada por `CatalogResults` y Cuentas. `CatalogResults` conserva sus props y decisiones de carga/error/vacío/datos, presentación mejorada y reconsulta explícita con espera existente. Los otros consumidores reciben esa mejora compartida sin nuevos endpoints.

`FormField`/`SelectField` conservan eventos/valores y IDs generados; aceptan ID explícito y combinan descripciones externas, ayuda y errores. SelectField incorpora ayuda opcional sin pasar `hint` al DOM. Alert conserva clases base al recibir `className`. Button conserva API/tipos/loading; solo cambia su base visual. Modal conserva `open/onClose/title/children` y etiqueta predeterminada de ayuda; añade `closeLabel` opcional y contempla select/textarea al contener Tab, excluyendo controles ocultos/deshabilitados. Sin cambios en validaciones, autenticación, consentimiento o negocio.

**Verificación:** **1000 pruebas/56 archivos correctos**, 30 nuevas (27 de controles/composición/paginación/estados de consulta y tres de modal). Incluye regresión completa de navegación, cuatro roles, sesión, reserva y errores HTTP existentes. Primer intento corrigió exclusivamente fixtures nuevos: no pasar children a input y desmontar antes de retirar mocks del dialog. Build correcto, aviso conocido >500 kB (667,18 kB); sin script lint configurado. `git diff --check` correcto.

**Chrome/CDP con transporte ficticio:** datos con textos largos, vacío, error, carga, Consultorios y ayuda modal a 320/390/768/1440; 24 revisiones más cuatro de texto al 200% (320/1440), **28 sin desbordamiento**, **16 auditorías axe sin infracciones detectadas**, cero excepciones JS. Escape modal/restauración de foco verificados y capturas inspeccionadas. Se corrigió el arnés para enfocar el botón antes del click programático; no cambio del producto para eludir la comprobación. Evidencia externa `%LOCALAPPDATA%/Temp/opencode/rediseno2-{evidence.json,*.png}` y `rediseno2-extra-*`. No nueva integración Laravel/PostgreSQL ni certificación integral de accesibilidad.

**Documentación/límites:** análisis incremental y contexto §17.98 sincronizados; no RF/RN/reglas/DER/CU funcionales/diagramas/Word/PDF/backend/base/migraciones/contratos o autenticación modificados. Sin dependencias, commits, push ni despliegue. El diagrama global/PHP sigue aplazado. La adopción general de encabezados, filtros y paginación en los demás listados/detalles corresponde a fase 3; formularios, planificación y acceso conservan fases posteriores. **Se detiene al finalizar fase 2 y espera autorización.**

## Rediseño general — fase 1: navegación modular y orientación (2026-10-22)

**Fase 1 implementada y verificada localmente; fases 2–7 no iniciadas.** Plan aprobado: navegación → base visual compartida → listados/detalles → formularios/acciones → reserva/planificación → acceso/seguridad → revisión integral. Se conserva Source Sans 3 y la paleta verde petróleo, con referencia a `frontend-design`. No cambia el alcance funcional de los CU existentes.

- `src/constants/navigation.js` centraliza módulos, etiquetas descriptivas, roles, patrones de rutas efectivamente registradas y correspondencia CU interna. No se muestran identificadores CU en sidebar ni títulos. Incluye CU-01/CU-04/CU-06 como flujos públicos y CU-02 como acción `LogoutButton` existente, no enlaces del menú protegido.
- `ModuleNavigation` organiza funcionalidades operativas y módulos pendientes según rol. Varios módulos abiertos, contracción manual, apertura automática por ruta directa/profunda e historial; expansión solo en memoria, reiniciada por identidad/rol. Botones nativos, `aria-expanded`/`aria-controls`, listas ocultas fuera del foco y `aria-current` en la función activa. Pendientes legibles, nunca enlaces ficticios.
- `MainLayout` conserva rutas, guards, Outlet, logout y salto al contenido sin hash; añade encabezado contextual y ubicación actual con retorno a área/listado. La asignación `/administracion/medicos/:id/consultorio` pertenece a Consultorios, no a Médicos. El menú móvil cierra por Escape/navegación, con foco de retorno, incluso al seleccionar la misma ruta; al cambiar a móvil no deja foco en navegación oculta.
- `HomePage` y las áreas mantienen sus URLs y accesos, pero ahora distinguen **Funciones disponibles** y **Próximos módulos** usando el mismo catálogo. Ya no anuncian como futuras la reserva, horarios o bitácora existentes. Médico conserva únicamente las funciones de cuenta operativas; no se simula agenda/consulta/laboratorio.

**Pruebas:** 41 casos nuevos: 18 de catálogo/trazabilidad/cobertura de rutas, 12 de layout/despliegue/foco/roles/historial/identidad/blocker, ocho de inicio/áreas y tres de reserva real del frontend (selección/revisión de ambos roles e incertidumbre conservadas al desplegar, sin peticiones nuevas). Pruebas anteriores adaptadas a nombres descriptivos, desplegables y navegación con nombre accesible, conservando aserciones de negocio. Regresión completa **966 pruebas/54 archivos correctos**; después se añadieron cuatro de esos casos y se ejecutaron sus dos archivos: **60 pruebas correctas**, sin repetir la suite intacta. Inventario final: **970 pruebas**, 41 más que las 929 previas. `pnpm build` correcto, aviso conocido >500 kB (667,04 kB); sin script lint configurado.

**Chrome/CDP:** cuatro roles × 320/390/768/1440 px, **16 comprobaciones sin desbordamiento y ocho auditorías axe sin infracciones detectadas**, cero excepciones JS. Menú abierto, despliegue y Escape/foco; capturas escritorio/móvil inspeccionadas. Transporte ficticio interceptado antes de salir del navegador, sin Laravel/PostgreSQL/Internet; no nueva certificación integral ni integración backend. Evidencia externa `rediseno1-evidence.json` y `rediseno1-*.png` en `%LOCALAPPDATA%/Temp/opencode/`. El arnés requirió esperar el cambio de breakpoint antes de abrir el menú, sin relajar comprobaciones.

**Documentación y límites:** contexto §17.97 y análisis incremental sincronizados; sin cambios a RF/RN, reglas, casos de uso funcionales, DER, diagramas, Word/PDF, backend, migraciones, contratos HTTP o autenticación. No dependencias, commits, push o despliegue. El diagrama global/PHP conserva su aplazamiento aprobado. **Se detiene al cerrar fase 1; fase 2 requiere autorización.** En fase 5 se contrastarán nuevamente las interfaces reales de búsqueda/reserva antes de rediseñarlas.

## CU-13/CU-14 — fase 5: integración real y cierre frontend local (2026-10-22)

**Estado vigente: fases frontend 1–5 completadas y verificadas localmente.** Los apartados inferiores conservan evidencia histórica; sus pendientes de integración local de búsqueda/reserva quedan resueltos por este cierre. React → Axios → Laravel → PostgreSQL comprobados para Recepción y Paciente. No se implementan CU-15/CU-16/CU-17–CU-21/CU-28, no se acredita producción/Railway/HTTPS ni el cierre independiente de otros módulos.

**Entorno aislado:** Vite propio en localhost:5175 y Laravel temporal en localhost:8000; exclusivamente base/rol clinica_vida_salud_test, esquema aleatorio prueba_reservas5_*, 35 migraciones ejecutadas en él (incluidas 000034/000035). Cuatro roles con credenciales aleatorias ficticias, dos Médicos/salas/horarios, especialidades paginadas y fichas propia/presencial sin CI/cuenta/otra/inactiva. CORS/Sanctum/cookies y sesiones configurados solo en el arnés desechable, CSRF real activo, solicitudes externas bloqueadas, correo array sin envío real. search_path del trigger adaptado solo al esquema propio; extensión btree_gist exigida como preexistente, no instalada/reubicada. Comparación de objetos/funciones/conteos de public de la base de pruebas sin diferencias. Base habitual/remota no utilizada. Esquemas/credenciales/perfil Chrome propios retirados y servidores detenidos al terminar.

**23 comprobaciones funcionales reales (16 principales + 7 de revisión final):**
- Login/cookies/CSRF, ficha presencial seleccionada sin CI/cuenta, paginación de turnos con segundos y retiro de radio, registro Programada por Recepción y reserva propia del Paciente sin selector.
- Pérdida de respuesta provocada con Chrome **después de recibir un 201 real de Laravel**, no API ficticia ni rollback inyectado: UI pasa a incierto, no reenvía; consultar resultado recupera la única cita confirmada. PostgreSQL verifica que no se duplicó.
- Otra sesión HTTP real de Recepción ocupa exactamente el turno mientras el Paciente revisa su propuesta; al confirmar, 409 y nueva revisión exigida. Es intercalado real de peticiones, no nueva prueba multiproceso de locks (esa garantía conserva backend fase 4).
- Cambio de duración solo en el esquema aislado después de revisar provoca 409 real sin cambiar las citas existentes. CSRF ausente 419 y datos inválidos 422, logout/401, ficha inactiva sin seleccionar turno, Administrador/Médico rechazados por rutas React y API 403, revocación real de sesiones del Paciente al recuperar foco.
- Consulta real de los cuatro eventos CITA_PROGRAMADA por Administrador, resultado EXITO y detalle sin motivo/comprobantes. PostgreSQL: **4 citas, 4 UUID distintos y 4 eventos**, todas Programada y duración histórica de 15 minutos; cero CambioCita y cero Recordatorio, auditoría sin contraseña/motivo ficticios.
- Especialidades paginadas sin selección implícita; radio por teclado, foco al resumen, recarga restaura sesión sin conservar propuesta/crear cita, salto al contenido y menú móvil/Escape. Cookies HttpOnly/CORS con credenciales observados, Web Storage vacío. HTTP observados 200/201/204/401/403/409/419/422; red perdida después del COMMIT controlada por CDP, no fallos reales de infraestructura. 429/5xx restantes conservan pruebas simuladas de fase 4, no se presentan como nuevos casos productivos provocados.

**Hallazgo de integración corregido:** el enlace compartido «Saltar al contenido» usaba navegación nativa a #contenido mientras React Router tenía un blocker registrado, produciendo advertencia de historial POP ajeno al router. MainLayout mantiene href semántico pero su activación enfoca main sin cambiar fragmento/historial. Navigation.test.jsx añade una regresión específica. Se repitió teclado en entorno real limpio: hash vacío, cero advertencias de blocker; no cambios a reglas/contratos/estado de la reserva. Los procesos temporales reportan salida no cero al detenerlos expresamente; no significa fallo del build o de los recorridos.

**Revisión visual final:** **15 comprobaciones de disposición/legibilidad y 10 auditorías axe WCAG A/AA sin infracciones detectadas**, cero excepciones JS. Incluyen resumen de Recepción/Paciente, recibo, incertidumbre y ficha inactiva, con revisión adicional a 320/390/768/1440 px, texto al 200% y espaciado ampliado; capturas inspeccionadas. Evidencia principal de 8 vistas y revisión final corregida de 7 vistas, sin sumar de nuevo la primera revisión equivalente. No certificación integral de accesibilidad. Ajustes previos de escape/selectores/espera del arnés y un 429 durante sus reintentos se resolvieron en el entorno desechable, sin relajar límites del producto ni atribuirlos a defectos de negocio.

**Verificación/cierre:** suite final **929 pruebas/51 archivos correctos**, build correcto con aviso conocido de bundle >500 kB (661,32 kB). Módulo acumulado **138 pruebas/5 archivos** de citas más la regresión nueva del layout: 139 casos frente a las 790 pruebas anteriores al módulo. No suite backend intacta repetida ni lint inexistente. README/contexto §17.96/análisis/CU-13/CU-14 sincronizados; reglas/RF/RN/DER/PHP sin cambios por fase 5. Diagrama global de clases y actualización PHP conservan aplazamiento explícito; no se declaran terminados.

**Evidencia externa:** `%LOCALAPPDATA%/Temp/opencode/reservas5-review/{evidence.json,evidence-extra-final.json,database-evidence.json,*.png}`; arneses temporales reservas5.php/reservas5-browser.cjs/reservas5-extra.cjs, sin secretos versionados. La comprobación SQL de cuatro citas corresponde al recorrido principal, antes de limpiar su esquema; la revisión final de teclado usó otro esquema limpio y también se retiró. Sin commit/push/despliegue. **Frontend CU-13/CU-14 cerrado localmente; se detiene sin iniciar otro módulo.**

## CU-13/CU-14 — frontend fase 4: robustez y seguridad (2026-10-22)

**Alcance verificado:** ampliación de sesión/errores/contratos/navegación y resultado incierto sobre la reserva existente. No endpoints, dependencias, migraciones, PHP, CSS global ni funcionalidades de otro CU. Fase 5 integración real/cierre conserva autorización posterior; las comprobaciones de esta fase usan React/AuthProvider/Axios reales con transporte HTTP simulado, no Laravel/PostgreSQL.

**Fallos reproducidos y corregidos:** dos pruebas fallaron antes del ajuste. (1) Logout con error de red incrementa la época de sesión pero puede mantener la misma identidad autenticada; si el POST de reserva termina después, Axios descarta su respuesta. useReservaRegistro antes ignoraba SESSION_SUPERSEDED dejando «Registrando» sin salida. Ahora, si la ruta sigue vigente, termina busy y pasa a resultado incierto (o fallo previo de CSRF si aún no se había enviado), sin publicar el recibo antiguo ni reenviar; si la identidad/ruta ya cambió, sigue descartando por montaje/secuencia. (2) Un 403 durante el GET CSRF previo al POST retenía revisión y botón de confirmar por considerar todo fallo previo reintentable. Ahora 403/404/422 no conservan esa autorización visual; reintento explícito de revisión solo para red/5xx previos o 419/429. No cambios a AuthProvider/api.js globales.

**Navegación y límites de contrato:** una advertencia de salida pendiente se descarta cuando deja de existir incertidumbre (por éxito o ausencia comprobada), sin arrastrar una navegación antigua ni cambiar ruta automáticamente. Servicios comprueban listas/meta y contexto mínimo de disponibilidad antes de renderizar: estructura inválida se convierte en fallo seguro, no listado inventado ni excepción JSX. Propuesta puede contrastar inicio exacto del turno elegido; confirmación/resultado contrastan Paciente contra el del resumen, sin enviar esos datos de comprobación al backend. No se exige conservar Médico/sala/tiempos del resumen al reconciliar una cita vigente que pudo cambiar por un flujo autorizado. Backend sigue siendo autoridad de permisos/reglas: estas comprobaciones protegen presentación, no sustituyen autorización ni calculan turnos.

**41 casos nuevos:** 10 de lectura malformada, 4 de contrato de propuesta/resultado (inicio/Paciente/booleano), 6 del hook (Retry-After de tres operaciones, beforeunload, pagehide/respuesta tardía, consulta doble) y 21 de páginas. Estos últimos cubren los dos defectos reproducidos, matrices de siete errores de propuesta y siete de resultado, 401 al consultar, cambio de rol durante propuesta/resultado, salida durante propuesta y finalización con navegación bloqueada. Resultado 403 oculta resumen y bloquea nuevas consultas de ese comprobante; no autoriza recreación. Ausencia false requiere otra decisión expresa; errores/429 no generan envíos automáticos al vencer el contador. Se comprueban token fuera de DOM/almacenamientos y limpieza de sesiones con los casos acumulados.

**Verificación final:** módulo **138 pruebas/5 archivos correctos**, regresión frontend completa **928 pruebas/51 archivos correctos**; `pnpm build` correcto con aviso conocido de bundle >500 kB (661,27 kB). Nuevas pruebas en tests/hooks, ampliaciones de tests/pages y tests/services, fixture ficticio existente reutilizado. Sin script lint configurado, no se afirma lint. No se repite la suite backend intacta. No se modificó la disposición/CSS en esta fase ni se presentan las capturas/axe de fase 3 como ejecución nueva: validación visual real/integración final se revisará en fase 5. Foco/errores/transiciones de salida comprobados por Testing Library, no certificación integral de accesibilidad.

README/contexto §17.95/análisis/CU-14 sincronizados. Esquema/DER/reglas/RF/RN y base habitual/Railway intactos; sin commit/push/despliegue. Diagrama global/PHP siguen aplazados. **Fase 4 cerrada localmente; se detiene antes de fase 5.** Módulo frontend todavía no cerrado integralmente.

## Reserva CU-14 — frontend fase 3: revisión, registro y resultado (2026-10-22)

**Implementado:** seleccionar turno → motivo breve opcional → POST propuesta → resumen explícito → POST /citas → cita registrada. Mismas rutas/roles de fase 2. ReservarCitaPage integra useReservaRegistro y ReservaRegistro; el servicio añade proponerReserva/confirmarReserva/consultarResultadoReserva. Los apartados inferiores conservan historia: el registro ya no está pendiente como se indicó al cerrar fase 2. Sin frontend de cancelar/reprogramar/confirmar llegada ni recordatorios; fases 4 robustez ampliada y 5 integración real/cierre siguen pendientes.

**Revisión y consentimiento:** motivo opcional trim/vacío→null, máximo 500 caracteres sin controles/saltos de línea; «0» válido. Hora/revisión del turno y selección exactas por whitelist, id_paciente solo Recepción. Resumen exclusivamente del backend: Paciente/código, especialidad, Médico, inicio/fin (24 horas, segundos y America/La_Paz), duración, consultorio, sede/dirección, Presencial y motivo. No retención del turno. Durante propuesta/guardado no se editan criterios ni motivo; volver retira la propuesta y obliga a consultar/seleccionar/revisar de nuevo. Doble envío bloqueado con ref antes del primer await y botones retirados durante envío. POST exige cookie CSRF y sessionBound; sin retries ni cambios globales de Axios.

**Contrato y resultado:** 201 con repetida=false o 200 con repetida=true validados junto al DTO mínimo; respuesta malformada de creación no es éxito. Resultado muestra exclusivamente atributos efectivos de Cita, incluido su estado vigente, código y ubicación/duración guardadas; no inventa nombres/dirección actuales que ese DTO no contiene. Programada no se confunde con Confirmada/llegada. Al obtener la cita se descartan token/resumen de propuesta; no UUID/cifrado en DOM, URL, history.state, Web Storage o AuthContext. Los únicos cambios en pruebas previas reconocen que ahora hay botón Revisar cita tras seleccionar, sin cambiar el contrato de búsqueda.

**Incertidumbre mínima segura desde la primera escritura:** servicio distingue fallo del GET CSRF (reservaEnviada=false, no POST) de error tras intentar POST. Red/timeout/5xx o 2xx inválido tras /citas deja estado incierto, sin botón de creación ni reenvío automático. «Consultar resultado» hace POST solo con el mismo sobre. true presenta la cita vigente sin crear otra; false informa ausencia en ese instante y permite volver a buscar o confirmar expresamente la misma propuesta/motivo, sin nuevo UUID automático. Fallo de consulta mantiene incertidumbre; no polling. Navegación interna durante guardado/incertidumbre/consulta usa useBlocker con advertencia y elección permanecer/salir, y beforeunload solicita aviso nativo al recargar/cerrar cuando el navegador lo permita. Avisos no garantizan entrega ni persistencia. Logout/pagehide/401/desmontaje/cambio de identidad limpian; no recuperación prometida tras recarga/nuevo login.

**Errores:** 409 obsoleta/ocupación/Paciente no disponible retira propuesta y exige otra búsqueda, sin sustituciones silenciosas. 404/403/422 no habilitan confirmar el resumen anterior; 422 publica solo campos permitidos. 419 mantiene revisión para una decisión explícita y nueva preparación CSRF; 429 deshabilita acciones según Retry-After. SQL/texto arbitrario/error completo no se muestra. Respuestas de otra época/instancia no publican recibos ni reautorizan operaciones. Fase 4 ampliará matrices de errores/sesión/navegación; no se declara cerrada toda la robustez por esta fase.

**Verificación:** **46 pruebas nuevas** (22 de servicio, 24 de páginas), acumulado del módulo **97 pruebas/4 archivos**. Ambos roles/resumen/whitelist/CSRF/segundos/motivo «0»/validación, propuesta fallida, doble clic, 201/200 vigente, cuatro conflictos 409, fallo previo CSRF, 419/422/429/401, red/500/respuesta inválida inciertas, resultado true/false/fallido, confirmación expresa del mismo sobre, logout tardío y advertencia/abandono sin persistir. Primeras dos expectativas de hora detectaron formato regional de 12 horas; se fijó hourCycle h23 para mostrar 08:00:01 sin cambiar instantes. **Suite completa: 887 pruebas/50 archivos correctos** y pnpm build correcto (aviso conocido bundle >500 kB, 659,74 kB). No lint configurado ni suite backend intacta repetida.

**Diseño/revisión visual:** identidad/tokens existentes conservados; revisión en sección completa sustituye búsqueda, resultado usa datos persistidos y foco al encabezado en cambios de estado. Chrome/CDP con HTTP ficticio interceptado: cinco estados × 1440/768/390/320 px, **20 comprobaciones sin desbordamiento, 10 auditorías axe sin infracciones detectadas**, cero excepciones JS; capturas móvil/escritorio inspeccionadas. Primer intento del arnés tuvo un selector mal escapado, corregido solo en el script temporal antes de completar todas las comprobaciones. Evidencia externa reservas3-evidence.json/reservas3-*.png, sin datos reales/secretos. No integración Laravel/PostgreSQL real nueva ni certificación integral de accesibilidad.

**Cierre local fase 3:** README/contexto §17.94/análisis/CU-14 sincronizados; reglas/RF/RN/DER/PHP/base habitual/Railway intactos. Sin commit/push/despliegue/dependencias. Diagrama global y actualización PHP aplazados. Se detiene antes de fase 4 hasta autorización; módulo frontend todavía abierto.

## Búsqueda y disponibilidad CU-13 — frontend fase 2 (2026-10-22)

**Implementado localmente:** `/recepcion/citas/nueva` y `/reservar-cita`, con ProtectedRoute/RoleRoute/MainLayout y menú/título exclusivos según rol. Esta fase permite buscar y seleccionar un turno, **no registrar citas**: propuesta/revisión/POST /citas/resultado conservan fases 3–4. La pantalla indica expresamente ese límite y que la disponibilidad no reserva espacio. Los apartados inferiores son evidencia histórica, no descripción del estado actual de implementación.

**Capas/componentes:** nueva feature `src/features/citas/`: ReservarCitaPage y ReservaFlujo (interno) coordinan selección; ReservaCatalogo presenta consultas paginadas de ficha/especialidad/Médico con callbacks; DisponibilidadTurnos presenta contexto/turnos/selección local. reserva.service.js contiene exclusivamente tres GET de reserva, whitelist, IDs BIGINT textuales, signal/sessionBound y filtros literales; reutiliza api.js, useCatalogQuery, servicio propietario de Pacientes y controles compartidos. No catálogo administrativo de Médicos/especialidades abierto a otros roles, ni nuevo Axios/Context/dependencia. Pruebas en tests/services y tests/pages de la feature.

**Comportamiento:** Recepción selecciona explícitamente una ficha por CI opcional/nombres/apellidos; muestra código y actividad, permite sin CI/cuenta y no crea fichas desde reserva. Paciente no ve selector ni envía id_paciente. Especialidad → Médico (nombre/código de consultorio) → fecha civil → consulta explícita. Turnos, duración, ubicación, actividad de ficha y zona America/La_Paz vienen de la API; sin cálculo local/anclaje/horizonte arbitrario. Fecha imposible/vacía se señala; backend decide fecha pasada/inicio futuro. Hora local con segundos íntegros, HMAC opaca no mostrada ni recalculada. Ficha inactiva puede consultar pero radios quedan deshabilitados; respuestas 403/404/409 bloquean la selección disponible, sin adoptar condiciones nuevas silenciosamente.

**Estado/privacidad:** filtros/selecciones solo en memoria, sin query/history.state/Web Storage/almacén global. Cambiar ficha/especialidad/filtros de Médico/fecha retira dependencias y resultados inmediatamente; cambiar página de turnos/actualizar retira turno elegido. Peticiones obsoletas canceladas/descartadas mediante useCatalogQuery y época de sesión de Axios. Key por usuario/rol evita heredar selección; salida/pagehide/logout/revocación retiran datos privados. 401 integra login, 419 pide reconsulta, 422 fecha permitida, 429 respeta Retry-After en reconsulta; errores de red/5xx no reintentan ni muestran cuerpos técnicos. **No POST de reserva ni CSRF solicitado por estos GET**, no motivo/propuesta por esta fase. Las futuras escrituras deberán implementar la incertidumbre diseñada en fase 1.

**Diseño/revisión:** Source Sans 3, paleta y layout existentes conservados. Filtros en filas flexibles y secciones con jerarquía, contexto efectivo y radios nativos para turno único; móvil apilado y grilla flexible. Chrome real con API **ficticia interceptada**, cuatro escenarios (Paciente, Recepción, ficha inactiva, sin turnos) × 1440/768/390/320 px: **16 comprobaciones sin desbordamiento**, **8 auditorías axe sin infracciones detectadas**, cero excepciones JS. Capturas móvil/escritorio inspeccionadas. No certificación integral de accesibilidad ni integración con Laravel/DB por esta revisión. Evidencia temporal externa `%LOCALAPPDATA%/Temp/opencode/reservas2-{evidence.json,*.png}` y arnés reservas2-browser.cjs; sin pacientes reales ni secretos.

**Verificación final:** **51 pruebas nuevas/2 archivos** (12 servicios, 39 páginas). Roles/cruce de rutas/anonimato/restricción, ficha sin CI, filtros/IDs/segundos, vacíos/paginación/ficha inactiva, cambios de selección/respuestas tardías, errores 403/404/409/419/422/429/5xx/red, 401/logout/cambio de rol y ausencia de persistencia. Prueba de errores inicial esperaba encabezado desaparecido en 403/404/409: se conservó encabezado contextual accesible también en esos estados y pasaron todos los casos. **Regresión completa frontend: 841 pruebas/48 archivos correctos**; `pnpm build` correcto, aviso conocido de bundle >500 kB (645,61 kB), sin optimización ajena al alcance. No script lint configurado, no se afirma ejecución de lint ni suite backend intacta. Diff/documentos verificados al cierre.

README/contexto §17.93/análisis CU-13/ficha sincronizados. Backend/DB/migraciones/DER/reglas/RF/RN sin cambios por esta entrega; base habitual/Railway sin tocar, sin commit/push/despliegue. Diagrama global/PHP aplazados. **Fase 2 cerrada localmente; no iniciar fase 3 sin autorización.** Módulo frontend todavía abierto.

## Búsqueda y reserva CU-13/CU-14 — fase 1: diseño frontend (2026-10-22)

**Estado: diseñado, no implementado.** Backend cerrado localmente en contexto §17.91 (1380 pruebas/16361 aserciones). Esta fase no crea feature/rutas/componentes/servicios/tests funcionales ni modifica PHP, esquema, sesión o CSS. Se inspeccionaron router, MainLayout, AuthProvider, Axios, servicios de Pacientes/perfil, componentes compartidos y contratos PHP reales. React 19/Vite 8/JavaScript/React Router 7/Axios, pnpm 11.17.0 y Vitest/Testing Library existentes; sin librerías nuevas. Las cinco fases siguientes son frontend, independientes de las cinco fases backend ya terminadas.

### Alcance, entradas y arquitectura previstas

- **Recepción:** `/recepcion/citas/nueva`, menú «Programar cita». Selección explícita de ficha mediante búsqueda paginada autorizada de Pacientes, nombre/código/CI solo cuando exista; mostrar actividad y permitir volver a seleccionar. No exigir CI ni cuenta web a ficha presencial. No alta de ficha incrustada ni transporte de ficha/motivo por URL/history.state; se reutiliza la gestión de Pacientes existente fuera del flujo.
- **Paciente:** `/reservar-cita`, menú «Reservar cita». Sin selector de ficha y **sin enviar id_paciente**; backend deriva la identidad. Puede consultar su perfil existente para contexto mínimo/actividad, sin ampliar AuthContext ni depender del nombre de la cuenta para probar identidad. La disponibilidad y el resumen de propuesta son la referencia efectiva del servidor.
- Ambas rutas previstas bajo ProtectedRoute/RoleRoute y MainLayout; Administrador/Médico sin acceso. No listado general de citas, agenda/historial ni acciones de cancelar/reprogramar/llegada/Atendida. No enlaces a páginas todavía inexistentes.
- Una Page compartida `ReservarCitaPage` con variante según rol, dentro de `src/features/citas/pages/`, sin duplicar flujos. Composición prevista: selector de ficha de Recepción, criterios, turnos, revisión y resultado como componentes de feature. `services/reserva.service.js` encapsulará las seis operaciones; Page/hook local coordinará estados, sin Axios en componentes ni lógica de disponibilidad. Subcarpetas hooks/utils/styles solo si tienen uso real. Nombres de diseño, **no archivos creados**. Reutilizar Button/FormField/SelectField/Alert/Modal y patrones de paginación cuando encajen; no forzar un catálogo administrativo en los catálogos de reserva.
- Estado local de la instancia de ruta, no AuthContext ni caché global. Pruebas futuras exclusivamente en `src/features/citas/tests/{services,pages,components,hooks,utils}/` según los archivos necesarios, no carpetas vacías. Services existentes de Pacientes/perfil se importan desde su módulo propietario.

### Flujo visual previsto

Conservar Source Sans 3, tamaños/tokens actuales y alineación izquierda: verde clínico `#146b68`, texto `#18333b`, secundario `#526871`, fondo `#f5f8fa`, superficie `#ffffff`, borde `#d5e0e5`; feedback con tokens existentes, sin cambiar paleta ni tipografía. Elemento principal: selección legible de fecha/turnos y ubicación real, no métricas ni calendario decorativo. Secuencia real «Buscar turno → Revisar → Resultado» con paso actual accesible; revisión y resultado sustituyen el área de búsqueda, no apilan formularios activos.

```text
Escritorio (MainLayout existente)
Programar cita / Reservar cita
Buscar turno · Revisar · Resultado
[Paciente seleccionado y Cambiar]  (solo Recepción)
[Especialidad] [Médico + búsqueda] [Fecha]
[Filtro opcional de consultorio] [Consultar turnos]
Consultorio real · duración · America/La_Paz
[08:00:01] [08:15:01] [08:30:01] ... [paginación]
Motivo breve (opcional)                 [Revisar cita]

Revisión: Paciente / especialidad / Médico / fecha-hora completa
          duración / sede-dirección / consultorio / Presencial / motivo
          «El turno no queda reservado hasta completar el registro»
          [Volver a buscar] [Reservar cita / Programar cita]

Resultado incierto: «No pudimos confirmar si la cita se registró»
                   [Consultar resultado] (sin botón de reenvío automático)
Resultado confirmado: «Cita registrada» · código · Programada · datos efectivos
```

En móvil, criterios/resumen apilados, turnos con salto de línea y controles >=44 px, sin desplazamiento horizontal; revisión en sección completa para no encerrar formulario largo en modal. Radios nativos para turno único, fieldset/legend, etiquetas visibles, aria-busy/live para cambios, foco al encabezado de revisión/resultado o primer error, sin anunciar cada tecla. Fechas con zona de sede explícita, nunca zona automática del navegador. Pruebas futuras a 320/390/768/1440 px, teclado, zoom/texto ampliado y contraste. Sin animaciones de entrada ni significado comunicado solo por color. Revisión de diseño: se descarta un calendario mensual y tarjetas decorativas, pues la API consulta por fecha y no persiste meses de turnos; se conserva el lenguaje visual ya aprobado.

### Contratos efectivos para implementar en fases 2–4

Prefijo `/api` configurado solo en api.js; services usan rutas relativas, `sessionBound: true`, cookies/XSRF y `signal` en consultas cancelables. POST obtiene cookie CSRF conforme a los servicios existentes; nunca reintenta automáticamente. IDs BIGINT textuales, página/tamaño acotados por API, listas vacías válidas. No seguir links recibidos como URLs arbitrarias: construir parámetros permitidos sobre ruta conocida.

| Operación | Entrada permitida | Respuesta que consumirá React |
| --- | --- | --- |
| GET `/reservas/especialidades` | nombre opcional, page/per_page | data[] id_especialidad/nombre, links/meta |
| GET `/reservas/medicos` | id_especialidad; nombre/consultorio opcionales; page/per_page | data[] id_medico/codigo_medico/nombres/apellidos/consultorio mínimo, links/meta; no catálogo administrativo |
| GET `/reservas/disponibilidad` | id_especialidad/id_medico/fecha YYYY-MM-DD; id_paciente obligatorio solo Recepción; page/per_page | data con contexto Médico/especialidad/consultorio, zona_horaria/consultado_en/paciente_reservable/duracion_minutos/turnos; links/meta están en raíz, no dentro de data |
| POST `/reservas/propuesta` | selección sin paginación + hora_inicio HH:mm:ss + revision_turno + motivo_breve opcional; id_paciente solo Recepción | 200 data: id_propuesta/propuesta opacos, resumen Paciente/Médico/especialidad/consultorio/inicio_at/fin_at/duracion_minutos/zona_horaria/modalidad/sede_atencion/direccion_sede/motivo_breve |
| POST `/citas` | propuesta exacta + mismo motivo_breve revisado; sin selección/estado/roles/duración adicionales | 201 primera creación o 200 repetición; data cita vigente y meta.repetida en raíz |
| POST `/reservas/resultado` | únicamente propuesta exacta | 200 data.registrada booleano; si true, data.cita vigente. Nunca crea |

Turnos: inicio_at/fin_at UTC con seis decimales, hora_inicio local con segundos y revision_turno HMAC opaca. Mostrar segundos cuando existan, conservar texto exacto al enviar; no truncar segundos, no regenerar HMAC ni convertir YYYY-MM-DD con toISOString(). Usar Intl con `timeZone: 'America/La_Paz'` al presentar instantes, no imponer horizonte/anticipación ni calcular cuadrícula/ocupación en React. El reloj cliente solo ayuda: backend decide fecha/inicio futuro.

Motivo opcional: trim, vacío→null, máximo 500 caracteres y sin caracteres de control según servidor; «0» no es vacío. Se edita antes de solicitar propuesta; editarlo posteriormente retira propuesta y exige revisión nueva. No enviar motivo al consultar resultado. Servicios harán listas permitidas de campos, tratarán respuestas de guardado malformadas como resultado no confirmado y no mostrarán error.response/config/request completos.

Cita devuelta: IDs id_cita/id_paciente/id_medico/id_especialidad/id_consultorio; codigo_cita/estado/modalidad/duracion_minutos/sede_atencion/consultorio_atencion/motivo_breve/version_programacion e inicio_at/fin_at/creada_en/actualizada_en. **No devuelve nombres actuales ni direccion_sede/zona_horaria/UUID**. Resultado usa campos efectivos persistidos; nombres del resumen solo como contexto de la revisión de esta instancia, no como datos actuales reconsultados. No inventar endpoint GET de cita ni reconstruir estado inicial al reconciliar. «Cita registrada» no equivale a estado Confirmada; mostrar estado vigente, incluso distinto de Programada en replay.

### Estados, invalidación y resultado incierto

Estados **de interfaz**, no estados nuevos de Cita: buscando/cargando → turno seleccionado → preparando propuesta → revisión → guardando → registrada; alternativa guardando → resultado incierto → consultando resultado → registrada o ausencia comprobada. Errores de consulta/propuesta no implican que se haya creado una cita; solo POST /citas tiene esa escritura.

| Cambio/resultado | Efecto previsto |
| --- | --- |
| Cambiar ficha o especialidad | Cancelar/descartar respuestas anteriores, limpiar dependencias Médico/turnos/selección/propuesta; motivo se limpia al cambiar ficha |
| Cambiar Médico, fecha, filtro de Médicos o página/tamaño de turnos | Retirar turno/revisión/propuesta y resultados que ya no correspondan; no seleccionar automáticamente el primero |
| Editar motivo o volver desde revisión | Invalidar propuesta; nueva solicitud de revisión, nunca reutilizarla con otro cuerpo |
| Nueva consulta de disponibilidad | Cancelar la anterior o ignorar por secuencia; preservar criterios válidos pero exigir selección vigente |
| `paciente_reservable=false` | Informar ficha inactiva y bloquear nueva propuesta; no confundir con cuenta desactivada |
| Guardando | Bloqueo inmediato en ref y visual contra doble submit; congelar selección/motivo. No afirmar rollback por abortar/navegar |
| Resultado incierto | Conservar en memoria propuesta exacta/motivo/snapshot; bloquear nueva reserva desde este flujo hasta consultar o abandonar explícitamente con advertencia. No polling ni reenvío automático |
| Resultado true | Presentar cita vigente, retirar controles de creación anterior; no duplicar evento ni restaurar estado/ubicación/duración |
| Resultado false | «No se encontró una cita registrada para esta propuesta al consultar». No es autorización de reenvío: permitir volver a buscar o una confirmación explícita de la misma propuesta revisada, que backend revalidará. Nunca emitir automáticamente otro UUID |
| Fallo al consultar resultado | Mantener incertidumbre y botón de consulta cuando corresponda; no habilitar un nuevo POST /citas por suposición |

Sin persistencia en localStorage/sessionStorage, URL/query/hash, history.state, logs, analytics ni AuthContext de ficha/motivo/propuesta/HMAC/resumen/cita. Solo ruta sin query para retorno de login. Limpiar al desmontar/abandonar/pagehide, logout, cambio de actor/rol, sesión restringida o inválida; descartar respuestas tardías con secuencia local + sessionBound/época existentes. La indisponibilidad de comprobación de sesión oculta área privada, sin confundir error de red con logout.

**Límite deliberado:** backend puede reconciliar un token conservado autorizadamente después de nuevo login, pero este frontend no lo conserva a través de logout/401/recarga. No ampliar AuthContext ni crear un almacén de comprobantes sobre el área pública para prometer esa recuperación. Advertir antes de abandonar un envío incierto, cuando la navegación pueda interceptarse con APIs existentes, y ofrecer aviso nativo beforeunload sin datos sensibles; la advertencia no garantiza entrega ni preservación. Después de perder la prueba no reconstruir petición ni crear otra automáticamente, ni inventar búsqueda pública por CI/código. Mostrar orientación administrativa sin afirmar que ya existe una pantalla de historial/CU-21 ni un procedimiento nuevo de recuperación. Cualquier recuperación persistente entre sesiones requerirá diseño/autorización separados.

### Errores y pruebas previstas

| Caso | Presentación/acción |
| --- | --- |
| 401 | Integración con invalidación de sesión existente, limpiar datos privados y login; no reenvío ni transporte del comprobante |
| 403 | Acceso denegado, no fingir logout ni mostrar datos previos como autorizados |
| 404 | Selección no disponible, reconsultar catálogos/ficha sin revelar datos ajenos |
| 409 CITA_PROPUESTA_OBSOLETA | Retirar propuesta/consentimiento; preservar criterios razonables, obtener disponibilidad vigente y nueva elección/revisión explícitas |
| 409 CITA_TURNO_NO_DISPONIBLE/CITA_PACIENTE_SOLAPADO | Informar indisponibilidad sin ocupante, reconsultar; sin elegir alternativa automáticamente |
| 409 CITA_PACIENTE_NO_DISPONIBLE | Bloquear reserva, revisar ficha autorizada; no activar ficha/cuenta desde este módulo |
| 419 | Renovación CSRF solo mediante nueva acción explícita; si envío ya incierto, consultar resultado, no reenviar confirmación |
| 422 | Campos permitidos y mensaje seguro; propuesta/revisión inválida exige nueva búsqueda/revisión, no edición manual del comprobante |
| 429 | Retry-After del servidor, botón de acción limitado sin bucle/reintento; no revelar cuenta/ocupante |
| Sin red/timeout/5xx o 2xx inválido después de enviar /citas | Resultado incierto, consultar endpoint de resultado. Si solo falló obtención de CSRF antes del POST, señalar que no se envió; no adivinar la etapa |

`describirError` existente requiere mensajes específicos en feature: su genérico 419 sugiere reenviar y **no debe aplicarse ciegamente al guardado incierto**. Categorías error.details.cambios se limitan a HORARIO/DURACION/CONSULTORIO/HABILITACION/DISPONIBILIDAD; no suponer causa exacta si API informa genéricamente. Nunca errores técnicos completos en pantalla/consola.

Matriz futura: contratos de seis servicios y whitelist; IDs grandes/segundos/motivo «0»; permisos de cuatro roles y sesión restringida; Paciente sin id_paciente/Recepción con ficha sin CI; cambios de criterios y respuestas fuera de orden; vacíos/paginación/ficha inactiva; resumen coherente y propuesta invalidada; doble clic; 201/200 replay; red después de COMMIT simulado → resultado true/false/fallido; 401/logout/cambio de rol durante solicitud; 403/404/409/419/422/429/5xx; ausencia de persistencia/filtración; foco/teclado/responsive. Vitest/Testing Library por fase; integración real solo en entorno/base/rol aislados, correos externos bloqueados. No repetir suites backend intactas sin causa técnica.

### Cadencia y cierre de fase 1

1. **Diseño (esta entrega):** contratos/estados/rutas/UX/matriz, sin código funcional.
2. **CU-13:** feature/servicio de lecturas/rutas/menús y búsqueda/selector autorizado/turnos; pruebas y revisión visual. No POST /citas todavía.
3. **CU-14:** propuesta/revisión/confirmación/resultado nominal y protección mínima imprescindible desde el primer guardado: incertidumbre bloquea reenvío y ofrece consulta explícita. **No entregar guardado inseguro esperando fase 4.**
4. **Robustez:** ampliar pruebas y estados de reconciliación, sesión/respuestas tardías/navegación/errores/privacidad; cerrar alternativos sin reintentos automáticos.
5. **Integración/cierre:** recorridos reales de ambos roles, accesibilidad/responsive, suite frontend y build; sincronización documental. Frontend funcional local no equivale a aceptación Railway/HTTPS.

**Fase 1 completada solo documentalmente:** README/contexto §17.92/análisis previsto y notas CU-13/CU-14 sincronizados, fuentes/contratos revisados; sin cambios de reglas/RF/RN/DER/PHP/UI ni tests funcionales ejecutados por este diseño. Las pruebas/build de fases posteriores no se anuncian como realizadas. Diagrama global y actualización PHP conservan aplazamiento expreso; no commit/push/despliegue ni cambios de base habitual/Railway. Se detiene antes de fase 2 hasta autorización.

## Consultorios y horarios CU-11/CU-12 — fase 5: cierre integrado local (2026-10-22)

**Estado vigente:** fases frontend 1–5 completadas y comprobadas localmente con React → Axios → Laravel → PostgreSQL. Los apartados inferiores conservan la evidencia histórica de cada fase; sus pendientes de integración local de CU-11/CU-12 quedan resueltos por esta entrega. No se cierra la integración propia de CU-30 ni se inicia otro módulo.

**Entorno autorizado:** Vite existente en `localhost:5175`, Laravel temporal en `localhost:8000`, base/rol exclusivamente `clinica_vida_salud_test` y esquema aleatorio propio. Se ejecutaron las 33 migraciones existentes solo en ese esquema, con cuatro usuarios de roles fijos, dos Médicos (uno inactivo), consultorios, horarios y 22 citas ficticias. Configuración temporal de CORS/Sanctum/cookies y almacenamiento de sesiones aislados; CSRF real activo, HTTP externo bloqueado y sin correos reales. Ajuste de search_path del trigger de bitácora únicamente dentro del esquema desechable. Código/configuración backend versionados, base habitual y Railway sin cambios. Comparación de objetos/funciones/cantidades de filas de public antes/después sin diferencias. Servidor temporal detenido, esquema/credenciales/perfil Chrome de esta prueba eliminados al terminar; Vite previo conservado.

**Integración real, 27 comprobaciones funcionales correctas:**
- Catálogo paginado, alta/edición/actividad y reconsulta; código duplicado 409; desactivación ocupada rechazada.
- Impacto de asignación de 22 citas en dos páginas por POST, consentimiento completo retirado al paginar y operable por teclado; confirmación/PUT y traslado atómico completo. PostgreSQL confirmó **22 citas trasladadas y 22 cambios de cita**, conservando exactamente sus IDs, inicio/fin, estados y duración de 15 minutos.
- Alta/edición parcial/actividad de horarios con segundos locales; diagnóstico impide dejar citas sin cobertura; Médico inactivo conserva horario activo. Duración 30 incompatible por el intervalo del Médico inactivo; 20 compatible guardada/reconsultada. Cambio concurrente a 18 deja la propuesta anterior obsoleta sin cambiar duraciones históricas.
- Huella de asignación obsoleta por cambio concurrente de sala; versión Médico obsoleta en asignación/horario; versión Consultorio obsoleta conserva borrador hasta adopción explícita. Son operaciones HTTP reales secuenciales intercaladas, no una nueva certificación de carreras de base de datos.
- Cookies HttpOnly y CORS con credenciales, restauración, logout, revocación de sesión al recuperar foco; las cuatro rutas del módulo y sus lecturas API rechazan los otros tres roles. HTTP reales observados 200/201/204/401/403/409/419/422. Sin almacenamiento web ni excepciones JS. Verificación final: 30 eventos de bitácora sin la contraseña temporal; no se acredita auditoría exhaustiva de todos sus campos por esta comprobación.

**Revisión frontend-design:** identidad visual existente intacta, sin nuevas pantallas ni retoques arbitrarios. Chrome/CDP real: **20 comprobaciones de disposición/legibilidad y 12 auditorías axe WCAG A/AA sin infracciones detectadas**, cero excepciones JS. Incluye asignación/impacto, conflicto de horario, confirmación global, catálogo y Médico inactivo a 320/1440 px; cuatro pantallas a 768 px con texto 200% y espaciado ampliado. Consentimiento, salto al contenido y menú móvil/Escape verificados por teclado. Capturas móvil/escritorio/texto ampliado revisadas; no equivale a certificación integral de accesibilidad.

**Regresión versionada:** nuevo `src/features/horarios/tests/pages/PlanificacionSesion.test.jsx`, **10 pruebas** con AuthProvider/servicios/Axios reales y transporte simulado: lecturas tardías tras logout o revocación en catálogo/asignación/horarios/duración, cambio de rol con confirmación abierta y PATCH tardío tras logout. No hubo fallos de producción que requirieran cambiar las fases 1–4. `pnpm test`: **790 pruebas/46 archivos correctos**; módulo acumulado 169 pruebas/10 archivos. `pnpm build` correcto, aviso conocido de bundle 635,97 kB. No se repitieron las 1188 pruebas backend, pues su código permaneció intacto. Los errores de red/500/429/404 y demás alternativos simulados conservan la cobertura de fases anteriores: no se presentan como provocados en el servidor real.

**Evidencia local externa:** `%LOCALAPPDATA%/Temp/opencode/plan5-review/{evidence.json,evidence-extra.json,database-evidence.json,*.png}`; arneses desechables `plan5.php`, `plan5-browser.cjs`, `plan5-extra.cjs`, sin secretos versionados. README/contexto §17.86/análisis y avances CU-11/CU-12 sincronizados. Cierre funcional **local** del frontend, no despliegue ni aceptación Railway/HTTPS; migración 000033 en la base habitual/remota no verificada. DER/reglas/requisitos/Word/PDF intactos, diagrama global y actualización PHP siguen expresamente aplazados.

## Horarios CU-12 — fase 4: duración global de citas (2026-10-22)

**Implementado:** ruta exclusiva de Administrador `/administracion/horarios/configuracion`, enlazada desde Horarios. Consulta duración global vigente, zona de sede y versión de ConfiguracionClinica; propuesta entera positiva dentro de INTEGER, impacto paginado y confirmación explícita del alcance global antes de PATCH. La duración no es por Médico/especialidad y las citas existentes no se actualizan desde esta pantalla. La configuración se vuelve a consultar tras éxito, sin calcular ni almacenar disponibilidad en React.

**Arquitectura/contrato:** ConfiguracionCitasPage/DurationEditor (interno) y configuracionCitas.service.js reutilizan useCatalogQuery, useHorarioSubmission y HorarioImpacto, ampliados con una opción explícita de configuración para mensajes/campos correctos sin cambiar el flujo de horarios. GET /configuracion-citas sin entrada, POST /configuracion-citas/impacto con duración/version/page/per_page y PATCH /configuracion-citas con duración/version únicamente. Versión global UTC/seis decimales conservada intacta, sin usar versión de Médico/huella/ID inventado. POST/PATCH con cookies/CSRF/sessionBound y sin reintentos automáticos; paginación repite POST del mismo cuerpo, sin seguir links como GET. Conflictos proyectan solo referencias de Médico/horario y día/horas locales devueltos por servidor, no datos clínicos.

**Experiencia/seguridad:** no-op evita envíos; entradas vacías/no enteras/fuera de INTEGER se señalan por campo. No máximo funcional arbitrario ni división exacta exigida. Backend comprueba todos los horarios activos, incluidos Médicos inactivos; horarios desactivados no se presentan como bloqueadores inventados. Cambiar propuesta cancela/descarta diagnóstico y confirmación, paginar exige nueva revisión. Vista previa no reserva ni garantiza guardado. 409 DURACION_INCOMPATIBLE conserva borrador pero retira diagnóstico; CONFIGURACION_EDICION_OBSOLETA bloquea propuesta hasta descarte/reconsulta explícitos. Red/500 inciertos tras PATCH se tratan como posiblemente guardados; sin afirmar rollback ni reenviar. 401 elimina vista, 403/404 bloquean, 419/422/429 seguros con campos permitidos/Retry-After. Doble submit bloqueado y respuestas tardías descartadas al editar/salir. No persistencia de propuestas en URL/history.state/almacenamientos. La entrada informativa a nueva reserva/reprogramación no implementa esos CU ni cambia citas.

**Diseño frontend-design:** conserva Source Sans 3, tokens/paneles/layout existentes y estilos específicos de horarios, sin CSS/dependencias nuevos. Jerarquía: configuración actual → alcance global → propuesta en minutos → diagnóstico → confirmación del antes/después. Se mantiene ayuda de conservación histórica y no se agrega un calendario ni métricas decorativas.

**Verificación/cierre local de fase 4:** **37 pruebas nuevas** (6 servicio/31 páginas), acumulado Horarios 79 pruebas/5 archivos. Pruebas específicas correctas; regresión completa frontend por cambios de router y colaboradores compartidos: **780 pruebas/45 archivos correctos**. `pnpm build` correcto, aviso conocido bundle 635,97 kB. Chrome headless/React con HTTP ficticio interceptado: tres estados (vigente, confirmación compatible y conflicto) × 1440/768/390/320 px, **12 comprobaciones sin desbordamientos y 6 auditorías axe sin infracciones detectadas**, cero excepciones JS. Capturas móvil/desktop revisadas; evidencia externa temporal duracion4-evidence.json/duracion4-*.png y guion duracion4-browser.cjs (reutiliza arnés horarios3). No acredita aún integración Laravel/PostgreSQL real ni producción/certificación integral. No suite backend repetida.

README/contexto §17.85/análisis/CU-12 sincronizados. Backend/base/migraciones/DER/reglas/Word/PDF intactos y cambios previos conservados; diagrama global/PHP aplazados. Fase 4 completada en cliente. Próxima y última fase autorizable: 5 integración real/revisión transversal y cierre CU-11/CU-12; no se inició en esta entrega.

## Horarios CU-12 — fase 3: intervalos por Médico (2026-10-22)

**Implementado:** entrada administrativa `/administracion/horarios` con búsqueda/paginación de Médicos por nombre/actividad (incluye inactivos), y `/administracion/horarios/medicos/:id` con contexto profesional/consultorio fijo, listado de horarios por estado/tamaño/página y duración vigente informativa. Alta, edición parcial y activación/desactivación mediante impacto previo y confirmación explícita. Acceso desde menú y detalle Médico, rutas bajo guards Administrador/sesión normal. Duración global aún no editable (fase 4).

**Clases UI/capas:** feature horarios con HorariosPage/HorariosMedicoPage, HorarioEditor/Impacto, useHorarioSubmission, horario.service.js, horarioFormat.js y styles/horarios.css. Reutiliza MainLayout, useCatalogQuery/CatalogResults, controles/Alert y servicios existentes de Médico/asignación. La API devuelve filas en data.elementos, versión del Médico y duración en data, meta/links externos: la adaptación local para CatalogResults no cambia el contrato ni recalcula totales. Capacidad turnos_completos procede del servidor, no equivale a huecos reservables. Horarios ordenados por día/inicio/ID según backend.

**Contrato/experiencia:** cinco endpoints reales GET/POST /medicos/{id}/horarios, PATCH /{horario}, PATCH /{horario}/estado y POST /impacto. Payloads cerrados, IDs textuales, versión del Médico UTC/seis decimales intacta; edición envía solo campos cambiados, estado envía únicamente activo/versión con ID en ruta. Impacto usa accion CREAR/EDITAR/ESTADO e id_horario cuando corresponde, page/per_page en POST repetido sin seguir enlaces GET; no huella ni reserva de franja. La propuesta se invalida al editar campos/paginar y no permite guardar mientras se consulta. Compatible habilita revisión/confirmación, incompatible solo muestra conflictos y referencias/fechas de sede, nunca contenido de paciente/clínica. Horas locales textuales HH:mm:ss para conservar segundos sin limitaciones de controles time ni conversión UTC, con ayuda visible y validación estructural/fin posterior al inicio. No se calculan solapamientos/cobertura en React.

**Seguridad:** Médico inactivo puede gestionarse administrativamente sin activar cuenta/horarios. Activar/desactivar revalida en backend sala/duración/ocupación/cobertura entera de citas vigentes; no hay borrar ni modificar citas. 409 tras impacto retira diagnóstico y exige nueva revisión conservando borrador; versión obsoleta bloquea sin sustituirla silenciosamente. Red/500 inciertos tras escritura bloquean hasta descarte explícito del borrador y reconsulta del listado. 401 limpia vista, 403/404 bloquean y orientan a consultar, 419/422/429 seguros con Retry-After, doble submit bloqueado. Cambio de campos/desmontaje aborta/descarta impactos tardíos. Cambiar filtros/consultar contexto no está permitido con editor abierto para evitar perder borrador sin acción explícita.

**Diseño frontend-design:** misma paleta/tipografía de fases previas, secuencia Médico/sala → filtros/listado → editor → impacto → confirmación, sin calendario ni métricas decorativas. Tablas/acciones adaptativas y horas alineadas en dos columnas desktop/una móvil; mensajes distinguen actividad profesional, horario y cobertura sin prometer disponibilidad. CSS solo de horarios.

**Verificación/cierre local de fase 3:** **42 pruebas nuevas** (10 servicios, 30 páginas, 2 formato) en tests por responsabilidad; suite frontend completa **743 pruebas/43 archivos correctos**, build correcto (aviso conocido bundle 628,40 kB). Chrome headless/React con HTTP ficticio interceptado: cinco estados (selección Médico/listado/alta/desactivación compatible/conflicto) × 1440/768/390/320 px, **20 comprobaciones sin desbordamientos y 10 auditorías axe sin infracciones detectadas**, cero excepciones JS. Capturas desktop/móvil revisadas. Evidencia temporal externa horarios3-evidence.json, horarios3-browser.cjs y horarios3-*.png; no acredita aún Laravel/PostgreSQL real ni producción/certificación integral de accesibilidad. No suite backend repetida.

README/contexto §17.84/análisis/avance CU-12 sincronizados; backend/base/migraciones/DER/reglas/Word/PDF intactos y cambios de fases previas conservados. Fase 3 completada; próxima fase 4 duración global y luego fase 5 integración/revisión ampliada/cierre. Diagrama global/PHP aplazados. No se implementaron fases siguientes.

## Consultorios CU-11 — fase 2: asignación, impacto y traslados (2026-10-22)

**Implementado:** ruta exclusiva de Administrador `/administracion/medicos/:id/consultorio`, con acceso «Gestionar consultorio fijo» desde el detalle del Médico. Consulta Médico/asignación existentes, muestra actividad profesional independiente (también inactivos), sala fija o ausencia legítima, y permite seleccionar destino entre consultorios activos con búsqueda/paginación del servidor. Se conserva la opción elegida fuera de la página visible; no se supone que los primeros resultados sean todo el catálogo. No retirada de asignación, activación profesional/cuenta ni cambios de fecha/estado/duración de citas.

**Contrato real:** asignacionConsultorio.service.js usa GET `/medicos/{id}/consultorio`, POST `/medicos/{id}/consultorio/impacto` y PUT `/medicos/{id}/consultorio`; listas cerradas de campos, ID textual BIGINT, actualizado_en_original intacto, huella y consentimiento booleano. POST/PUT precedidos de cookie CSRF, solicitudes sessionBound y sin reintentos automáticos. Paginación de impacto repite POST con propuesta/versión/page/per_page, nunca sigue los links del servidor como GET. Vista previa no reserva ni autoriza; servidor recalcula la compatibilidad al guardar.

**Revisión y consentimiento:** selector nuevo invalida y cancela impacto previo. Al paginar se retira consentimiento/confirmación; si cambia la huella respecto de la revisión anterior se descarta el resultado y se exige primera página. Mientras hay consulta pendiente no se puede confirmar. Impacto no confirmable muestra conflictos administrativos, sin botón de guardar; uno compatible exige aceptar todas las citas propuestas si cantidad > 0 y después revisar/confirmar destino explícitamente. No-op/primera asignación sin traslados envía confirmar_traslados=false. Elementos muestran solo códigos conocidos/referencias/estados/fechas de sede America/La_Paz, sin JSON genérico, Paciente ni contenido clínico. Fechas imposibles no se normalizan silenciosamente.

**Errores/ciclo:** 409 de impacto/ocupación/atención/consentimiento retira vista previa y aceptación, conserva destino y requiere consulta nueva. Versión de Médico obsoleta exige consultar base vigente antes de nuevo impacto; red/500 inciertos tras PUT bloquean hasta consultar asignación actual, sin afirmar rollback ni reenviar. 403/404 bloquean envíos y orientan a revisar; 419/422/429 seguros, Retry-After y 401 mediante invalidación compartida. Guardado exitoso usa respuesta persistida, muestra cantidad realmente trasladada y exige reconsulta antes de otra operación. La reconsulta conserva destino pero no huella/consentimiento. Doble submit bloqueado y respuestas pendientes descartadas al cambiar destino/Médico o desmontar. Todo estado de revisión vive en React, no en URL/history.state/almacenamientos.

**Diseño:** continúa frontend-design y tokens de fase 1; secuencia visual Médico/asignación actual → destino → impacto → consentimiento/confirmación. AsignacionImpacto presenta referencias largas en columnas adaptativas y texto de estado, sin calendario ficticio ni nombres de pacientes. CSS específico en styles/asignacion.css, sin modificar estilos globales. Se reutilizan useCatalogQuery, CatalogResults, Button/Alert/FormField/SelectField y guards/Axios; los componentes ConsultorioSelector/AsignacionImpacto pertenecen a la feature.

**Verificación/cierre local de fase 2:** **41 pruebas nuevas** (7 de servicio/34 de páginas); acumulado Consultorios 80 pruebas/4 archivos. Suite frontend completa **701 pruebas/40 archivos correctos** por el impacto de rutas y acceso desde Médico; build correcto con aviso conocido del bundle (607,06 kB). Chrome headless/React con HTTP ficticio interceptado: dos estados (impacto compatible con consentimiento/confirmación y conflicto) × cuatro tamaños 1440/768/390/320, **8 comprobaciones sin desbordamientos y 4 auditorías axe sin infracciones detectadas**, cero excepciones JS. Captura móvil revisada. Evidencia fuera de Git en `%LOCALAPPDATA%/Temp/opencode/consultorios2-evidence.json`, guion consultorios2-browser.cjs y capturas consultorios2-*.png. No acredita aún Laravel/PostgreSQL real ni producción/certificación integral.

README/contexto §17.83/análisis/CU-11 sincronizados. Backend/DER/base/reglas/migraciones/Word/PDF intactos; no suite backend repetida. Fase 2 completada en cliente; próxima fase 3 horarios por Médico, luego duración global e integración ampliada. Diagrama global/PHP siguen aplazados; no se implementaron fases siguientes.

## Consultorios CU-11 — fase 1: catálogo administrativo (2026-10-22)

**Implementado y verificado en cliente:** catálogo/listado, búsqueda literal por código o nombre (`q`), filtro activo y tamaño 10/20/50/100, paginación del servidor, alta, detalle con cantidades, edición y cambio de actividad con confirmación. Rutas `/administracion/consultorios`, `/nuevo`, `/:id` y `/:id/editar`, menú/título y acceso exclusivo de Administrador con sesión normal mediante los guards existentes. No eliminación, desasignación, cambios de citas ni asignación/horarios anticipados.

**Arquitectura:** features/consultorios contiene consultorio.service.js (cinco endpoints reales), useConsultorioSubmission, ConsultoriosPage/ConsultorioEditorPage/ConsultorioDetailPage y componentes ConsultorioEditor/Data/Failure/VersionReview. Reutiliza MainLayout, RoleRoute/ProtectedRoute, Axios sessionBound/cookies/CSRF, useCatalogQuery y CatalogResults, Button/Alert/FormField/SelectField y catalogs.css, sin nuevos estilos/dependencias. Datos de detalle son referencias y cantidades administrativas de API, no una lista de pacientes/citas. Código conserva mayúsculas/minúsculas, trim y límites existentes; IDs BIGINT textuales y versión UTC original con seis decimales sin conversión.

**Flujos y seguridad:** filtros borrador separados de los aplicados, sin consultas por pulsación; búsqueda/limpieza reinician página. Alta/edición solo envían campos permitidos, no estado/IDs privilegiados. 422 muestra campos permitidos; código duplicado permite corregir; edición obsoleta conserva el borrador bloqueado y consulta datos actuales antes de adopción explícita. Resultado incierto de red/500 bloquea nuevos envíos hasta revisión; alta incierta orienta al catálogo, sin reintento automático. Estado confirma/revalida en servidor; rechazos por asignaciones (también Médicos inactivos) u obligaciones no cambian estado ni prometen traslados. Revisión de estado exige nueva confirmación. 401 limpia vista vía AuthProvider, 403/404/419/429/errores muestran mensajes seguros; 419 necesita reenvío explícito y 429 respeta Retry-After. Peticiones obsoletas canceladas/ignoradas, doble submit protegido. No se persisten propuestas en URL/localStorage/sessionStorage.

**Diseño frontend-design:** skill local leída en `.opencode/skills/frontend-design/SKILL.md`. Dirección contrastada con la interfaz vigente: fondo #f5f8fa, superficie #ffffff, texto #18333b, primario #146b68, bordes #d5e0e5 y error #a52e38; Source Sans 3 y escala/radios existentes. Esquema alineado a izquierda: cabecera/acción → filtros → tabla código/nombre/estado → paginación; detalle con datos/cantidades → explicación → confirmación explícita. Se descartaron métricas decorativas y un calendario sin API. Tablas adaptativas/controles y foco reutilizados, estado comunicado por texto además de color. Capturas desktop/móvil revisadas sin necesitar CSS adicional.

**Verificación final de fase 1:** **39 pruebas nuevas en dos archivos** (`tests/services` y `tests/pages`), incluidas en **660 pruebas/38 archivos correctos** de frontend. Se amplió regresión a toda la suite del cliente por cambios en router/layout compartidos; no se repitió la suite backend. `pnpm build` correcto, aviso conocido de bundle sin partición (592,48 kB). Chrome headless/React reales con HTTP interceptado: **16 comprobaciones de tamaño** (1440/768/390/320 px) en listado/alta/detalle con confirmación/edición, sin desbordamientos; **8 auditorías axe-core** sin infracciones detectadas y cero excepciones JavaScript. Evidencia ficticia fuera de Git en `%LOCALAPPDATA%/Temp/opencode/consultorios1-evidence.json`, guion consultorios1-browser.cjs y capturas consultorios1-*.png. No equivale a integración Laravel/PostgreSQL real, aceptación productiva ni certificación completa de accesibilidad. No existe script lint.

**Plan aprobado por dependencias:** 1 catálogo (esta entrega); 2 asignación/impacto/consentimiento completo de traslados; 3 horarios por Médico; 4 duración global/impacto; 5 integración, seguridad/responsive ampliadas y cierre. Se crea código solo de la fase autorizada. README, contexto §17.82, análisis y avance CU-11 sincronizados. Backend/migraciones/base/DER/reglas/Word/PDF sin cambios; diagrama global/PHP aplazados. CU-11 frontend parcial y CU-12 frontend pendiente; próxima fase 2 únicamente tras autorización.

## Bitácora CU-30 — fase 4: seguridad de interfaz, responsive y accesibilidad (2026-10-21)

**Revisión completada:** Chrome headless con React/Vite reales y respuestas HTTP interceptadas (datos ficticios, sin Laravel/PostgreSQL ni cookies reales del servidor en esta fase). Nueve estados: listado, avanzados, validación 422, vacío, denegado 403, detalle con 100 IDs de especialidad, estados de cuenta, formato desconocido y evento inexistente. **72 comprobaciones de tamaño** (1440/1024/961/960/768/700/390/320 px) sin desbordamiento de documento/contenido; **18 auditorías axe-core 4.10.3** (1440/390 px) sin infracciones detectadas. Capturas revisadas de listado desktop y detalle móvil. No se necesitó cambiar el CSS existente de Bitácora.

**Teclado/permisos/sesión:** en Chrome se verifica apertura/cierre de avanzados con Espacio, Tab a responsable y, al cerrar después de 422, salto a tamaño de página; campos inválidos reciben foco. Tres roles ajenos no generan consultas de auditoría al abrir la ruta; revalidación a sesión no autenticada retira detalle y dirige a login. Sin solicitudes de escritura ni excepciones JavaScript en ese recorrido. Evidencia local fuera de Git en `%LOCALAPPDATA%/Temp/opencode/bit4-evidence.json`, capturas `bit4-*.png` y guion `bit4-browser.cjs`; no constituye aceptación de producción ni certificación completa de accesibilidad.

**Correcciones focalizadas:** BitacoraFilters utiliza el desplegable nativo y abre/enfoca solo al recibir errores nuevos; un 422 ya mostrado no lo reabre al intentar cerrarlo ni roba foco al cambiar otro control. bitacoraFormat rechaza fechas imposibles que Date normalizaría silenciosamente (30 de febrero, 31 de abril, 24:00), conservando presentación válida de días bisiestos y precisión UTC original.

**Regresión:** 9 escenarios nuevos en tests/pages/BitacoraSafety.test.jsx y 5 de fechas en tests/utils; cubren revocación y nuevo login con filtros limpios, cambio a los otros roles, fallo/restauración de comprobación de sesión, logout con respuesta pendiente, foco/semántica/solo lectura y regresiones de ambos ajustes. La activación nativa por Espacio y tabulación de details cerrado se comprueban en Chrome, pues jsdom no las reproduce completamente. Acumulado Bitácora **100 pruebas/6 archivos**, suite completa **621 pruebas/36 archivos correctos**, build correcto con aviso conocido del bundle de 576,32 kB.

README/contexto §17.75/análisis parcial/ficha CU-30 sincronizados. Fases frontend 1–4 completadas; integración con Laravel/PostgreSQL y cierre local pendientes de fase 5. Backend/DER sin cambios; diagrama global y actualización PHP siguen aplazados.

## Bitácora CU-30 — fase 3: detalle y retorno al listado (2026-10-21)

**Implementado:** BitacoraDetailPage valida el ID antes de consultar, consume consultarEvento y presenta carga/errores seguros y detalle mediante BitacoraEventData. Cabecera con fechas del hecho/registro de sede y UTC original de seis decimales, origen, responsable/objetivo/roles históricos, resultado/causa general conocida, IP/procedencia, correlación, versión e ID de evento relacionado sin expansión ni enlace implícito.

El componente presenta únicamente campos conocidos del contrato: referencias como identificadores, nombres de campos modificados sin valores personales/clínicos, altas sin CI, habilitaciones/retiradas, estados/vínculos antes/después, IDs de Paciente/registro de activación y metadatos de instalación/pruebas. No hay visor genérico de JSON. Claves desconocidas se omiten y formatos distintos de 1 conservan cabecera sin interpretar información adicional. Los metadatos explican que autorización de instalación o simulación no acreditan control real del buzón.

BitacoraWorkspace mantiene borrador/criterios/página mediante estado local y Outlet context exclusivo de ambas rutas, sin guardar eventos ni escribir en URL, history.state, localStorage o sessionStorage. Se conserva al ir a detalle y volver (enlace o historial), se reconsulta el listado y se descarta al salir del módulo/desmontar el área protegida. Acceso directo al detalle funciona sin listado previo y vuelve a criterios iniciales. ID inválido local no genera HTTP; 404/422 orientan al listado, otros errores permiten consulta manual y 429 conserva espera. Cambio de ID cancela/descarta detalles previos.

**Verificación:** 28 pruebas nuevas en tests/pages/BitacoraDetail.test.jsx; navegación/listado previos ajustados a la nueva consulta real del cliente. **86 pruebas en 5 archivos de Bitácora** incluidas en la suite completa: **607 pruebas/35 archivos correctos**. Build correcto con aviso conocido del bundle de 576,30 kB. Adaptador HTTP simulado con Router/AuthProvider/servicios/Axios del cliente reales; no acredita aún integración con Laravel/PostgreSQL ni revisión visual completa en navegador.

README, contexto §17.74, análisis parcial y ficha CU-30 sincronizados. Frontend fases 1–3 implementadas; fases 4–5 revisión de seguridad/responsive/accesibilidad e integración/cierre pendientes. Backend/DER sin cambios; diagrama global y actualización PHP siguen aplazados.

## Bitácora CU-30 — fase 2: listado, filtros y paginación (2026-10-21)

**Implementado:** BitacoraPage consulta el servicio real del frontend y coordina borrador de filtros separado de los criterios aplicados. BitacoraFilters es presentacional y ofrece período, módulo y resultado, con criterios avanzados para responsable/objetivo, origen, operación, tipo de evento, IP y recurso. Permite 10/20/50/100 eventos por página; aplicar o limpiar reinicia la página. No hay consultas por cada pulsación. Las fechas locales se envían sin conversión; Laravel valida criterios, combina filtros y determina totales/orden.

BitacoraTable presenta evento/fecha del hecho en America/La_Paz, módulo/operación, responsable y cuenta objetivo con IDs/roles históricos diferenciados, origen sistema/anónimo, resultado y enlace de detalle. Conserva el orden del servidor, sin deducir nombres ni consultar fichas. Se reutilizan FormField/SelectField/Button, useCatalogQuery y CatalogResults: carga/vacío, errores seguros, campos 422 (apertura de avanzados y foco), 429 con Retry-After/reintento manual, invalidación central 401 y cancelación/descarte de respuestas obsoletas. Paginación por meta; no se siguen URLs de links. Estilos específicos en features/bitacora/styles, con disposición móvil apoyada en catalogs.css.

**Verificación:** 14 pruebas nuevas de comportamiento en tests/pages/BitacoraList.test.jsx y navegación previa adaptada al listado funcional. Feature completa **58 pruebas/4 archivos correctos**; suite frontend **579 pruebas/34 archivos correctos**; build correcto con aviso conocido del bundle de 566,55 kB. Pruebas con Router/AuthProvider/servicio/Axios reales del cliente y adaptador HTTP simulado. No acredita aún integración con Laravel/PostgreSQL ni revisión visual completa en navegador.

Contexto §17.73, análisis parcial y ficha CU-30 sincronizados. Detalle y retorno con conservación de filtros/página se implementarán en fase 3; revisión de seguridad/responsive/accesibilidad e integración/cierre en fases 4–5. El enlace ya navega a la vista inicial de detalle. Backend/persistencia/DER sin cambios; diagrama global y actualización PHP siguen aplazados.

## Bitácora CU-30 — fase 1: servicio, rutas y navegación (2026-10-21)

**Completada la base del frontend.** La feature `src/features/bitacora/` incorpora `services/bitacora.service.js`: `listarBitacora` consume GET `/bitacora` y conserva `data/meta/links`; `consultarEvento` consume GET `/bitacora/{id}` y devuelve `data`. Ambas usan Axios central, cookies, `sessionBound` y AbortSignal, sin peticiones CSRF ni reintentos. Solo se envían filtros permitidos por el contrato de `backend/README.md`; se omiten vacíos, se recortan espacios ASCII y se conserva el caso. IDs de detalle se validan mediante `esIdentificador` existente; BIGINT/fechas/JSON de respuesta permanecen sin conversión.

Rutas `/administracion/bitacora` y `/administracion/bitacora/:id` bajo ProtectedRoute/RoleRoute de Administrador. MainLayout incorpora menú exclusivo, estado activo, título y foco compartido. `destinoSeguro` admite listado/detalle para regresar después de login. Las páginas iniciales indican explícitamente qué funcionalidad está pendiente y todavía no consultan eventos: listado/filtros/paginación corresponden a fase 2 y detalle a fase 3.

`utils/bitacoraFormat.js` prepara fechas ISO UTC del contrato para presentación en `America/La_Paz` (sin alterar el original) y etiquetas de actor/resultado; ausencia o valores desconocidos producen «No disponible». No se persisten eventos ni se añaden dependencias.

**Pruebas:** tres archivos en `tests/services/`, `tests/pages/` y `tests/utils/`, **44 pruebas correctas** con adaptador HTTP simulado y Router/AuthProvider reales. Cubren filtros, cadenas BIGINT, precisión original, errores HTTP, cancelación/sesiones anteriores, cuatro roles, restauración/sesión restringida, retorno seguro, navegación y cruce de día UTC/La Paz. Suite completa `pnpm test`: **565 pruebas/33 archivos correctos**. `pnpm build` correcto con aviso conocido del bundle principal, ahora 558,16 kB. Sin script de lint instalado. Integración con Laravel/PostgreSQL y revisión responsive completas quedan para fases 4–5.

Contexto §17.72, análisis parcial y ficha CU-30 sincronizados. Backend, persistencia y DER sin cambios; diagrama global y actualización PHP mantienen su aplazamiento.

## Organización de pruebas — fase 4 completada (2026-10-21)

**Reorganización de las cinco features terminada.** Cada prueba pertenece a `src/features/<modulo>/tests/<responsabilidad>/`, separada del código funcional. La convención obligatoria se documenta en `AGENTS.md` §5.1 y se aplica también a futuras pruebas de componentes/hooks. Se crean subcarpetas únicamente cuando contienen archivos.

| Feature | Archivos de pruebas | Distribución dentro de `tests/` |
| --- | ---: | --- |
| auth | 11 | pages (6), services (3), context (1), utils (1) |
| usuarios | 3 | pages (2), services (1) |
| pacientes | 8 | pages (5), services (3) |
| medicos | 5 | pages (4), services (1) |
| especialidades | 2 | pages (1), services (1) |

Los 29 archivos conservan nombres, casos y aserciones; la comparación contra Git confirma que solo cambian rutas relativas. No quedan pruebas fuera de `tests/` dentro de features. `src/layouts/Navigation.test.jsx` conserva su ubicación y `src/test/setup.js` el setup compartido. Vitest descubre todos los archivos sin ajustes de configuración.

```powershell
pnpm test
pnpm test src/features/auth/tests
pnpm build
```

**Verificación final:** **521 pruebas correctas/30 archivos**, build correcto con el aviso conocido del bundle de 556,88 kB y `git diff --check` correcto. Referencias documentales revisadas; se actualiza la ruta de AuthIntegration en este README. Contexto §17.66 registra la decisión técnica aprobada y el cierre de las cuatro fases. Sin nuevos CU ni cambios de clases funcionales/persistencia, por lo que análisis/DER/diagrama global no requieren modificación. Las secciones siguientes conservan la cronología de cada fase.

## Reorganización de pruebas — fase 3: Médicos y Especialidades (2026-10-21)

**Completada:** Médicos tiene cuatro archivos en `tests/pages/` y uno en `tests/services/`; Especialidades, uno en `tests/pages/` y uno en `tests/services/`. Se conservan nombres y casos, ajustando imports y mocks, incluidas las referencias entre features, Auth, Router, Axios y constantes compartidas.

**Verificado:** `pnpm test src/features/medicos/tests src/features/especialidades/tests`: **142 pruebas/7 archivos correctos**; build correcto con aviso conocido del bundle de 556,88 kB. Los 29 archivos de pruebas de las cinco features actuales están dentro del `tests/` de su módulo, sin pruebas mezcladas en carpetas funcionales. Pendiente fase 4: regresión completa, documentación definitiva de la convención y revisión de referencias documentales. Sin cambios funcionales, de contratos o persistencia.

## Reorganización de pruebas — fase 2: Usuarios y Pacientes (2026-10-21)

**Completada:** todos los archivos de pruebas de ambos módulos se encuentran en su `tests/` correspondiente. Usuarios: dos archivos en `tests/pages/` y uno en `tests/services/`; Pacientes: cinco en `tests/pages/` y tres en `tests/services/`. Se conservan nombres y casos, ajustando imports, mocks e importaciones `vi.importActual` a la nueva ubicación, incluidas referencias a Auth y Axios compartido.

**Verificado:** `pnpm test src/features/usuarios/tests src/features/pacientes/tests`: **225 pruebas/11 archivos correctos**. Build correcto con el aviso conocido del bundle de 556,88 kB. Sin pruebas fuera de `tests/` en estos dos módulos; Auth conserva el traslado de fase 1. Pendientes: fase 3 Médicos/Especialidades y fase 4 revisión/documentación global. Sin cambios funcionales ni cierre adicional de CU; análisis/DER/diagrama global no requieren cambios por este traslado.

## Reorganización de pruebas — fase 1: Auth (2026-10-21)

Se adopta progresivamente `src/features/<modulo>/tests/<responsabilidad>/` para todos los archivos de pruebas de cada feature. Inventario inicial: 29 archivos en features (Auth 11, Usuarios 3, Pacientes 8, Médicos 5 y Especialidades 2); un archivo adicional de layouts permanece fuera de este alcance.

**Fase 1 completada:** las once pruebas de Auth están en `auth/tests/`: seis archivos en `pages/`, tres en `services/`, uno en `context/` y uno en `utils/`. Se conservan nombres y casos, ajustando únicamente imports y rutas de mocks. Vitest descubre la ubicación sin cambiar su configuración y `src/test/setup.js` continúa como setup compartido.

**Verificado:** `pnpm test src/features/auth/tests`: **152 pruebas/11 archivos correctos**; `pnpm build` correcto con aviso conocido del bundle principal de 556,88 kB. Fases siguientes: Usuarios/Pacientes, Médicos/Especialidades y revisión/documentación global de la convención. No cambia comportamiento, contratos ni clases funcionales; este traslado no cierra nuevos CU ni modifica DER/diagrama global.

## Médicos y especialidades — fase 5: cierre administrativo local (2026-10-21)

Detalle ofrece «Crear cuenta para este médico» solo sin vínculo. CU-03 recibe `id_medico` como cadena, valida parámetro único/reconsulta y fija rol Médico/registro; vínculo existente impide alta. No infiere ID/estado de cuenta desde el booleano. Correo de acceso se introduce expresamente, separado del contacto; Laravel valida vinculación al guardar. Resultados inciertos bloquean repetición hasta revisar vínculo vigente.

**Integración real:** Chrome headless → React/Axios → Laravel → PostgreSQL, base/rol de pruebas aislados y esquema aleatorio con todas las migraciones (incluidas 000030–000032); solo correo sustituido por captura local. **17 comprobaciones**: catálogo/paginación/duplicado/edición/estado, alta de Médico con catálogo inactivo, 409 obsoleto/adopción, retiro/rehabilitación con historia, rechazo por cita futura/rollback, cuenta/invitación/aceptación/login/restauración y separación actividad/acceso. SQL final confirma un Médico nuevo, cita conservada, tres pares históricos y 25 eventos sin contraseña/token de prueba. Respuestas reales 200/201/204/401/403/409/419; negativas de permisos/CSRF también mediante fetch autenticado.

**Responsive/accesibilidad:** diez vistas a 320/1440 px, tres en límites 700/701, 960/961, 1100/1101 y 1920, confirmaciones móviles, texto al 200 % y espaciado ampliado. **52 comprobaciones sin overflow global/controles fuera de ancho y 23 auditorías axe sin infracciones automáticas**; selección por teclado, menú/Escape/retorno de foco, salto al contenido y semántica de tabla móvil comprobados. Capturas revisadas; sin excepciones JS ni Web Storage. Se corrigen corte de códigos largos, selección apilable y separación de enlaces de retorno. No equivale a certificación integral.

**Cierre:** `pnpm test` **521 pruebas/30 archivos**, 12 nuevas; build correcto, aviso conocido del bundle 556,88 kB. PHPUnit focalizado MedicosEspecialidadesIntegracionTest: **2 pruebas/140 aserciones**. Plan administrativo frontend CU-09/CU-10 completado localmente. README/análisis/casos/contexto §17.65 sincronizados; DER sin cambios nuevos, diagrama global expresamente aplazado. Auxiliares/evidencias `med5*` fuera de Git; esquema/secretos de prueba retirados y servidores temporales detenidos al finalizar. Consultorios/horarios/agenda y aceptación Railway/Brevo real conservan sus alcances posteriores; actualización PHP sigue aplazada.

## Médicos — fase 4 frontend (2026-10-21)

Detalle incorpora activación/inactivación profesional con confirmación y explicación de independencia de cuenta, rol, catálogo y sesiones. Se consume PATCH `/medicos/:id/estado` con versión exacta; vínculo incompatible dirige a gestión de cuentas y requiere revisar estado actual. No se alteran automáticamente citas ni obligaciones anteriores.

Nueva ruta de Administrador `/administracion/medicos/:id/especialidades`: consulta el conjunto habilitado, permite agregar/rehabilitar o retirar mediante catálogo paginado existente y mantiene como mínimo una habilitación, incluso con Médico/catálogo inactivos. Antes de guardar muestra adiciones y retiros; PUT `/medicos/:id/especialidades` envía el conjunto completo y `actualizado_en_original`. Rechazo 409 por citas futuras conserva selección y muestra que no se guardó ningún cambio. La comprobación de citas y la integridad histórica corresponden al backend. Versión obsoleta/resultado incierto exigen consulta y adopción explícita; nueva adopción reinicia confirmación. Éxito reconsulta detalle.

**Verificación:** `pnpm test`: **509 pruebas correctas/29 archivos**, 34 nuevas respecto de fase 3 (11 servicios y 23 pantallas). `pnpm build` y `git diff --check` correctos; aviso conocido del bundle principal (553,88 kB). Recorridos con servicios/Axios reales y transporte ficticio; no acreditan carreras PostgreSQL, consulta real de citas ni integración Laravel. Fase 5 pendiente: vínculo con creación de cuenta existente, integración real y revisión visual/responsive/accesible. Diagrama global aplazado expresamente.

## Médicos — fase 3 frontend (2026-10-21)

Alta (`/administracion/medicos/nuevo`), detalle (`/administracion/medicos/:id`) y edición de datos (`/administracion/medicos/:id/editar`) para Administrador. Formularios de registro profesional, nombres, apellidos, teléfono y correo de contacto; selección de una o varias especialidades iniciales con búsqueda/paginación, incluidas inactivas, selección persistente entre páginas y retirada individual antes del alta. Código generado por servidor, sin creación automática de cuenta, consultorio u horarios. Edición de datos mantiene independientes actividad, habilitaciones y cuenta; correo de contacto no cambia acceso, ni se exige unicidad no acordada de correo/registro profesional. Detalle muestra asociaciones habilitadas e históricas y estado del catálogo por separado.

Servicios `consultarMedico`, `crearMedico` y `actualizarMedico` sobre Axios/CSRF existentes, IDs BIGINT como cadenas, versión exacta y payloads explícitos. Borradores conservados ante errores; versión obsoleta exige consulta/adopción explícita. Resultados inciertos bloquean reenvío hasta revisar el registro; alta orienta al listado. Sin reintentos automáticos y con reconsulta de detalle después de éxito.

**Verificación:** `pnpm test`: **475 pruebas correctas/28 archivos**, incluidas 32 nuevas (12 de servicios, 20 de pantallas). `pnpm build` y `git diff --check` correctos. Aviso conocido de bundle principal: 545,88 kB. Se usan servicios/Axios reales con transporte ficticio; integración Laravel y revisión visual en navegador pendientes del cierre integrado. Siguiente fase 4: actividad profesional/habilitaciones; fase 5: vínculo con cuentas e integración. Diagrama global continúa aplazado expresamente.

## Especialidades — fase 2 frontend (2026-10-21)

Gestión de catálogo CU-09 para Administrador: alta en `/administracion/especialidades/nueva`, detalle en `/administracion/especialidades/:id` y edición en `/administracion/especialidades/:id/editar`. El listado enlaza a detalle y alta. Nombre obligatorio hasta 120 caracteres y descripción opcional hasta 2000, sin caracteres de control conforme al contrato actual (incluidos saltos de línea). Alta no permite imponer estado; detalle permite activación/inactivación con confirmación explícita y explica preservación de asociaciones/citas/historial.

`especialidad.service.js` incorpora consulta, creación, edición y estado con CSRF, listas explícitas de campos, IDs de texto y versión UTC de seis decimales conservada exactamente. Duplicado 409 permite corregir el nombre manteniendo borrador; edición obsoleta bloquea envío y consulta la versión vigente para comparación/adopción explícita. Los resultados inciertos de alta bloquean duplicación y orientan al catálogo; en edición/estado requieren revisar versión actual. Sin reintentos automáticos de escritura. Después de éxito se consulta nuevamente el detalle, con aviso de la operación confirmada.

**Verificación:** `pnpm test`: **443 pruebas correctas/26 archivos**, 34 nuevas respecto de fase 1; `pnpm build` y `git diff --check` correctos. Bundle principal 532,15 kB, con aviso conocido de tamaño. Pruebas de recorrido con Router/AuthContext/servicios/Axios reales y adaptador HTTP ficticio; no equivalen a integración Laravel ni revisión visual en navegador, pendientes del cierre integrado. Diagrama global aplazado; próximas fases: datos/alta de Médicos, actividad/habilitaciones y vínculo con creación de cuentas.

## Médicos y especialidades — fase 1 frontend (2026-10-21)

Listados exclusivos del Administrador en `/administracion/medicos` y `/administracion/especialidades`, con enlaces del menú, títulos y destino seguro tras login. Servicios de feature consumen GET `/medicos` y `/especialidades` mediante Axios central y `sessionBound`, con cancelación y descarte de respuestas obsoletas.

Especialidades permite buscar nombre y estado del catálogo; Médicos, nombre/apellidos, actividad profesional y especialidad habilitada. El selector consulta todo el catálogo mediante búsqueda y paginación, representa inactivas y conserva la selección al cambiar de página. La paginación utiliza `meta`, sin seguir URLs recibidas. Se mantienen IDs BIGINT como texto y versiones sin conversión. Estados de carga, vacío, errores seguros, validación y espera `Retry-After` con reconsulta manual. Tablas adaptables a tarjetas bajo 700 px, etiquetas visibles y foco en resultados al paginar.

**Verificación:** `pnpm test`: **409 pruebas correctas/25 archivos**, incluidas 30 nuevas de servicios y pantallas; `pnpm build` y `git diff --check` correctos. El build conserva el aviso del bundle principal mayor de 500 kB (519,80 kB). Esta fase se verifica con servicios simulados y adaptador Axios de prueba; integración Laravel y revisión visual en navegador de las nuevas pantallas siguen pendientes. Altas, detalles, ediciones, actividad/habilitaciones y enlace con creación de cuenta se implementarán en fases posteriores. Avance parcial CU-09/CU-10; diagrama global aplazado por decisión expresa.

## Mejora visual y responsive — fase 4 completada (2026-10-21)

**Plan visual de cuatro fases completado para las pantallas existentes.** Se conserva la identidad clínica acordada y se revisa el conjunto después de las mejoras de acceso, cuentas y Pacientes. Las secciones inferiores mantienen la cronología de cada fase.

**Correcciones de navegación:** `AuthLayout` y `MainLayout` restablecen el desplazamiento al cambiar de pantalla. El layout público enfoca el contenido al cambiar de ruta y su destino «Saltar al contenido» admite foco sin añadir una parada a Tab. La carga inicial pública conserva el recorrido de teclado desde la cabecera. `src/layouts/Navigation.test.jsx` incorpora dos pruebas de regresión para desplazamiento/foco en navegación pública y autenticada; `src/styles/global.css` evita el contorno de foco del contenedor público completo, manteniendo el de sus controles.

**Verificación final:** `pnpm test`: **379 pruebas correctas/23 archivos**; `pnpm build` y `git diff --check` correctos. Persiste el aviso conocido del bundle principal (~508 kB). Revisión Chrome headless sobre React con transporte API ficticio: 23 variantes públicas/autenticadas a 320/1440 px, seis vistas representativas en los límites 700/701, 960/961, 1100/1101, 1280 y 1920 px, texto raíz al 200 % y espaciado ampliado en seis vistas. **108 comprobaciones de distribución sin desbordamiento global ni controles fuera de ancho**, y **47 auditorías axe-core 4.10.3 sin infracciones automáticas**. Se verifican navegación desde el final de páginas largas, foco en contenido, salto al contenido, Tab/Shift+Tab/Escape en ayuda, devolución de foco, menú y cierre al cambiar de breakpoint, y reducción de movimiento. Sin excepciones JS ni Web Storage. Capturas representativas de escritorio amplio y transición del menú revisadas.

Esta revisión automatizada no equivale a una certificación completa de accesibilidad ni a una nueva integración Laravel/PostgreSQL o validación Railway. Auxiliares/evidencias fuera de Git y servidor temporal retirado. Documentación sincronizada en contexto §17.53 y análisis de fronteras; diagrama global con aplazamiento expreso. La fase termina la revisión visual de las vistas construidas, sin declarar implementados los módulos futuros.

## Mejora visual y responsive — fase 3 completada (2026-10-21)

Revisión específica de las áreas autenticadas existentes:

- **Cuentas:** filtros, paginación y acciones adaptables; tabla con filas apiladas hasta 700 px conservando correo, ID, rol, estado y detalle visibles. Semántica de tabla comprobada en escritorio y móvil. Detalle administrativo en tres/dos/una columnas según ancho; formularios de acciones con longitud de lectura limitada.
- **Personal:** composición de ayuda/formulario equilibrada, nombres/apellidos emparejados cuando hay espacio y botón principal completo. Invitaciones, confirmaciones y entrega temporal usan la base visual compartida.
- **Pacientes:** ficha con identidad y acciones a ancho completo, contacto y emergencia paralelos en escritorio y apilados hasta 1100 px. Edición y perfil con ancho máximo coherente de 1000 px. Activación presencial limitada a 900 px, con entrega legible y acción de ocultar adaptable.
- **Correo de acceso:** solicitud y verificación en columnas independientes en escritorio, secuencia vertical en tablet/móvil, código destacado y separaciones claras de las ayudas.
- **Inicio/áreas de los cuatro roles:** ancho de lectura limitado, distribución proporcional y textos de disponibilidad apilados en móvil.

Archivos principales: páginas/componentes y estilos de `src/features/usuarios/` y `src/features/pacientes/`, `HomePage.jsx` y `src/styles/workspace.css`. Se ajustó la espera asíncrona del foco en una prueba existente de login.

**Verificado:** regresión completa **377 pruebas/22 archivos** y build correctos; aviso conocido de bundle principal ~508 kB. Chrome headless con React y transporte API ficticio: once variantes de vistas a 320/390/768/1024/1440, inicio general/área para cuatro roles a 320/390/1440 y estados adicionales; 100 capturas/comprobaciones sin overflow global ni controles fuera de ancho. **32 auditorías axe-core 4.10.3 sin infracciones automáticas**. Tabla de cuentas con cuatro encabezados y doce celdas de muestra en árbol accesible en los cinco anchos. Recorridos: alta/reenvío, estado de cuenta, entrega/ocultación temporal y de activación, estado de ficha, edición con 409/adopción/guardado, perfil y cambio de correo hasta login. Vacío/500/403 y denegación de ruta revisados a 320 px; Tab/foco visibles, sin Web Storage ni excepciones JS. Capturas representativas revisadas. No acredita nueva integración Laravel ni despliegue. Auxiliares fuera de Git y servidor temporal retirado.

Contexto §17.52 y referencia visual en `analisis-clases.md` sincronizados. **Siguiente:** fase 4 de revisión integral. Diagrama global aplazado expresamente; sin cierre funcional adicional.

## Mejora visual y responsive — fase 2 completada (2026-10-21)

Acceso y seguridad adaptados a la base visual de la fase 1:

- **Registro/vinculación:** ancho propio de hasta 680 px, campos agrupados en ficha/datos personales, contacto y acceso, con dos columnas solo cuando hay espacio real. Indicador compartido de los dos pasos: datos y verificación del correo.
- **Verificación:** código de seis dígitos más legible, acciones principal/reenvío separadas y condiciones de vigencia/reenvío ordenadas. Se conserva la respuesta neutra sobre la entrega de correo.
- **Recuperación, invitación y acceso restringido:** ancho de lectura consistente, ayudas, enlaces de retorno y confirmaciones uniformes. El panel ilustrado se retira hasta 960 px para priorizar la tarea; en escritorio acompaña al formulario largo sin estirarse con él.
- **Cambio de contraseña autenticado:** formulario con espacio proporcional y botón completo; una columna en tablet/móvil, manteniendo la información sobre renovación de sesión.

Archivos principales: `src/layouts/AuthLayout.jsx`, páginas de `src/features/auth/pages/`, `RegistrationProgress.jsx`, `RegistrationSession.jsx`, `styles/access.css`, `styles/registration.css` y el icono de correo compartido.

**Verificado:** `pnpm test`: **377 pruebas correctas/22 archivos**. `pnpm build` correcto con aviso conocido de bundle >500 kB (~507 kB). Chrome headless con React y API ficticia: ocho vistas en 320/390/768/1024/1440 px sin overflow global ni controles fuera del ancho; registro horizontal en 960×600. Dieciséis auditorías axe-core 4.10.3 sin infracciones automáticas a 390/1440; capturas revisadas. Ambos recorridos de registro, código inválido/espera, confirmaciones, enlaces inválidos, salida restringida, sesión existente/no disponible y validación 422 comprobados. Tab/foco visible y mostrar/ocultar contraseña por teclado correctos; secretos limpiados, tokens retirados de URL, sin Web Storage ni excepciones JS. Transporte simulado exclusivamente para revisión visual, sin nueva integración Laravel ni despliegue remoto. Auxiliares/capturas fuera de Git y servidor temporal retirado.

README, contexto §17.51 y referencia de fronteras de CU-04 en `analisis-clases.md` sincronizados. **Siguiente:** fase 3, demás áreas autenticadas; después fase 4, revisión integral. El diagrama global conserva su aplazamiento expreso; no se cierra ningún CU adicional.

## Mejora visual y responsive — fase 1 completada (2026-10-21)

Evolución del diseño existente con `frontend-design`: se conservan el verde clínico, los fondos claros y Source Sans 3. Tokens de separación/radios/controles y tamaño de título afinados; gutters fluidos, botones y campos compartidos consistentes y modal con padding adaptable. Sin nuevas dependencias.

- **Estructura:** lateral de 248 px con navegación persistente en escritorio; menú compacto hasta 960 px, dos columnas en tablet y una en móvil. Cabecera de área de 80 px, identidad legible y contenido con más espacio útil. Escape cierra el menú y devuelve el foco; navegar o cambiar de breakpoint lo cierra. El foco inicial del contenido ya no desplaza la cabecera al cargar la ruta.
- **Login de referencia:** panel de marca refinado conservando su ilustración, mejor separación de campos y dos enlaces de primer acceso con áreas táctiles independientes.
- **Listado de Pacientes de referencia:** filtros en cuatro columnas a partir de 1280 px, dos en anchos intermedios y una en móvil. Tabla en escritorio y filas apiladas hasta 700 px, conservando nombre/código/CI/estado/«Ver ficha» y semántica de tabla en el árbol de accesibilidad. Sin desplazamiento horizontal para consultar o abrir las fichas.
- **Formulario de referencia:** `PatientForm` agrupa identidad, contacto y emergencia; CI con ayuda a ancho completo, nombres/apellidos y fecha/sexo emparejados en escritorio, una columna en móvil. Su reutilización aplica esas mejoras a edición y perfil. Validaciones y operaciones se conservan.

**Verificado:** regresión completa de 377 pruebas/22 archivos; después de los últimos ajustes de distribución/foco, 81 pruebas focalizadas correctas. `pnpm build` correcto con el aviso existente de bundle superior a 500 kB (~504 kB). Chrome headless con React real y respuestas API ficticias interceptadas solo para la revisión visual: login/listado/alta en 320, 390, 768, 1024 y 1440 px, sin overflow global ni controles fuera del ancho; estados vacío/error a 320 px; estructura compartida de los cuatro roles a 390/1440. Capturas revisadas, seis auditorías axe-core 4.10.3 sin infracciones automáticas en las tres vistas a 390/1440, tabla presente con sus cinco encabezados/quince celdas de muestra en todos los anchos, Tab/Escape/cierre de menú y reducción de movimiento comprobados. Sin excepciones JS ni Web Storage. Auxiliares/capturas fuera de Git; servidor temporal de revisión retirado. No acredita una nueva integración con Laravel ni un despliegue Railway.

**Siguiente:** fase 2 para el resto de acceso/seguridad; fase 3 para las demás áreas autenticadas; fase 4 de revisión integral. La base compartida ya beneficia a esas vistas, pero su revisión visual específica sigue pendiente. Contexto actualizado en §17.50; sin nuevos CU, cambios de API o persistencia y sin cambios en análisis de clases/DER. Diagrama global mantiene el aplazamiento expreso.

## Railway: React y API por el mismo origen

El `Dockerfile` compila React con Node/pnpm y `VITE_API_URL=/api`, y sirve `dist/` mediante Caddy. `Caddyfile` reenvía `/api`, `/api/*` y `/sanctum/csrf-cookie` al backend definido en `BACKEND_ORIGIN`; conserva Origin, Referer, cookies, cabecera CSRF, cuerpo y rutas. Solo cambia Host al destino para el enrutado de Railway. Las respuestas de Laravel no pasan por el fallback de React ni se cachean; las rutas UI sí permiten recarga de la SPA.

### Servicio frontend en Railway

1. Configurar **Root Directory: `/frontend`** y utilizar el **Dockerfile** de esa carpeta. Si el servicio tenía un comando de inicio personalizado (`pnpm preview`, `vite`, etc.), quitarlo para utilizar el `CMD` de la imagen. Quitar también overrides de build que sustituyan el proceso del Dockerfile.
2. Añadir esta variable **de servidor**, sin comillas ni barra final:

   ```dotenv
   BACKEND_ORIGIN=https://clinica-vida-salud-production.up.railway.app
   ```

   Debe ser únicamente el origen del backend, sin `/api`. No renombrarla a `VITE_*`: el navegador no necesita conocer el destino interno del proxy.
3. El Dockerfile fija `VITE_API_URL=/api` durante el build. Retirar la variable anterior con URL absoluta del servicio, o dejarla como `/api` para que la configuración visible coincida. No se usa Vite como servidor de producción.
4. El servidor escucha en `0.0.0.0:$PORT`; por defecto 8080. Usar ese puerto objetivo si Railway solicita uno y `/login` como healthcheck opcional. Railway termina HTTPS; Caddy atiende HTTP dentro del contenedor.
5. Volver a desplegar el frontend después de subir los archivos. No basta con cambiar la variable del build anterior.

### Variables relacionadas del backend

```dotenv
APP_URL=https://clinica-vida-salud-production.up.railway.app
FRONTEND_URL=https://striking-enchantment-production-a5d9.up.railway.app
SANCTUM_STATEFUL_DOMAINS=striking-enchantment-production-a5d9.up.railway.app,clinica-vida-salud-production.up.railway.app
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_PATH=/
```

**Eliminar `SESSION_DOMAIN`** para cookies host-only; no establecer `.up.railway.app`. Al solicitarse a través del proxy, tanto la cookie de sesión como XSRF-TOKEN quedan asociadas al origen del frontend. La primera continúa HttpOnly y la segunda permite a Axios adjuntar X-XSRF-TOKEN. Mantener la APP_KEY existente. Aplicar cambios/recrear la caché de configuración del backend mediante su despliegue. No se desactiva CSRF ni se introduce JWT o almacenamiento de sesión en Web Storage.

El navegador debe consultar `https://striking-enchantment-production-a5d9.up.railway.app/api/auth/me` y `/sanctum/csrf-cookie` en ese mismo host. Antes del login, `/api/auth/me` responde 401 normal; CSRF responde 204, login válido 200 y logout 204. Tras el cambio de arquitectura, limpiar datos de los sitios antiguos o probar en incógnito.

**Verificación local (2026-10-21):** build con `/api` correcto; Caddy 2.11.7 valida configuración y pasa comprobaciones de fallback SPA, assets/404, conservación de las dos cookies, Origin/Referer/Cookie/X-XSRF-TOKEN, cuerpo/query, status/Retry-After y ausencia de repetición del POST. Chrome con la compilación estática, Caddy, Laravel y PostgreSQL reales en esquema aislado: login/recarga/logout Recepcionista y Paciente, cabecera CSRF presente y guardado de perfil persistido, sin 419 ni excepciones JS/Web Storage. Auxiliares fuera de Git; fixture y servidores temporales retirados. Docker no está instalado localmente: la construcción de la imagen y el flujo HTTPS de Railway deben comprobarse al desplegar. No se declara resuelto el entorno remoto antes de esa comprobación.

## Estado actual: Pacientes fases 1–6 — integración local verificada

Implementados sesión, contraseñas, cuentas del personal/invitaciones, acceso/roles/credencial temporal, ambos recorridos de registro del Paciente (CU-04) y emisión presencial de Recepción (parte de CU-07). Revisión del cliente con 213 pruebas correctas y AUT-21 verificada en Chrome contra Laravel/PostgreSQL reales con identidades ficticias aisladas. El correo se capturó localmente: aceptación y entrega mediante Brevo permanecen pendientes. La cronología inferior conserva las limitaciones de cada fase; el resultado vigente está al final.

**Avance vigente de Pacientes:** plan frontend completado para gestión administrativa CU-07 y perfil/correo CU-08, con integración real local y regresión de **377 pruebas frontend**. La consulta de citas anteriores conserva su dependencia de CU-21; no se acredita RF02 íntegro por esta entrega. Cierre y límites de fase 6 a continuación de la cronología de Pacientes.

### Pacientes — fase 1 frontend (2026-10-20)

Recepción dispone de **Pacientes** en su menú y área, con listado en `/recepcion/pacientes` y detalle en `/recepcion/pacientes/:id`. Filtros por CI, nombres, apellidos y estado de ficha; búsqueda al enviar, limpieza y paginación por `meta` del servidor. Consultas GET `/api/pacientes` y `/api/pacientes/{id}` según contratos existentes. Presenta identidad, teléfono, correo de contacto, dirección y emergencia, incluidos datos ausentes; distingue ficha activa/inactiva de cuenta vinculada, sin inferir estado de acceso ni bloqueos.

Servicios con filtros permitidos, cookies y `sessionBound`; IDs como texto y timestamps intactos. Consultas cancelables y respuestas tardías descartadas; carga/vacío/error/reconsulta y 401/403/404/419/422/429/red/500. La UI no almacena fichas/filtros personales en Web Storage ni los agrega a URL. Activación existente mantiene emisión por ID y orienta al nuevo catálogo.

**Verificación actual:** `pnpm test`: **243 pruebas correctas/16 archivos** (30 nuevas); `pnpm build` correcto. Se corrigió una espera prematura en la prueba previa de invitación para esperar su confirmación. Chrome headless con API simulada recorrió listado → detalle → búsqueda vacía; Tab entre filtros, anchos 1440/768/390/320 sin overflow global, capturas revisadas y axe sin infracciones automáticas en listado/detalle a 1440/390. Sin excepciones JS. Esta revisión no acredita integración autenticada real de las nuevas consultas con Laravel/PostgreSQL.

**Siguientes fases:** 2 alta administrativa; 3 edición/CI/estado/activación desde ficha; 4 perfil propio; 5 correo verificado; 6 integración/responsive/cierre. Avance parcial de CU-07, sin cerrar CU-07/CU-08. Documentación: contexto §17.41 y análisis incremental; DER sin cambios y diagrama global aplazado expresamente. Comprobación real Brevo aplazada al despliegue por decisión del usuario.

### Pacientes — fase 2 frontend (2026-10-20)

**Registrar paciente** desde el listado abre `/recepcion/pacientes/nuevo`, exclusiva de Recepción. El formulario envía identidad/fecha civil, teléfono, sexo de texto opcional, correo de contacto/dirección y emergencia completa opcionales. Utiliza POST `/api/pacientes`, CSRF, cookies y campos permitidos; normaliza opcionales vacíos a NULL. No crea cuenta web, códigos ni preferencias. Solo 201 confirmado muestra éxito y enlace a la ficha persistida, preservando BIGINT textual.

**Duplicados y ausencia de CI:** búsqueda integrada por CI exacto o nombres/apellidos, incluidos inactivos, con paginación y enlaces a fichas en otra pestaña. Para alta sin CI se exige consulta exitosa y declaración de revisión; modificar identidad invalida lo revisado. No se fusionan fichas ni se presenta una coincidencia como prueba de titularidad o la falta de resultados como garantía de unicidad. El CI sigue siendo obligatorio para registro/vinculación web.

**Fallos:** doble envío bloqueado, 409 orientado a revisión, 422 por campo con conservación de datos y foco, 419 sin reenvío automático, 429 con espera, 403 sin logout y 401 retirando formulario. Un fallo de red/5xx o respuesta no confirmada conserva resultado incierto y exige consultar otra vez el registro antes de habilitar otro envío manual. No se afirma rollback ni ausencia de duplicados por una búsqueda. Datos solo en memoria, sin Web Storage.

**Verificación vigente:** **271 pruebas correctas/17 archivos**, 28 nuevas; `pnpm build` correcto. Incluye recorrido con servicios/Axios reales y transporte simulado (revisión → CSRF → alta sin CI → detalle) y revocación 401 al enviar. Chrome headless simulado revisa formulario con emergencia, confirmación y detalle; formulario/confirmación a 1440/768/390/320 sin overflow global, capturas revisadas y axe sin infracciones automáticas a 1440/390; teclado Tab correcto, un solo POST, Web Storage vacío y sin excepciones JS. Integración autenticada real de estas pantallas con Laravel/PostgreSQL aún pendiente.

**Siguiente entrega:** fase 3 edición/CI/estado/activación desde ficha. CU-07/CU-08 frontend conservan avance parcial. Documentación sincronizada en contexto §17.42 y análisis incremental; DER sin cambios y diagrama global aplazado expresamente.

### Pacientes — fase 3 frontend (2026-10-20)

Recepción puede editar desde la ficha mediante `/recepcion/pacientes/:id/editar`: contacto/emergencia y corrección de identidad con comprobación presencial, motivo y confirmación. El CI informado no se retira; incorporarlo o corregirlo advierte de la invalidación de códigos y vinculaciones pendientes, conservando cuenta e historial. Se envían únicamente diferencias permitidas por PATCH `/api/pacientes/{id}`.

El detalle permite desactivar/reactivar ficha con motivo y confirmación mediante PATCH `/api/pacientes/{id}/estado`. Se explica la independencia de cuenta web, sesiones, bloqueos y citas existentes. La activación desde `/recepcion/pacientes/:id/activacion` consulta la ficha, fija su ID y reutiliza `ActivationCodePage`; requiere CI y ausencia de cuenta vinculada, con comprobación final del servidor.

**Concurrencia y errores:** `actualizado_en_original` conserva los seis decimales sin pasar por `Date`. Conflicto obsoleto o resultado incierto bloquean nuevas escrituras y conservan el borrador/motivo mientras se consulta la versión actual. Cargarla y descartar el borrador exige una acción explícita; estado reinicia motivo/confirmación. Sin reenvíos automáticos ni Web Storage; campos seguros y espera Retry-After.

**Verificación vigente:** **306 pruebas correctas/18 archivos**, 35 nuevas; `pnpm build` correcto. Incluye permisos, payload parcial, CI, confirmaciones, microsegundos, 409 y revisión explícita con servicios/Axios reales y adaptador simulado, fallos inciertos y activación seleccionada. Chrome con API simulada completa CI → conflicto/revisión → estado → activación/ocultado; cuatro vistas a 1440/768/390/320 sin overflow global, capturas desktop/móvil revisadas para identidad y conflicto, axe sin infracciones automáticas a 1440/390, Web Storage vacío y sin excepciones JS.

**Siguiente entrega:** fase 4 perfil propio; después correo verificado e integración autenticada real de las pantallas con Laravel/PostgreSQL. CU-07/CU-08 siguen con cierre frontend pendiente. Contexto §17.43 y análisis incremental actualizados; diagrama global aplazado por decisión expresa. Brevo real sigue pendiente de despliegue.

### Pacientes — fase 4 frontend: Mi perfil (2026-10-20)

`/mi-perfil` está disponible exclusivamente para Paciente, desde el menú y su área personal. Consulta GET `/api/mi-perfil` y muestra identidad como solo lectura (CI, nombres, apellidos, nacimiento civil, sexo y estado), orientando correcciones a Recepción. Permite cambiar teléfono, correo de contacto, dirección, emergencia y preferencia de recordatorios mediante PATCH del mismo endpoint, sin ID del titular en URL/payload. Cuenta activa con ficha inactiva conserva estas operaciones; se informa de la restricción de nuevas reservas.

Reutiliza `PatientForm` limitado a contacto y emergencia y presenta recordatorios como elección explícita: NULL se mantiene «Sin preferencia registrada» sin inventar un valor. Retirar emergencia/deshabilitar avisos requiere confirmación. Correo de acceso se muestra separado y enlaza al cambio de contraseña ya existente. No carga información clínica. El cambio verificado de correo de acceso corresponde a fase 5.

Actualiza solo diferencias permitidas, con CSRF/cookies/sessionBound y `actualizado_en_original` intacto. Obsolescencia o resultado incierto conservan borrador y bloquean escrituras hasta revisión explícita de la versión propia actual, incluidas preferencias. Consulta cancelable, errores seguros por campo, Retry-After y retirada de datos al invalidarse sesión; sin Web Storage. Guardado confirmado reconsulta el perfil; una nueva tentativa retira el mensaje de éxito anterior.

**Verificación vigente:** **338 pruebas correctas/20 archivos**, 32 nuevas (23 interfaz/integración y 9 contrato); build correcto. Incluye Axios real con transporte simulado para GET → CSRF/PATCH → GET y 401 durante PATCH. Se ajustan esperas asíncronas anteriores de consulta de ficha, foco de activación y confirmación de invitación. Chrome con API simulada recorre edición de ficha inactiva, emergencia/preferencia, conflicto/adopción y confirmación de retirada/deshabilitación; tres vistas a 1440/768/390/320 sin overflow global, capturas de perfil móvil/conflicto desktop revisadas, axe sin infracciones automáticas a 1440/390, Tab entre contacto correcto, sin consultas administrativas, Web Storage vacío y sin excepciones JS.

Contexto §17.44 y análisis incremental sincronizados. Próxima fase 5: correo de acceso verificado; fase 6: integración autenticada real Laravel/PostgreSQL y cierre de CU-07/CU-08. DER sin cambios; diagrama global aplazado por decisión expresa. Comprobación real Brevo pendiente de despliegue.

### Pacientes — fase 5 frontend: cambio verificado del correo de acceso (2026-10-20)

Desde Mi perfil, el Paciente abre `/mi-perfil/correo`. La nueva solicitud requiere correo distinto, contraseña actual y confirmación de sustitución de una solicitud anterior. Consume POST `/api/mi-perfil/correo`, `/correo/reenviar` y `/correo/verificar` existentes. Presenta `envio` aceptado/simulado/fallido/pendiente sin afirmar llegada al buzón, junto al vencimiento del código según servidor; muestra espera de 60 segundos tras emisión y respeta Retry-After. Laravel determina límites, vigencia y consumo.

La verificación utiliza código de seis dígitos, preservando ceros iniciales; no envía destino, contraseña ni identificador. Se puede retomar la solicitud de la misma cuenta tras volver a iniciar sesión: el servidor selecciona la última solicitud y su destino, sin recuperar un proceso con CI/correo. El correo vigente permanece visible hasta confirmar; el correo de contacto es independiente. Código inválido/vencido/agotado tiene respuesta segura común, sin contadores locales autoritativos.

Solo 204 confirma el cambio. `AuthContext.completeEmailChange` coordina la escritura, invalida comprobaciones anteriores y limpia el contexto público con aviso de éxito al login, sin autenticación automática. Errores de red/5xx/respuesta no confirmada bloquean escrituras y exigen consultar explícitamente sesión/perfil antes de decidir otro envío, sin afirmar rollback o cambio exitoso. Contraseña y código se limpian tras sus operaciones, al salir y en pagehide; sin Web Storage, códigos ni contraseñas en URL.

**Verificación vigente:** **377 pruebas correctas/22 archivos**, 39 nuevas (26 interfaz/integración y 13 contratos). Incluye servicios/Axios reales con adaptador simulado para solicitud 202 → verificación 204 → login y 401 al verificar, carrera con comprobación /me anterior, roles, estados de transporte, reenvío, límites, secretos y resultado incierto. Build correcto con aviso de tamaño del bundle principal (~503 kB minificado). Chrome con API simulada completa Mi perfil → solicitud → confirmación → código → login; tres vistas a 1440/768/390/320 sin overflow global, capturas código móvil/confirmación desktop revisadas, axe sin infracciones automáticas a 1440/390, Tab correcto, Web Storage vacío y sin excepciones JS.

README/análisis/contexto §17.45 sincronizados. Fase 6 pendiente: integración autenticada real Laravel/PostgreSQL, revisión final y cierre frontend CU-07/CU-08. Persistencia/DER sin cambios; diagrama global aplazado expresamente. Entrega real Brevo permanece pendiente de despliegue según decisión del usuario.

### Pacientes — fase 6 frontend: integración real y cierre del plan (2026-10-20)

Tras iniciar PostgreSQL, Chrome headless recorrió React → Axios → Laravel → PostgreSQL reales. Se utilizó base/rol `clinica_vida_salud_test`, esquema aleatorio aislado, los cuatro roles y pacientes ficticios; frontend temporal en localhost:5174 y Laravel en localhost:8001. Sin interceptar respuestas de la API ni deshabilitar CSRF. Únicamente el transporte de correo se sustituyó por captura local para evitar envíos externos.

| Recorrido | Resultado verificado |
| --- | --- |
| Recepción | Listado, paginación, búsqueda, alta sin CI después de revisión explícita, detalle e incorporación del CI conservando ID. |
| Mantenimiento | Edición con 409 obsoleto real, borrador conservado/bloqueado, adopción explícita de versión actual y guardado posterior; desactivación/reactivación con motivo y confirmación. |
| Activación | Emisión desde ficha seleccionada con ID de solo lectura, código de 12 caracteres y ocultación tras comunicarlo. No crea cuenta. |
| Perfil | Cuenta activa/ficha inactiva editable; identidad solo consulta, contacto/emergencia/preferencia persistidos, correos independientes, 409 propio y confirmación de retirada de emergencia/deshabilitación. |
| Correo de acceso | Contraseña actual, solicitud 202/código capturado, correo anterior vigente hasta confirmar 204, retorno al login y nuevo acceso conservando ficha, contraseña y correo de contacto. |
| Protección | Roles ajenos rechazados sin cerrar sesión; sesión vencida retira datos privados; cookies HttpOnly/SameSite Lax, CSRF y CORS con credenciales reales. |

Respuestas reales observadas: **200/201/202/204/401/403/409/419/422/429**. Algunas comprobaciones negativas (CSRF ausente, campos prohibidos, permisos y límite de reenvío) se ejecutaron mediante fetch autenticado en el navegador; no se presentan como errores provocados desde controles normales. La comprobación SQL final verificó vínculo/identidad, cuenta activa con ficha inactiva, correo nuevo, contraseña conservada y bitácora sin contraseña ni códigos de la prueba.

**Verificación de cierre:** `pnpm test`: **377 pruebas correctas/22 archivos**; `pnpm build` correcto con el aviso conocido de bundle principal ~503 kB. Regresión focalizada Laravel de seis clases de Pacientes/perfil/correo: **134 pruebas/1272 aserciones correctas**. Las seis vistas reales (listado, detalle, alta, edición, perfil y correo) sin overflow global a 1440/768/390/320; axe sin infracciones automáticas a 1440/390, Tab comprobado en perfil, capturas listado desktop/perfil móvil revisadas. Sin excepciones JS ni Web Storage.

Auxiliares/evidencia no sensible en `C:\Users\jesus\AppData\Local\Temp\opencode\pac6*`, fuera de Git. Servidores temporales detenidos; esquema aislado y secretos temporales retirados al finalizar. No se requirieron correcciones del código funcional ni cambios de esquema. Análisis/contexto §17.47 y README backend sincronizados. Se completa el plan frontend del módulo dentro del alcance administrativo/perfil aprobado; CU-21, entrega real de Brevo/HTTPS de producción y diagrama global expresamente aplazado conservan su estado pendiente. El DER no cambia.

## Fase 1: base visual e inicio de sesión

React + Vite + JavaScript, React Router y Axios. Diseño con `frontend-design`, conforme a `AGENTS.md`. Primera pantalla funcional en `/login`; validación visual del usuario pendiente antes de extender el diseño.

### Desarrollo local

Requisitos: Node >=22.12 y pnpm 11.17.0; comprobados con Node 24.18.0. Las versiones efectivas quedan fijadas en `pnpm-lock.yaml`.

```powershell
pnpm install --frozen-lockfile
Copy-Item .env.example .env
pnpm dev
```

Abrir **http://localhost:5173/login**. Laravel debe estar ejecutándose por separado en **http://localhost:8000** y PostgreSQL disponible. La aplicación utiliza `VITE_API_URL` (por defecto local `http://localhost:8000/api`); esta variable es pública. No alternar `localhost` y `127.0.0.1`: cookies, CORS y Sanctum dependen del host. Puerto estricto 5173 para conservar el origen aprobado.

```powershell
pnpm test
pnpm build
pnpm preview
```

La compilación incluye fuentes locales; no consulta Google Fonts. Para otros entornos se debe proporcionar `VITE_API_URL` antes de compilar y coordinar HTTPS, cookies, CORS y dominios stateful con la configuración real del servidor. Esta entrega no configura producción.

## Sistema de diseño v0.1

**Dirección:** claridad clínica. Una identidad compartida para los cuatro roles, con diferencias de navegación/acciones según autorización. Superficies amplias, alineación izquierda, contenido funcional y color concentrado en identidad/acciones. Sin animaciones de entrada, degradados ni estadísticas decorativas. Composición de acceso en dos columnas; móvil prioriza el formulario y elimina la ilustración decorativa.

### Tokens reutilizables

Fuente de verdad: `src/styles/tokens.css`.

| Uso | Valor |
| --- | --- |
| Fondo / superficie | `#F5F8FA` / `#FFFFFF` |
| Texto / secundario | `#18333B` / `#526871` |
| Identidad / hover | `#146B68` / `#105653` |
| Borde estructural | `#D5E0E5`; campos usan `#81979F` para distinguir el control |
| Fondo editorial / ilustración | `#EAF3F1` / `#D1E6DF` |
| Error / éxito | `#A52E38` / `#206746`, con fondos semánticos claros |
| Información / advertencia | `#245B79` / `#805413`, con fondos semánticos claros |
| Tipografía | Source Sans 3 local; pesos 400/600/700, fallback system-ui |
| Tamaños | Texto 16 px; auxiliar 14 px; título fluido 28–36 px; interlineado 1,5 |
| Espaciado | 4, 8, 12, 16, 20, 24, 32, 40 y 48 px; margen lateral fluido 16–40 px |
| Radios | Controles 8 px; paneles/modales 14 px; panel editorial asimétrico 24/80 px, reducido en móvil |
| Interacción | Botones de 46 px mínimo; campos de 50 px; controles de icono de 44 px |

### Componentes implementados

- `Button`: principal, secundario, discreto y destructivo; carga identificada por texto/aria-busy y bloqueo del envío.
- `FormField`: etiqueta vinculada, ayuda/error asociados, required, autocomplete y error por campo. Contraseñas permiten pegar y mostrarse mediante un botón con nombre accesible.
- `Alert`: mensajes semánticos con icono/texto; error anunciado con role=alert. No depende solo del color.
- `Modal`: dialog nativo, título accesible, contención del foco, Escape, retorno al disparador y bloqueo del scroll de fondo. Usado en ayuda de acceso.
- `Brand` e `Icon`: identidad e iconos SVG locales, decorativos fuera del árbol accesible.
- `AuthLayout`: cabecera, ayuda, introducción visual, contenido y pie. Incluye enlace para saltar al contenido.

### Convenciones definidas para las siguientes pantallas

Se implementarán cuando exista una pantalla que las necesite:

- **Tablas:** HTML semántico, encabezado de 14 px/600, filas con padding 12–16 px, separadores horizontales, texto a la izquierda y números según significado; sin sombras por fila. Filtros visibles, conteo y paginación del servidor. En móvil, desplazamiento local identificable o detalle equivalente que preserve los datos.
- **Estados:** Programada, Confirmada, Atendida, Cancelada e Inasistencia conservan sus nombres oficiales cuando se desarrollen citas. Etiquetas con texto, nunca solo un punto de color. Cuenta, ficha y bloqueo se muestran por separado.
- **Vacío/carga/error:** indicar qué falta, qué está ocurriendo y la acción disponible; no inventar éxito, registros o permisos. Conservar el espacio del contenido cuando ayude a evitar saltos.
- **MainLayout:** implementado en fase 2 con navegación lateral en escritorio, menú accesible en móvil y cabecera contextual; mismo lenguaje visual para los cuatro roles.

## Flujo y contratos consumidos

`LoginPage → auth.service.js → api.js → Laravel`. La página coordina formulario/estado; los componentes presentan; el servicio realiza HTTP; Axios centraliza origen/cookies/CSRF. El backend sigue decidiendo cuenta, bloqueo, rol y autorización.

1. GET `/sanctum/csrf-cookie` con credenciales.
2. POST `/api/auth/login` con **solo** `correo_acceso` y `password`, usando `X-XSRF-TOKEN` de Axios. La contraseña no se recorta ni transforma.
3. Éxito 200: AuthContext conserva solo la proyección pública y dirige al inicio o destino interno seguro. El contexto restringido se dirige exclusivamente a `/clave-obligatoria`, sin asumir rol/identidad ni acceso ordinario.
4. GET `/api/auth/me` permite comprobar la sesión ante 409 o resultado incierto de red. No se reenvía automáticamente el login.
5. Cierre explícito: renovar CSRF y POST `/api/auth/logout`; el estado se limpia tras confirmación o rechazo 401 de una sesión ya inexistente. Fallos de red/500 no se anuncian como cierre exitoso.

No existen JWT, tokens en almacenamiento web, selección pública de rol, credenciales predeterminadas ni persistencia del estado de sesión en el cliente. Contraseñas se limpian después de cada envío y se ocultan de nuevo. El límite de 12 caracteres se aplicará al **establecer** contraseñas; login exige presencia y máximos del contrato, para admitir las credenciales autorizadas por el servidor.

Errores 401/403/409/419/422/429/500 y red tienen mensajes seguros. Se muestran únicamente errores de campos permitidos de 422; no se imprimen cuerpos HTTP técnicos. 429 respeta Retry-After numérico del servidor sin simular la política de bloqueo 3/10. Un nuevo envío manual obtiene CSRF otra vez; ninguna escritura se reintenta automáticamente.

## Verificación de fase 1

- **19 pruebas Vitest/Testing Library correctas:** cuatro de contrato HTTP y quince de interfaz; cuatro roles, acceso restringido, validación nativa/422 y foco, 401/403/419/500, 429, resultado incierto, duplicación y cierre confirmado/fallido.
- `pnpm build` correcto.
- Chrome headless real: anchos 1440, 768, 390 y 320 px sin desbordamiento horizontal; capturas desktop/móvil revisadas. Modal probado con Tab, Escape y retorno de foco.
- axe-core 4.10.3: **cero infracciones automáticas** WCAG 2 A/AA y 2.1 AA en la pantalla inicial móvil. Esto no sustituye revisión humana completa.
- Integración con Laravel local: formulario real obtiene cookies CSRF/HttpOnly y recibe rechazo 401 genérico con credenciales ficticias; petición sin CSRF recibe 419; `/auth/me` anónimo recibe 401. CORS con credenciales funciona. Sin excepciones JS durante el recorrido.
- Los accesos **exitosos** de cada rol se verifican con respuestas simuladas en pruebas; no se usaron credenciales de usuarios reales ni se crearon cuentas para este trabajo. AUT-21 completo sigue pendiente.

## Próximas fases

1. Base visual/login implementada; el usuario solicita continuar conservando la identidad.
2. Implementada: AuthContext/useAuth, restauración automática, MainLayout, rutas y navegación por rol.
3. Implementada con integración real pendiente: cambio ordinario y recuperación por enlace.
4. **Actual, implementada con integración real pendiente:** personal, invitaciones y consultas administrativas.
5. Estado/roles y credencial temporal/cambio obligatorio.
6. Registro nuevo, vinculación y emisión presencial.
7. Integración completa por rol, revisión final y entrega real de Brevo autorizada.

El inicio autenticado identifica explícitamente las funciones posteriores pendientes. No hay enlaces ficticios hacia registro/recuperación ni datos de negocio simulados en la aplicación. No se modificaron el backend o las reglas funcionales. El diagrama global sigue aplazado por decisión del usuario.

## Fase 2 — sesión, layout y permisos (2026-10-20)

### Componentes y responsabilidades

- `features/auth/context/AuthContext.jsx`: única fuente del estado **público** de autenticación en React. Estados loading/anonymous/authenticated/restricted/unavailable; valida y proyecta la respuesta del servidor, sin guardar credenciales ni información clínica.
- `hooks/useAuth.js`: acceso al contexto. Expone usuario, loading, isAuthenticated (solo acceso normal), isRestricted, login, logout y refreshSession.
- `SessionGate`: espera la comprobación inicial y ofrece reintento ante fallos de conexión/403/419/500. Un fallo operativo no se confunde con sesión ausente y no deja visible el área privada.
- `ProtectedRoute`, `GuestRoute` y `RoleRoute`: controlan acceso visual, destino posterior al login y denegación por rol. La autorización efectiva sigue en Laravel.
- `MainLayout`: cabecera con nombre/rol, navegación exclusiva del rol, contenido y cierre. Menú móvil con aria-expanded/controls, Escape y retorno de foco; navegación identifica la página activa y enfoca contenido al cambiar de ruta.
- `LogoutButton`: bloqueo de envíos duplicados y mensaje de resultado incierto si falla el cierre.
- `HomePage`, `AccessDeniedPage` y `RestrictedAccessPage`: inicio común/área de rol, acceso denegado sin logout y frontera mínima restringida. El cambio obligatorio real sigue pendiente para fase 5.
- `constants/roles.js`: cuatro roles fijos, sus áreas y lista permitida de destinos internos; no hay asignación pública de rol ni redirecciones externas.

### Rutas UI

| Ruta | Acceso |
| --- | --- |
| `/login` | Anónimo; sesión normal redirige a inicio/destino seguro y restringida a cambio obligatorio. |
| `/inicio` | Cualquiera de los cuatro roles con sesión normal comprobada. |
| `/administracion` | Administrador. |
| `/recepcion` | Recepcionista. |
| `/medico` | Médico. |
| `/paciente` | Paciente. |
| `/clave-obligatoria` | Exclusivamente sesión restringida; sin menú ni identidad ordinarios. |

Las cuatro áreas muestran una introducción, la proyección pública de cuenta y funciones previstas rotuladas «Próximamente». No conceden operaciones clínicas/administrativas ni llaman a endpoints nuevos. Una ruta de otro rol muestra acceso denegado conservando la sesión; una ruta desconocida muestra página no encontrada dentro del área normal. `/` conduce al inicio y sus controles de sesión.

### Restauración, errores y concurrencia del cliente

Al montar se consulta GET `/api/auth/me`, sin duplicar la restauración bajo StrictMode. No se muestran datos ni se redirige mientras la comprobación inicial está pendiente. Al volver a una pestaña/ventana visible se revalida; no se agrega polling ni se asume que una sesión siga válida por conservar un objeto en memoria.

Las peticiones autenticadas del servicio se marcan con **`sessionBound: true`**. Los servicios protegidos futuros deben usar esa opción de la instancia Axios común. Un interceptor de 401 notifica al contexto sin depender de React: limpia la identidad y retira la vista privada. Un 401 de login no provoca esa invalidación; 403 no cierra la sesión, y 419 nunca reenvía escrituras automáticamente.

Un contador de generación en HTTP y una secuencia de operaciones en AuthContext descartan respuestas de contextos anteriores: una restauración tardía no puede resucitar un logout y un 401 antiguo no expira un login nuevo. Las consultas simultáneas de restauración se comparten. No se introducen tokens propios, persistencia de usuario ni dependencias nuevas.

### Evidencia

- **43 pruebas correctas:** 4 de contrato HTTP, 15 de login adaptadas al contexto y 24 de sesión/rutas. Cubren espera inicial y StrictMode, cuatro roles, URLs manipuladas, restauración restringida, fallos de comprobación/reintento, almacenamiento falso ignorado, revocación al volver a la ventana, 401/403 protegidos, respuestas tardías, logout y destinos externos rechazados.
- `pnpm build` correcto.
- Chrome real con respuestas ficticias interceptadas para las áreas autenticadas: los cuatro roles restauran tras recarga, navegación manipulada se deniega, logout retira el área, sesión restringida no recibe navegación y revocación al volver al foco dirige a login.
- Layout revisado en capturas de escritorio/móvil y anchos 1440/768/390/320 sin overflow horizontal. Menú móvil y Escape/retorno de foco comprobados; control móvil oculto en escritorio.
- axe-core 4.10.3: **cero infracciones automáticas** WCAG 2 A/AA y 2.1 AA en inicio autenticado desktop y móvil. Sin excepciones JS en el recorrido.
- Laravel real, navegador sin sesión: acceso directo a `/inicio` recibe rechazo de `/auth/me` y redirige al login. Los éxitos autenticados/recargas por rol en esta fase se verifican con respuestas simuladas, sin usar cuentas reales. AUT-21 completo sigue pendiente.

Documentación sincronizada en contexto §17.28 y análisis incremental CU-01/CU-02. Siguiente entrega: **fase 3, cambio ordinario y recuperación de contraseña**. DER y diagrama global no cambian.

## Fase 3 — CU-05/CU-06 (2026-10-20)

### Pantallas y contratos

| Ruta UI | Acceso y operación |
| --- | --- |
| `/mi-cuenta/contrasena` | Sesión normal de cualquiera de los cuatro roles. PUT `/api/auth/password` con `current_password`, `password` y `password_confirmation`. La sesión restringida sigue en su flujo separado. |
| `/olvide-mi-contrasena` | Solicitud pública. POST `/api/auth/password/forgot` con `correo_acceso`; tras 202 se muestra exclusivamente el mensaje genérico «Si corresponde…», sin afirmar existencia ni entrega. |
| `/recuperar-clave#token=…` | Enlace emitido por Laravel. POST `/api/auth/password/reset` con `token`, `password` y `password_confirmation`. Sin login automático. |

`ChangePasswordPage`, `ForgotPasswordPage` y `ResetPasswordPage` coordinan formularios; `PasswordField` reutiliza etiquetas, ayuda y controles de visibilidad accesibles. `usePasswordSubmission` controla duplicación, errores seguros, foco y espera del `Retry-After` recibido. `password.service.js` obtiene CSRF antes de cada escritura y envía exclusivamente los campos del contrato por Axios; solo el cambio ordinario usa `sessionBound: true`.

`AuthContext.updatePassword` coordina el cambio/restablecimiento y consulta `/me` después del éxito. Descarta comprobaciones anteriores mediante la secuencia/generación existente. Laravel renueva la sesión actual al cambiar ordinariamente e invalida sesiones de la cuenta recuperada al restablecer; una sesión de otra cuenta en el mismo navegador se conserva únicamente si `/me` lo confirma. No se guardan contraseñas ni tokens en el contexto.

El enlace se captura antes de crear el router, retirando query y fragmento del historial actual. Solo se acepta un token hexadecimal de 64 caracteres en el fragmento; no se aceptan tokens por query. La entrega temporal al formulario se borra al montarlo; el token queda en memoria local hasta éxito, rechazo definitivo o desmontaje. Recargar exige abrir de nuevo el enlace del correo. No se coloca el secreto en campos ocultos, Router state, almacenamiento web ni logs.

Validación visual de mínimo 12 caracteres y coincidencia; Laravel conserva las comprobaciones definitivas. Las contraseñas se limpian al terminar cada envío HTTP. Errores 422 por campo, contraseña actual incorrecta y enlace inválido/vencido/usado se presentan sin cuerpos técnicos. 419, 429, fallos de servidor y red no producen éxito ni reenvíos automáticos; un fallo de red comunica resultado incierto.

### Evidencia y pendientes

- **81 pruebas correctas en ejecuciones focalizadas:** 43 de regresión anterior y 38 de fase 3 (22 de pantallas, nueve de contratos HTTP y siete de captura del enlace). Incluyen cuatro roles, restricción, validación/foco, duplicación, Retry-After, errores, StrictMode, respuesta tardía, limpieza de secretos y nuevo login tras recuperación.
- `pnpm build` correcto. No hay script de lint configurado.
- Chrome headless con respuestas simuladas: solicitud genérica, cambio con reconsulta de sesión, recuperación sin login automático, token retirado de URL/DOM y no recuperable tras recarga. Sin excepciones JavaScript.
- Capturas desktop/móvil revisadas; cambio sin overflow en 1440/768/390/320 px y solicitud sin overflow en 1440/390 px.
- Axe-core 4.10.3: cero infracciones automáticas WCAG 2 A/AA y 2.1 AA en las tres pantallas a 1440 y 390 px; no sustituye una auditoría humana completa.
- **Integración real de fase 3 pendiente:** el intento de navegador no completó la comprobación de sesión y PostgreSQL no escuchaba en el puerto local esperado (5432). No se acredita un rechazo real de reset ni cambios reales de credenciales. Entrega Brevo y AUT-21 completo permanecen pendientes.

Análisis incremental CU-05/CU-06 y contexto §17.29 sincronizados. Siguiente fase: **4, personal, invitaciones y consulta administrativa**. El diagrama global continúa aplazado expresamente; no hubo cambios de persistencia.

## Fase 4 — consulta de cuentas, alta e invitaciones, CU-03 parcial (2026-10-20)

### Rutas y contratos

| Ruta UI | Comportamiento |
| --- | --- |
| `/administracion/cuentas` | Solo Administrador normal. GET `/api/usuarios` con filtros opcionales de rol, estado y correo exacto, y paginación del servidor. |
| `/administracion/cuentas/nueva` | POST `/api/personal` con correo/rol y nombres/apellidos para Administrador o Recepcionista; para Médico, solo referencia `id_medico` existente. Sin contraseña administrativa ni alta de Paciente. |
| `/administracion/cuentas/:id` | GET `/api/usuarios/{id}` con datos mínimos autorizados. Reenvío inicial mediante POST `/api/personal/{id}/invitacion`, solo ofrecido para personal pendiente sin correo verificado y con confirmación visual. |
| `/activar-personal#token=…` | Aceptación pública de invitación mediante POST `/api/auth/invitaciones/aceptar` con token, nueva contraseña y confirmación. Si hay sesión normal o restringida, exige cerrarla primero. |

El backend sigue validando permisos, unicidad, vínculo profesional y elegibilidad en cada operación. No existe aún un endpoint de catálogo de médicos; el alta pide el identificador profesional previo como texto, sin convertir BIGINT a Number ni crear registros profesionales. Los identificadores se validan antes de construir rutas.

### Organización y estados

- `features/usuarios/pages/`: listado, alta y detalle. Componentes específicos para tabla, errores y resultado de envío; estilos locales en `styles/accounts.css`.
- `usuario.service.js`: llamadas con la instancia Axios común y `sessionBound: true`; CSRF previo a escrituras, campos explícitos según rol y sin reintentos automáticos. La página nunca sigue URLs de paginación devueltas por la API: usa `meta` para solicitar la página al endpoint fijo.
- `useAccountQuery`: carga, datos, vacío/error, cancelación con AbortController e ignorado de respuestas de filtros/rutas anteriores. Al cambiar filtros o actualizar detalle retira la respuesta previa hasta recibir la nueva.
- `useAccountSubmission`: exclusión de envíos duplicados, errores seguros, foco y espera conforme al Retry-After recibido. 422 conserva campos corregibles; un timeout de alta orienta a consultar la cuenta por correo exacto antes de repetir.
- `SelectField` reutilizado en filtros y alta, con el mismo lenguaje visual de `FormField`. Tabla semántica desplazable localmente por teclado/táctil y aviso móvil; filtros se apilan sin perder información. Menú marca únicamente la sección activa.
- Paciente recibe solo proyección administrativa mínima, sin nombres clínicos, documento, vínculo profesional o invitación; no hay formularios de creación/contraseña/rol de Paciente.
- Resultados de invitación distinguen **simulado**, **aceptado por proveedor**, **fallido** y **pendiente**. Alta confirmada permanece pendiente aunque falle el envío; se ofrece consulta de la misma cuenta. Reenvío invalida el enlace anterior según Laravel y vuelve a consultar detalle, sin crear otra cuenta.
- `AcceptInvitationPage` usa controles de contraseña y captura del fragmento existentes. Retira el token antes del router y de la entrega temporal al montarse; solo memoria local, sin campos ocultos ni almacenamiento web. Ausencia/vencimiento/uso/revocación orienta a Administración; éxito confirmado exige login, sin autenticación automática. La sesión activa puede cerrarse conservando el token local mientras la página siga montada.

### Verificación y avance

- **131 pruebas correctas en ejecuciones focalizadas:** 81 anteriores y 50 nuevas (22 de interfaz administrativa, 16 de servicios/contratos, 11 de aceptación y una adicional de captura del enlace).
- Incluyen denegación para los otros tres roles, filtro/paginación, cancelación/respuestas tardías, vacío/403/404/500, BIGINT, exclusión de campos, roles de alta, envío simulado/aceptado/fallido/pendiente, duplicación, 422/foco, conflicto de correo, resultado incierto y Retry-After de reenvío. Aceptación cubre StrictMode, sesión normal/restringida, enlace no disponible, 419/422/429/500/red, limpieza de claves y login posterior.
- `pnpm build` y `git diff --check` correctos; sin script de lint configurado.
- Chrome headless con respuestas simuladas: consulta y denegación de roles, alta con envío fallido, reenvío simulado sobre la misma cuenta, detalle mínimo de Paciente y aceptación sin login automático. Token retirado de URL/DOM y ausente tras recarga; sin excepciones JavaScript.
- Listado, alta y detalle sin overflow global a 1440/768/390/320 px; tabla conserva desplazamiento local. Aceptación revisada a 1440/390/320 px. Capturas desktop/móvil revisadas.
- Axe-core 4.10.3: cero infracciones automáticas WCAG 2 A/AA y 2.1 AA en las cuatro pantallas a 1440 y 390 px. No equivale a una auditoría humana completa.
- PostgreSQL sigue sin escucha local en el puerto esperado 5432; integración autenticada real, AUT-21 y entrega Brevo siguen pendientes. No se crearon cuentas reales ni se enviaron correos en las verificaciones.

Análisis incremental CU-03 y contexto §17.30 actualizados. **Siguiente fase 5:** estado de acceso, roles y credencial temporal/cambio obligatorio. CU-03 y el módulo frontend aún no se declaran completos. Diagrama global aplazado expresamente y persistencia/DER sin cambios.

## Fase 5 — acceso, roles y credencial temporal (2026-10-20)

`AccountDetailPage` incorpora `AccountActions` con confirmaciones de desactivación/reactivación, asignación de roles del personal y emisión presencial de credencial temporal. Consume PATCH `/api/usuarios/{id}/estado`, PATCH `/api/usuarios/{id}/rol` y POST `/api/usuarios/{id}/credencial-temporal`, con CSRF, `sessionBound: true` y campos explícitos. Laravel conserva las decisiones sobre último Administrador, identidad profesional, obligaciones médicas y elegibilidad.

- Paciente solo admite desactivar/reactivar cuentas previamente verificadas; no tiene cambio de rol ni credencial temporal. Reactivar no activa la ficha ni elimina bloqueos.
- El cambio a Médico conserva el vínculo histórico o pide ID profesional como texto y comprobación presencial para un vínculo inicial. Retirar Médico comunica las restricciones asistenciales sin mostrar datos clínicos.
- `AuthContext.mutateAccount` descarta comprobaciones anteriores y reconsulta `/me` tras una escritura confirmada. Los cambios propios que revocan sesión retiran el área y exigen login.
- `CredentialReceiptHost` reemplaza el área enrutada por una entrega aislada de una sola consulta, incluso al emitir sobre la propia cuenta y revocarse la sesión. El secreto se mantiene solo en memoria local del componente, fuera de AuthContext, URL, Router state y almacenamiento web; se retira al ocultar, cambiar de ruta o abandonar la página. Se comunica vencimiento con zona UTC explícita y entrega presencial.
- `/clave-obligatoria` permite PUT `/api/auth/password/temporal` con nueva contraseña y confirmación, sin ID ni contraseña anterior. Tras 204 limpia la sesión restringida y exige nuevo login. El cierre sin completar advierte que invalida la temporal. No hay navegación normal con acceso restringido.
- Errores seguros por código/campo, limpieza de contraseñas, foco, duplicación bloqueada y Retry-After; ningún fallo de red produce éxito ni reenvío automático.

**Verificación:** `pnpm test`: **167 pruebas correctas en 11 archivos**, 36 nuevas de esta fase. `pnpm build` correcto. Chrome headless con respuestas simuladas verifica estado de Paciente, cambio a Médico, emisión para otra cuenta y propia, ocultación del secreto y cambio obligatorio con login posterior. Cinco vistas revisadas a 1440/768/390/320 px sin overflow global; axe-core 4.10.3 sin infracciones automáticas WCAG 2 A/AA y 2.1 AA a 1440/390 px, sin excepciones JavaScript. Capturas desktop/móvil revisadas; estas comprobaciones no equivalen a una auditoría humana completa.

Contexto §17.31 y análisis incremental CU-03/CU-05 sincronizados. Integración real autenticada de fases 3–5, AUT-21 y entrega Brevo pendientes: PostgreSQL sigue sin escucha local en 5432. No se utilizaron cuentas reales ni correos externos. El módulo frontend aún no se declara cerrado; **siguiente fase 6: registro/vinculación del Paciente y emisión autorizada de Recepción**. Diagrama global aplazado expresamente; persistencia y DER sin cambios.

## Fase 6 — CU-04 y emisión parcial de CU-07 (2026-10-20)

| Ruta UI | Contrato y alcance |
| --- | --- |
| `/registro` | Paciente nuevo: POST `/api/auth/registro`, CI obligatorio, datos personales/contacto y contraseña. No exige código de Recepción. |
| `/vincular-ficha` | Ficha existente: POST `/api/auth/registro/vincular`, exclusivamente CI, código presencial de 12 caracteres, correo y contraseña/confirmación. |
| `/verificar-registro` | Ambos escenarios: POST `/api/auth/registro/verificar` con `codigo` de seis dígitos como texto; reenvío mediante POST `/api/auth/registro/reenviar` sin selector de proceso/correo. |
| `/recepcion/activacion-web` | Solo Recepcionista normal: POST `/api/pacientes/{id}/codigo-activacion` con `identidad_verificada: true`. Confirmación de sustitución y entrega presencial única. |

`RegisterPatientPage`, `VerifyRegistrationPage` y `RegistrationSession` coordinan el recorrido público; `registration.service.js` limita campos y usa la instancia Axios/CSRF compartida. El navegador conserva el contexto HttpOnly del servidor sin leerlo, copiarlo a estado ni almacenarlo en Web Storage. Una sesión normal o restringida exige cierre previo; se informa la invalidación de temporal al salir del acceso restringido. Los enlaces de login/ayuda permiten elegir ambos recorridos y reabrir verificación en el mismo navegador tras una recarga, sin pedir ID/correo para recuperar un proceso.

Inicio/reenvío 202 muestran **solicitud recibida, sin confirmar envío ni existencia**. El frontend no infiere códigos vencidos/agotados o cuentas existentes a partir de respuestas genéricas. El rechazo `REGISTRO_VERIFICACION_INVALIDA` mantiene orientación segura hacia código reciente, reenvío y Recepción. Solo 204 de verificación muestra activación y exige login, sin autenticación automática. Se explican 24 h de proceso/autorización, diez minutos y cinco errores del correo, reenvío mínimo 60 s y cinco por hora; el servidor aplica los límites. El contador de espera solo representa `Retry-After` recibido y no simula límites ocultos ni acredita envío.

CI conserva ceros, caso y signos; la ayuda valida presencia y caracteres permitidos sin inventar patrón civil. Sexo es opcional y libre según API. Correo de contacto opcional permanece separado del acceso. Datos de perfil no se envían en vinculación. Contraseñas y código presencial se limpian tras envío; código de correo tras verificar/reenviar. No se guardan secretos en URL, Router state, logs o almacenamiento web. Respuestas de inicio tardías tras desmontaje no redirigen otra pantalla. Escrituras no se reintentan automáticamente.

`ActivationCodePage` y `activation.service.js` viven en `features/pacientes/`. Todavía no existe catálogo HTTP de pacientes: Recepción introduce el identificador interno textual de la ficha que ya comprobó presencialmente, nunca el CI como ID de ruta. Laravel verifica ficha, CI, vínculo y rol. La emisión no crea cuenta ni activa ficha; un reemplazo invalida la autorización y procesos anteriores. Se muestra el código solo en memoria local hasta ocultar/navegar/abandonar, con vencimiento UTC explícito. Un fallo de red no fabrica una entrega.

**Verificación:** 201 pruebas correctas en 13 archivos, 34 nuevas (24 de comportamiento y diez de contratos); build y `git diff --check` correctos. Incluyen anonimato previo/cierre de los cuatro roles y sesión restringida, ambos escenarios, CI obligatorio, campos explícitos, BIGINT textual, conservación de ceros, limpieza de secretos, respuesta tardía, duplicación, errores 419/422/429/500/red, reenvío genérico y permisos de Recepción. Ajustada una comprobación asíncrona anterior de limpieza del token de invitación para esperar su efecto de React.

Chrome headless con respuestas simuladas completa Nuevo y Vinculación → verificación → login posterior, reenvío y emisión/ocultación presencial. Cinco vistas comprobadas a 1440/768/390/320 px sin overflow global; capturas desktop/móvil revisadas. Axe-core 4.10.3 sin infracciones automáticas WCAG 2 A/AA y 2.1 AA a 1440/390 px; sin excepciones JavaScript. No equivale a auditoría humana completa.

Contexto §17.32 y análisis incremental CU-04/CU-07 sincronizados. Integración real de fases 3–6, AUT-21 y Brevo siguen pendientes: PostgreSQL sin escucha local en 5432. No se crearon cuentas reales ni enviaron correos externos. **Siguiente fase 7: revisión e integración final del módulo frontend**; CRUD administrativo restante de CU-07 es posterior. Diagrama global continúa aplazado expresamente; backend, persistencia y DER sin modificaciones.

## Fase 7 — revisión integrada y cierre condicionado (2026-10-20)

Revisados router/guards, sesión, servicios Axios, generación HTTP, enlaces secretos, errores y consultas cancelables. Se mantienen los 20 endpoints API del backend consolidado, cuatro roles fijos y separación Page/Component → Service → Axios → Laravel. No se detectó necesidad de cambiar código productivo en esta revisión.

`src/features/auth/tests/pages/AuthIntegration.test.jsx` añade **12 pruebas integradas del cliente**: se montan router, páginas, AuthProvider, servicios e interceptores reales y se sustituye únicamente el adaptador HTTP de Axios. Permite detectar incompatibilidades entre capas del frontend que las pruebas con servicios simulados no verifican. No comprueba cookies del navegador, CORS, PostgreSQL, reglas clínicas ni autenticación real de Laravel.

| CU / recorrido | Evidencia disponible | Pendiente real |
| --- | --- | --- |
| CU-01/CU-02 | Cuatro roles: login → destino → desmontaje/remontaje y `/me` → logout; 403 conserva sesión y 401 protegido retira el área mediante interceptor. | AUT-21: mismo recorrido con cookies cross-origin y Laravel. |
| CU-03 | Alta → detalle → reenvío sobre la misma cuenta con envío simulado; emisión sobre cuenta propia → `/me` 401 → entrega aislada → login. Pruebas específicas anteriores cubren estado, roles, último Administrador y restricciones de Paciente/Médico. | Escrituras y revocación reales; invitación/entrega de correo. |
| CU-04 | Nuevo y Vinculación → verificación 204 → login → CU-05 → logout, con campos reales de los servicios. | Persistencia de ficha/cuenta/vínculos, contexto de cookie y códigos reales de prueba. |
| CU-05 | Cambio ordinario → reconsulta de sesión; temporal → formulario restringido → 204 → nuevo login normal, sin elevar automáticamente la sesión. | Renovación/revocación multidispositivo en Laravel. |
| CU-06 | Solicitud genérica → enlace → reset 204 → nuevo login; también invitación aceptada sin login automático y retirada del secreto. | Entrega del enlace y consumo real de tokens/sesiones. |
| CU-07 parcial | Emisión de Recepción y entrega única verificadas en fase 6 con simulación. | Emisión real; CRUD administrativo restante fuera de este cierre. |

**Resultado actual:** `pnpm test` → **213 pruebas correctas en 14 archivos**; `pnpm build` y `git diff --check` correctos. La nueva suite complementa las 201 pruebas y evidencias de Chrome/axe/responsive de fases anteriores; no se presentan esas capturas históricas como una nueva ejecución en esta fase. Sin dependencias, cambios de backend, persistencia o reglas.

### Bloqueo comprobado y reanudación de AUT-21

Vite y Laravel escuchan en 5173 y 8000. `Get-Service` confirma **`postgresql-x64-18` detenido**, sin escucha en 5432. `Start-Service` falló porque esta sesión no puede abrir el servicio. No se modificó su configuración ni se ejecutaron migraciones/seeders.

Para habilitar la integración, iniciar el servicio desde Servicios de Windows o desde PowerShell con permisos de Administrador:

```powershell
Start-Service -Name 'postgresql-x64-18'
Get-NetTCPConnection -State Listen -LocalPort 5432
```

Con PostgreSQL disponible, reanudar en `http://localhost:5173` con Laravel en `http://localhost:8000`, manteniendo el mismo host:

1. Comprobar `/auth/me` anónimo, cookies CSRF/HttpOnly y CORS con credenciales; una escritura sin CSRF debe rechazarse, sin reintento automático.
2. Para cada rol de prueba: login → destino autorizado → recarga y `/me` → intento de ruta ajena → logout confirmado → ruta protegida denegada.
3. Comprobar sesión expirada/revocada al volver a la pestaña y diferencia entre 401, 403 y 419; errores de validación, conflicto y Retry-After deben conservar la semántica documentada.
4. Recorrer CU-03–CU-06 y emisión parcial de CU-07 con identidades ficticias: invitación, acceso/roles, temporal/cambio obligatorio, registro nuevo/vinculación, cambio ordinario y recuperación. Contrastar persistencia y revocación con las respuestas de la API.
5. Registrar por separado la aceptación de Brevo y la llegada efectiva a buzón; una respuesta simulada o aceptación del proveedor no acredita entrega.

Este era el bloqueo de §17.33; quedó resuelto en la ejecución siguiente. Diagrama global sigue aplazado expresamente y DER sin cambios.

### AUT-21 — integración local real completada (2026-10-20)

Tras iniciar PostgreSQL, Chrome headless/CDP ejecutó React en `http://localhost:5174` contra Laravel en `http://localhost:8001`. Se usaron base/rol `clinica_vida_salud_test` y un esquema aleatorio exclusivo, con migraciones existentes y cuentas ficticias. Los puertos, origen CORS, Sanctum y cookie propios de prueba se configuraron en un auxiliar temporal, sin editar configuración productiva. Solo se sustituyó CorreoSeguridadService para capturar mensajes localmente; autenticación, middleware, CSRF, API y persistencia fueron reales.

| Recorrido | Resultado comprobado |
| --- | --- |
| CU-01/CU-02 y AUT-21 | Login, `/me`, recarga, área propia, ruta ajena denegada sin logout y cierre confirmado para cuatro roles. Expiración del Paciente al volver al foco retira el área privada. Cookie de sesión HttpOnly/SameSite=Lax, CSRF legible y CORS con credenciales/origen exacto. |
| CU-03 | Alta de Recepcionista, detalle, aceptación de invitación, cambio a Administrador, desactivación/reactivación y nuevo login con rol persistido. Emisión y entrega única de temporal desde la interfaz. |
| CU-04/CU-07 parcial | Emisión presencial y ambos recorridos de registro/verificación con códigos reales del backend capturados localmente. Activación sin login automático. Ficha histórica mantiene ID/CI, estado inactivo y vínculo único. |
| CU-05 | Cambio ordinario persiste el hash y conserva la sesión actual; temporal dirige al formulario restringido sin menú y su finalización exige nuevo login. |
| CU-06 | Solicitud genérica, enlace real capturado, token retirado de URL y restablecimiento confirmado sin login automático. |

Se observaron respuestas reales 200/201/202/204/401/403/409/419/422/429; 419 se provocó omitiendo CSRF. Parte de los rechazos se comprobó mediante fetch desde el navegador, por lo que no se atribuye a esta ejecución toda la cobertura visual de errores de las pruebas del cliente. Un 429 durante el recorrido administrativo acelerado se mostró correctamente; el login final se completó tras la espera, sin desactivar límites. Sin excepciones JavaScript ni uso de Web Storage en el recorrido principal. La bitácora no contenía la contraseña inicial ni los códigos/tokens capturados comprobados.

**Verificación adicional:** `php artisan test --compact --no-ansi tests/Feature/UsuariosAutenticacionIntegracionTest.php` → **3 pruebas, 190 aserciones correctas**. Complementa el navegador con renovación/revocación de varias sesiones e integridad de vínculos; correo simulado. Las 213 pruebas frontend y build correctos de la revisión previa conservan su evidencia, sin presentarse como repetidos en esta ejecución documental.

**Limpieza/evidencia:** servidores temporales detenidos, esquema exclusivo y archivos de secretos retirados. Resúmenes sin secretos: `aut21-evidence.json`, `aut21-extra-evidence.json`, `aut21-routes-evidence.json` y `aut21-persistence-evidence.json` en la carpeta temporal local de OpenCode; no constituyen un nuevo runner versionado. No fue necesario corregir código productivo.

**Estado:** AUT-21 local aprobada; CU-01–CU-06 integrados localmente y emisión parcial de CU-07 verificada. Cierre externo pendiente de aceptación de Brevo y llegada efectiva a buzón; HTTPS/configuración de producción requieren su propia comprobación. CRUD restante de CU-07 conserva su alcance posterior. Contexto §17.34 y análisis incremental sincronizados; diagrama global aplazado por decisión expresa, sin cambios de esquema/DER.
