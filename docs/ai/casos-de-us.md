# Casos de uso — Clínica Vida Salud

> **Estado vigente CU-24–CU-27, cierre frontend Fase 6 (2026-10-30):** seis fases frontend cerradas localmente con integración Chrome/React/Axios/Laravel/Sanctum/PostgreSQL real.23 controles funcionales/90 layouts/36 axe correctos; global frontend nueva3356/115 archivos y build correctos. Solicitud→carga PDF/PNG/JPEG→publicación directa o devolución/sustitución/revisión nueva, reasociación entre fichas/responsables/recarga de origen, tres cortes tras commit recuperados por GET sin replay y cuatro descargas completas idénticas en disco. Sesión/CSRF/revocación/roles/privacidad/versiones/autoría real comprobados, entorno propio retirado/inventario SQL conservado. Sustituye pendientes de implementación/integración de laboratorio en notas y matriz históricas inferiores, sin cambiar flujos/RF/RN/permisos ni declarar producción/base habitual-remota/CU-28/reportes. Evidencia y límites en contexto §17.182/frontend README/análisis laboratorio-frontend-fase6.

> **Administrador, Fase 4 frontend (2026-10-25):** historial por ficha compartido verificado, detalle administrativo con motivo/CI/teléfono y bitácora con origen seguro de reservas. Listados mínimos y ámbitos de Médico/Paciente conservados; sin nuevos endpoints o escrituras. 316 dirigidas/11 archivos, suite global 1601/68 y build correctos con sesión/API simuladas. Integración real y revisión visual nuevas quedan para Fase 5; contexto §17.126.

> **Administrador, Fase 3 frontend (2026-10-24):** programación compartida ahora requiere selección de Paciente para Administración/Recepción, misma búsqueda/propuesta/confirmación/resultado incierto; reserva propia permanece exclusiva del Paciente. 255 pruebas citas/5 archivos y suite global 1549/68 correctas, build correcto. Sesión/API simuladas, integración real nueva pendiente Fase 5. Resuelve el pendiente funcional señalado por Fases 1/2; detalle/auditoría aún Fase 4. Contexto §17.125.

> **Administrador, Fase 2 frontend (2026-10-24):** CU-07 revisado con los mismos listados/formularios/estado y emisión de códigos para Administración/Recepción. Baterías parametrizadas, 275 dirigidas/9 archivos y suite global 1438/68 correctas, build correcto; API/sesión simuladas, no nueva integración real. Emisión independiente añade enlace a búsqueda de ficha, no nuevos endpoints/roles ni credenciales de Pacientes. CU-04/CU-08 conservan exclusividad. Contexto §17.124; Fases 3–5 pendientes.

> **Administrador, Fase 1 frontend (2026-10-24):** guards de diez rutas existentes/Inicio/sidebar permiten accesos administrativos de pacientes/códigos, programación e historial; sin páginas/endpoints duplicados. 1352 pruebas/68 archivos y build correctos; nuevas pruebas de rutas con páginas de negocio simuladas, no flujos completos. CU-04/CU-08 permanecen personales y Ciclo 2 solo pendiente sin enlaces. Fases 2–4 revisarán pacientes, selección administrativa de reserva y campos del detalle; no considerar utilizable programación del Administrador hasta Fase 3. Contexto §17.123. No nueva integración React/Laravel ni backend modificado.

> **Cierre posterior Fase 5 con PostgreSQL (2026-10-24):** autorización exclusiva de entorno aislado, suite completa **2535 pruebas/25681 aserciones correcta** (15 min 10,998 s), incluidas integración/sesiones database/rollback/exclusiones y concurrencia multiproceso. Pendientes SQL históricos resueltos para esta ampliación backend; correcciones solo en contraseñas ficticias de dos tests, sin cambios de negocio. Contexto §17.122. No implementa Ciclo 2 ni adapta React; producción intacta.

> **Fase 5 administrativa (2026-10-24):** regresión sin SQL completada, cierre integral pendiente. Matriz de 58 operaciones por rol/70 API y rechazos con AutenticacionService real/repositorios simulados; 725 pruebas/3525 aserciones correctas sumando fases previas, infraestructura/correo simulados y política de claves sin SQL. Gates positivos no ejecutan cada CU. Flujos positivos heredados y garantías PostgreSQL pendientes por migraciones prohibidas; 2535 casos descubiertos no equivalen a suite ejecutada. React no iniciado. Contexto §17.121.

> **Avance administrativo Fase 4 (2026-10-24):** historial CU-21 por ficha y detalle administrativo ampliados en backend; CU-20 revisado sin reimplementar agenda. Administrador comparte motivo/CI/teléfono del detalle con Recepción, nunca listados ni contenido clínico; Médico/Paciente conservan ámbitos propios. 44 pruebas nuevas + 84 previas: 128/935 correctas con persistencia/sesión simuladas. 89 regresiones heredadas descubiertas, no ejecutadas por migraciones prohibidas. PostgreSQL real/React/Fase 5 pendientes. Contexto §17.120 sustituye exclusiones administrativas históricas del detalle.

> **Ruta canónica:** `docs/ai/casos-de-us.md`.  
> **Base vigente:** `Clinica_Vida_Salud_Captura_Actualizada(3).pdf` (104 páginas), especialmente capítulo 4, apartados 4.1–4.3.  
> **Referencia superior:** examen oficial del Segundo Parcial de Ingeniería de Requisitos, `RF01`–`RF13` y `RN01`–`RN14`.  
> **Estado documental:** los 30 casos están **especificados** en el PDF; su implementación y pruebas deben verificarse en el repositorio.  
> **Revisión documental:** 18 de octubre de 2026.

> **Actualización de permisos 2026-10-24 — Fase 1:** decisión definitiva del responsable: Administrador autorizado en 28/30 CU, excepto CU-04 y CU-08. Catálogo y §3.1 son la matriz vigente; sustituyen las exclusiones administrativas de la versión fuente sin afirmar cotejo de un nuevo Word/PDF. Las fichas conservan sus recorridos de origen: en los CU ampliados, sus apartados Actores/Iniciador y menciones exclusivas de Recepción/Médico se interpretan con la participación adicional del Administrador según §3.1, **sin ampliar los permisos de los otros roles ni atribuir al Médico acciones administrativas**. No acredita implementación de nuevos permisos.

> **Avance posterior — Fase 2:** backend CU-07 ampliado en rutas y Services para Administrador/Recepcionista, sin nuevos endpoints. 44 pruebas/222 aserciones correctas con sesión/persistencia simuladas; 42 casos de regresión heredados para Administración descubiertos pero no ejecutados por invocar migraciones. Persistencia/rollback/concurrencia reales pendientes; React sin adaptar. CU-04/CU-08 continúan excluidos. Contexto §17.118. Fases 3–5 no iniciadas.

> **Avance posterior — Fase 3:** backend CU-13/CU-14 ampliado para Administrador por flujo administrativo existente; selección de Paciente en Requests/Services y tokens administrativos ligados al ejecutor. 40 casos nuevos y 44 de Fase 2: 84 pruebas/763 aserciones correctas sin DB real. 173 regresiones heredadas preparadas, no ejecutadas por migraciones; concurrencia/persistencia reales pendientes. Metadata de bitácora distingue ADMINISTRACION sin cambiar PROPIA/RECEPCION. Contexto §17.119; React y Fase 4 todavía pendientes.

## 1. Objetivo y forma de uso

Fuente operativa de consulta para implementar los **30 casos de uso** con la numeración y reglas de la documentación académica. Contiene sus fichas funcionales, **no representa código implementado**, esquemas físicos definitivos ni contratos HTTP aprobados. Antes de trabajar un CU, consultar además `contexto-maestro.md`, `requerimientos.md`, `reglas-negocio.md`, el DER, el examen oficial y los AGENTS correspondientes. No convertir automáticamente cada paso en un endpoint ni inventar permisos por inferencia.

**Precedencia:** el examen es obligatorio; las decisiones aprobadas más recientes desarrollan lo no especificado; este archivo reproduce las fichas de la versión fuente. En caso de diferencia, contrastar la fuente y pedir definición en lugar de alterar silenciosamente requisitos. Los plazos, códigos, Brevo, módulos clínicos y bitácora son decisiones del proyecto cuando el examen no los exige expresamente.

**Precisiones aprobadas incorporadas:** CU-03 / PN02 incluye la administración exclusiva del estado de acceso de cuentas de Pacientes previamente verificadas y la excepción controlada del primer Administrador activo; CU-04 / PN03 exige CI para registro/vinculación web, mientras CU-07 / PN04 admite fichas administrativas sin CI y su incorporación posterior sobre la misma ficha. CU-23 / PN18 incluye el mantenimiento médico de antecedentes durante una atención autorizada. Se conservan identificadores y ciclos. El título actualizado de CU-03, «Administrar cuentas de usuario y su acceso», se sincroniza por instrucción expresa del responsable del proyecto; el Word/PDF original no está disponible para cotejo directo. No se presume que estas precisiones ya figuren en las páginas del PDF citado ni que estén implementadas.

**Navegación:** cada caso tiene ancla de encabezado `CU-XX`; los números no se reinician por módulo. El **ciclo** es el registrado en 4.2/4.3; no equivale al estado de implementación.

## 2. Actores y límites comunes

| Actor | Responsabilidad y prohibiciones principales |
| --- | --- |
| Administrador | Todas las operaciones de CU-01–CU-03, CU-05–CU-07 y CU-09–CU-30. CU-07 incluye emisión administrativa de códigos; no realiza el proceso personal CU-04 ni edita el perfil por CU-08. CU-03 conserva límites de credenciales, rol y vínculo de cuentas de Pacientes. Conserva identidad real del ejecutor separada de Paciente/Médico responsable, estados, versiones y controles de integridad. |
| Recepcionista | Fichas de pacientes, verificación presencial, códigos de activación, programación/reprogramación/cancelación, llegadas e inasistencias. No administra credenciales de pacientes ni modifica contenidos clínicos. |
| Médico | Agenda e información clínica autorizadas, consultas médicas propias y mantenimiento de antecedentes durante una atención real y autorizada, solicitudes y revisión/publicación de laboratorio y Atendida solo de citas propias. |
| Paciente | Registro/activación de su cuenta, perfil permitido, reserva únicamente para sí, historial, información clínica autorizada, resultados publicados y recordatorios internos. No edita antecedentes, reprograma ni cancela citas. |

En los flujos protegidos, el backend valida **sesión, rol, estado de cuenta y relación con el recurso**; React no sustituye la autorización. Brevo es servicio externo transaccional, no rol nuevo. Los procesos automáticos (notificaciones/auditoría) no crean un quinto actor.

## 3. Catálogo, correspondencia y priorización

| CU | Nombre | Actores | Ciclo | Riesgo | Ficha PDF |
| --- | --- | --- | :---: | --- | --- |
| [CU-01](#cu-01) | Iniciar sesión | Administrador, Recepcionista, Médico, Paciente | 1 | Alto | p. 70 |
| [CU-02](#cu-02) | Cerrar sesión | Administrador, Recepcionista, Médico, Paciente | 1 | Medio | p. 71 |
| [CU-03](#cu-03) | Administrar cuentas de usuario y su acceso | Administrador | 1 | Alto | p. 72 |
| [CU-04](#cu-04) | Registrar cuenta del Paciente y vincular su registro | Paciente | 1 | Alto | p. 73 |
| [CU-05](#cu-05) | Cambiar contraseña propia | Administrador, Recepcionista, Médico y Paciente | 1 | Alto | p. 75 |
| [CU-06](#cu-06) | Recuperar contraseña mediante correo electrónico | Administrador, Recepcionista, Médico y Paciente | 1 | Alto | p. 76 |
| [CU-07](#cu-07) | Gestionar el registro administrativo de pacientes | Administrador, Recepcionista | 1 | Alto | p. 77 |
| [CU-08](#cu-08) | Actualizar el perfil personal | Paciente | 1 | Alto | p. 78 |
| [CU-09](#cu-09) | Gestionar especialidades médicas | Administrador | 1 | Medio | p. 79 |
| [CU-10](#cu-10) | Gestionar médicos y sus especialidades | Administrador | 1 | Alto | p. 80 |
| [CU-11](#cu-11) | Gestionar consultorios y su asignación fija | Administrador | 1 | Alto | p. 81 |
| [CU-12](#cu-12) | Configurar horarios y duración de citas | Administrador | 1 | Alto | p. 82 |
| [CU-13](#cu-13) | Buscar médicos y consultar disponibilidad | Administrador, Recepcionista, Paciente | 1 | Medio | p. 83 |
| [CU-14](#cu-14) | Programar o reservar una cita presencial | Administrador, Recepcionista, Paciente | 1 | Alto | p. 84 |
| [CU-15](#cu-15) | Reprogramar una cita | Administrador, Recepcionista | 2 | Alto | p. 85 |
| [CU-16](#cu-16) | Cancelar una cita | Administrador, Recepcionista | 2 | Alto | p. 86 |
| [CU-17](#cu-17) | Confirmar la llegada del Paciente | Administrador, Recepcionista | 2 | Medio | p. 87 |
| [CU-18](#cu-18) | Registrar la inasistencia del Paciente | Administrador, Recepcionista | 2 | Alto | p. 88 |
| [CU-19](#cu-19) | Marcar una cita como Atendida | Administrador, Médico | 2 | Alto | p. 89 |
| [CU-20](#cu-20) | Consultar la agenda médica | Administrador, Recepcionista, Médico | 1 | Medio | p. 90 |
| [CU-21](#cu-21) | Consultar el historial y seguimiento de citas | Administrador, Recepcionista, Paciente | 1 | Medio | p. 91 |
| [CU-22](#cu-22) | Consultar la historia clínica autorizada | Administrador, Médico, Paciente | 2 | Alto | p. 92 |
| [CU-23](#cu-23) | Registrar o actualizar una consulta médica | Administrador, Médico | 2 | Alto | p. 93 |
| [CU-24](#cu-24) | Solicitar estudios de laboratorio | Administrador, Médico | 2 | Medio | p. 94 |
| [CU-25](#cu-25) | Cargar o corregir documentos de laboratorio | Administrador | 2 | Alto | p. 95 |
| [CU-26](#cu-26) | Revisar y autorizar la publicación de resultados | Administrador, Médico | 2 | Alto | p. 96 |
| [CU-27](#cu-27) | Consultar solicitudes y descargar resultados autorizados | Administrador, Médico, Paciente | 2 | Alto | p. 97 |
| [CU-28](#cu-28) | Consultar recordatorios de próximas citas y recibir Web Push opcional | Administrador, Paciente | 2 | Medio | p. 98 + ampliación aprobada 2026-10-30 |
| [CU-29](#cu-29) | Generar reportes administrativos | Administrador | 2 | Medio | p. 99 |
| [CU-30](#cu-30) | Consultar bitácora del sistema | Administrador | 1 | Medio | p. 100 |

**Nota sobre el ciclo:** el examen y la priorización indican entregas por fases; el catálogo identifica funciones, no el orden exacto de programación. Todos los casos aquí son especificaciones, no tareas terminadas.

### 3.1. Matriz administrativa vigente y estado real — 2026-10-24

**Decisión expresa:** 28/30 CU autorizados; CU-04 y CU-08 excluidos. Priorización vigente de esta entrega: **Ciclo 1 = CU-01–CU-14, CU-20, CU-21 y CU-30 (17)**, con 15 autorizados para Administración; **Ciclo 2 = CU-15–CU-19 y CU-22–CU-29 (13)**. CU-30 se incluye en Ciclo 1 por la priorización actual y su implementación existente; su ficha/PDF conservan el antecedente de ciclo 2, no se modifica el académico. El estado siguiente procede de inspección de rutas/servicios/React, no de una nueva ejecución de pruebas funcionales.

| CU | Autorización Administrador | Estado vigente / evidencia pendiente |
| --- | --- | --- |
| CU-01 | Sí, sesión propia | Existente; regresión completa PostgreSQL Fase 5 correcta |
| CU-02 | Sí, sesión propia | Existente; regresión completa PostgreSQL Fase 5 correcta |
| CU-03 | Sí, administración de cuentas según sus límites | Existente; sin habilitar creación/vinculación o credenciales de Pacientes |
| CU-04 | **No** | Flujo personal público del Paciente; probar que Administración no actúe por terceros ni omita comprobaciones |
| CU-05 | Sí, contraseña propia | Existente; no permite cambiar contraseñas ajenas |
| CU-06 | Sí, recuperación propia | Existente; no permite recuperar cuentas de terceros como operación administrativa |
| CU-07 | Sí, ficha y código administrativo | Backend ampliado/verificado; frontend cinco fases completadas localmente, integración real de alta/CI/edición/estado/código y revisión visual correctas (§17.134) |
| CU-08 | **No** | Perfil propio exclusivo del Paciente; conservar rechazo a Administración |
| CU-09 | Sí | Existente; regresión completa PostgreSQL Fase 5 correcta |
| CU-10 | Sí | Existente; regresión completa PostgreSQL Fase 5 correcta |
| CU-11 | Sí | Existente; regresión completa PostgreSQL Fase 5 correcta |
| CU-12 | Sí | Existente; regresión completa PostgreSQL Fase 5 correcta |
| CU-13 | Sí, contexto administrativo | Backend verificado; frontend selección administrativa/búsqueda/turnos compartidos integrados con Laravel/PostgreSQL reales en Fase 5 local (§17.134) |
| CU-14 | Sí, para paciente seleccionado | Backend verificado; frontend revisión/confirmación/resultado incierto administrativo integrado, incluida respuesta cortada tras 201 sin duplicación, Fase 5 local (§17.134) |
| CU-15 | Sí | Backend y frontend cerrados localmente (§17.140/§17.146). Integración real, revisión visual y global final 2015/78 correctas. Producción pendiente |
| CU-16 | Sí | Backend y frontend cerrados localmente (§17.140/§17.146). Cancelación/recuperación/historial reales y global final 2015/78 correctos. Producción pendiente |
| CU-17 | Sí | Backend cerrado localmente (§17.152); seis fases frontend cerradas localmente con integración real/visual y suite global correctas (§17.158); producción pendiente |
| CU-18 | Sí | Backend cerrado localmente (§17.152); seis fases frontend cerradas localmente con integración real/visual y suite global correctas (§17.158); producción pendiente |
| CU-19 | Sí, conservando Médico responsable | Backend cerrado localmente (§17.152); seis fases frontend cerradas localmente con integración real/visual y suite global correctas (§17.158); producción pendiente |
| CU-20 | Sí, selección de Médico | Agenda/backend verificados; frontend agenda seleccionada/detalle administrativo integrados en Fase 5 local, límites de Médico propio conservados (§17.134) |
| CU-21 | Sí, historial administrativo por paciente | Backend verificado; frontend historial por ficha → detalle → retorno integrado en Fase 5 local; paginación/variantes adicionales con cobertura simulada previa (§17.134) |
| CU-22 | Sí, consulta clínica | Backend cerrado localmente, seis fases verificadas con suite global 3828/55111 (§17.164); frontend/producción pendientes |
| CU-23 | Sí, consulta/antecedentes con versiones | Backend cerrado localmente, seis fases verificadas con suite global 3828/55111 (§17.164), ejecutor/Médico separados; frontend/producción pendientes |
| CU-24 | Sí, solicitud ligada a consulta | Models/persistencia existentes; sin flujo funcional; conservar solicitante/responsable |
| CU-25 | Sí, carga/corrección documental | Models/persistencia existentes; sin flujo funcional de archivos protegidos |
| CU-26 | Sí, revisión/devolución/publicación | Models/persistencia existentes; falta resolver usuario revisor separado de Médico antes de implementar |
| CU-27 | Sí, consulta/descarga autorizada | Models/persistencia existentes; sin flujo funcional; autorizar también entrega de bytes |
| CU-28 | Sí, consulta administrativa por paciente | Model/persistencia y preferencias existentes; generador/consulta/Web Push pendientes. Dispositivos push solo por consentimiento del titular; no generación manual ni edición administrativa de preferencias |
| CU-29 | Sí | Sin módulo funcional de reportes; agenda y bitácora no lo sustituyen |
| CU-30 | Sí, solo lectura | Backend verificado; frontend origen administrativo/Recepción/propio y referencias seguras integrados en Fase 5 local, exclusivo de Administrador (§17.134) |

**Evidencia Fase 5 aplicable a las filas existentes:** suite completa 2535/25681 correcta en PostgreSQL aislado, incluidas regresiones positivas/integradas/concurrentes heredadas y administrativas nuevas. Los 15 CU administrativos del Ciclo 1 mantienen implementación con regresión backend local verificada, no aceptación de producción. No habilita los 13 CU pendientes del Ciclo 2 ni acredita UI React ampliada. CU-04/CU-08 siguen personales, sin nuevos permisos para otros roles; contexto §17.122.

**Cierre posterior Fase 5 frontend (2026-10-26):** ampliación administrativa React completada localmente tras reanudación: suite nueva 1601 pruebas/68 archivos y build correctos; recorrido Chrome/React/Laravel/PostgreSQL real de 19 comprobaciones, 56 disposiciones y 23 axe sin infracciones automáticas. Cuatro citas/cuatro eventos con autores/orígenes correctos y sin duplicar la reserva de respuesta incierta. Sesión/CSRF/revocación y límites de otros roles conservados; esquema/credenciales/servidores temporales propios retirados, public/esquemas ajenos intactos. No código productivo nuevo ni reglas cambiadas, no nueva suite backend completa ni certificación de producción/correo real; incidencias y límites en contexto §17.134. Sustituye los pendientes de integración de esta ampliación, no implementa los trece CU restantes ni amplía permisos por navegar.

**Aplicación común a las fichas:** Administrador reutiliza las operaciones administrativas de Recepción en CU-07/CU-13/CU-14/CU-21, y las operaciones autorizadas de asistencia/clínica/laboratorio cuando se implementen. No se cambia su rol ni se exige que sea el Médico para ejecutar la operación administrativa; sí se valida que la cita/consulta/solicitud tenga el Paciente y Médico responsable correctos. Conservar estados, atención real, ausencia comprobada, horarios, disponibilidad, idempotencia, versiones y auditoría. Ningún permiso nuevo para Recepción, Médico o Paciente.

**Límite CU-07/CU-04:** Administrador puede verificar identidad y emitir/reemitir el código administrativo con las mismas restricciones (CI, 24 h, cinco intentos, uso único). El Paciente sigue introduciendo CI/código/correo/contraseña y verificando correo por CU-04; emitir el código no autoriza a Administración a realizar esos pasos ni a crear la cuenta. La etiqueta histórica «código de Recepción» no cambia formato ni contrato por esta ampliación.

**Trazabilidad y dependencias futuras:** Cita.creada_por y Bitacora.id_usuario_responsable identifican al ejecutor; Cita.id_medico conserva al responsable. CodigoActivacionPaciente.id_recepcionista es FK a Usuario, no a un registro profesional ni a un rol: reutilizable con el ID real del emisor sin migración en esta fase. VersionConsulta/VersionAntecedente/VersionResultado tienen id_autor a Usuario; RevisionResultado tiene id_medico a Médico y no un campo separado de usuario revisor: documentar diseño y justificar cualquier ampliación antes de desarrollar CU-26, sin atribución falsa ni migraciones ahora. Models/fixtures no acreditan CU funcionales.

**Alcance de Fase 1:** solo política/documentación y diagnóstico PostgreSQL de solo lectura. No cambian rutas, permisos ejecutables, contratos efectivos, PHP/React, esquemas ni datos. Fases 2–5 requieren autorización individual; Word/PDF/draw.io intactos.

## 4. Fichas de los casos de uso

### 4.1. Usuarios y autenticación (`CU-01` a `CU-06`)

<a id="cu-01"></a>
#### CU-01 — Iniciar sesión

**Trazabilidad de la ficha:** Alcance: 1.4.1. Proceso: PN01. Correspondencia: RF01. RN12. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 70.

**Propósito**

Acceder a las funciones autorizadas mediante correo electrónico y contraseña.

**Actores**

Administrador, Recepcionista, Médico, Paciente

**Iniciador**

Administrador, Recepcionista, Médico, Paciente

**Precondición**

La cuenta está activa y tiene correo verificado; no está bloqueada temporalmente. No se necesita sesión previa.

**Flujo principal**

1. El usuario introduce correo electrónico y contraseña.
2. El servidor aplica límites de solicitudes por origen y comprueba el estado de la cuenta y su bloqueo.
3. Verifica el hash mediante Laravel. Tras tres contraseñas incorrectas consecutivas, bloquea el acceso durante diez minutos.
4. Si el acceso es válido, reinicia el contador de errores e identifica uno de los cuatro roles fijos. Con credencial temporal y cambio obligatorio del personal, genera únicamente el contexto restringido de CU-03; en el acceso ordinario genera la sesión normal segura.
5. Solo la sesión normal permite mostrar el panel del rol. Comprueba sesión, estado, permiso y relación con el recurso en cada petición; la restringida solo permite formulario/envío de contraseña definitiva y cierre de sesión.
6. Registra el resultado de autenticación en la bitácora, sin guardar la contraseña.

**Postcondición**

Existe una sesión válida únicamente para una cuenta habilitada; los datos de acceso se conservan solo en la cuenta.

**Excepciones**

Credenciales incorrectas, cuenta inexistente, pendiente, desactivada o bloqueada: denegar con un mensaje general sin revelar si existe el correo. Durante el bloqueo no se autentica ni se amplían sus diez minutos. Al terminar, se reinicia el contador y se permiten nuevos intentos si la cuenta sigue activa. Una cuenta desactivada no recupera acceso por vencer el bloqueo. La credencial temporal solo habilita el cambio obligatorio restringido de CU-03, no el cambio ordinario de CU-05; al completarlo se cierra esa sesión y se exige login normal. No omite activación/verificación pendientes.

<a id="cu-02"></a>
#### CU-02 — Cerrar sesión

**Trazabilidad de la ficha:** Alcance: 1.4.1. Proceso: PN01. Correspondencia: RF01. RN12. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 71.

**Propósito**

Finalizar de forma segura la sesión autenticada.

**Actores**

Administrador, Recepcionista, Médico, Paciente

**Iniciador**

Administrador, Recepcionista, Médico, Paciente

**Precondición**

El usuario mantiene una sesión válida, incluida una sesión restringida de cambio obligatorio de contraseña.

**Flujo principal**

1. El usuario selecciona Cerrar sesión.
2. El servidor invalida la sesión y sus identificadores o tokens asociados mediante Laravel.
3. Registra el cierre en la bitácora.
4. Presenta la pantalla de acceso.
5. Las solicitudes posteriores a páginas, operaciones o archivos protegidos exigen autenticación nuevamente.

**Postcondición**

La sesión anterior deja de autorizar solicitudes; se conservan la cuenta, la contraseña almacenada mediante hash y los registros asociados.

**Excepciones**

Sesión ya finalizada: mostrar el acceso sin restablecerla. Fallo de comunicación: no mostrar un cierre confirmado sin su ejecución en el servidor. Volver atrás no autoriza nuevas solicitudes; la presentación de datos sensibles aplicará controles de caché apropiados.

**Notas y verificaciones de la ficha**

Comprobar que volver atrás o solicitar directamente un archivo no restablezca el acceso autenticado.

<a id="cu-03"></a>
#### CU-03 — Administrar cuentas de usuario y su acceso

**Trazabilidad de la ficha:** Alcance: 1.4.1. Proceso: PN02. Correspondencia: RF01. RN12, RN14. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 72.

**Propósito**

Crear y mantener cuentas del personal con roles fijos y credenciales establecidas de forma segura; consultar datos administrativos mínimos y desactivar/reactivar únicamente el acceso de cuentas web de Pacientes existentes y previamente verificadas, sin modificar credenciales, rol o vinculación.

**Actores**

Administrador

**Iniciador**

Administrador

**Precondición**

El Administrador tiene sesión válida y el servidor comprueba su permiso. Existen los roles predefinidos; el registro profesional existe cuando se crea la cuenta de un Médico. Para administrar acceso de un Paciente, la cuenta objetivo ya existe, fue verificada legítimamente y conserva su vínculo original; reactivarla requiere que esté desactivada administrativamente.

**Flujo principal**

1. El Administrador lista cuentas o abre su detalle por identificador; para Pacientes solo obtiene los datos administrativos mínimos autorizados.
2. Sobre cuentas del personal selecciona crear, modificar, asignar un rol existente, activar, desactivar o restablecer credenciales. Si administra el acceso de una cuenta de Paciente, aplica exclusivamente el flujo alternativo.
3. Para una nueva cuenta registra los datos y un correo único, asigna Administrador, Recepcionista o Médico y vincula al Médico con su registro profesional.
4. El servidor valida autorización, unicidad y relación profesional; crea una sola cuenta pendiente.
5. Envía mediante Brevo una invitación de 24 horas y uso único. El trabajador confirma el control del correo y establece su contraseña; el servidor activa la cuenta.
6. Para cambios administrativos conserva identificador, identidad, vínculos profesionales e historia. Aplica VS-AUT-25/26 para cambios de rol: revoca sesiones y exige nuevo login. Desactivar impide también el uso de sesiones anteriores. Desactivaciones, cambios de rol y restablecimientos respetan VS-AUT-27 bajo coordinación transaccional, incluyendo operaciones simultáneas y sobre la propia cuenta.
7. Para el restablecimiento administrativo excepcional, el Administrador comprueba identidad del trabajador sin conocer su contraseña anterior; el sistema genera una credencial temporal segura, con vencimiento y uso controlado, para el flujo restringido descrito abajo. No aplica a Pacientes y no sustituye la invitación de primera contraseña ni CU-06.
8. Registra los cambios y restablecimientos en la bitácora sin guardar secretos.

**Flujo alternativo**

ADMINISTRAR EL ACCESO DE UNA CUENTA DE PACIENTE PREVIAMENTE VERIFICADA

1. El Administrador consulta los datos mínimos de la cuenta y solicita desactivar o reactivar su acceso.
2. El servidor revalida sesión, permiso, existencia, rol Paciente, verificación legítima previa y vínculo original con la ficha.
3. Para desactivar, cambia el estado administrativo a Desactivada e invalida sus sesiones anteriores; conserva la cuenta y sus relaciones.
4. Para reactivar, comprueba que estaba desactivada administrativamente y conserva el correo previamente verificado. Cambia únicamente su habilitación a Activa, sin iniciar sesión ni restaurar sesiones anteriores; el titular debe realizar un nuevo login y cumplir las restricciones de seguridad, incluido cualquier bloqueo temporal vigente.
5. En ambas operaciones conserva identificador de Usuario, rol Paciente, contraseña, correo de acceso, vínculo con el mismo Paciente, antecedentes, citas, historial y resultados. No crea otra cuenta ni cambia el estado de la ficha: si sigue inactiva, continúa impidiendo nuevas reservas.
6. Registra responsable, cuenta objetivo, operación y resultado en Bitácora, únicamente mediante referencias y metadatos seguros, sin contraseñas, hashes, tokens ni contenido clínico.
7. Informa el estado de acceso actualizado. Suspender y rehabilitar significan desactivar y reactivar con los estados existentes; no se crea Suspendida ni se habilita un procedimiento de recuperación de identidad o credenciales.

RESTABLECIMIENTO ADMINISTRATIVO DEL PERSONAL Y CAMBIO OBLIGATORIO

1. El Administrador con sesión normal válida selecciona una cuenta del personal y comprueba su identidad. El servidor valida autorización, cuenta y restricciones, incluida la continuidad del último Administrador; no conoce ni exige contraseña anterior.
2. Solo para personal Activo y previamente verificado, el sistema genera una credencial criptográfica aleatoria de **20 caracteres** y la muestra **una sola vez al Administrador para entrega presencial**. Guarda únicamente HMAC con propósito `CredencialTemporal` de TokenAcceso y **30 minutos** de vigencia desde emisión, sin registrar el secreto. Activa cambio obligatorio, conserva pero impide usar el hash anterior, invalida temporales/recuperaciones anteriores y revoca todas las sesiones. No reinicia bloqueos ni contadores y protege también el último Administrador; una emisión sobre sí mismo cierra su sesión normal.
3. Tras validar la credencial de la cuenta correcta y sus restricciones, establece solo una sesión restringida. No habilita cuentas desactivadas ni omite verificaciones pendientes o bloqueos; no concede funciones ordinarias. Conserva tres errores/diez minutos y admite reingreso antes del cambio solo dentro de los 30 minutos, sin ampliar vigencia y con una sola sesión restringida vigente; un nuevo ingreso revoca la anterior.
4. El trabajador accede únicamente al formulario de contraseña definitiva, envía/ confirma la nueva contraseña de mínimo 12 caracteres o cierra sesión por CU-02. La credencial temporal válida acredita este procedimiento, sin exigir una contraseña anterior desconocida. El backend deniega cualquier otra operación, incluidas agenda, administración, historia clínica, laboratorio, archivos y reportes.
5. Al completar válidamente, el servidor guarda el nuevo hash, invalida/consume definitivamente la credencial, elimina cambio obligatorio y revoca sesiones incompatibles, coordinando los cambios para evitar estados parciales o consumos concurrentes.
6. Registra responsable y resultado mediante metadatos seguros, sin secretos, y finaliza la sesión restringida. Exige un nuevo login por CU-01 con la contraseña definitiva; no convierte la restringida en normal.
7. Vencimiento, revocación, intento inválido o fallo: no concede acceso normal ni confirma un cambio inexistente. Al vencer o cerrar sesión expresamente sin completar, mantiene cambio obligatorio y requiere nueva emisión administrativa; el cierre invalida la credencial y nunca restaura automáticamente la contraseña anterior. Perder una pestaña sin logout no equivale a cerrar sesión en servidor. Reemisión: espera **60 segundos**, máximo **cinco emisiones/hora/cuenta**, invalidando la anterior sin prolongar ventanas por rechazo. CU-06 no emite ni consume recuperaciones durante el cambio obligatorio; cambios de estado/rol invalidan temporales. La política completa y límites técnicos por acción se recogen en reglas §4.4, aprobados expresamente para fase 6; no se heredan de invitación o recuperación.

**Postcondición**

Las cuentas del personal quedan creadas o actualizadas con los permisos del rol existente; no se modifican permisos generales ni individuales. En cuentas de Pacientes solo cambia el estado administrativo de acceso: identidad, credenciales, verificación previa, vínculo y datos históricos permanecen intactos, al igual que el estado de la ficha y los bloqueos temporales vigentes. La desactivación invalida sesiones y la reactivación exige nuevo login.

Un cambio de rol autorizado conserva identidad y vínculos e invalida sesiones, con nuevo login. El restablecimiento temporal completado deja vigente la contraseña definitiva y termina la sesión restringida. Las operaciones administrativas no dejan sin un Administrador habilitado para acceso normal.

**Excepciones**

Correo duplicado, datos inválidos, vínculo profesional incompatible o rol no permitido: rechazar. Invitación vencida, utilizada o envío fallido: permitir reenvío controlado por el Administrador sobre la misma cuenta e invalidar la anterior. La recuperación automática de los cuatro roles se realiza por CU-06; el restablecimiento administrativo es exclusivo del personal y no revela contraseñas anteriores. Las cuentas del Paciente se crean y verifican por CU-04, sin registro público del personal. Cuenta de Paciente inexistente, pendiente de verificación, vínculo incompatible, reactivación sin desactivación previa o petición de cambiar contraseña, correo, rol, permisos o vinculación: rechazar. No omitir bloqueos vigentes ni activar la ficha al reactivar acceso. Fallo o cambio simultáneo: no confirmar cambios parciales ni registrar éxito inexistente. Un Paciente sin acceso a su correo verificado no obtiene recuperación administrativa; la alternativa sigue pendiente de definición. La primera cuenta de Administrador se configura de forma segura durante la instalación.

**Excepción de instalación inicial aprobada:** el primer Administrador se crea Activo mediante procedimiento local controlado por el responsable autorizado, sin sesión administrativa previa, con identificador único de Usuario, rol Administrador y permisos fijos, correo de acceso único y contraseña privada de mínimo 12 caracteres, almacenada como hash seguro. No usar credenciales predeterminadas ni secretos legibles en seeders, archivos versionados, logs o documentación. La marca de correo verificado por autorización de instalación es una excepción administrativa de arranque, no prueba de control del buzón mediante enlace, código o Brevo. No usa invitación ni cambio obligatorio; recomendar un correo legítimo bajo control del responsable para recuperación posterior. Registrar creación como SISTEMA sin secretos. La creación es idempotente: conserva cuentas, estado y credenciales al repetir, sin activar una cuenta previa ni crear otra identidad inicial; no es alta general de Administradores ni aplica a Pacientes. La forma de suministrar credenciales pertenece al diseño técnico; `AdministradorInicialSeeder` y su ejecución existente se documentan en contexto §17.12. Esta excepción sustituye la preparación Pendiente anterior y no modifica la invitación ordinaria del resto del personal ni CU-06. No es ruta pública ni CU adicional y no completa CU-01: el acceso normal depende de implementar autenticación, sesiones y autorización.

**Cambios de rol aprobados — precisiones del proyecto:**

| Transición | Condiciones y efectos |
| --- | --- |
| Administrador ↔ Recepcionista | Autorizar, conservar identidad/identificador e historia; respetar último Administrador, revocar sesiones, exigir login y registrar bitácora. |
| Administrador/Recepcionista → Médico | Registro profesional legítimo de la misma persona, sin vínculo a otra identidad; conservar o establecer el vínculo compatible. No crear disponibilidad, horarios, especialidades, consultorios ni citas automáticamente. |
| Médico → Administrador/Recepcionista | Resolver previamente citas futuras y obligaciones asistenciales incompatibles, incluidas atención/documentación y revisión propia de laboratorio. Si hay obligación o no puede comprobarse su resolución con el modelo actual, rechazar e informar la limitación sin exponer contenido clínico. La inactivación profesional necesaria se realiza expresamente por CU-10; coordinar con nuevas reservas y obligaciones concurrentes. No guardar una transición pendiente. |
| Paciente ↔ personal; cuenta del Médico A → Médico B | No permitido por CU-03. La corrección extraordinaria de identidad no está definida por este flujo. |

Todas conservan registro/vínculo profesional, citas, consultas, autores/versiones y resultados; retirar el rol Médico retira sus permisos clínicos sin borrar el vínculo histórico ni inactivar automáticamente al profesional. No cancelar, trasladar, reprogramar o reasignar citas/resultados automáticamente. `Atendida`, la existencia de una consulta o `iniciada_en = NULL` no prueban cierre documental: no inventar estados o marcas para autorizar el cambio.

**Último Administrador — política aprobada del proyecto, no RN literal:** rechazar desactivación, cambio de rol o restablecimiento administrativo que deje sin al menos un Administrador Activo, con hash, activación/verificación aplicable completada y sin restricción exclusiva de cambio obligatorio. Incluye la propia cuenta; comprobar el estado resultante bajo coordinación transaccional para impedir que operaciones simultáneas retiren los últimos accesos. Un bloqueo pasajero no desactiva ni excluye la cuenta de este cómputo administrativo, pero impide login durante su vigencia. No garantiza disponibilidad permanente ante bloqueos u olvidos. Se aplica una vez habilitada la cuenta inicial de instalación.

<a id="cu-04"></a>
#### CU-04 — Registrar cuenta del Paciente y vincular su registro

**Trazabilidad de la ficha:** Alcance: 1.4.1 y 1.4.2. Proceso: PN03. Correspondencia: RF01, RF02. RN01, RN12, RN14. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 73.

**Propósito**

Crear la cuenta propia del Paciente con CI obligatorio y vincularla con una única ficha, sin exponer información ajena ni sustituir su identificador interno.

**Actores**

Paciente

**Iniciador**

Paciente, antes de autenticarse; Recepción interviene previamente mediante CU-07 cuando existe una ficha sin cuenta.

**Precondición**

No se requiere sesión. El solicitante dispone de CI válido y un correo al que puede acceder. El CI es obligatorio tanto para registro nuevo como para vinculación web por decisión particular de Clínica Vida Salud, no por exigencia literal de RF02 para toda ficha administrativa. Puede existir una ficha previa con ese CI y sin cuenta web; si fue registrada sin documento, Recepción debe incorporarlo primero a la misma ficha por CU-07. No se permite una vinculación web alternativa sin CI.

**Flujo principal**


**ESCENARIO A. PACIENTE NUEVO**

1. El Paciente introduce obligatoriamente CI, nombres, apellidos, fecha de nacimiento, teléfono, correo de acceso y contraseña, además de sexo según el formulario aprobado; la dirección conserva su carácter opcional.
2. El servidor exige CI no vacío, valida formato y unicidad conforme a la normalización vigente y comprueba los demás campos y el correo de acceso único, aunque la interfaz ya los valide. Si el CI corresponde a una ficha previa, no crea otra y orienta genéricamente al procedimiento autorizado de vinculación. Si no existe ficha previa, inicia un registro pendiente de 24 horas.
3. Envía por Brevo un código de correo de seis dígitos, vigente diez minutos.
4. El Paciente introduce el código. El servidor valida vigencia, intentos y uso, revalida unicidades y completa de forma atómica una sola ficha con CI y su cuenta activa de rol Paciente. No permite seleccionar roles del personal ni crea datos clínicos ficticios. No exige código de Recepción.

**ESCENARIO B. FICHA PREEXISTENTE CON CI Y SIN CUENTA**

1. Si el CI ya está registrado, el sistema impide una ficha duplicada y muestra un mensaje general con las opciones de asistencia, acceso, recuperación y Ya tengo un código.
2. Recepción comprueba presencialmente la identidad y la correspondencia del CI con la ficha existente y genera mediante CU-07 el código de activación asociado a esa misma ficha.
3. El Paciente introduce CI, código de Recepción, correo electrónico y contraseña.
4. El servidor valida presencia y formato del CI, correspondencia entre CI, ficha y código, correo único, vigencia, intentos y ausencia de cuenta vinculada; reserva de forma segura la activación pendiente para ese proceso.
5. Envía por Brevo un segundo código, numérico de seis dígitos, que verifica exclusivamente el correo indicado.
6. El Paciente introduce el segundo código. El servidor comprueba ambos controles y su vigencia, revalida que no exista otra cuenta vinculada y completa la cuenta, la vinculación y el consumo de los códigos en una operación atómica, conservando el identificador original, citas, consultas, antecedentes y resultados.
7. En ambos escenarios informa la activación y permite iniciar sesión mediante correo y contraseña; registra el evento en la bitácora.

**Postcondición**

Existe como máximo una cuenta por Paciente, con CI informado y vinculada a su único identificador interno; el CI no es su clave primaria. La ficha previa conserva citas, consultas, antecedentes y resultados autorizados. No se completa registro/vinculación web sin CI ni se habilita acceso protegido durante el registro pendiente.

**Excepciones**

CI previo: no revelar nombre, historia, citas ni existencia o estado de cuenta; no vincular automáticamente. Con cuenta ya vinculada, impedir una segunda y ofrecer opciones generales de acceso o recuperación. Código incorrecto, vencido, usado o con cinco errores: rechazar. Si corresponde al de Recepción, exigir otro emitido tras verificar identidad. Para el de correo, permitir reenvío dentro de los límites y vigencia principal. Interrupción: reanudar el mismo proceso mediante un contexto protegido, sin duplicar fichas ni permitir que otra solicitud reutilice el código reservado. Envío fallido: informar sin confirmar un envío inexistente. Registro principal vencido: invalidar la activación pendiente; en B se necesita otro código de Recepción y en A puede reiniciarse el registro. Cambio simultáneo o fallo al guardar: no dejar vínculos parciales. CI ausente, vacío o inválido: rechazar también en servidor y explicar que es obligatorio para el registro web; orientar a Recepción para asistencia administrativa sin crear CI ficticio, otra ficha o una cuenta ni ofrecer vinculación web sin documento.

**Notas y verificaciones de la ficha**

El código de Recepción acredita la autorización de vinculación tras comprobación presencial; el del correo acredita control del buzón. Ninguno sustituye al otro ni habilita por sí solo acceso clínico. Conocer el CI o verificar el correo no prueba identidad civil. Se mantienen los límites de VS-AUT-15, VS-AUT-17 y VS-AUT-18: código de Recepción de 12 caracteres alfanuméricos, 24 horas, cinco errores y uso único; código de correo de seis dígitos, diez minutos, cinco errores y uso único; reenvío mínimo cada 60 segundos y máximo cinco por hora por proceso/correo, sin ampliar la vigencia principal. La ficha administrativa sin CI pertenece a CU-07 y no autoriza omitir estos controles.

<a id="cu-05"></a>
#### CU-05 — Cambiar contraseña propia

**Trazabilidad de la ficha:** Alcance: 1.4.1. Desarrollo de RF01 y RN12, RN14; política de seguridad del proyecto. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 75.

**Propósito**

Permitir que cualquier usuario cambie su propia contraseña sin modificar su identidad ni sus permisos.

**Actores**

Administrador, Recepcionista, Médico y Paciente

**Iniciador**

Administrador, Recepcionista, Médico y Paciente

**Precondición**

Cuenta activa, sesión **normal** válida y conocimiento de la contraseña actual. Es el cambio ordinario para cualquiera de los cuatro roles. La sesión restringida del restablecimiento administrativo pertenece al flujo separado de CU-03 y no cumple esta precondición; la primera contraseña por invitación también pertenece a CU-03 y no exige una anterior inexistente.

**Flujo principal**

1. El usuario abre la seguridad de su cuenta.
2. Introduce su contraseña actual, la nueva y su confirmación.
3. El servidor comprueba titularidad, contraseña actual y política común de contraseñas.
4. Guarda el nuevo hash mediante Laravel sin duplicarlo en la ficha de Paciente o Médico.
5. Invalida las sesiones anteriores y renueva de forma segura la sesión normal actual. Esta renovación no se aplica a la sesión restringida de CU-03, que se cierra al completar su cambio obligatorio y exige nuevo login.
6. Registra el cambio en la bitácora sin incluir contraseñas ni hashes y confirma la operación.

**Postcondición**

La nueva contraseña queda vigente y la anterior deja de autenticar; no cambian rol, permisos, identificadores ni información relacionada.

**Excepciones**

Contraseña actual incorrecta, nueva contraseña inválida o confirmación diferente: rechazar sin cambios. Sesión inválida, restringida o cuenta ajena: denegar este flujo ordinario. Error al guardar: no confirmar el cambio. El restablecimiento administrativo del personal y su cambio obligatorio pertenecen a CU-03; olvidar la contraseña se resuelve por CU-06 sin exigirla, no omitiendo la comprobación de este caso.

**Notas y verificaciones de la ficha**

Verificar los cuatro roles, contraseña actual incorrecta, renovación de sesión y rechazo de cambios sobre cuentas ajenas

<a id="cu-06"></a>
#### CU-06 — Recuperar contraseña mediante correo electrónico

**Trazabilidad de la ficha:** Alcance: 1.4.1. Desarrollo de RF01 y RN12, RN14; recuperación definida por el proyecto. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 76.

**Propósito**

Restablecer una contraseña olvidada sin revelar la existencia de cuentas.

**Actores**

Administrador, Recepcionista, Médico y Paciente

**Iniciador**

Administrador, Recepcionista, Médico y Paciente

**Precondición**

No se necesita sesión ni conocer la contraseña anterior. Para emitir el enlace debe existir una cuenta activa con correo verificado y cumplirse los límites de solicitudes. Es independiente de invitación, cambio ordinario y restablecimiento administrativo.

**Flujo principal**

1. El usuario selecciona Olvidé mi contraseña e introduce su correo.
2. El servidor valida el formato y aplica límites de frecuencia por correo y origen.
3. Muestra una respuesta general independiente de la existencia de la cuenta.
4. Cuando corresponde, emite por Brevo un enlace temporal con token seguro de 60 minutos, distinto de los códigos de verificación del registro.
5. El usuario abre el enlace e introduce y confirma la nueva contraseña.
6. El servidor comprueba token, cuenta activa, vigencia, uso y política de contraseña.
7. Actualiza el hash, consume el token, invalida los enlaces anteriores y las sesiones existentes y registra el resultado en la bitácora.
8. Confirma la recuperación y solicita iniciar sesión; no autentica automáticamente.

**Postcondición**

La cuenta conserva identidad y permisos; la contraseña nueva sustituye a la anterior sin reactivar cuentas desactivadas.

**Excepciones**

Correo inexistente, no verificado o cuenta desactivada: mantener respuesta general, sin emitir un enlace utilizable. Token inválido, vencido o utilizado, exceso de solicitudes o contraseña no válida: rechazar sin cambios. Una nueva emisión invalida el enlace anterior; los reintentos no extienden su vigencia. Error de envío o persistencia: no confirmar falsamente la entrega o el restablecimiento. El personal sin acceso a su correo solicita el procedimiento administrativo de CU-03; este caso no permite apropiarse de cuentas ajenas.

**Notas y verificaciones de la ficha**

La recuperación no inicia sesión automáticamente ni reactiva cuentas desactivadas. El correo contiene un enlace temporal, no el código de registro.


### 4.2. Pacientes y perfil personal (`CU-07` a `CU-08`)

<a id="cu-07"></a>
#### CU-07 — Gestionar el registro administrativo de pacientes

**Trazabilidad de la ficha:** Alcance: 1.4.2. Proceso: PN04. Correspondencia: RF02. RN01, RN11, RN12, RN14. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 77.

**Propósito**

Mantener las fichas administrativas y emitir códigos para vincular cuentas de pacientes cuya identidad se comprobó presencialmente.

**Actores**

Administrador, Recepcionista

**Iniciador**

Administrador, Recepcionista

**Precondición**

Recepción tiene una sesión válida; el Paciente puede existir sin CI o sin cuenta web. La emisión de código para vinculación web exige comprobar presencialmente su identidad y que la ficha ya tenga CI válido.

**Flujo principal**

1. Recepción lista o busca pacientes por CI, nombre o apellido antes de registrar otro.
2. Selecciona registrar, modificar, desactivar, consultar citas anteriores o generar un código de activación web.
3. Para la ficha conserva identificador interno y código únicos, CI cuando se proporciona, nombres, apellidos, nacimiento, sexo según formulario y teléfono; correo de contacto y dirección son opcionales. Excepcionalmente puede registrar sin CI, con `documento_identidad = NULL`, tras revisar posibles fichas previas. No inventa un documento temporal, ficticio o ajeno ni sustituye la PK por el CI.
4. Para emitir el código de vinculación web localiza la ficha, comprueba presencialmente identidad y CI válido y verifica que no tenga cuenta vinculada. La exigencia de CI en este paso no impide crear una ficha administrativa sin documento.
5. El servidor genera un código aleatorio criptográfico alfanumérico de 12 caracteres, asociado al identificador del Paciente, vigente 24 horas y con un máximo de cinco errores. Lo almacena de forma segura e invalida el anterior y su activación pendiente si se sustituye.
6. Presenta el nuevo código para entregarlo de forma segura al titular y registra emisión o sustitución en la bitácora, sin su valor secreto.
7. Para las demás operaciones valida datos y permisos, guarda la ficha sin eliminar historia y permite consultar citas anteriores por CU-21.

**Flujo alternativo**

INCORPORAR CI A UNA FICHA REGISTRADA SIN DOCUMENTO

1. El Paciente acude a Recepción, que identifica la ficha original y comprueba el CI que desea incorporar.
2. El servidor valida formato y unicidad conforme a la normalización vigente; si pertenece a otra ficha, no guarda ni fusiona registros automáticamente.
3. Recepción incorpora el CI a la misma ficha conservando su identificador interno, citas, consultas, antecedentes, solicitudes, resultados, recordatorios e historial. No modifica contenido clínico ni crea otra ficha o cuenta web.
4. Registra la modificación administrativa con responsable y referencias seguras, sin contenido clínico.
5. Si el Paciente desea cuenta web, posteriormente inicia la vinculación ordinaria de CU-04 con CI, código de Recepción y verificación de correo; incorporar el CI no verifica el correo ni sustituye esos controles.

**Postcondición**

La ficha mantiene su identidad e historia, también sin CI, y permite las citas presenciales autorizadas conforme a sus reglas y estado. Un CI proporcionado es único. El código autoriza iniciar CU-04 solo cuando se cumplen sus condiciones, incluido CI, pero no crea por sí mismo una cuenta ni publica información clínica.

**Precisiones aprobadas de Clínica Vida Salud — CU-07/CU-08 (fase 1 del módulo):** la siguiente distribución es una decisión propia del proyecto; no se presenta como restricción textual adicional de RF02. Las operaciones se autorizan en servidor y conservan siempre ID, código e identidad vinculada.

| Campos u operación | Recepción — CU-07 | Paciente — CU-08 |
| --- | --- | --- |
| CI, nombres, apellidos, nacimiento y sexo | Registrar; incorporar/corregir con comprobación presencial de identidad. Corrección de identidad con motivo. | Consultar los propios; solicitar corrección en Recepción. No editar. |
| Teléfono, dirección y correo de contacto | Registrar/actualizar. | Consultar/actualizar los propios. |
| Contacto de emergencia: nombre, parentesco y teléfono | Registrar/actualizar. | Consultar/actualizar el propio. |
| Recordatorios internos habilitados | Consultar en el detalle si resulta necesario; no modificar la elección del titular. | Consultar/modificar su preferencia. No genera avisos desde CU-08. |
| Estado de ficha | Desactivar/reactivar con confirmación y motivo obligatorio. | Consultar; no modificar. |
| ID, código de Paciente y vínculo con Usuario | Consultar referencias administrativas necesarias; no reasignar ni editar. | No editar; referencia propia únicamente. |
| Correo de acceso | No modificar por CU-07. La emisión de código no crea cuenta. | Gestionar solo por el flujo verificado de CU-08. |
| Contraseña y estado de cuenta | Sin gestión por CU-07. | Contraseña por CU-05/CU-06; no modificar estado de cuenta. |
| Antecedentes, diagnósticos, consultas, resultados y documentos | No consultar ni editar por CU-07. | No editar; consulta autorizada por CU-22/CU-27, sin cargarla automáticamente en Mi perfil. |

**Corrección de CI existente:** además de incorporar un CI antes ausente, Recepción puede corregir uno incorrecto, incluso con cuenta vinculada, previa comprobación presencial y motivo. Nunca se permite retirar un CI ya informado mediante este flujo. Se valida formato/unicidad; si pertenece a otra ficha, se rechaza sin fusionar ni trasladar vínculos. Un cambio efectivo invalida en la misma operación los códigos de Recepción y procesos de vinculación pendientes de la ficha; conserva la cuenta ya vinculada y sus credenciales. La operación concurrente con CU-04 debe preservar una sola identidad y evitar consumos parciales.

**Estado administrativo:** desactivar impide nuevas reservas pero no cancela citas existentes, no cierra la cuenta ni invalida por sí mismo los derechos históricos. Reactivar restablece únicamente la elegibilidad administrativa para nuevas reservas; no reactiva cuentas, elimina bloqueos ni restaura citas. Ambas acciones exigen motivo y trazabilidad sin información clínica.

**Edición concurrente:** una escritura basada en datos administrativos desactualizados se rechaza como conflicto, sin sobrescribir modificaciones de Recepción o del Paciente. Se reconsulta y revisa antes de un nuevo envío. El mecanismo técnico se resuelve primero con el modelo existente; no exige atributos nuevos de versionado. La consulta de citas anteriores depende de CU-21 y no queda implementada por completar solamente las operaciones de ficha.

**Excepciones**

Paciente inexistente, identidad no comprobada, CI ausente para vinculación web, cuenta vinculada o petición sin permiso: no emitir código. CI duplicado/datos inválidos: rechazar cambios; ausencia de CI no impide alta administrativa excepcional. No fusionar fichas por semejanza ni asumir identidad por nombres/nacimiento/teléfono; identidades dudosas siguen pendientes de diseño administrativo. Código vencido/agotado: Administrador o Recepción emiten otro tras nueva comprobación de identidad/condiciones. Ninguno establece o consulta contraseñas del Paciente ni modifica contenido clínico por CU-07. La desactivación conserva fichas/citas/cuentas e historial.

<a id="cu-08"></a>
#### CU-08 — Actualizar el perfil personal

**Trazabilidad de la ficha:** Alcance: 1.4.1 y 1.4.2. Proceso: PN05. Correspondencia: RF01, RF02; RN01, RN12, RN14. El cambio de contraseñ pertenece a CU-05. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 78.

**Propósito**

Actualizar el perfil propio y gestionar de forma segura el cambio del correo de acceso, sin alterar registros clínicos ni políticas de contraseña.

**Actores**

Paciente

**Iniciador**

Paciente

**Precondición**

Cuenta activa, sesión normal válida y vinculación con la ficha personal. Una ficha inactiva permite consultar el perfil, actualizar contacto/emergencia/preferencias y gestionar credenciales por sus flujos autorizados; no habilita reservas nuevas. No se permite editar directamente antecedentes médicos, alergias registradas clínicamente, enfermedades crónicas, medicamentos/tratamientos, diagnósticos ni resultados de laboratorio.

**Flujo principal**

1. El Paciente abre Mi perfil; el servidor recupera sus datos administrativos propios, contacto de emergencia y preferencia de recordatorios. La información clínica se consulta mediante CU-22/CU-27 con autorización propia, no se carga automáticamente desde el perfil.
2. Modifica exclusivamente teléfono, dirección, correo de contacto, contacto de emergencia y preferencia de recordatorios, conforme a la matriz explícita de CU-07/CU-08. Consulta CI, nombres, apellidos, nacimiento y sexo; solicita sus correcciones en Recepción.
3. El servidor verifica titularidad, campos y unicidad, sin cambiar el identificador ni la vinculación.
4. Guarda los cambios y registra las modificaciones administrativas relevantes en la bitácora.
5. El correo de contacto se conserva separado del de acceso; el cambio de contraseña se realiza exclusivamente por CU-05.

**Flujo alternativo**

CAMBIO DEL CORREO DE ACCESO
1. El Paciente autenticado confirma su contraseña actual e introduce el nuevo correo.
2. El servidor rechaza un correo igual al vigente conforme a la normalización existente, comprueba unicidad y crea una solicitud protegida, sin sustituir el correo vigente ni el de contacto. Solo hay una solicitud pendiente por cuenta; iniciar otra invalida la anterior sin eludir los límites de emisión.
3. Brevo envía al nuevo correo un código según la política existente: seis dígitos, diez minutos, cinco errores y los límites de reenvío ya definidos.
4. El Paciente introduce el código; el servidor valida su contexto, vigencia y uso.
5. Antes de confirmar vuelve a comprobar la unicidad y la cuenta, para impedir cambios simultáneos incompatibles.
6. Actualiza únicamente el correo de acceso y su verificación, consume el código de forma íntegra, mantiene cuenta/ficha y contraseña e invalida las recuperaciones pendientes. Revoca todas las sesiones de la cuenta y exige nuevo login con el nuevo correo; no inicia sesión automáticamente.
7. Registra el cambio sin códigos ni contraseñas. Hasta entonces solo el correo anterior permite iniciar sesión. Cambio/recuperación de contraseña, desactivación de cuenta y cambio administrativo de rol invalidan las solicitudes pendientes de cambio de correo. Logout ordinario no las cancela por sí solo: continuar exige autenticarse como la misma cuenta y una solicitud aún válida.

El código pertenece exclusivamente al propósito CambioCorreo, al usuario y al correo propuesto; no se intercambia con activación, invitación o recuperación. Cada reenvío invalida el código anterior. Fallar el envío no modifica el correo vigente ni permite confirmar con un código cuya entrega no fue aceptada por el transporte; aceptación no acredita llegada al buzón. Un reenvío permite reintentar de forma controlada, sin fabricar éxito ni ampliar la vigencia del código anterior.

**Postcondición**

El perfil o correo de acceso quedan actualizados solo tras sus validaciones. No cambian contraseña, rol, permisos, antecedentes, alergias clínicas, enfermedades crónicas, medicamentos/tratamientos, diagnósticos, consultas, resultados ni vínculos de la cuenta.

**Excepciones**

Contraseña actual incorrecta, correo ocupado, código inválido, vencido o agotado, envío fallido o cuenta sin autorización: rechazar el cambio de acceso. Interrupción o conflicto simultáneo: conservar el correo anterior y evitar cambios parciales o cuentas duplicadas. Los reenvíos respetan las políticas existentes. Ficha ajena o intento de editar antecedentes u otros campos clínicos: denegar. El correo de contacto no verifica ni modifica automáticamente el de acceso.


### 4.3. Médicos y especialidades (`CU-09` a `CU-10`)

<a id="cu-09"></a>
#### CU-09 — Gestionar especialidades médicas

**Avance frontend — fases 1/2 (2026-10-21):** listado/filtros/paginación, alta, detalle, edición y estado implementados para Administrador. Confirma activación/inactivación, conserva asociaciones/citas/historial y separa actividad de catálogo, profesional y cuenta. Duplicados permiten corrección; obsolescencia conserva borrador y exige revisión/adopción de versión actual, sin reenvíos automáticos. Contratos y recorridos verificados mediante transporte HTTP ficticio; integración Laravel y revisión visual en navegador pendientes del cierre integrado. Contexto §17.62, análisis incremental y README frontend contienen la evidencia (443 pruebas/26 archivos en suite completa). Diagrama global continúa aplazado expresamente.

**Trazabilidad de la ficha:** Alcance: 1.4.3. Proceso: PN06. Correspondencia: RF04. RN12, RN14. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 79.

**Propósito**

Mantener las especialidades ofrecidas por la clínica sin duplicar nombres normalizados.

**Actores**

Administrador

**Iniciador**

Administrador

**Precondición**

El Administrador inició sesión.

**Flujo principal**

1. El Administrador lista o consulta las especialidades y elige registrar, modificar, activar o desactivar.
2. Completa identificador, nombre, descripción opcional y estado conforme a RF04.
3. El servidor normaliza el nombre y rechaza duplicados.
4. Guarda el catálogo conservando identificadores, asociaciones médicas y citas históricas.
5. Una especialidad inactiva no se ofrece para nuevas reservas ni reprogramaciones a nuevos turnos, sin cancelar las citas existentes.
6. Al reactivarla, solo presenta turnos reservables cuando existen Médicos activos asociados, horarios válidos y disponibilidad. Registra los cambios relevantes en bitácora.

**Postcondición**

El catálogo queda actualizado sin eliminar relaciones ni citas anteriores. La disponibilidad para nuevas programaciones depende del estado activo, las asociaciones y los horarios válidos.

**Excepciones**

- Nombre normalizado duplicado, campos inválidos o petición de un rol distinto del Administrador: rechazar la operación.
- Desactivar una especialidad no cancela ni modifica las citas existentes.
- Reactivar una especialidad sin Médicos activos asociados o sin horarios válidos no genera disponibilidad de citas por sí mismo. La especialidad podrá permanecer activa, pero solo ofrecerá turnos reservables cuando existan Médicos activos asociados, horarios válidos y disponibilidad real.

**Avance backend — fase 1 (2026-10-21):** listado/detalle, alta, edición parcial y estado implementados para Administrador normal mediante API de especialidades. Validación estricta, duplicado normalizado protegido por índice PostgreSQL y control de edición obsoleta con `actualizado_en_original`. Cambios/versión/auditoría atómicos; no-op no duplica evento. Especialidad inactiva conserva íntegramente asociaciones y citas. Ver README backend, análisis CU-09 y contexto §17.55 para verificación. Interfaz React específica y uso en reserva/reprogramación se implementan en sus propios incrementos; no se acredita disponibilidad por activar el catálogo.

<a id="cu-10"></a>
#### CU-10 — Gestionar médicos y sus especialidades

**Cierre frontend administrativo CU-09/CU-10 — fase 5 (2026-10-21):** detalle sin cuenta enlaza a CU-03; reconsulta profesional, fija rol Médico/ID y solicita correo de acceso independiente. Vínculo existente/parámetro inválido impiden alta; servidor valida concurrencia y errores inciertos bloquean repetición hasta revisión. Recorrido real React/Laravel/PostgreSQL aislado completa catálogo → Médico → habilitaciones → cuenta/invitación → aceptación/login, con 409 obsoleto/adopción y cita futura/rollback; conserva cita/pares y separación de actividad/acceso. Correo capturado localmente. Suite **521 pruebas/30 archivos**, build correcto; PHPUnit focalizado **2/140 aserciones**. Revisión visual/teclado: 52 comprobaciones de disposición y 23 auditorías axe sin infracciones automáticas. Contexto §17.65/análisis/README sincronizados; DER sin cambios nuevos y diagrama global aplazado expresamente. Consultorios/horarios/agenda y aceptación de producción/Brevo real permanecen posteriores.

**Avance frontend — fase 4 (2026-10-21):** actividad profesional con confirmación en detalle y gestión del conjunto habilitado en ruta exclusiva de Administrador. Resumen previo de adiciones/retiros, mínimo una habilitación y versiones exactas. Rechazo de retiro por citas futuras conserva borrador sin modificar citas; reactivación con vínculo incompatible orienta a gestión de cuentas sin cambiar rol/acceso. Obsolescencia/incertidumbre requieren revisar/adoptar datos vigentes y confirmar nuevamente. Suite completa 509 pruebas/29 archivos con transporte ficticio, build correcto; cierre integrado Laravel/navegador y vínculo con creación de cuentas pendientes de fase 5. Contexto §17.64/análisis/README frontend; diagrama global aplazado.

**Avance frontend — fase 3 (2026-10-21):** listado/detalle/alta/edición de datos disponibles para Administrador. Alta exige al menos una especialidad, con búsqueda/paginación y selección conservada entre páginas; permite catálogo inactivo sin activarlo ni generar disponibilidad. Código generado por servidor; alta no crea cuenta/consultorio/horarios. Edición de datos no cambia actividad, habilitaciones ni cuenta; usa versión original y preserva borrador ante conflictos. Detalle muestra habilitaciones y relaciones históricas sin inferir estado de cuenta desde el vínculo booleano. Suite completa 475 pruebas/28 archivos con transporte ficticio, build correcto. Actividad/habilitaciones, vínculo con creación de cuenta, integración Laravel y revisión en navegador siguen posteriores. Evidencia en contexto §17.63/análisis/README frontend; diagrama global aplazado expresamente.

**Trazabilidad de la ficha:** Alcance: 1.4.3. Proceso: PN07. Correspondencia: RF03, RF04. RN02, RN04, RN11, RN12, RN14. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 80.

**Propósito**

Registrar, buscar y mantener profesionales y sus especialidades, conservando sus citas históricas.

**Actores**

Administrador

**Iniciador**

Administrador

**Precondición**

El Administrador inició sesión; existen las especialidades que se van a asociar.

**Flujo principal**

1. El Administrador lista médicos o busca por nombre o especialidad.
2. Selecciona registrar, modificar o activar/desactivar un profesional.
3. Completa código único, registro profesional, nombres, apellidos, teléfono, correo y una o varias especialidades.
4. El servidor valida los datos y las relaciones; la asignación fija de consultorio se gestiona mediante CU-11.
5. El sistema guarda los cambios sin eliminar citas históricas; si queda inactivo, impide nuevas reservas.
6. El Administrador puede consultar su agenda mediante CU-20.

**Postcondición**

El médico y sus relaciones quedan actualizados; la inactivación conserva la información anterior. Si el Médico queda inactivo no recibe nuevas citas ni reprogramaciones a su favor. Con cuenta activa, rol Médico y vínculo legítimo puede completar atenciones y revisiones ya asignadas; la desactivación de la cuenta impide todo acceso protegido. La inactivación profesional no cambia automáticamente el rol, ni un cambio de rol por CU-03 inactiva automáticamente al profesional. Se conserva el vínculo histórico tras retirar el rol Médico, sin que ello conceda permisos clínicos.

**Excepciones**

Código duplicado, especialidad inválida o datos incompletos: no guardar y señalar el problema. Intento de eliminación con historial: aplicar desactivación en lugar de eliminar sus registros.

**Notas y verificaciones de la ficha**

Un médico puede pertenecer a varias especialidades y cada especialidad a varios médicos; el servidor verifica su asociación al guardar citas.

**Decisión aprobada — retiro de habilitación (2026-10-21):** el Administrador puede retirar una especialidad para nuevas reservas sin borrar la asociación histórica. Si el par tiene citas Programadas/Confirmadas cuyo inicio es mayor o igual al instante actual del servidor, se rechaza el retiro con 409 y no se guarda ningún cambio parcial. La comprobación y el retiro se coordinan transaccionalmente con las nuevas obligaciones concurrentes. Tras desaparecer el impedimento se permite retirar la habilitación, manteniendo al menos una especialidad habilitada del profesional conforme a su requisito de una o varias especialidades. La actividad del catálogo es independiente: conservar una asociación no garantiza disponibilidad. El retiro conserva las citas y la atención/revisión previa autorizada; no cambia cuenta, rol ni consultorio.

Administrador o Recepción resuelven citas por sus flujos ordinarios cuando existan. CU-15 conserva Médico/especialidad: desplazar fecha no habilita retiro ni traslado a otro profesional. Cancelación por CU-16, también autorizada a Administración. CU-10 no cancela/reprograma automáticamente. Rehabilitar un par conserva identidad, sin generar turnos ni reactivar Médico/catálogo/cuenta.

**Estado de desarrollo (2026-10-21):** CU-09 backend implementado en fase 1; CU-10 fase 2 incorpora listado/detalle/alta/edición de datos, con especialidades iniciales existentes, código aleatorio generado por servidor y control de edición obsoleta. Alta y pares/evento atómicos, sin crear cuenta ni consultorio. Filtros administrativos exclusivos de Administrador normal y recursos mínimos. No añade unicidad del registro profesional ni correo de contacto. La actividad del catálogo es independiente del par: asociar no genera turnos ni reactiva especialidades. Fase 3 incorpora PATCH de actividad profesional con versión/auditoría atómicas, preservación de sesiones/obligaciones e integración concurrente CU-03. Reactivar con cuenta vinculada exige rol Médico y vínculo recíproco compatible; no activa cuentas ni levanta bloqueos. Retirar el rol sigue exigiendo inactividad y ausencia comprobable de obligaciones; reactivación profesional no devuelve roles retirados. Fase 4 incorpora sustitución de habilitaciones por PUT: pares históricos conservados, mínimo una habilitación, control de versión y rechazo atómico por citas futuras según VS-MED-07. Listado/filtro usa pares habilitados; detalle incluye retirados con su marca. Retiro frente a escritura de reserva se verifica mediante protocolo simulado de pruebas, sin acreditar CU-14/CU-15. Fase 5 integra catálogo/médico/invitación/cuentas/sesiones e historia clínica/laboratorio preservada mediante pruebas de todos los componentes. Alcance administrativo backend CU-09/CU-10 implementado; evidencia y contratos en README/contexto §§17.56–17.59. Frontend específico, agenda CU-20 y aceptación de producción pendientes. Consultorio fijo/horarios se desarrollan en CU-11/CU-12.


### 4.4. Consultorios, horarios y disponibilidad (`CU-11` a `CU-12`)

<a id="cu-11"></a>
#### CU-11 — Gestionar consultorios y su asignación fija

**Trazabilidad de la ficha:** Alcance: 1.4.3 y 1.4.4. Proceso: PN08. Correspondencia: RF03, RF05. RN05, RN07, RN12, RN14. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 81.

**Propósito**

Mantener los consultorios de la sede única y la asignación fija de cada médico con uso compartido compatible.

**Actores**

Administrador

**Iniciador**

Administrador

**Precondición**

El Administrador inició sesión; el médico está registrado y se dispone de los horarios y citas afectados.

**Flujo principal**

1. El Administrador registra o consulta el código del consultorio, selecciona al Médico y propone su asignación fija o un cambio.
2. El servidor recupera la asignación, los horarios compartidos y las citas futuras afectadas, y muestra los conflictos.
3. Comprueba que el nuevo consultorio permite mantener los horarios y las citas sin superposición con otros Médicos.
4. El Administrador revisa y confirma expresamente los cambios de ubicación permitidos.
5. El servidor revalida la ocupación ante solicitudes simultáneas y guarda de forma íntegra la asignación y las ubicaciones autorizadas de citas futuras Programadas o Confirmadas cuya atención no haya comenzado.
6. Conserva en cada cita Paciente, Médico, especialidad, fecha, hora, duración, código y estado.
7. Actualiza los avisos afectados y registra responsable, fecha y referencias del cambio.
8. Mantiene sin cambios las ubicaciones de citas pasadas, Atendidas, Canceladas o con Inasistencia.

**Postcondición**

La asignación y sus cambios expresos de ubicación quedan compatibles y completos; las citas históricas y sus demás datos permanecen intactos.

**Excepciones**

Conflicto en horarios o citas futuras, consultorio inválido, atención iniciada o cambio simultáneo: no confirmar modificaciones incompatibles ni parciales; conservar la configuración anterior. Si debe cambiarse fecha u hora, Recepción resolverá por CU-15; el Administrador no reprograma ni cancela desde este caso. Un uso compartido exige horarios sin superposición.

**Notas y verificaciones de la ficha**

Probar conflicto con una cita futura y con otro Médico, confirmación simultánea y conservación de ubicación histórica. La actualización administrativa de ubicación no otorga permisos de reprogramación.

**Decisión aprobada (2026-10-21):** desactivar un consultorio se rechaza mientras tenga Médicos asignados (incluidos inactivos) u obligaciones futuras de atención. Resolver antes asignaciones y citas mediante operaciones autorizadas, sin cambios automáticos. Inactivar un horario no borra ni libera por sí solo los intervalos de citas existentes.

**Avance backend — fase 1 (2026-10-21):** diseño/contrato documentados en `backend/README.md`; catálogo/estado, consulta de impacto y confirmación expresa de traslado previstos, todavía sin endpoints nuevos. Edición obsoleta por versión, huella de impacto completo independiente de paginación y revalidación transaccional. Diseño conserva citas históricas y bloquea traslados ante atención documentada incluso con iniciada_en NULL. Fases 2/3 implementarán catálogo y asignación; fases 5/6 verificarán concurrencia/integración. No se declara CU-11 terminado.

**Avance backend — fase 2 (2026-10-21):** catálogo/estado implementados mediante GET/POST de consultorios, GET/PATCH de detalle y PATCH de estado, exclusivos de Administrador normal. Código único exacto; versión original y guardado monotónico, rechazo de edición obsoleta incluso no-op. Desactivar exige cero Médicos asignados y cero citas Programadas/Confirmadas con fin_at > ahora; incluye Médicos inactivos y citas en curso aun con asignación fija distinta. Catálogo no modifica instantáneas/intervalos ni avisos existentes. Eventos y datos atómicos; consulta CU-30 amplía listas permitidas para Consultorio. Migración 000033 versiona consultorios con inicialización de existentes, probada solo en base aislada. Contratos/pruebas en README/contexto §17.77, análisis/DER sincronizados; asignación/impacto/traslados siguen en fase 3. CU-11 continúa parcial.

**Verificación de fase 2:** 43 pruebas nuevas (39 funcionales y 4 concurrentes); suite completa **1030 pruebas/11417 aserciones correctas**, Pint/sintaxis/Composer/rutas/diff correctos. Fase 2 cerrada localmente; no acredita asignación futura ni aplicación de migración en base habitual/Railway.

**Avance backend — fase 3 (2026-10-21):** GET de asignación, POST de impacto paginado y PUT de confirmación implementados. Evalúa horarios activos (también de Médicos inactivos), ocupación real de ambos recursos, cobertura y atención documentada. Huella completa independiente de página y vinculada al actor, revalidada bajo coordinación transaccional; confirma todos los traslados expresamente o rechaza íntegro. Citas futuras elegibles actualizan solo ubicación/actualizada_en; preservan tiempos/duración/estado/identidad/version_programacion y avisos. No-op no reescribe snapshots por cambios del catálogo. CambioCita y evento MEDICO_CONSULTORIO_CAMBIADO atómicos y proyectados por CU-30; sin nueva migración. Funcionalidad CU-11 implementada con pruebas de permisos/historia/rollback y carreras independientes; revisión integrada del módulo en fases 5/6. Resultados finales en README/contexto §17.78. CU-12 mantiene fase 4 y diagrama global permanece aplazado.

**Verificación final de fase 3:** 47 pruebas nuevas (42 funcionales/5 concurrentes), suite completa **1077 pruebas/11943 aserciones correctas**. Formato/sintaxis/autoload/rutas/diff correctos; fase 3 cerrada localmente. Reserva/atención concurrentes son simulaciones del protocolo futuro, sin acreditar CU-14/CU-23.

**Garantías ampliadas — fase 5 (2026-10-21):** revalidación del actor/sesión tras middleware y espera, carreras en ambos órdenes con rollback frente a horarios/duración y reserva/atención simuladas. Traslado conserva atomicidad de versión/ubicación/historial/evento; consulta existente con iniciada_en NULL invalida huella. Barrido de intervalos en AsignacionConsultorioService elimina coste repetido de pares/casts, conservando todos los conflictos, límites contiguos y huella independiente de página; medición local 300 citas ~12,4 s → ~222 ms HTTP sin índices nuevos. GiST real se traduce selectivamente a 409 y SQL inesperado a 500 seguro. Resultados de regresión/cierre en README/contexto §17.80. Fase 6 conserva integración/cierre del módulo; fixtures no acreditan CU-14/CU-23.

**Avance frontend CU-11 — fase 1 (2026-10-22):** catálogo de consultorios con filtros/paginación, alta/detalle/edición y actividad con confirmación, exclusivo de Administrador normal. Consulta cantidades administrativas y representa bloqueos por asignaciones (también Médicos inactivos)/citas según backend, sin cambiar historia ni resolver reservas automáticamente. Borradores/versiones/errores/resultados inciertos tratados mediante revisión explícita; cookies/CSRF y seguridad compartida conservados. 39 pruebas nuevas y suite frontend completa 660 pruebas/38 archivos correctos, build correcto; Chrome con HTTP interceptado: 16 comprobaciones responsive/8 auditorías axe sin incidencias detectadas. No acredita integración Laravel real aún. Contrato/evidencia en frontend/README.md y contexto §17.82; asignación/impacto/traslados mantienen fase 2, CU-11 frontend parcial. Backend/DER/reglas intactos, diagrama global/PHP aplazados.

**Avance frontend CU-11 — fase 2 (2026-10-22):** asignación inicial/cambio y no-op mediante consulta de contexto Médico, selector paginado de consultorio activo, impacto completo paginado por POST y PUT con versión/huella/consentimiento de todos los traslados. Destino nuevo o huella diferente entre páginas invalida revisión; consentimiento nunca se arrastra de una propuesta anterior. Conflictos/atención documentada impiden guardado según backend; red/500 inciertos requieren consultar estado vigente antes de otro envío. No modifica fechas/estados/duración ni ofrece desasignación. 41 pruebas nuevas; suite frontend 701 pruebas/40 archivos correctos, build correcto; Chrome con HTTP ficticio: 8 tamaños/estados y 4 auditorías axe sin incidencias detectadas. Contrato/evidencia en frontend README/contexto §17.83. Funcionalidad cliente CU-11 incorporada; integración real/cierre conjunto conserva fase 5, CU-12 frontend fases 3/4 pendientes. Backend/DER/reglas intactos y diagrama global/PHP aplazados.

**Cierre frontend CU-11 — fase 5 (2026-10-22):** integración real local con Laravel/PostgreSQL en esquema de prueba autorizado y desechable. Catálogo/actividad/paginación, rechazo de duplicado/desactivación ocupada; impacto de 22 citas en dos páginas, consentimiento completo/teclado y 22 traslados con 22 cambios de cita. IDs, fechas/horas, estados y duraciones conservados en PostgreSQL. Huella/versión Médico/versión Consultorio obsoletas rechazadas con revisión explícita; tres roles ajenos sin acceso React/API y sesiones reales comprobadas. Cierre conjunto CU-11/CU-12: 27 comprobaciones funcionales, 20 de disposición/legibilidad y 12 auditorías axe sin incidencias detectadas; 10 regresiones transversales nuevas, suite frontend 790 pruebas/46 archivos y build correctos. README/contexto §17.86/análisis sincronizados; entorno temporal retirado, base habitual/backend/DER/reglas intactos. Funcionalidad frontend cerrada **localmente**; producción, diagrama global y actualización PHP siguen pendientes/aplazados. No modifica flujos aprobados de este CU.

<a id="cu-12"></a>
#### CU-12 — Configurar horarios y duración de citas

**Trazabilidad de la ficha:** Alcance: 1.4.4. Proceso: PN09. Correspondencia: RF05. RN05, RN07, RN08, RN12, RN14. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 82.

**Propósito**

Definir la atención del médico y calcular turnos completos con la duración configurada.

**Actores**

Administrador

**Iniciador**

Administrador

**Precondición**

El Administrador inició sesión; existen el médico y su consultorio asignado.

**Flujo principal**

1. El Administrador recupera el Médico, su consultorio fijo y la configuración vigente.
2. Define días, hora inicial, hora final y duración, inicialmente de 15 minutos.
3. El servidor identifica citas futuras y verifica que continúen dentro del horario propuesto y sean compatibles con ambos recursos.
4. Muestra conflictos antes de confirmar y exige que se resuelvan por operaciones autorizadas.
5. Si todo es válido, revalida y guarda íntegramente la nueva configuración; las citas existentes conservan sus duraciones y datos originales.
6. Calcula nuevos intervalos con la duración vigente y con la ocupación real de cada cita existente, sin ofrecer bloques que invadan su duración almacenada.
7. Las nuevas reservas aplican la configuración vigente; CU-15 la aplica al reprogramar y vuelve a comprobar el intervalo completo. Registra la modificación administrativa en bitácora.

**Postcondición**

La configuración queda actualizada sin invalidar citas existentes ni modificar automáticamente sus duraciones, fechas o estados.

**Excepciones**

Fin no posterior al inicio, duración inválida, cita futura fuera del nuevo horario o conflicto de Médico o consultorio: rechazar y conservar la configuración anterior. El Administrador no mueve ni cancela reservas ni cambia individualmente su duración. Recepción resuelve las reprogramaciones mediante CU-15. Error de persistencia o solicitud simultánea incompatible: evitar cambios parciales. Feriados y bloqueos siguen siendo opcionales.

**Notas y verificaciones de la ficha**

Probar  horario  que  excluye  una  reserva,  cambio  de  duración  sin  alteración  retroactiva  y  nuevas  disponibilidades calculadas con los intervalos reales de las citas conservadas.

**Decisiones aprobadas (2026-10-21):** actividad del Médico y del horario independientes. Inactivar al Médico conserva horarios; los activos siguen reservando franja administrativa hasta su modificación/desactivación expresa por el Administrador sin afectar citas vigentes. Horario desactivado deja de reservarla, sin alterar ocupación/ubicación de citas existentes. Reactivar al Médico no reactiva horarios y un Médico inactivo no ofrece nuevas reservas. Duración global entera positiva, inicialmente 15, debe caber en cada intervalo activo, incluidos los de Médicos inactivos; rechazo íntegro si alguno es incompatible, sin exigir múltiplos exactos y sin modificar duraciones de citas existentes.

**Avance backend — fase 1 (2026-10-21):** diseño de endpoints de horarios/actividad/impacto/duración y estrategia transaccional documentados; aún no implementados. Se reutilizará versión del Médico para horarios y versión existente de ConfiguracionClinica para duración. Intervalos locales semanales sin cruce de medianoche; sobrantes no forman turnos. Fase 4 implementará CU-12, fases 5/6 verificarán garantías e integración. Cálculo reutilizable no acredita búsqueda/reserva CU-13/CU-14, ni scheduler CU-28. No se declara CU-12 terminado.

**Avance backend — fase 4 (2026-10-21):** ocho operaciones implementadas: listado/alta/edición/estado/impacto de horarios y lectura/impacto/cambio de duración global, exclusivas de Administrador normal. Intervalos activos exigen sala fija activa, duración que quepa, ausencia de conflictos semanales y ocupación real de sala; la actividad del Médico no libera horarios. Propuesta preserva cobertura completa de citas propias Programadas/Confirmadas con fin_at > ahora, incluidas en curso. Duración comprueba todos los intervalos activos (también Médicos inactivos), ignora desactivados y conserva citas existentes. Turnos completos por división entera y sobrantes descartados, sin acreditar búsqueda/reserva. Revalidación bajo locks, versiones monotónicas existentes de Médico/configuración y auditoría atómicas; no-op sin nueva versión/evento. Impactos completos paginados sin escritura; sin nueva migración. Verificación funcional/concurrente/regresión y resultados finales en README/contexto §17.79. Funciones administrativas CU-12 implementadas; revisión transversal/integración mantiene fases 5/6 y diagrama global aplazado.

**Verificación final de fase 4:** 55 pruebas nuevas (51 funcionales/4 concurrentes); suite completa **1132 pruebas/12506 aserciones correctas**. Formato/sintaxis/Composer/autoload/rutas/diff correctos; fase 4 cerrada localmente. Reserva concurrente usa protocolo simulado, sin acreditar CU-14. Próxima fase 5: seguridad y concurrencia ampliadas.

**Avance frontend CU-12 — fase 3 (2026-10-22):** selección paginada/buscable de Médico (incluye inactivos), listado filtrado de horarios y alta/edición parcial/actividad con impacto paginado y confirmación. Horas locales HH:mm:ss y días ISO sin pasos arbitrarios; capacidad/duración informativa del servidor, sin calcular huecos ni cobertura en React. Propuesta cambiada invalida diagnóstico, obsolescencia/red incierta bloquean conservando borrador hasta descarte explícito/reconsulta; permisos/cookies/CSRF y seguridad compartida conservados. 42 pruebas nuevas, suite frontend 743 pruebas/43 archivos correctos, build correcto. Chrome con HTTP ficticio: 20 comprobaciones responsive/10 auditorías axe sin incidencias detectadas. Contrato/evidencia en frontend/README.md y contexto §17.84; duración global editable conserva fase 4 e integración real/revisión ampliada/cierre fase 5. Backend/DER/reglas intactos y diagrama global/PHP aplazados.

**Avance frontend CU-12 — fase 4 (2026-10-22):** consulta/edición de duración global con versión de ConfiguracionClinica, impacto completo paginado por POST y confirmación del alcance común a toda la sede. Validación INTEGER/no-op de experiencia, compatibilidad real en servidor e intervalos incompatibles administrativos; no recalcula ni modifica duración de citas existentes. Cambio de propuesta invalida revisión; versión obsoleta o PATCH incierto bloquean hasta descarte/reconsulta explícitos. 37 pruebas nuevas; suite frontend 780 pruebas/45 archivos correctos y build correcto. Chrome/HTTP ficticio: 12 comprobaciones responsive y 6 auditorías axe sin incidencias detectadas. Frontend README/contexto §17.85/análisis sincronizados. Funcionalidades cliente CU-12 incorporadas; integración real/revisión ampliada y cierre conjunto conservan fase 5. Backend/DER/reglas intactos y diagrama global/PHP aplazados.

**Cierre frontend CU-12 — fase 5 (2026-10-22):** horarios y duración conectados realmente a Laravel/PostgreSQL en esquema ficticio aislado: alta/edición parcial/actividad, segundos locales, rechazo de falta de cobertura, Médico inactivo con intervalo activo y duración global incompatible/compatible; versiones obsoletas de Médico/configuración exigen revisión explícita. Se conserva duración histórica de las 22 citas comprobadas; no se implementan búsqueda/reserva/reprogramación. Diez regresiones nuevas de respuestas tardías/logout/revocación/cambio de rol para cuatro rutas del módulo; suite frontend 790 pruebas/46 archivos y build correctos. Evidencia conjunta de 27 comprobaciones funcionales, 20 de disposición/legibilidad y 12 auditorías axe sin incidencias detectadas en README/contexto §17.86/análisis. Entorno temporal retirado, sin cambios backend/base habitual/DER/reglas. Funcionalidad frontend cerrada **localmente**, producción no acreditada; diagrama global/PHP aplazados. No modifica reglas ni flujos aprobados.

**Garantías ampliadas — fase 5 (2026-10-21):** controles de sesión normal/estado/revocación/origen/CSRF de todas las operaciones, revalidación tras middleware y carreras controladas horario frente a asignación/duración/versión compartida de Médico. Ambos órdenes y rollback del primer escritor mantienen compatibilidad y versiones/eventos del ganador. Sin cambio de reglas ni de duración almacenada de citas previas. Resultados de regresión y evidencia en README/contexto §17.80; integración/cierre mantiene fase 6.

**Verificación final compartida CU-11/CU-12 — fase 5:** 53 pruebas nuevas (9 garantías/44 concurrentes); suite completa **1185 pruebas/13615 aserciones correctas**. Medición final con 300 citas: 238,15 ms HTTP/24 consultas; formato/sintaxis/Composer/autoload/rutas/diff correctos. Fase 5 cerrada localmente; siguiente fase 6 integración/cierre del módulo. Reserva/atención siguen siendo fixtures de protocolo, sin acreditar CU-14/CU-23; diagrama global/PHP aplazados.

**Integración y cierre compartido CU-11/CU-12 — fase 6 (2026-10-22):** tres recorridos/183 aserciones dirigidas correctas con HTTP Laravel, cookies/CSRF y sesiones persistidas reales; planificación y productores CU-30 integrados con alta de Médico/especialidad y ficha de Paciente por Recepción, logout/nuevo login, independencia de actividad profesional/horario, traslados/avisos e historia clínica/documental conservada. Citas/avisos/clínica/laboratorio se preparan como fixtures ficticios, no acreditan sus endpoints. **Suite completa 1188 pruebas/13798 aserciones correctas**, formato/sintaxis/Composer/autoload/rutas/diff correctos; evidencia en README/contexto §17.81. Backend administrativo de ambos CU terminado y verificado localmente, fases 1–6 completadas; frontend/producción y disponibilidad/reservas siguen pendientes, diagrama global/PHP aplazados.


### 4.5. Registro y reserva de citas presenciales (`CU-13` a `CU-14`)

<a id="cu-13"></a>
#### CU-13 — Buscar médicos y consultar disponibilidad

**Trazabilidad de la ficha:** Alcance: 1.4.3 a 1.4.5. Proceso: PN10. Correspondencia: RF03, RF05, RF06. RN04, RN05, RN06, RN07, RN08, RN12 Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 83.

**Propósito**

Encontrar médicos activos de una especialidad y conocer sus turnos presenciales disponibles.

**Actores**

Administrador, Recepcionista, Paciente

**Iniciador**

Administrador, Recepcionista, Paciente

**Precondición**

Recepción o el Paciente inició sesión; existen médicos, horarios y consultorios configurados. La especialidad seleccionada debe estar activa para ofrecer nuevas reservas o reprogramaciones.

**Flujo principal**

1. El usuario elige especialidad y, si lo necesita, filtra por consultorio o ubicación dentro de la sede.
2. El sistema muestra solo médicos activos asociados a la especialidad.
3. El usuario selecciona médico y fecha; el sistema obtiene su consultorio fijo.
4. El servidor calcula intervalos disponibles considerando horario, duración y ocupación de ambos recursos.
5. El sistema presenta turnos y ubicación; el Paciente puede continuar solo hacia su propia reserva en CU-14.
6. Recepción puede continuar hacia CU-14 o hacia CU-15 para una cita existente; no se concede esta última opción al Paciente.

**Postcondición**

Se muestra disponibilidad orientativa; todavía no existe una nueva reserva ni se bloquea un turno.

**Excepciones**

Sin médicos o sin turnos: informar y permitir elegir otra especialidad, fecha o profesional. Fecha pasada o filtro inválido: rechazar el criterio y solicitar corregirlo.

**Notas y verificaciones de la ficha**

La consulta no garantiza la reserva: CU-14 y CU-15 vuelven a validar en el servidor antes de confirmar.

**Precisiones aprobadas 2026-10-22:** cada intervalo activo tiene su propio anclaje: inicio + múltiplos de duración vigente, descartando sobrantes y sin desplazar turnos por citas/cancelaciones. Se verifica intervalo completo libre de Médico/consultorio y, con Paciente autorizado identificado, de sus citas Programada/Confirmada; no se revela identidad del ocupante. Inicio estrictamente posterior al instante del servidor, zona America/La_Paz, sin mínimo/máximo arbitrario de días y con consulta por fecha. Disponibilidad/versiones son orientativas: un cambio incompatible exige nueva revisión (VS-HOR-12/VS-CIT-11–13).

**Avance backend fase 1 (2026-10-22): diseñado, no implementado.** Contratos previstos de catálogos mínimos para Recepción/Paciente y disponibilidad paginada por fecha, separados de endpoints administrativos. Cada turno tendrá una revisión HMAC ligada al actor/Paciente/selección/recursos para detectar cambios al solicitar propuesta. Sin tabla de turnos ni bloqueo durante interacción del usuario. README backend/contexto §17.87/análisis previsto sincronizados; fase 2 implementará CU-13 y sus pruebas. Ninguna ruta/clase nueva creada en fase 1.

**Avance backend fase 2 (2026-10-22): CU-13 implementado localmente.** GET de especialidades/Médicos de reserva y disponibilidad por fecha, exclusivos de Recepción/Paciente, filtros/paginación mínimos y turnos anclados por intervalo con segundos, ocupación de Médico/sala/Paciente y franjas compartidas activas incluso de Médico inactivo. Inicio estrictamente futuro según reloj del servidor y fecha America/La_Paz, sin anticipación arbitraria ni turnos persistidos. Revisión HMAC por turno ligada a actor/ficha/recursos, estable entre páginas/reloj sin cambio relevante; no reserva ni autoriza CU-14. Privacidad de ocupantes/entradas cerradas/revalidación de sesión, ficha inactiva consultable con indicador de no reservable. 56 pruebas nuevas; regresión dirigida 229 pruebas/2670 aserciones correctas, verificaciones técnicas en README/contexto §17.88/análisis. Sin frontend/migración ni cierre integral del módulo, que conserva fases 3–5; diagrama global/PHP aplazados.

**Diseño frontend CU-13 fase 1 (2026-10-22), no implementado:** entradas previstas para Recepción/Paciente, selector autorizado de ficha solo Recepción y catálogos mínimos de reserva; fecha de sede/segundos, turnos paginados procedentes del servidor, sin cálculo local ni retención. Cambio de criterios descarta selección/propuesta/respuestas anteriores; ficha inactiva no habilita nueva reserva. Arquitectura, UX, seis contratos y matriz de pruebas en frontend README/contexto §17.92/análisis previsto. Sin rutas/pantallas/servicios nuevos por esta fase; implementación CU-13 requiere fase 2 autorizada. Reglas y backend intactos.

**Avance frontend CU-13 fase 2 (2026-10-22):** búsqueda y selección orientativa implementadas en /recepcion/citas/nueva y /reservar-cita con sesión/rol/menú. Ficha de Recepción sin exigir CI/cuenta; Paciente sin selector/id_paciente. Catálogos específicos de reserva, filtros/paginación, fecha civil y turnos del servidor con segundos/contexto real/indicador de ficha no reservable. Cambiar criterios retira dependencias y respuestas antiguas; sin almacenar selección en URL/history.state/Web Storage, sin cálculos autoritativos ni POST de reserva. 51 pruebas nuevas, regresión frontend **841/48 archivos correcta**, build correcto; revisión Chrome/API ficticia con 16 layouts/8 axe sin infracciones detectadas. No integración Laravel real nueva ni CU-14 UI todavía. README frontend/contexto §17.93/análisis sincronizados; fase 2 local terminada, fases 3–5 pendientes y diagrama global/PHP aplazados.

**Cierre frontend CU-13 fase 5 (2026-10-22):** búsqueda integrada con Laravel/PostgreSQL reales para Recepción/Paciente, especialidades/turnos paginados, segundos/zona de sede y ficha inactiva consultable sin habilitar reserva. Roles ajenos rechazados en UI/API; respuestas de sesión inválida retiran datos. Recarga restaura sesión sin persistir selección/propuesta. Evidencia conjunta CU-13/CU-14: 23 comprobaciones funcionales, 15 visuales/10 axe sin infracciones detectadas, regresión frontend 929 pruebas/51 archivos y build correctos. README frontend/contexto §17.96/análisis sincronizados; frontend cerrado localmente, no producción/Railway/HTTPS ni nuevo CU. Diagrama global/PHP aplazados.

<a id="cu-14"></a>
#### CU-14 — Programar o reservar una cita presencial

**Trazabilidad de la ficha:** Alcance: 1.4.5. Proceso: PN11. Correspondencia: RF05, RF06, RF07. RN03, RN04, RN05, RN06, RN07, RN08, RN12 RN13, RN14. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 84.

**Propósito**

Registrar una cita válida y sin conflictos para el propio Paciente o para un paciente seleccionado por Recepción.

**Actores**

Administrador, Recepcionista, Paciente

**Iniciador**

Administrador, Recepcionista, Paciente

**Precondición**

Existe sesión autorizada y un paciente válido; el Paciente dispone de su cuenta vinculada. Hay turnos calculados mediante CU-13. El registro administrativo del Paciente está activo, tanto para reservas propias como para programación por Recepción.

**Flujo principal**

1. Administrador o Recepción seleccionan al paciente mediante el flujo administrativo; si actúa el Paciente, se utiliza exclusivamente su registro vinculado.
2. El usuario elige especialidad, médico activo, fecha y hora disponible; el motivo breve es opcional.
3. El sistema muestra el detalle con modalidad Presencial y consultorio fijo obtenido del médico.
4. El usuario solicita guardar la reserva.
5. El servidor verifica paciente, rol, especialidad del médico, estado activo, fecha, horario y duración.
6. El sistema revalida ambos recursos al confirmar e impide superposiciones incluso con reservas o reprogramaciones simultáneas.
7. Guarda código único, fecha de creación, ubicación y estado Programada y muestra el detalle.

**Postcondición**

La cita queda persistida en PostgreSQL sin conflicto para el médico ni el consultorio.

**Excepciones**

Turno ocupado, intervalo superpuesto, fecha pasada o fuera del horario: rechazar y permitir seleccionar otro turno. Médico ajeno a la especialidad, inactivo o paciente ajeno a la cuenta: no guardar. Error de persistencia: no confirmar una reserva parcial ni dos operaciones incompatibles. Paciente inactivo: rechazar una nueva cita, conservando su historial y las citas existentes. Especialidad inactiva: rechazar la nueva reserva, sin modificar citas existentes.

**Notas y verificaciones de la ficha**

Conservar ubicación histórica. El diseño con Laravel y PostgreSQL definirá la protección de concurrencia; no se agrega un estado previo a Programada.

**Precisiones aprobadas 2026-10-22:** turno anclado por intervalo activo y comienzo estrictamente futuro según servidor/America/La_Paz, sin anticipación arbitraria. Impedir citas Programada/Confirmada superpuestas del mismo Paciente aun con recursos distintos; permite consecutivas y es ampliación propia, no obligación textual de RN07. Si cambian horario/duración/consultorio/habilitación o se pierde disponibilidad, rechazar confirmación incompatible e informar de forma segura; usuario revisa datos vigentes antes de otra propuesta. No sustituir condiciones automáticamente. Conserva recepción para ficha sin CI/cuenta web y reserva propia exclusivamente desde sesión vinculada.

**Avance backend fase 1 (2026-10-22): diseñado, no implementado.** POST de propuesta sin persistencia/reserva temporal, confirmación en POST /api/citas y consulta explícita de resultado tras red incierta. Propuesta cifrada autenticada de Laravel y UUID único previsto en Cita para deduplicación durable, con autorización actual en toda repetición/consulta. Planeados: transacción/auditoría atómicas, revalidación de recursos/actor y exclusión GiST del Paciente sobre Programada/Confirmada, preservando exclusiones existentes del Médico/consultorio. Migración futura comprueba conflictos previos y aborta sin corregir/borrar citas automáticamente. Contratos/matriz de pruebas en README/contexto §17.87/análisis/nota DER; fases 3/4 implementarán escritura/garantías y fase 5 cerrará integración. No rutas/migraciones nuevas ni CU funcional cerrado por este diseño; CU-15/CU-16/CU-28 siguen fuera de esta entrega.

**Avance backend fase 3 (2026-10-22):** implementados POST propuesta, confirmación /api/citas y consulta de resultado con sesión/CSRF y scopes de Recepción/Paciente. Propuesta sin tabla/retención del turno, sobre Crypt autenticado con propósito/versión/UUID/actor/ficha/selección/revisión/resumen y HMAC del motivo; confirma solo la información revisada, sin sustituciones silenciosas. Nueva Cita Programada/Presencial con código/fecha/ubicación/duración efectivas y evento CITA_PROGRAMADA atómicos. Migración aditiva 000034 agrega UUID nullable/único a Cita para repetición/resultado durable: misma cita vigente, sin otro evento, con permisos actuales y nunca restaurando el estado inicial. Motivo opcional protegido, errores seguros, rollback incluso tras auditoría. 56 pruebas nuevas/678 aserciones dirigidas correctas; evidencia/regresión final en README/contexto §17.89/análisis y DER actualizado (28/244/43). Reserva funcional local incorporada; exclusión SQL de Paciente/carreras ampliadas y cierre del módulo aún fases 4/5. No frontend, CU-15/CU-16/CU-28 ni despliegue; diagrama global/PHP aplazados.


**Verificación final CU-14 fase 3 (2026-10-22):** suite backend completa **1300 pruebas/14957 aserciones correctas** (8 min 54,64 s/158 MB; memoria CLI 512 MB solo para pruebas), con inventario de columnas actualizado a la migración aditiva. Pint/sintaxis/Composer/autoload/rutas/diff correctos. Contexto §17.89/README/análisis/reglas/DER sincronizados; fase 3 cerrada localmente. Continúan pendientes fases 4/5 del módulo, frontend y producción; no se acredita todavía GiST de Paciente ni nuevas carreras multiproceso.

**Avance CU-13/CU-14 fase 4 (2026-10-22):** nueva exclusión SQL del Paciente Programada/Confirmada por intervalo semiabierto, sin debilitar Médico/sala. Migración 000035 bloquea tabla, detecta conflictos previos y aborta sin modificar datos ni revelar identidad; down preserva lo demás. ReservaService traduce su 23P01 a 409 seguro. Conjunto de persistencia/API 107/929 y 62 pruebas multiproceso/1218 aserciones correctos: reservas/replay/resultado durante escritura, SQL sin protocolo, migración esperando escritor, revocaciones después de middleware y cambios de horario/duración/sala/habilitación/inactividad en ambos órdenes, con commit/rollback. No sustitución de propuestas ni alteración histórica silenciosa. Regresión completa final 1377/16232 correcta; evidencia en contexto §17.90/README/análisis/DER. Base habitual/Railway intactas, sin frontend/CU-15/CU-16/CU-28; integración/cierre conserva fase 5.

**Cierre local CU-13/CU-14 fase 4 (2026-10-22):** suite completa final **1377 pruebas/16232 aserciones correctas**, 10 min 15,366 s/178 MB. Dos fixtures de planificación incompatibles con el nuevo GiST se corrigieron con pacientes ficticios distintos y se verificaron antes de la regresión final. Sintaxis/Pint/Composer/autoload/rutas/diff correctos. README/contexto §17.90/análisis/reglas/DER sincronizados. Solo fase 4 terminada; fase 5 no iniciada, frontend y producción pendientes, diagrama global/PHP aplazados.

**Cierre backend CU-13/CU-14 fase 5 (2026-10-22):** integración automatizada de las seis operaciones para Recepción/Paciente con kernel/cookies/CSRF/sesión reales y consulta CU-30. Preparación administrativa por APIs, ficha presencial sin CI/cuenta creada tras revisión explícita de duplicados; identidad de Paciente previamente activada es fixture, no nueva prueba CU-04. Logout/401/nuevo login/resultado/replay conservan una cita/evento e instantáneas, sin recordatorio/CambioCita inicial. Tres pruebas nuevas/129 aserciones; regresión completa **1380/16361 correcta**, 8 min 53,003 s/178 MB. Disponibilidad con 30/300 citas conserva 31 consultas HTTP (18,09/47,66 ms locales, no SLA). README/contexto §17.91/análisis/reglas/DER sincronizados. **Backend CU-13/CU-14 cerrado localmente**; frontend con plan separado, producción/migración habitual/remota sin verificar; diagrama global/PHP aplazados. No inicio de CU-15/CU-16/CU-28.

**Diseño frontend CU-14 fase 1 (2026-10-22), no implementado:** búsqueda → revisión explícita del resumen del servidor → guardado → resultado. Propuesta/motivo solo en memoria de la ruta; cambiarlos exige nueva revisión. Red/timeout/5xx tras guardar requiere consulta explícita del resultado, sin reenvío automático ni suponer rollback. Cita registrada permanece Programada salvo estado vigente distinto devuelto al reconciliar. Logout/401/recarga limpian comprobante: UI no promete recuperarlo después de nuevo login ni inventa búsqueda pública/historial. Fase 3 de primera escritura ya incluirá reconciliación mínima segura; fase 4 ampliará robustez. Contratos/privacidad/errores/rutas previstas en frontend README/contexto §17.92/análisis. No código funcional frontend, cambios backend/DER/RF/RN, ni inicio de CU-15/CU-16/CU-28.

**Avance frontend CU-14 fase 3 (2026-10-22):** motivo opcional y propuesta real → resumen antes de confirmar → registro 201/replay 200 → cita vigente. Backend determina identidad/tiempos/ubicación/estado; hora America/La_Paz h23 y segundos, estado Programada no equivale a llegada Confirmada. Ref contra doble submit, cuerpo exacto propuesta+motivo, CSRF/cookies/sessionBound, sin almacenar token en URL/history.state/Web Storage/AuthContext. Red/5xx/respuesta inválida tras guardar → consulta explícita de resultado sin reenvío automático; true muestra cita, false permite nueva decisión expresa de misma propuesta o búsqueda; fallos conservan incertidumbre. Salida advertida y limpieza por logout/401/pagehide/desmontaje, sin recuperación prometida entre sesiones. 46 pruebas nuevas; regresión **887/50 archivos correcta**, build correcto; Chrome/API simulada 20 layouts/10 axe sin infracciones detectadas. README frontend/contexto §17.94/análisis sincronizados, backend/DER/reglas/RF/RN intactos. Fase 3 local terminada; robustez ampliada/integración real quedan fases 4/5, sin iniciar CU-15/CU-16/CU-28 ni cerrar módulo integral.

**Avance frontend CU-13/CU-14 fase 4 (2026-10-22):** robustez de errores/sesión/navegación comprobada con 41 pruebas nuevas, módulo acumulado 138/5 archivos; regresión frontend **928/51 archivos correcta**, build correcto. Corregidos dos fallos reproducidos: busy indefinido por respuesta descartada tras logout fallido y revisión aún confirmable tras 403 previo de CSRF. Se mantienen consultas explícitas sin reenvíos automáticos, respuestas malformadas no crean éxitos/ausencias supuestos, referencias locales de inicio/Paciente no se envían como campos nuevos. Advertencia de salida se retira al conocerse resultado; roles/pagehide/401/respuestas tardías y Retry-After cubiertos. Sin nueva regla ni implementación de CU-15/CU-16/CU-28, backend/base habitual/Railway intactos. README frontend/contexto §17.95/análisis sincronizados; fase 4 terminada localmente, fase 5 integración real/cierre pendiente, diagrama global/PHP aplazados.

**Cierre frontend CU-14 fase 5 (2026-10-22):** registro real por ambos actores y consulta de resultado tras respuesta perdida después de 201 de Laravel; no reenvío automático ni duplicación. Otra sesión real ocupa turno durante revisión → 409; duración revisada cambia → 409 sin alterar citas anteriores. SQL: cuatro citas Programada con UUID distintos y cuatro eventos, sin CambioCita/recordatorios y auditoría sin motivo/comprobantes. Integración conjunta 23 comprobaciones funcionales/15 visuales/10 axe sin infracciones detectadas; 929 pruebas frontend/51 archivos y build correctos. Corregido skip-link compartido para enfocar contenido sin navegación nativa que interfiera con blocker, con regresión y revisión real. Solo entornos/base/rol/esquemas ficticios aislados, retirados al terminar; base habitual/Railway intactas. README frontend/contexto §17.96/análisis sincronizados. **Módulo frontend cerrado localmente**, no HTTPS/producción; diagrama global/PHP aplazados. No inicio de CU-15/CU-16/CU-28 ni despliegue.

### 4.6. Reprogramación y cancelación (`CU-15` a `CU-16`)

<a id="cu-15"></a>
#### CU-15 — Reprogramar una cita

**Frontend Fase 6 (2026-10-27):** integración real local para ambos operadores, misma cita de ficha inactiva/Confirmada preparada como fixture, revisión/duración vigente/PATCH, corte de respuesta tras commit y recuperación GET sin replay. Otra petición modifica programación entre revisión/escritura y causa 409 real que exige reconsulta explícita. Ajuste responsive y ocultación del snapshot antiguo cuando no puede considerarse vigente, sin nuevas reglas. Recorrido final compartido 18 comprobaciones/65 disposiciones/26 axe correcto, persistencia/autoría/privacidad verificadas y entorno propio retirado; regresión final y cierre en contexto §17.146/análisis/README. Producción y otros módulos fuera.

**Cierre local confirmado:** seis fases frontend completas, global final nueva 2015 pruebas/78 archivos correctos (234,12 s) y build correcto. Sin cambios de reglas/backend/DER ni inicio de otro módulo; producción pendiente.

**Frontend Fase 5 (2026-10-27):** robustez ampliada sin cambio funcional: señal de ciclo de vida impide PATCH tardío después de abandonar espera CSRF, abort tras envío conserva resultado incierto, editar fecha no borra Retry-After y GET descartado tras logout fallido deja error seguro/reconsulta en lugar de carga permanente. 408/status no contractual tras PATCH exige lectura explícita, sin replay; matriz dirigida 208 casos y global final 2015 pruebas/78 archivos correctos con API simulada, build correcto. Fase 5 cerrada localmente, límites en contexto §17.145/análisis/README; integración/cierre frontend Fase 6 pendientes.

**Frontend Fase 3 (2026-10-27):** alternativas paginadas/revisión anterior-nueva/confirmación/PATCH y actualización de gestión implementados para ambos operadores, misma ficha inactiva admisible y Confirmada→Programada. Originales/HMAC intactos, sin selector de otro recurso ni reserva nueva/replay, doble envío bloqueado y resultados inciertos consultados explícitamente; guardado confirmado no se pierde por fallo de refresco. Pruebas dirigidas de citas 428/10 y suite global final 1778/73 correctas con API simulada, build correcto. Fase 3 cerrada localmente, alcance en contexto §17.143/análisis/README. No cierre frontend integral ni integración real/producción; CU-16 todavía sin escritura React.

**Frontend Fase 2 (2026-10-27):** gestión de solo consulta implementada para Administrador/Recepcionista, entradas desde detalle/selección de Paciente, programación/impedimentos/último cambio y retorno. Nombres del detalle verificados por IDs, datos mínimos/privacidad/errores y sesiones con API simulada, sin alternativas ni reprogramación React todavía. Fase 2 cerrada localmente, suite nueva 1690 pruebas/70 archivos correcta y build correcto; análisis/contexto §17.142. No cambio de reglas ni cierre frontend del CU por el enlace operativo.

**Frontend Fase 1 (2026-10-26):** contratos y flujo de gestión compartido Administrador/Recepcionista documentados, no React implementado. Entrada desde detalle de agenda/historial, consulta de gestión, alternativas y revisión anterior/nueva previstas; originales/HMAC no se sustituyen ni se reintenta PATCH automáticamente. Diseño por procedimiento en análisis `cu15-cu16-frontend-fase1`, contexto §17.141; backend cerrado §17.140, frontend Fases 2–6 pendientes. Sin nuevas reglas ni modificación de las vistas de ejecución anteriores.

**Trazabilidad de la ficha:** Alcance: 1.4.6. Proceso: PN12. Correspondencia: RF05, RF07, RF08. RN04, RN05, RN06, RN07, RN08, RN10, RN12 RN13, RN14. Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 85.

**Propósito**

Cambiar fecha y hora de una cita sin perder integridad, historial ni la disponibilidad de los recursos.

**Actores**

Administrador, Recepcionista

**Iniciador**

Administrador, Recepcionista

**Precondición**

Recepción tiene sesión válida. La cita está Programada o Confirmada sin atención realizada; Médico y especialidad están activos. Se admite la misma cita de un Paciente inactivo, sin crear otra.

**Precisiones aprobadas 2026-10-26 (VS-CIT-14–16):** Administrador o Recepcionista con sesión normal, inicio original estrictamente posterior al instante del servidor y ausencia de cualquier ConsultaMedica asociada, incluso si iniciada_en es NULL. La nueva selección debe cambiar el inicio: elegir fecha/hora idénticas se rechaza, sin reajuste de duración/ubicación o pérdida de Confirmada por ese intento. Son concreciones del proyecto; no modifican RF/RN oficiales. Fase 1 define contratos/participantes E/P en README/análisis/contexto §17.135.

**Avance Fase 2 backend:** GET `/api/citas/{id}/disponibilidad-reprogramacion` implementado para Administrador/Recepcionista. Deriva recursos de cita persistida, excluye solo ocupación propia y mismo inicio, admite esa ficha inactiva, conserva barreras de destino/ocupantes ajenos y revalida cita/sesión/tiempo/consulta tras esperas. Entrada cerrada fecha/paginación, respuesta mínima/versiones/HMAC contextual, sin cambios de negocio. 49 pruebas/385 aserciones dirigidas y regresión dirigida 836/5556 correctas; P41 validado/renderizado. Evidencia/límites en contexto §17.136. No gestión GET ni PATCH, cancelación, avisos o frontend; CU-15 parcial, Fases 3–6 pendientes.

**Avance posterior Fase 3 backend:** GET gestión y PATCH reprogramación implementados para ambos operadores. Escritura atómica de misma cita/CambioCita/invalidación de avisos/bitácora con autor real; preserva identidad/creación/motivo/UUID, aplica duración/ubicación revisadas y Confirmada→Programada. Control estricto de originales/HMAC/recursos/elegibilidad y revalidación antes de UPDATE; repetición obsoleta 409, sin replay. Consulta de gestión mínima permite revisar estado persistido sin prueba inequívoca de causalidad de petición perdida. 57/651 dirigidas y 1075/8234 regresión dirigida correctas; P42/P43 cotejados/renderizados, contexto §17.137. Sin cancelación/frontend/generación CU-28; robustez/integración/cierre conservan Fases 5/6. No CU-16 implementado por compartir clases.

**Avance Fase 5 backend:** seguridad/concurrencia ampliadas; control de plazo original también después de última consulta de existencia antes de UPDATE. Mismos procedimientos y contratos, no nueva regla. Pruebas multiproceso verifican gestión/reserva/planificación/revocaciones/SQL con commit/rollback y PID/esperas PostgreSQL independientes; fixtures de consulta clínica no acreditan CU-23. Evidencia y límites en contexto §17.139; frontend y cierre integral Fase 6 pendientes.

**Avance Fase 6 backend:** integración reserva→reprogramación→agenda/historial/detalle→nueva sesión/gestión→cancelación→auditoría→nueva reserva válida, parametrizada por Administrador/Recepcionista. Traslado CU-11 real invalida originales anteriores; misma ficha inactiva admite CU-15, pero no nuevas reservas CU-14. Cuatro pruebas/298 aserciones correctas en GestionCitaIntegracionTest para el grupo CU-15/CU-16, sin sumar como casos distintos a la suite global; evidencia final en contexto §17.140. No se crean cuentas/profesionales ficticios para atribuir autoría ni se implementa frontend/avisos CU-28/clínica. Análisis P41–P44 y UML global acumulativo sincronizados; persistencia conservada.

**Flujo principal**

1. Recepción recupera la cita y su programación vigente.
2. Elige por CU-13 nueva fecha y hora para el mismo Médico y especialidad.
3. El sistema muestra el intervalo con duración vigente y consultorio válido.
4. El servidor comprueba rol, estado, atención no realizada, estados activos y disponibilidad de ambos recursos, incluso ante solicitudes simultáneas.
5. Guarda íntegramente el nuevo intervalo conservando código, Paciente, Médico y especialidad; solo entonces libera el anterior.
6. Programada se conserva; Confirmada vuelve a Programada.
7. Recalcula o invalida los avisos desactualizados, evita duplicados y conserva trazabilidad.

**Postcondición**

La misma cita queda reprogramada con duración vigente y ubicación válida. El Paciente inactivo no recibe por ello autorización para una reserva adicional.

**Excepciones**

Estado final, atención realizada, fecha pasada, turno inválido u otro actor: rechazar. Médico o especialidad inactivos, conflicto o fallo de persistencia: conservar cita y ocupación anteriores, sin cambios parciales. CU-14 sigue rechazando nuevas reservas para pacientes inactivos.

<a id="cu-16"></a>
#### CU-16 — Cancelar una cita

**Frontend Fase 6 (2026-10-27):** cancelación integrada real por ambos operadores con motivo privado opcional, Confirmada/Programada, originales/CSRF y resultado incierto recuperado tras cortar respuesta de commit. Conserva programación/versiones/historial, invalida avisos preparados y libera intervalo reutilizable por nueva reserva UI sin restaurar la cancelada; 409/419/422 y rechazo Médico/Paciente reales. No presentación de snapshot anterior como actual mientras falta reconsulta. Persistencia/auditoría/privacidad/visual verificadas, fixture/servidores propios retirados; resultado final de regresión/cierre en contexto §17.146/análisis/README. No generador CU-28 ni producción.

**Cierre local confirmado:** seis fases frontend completas, global final nueva 2015 pruebas/78 archivos correctos (234,12 s) y build correcto. Sin cambios de reglas/backend/DER ni inicio de otro módulo; producción pendiente.

**Frontend Fase 5 (2026-10-27):** comparte endurecimiento de transporte/lock/sesión/ciclo de vida/recuperación con reprogramación, sin motivo privado persistido ni reenvío. Abandonar CSRF impide PATCH tardío, abort tras envío no demuestra rollback, HTTP no contractual exige consulta de estado y espera tras restaurar sigue visible. Matriz dirigida compartida 208 casos y global final 2015 pruebas/78 archivos correctos con API simulada, build correcto. Fase 5 cerrada localmente; alcance en contexto §17.145/análisis/README. Sin nuevas reglas ni integración real/cierre integral del módulo, Fase 6 pendiente.

**Frontend Fase 4 (2026-10-27):** formulario de motivo privado opcional, revisión/confirmación explícita/PATCH y resultado/reconsulta implementados en gestión compartida para Administrador/Recepcionista. Conserva programación/versión/historial, sin exigir disponibilidad/actividad de recursos, sin borrado/reapertura ni autoría ficticia. Reutiliza coordinador de escrituras y consulta explícita del resultado incierto; motivo no aparece en resultado/último cambio/historial. Pruebas dirigidas de cancelación/servicio/hook 107 y suite global final 1872 pruebas/75 archivos correctas, build correcto con API simulada. Fase 4 cerrada localmente; límites en contexto §17.144/análisis/README. No cierre integral frontend ni integración real/producción, Fases 5/6 pendientes.

**Frontend Fase 2 (2026-10-27):** comparte pantalla de consulta de gestión/acciones básicas/impedimentos/último cambio y acceso seguro desde detalle. Solo GET, sin formulario de motivo ni confirmación/PATCH cancelación; muestra expresamente que las escrituras no están disponibles aún. Fase 2 cerrada localmente con API simulada, suite nueva 1690 pruebas/70 archivos y build correctos; alcance en análisis/contexto §17.142. Cancelación frontend sigue para Fase 4, no CU terminado por contar con navegación.

**Frontend Fase 1 (2026-10-26):** misma consulta de gestión prevista que CU-15, confirmación explícita y motivo privado opcional; cancelación no depende de recursos activos ni de que funcione la consulta de alternativas. Resultado incierto se consulta sin reenvío automático ni afirmar rollback/causalidad. Diseño y matriz de pruebas documentados en análisis/contexto §17.141; aún sin React/rutas/acciones funcionales, frontend Fases 2–6 pendientes. Backend cerrado §17.140 y reglas conservadas.

**Trazabilidad de la ficha:** Alcance: 1.4.6. Proceso: PN13. Correspondencia: RF09. RN09, RN10, RN12, RN14. Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 86.

**Propósito**

Cancelar una reserva antes de la atención, liberar su turno y conservar el registro histórico.

**Actores**

Administrador, Recepcionista

**Iniciador**

Administrador, Recepcionista

**Precondición**

Recepción inició sesión; existe una cita Programada o Confirmada cuya atención todavía no se realizó.

**Precisiones aprobadas 2026-10-26 (VS-CIT-14/15):** Administrador o Recepcionista con sesión normal; rechazar al llegar el inicio original del turno y si existe cualquier ConsultaMedica, incluso con iniciada_en NULL. No exigir ficha/Médico/especialidad activos para cancelar una cita por lo demás elegible. Motivo continúa opcional y no se elimina historial. Estas precisiones no significan endpoint implementado: Fase 1 backend solo análisis/contratos, contexto §17.135.

**Avance posterior Fase 4 backend:** PATCH `/api/citas/{id}/cancelacion` implementado en GestionCitaController/Request/Service existentes, compartido por ambos operadores. Originales obligatorios de programación/timestamp y motivo opcional privado, sin query/IDs de recursos/estado impuesto por cliente. Coordinación/actor/planificación/cita bloqueada, comparación/revalidación de sesión/estado/plazo/consulta y reloj antes de UPDATE. Misma fila Cancelada/programación/versión conservadas, timestamp creciente; CambioCita CANCELACION/invalidación de avisos/CITA_CANCELADA atómicos con ejecutor real. GET gestión agrega acciones.cancelar y permite revisar resultado persistido sin reenvío automático. Repetición/estado final/originales obsoletos rechazan 409 sin duplicaciones. No disponibilidad de destino/actividad de recursos exigidas ni borrado/generación CU-28. 50 pruebas/628 aserciones dirigidas correctas, incluida nueva reserva real en intervalo liberado; evidencia de regresión/documentación P44 en contexto §17.138. Frontend y cierre integral Fases 5/6 pendientes.

**Avance Fase 5 backend:** cancelación verificada frente a otra cancelación/reprogramación y reserva SQL de intervalo original en procesos independientes, commit/rollback. La liberación no es visible antes de confirmar; rollback conserva cita/avisos/historial/auditoría y exclusiones existentes. Revocación tras middleware y espera, sesión restringida/proyecciones privadas verificadas sin nuevos permisos/estados/esquema. P44 conserva sus participantes/diagrama; evidencia/límites en contexto §17.139. Fase 6 y frontend pendientes.

**Avance Fase 6 backend:** cancelación integrada con reserva/reprogramación previas, historial/detalle/bitácora y nueva reserva HTTP sobre intervalo liberado para ambos operadores. Misma identidad/creación/UUID/motivo original y programación/versión conservadas, motivo de cancelación solo privado en CambioCita, avisos invalidados sin generador. Repetición de creación devuelve el estado actual Cancelada sin restaurar ni duplicar la cita. GET gestión con sesión nueva permite revisar estado, no adjudica causalidad inequívoca de petición perdida. Grupo integrado 4/298 correcto, cierre/suite global en contexto §17.140; P44/UML acumulativos y DER sin cambio estructural. Frontend/producción/otros módulos pendientes.

**Flujo principal**

1. Recepción recupera la cita y revisa su estado y datos.
2. Selecciona Cancelar e ingresa un motivo si corresponde; el motivo es opcional.
3. Confirma la operación.
4. El servidor comprueba rol, estado permitido y atención no realizada.
5. El sistema guarda Cancelada sin eliminar la cita y deja de ocupar el intervalo.
6. Retira el recordatorio de asistencia y muestra la confirmación con el historial conservado.

**Postcondición**

La cita está Cancelada, no consume disponibilidad y continúa visible en consultas históricas autorizadas.

**Excepciones**

Cita Atendida, Cancelada o con Inasistencia: rechazar cambio ordinario. Solo Administrador/Recepción autorizados; Paciente/Médico u otro actor sin permiso: denegar incluso por petición directa. Fallo al actualizar: conservar situación anterior y no informar cancelación exitosa.

**Notas y verificaciones de la ficha**

Nunca cancelar una atención realizada como si no hubiera ocurrido; no hay eliminación física del registro.


### 4.7. Estados de citas y control de asistencia (`CU-17` a `CU-19`)

<a id="cu-17"></a>
#### CU-17 — Confirmar la llegada del Paciente

**Frontend Fase 6 cerrado localmente (2026-10-28):** llegada desde UI por Administrador/Recepción con declaración/revisión/CSRF reales, mismo día y anticipación permitida. Corte de respuesta tras commit → GET sin replay, originales obsoletos/permiso ajeno rechazados y programación/versión/ejecutor preservados. Recorrido conjunto 15 comprobaciones/80 disposiciones/32 axe y **suite global final nueva 2702 pruebas/88 archivos correctos**, 266,58 s, §17.158/README/análisis. Seis fases frontend cerradas localmente, fixture/esquema propios retirados. No clínica ni generación CU-28 ni producción. Notas anteriores conservan su alcance histórico.

**Robustez frontend Fase 5 cerrada localmente (2026-10-28):** matriz conjunta CU-17–CU-19 amplía pruebas de sesión/época/ciclo de vida/lock/incertidumbre sin cambiar producción. Administrador/Recepción conservan declaración explícita y revisión; abandono durante CSRF impide escritura tardía, después de enviar no acredita rollback. Cambio de cita/identidad limpia borrador; recuperación rechazada mantiene bloqueo, 429 no habilita otra acción ni autoenvía. Dirigida nueva 351/3, build y **suite global final nueva 2702 pruebas/88 archivos correctos**, 265,76 s, §17.157/README/análisis. HTTP/sesión simulados, no integración real ni Fase 6.

**Frontend Fase 2 cerrada localmente (2026-10-28):** entrada /asistencia reutiliza agenda por rol y abre /citas/:id/asistencia, también accesible desde detalle/historial autorizado. Administrador/Recepción declaran presencialmente presencia/identidad → revisión → confirmación → CSRF/PATCH cerrado con originales exactos; Médico solo consulta propia, Paciente sin acceso. Contrato verificado, resultado Confirmada sin alterar programación/versión, sin clínica/Atendida automáticas. Red/resultado incierto exige GET explícito sin replay/causalidad inequívoca, guardado confirmado se conserva si falla refresco. Dirigida 214/5 y build correctos; **suite global final nueva 2158 pruebas/81 archivos correctos**, 234,74 s, evidencia/límites en contexto §17.154/README/análisis. API/sesión simuladas, no integración real ni CU-18/CU-19 operativos; Fases 3–6/producción pendientes.

**Diseño frontend Fase 1 (2026-10-28), sin UI implementada:** entrada/selección de agenda reutilizada → pantalla compartida de asistencia → presencia/identidad declaradas explícitamente y revisión → PATCH llegada con originales exactos. Administrador/Recepción, mismo día y turno no finalizado según servidor; no crear consulta ni confundir Confirmada con reserva. Ruta UI/navegación/servicio todavía previstos, no registrados. Diseño/matriz en análisis ancla cu17-cu19-frontend-fase1, frontend/README y contexto §17.153; implementación Fase 2 requiere autorización.

**Cierre backend Fase 6 (2026-10-28):** reserva real por tres roles, llegada presencial anticipada por flujo autorizado, agenda/historial/detalle/bitácora y conservación de avisos integrados con CU-19 o reprogramación CU-15. Dirigida conjunta 14/2410 y **suite global final nueva 3260 pruebas/40375 aserciones correctas**, inventario PostgreSQL pre/post preservado. Seis fases backend cerradas localmente, evidencia/límites en contexto §17.152/README/análisis. Sin frontend/producción ni nueva regla; notas siguientes históricas.

**Verificación backend Fase 5 (2026-10-28), cerrada localmente:** seguridad/concurrencia ampliadas junto a CU-18/CU-19, con carreras reales/rollback/commit, revocación tras middleware, consulta clínica simulada y corte de fin/día local tras espera. Regresión ampliada final 1396 pruebas/22337 aserciones correctas, incluidos 164 casos nuevos. Conserva las reglas y el protocolo de Fase 2; evidencia/límites en contexto §17.151/README/análisis. No cierre integral Fase 6 ni frontend.

**Avance backend Fase 2 (2026-10-28):** GET asistencia para Administrador/Recepcionista/Médico propio y PATCH llegada solo para ambos operadores administrativos. Entrada cerrada, originales exactos y declaración JSON true de presencia/identidad; relectura bloqueada, sesión/rol/vínculo, mismo día local, turno no finalizado y cualquier consulta asociada impide escritura. Confirmada/fecha/actor + CambioCita/auditoría atómicos; programación/versión y avisos existentes conservados. Pruebas dirigidas 74/739 correctas; resultado final de regresión en contexto §17.148. No CU-18/CU-19 implementados, generación CU-28, frontend o cierre integral; la nota Fase 1 siguiente conserva su fecha/diseño, no el estado actual.

**Backend Fase 1 (2026-10-28):** diseño documental, no endpoint implementado. Declaración explícita presencia_verificada=true incluye presencia e identidad; Programada/mismo día America/La_Paz/ahora < fin_at. Decisión aprobada: cualquier ConsultaMedica asociada, incluso iniciada_en NULL, bloquea esta operación. No crea consulta ni invalida avisos por pasar a Confirmada. Contratos y pruebas previstas en backend/README/contexto §17.147; implementación Fase 2 pendiente. No amplía permisos ni modifica RF/RN.

**Trazabilidad de la ficha:** Alcance: 1.4.7. Proceso: PN14. Correspondencia: RF10. RN12, RN14. Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 87.

**Propósito**

Registrar que Recepción verificó la presencia efectiva del Paciente para su cita.

**Actores**

Administrador, Recepcionista

**Iniciador**

Administrador, Recepcionista

**Precondición**

Recepción tiene sesión válida; el Paciente está presencialmente identificado, la cita está Programada, corresponde al mismo día local y su intervalo no ha finalizado. No existe atención, cancelación o inasistencia registradas.

**Flujo principal**

1. Recepción localiza la cita y verifica la presencia e identidad del Paciente.
2. Solicita Confirmar llegada.
3. El servidor utiliza la fecha y hora local de la clínica, America/La_Paz, y valida el mismo día, turno no finalizado, estado Programada y atención no registrada.
4. Permite llegada anticipada dentro del mismo día; vuelve a comprobar el estado ante cambios simultáneos.
5. Registra Confirmada, fecha y hora y responsable; genera el evento de auditoría y muestra el estado.
6. No crea consulta ni marca Atendida: son operaciones separadas, CU-19 autorizado a Administrador o Médico responsable, no a Recepción/Paciente.

**Postcondición**

La cita pasa únicamente de Programada a Confirmada por llegada presencial verificada, con registro de responsable y momento.

**Excepciones**

Paciente ausente, otro día, turno finalizado, atención realizada, cita Cancelada, Atendida, con Inasistencia o ya Confirmada: rechazar. Si llega después del fin y la cita sigue Programada sin atención, Recepción podrá reprogramar mediante CU-15 y confirmar solo cuando el nuevo turno cumpla las condiciones; no se cambia un estado final por este procedimiento. Otro rol o modificación simultánea: denegar o mostrar el estado vigente. No se introduce un estado de tardanza.

**Notas y verificaciones de la ficha**

Probar llegada anticipada el mismo día, fecha futura o pasada y llegada tras el fin. CU-18 conserva Inasistencia desde Programada, ausencia comprobada y turno finalizado; CU-19 permite Atendida desde Programada tras una atención real

<a id="cu-18"></a>
#### CU-18 — Registrar la inasistencia del Paciente

**Frontend Fase 6 cerrado localmente (2026-10-28):** ausencia comprobada desde UI por Administrador/Recepción después del intervalo, con declaración/revisión/CSRF reales; estado final/historia/versión preservados y avisos preexistentes invalidados. Corte tras commit y GET sin replay, sin inferir el hecho por tiempo ni crear clínica. Recorrido conjunto 15 comprobaciones/80 disposiciones/32 axe y **suite global final nueva 2702 pruebas/88 archivos correctos**, 266,58 s, §17.158/README/análisis. Seis fases frontend cerradas localmente, fixtures explícitas retiradas; producción pendiente.

**Robustez frontend Fase 5 cerrada localmente (2026-10-28):** matriz conjunta 351/3 dirigida correcta y build, sin cambios productivos/reglas; verifica declaración específica, lock compartido, sesión/época/abort/respuestas tardías, navegación/limpieza/429 y GET incierto sin replay. Solo Administrador/Recepción registran ausencia; **suite global final nueva 2702 pruebas/88 archivos correctos**, 265,76 s, §17.157/README/análisis. API simulada, no integración real ni Fase 6.

**Frontend Fase 3 cerrada localmente (2026-10-28):** misma pantalla de asistencia para Administrador/Recepción, Preparar inasistencia → declaración de ausencia comprobada desmarcada inicialmente → revisión de identidad/programación/efecto final → confirmación explícita. PATCH con ausencia_verificada=true y originales exactos tras CSRF; verifica misma programación/versión/Inasistencia/cambio INASISTENCIA/actor real, sin clínica/avisos manipulados desde React. Elegibilidad del servidor, sin inferir ausencia por tiempo ni admitir Confirmada/reapertura. Comparte lock/error/incertidumbre/GET explícito sin replay con llegada; Médico/Paciente sin registro y Atendida aún pendiente. Dirigida 288/6, dirigida transversal posterior 88/3 y build correctos; **suite global final nueva 2256 pruebas/83 archivos correctos**, 237,62 s, §17.155/README/análisis. Ajuste solo de prueba de Inicio para Recepción sin pendientes; no cambios productivos de autenticación ni reducción de permisos. API simulada, no integración real ni módulo cerrado; Fases 4–6/producción pendientes.

**Diseño frontend Fase 1 (2026-10-28), sin UI implementada:** misma pantalla y consulta de asistencia, declaración de ausencia desmarcada inicialmente/revisión/confirmación, PATCH específico y originales exactos. Administrador/Recepción; elegibilidad/fin/estado decididos por servidor, sin tolerancia/automatismo/reapertura. Diseño/contexto §17.153/análisis/frontend/README; implementación Fase 3 pendiente, no habilitada por esta fase documental.

**Cierre backend Fase 6 (2026-10-28):** secuencia real de llegada/reprogramación/Programada demuestra que rige el nuevo intervalo, no el anterior: rechaza ausencia temprana y admite exactamente al nuevo fin, con misma cita y auditoría/avisos atómicos. Dirigida conjunta 14/2410 y **suite global final nueva 3260 pruebas/40375 aserciones correctas**, inventario PostgreSQL pre/post preservado. Seis fases backend cerradas localmente, evidencia/límites en contexto §17.152/README/análisis. No automatismo, frontend/producción ni CU-28 completo.

**Verificación backend Fase 5 (2026-10-28), cerrada localmente:** carreras/rollback/commit y revocaciones multiproceso, consulta concurrente incluso iniciada_en NULL y corte exacto de fin tras espera, sin tolerancia ni automatismo. Reutiliza protocolo productivo sin nuevos estados/esquema. Regresión ampliada final 1396 pruebas/22337 aserciones correctas, incluidos 164 casos nuevos; evidencia/límites en contexto §17.151/README/análisis. Integración/cierre Fase 6 y frontend pendientes.

**Avance backend Fase 3 (2026-10-28):** PATCH /api/citas/{id}/inasistencia para Administrador/Recepcionista, entrada cerrada/originales exactos y ausencia_verificada JSON true. Solo Programada, intervalo finalizado (ahora >= fin_at, igualdad incluida) y sin consulta asociada, incluso iniciada_en NULL. Confirmada/finales rechazan; sin tolerancia ni automatismo. Estado/timestamp/historial inmutable/invalidación solo de avisos propios existentes/auditoría segura en una transacción; conserva identidad/programación/versiones y no genera avisos. GET asistencia expone elegibilidad orientativa sin modificar datos; CU-19 sigue no implementado. Nueva batería 63/643 correcta; regresión final y límite local en contexto §17.149/README/análisis. Sin cambios RF/RN/esquema/frontend ni cierre integral; la nota Fase 1 siguiente es antecedente documental.

**Backend Fase 1 (2026-10-28):** diseño documental, no endpoint implementado. Declaración explícita ausencia_verificada=true, exclusivamente Programada y ahora >= fin_at (exactamente al fin ya terminó [inicio, fin)); sin tolerancia ni automatismo. Decisión aprobada: cualquier ConsultaMedica asociada, incluso iniciada_en NULL, bloquea la operación. Historial/auditoría/invalidación de avisos existentes previstos en transacción, sin generador CU-28. Contratos/matriz en backend/README/contexto §17.147; implementación Fase 3 pendiente.

**Trazabilidad de la ficha:** Alcance: 1.4.7. Proceso: PN14. Correspondencia: RF10. RN12, RN14. Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 88.

**Propósito**

Registrar la ausencia comprobada cuando ya terminó el intervalo de una cita.

**Actores**

Administrador, Recepcionista

**Iniciador**

Administrador, Recepcionista

**Precondición**

Recepción inició sesión; la cita está Programada, su intervalo terminó y se comprobó que el Paciente no se presentó.

**Flujo principal**

1. Recepción localiza la cita y revisa fecha, hora inicial, hora final y estado.
2. Comprueba la ausencia y selecciona Registrar inasistencia.
3. El servidor verifica rol, estado Programada y finalización del intervalo.
4. El sistema guarda Inasistencia y conserva la cita para historial y reportes.
5. El sistema no genera nuevos recordatorios de asistencia para esa cita.

**Postcondición**

La cita queda en Inasistencia como estado final para las operaciones ordinarias.

**Excepciones**

Intervalo aún no finalizado o presencia registrada: rechazar el cambio. Confirmada u otro estado, usuario no autorizado o cambio simultáneo: no registrar Inasistencia.

**Notas y verificaciones de la ficha**

No se aplica Inasistencia desde Confirmada ni antes del fin del turno; no se agregan períodos de tolerancia no definidos.

<a id="cu-19"></a>
#### CU-19 — Marcar una cita como Atendida

**Frontend Fase 6 cerrado localmente (2026-10-28):** Administrador/Médico propio registran atención desde Programada/Confirmada con declaración/revisión/CSRF reales, profesional inactivo con cuenta activa conserva asignaciones anteriores. Responsable/ejecutor separados; no consulta clínica requerida ni creada. Corte tras commit/GET sin replay, 409 real entre revisión y envío exige reconsulta sin reapertura; permisos/419/422 rechazados. Recorrido conjunto 15 comprobaciones/80 disposiciones/32 axe y **suite global final nueva 2702 pruebas/88 archivos correctos**, 266,58 s, §17.158/README/análisis. Seis fases frontend cerradas localmente, producción pendiente. Fixtures/esquema propios retirados.

**Robustez frontend Fase 5 cerrada localmente (2026-10-28):** matriz conjunta 351/3 dirigida correcta y build, sin cambios productivos/reglas; Administrador/Médico propio mantienen declaración/revisión y responsable separado del ejecutor. Verifica ciclo de vida/sesión/época/lock/incertidumbre/GET rechazado sin replay, sin modificar clínica ni habilitar Recepción/Paciente. **Suite global final nueva 2702 pruebas/88 archivos correctos**, 265,76 s, §17.157/README/análisis. API simulada, no integración real ni Fase 6.

**Frontend Fase 4 cerrada localmente (2026-10-28):** Administrador/Médico propio reutilizan asistencia: preparar atención, declarar atención efectivamente realizada, revisar identidad/programación/estado/efecto y confirmar explícitamente. PATCH con originales exactos y atencion_realizada=true; verifica Atendida/programación/versión/cambio ATENCION/actor real sin sustituir al Médico responsable. No requisito local de llegada/fin/mismo día/consulta ni creación/modificación clínica. Recepción/Paciente sin acción, finales sin reapertura. Un lock para las tres escrituras y GET explícito ante incertidumbre sin replay ni causalidad inequívoca. Dirigidas 176/4 y ampliada 415/10, build y **suite global final nueva 2351 pruebas/85 archivos correctos**, 244,93 s, §17.156/README/análisis; no sumar ejecuciones. API simulada, no integración real ni módulo cerrado; Fases 5–6/producción pendientes. El diseño histórico siguiente no representa el estado vigente.

**Diseño frontend Fase 1 (2026-10-28), sin UI implementada:** pantalla compartida Administrador/Médico propio, declaración de atención efectivamente realizada/revisión/confirmación y PATCH atención con originales; no exigir Confirmada/fin/mismo día/consulta previa ni crear clínica. GET asistencia orientativo, ejecutor auténtico/Médico responsable separados, Recepción/Paciente sin acción. Recuperación incierta solo GET explícito sin replay/causalidad inequívoca. Diseño §17.153/análisis/frontend/README; implementación Fase 4 pendiente.

**Cierre backend Fase 6 (2026-10-28):** reserva real, Médico invitado/activado por API, atención desde inicio por Administrador/Médico con/sin llegada previa y lecturas de cuatro roles; conserva ejecutor/responsable separados, programación/versión y clínica no creada. Logout/nuevo login/GET reconcilia sin replay, finales no reabiertos. Dirigida conjunta 14/2410 y **suite global final nueva 3260 pruebas/40375 aserciones correctas**, inventario PostgreSQL pre/post preservado. Seis fases backend cerradas localmente, evidencia/límites en contexto §17.152/README/análisis. Frontend/producción y CU-23 completos fuera.

**Verificación backend Fase 5 (2026-10-28), cerrada localmente:** seguridad/concurrencia multiproceso, Médico propio/ajeno/reasignación simulada/vínculo revocado/identidad dual, carreras contra otras transiciones y gestión, commit/rollback y corte de inicio con tiempo monotónico. Conserva responsable/ejecutor separados y no altera clínica; sin nuevo flujo CU-23. Regresión ampliada final 1396 pruebas/22337 aserciones correctas, incluidos 164 casos nuevos; evidencia/límites en contexto §17.151/README/análisis. No cierre Fase 6 ni frontend.

**Avance backend Fase 4 (2026-10-28):** PATCH /api/citas/{id}/atencion compartido por Administrador/Médico propio, con originales exactos y declaración estricta atencion_realizada=true. Programada/Confirmada, ahora >= inicio_at; sin exigir fin/mismo día/consulta, sin crear ni alterar clínica. Médico ajeno 404 neutro, vínculo incompatible 403, cuenta/sesión revocada impide acceso, Recepción/Paciente 403. Conserva Médico responsable/ejecutor real e historial/programación/versión, invalida solo avisos propios existentes y registra CambioCita/evento seguros atómicos. GET asistencia solo expresa elegibilidad orientativa. Nueva batería inicial 69/852 correcta; regresión final tras reforzar negativas en contexto §17.150/README/análisis. Sin nuevos RF/RN/esquema/frontend ni cierre integral; Fases 5–6 pendientes. La nota Fase 1 siguiente conserva su diseño/fecha, no describe el estado actual.

**Backend Fase 1 (2026-10-28):** diseño documental, no endpoint implementado. Decisiones aprobadas: atencion_realizada=true explícito y ahora >= inicio_at vigente; no exigir fin_at ni mismo día ni ConsultaMedica previa. Desde Programada/Confirmada, Administrador o Médico responsable; su declaración no sustituye comprobaciones ni demuestra automáticamente el hecho clínico. Médico inactivo profesionalmente con cuenta activa conserva atención previamente asignada; ejecutor real/Médico responsable separados. No crea consulta ni transición automática al guardar CU-23. Contratos/matriz en backend/README/contexto §17.147; implementación Fase 4 pendiente.

**Trazabilidad de la ficha:** Alcance: 1.4.7 y 1.4.9. Proceso: PN15. Correspondencia: RF10. RN10, RN12, RN14. Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 89.

**Propósito**

Registrar que el Médico responsable realizó la atención de su propia cita.

**Actores**

Administrador, Médico

**Iniciador**

Administrador, Médico

**Precondición**

El Médico responsable inició sesión, realizó la atención y la cita está Programada o Confirmada.

**Flujo principal**

1. El Médico abre su agenda y selecciona la cita cuya atención realizó.
2. Solicita marcarla como Atendida.
3. El servidor comprueba que sea el médico responsable y que el estado sea Programada o Confirmada.
4. El sistema guarda Atendida y conserva el registro histórico de la cita.
5. Presenta el estado actualizado sin generar nuevos recordatorios de asistencia.

**Postcondición**

La cita queda Atendida; la consulta clínica permanece vinculada pero su registro se gestiona por separado.

**Excepciones**

Médico ajeno, Recepción o Paciente: denegar. Administrador autorizado por CU-19 conserva ejecutor real distinto del Médico responsable. Cancelada, Inasistencia, Atendida o atención no realizada: rechazar cambio ordinario.

**Notas y verificaciones de la ficha**

Es un permiso fijo del rol Médico, sin habilitación individual. No exige Confirmada previa ni se ejecuta automáticamente a guardar CU-23.


### 4.8. Agenda e historial de citas (`CU-20` a `CU-21`)

<a id="cu-20"></a>
#### CU-20 — Consultar la agenda médica

**Trazabilidad de la ficha:** Alcance: 1.4.3 y 1.4.8. Proceso: PN16. Correspondencia: RF03, RF11. RN10, RN11, RN12, RN14. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 90.

**Propósito**

Consultar las citas de un médico por fecha y estado, mostrando únicamente información autorizada.

**Actores**

Administrador, Recepcionista, Médico

**Iniciador**

Administrador, Recepcionista, Médico

**Precondición**

Existe sesión válida; el Médico solo accede a su propia agenda. Administrador y Recepción consultan conforme a sus funciones administrativas.

**Flujo principal**

1. El usuario abre Agenda y establece médico, fecha y estado según sus permisos.
2. El servidor verifica rol y relación con el médico; para el Médico utiliza su propia identidad.
3. Recupera las citas que cumplen los filtros, conservando los estados y las relaciones históricas.
4. Muestra hora, código, paciente, especialidad, estado, modalidad Presencial y consultorio.
5. Muestra el motivo breve solo si corresponde y el usuario tiene permiso para verlo.
6. El usuario puede cambiar filtros o abrir el detalle permitido; ninguna consulta modifica estados.

**Precisiones aprobadas (2026-10-22):** consulta por día, fecha actual de America/La_Paz si no se indica otra, orden inicio ascendente y filtros Médico/fecha/estado. Administrador/Recepción seleccionan profesional; Médico derivado de su sesión sin elegir otro. Motivo breve únicamente dentro del detalle autorizado, nunca en tablas: Administrador no lo recibe, Recepción solo para cita que pueda gestionar y Médico solo para la propia. Los datos mínimos y adicionales por rol se rigen por VS-HIS-04/05; identificación/contacto no se incorporan indiscriminadamente a listados. No contenido clínico. Profesional inactivo con cuenta válida conserva agenda; la consulta histórica no exige horarios, especialidad habilitada o consultorio fijo actuales. Ubicación de la cita se toma del dato conservado, no de la asignación actual.

**Estado de desarrollo (fase 2, 2026-10-22):** selector mínimo GET /api/agenda/medicos para Admin/Recepción y agenda diaria GET /api/agenda para Admin/Recepción/Médico propio implementados. Request/Controller/Service/Repository/Resources específicos, sesión revalidada y proyección sin motivo/datos adicionales; página/count en una sentencia PostgreSQL, contexto vacío y ubicación conservada. **43 pruebas/433 aserciones dirigidas correctas** con kernel/sesiones reales sobre base aislada; regresión completa en ejecución. Contratos en `backend/README.md`. Detalle autorizado conserva fase 4; no se declara cerrado todo CU-20/módulo, ni se adelanta CU-15–CU-19 o React.

**Postcondición**

Se muestra la agenda solicitada sin conceder acceso a citas o datos no autorizados.

**Excepciones**

Médico ajeno solicitado por un Médico o parámetros inválidos: denegar o solicitar corrección. Sin citas: mostrar un resultado vacío; no tratarlo como error ni crear registros.

**Notas y verificaciones de la ficha**

La reserva, el registro clínico y los cambios de estado requieren sus propios casos de uso y controles.

**Cierre final frontend CU-20 (2026-10-23):** regresión completa **1215 pruebas/64 archivos correctos**, 156,64 s. Sustituye el pendiente de fase 4 que sigue; cuatro fases frontend completadas localmente, build/diff correctos y revisión visual final registrada en §17.113/README/análisis. No nueva integración autenticada o aceptación productiva; diagrama global/PHP mantienen aplazamiento y CU-15–CU-19 siguen pendientes.

**Revisión frontend fase 4 (2026-10-23):** CU-20 contrastado con consultas/detalles de tres roles, selección expresa/propia, fecha de clínica/filtros/paginación/ubicación histórica, ausencia de motivo en lista/Admin y navegación segura. Dirigida acumulada de cierre 237/9 correcta, build/diff correctos; regresión completa final pendiente. Tres arneses Chrome/API ficticia sobre interfaz final: 120 disposiciones/40 axe sin infracciones, cero excepciones/escrituras. Sin nueva integración autenticada Laravel o CU-15–CU-19. README/análisis/contexto §17.113; cierre local final sujeto al resultado global, no aceptación productiva. Diagrama global/PHP aplazados.

**Avance frontend fase 2 (2026-10-22):** /agenda implementa CU-20 para Administrador/Recepción con selección expresa paginada de Médico, y para Médico con agenda propia sin id_medico. Fecha inicial/filtros/orden/contexto/paginación del servidor, listado mínimo sin motivo/contactos/clínica y detalle por matriz de fase 1. Criterios solo en memoria durante ida/retorno, filas reconsultadas; inactividad no impide consultar historia autorizada. Sidebar/Inicio activan agenda, historial/Mis citas siguen pendientes. 26 pruebas nuevas, global **1156/63 correcta**, build/diff correctos; Chrome/API ficticia **50 disposiciones/16 axe sin infracciones**. No integración autenticada Laravel nueva ni CU-15–CU-19. README/análisis/contexto §17.111; cierre integral frontend fase 4 pendiente y diagrama global/PHP aplazados.

**Cierre backend fase 6 (2026-10-22):** reserva real de Recepción/Paciente enlazada con selector/agenda/detalle por los cuatro roles, matriz efectiva y coherencia de intervalo/duración/ubicación/estado. Logout/401/nuevo login, lectura con inactividad y ubicación actual distinta sin pérdida histórica; sin acciones CU-15–CU-19. AgendaIntegracionTest: tres casos/394 aserciones correctas (compartidos con CU-21). Regresión completa final **1612 pruebas/19176 aserciones correctas**, 12 min 20,196 s/200 MB. Backend CU-20 cerrado localmente; frontend no iniciado ni producción acreditada. Contratos vigentes/matriz de aceptación en README, contexto §17.109/análisis; diagrama global/PHP aplazados.

**Refuerzo fase 5 (2026-10-22):** seguridad/concurrencia de selector, agenda y detalle propios verificadas con procesos HTTP/PostgreSQL independientes: revocación confirmada durante espera del actor da 401 sin datos; rollback conserva lectura autorizada. Consultas privadas, fechas civiles/extremos/zona de conexión/errores seguros y ausencia de escrituras de dominio comprobadas; sin cambio funcional de CU-20. Conjunto del módulo 229 pruebas/2421 aserciones correctas (101 casos nuevos); regresión completa **1609 pruebas/18782 aserciones correctas**, 10 min 44,239 s/198 MB, contexto §17.108/README. Fase 5 cerrada localmente; integración/cierre mantiene fase 6 no iniciada.

**Avance posterior fase 4 (2026-10-22):** GET /api/citas/{id} implementa el detalle de agenda con matriz por rol. Administrador no selecciona/devuelve motivo; Recepción recibe motivo/CI/teléfono; Médico solo citas propias y motivo/nacimiento para identificación. No contenido clínico o contacto personal del Médico, ni datos adicionales en listados. Scope en SQL/404 uniforme y sesión revalidada; 37 pruebas nuevas de detalle y regresión dirigida **257/2753 correcta**, contexto §17.107/README. Completa consulta/detalle de CU-20 a nivel implementación, pero refuerzo y cierre integral conservan fases 5/6; no React ni CU-15–CU-19 adelantados.

<a id="cu-21"></a>
#### CU-21 — Consultar el historial y seguimiento de citas

**Trazabilidad de la ficha:** Alcance: 1.4.2 y 1.4.8. Proceso: PN16. Correspondencia: RF02, RF12. RN01, RN10, RN11, RN12, RN14. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 91.

**Propósito**

Recuperar las citas de un paciente y seguir su situación sin perder las pasadas o canceladas.

**Actores**

Administrador, Recepcionista, Paciente

**Iniciador**

Administrador, Recepcionista, Paciente

**Precondición**

Existe sesión válida; Recepción consulta el paciente localizado y el Paciente solo su registro vinculado.

**Flujo principal**

1. Recepción abre el historial del paciente; el Paciente abre Mis citas.
2. El servidor verifica la autorización y la relación entre cuenta y paciente.
3. Recupera todas las citas asociadas, incluidas las pasadas y canceladas.
4. Muestra fecha y hora, médico, especialidad, estado, fecha de registro, modalidad Presencial y consultorio conservado.
5. El usuario abre un detalle autorizado o consulta las citas pendientes y concluidas según los cinco estados.
6. El sistema mantiene las ubicaciones históricas aunque cambie el consultorio fijo del médico.

**Precisiones aprobadas (2026-10-22):** Paciente inicia en Próximas, cita más cercana primero; Recepción abre desde ficha y muestra inicialmente Todas, orden más recientes primero. Primera entrega con filtros período temporal y estado, más paginación en servidor para recuperar todo el historial. Próximas comprende Programada/Confirmada con fin_at > instante actual del servidor, incluidas en curso; Anteriores comprende fin_at <= ese instante cualquiera sea su estado; Todas no aplica restricción temporal. Canceladas futuras permanecen en Todas y son filtrables por estado. Los criterios no son estados ni una partición exhaustiva; Programada pasada no cambia a Inasistencia/Atendida automáticamente. No filtros iniciales por Médico/especialidad/intervalo de fechas. Ficha inactiva con cuenta válida conserva consulta propia; Recepción puede consultar historial de ficha sin cuenta/CI dentro de sus atribuciones.

Motivo breve solo en detalle administrativo autorizado de Administrador/Recepción o del Paciente titular; nunca en listados. Sin contenido clínico ni contacto personal del Médico. Matriz VS-HIS-04/05: Administración selecciona Paciente y consulta historial reutilizando el flujo administrativo. La ampliación ejecutable/proyecciones sigue pendiente de Fase 4; el README distingue contratos actuales de política aprobada.

**Estado de desarrollo (fase 3, 2026-10-22):** consultas GET /api/mis-citas y /api/pacientes/{id}/citas implementadas para Paciente titular/Recepción respectivamente, con defaults/tiempo/estado/paginación/contexto autorizados, proyección mínima compartida con agenda y sin efectos de dominio. 48 pruebas nuevas; regresión dirigida de siete clases **220/2273 correcta** con kernel/sesiones/PostgreSQL aislados. Resultado global 1423/16794 recibido ahora corresponde a fase 2, no ejecución completa de esta fase. Detalle conserva fase 4; refuerzo/integración fases 5/6 pendientes, sin declarar módulo/RF12 integral terminado. CU-15–CU-19 se posponen, no se eliminan del alcance obligatorio.

**Postcondición**

El historial queda disponible en modo consulta sin alterar citas ni registros clínicos.

**Excepciones**

Paciente ajeno solicitado por una cuenta Paciente: denegar sin exponer datos. Paciente sin citas: mostrar el historial vacío; conservar su registro personal.

**Notas y verificaciones de la ficha**

El Paciente no reprograma ni cancela desde Mis citas. El historial administrativo no reemplaza la historia clínica.

**Cierre final frontend CU-21 (2026-10-23):** regresión completa **1215 pruebas/64 archivos correctos**, 156,64 s. Sustituye el pendiente de fase 4 que sigue; cuatro fases frontend completadas localmente, build/diff correctos y revisión visual final registrada en §17.113/README/análisis. No nueva integración autenticada o aceptación productiva; diagrama global/PHP mantienen aplazamiento y CU-15–CU-19 siguen pendientes.

**Revisión frontend fase 4 (2026-10-23):** CU-21 contrastado en recorridos de ambos actores y detalles directos/atrás-adelante/retorno, selección-ficha, clasificación del servidor/estados independientes/ubicación/paginación/contexto vacío. Nueve pruebas nuevas de integración/seguridad más seis contextos de navegación, dirigida 237/9 correcta, build/diff correctos; regresión final pendiente. Chrome/API ficticia agregado final 120 disposiciones/40 axe sin infracciones; no nueva integración autenticada Laravel/producción. README/análisis/contexto §17.113; cierre local final sujeto al resultado global, CU-15–CU-19 pendientes y diagrama global/PHP aplazados.

**Cierre de verificación frontend fase 3:** suite completa final **1200 pruebas/64 archivos correctos**, 151,94 s (`pnpm test --maxWorkers=2`). Sustituye el pendiente de regresión del avance de fase 3 que sigue. Fase 3 cerrada localmente; revisión integral/cierre frontend de fase 4 no iniciados. Contexto §17.112/README/análisis sincronizados; backend y reglas aprobadas intactos.

**Avance frontend fase 3 (2026-10-22):** Mis citas propia y historial administrativo desde ficha implementados, con defaults Próximas/Todas, período/estado separados, orden/paginación/contexto del servidor y detalle por matriz. Ficha inactiva o sin CI/cuenta conserva acceso administrativo; Paciente no depende de perfil/actividad para consultar. Ruta de selección de Recepción reutiliza búsqueda/ficha existentes sin nuevo endpoint o historial global ni duplicar enlace de Gestionar pacientes. Retorno conserva solo criterios en memoria/reconsulta, otra ficha/identidad descarta previos. Dirigida 211/8 correcta, Chrome/API ficticia 34 disposiciones/16 axe sin infracciones, build/diff correctos; regresión completa pendiente de resultado. README/análisis/contexto §17.112; fase 4/cierre frontend integral pendientes, sin CU-15–CU-19 o aceptación productiva; diagrama global/PHP aplazados.

**Cierre backend fase 6 (2026-10-22):** reserva real aparece en Mis citas propia y en historial de Recepción; aislamiento ajeno/404 indistinguible, logout/401/nuevo login, fin=t cambia período sin mutar Programada, inactividad/ubicación histórica y navegación por links.next preservando filtros verificadas. Tres casos/394 aserciones correctas compartidos con CU-20; regresión completa final **1612 pruebas/19176 aserciones correctas**, 12 min 20,196 s/200 MB. Backend CU-21 cerrado localmente; frontend separado no iniciado, sin transición/acción CU-15–CU-19 ni aceptación productiva. Contexto §17.109/README/análisis, diagrama global/PHP aplazados.

**Refuerzo fase 5 (2026-10-22):** Mis citas/historial/detalle mantienen titularidad/revalidación ante estado/rol/sesión/hash/vínculo cambiados mientras esperan al actor, con commit/rollback reales entre procesos. Filtros/errores seguros, ausencia de escrituras y snapshot de página vs siguiente petición comprobados. Sin cambios de reglas/estados o acciones CU-15–CU-19; 101 casos nuevos y módulo dirigido 229/2421 correcto, regresión completa **1609 pruebas/18782 aserciones correctas**, 10 min 44,239 s/198 MB. Contexto §17.108/README; fase 5 cerrada localmente e integración/cierre conserva fase 6 no iniciada.

**Avance posterior fase 4 (2026-10-22):** detalle compartido GET /api/citas/{id} implementado para historial, con ficha propia desde sesión de Paciente o lectura administrativa autorizada de Recepción. Paciente recibe motivo propio sin CI/teléfono/nacimiento; Recepción motivo/CI (null si falta)/teléfono. Scope de titularidad antes de leer; cita ajena/inexistente mismo 404, listados mínimos intactos y sin mutaciones. 37 pruebas nuevas de detalle, regresión dirigida **257/2753 correcta**; integración/refuerzo/cierre mantienen fases 5/6 pendientes, sin declarar terminado todo el módulo ni RF12 integral. Contexto §17.107/README backend.


### 4.9. Historia clínica y consultas médicas (`CU-22` a `CU-23`)

<a id="cu-22"></a>
#### CU-22 — Consultar la historia clínica autorizada

**Incremento frontend Fase 5 (2026-10-29):** aislamiento de época en GET y cadena perfil propio→historia, retirada inmediata/abort/secuencia ante cambio de sesión/pagehide, pausa sin reconsulta automática y datos no retenidos tras logout fallido. Selección administrativa también se retira; retorno BFCache/contexto manual fresco. HTTP seguros y espera Retry-After numérica/fecha HTTP, sin modificar titularidad/relación asistencial ni conceder permisos en React. Matriz y evidencia simulada en frontend/README/análisis `cu22-cu23-frontend-fase5`/contexto §17.169; integración real Fase 6 pendiente.

**Cierre local frontend Fase 5:** dirigida 313/313 y regresión global final nueva 2954/2954 pruebas correctas (100 archivos); build/controles de alcance/históricos correctos y trece vistas Chrome con API simulada. No integración real Laravel/PostgreSQL ni cierre del módulo frontend; Fase 6 no iniciada.

**Cierre posterior frontend Fase 6 (2026-10-29):** lectura clínica integrada realmente con Chrome/React/Laravel/Sanctum/PostgreSQL aislados: historia vacía/vigente, históricos/paginación internos, Paciente solo propia incluso incompleta sin autoría/motivos/versiones internos, recurso ajeno 404 y Recepción 403 conservando sesión. Revocación real retira clínica. Recorrido conjunto CU-22/CU-23: 15 comprobaciones, 70 disposiciones/28 axe sin infracciones automáticas, suite frontend nueva 2954/100 y backend dirigida 25/3842 correctas, build correcto. Contexto §17.170/README/análisis/UML acumulativo §16; inventario PostgreSQL conservado y entorno propio retirado. **Seis fases frontend cerradas localmente**, no producción ni laboratorio completo.

**Incremento frontend Fase 2 (2026-10-29):** lectura implementada en React con historia, detalle y versiones internas paginadas sobre cuatro GET existentes; entradas por rol, selección administrativa reutilizada, Médico desde agenda propia y Paciente titular resuelto por perfil. Paciente solo vigente incluso incompleta, sin pedir históricos ni conservar metadatos internos; Recepción excluida. Estados de sesión/carga/vacío/error, proyección/validación, abort/obsolescencia/BFCache/Retry-After y CSS local, sin escrituras CU-23/CU-19 ni archivos de laboratorio. Alcance/verificación en frontend/README, análisis `cu22-frontend-fase2` y contexto §17.166. Integración real y cierre del módulo frontend pendientes; notas backend anteriores históricas.

**Cierre local frontend Fase 2:** dirigida 125/125 y regresión global final nueva 2775/2775 pruebas correctas (91 archivos); build y controles documentales/de alcance correctos, nueve vistas Chrome con API simulada sin overflow. No integración real Laravel/PostgreSQL ni cierre de CU-23; Fase 3 no iniciada.

**Fase 6 y backend cerrados localmente (2026-10-29):** integración clínica 25/3842 y suite global final nueva **3828 pruebas/55111 aserciones correctas**, 27 min 12,393 s/498 MB. Lectura propia/vigente y versiones internas integradas con sesión/CSRF/PostgreSQL; UML acumulativo actualizado/fuentes históricas conservadas, inventario pre/post y controles finales correctos, §17.164. No nuevos permisos/RF/RN ni frontend/producción. Notas de fases anteriores siguientes históricas.

**Cierre local Fase 5 (2026-10-29):** regresión ampliada 2597 pruebas/37392 aserciones correcta, incluidos 226 casos clínicos, inventario/controles conservados; no suite global ni cambio funcional. §17.163. Las notas de ejecución previas siguientes mantienen su alcance histórico; Fase 6 autorizada después.

**Verificación backend Fase 5 (2026-10-29), en curso:** procesos independientes prueban lecturas de cuatro vistas tras commit/rollback clínico, proyección del Paciente y revocaciones posteriores a middleware bajo lock PostgreSQL real para todos sus roles. Sin nuevos permisos/estados/rutas/reglas ni auditoría clínica expuesta. Resultados/controles/límites en contexto §17.163/README/análisis; no cierre integral/Fase 6/UI/producción.

**Fase 2 backend (2026-10-28):** cuatro GET/HEAD implementan historia, detalle de consulta y versiones protegidas por ámbito clínico. Administrador/Médico relacionado/Paciente titular; versiones históricas solo internos, Recepción excluida. Paciente vigente incompleto sin motivos/autor/historial, Médico con cita válida incluso futura/inactivo profesional, consulta de otro profesional del mismo Paciente permitida sin escritura. Revalidación/SQL por Paciente/Resources mínimos, paginación, no-store/HEAD, vacíos y errores seguros; GET no cambia clínica/citas/avisos/bitácora. Estudios solo metadatos con publicación coherente, no archivos ni CU-27. Detalle paginado por estudios; contexto editable P50 pendiente, POST/PATCH 405. Dirigida 656/3449 y regresión ampliada final **1117 pruebas/8966 aserciones correctas**, 3 min 22,081 s/132 MB, §17.160/README/análisis; no suite global ni nueva carrera multiproceso. Modelos/migraciones intactos; cierre integral Fases 3–6/frontend/producción pendientes. Las notas previas siguientes conservan su alcance histórico.

**Fase 1 backend documental (2026-10-28):** decisiones confirmadas: Paciente solo versiones vigentes propias, incluso consultas guardadas incompletas, sin historial/motivos de corrección/auditoría interna; no publicación/borradores nuevos. Médico con cita propia Programada/Confirmada/Atendida consulta historia previa del Paciente incluso antes del inicio, sin caducidad adicional; Cancelada/Inasistencia solas no habilitan. Puede leer atenciones pertinentes de otros profesionales del mismo Paciente, no modificarlas. Administrador por CU-22, Recepción excluida. Historia/detalle y versiones protegidas paginadas con ámbitos/proyecciones diferenciados, originales de edición nunca al Paciente. Laboratorio conserva publicación propia y descarga CU-27, sin simular servicios futuros. Contratos previstos en backend/README, VS-CLI-09/10, análisis P49/P50/contexto §17.159; sin nuevas rutas/Services/SQL ni CU terminado. Fases 2–6/frontend/producción pendientes.

**Trazabilidad de la ficha:** Alcance: 1.4.9. Proceso: PN17. Correspondencia: Perfil del Paciente y decisiones clínicas del proyecto. RN12, RN14 Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 92.

**Propósito**

Acceder a los antecedentes y atenciones pertinentes del paciente conforme a la relación de atención y la titularidad.

**Actores**

Administrador, Médico, Paciente

**Iniciador**

Administrador, Médico, Paciente

**Precondición**

Existe sesión válida; el Médico accede a pacientes que le corresponden y el Paciente a su propia información autorizada.

**Flujo principal**

1. El Médico abre la historia del paciente atendido; el Paciente abre su sección de salud.
2. El servidor comprueba rol y relación con el paciente.
3. El sistema recupera tipo de sangre, alergias, enfermedades crónicas, medicamentos y antecedentes relevantes y familiares.
4. Presenta consultas, estudios y resultados publicados que el usuario tenga autorización para consultar.
5. Si se solicita un documento de resultado, aplica nuevamente los controles de CU-27.

**Postcondición**

Se muestra información clínica autorizada en modo consulta, sin modificar antecedentes, consultas, historial administrativo ni resultados.

**Excepciones**

Paciente ajeno o relación clínica no autorizada: denegar el acceso. Sin atenciones registradas: mostrar la información disponible sin inventar antecedentes. Documento sin publicación: no habilitarlo al Paciente.

**Notas y verificaciones de la ficha**

Recepción no obtiene acceso clínico por gestionar reservas. Administrador accede por autorización expresa de CU-22, no por gestionar cuentas. CU-22 no edita antecedentes; su mantenimiento corresponde a Administrador o Médico responsable por CU-23 durante atención real/autorizada, con ejecutor separado del responsable. Paciente solo consulta información propia permitida.

<a id="cu-23"></a>
#### CU-23 — Registrar o actualizar una consulta médica

**Incremento frontend Fase 5 (2026-10-29):** contexto/CSRF/POST/PATCH ligados a una misma época local, rechazo antes del adaptador si cambia, retirada de borrador/revisión/resultado y descarte de respuesta tardía sin afectar nueva sesión por 401 anterior. Incierto permanece sin replay y solo GET; cabecera de espera numérica/fecha HTTP, matriz de errores de ambos escritores y snapshots de antecedentes de alta más estrictos. No cambia reglas de atención/originales/versiones/autoría, no acredita permisos por metadatos cliente ni altera estado de Cita. Evidencia/alcance en frontend/README/análisis/contexto §17.169; Fase 6/producción no iniciadas.

**Cierre local frontend Fase 5:** dirigida 313/313 y regresión global final nueva 2954/2954 pruebas correctas (100 archivos); cuatro reproducciones iniciales corregidas, build/controles correctos, evidencia Chrome con API simulada. No integración real ni cierre del módulo frontend; Fase 6 no iniciada.

**Cierre posterior frontend Fase 6 (2026-10-29):** altas Administrador/Médico y correcciones compartidas integradas con Laravel/PostgreSQL reales aislados; 409 de antecedentes cambiados desde otra cita exige contexto nuevo, no-op no crea versiones/eventos y dos cortes tras commit se recuperan solo GET sin duplicación. CU-19 se ejecuta aparte, la documentación posterior conserva Atendida. Persistencia final 4 citas/3 consultas/5 versiones de consulta/4 de antecedentes/9 eventos clínicos/1 cambio de asistencia, snapshots iniciales intactos y ejecutor real separado del Médico. Recorrido conjunto 15 comprobaciones, 70 disposiciones/28 axe sin infracciones automáticas, suite frontend nueva 2954/100 y backend dirigida 25/3842 correctas, build correcto. Contexto §17.170/README/análisis/UML §16, sin cambios productivos/reglas/persistencia, entorno propio retirado e inventario conservado. **Seis fases frontend CU-22/CU-23 cerradas localmente**, no producción ni inicio automático de otro módulo.

**Incremento frontend Fase 4 (2026-10-29):** corrección parcial de la misma consulta y antecedentes por Administrador/Médico responsable, reutilizando editor/ruta/GET fresco, acciones autorizadas y declaración. Vigentes primero, acciones conservar/modificar/quitar explícitas, motivo ante cambio efectivo y revisión antes/después; PATCH existente con originales íntegros. No-op válido sin motivo/versiones nuevas solo tras respuesta del servidor; obsoleto rechaza aun con contenido igual. Conflicto por antecedentes compartidos exige contexto/revisión nuevos sin rebase/replay; incierto solo GET sin éxito/autoría/rollback inferidos. No cambia Cita/CU-19 ni permisos, no edición autónoma de antecedentes ni consulta ficticia. Paciente solo vigente/Recepción sin clínica y versiones internas de solo lectura. Alcance/evidencia en frontend/README, análisis `cu23-frontend-fase4` y contexto §17.168; Fases 5–6/cierre integral/producción pendientes. Notas de Fase 3 y backend siguientes conservan estado histórico.

**Cierre local frontend Fase 4:** dirigida 242/242 y regresión global final nueva 2892/2892 pruebas correctas (96 archivos); build/controles documentales y de alcance correctos, doce vistas Chrome con API simulada sin overflow. No integración real ni nueva prueba PostgreSQL; módulo completo y Fases 5–6 pendientes. Fase 5 no iniciada.

**Incremento frontend Fase 3 (2026-10-29):** alta implementada por Administrador/Médico responsable desde cita existente y contexto GET fresco, formulario/revisión/declaración de atención real y POST único con originales exactos. Antecedentes vigentes primero, conservar/modificar/quitar explícitos dentro de la misma atención; servidor decide elegibilidad y autoría. No cambio de estado/CU-19 ni corrección de consulta existente. 422 permite corregir/revisar/declarar otra vez; conflicto requiere nuevo contexto, incierto solo GET sin replay ni autoría/rollback inferidos. Retirada por navegación/identidad/BFCache y sin persistencia clínica. Lectura de Fase 2 conservada. Evidencia/alcance en frontend/README, análisis `cu23-frontend-fase3` y contexto §17.167; Fases 4–6/frontend completo/producción pendientes, no iniciarlas automáticamente.

**Cierre local frontend Fase 3:** dirigida 187/187 y regresión global final nueva 2837/2837 pruebas correctas (94 archivos); build y controles de alcance/documentación correctos, once vistas Chrome con API simulada sin overflow. No integración real Laravel/PostgreSQL ni cierre de correcciones/CU-23 completo; Fase 4 no iniciada.

**Fase 6 y backend cerrados localmente (2026-10-29):** integración clínica 25/3842 y suite global final nueva **3828 pruebas/55111 aserciones correctas**, 27 min 12,393 s/498 MB. Alta/correcciones/antecedentes/motivos/no-op/originales y auditoría por APIs reales; Médico inactivo profesional con cuenta activa conserva asignación, CU-19 separado, reactivación de cuenta exige nuevo login. UML acumulativo actualizado/fuentes históricas preservadas, inventario pre/post y controles finales correctos, §17.164. No nuevo esquema/frontend/producción. Notas de fases anteriores siguientes históricas.

**Cierre local Fase 5 (2026-10-29):** regresión ampliada 2597 pruebas/37392 aserciones correcta, incluidos 226 casos clínicos, inventario/controles conservados; no suite global ni cambio funcional. §17.163. Las notas de ejecución previas siguientes mantienen su alcance histórico; Fase 6 autorizada después.

**Verificación backend Fase 5 (2026-10-29), en curso:** altas y correcciones simultáneas/originales/no-op/antecedentes desde citas de distintos Médicos del mismo Paciente, commit/rollback reales, revocación bajo espera, cruces reales con asistencia/gestión/duración y reloj fresco. SQL de cambio de relación es fixture de seguridad, no nueva API de reasignación. Mismas reglas/contratos/producto; resultados/controles/límites en contexto §17.163/README/análisis. Fase 6/cierre integral/UI/producción pendientes.

**Fase 4 backend (2026-10-28), cerrada localmente:** PATCH de la misma consulta permite cambios parciales de consulta/antecedentes, solo Administrador/Médico responsable; cinco originales/declaración true/recurso/estado/atención revalidados antes de comparar. Cambio efectivo exige motivo y snapshot completo nuevo de cada bloque cambiado; no-op válido no crea versiones ni eventos y obsoleto siempre 409, incluso con contenido idéntico. Conserva versiones anteriores, misma consulta/Paciente/Médico/cita y ejecutor real, no modifica Cita ni avisos/CU-19. GET ofrece elegibilidad actualizar solo a operadores propios; Paciente ve vigente corregida sin motivos/autor/historial/edición. Dirigida inicial 72/894 y regresión ampliada final 1628 pruebas/14590 aserciones correctas, incluidos los 83 casos finales de corrección, no suite global; controles/inventario conservado en contexto §17.162/README/análisis P52 efectivo. No nuevas reglas/esquema/frontend; Fases 5–6/producción pendientes. Las notas previas siguientes son históricas.

**Fase 3 backend (2026-10-28), cerrada localmente:** POST `/api/citas/{id}/consulta` habilita alta solo por Administrador o Médico responsable, con originales explícitos vigentes, declaración true y ahora >= inicio_at; Programada/Confirmada/Atendida, nunca Cancelada/Inasistencia. GET existente agrega contexto de registro solo a esos operadores; no al Paciente ni al Médico meramente relacionado por otra cita. Consulta única/versión clínica 1/antecedentes aportados efectivos/eventos seguros atómicos, iniciada_en desconocida NULL y autor Usuario auténtico. No modifica Cita/avisos ni marca Atendida; CU-19 independiente. Primeros antecedentes permitidos dentro del alta según diseño, sin mantenimiento posterior ni PATCH de Fase 4. Dirigida nueva 101 pruebas/777 aserciones y regresión ampliada 1537/13564 correctas (no suite global); controles/inventario conservado/cierre de fase en contexto §17.161/README/análisis P50/P51 efectivos. CU-23 completo/Fases 4–6/frontend/producción siguen pendientes. Las notas documentales siguientes conservan su alcance histórico.

**Fase 1 backend documental (2026-10-28):** alta explícita desde inicio vigente y declaración estricta de atención real iniciada/realizada; no exige Confirmada/fin/mismo día ni cambia Cita a Atendida. Administrador/Médico responsable de la cita, autor Usuario real distinto del profesional responsable; Paciente/Recepción no escriben. Primera consulta/versión y antecedentes aportados atómicos; corregir reutiliza consulta y anexa snapshots solo de bloques cambiados. Cambio efectivo posterior al alta exige motivo; alta sin motivo de corrección. No-op válido sin versión/evento después de validar permisos/estado/declaración/originales; obsoleto siempre 409. Iniciada_en no se inventa. Antecedentes coordinados por Paciente y consulta de origen real, incluso correcciones desde distintas citas. Contratos/errores/protocolo previstos en backend/README, VS-CLI-11/12, análisis P50–P52/contexto §17.159; sin nuevas APIs/SQL ni CU terminado, Fases 2–6/frontend/producción pendientes. Los puntos 5/7 y la postcondición que siguen describen al Médico habitual; la autorización administrativa vigente de esta nota y VS-CLI-08 conserva ejecutor auténtico sin exclusión del Administrador.

**Trazabilidad de la ficha:** Alcance: 1.4.9. Proceso: PN18. Correspondencia: Perfil del Paciente y decisiones clínicas del proyecto. RN12, RN14 Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 93.

**Propósito**

Documentar la atención realizada por el Médico responsable y registrar, completar o corregir los antecedentes clínicos obtenidos durante esa atención autorizada, con un máximo de una consulta por cita.

**Actores**

Administrador, Médico

**Iniciador**

Administrador, Médico

**Precondición**

El Médico responsable tiene cuenta activa y sesión válida y documenta una atención real de su cita Programada, Confirmada o Atendida. El servidor valida rol y relación con la cita y el Paciente. No se crean consultas para Cancelada o Inasistencia ni consultas ficticias para justificar modificaciones de antecedentes.

**Flujo principal**

1. El Médico abre una cita que le corresponde; el servidor verifica cuenta, sesión, rol, relación con el Paciente y consulta existente.
2. Si existe, muestra el mismo registro para completarlo o corregirlo.
3. El Médico consulta los antecedentes existentes: tipo de sangre, alergias, enfermedades crónicas, medicamentos y antecedentes personales relevantes o familiares.
4. Registra fecha, tipo control o enfermedad, motivo, síntomas, observaciones, diagnóstico e indicaciones cuando correspondan; durante la atención real y autorizada puede registrar nueva información, completar o corregir los antecedentes obtenidos.
5. El servidor valida modificaciones permitidas, datos y relaciones, y el máximo de una consulta por cita, incluso con solicitudes simultáneas. Solo el Médico responsable puede modificar los antecedentes.
6. Guarda sin cambiar el estado de la cita. Las modificaciones clínicas relevantes de consultas o antecedentes conservan versión anterior y nueva, autor, fecha y motivo cuando corresponda en almacenamiento clínico protegido.
7. La bitácora general registra operación, responsable, resultado y referencias y metadatos seguros, sin antecedentes, diagnósticos, tratamientos ni otro contenido médico sensible.
8. Permite varios estudios por CU-24; CU-19 marca Atendida por separado.

**Postcondición**

Existe una única consulta por cita y los antecedentes registrados, completados o corregidos por el Médico responsable conservan trazabilidad y versionado clínico de las modificaciones relevantes. No se modifica automáticamente el estado de la reserva ni se duplica contenido médico en Bitácora.

**Excepciones**

Cita Cancelada/Inasistencia, atención ficticia/no realizada, recurso no autorizado, cuenta desactivada o datos inválidos: rechazar. Administrador o Médico responsable pueden modificar antecedentes/consulta por CU-23; Recepción/Paciente no, incluso por petición directa. No asignar al Médico autoría del Administrador. Segunda consulta simultánea: rechazar y abrir existente. Médico inactivo con cuenta activa conserva atenciones asignadas, sin nuevas citas. Fallo no confirma consulta/antecedentes/versiones parciales; preservar versiones protegidas.


### 4.10. Laboratorio digital (`CU-24` a `CU-27`)

**Estado frontend vigente de estas cuatro fichas:** cierre local Fase 6/§17.182. CU-24 permite solicitud desde consulta existente con declaración/versión; CU-25 carga/corrige documentos externos y reasocia con historia/identidad de ambos extremos; CU-26 publica directamente o devuelve con motivo exigiendo corrección y revisión nueva; CU-27 lista/descarga solo versión autorizada por ámbito, Paciente propia publicada. Revisión humana no se sustituye por una descarga ni concede acceso por vínculo de versión. Backend P53–P59 y frontend integrados localmente sin reglas nuevas, sin modificar Cita/consulta/avisos; solicitudes iguales no se deduplican por nombre. Los cierres backend/fases previas siguientes conservan su evidencia histórica; producción/operación habitual pendientes, no interpretación literal de antiguos «sin flujo funcional» como estado actual.

**Fase 1 backend diseñada (2026-10-29), no módulo funcional:** seis fases independientes acordadas: diseño/contratos → solicitudes → documentos/correcciones → revisión/publicación → consulta/descarga/seguridad → integración/regresión/UML/cierre. Contratos previstos, payloads, proyecciones, errores, archivos/transacciones y matriz de pruebas en backend README/contexto §17.171/análisis `laboratorio-backend-fase1`. Las fichas históricas siguientes se mantienen; precondiciones redactadas en términos del Médico se aplican también al Administrador autorizado, conservando usuario ejecutor y Médico responsable distintos. Recepción sin acceso clínico; no nuevo permiso de otro rol ni funcionalidad acreditada por Models.

**Concreciones aprobadas por el usuario en esta fase:** CU-24 admite consulta de atención iniciada/realizada desde inicio y Cita Programada/Confirmada/Atendida, incluida documentación posterior y continuidad con Médico profesionalmente inactivo/cuenta activa. CU-25 admite un PDF/JPEG/PNG hasta 10 MiB por versión; reasociar solo desde vigente devuelta En revisión a solicitud Pendiente sin documento/versiones, incluso de otro Paciente tras verificación explícita de ambas asociaciones. Origen queda En revisión sin vigente, conserva historia y admite nueva carga; destino exige nueva revisión por su responsable/Admin. No modificar Disponible. VS-LAB-11–13 no son RN oficiales ni implementación; CU-26 requiere corregir primero una versión devuelta, sin publicar esa misma versión; CU-27 revalida al entregar bytes, sin URL pública.

<a id="cu-24"></a>
#### CU-24 — Solicitar estudios de laboratorio

**Estado vigente de cierre backend:** Fase 6 y módulo CU-24–CU-27 cerrados localmente, global única **4088 pruebas/58 622 aserciones correctas**, código 0, 27 min 10,105 s/580 MB; PostgreSQL intacto/sin residuos propios. Las notas siguientes en curso son historia superada por contexto §17.176/README/análisis F6. No frontend/producción ni migración habitual/remota acreditados.

**Integración backend Fase 6 (2026-10-30), global final en curso:** solicita estudios por API desde consulta real creada por CU-23, conservando ejecutor/atención, incluso después de Atendida e inactividad profesional con cuenta válida. Forma parte del recorrido CU-24–CU-27 de **7 pruebas/784 aserciones correctas** con archivos ficticios/SQL privado. Cierre conjunto pendiente de global/inventario, contexto §17.176/README/análisis F6; no frontend/producción ni ampliación de permisos.

**Cierre final Fase 2 backend:** regresión global **3937 pruebas/56 226 aserciones correctas**, 28 min 57,640 s/534 MB con límite CLI 768M; inventario PostgreSQL final conservado, controles de alcance/documentación/calidad correctos. Sustituye pendientes de verificación, no la ficha funcional ni sus reglas. Contexto §17.172/README/análisis P53–P54. Fase 2 cerrada localmente; Fases 3–6 del módulo, frontend y producción pendientes, sin inicio automático.

**Backend Fase 2 implementado localmente (2026-10-30):** POST /api/consultas/{id}/solicitudes-laboratorio para Admin/Médico responsable, exige original de versión de consulta/atención real true, desde inicio y Cita Programada/Confirmada/Atendida; conserva atención anterior con profesional/ficha inactivos y cuenta válida. Crea Pendiente/creada_por Usuario real y evento seguro atómico sin cambiar Cita/clínica/avisos; varias solicitudes del mismo estudio permitidas sin idempotencia ficticia. GET por consulta/lista/detalle solo ámbito Admin/Médico responsable/Paciente titular, HEAD sin bytes y contexto de alta exclusivo interno. Migración 000036 aditiva, legado sin autor desconocido; backend README/contexto §17.172/análisis P53/P54/DER §6.23 contienen verificación/contratos. No carga, revisión, descarga, UI ni cierre del módulo por esta fase.

**Trazabilidad de la ficha:** Alcance: 1.4.9 y 1.4.10. Proceso: PN19. Correspondencia: Perfil del Paciente y decisiones clínicas del proyecto. RN12 RN14. Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 94.

**Propósito**

Registrar los estudios requeridos para una atención y relacionarlos con su consulta clínica.

**Actores**

Administrador, Médico

**Iniciador**

Administrador, Médico

**Precondición**

El Médico responsable inició sesión y existe la consulta médica a la que pertenecen los estudios.

**Flujo principal**

1. El Médico abre la consulta y selecciona Solicitar estudio.
2. Indica el estudio requerido y confirma la solicitud.
3. El servidor valida médico, paciente y consulta de origen.
4. Guarda la solicitud con estudio, fecha, paciente, consulta y Médico solicitante en estado interno Pendiente.
5. Si se requieren otros estudios, el Médico repite la operación y el sistema conserva todas las solicitudes de esa consulta.
6. El resultado emitido externamente se incorporará mediante CU-25.

**Postcondición**

Cada solicitud queda asociada a la atención correspondiente; una consulta puede tener varias solicitudes.

**Excepciones**

Consulta inexistente o ajena al Médico: rechazar la solicitud. Estudio no indicado o datos inválidos: solicitar corrección sin guardar una solicitud incompleta.

**Notas y verificaciones de la ficha**

El sistema no analiza muestras ni reemplaza al laboratorio emisor; sus estados internos no son estados de cita.

<a id="cu-25"></a>
#### CU-25 — Cargar o corregir documentos de laboratorio

**Estado vigente de cierre backend:** Fase 6 y módulo CU-24–CU-27 cerrados localmente, global única **4088 pruebas/58 622 aserciones correctas**, código 0, 27 min 10,105 s/580 MB; PostgreSQL intacto/sin residuos propios. Las notas siguientes en curso son historia superada por contexto §17.176/README/análisis F6. No frontend/producción ni migración habitual/remota acreditados.

**Integración backend Fase 6 (2026-10-30), global final en curso:** cargas PDF/JPEG/PNG reales, devolución/sustitución y reasociación entre dos fichas/Médicos con recarga max+1 de origen; versiones/bytes/relaciones anteriores conservados y destino con revisión independiente, todo por APIs. **7 pruebas/784 aserciones** del recorrido conjunto correctas; cierre definitivo global pendiente en contexto §17.176/README/análisis F6. No nueva regla, esquema o frontend.

**Estado vigente de implementación: Fase 3 backend cerrada localmente.** Regresión global ya terminada con código 0: **4002 pruebas/56 779 aserciones correctas**, 27 min 23,548 s/550 MB; inventario PostgreSQL intacto y sin raíces temporales propias pendientes. La nota «verificación en curso» siguiente conserva la cronología, superada por este cierre. No implica revisión/publicación CU-26, entrega binaria CU-27, frontend ni cierre integral del módulo; no se inicia la fase siguiente.

**Incremento Fase 3 backend (2026-10-30), verificación en curso:** dos POST administrativos de documentos/correcciones, contexto fresco/originales y versiones inmutables. Fileinfo/GD/qpdf comprueban PDF no cifrado/JPEG/PNG <=10 MiB, no identidad ni ausencia de malware. Sustituir exige devolución/motivo; reasociar a Pendiente incluso otra ficha verificada conserva origen y permite nueva carga numerada, nunca Disponible. Admin/Médico responsable consultan versiones internas sin bytes; motivo/origen cruzados exclusivos de Admin, Paciente/Recepción sin histórico. Almacenamiento privado, auditoría atómica/segura y compensación conservadora de bytes, sin publicación ni descarga HTTP de esta fase. Contratos/pruebas/resultados finales en backend README/contexto §17.173/análisis P55–P57; F4/F5/F6/frontend pendientes.

**Trazabilidad de la ficha:** Alcance: 1.4.10. Proceso: PN20. Correspondencia: Perfil del Paciente y decisiones clínicas del proyecto. RN12, RN14 Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 95.

**Propósito**

Asociar un resultado digital a su solicitud o corregirlo tras devolución médica, conservando su trazabilidad.

**Actores**

Administrador

**Iniciador**

Administrador

**Precondición**

El Administrador inició sesión; existe una solicitud Pendiente o una devolución del Médico en estado En revisión.

**Flujo principal**

1. El Administrador abre la solicitud y revisa paciente, estudio y motivo de devolución si existe.
2. Carga el PDF o imagen legible emitido externamente y verifica la asociación.
3. En una devolución, corrige la asociación o sustituye el documento conforme al motivo registrado.
4. El servidor valida archivo, solicitud y autorización; registra autor, fecha, motivo y datos y documentos anteriores y corregidos.
5. La carga inicial pasa de Pendiente a En revisión; una corrección permanece En revisión.
6. El sistema bloquea el acceso del Paciente y remite el documento a una nueva revisión mediante CU-26.

**Postcondición**

El documento queda protegido y trazable, pendiente de revisión médica; la corrección no lo publica.

**Excepciones**

Archivo ilegible o inválido, asociación incoherente o petición sin permiso: no aceptar la carga. Fallo de persistencia: conservar la documentación previa protegida sin confirmar una corrección parcial.

**Notas y verificaciones de la ficha**

El Administrador no interpreta ni modifica el contenido clínico. El documento incorrecto y sus antecedentes nunca quedan disponibles al Paciente.

<a id="cu-26"></a>
#### CU-26 — Revisar y autorizar la publicación de resultados

**Estado vigente de cierre backend:** Fase 6 y módulo CU-24–CU-27 cerrados localmente, global única **4088 pruebas/58 622 aserciones correctas**, código 0, 27 min 10,105 s/580 MB; PostgreSQL intacto/sin residuos propios. Las notas siguientes en curso son historia superada por contexto §17.176/README/análisis F6. No frontend/producción ni migración habitual/remota acreditados.

**Integración backend Fase 6 (2026-10-30), global final en curso:** publicación directa o devolución/corrección/revisión nueva, con Admin/Médico reales y responsable separado del ejecutor. Circuito conjunto **7 pruebas/784 aserciones correctas**, incluye independencia de revisión tras reasociar; no reautoriza versión devuelta ni modifica Disponible. Cierre global/inventario pendiente en contexto §17.176/README/análisis F6; no frontend/producción.

**Estado vigente: Fase 4 backend cerrada localmente.** Dirigida final **199 pruebas/2108 aserciones correctas**, código 0, 1 min 05,781 s/138 MB, sin suite global por rutina. PostgreSQL intacto tras pruebas y sin raíces temporales propias; migración 000037 solo en tests aislados. La nota siguiente en verificación conserva historia superada; entrega/visualización HTTP, frontend e integración posterior siguen pendientes y no se inicia F5 automáticamente. No cierre de todo laboratorio.

**Incremento implementado Fase 4 backend (2026-10-30), verificación dirigida en curso:** POST compartido de revisiones Admin/Médico solicitante sobre vigente exacta/no revisada; Autorizar publica directamente, Devolver conserva En revisión con motivo y exige corrección/versionado CU-25. Originales/archivo/actor revalidados bajo locks; revisor Usuario real separado de Médico responsable por migración aditiva 000037, sin atribuir legado desconocido. Detalle interno permite revisar al Médico sin permisos administrativos de carga. Paciente no ve motivos/contexto; entrega HTTP/visualización protegida aún F5/frontend. Resultados en README/contexto §17.174/análisis P58; no cierre integral del módulo ni inicio de otra fase.

**Trazabilidad de la ficha:** Alcance: 1.4.10. Proceso: PN21. Correspondencia: Perfil del Paciente y decisiones clínicas del proyecto. RN12, RN14 Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 96.

**Propósito**

Comprobar la correspondencia del resultado y autorizar su publicación o devolverlo para corrección.

**Actores**

Administrador, Médico

**Iniciador**

Administrador, Médico

**Precondición**

El Médico solicitante inició sesión; la solicitud tiene un resultado protegido en estado En revisión.

**Flujo principal**

1. El Médico solicitante abre un resultado En revisión.
2. El servidor verifica acceso y presenta la versión vigente protegida.
3. El Médico comprueba Paciente y estudio; si corresponde, selecciona Autorizar publicación sin devolución previa.
4. El servidor revalida permiso, estado y coincidencia con la versión revisada.
5. Guarda autor y fecha, audita y cambia a Disponible.
6. El Paciente accede por CU-27 únicamente a esa versión autorizada.

**Flujo alternativo**


**RESULTADO INCORRECTO**

1. El Médico devuelve indicando motivo; el sistema conserva En revisión y bloquea el acceso del Paciente.
2. El Administrador corrige asociación o documento por CU-25, sin alterar contenido clínico y conservando versiones anteriores y nuevas.
3. El Médico solicitante vuelve a revisar. Si es correcto, autoriza por el flujo principal; si persiste el error, puede devolverlo otra vez.

**Postcondición**

Disponible exige autorización sobre la versión revisada. La devolución mantiene En revisión y su protección; hasta publicar, el Paciente ve Pendiente.

**Excepciones**

Otro rol, Médico ajeno o sesión inválida: denegar publicación. Documento sustituido, estado modificado o versión no revisada: exigir nueva comprobación. Fallo al guardar: no publicar parcialmente ni informar éxito. Solo se conservan los tres estados internos definidos.

<a id="cu-27"></a>
#### CU-27 — Consultar solicitudes y descargar resultados autorizados

**Estado vigente de cierre backend:** Fase 6 y módulo CU-24–CU-27 cerrados localmente, global única **4088 pruebas/58 622 aserciones correctas**, código 0, 27 min 10,105 s/580 MB; PostgreSQL intacto/sin residuos propios. Las notas siguientes en curso son historia superada por contexto §17.176/README/análisis F6. No frontend/producción ni migración habitual/remota acreditados.

**Integración backend Fase 6 (2026-10-30), global final en curso:** GET/HEAD de bytes reales después de carga/publicación, compatibilidad CU-22/listados/bitácora, ausencia de secretos y acceso cruzado, revocación/reactivación por API sin recuperar cookie anterior y nuevo login sin replay. **7 pruebas/784 aserciones** del recorrido conjunto correctas; módulo no se declara cerrado antes de global/inventario en contexto §17.176/README/análisis F6. Sin navegador/frontend/producción.

**Estado vigente: Fase 5 backend cerrada localmente.** Dirigida final **191 pruebas/2513 aserciones correctas**, código 0, 1 min 09,536 s/142 MB, sin suite global por rutina. Incluye descarga autorizada/GET/HEAD, negativos, integridad y siete carreras SQL reales; PostgreSQL intacto y sin archivos propios residuales. Las notas en verificación siguientes son historia superada. No cierre integral de laboratorio/F6, frontend o producción, ni inicio automático de fase siguiente; evidencia en contexto §17.175/README/P59.

**Incremento Fase 5 backend (2026-10-30), verificación dirigida en curso:** GET/HEAD archivo exige versión exacta canónica y vuelve a comprobar cuenta/sesión/rol/ámbito, vigente última/publicación y bytes privados. Paciente únicamente propia publicada; Admin/Médico solicitante vigente para revisión, nunca historia/origen ajeno por IDs. Attachment sintético/no-store/nosniff, sin rutas públicas ni 206/304; HEAD mismos permisos sin bytes. Legado desconocido no se reatribuye ni se revoca solo por NULL; publicación debe superar comprobaciones de versión/revisión/responsable/integridad. Concurrencia SQL/procesos real, sin navegador/servidor por socket ni frontend, F6 pendiente. Evidencia/límites en contexto §17.175/README/análisis P59.

**Trazabilidad de la ficha:** Alcance: 1.4.9 y 1.4.10. Proceso: PN22. Correspondencia: Perfil del Paciente y decisiones clínicas del proyecto. RN12 RN14. Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 97.

**Propósito**

Consultar el seguimiento de estudios y acceder a documentos de resultado únicamente con autorización.

**Actores**

Administrador, Médico, Paciente

**Iniciador**

Administrador, Médico, Paciente

**Precondición**

Existe sesión válida y relación autorizada con la solicitud; el Paciente solo consulta sus propias solicitudes.

**Flujo principal**

1. El usuario abre las solicitudes desde su información clínica o la sección de laboratorio.
2. El servidor valida sesión, rol y relación con cada solicitud.
3. Para el Paciente muestra Pendiente cuando el estado interno es Pendiente o En revisión, sin enlace al documento.
4. Para un resultado Disponible muestra la información y permite solicitar consulta o descarga.
5. El servidor vuelve a comprobar titularidad, permiso y publicación antes de entregar el archivo protegido.
6. El Médico consulta los resultados que le corresponden; la revisión de archivos En revisión se realiza mediante CU-26.

**Postcondición**

Solo se entrega el documento vigente y autorizado; no se exponen rutas públicas ni antecedentes incorrectos.

**Excepciones**

Archivo pendiente o incorrecto solicitado por el Paciente: denegar la descarga y mantener Pendiente. Solicitud ajena, sesión inválida o dirección manipulada: denegar sin revelar el documento. Archivo no disponible: informar sin exponer rutas, credenciales ni detalles internos.

**Notas y verificaciones de la ficha**

El Paciente no modifica solicitudes ni documentos; la autorización se verifica en cada consulta y descarga.


### 4.11. Recordatorios automáticos (`CU-28` a `CU-28`)

<a id="cu-28"></a>
#### CU-28 — Consultar recordatorios de próximas citas y recibir Web Push opcional

**Incremento backend Fase3 (2026-10-31) cerrado localmente:** generación interna por comando/scheduler cada minuto, 24h/menor anticipación y outbox recuperable para autorizaciones elegibles antes de inicio. Aviso sin cuenta/preferencia explícita, sin modificar Cita/consulta/estados; retiro de obsoletos y un aviso por programación/dispositivo sin replay de terminales.79 pruebas/950 aserciones dirigidas correctas, incluida competencia de dos procesos SQL y cancelación por API real; contexto §17.186/README/análisis recordatorios-backend-fase3. No consulta HTTP de avisos, despacho Web Push/transporte/Service Worker/frontend ni módulo completo. Fuentes anteriores de «scheduler pendiente» conservan cronología superada solo en este alcance.

**Incremento backend Fase2 implementado localmente (2026-10-31):** consentimiento/renovación/revocación de suscripciones propias y configuración/listado mínimo disponibles por cinco rutas; Administración/Médico/Recepción sin gestionar dispositivos ajenos. Cookie de instalación segura y vínculo Usuario/Paciente versionado, logout voluntario/cambio de cuenta/revocación de acceso retiran autorización; expiración natural de sesión no equivale a logout. Datos cifrados, auditoría segura y dos migraciones aditivas solo verificadas en tests aislados.187 pruebas/2861 aserciones dirigidas correctas, contexto §17.185/README/análisis recordatorios-backend-fase2. No generación/consulta de avisos/scheduler/transporte/push real/Service Worker/frontend ni cierre CU-28 por esta fase; pendientes de F1 se resuelven solo en este alcance.

**Actualización de alcance aprobada por el usuario el 2026-10-30:** mantener avisos internos y añadir notificaciones Web Push en dispositivos compatibles autorizados del Paciente, sin app nativa. Sustituye la prohibición previa de push; correo/SMS/WhatsApp/Brevo siguen excluidos para recordatorios. No se garantiza encender la pantalla, sonido, recepción o lectura. Es una **ampliación propia del proyecto**, no texto del PDF ni un nuevo RF/RN/CU. Estado: especificado, **aún no implementado**; contexto §17.183, requerimientos §5.8 y VS-NOT-07–11.

**Fase 1 backend diseñada, no implementada (contexto §17.184):** usuario aprueba revisión cada minuto/minishlink-web-push con VAPID, desvincular ese navegador al logout explícito/cambio de cuenta (nuevo opt-in, otros dispositivos intactos), push solo antes del inicio de cita y ausencia de preferencia como aviso interno habilitado, nunca permiso push. Contratos de seis rutas previstas, persistencia/outbox y matriz de siete fases en README backend; no rutas disponibles ni entrega acreditada. VS-NOT-12–14 concretan estas decisiones; implementación/F2–F7/frontend pendientes.

**Trazabilidad de la ficha:** Alcance: 1.4.11. Proceso: PN23. Correspondencia: Perfil del Paciente y decisiones clínicas del proyecto. RN12, RN14 Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 98.

**Propósito**

Consultar los avisos automáticos de próximas atenciones desde el portal y permitir al Paciente recibirlos además mediante Web Push en sus dispositivos autorizados, aunque el portal esté cerrado cuando la plataforma lo soporte. El aviso interno persistido se conserva como respaldo.

**Actores**

Administrador, Paciente

**Iniciador**

Administrador para consultar avisos del Paciente seleccionado; Paciente para consultar los propios y habilitar/revocar push de sus dispositivos. Generación y despacho automáticos sin sesión activa del Paciente; el consentimiento inicial sí es una acción explícita del titular autenticado. Administrador no habilita dispositivos, modifica preferencias ni envía avisos manualmente.

**Precondición**

Existen Paciente y cita guardada exitosamente y aún vigente, con o sin cuenta web. Generar el aviso interno no requiere sesión ni intervención manual del Paciente o Recepción; consultar exige sesión válida y permiso del titular o del Administrador sobre el Paciente seleccionado. Se respetan preferencias internas y no se generan avisos de próxima atención si el intervalo ya finalizó. **Para push adicional:** cuenta activa vinculada al Paciente, permiso explícito del dispositivo, suscripción vigente y soporte Web Push bajo HTTPS/Service Worker; instalación de la web en pantalla de inicio cuando la plataforma la requiera. No tener estos requisitos no impide el aviso interno autorizado.

**Flujo principal**

1. El sistema calcula y genera automáticamente el aviso 24 horas antes de la fecha y hora programadas de la cita, según la zona horaria local de la clínica.
2. Una reserva registrada exitosamente con menos de 24 horas de anticipación genera el aviso después de guardarse correctamente en la base de datos o en la siguiente ejecución del programador de tareas, siempre que el recordatorio siga siendo válido conforme a las condiciones establecidas y el intervalo de atención no haya finalizado. La frecuencia específica del programador se definirá durante el diseño técnico. La generación no depende del estado Confirmada ni modifica la cita: una Programada puede generar su aviso y una Confirmada conservar los vigentes. La reserva nueva permanece inicialmente Programada; Confirmada significa exclusivamente llegada presencial verificada por Recepción mediante CU-17.
3. Antes de generar, también en cada ejecución del programador, revalida reserva guardada, vigencia, estados y preferencias y guarda un único aviso en PostgreSQL por Paciente, cita y programación: especialidad, Médico, fecha, hora, Presencial, Clínica Vida Salud y consultorio.
4. Sin cuenta web, conserva el aviso asociado al identificador del Paciente y de la cita; al activarla muestra solo avisos propios autorizados de citas futuras vigentes.
5. Al consultar Notificaciones, el servidor valida sesión y titularidad.
6. Reprogramar conserva la cita y su trazabilidad, recalcula el momento del aviso según la nueva fecha y hora y actualiza o invalida el anterior sin duplicarlo; cambiar una ubicación autorizada actualiza el consultorio del aviso.
7. Cancelada, Atendida, Inasistencia o turno finalizado impiden nuevos recordatorios de próxima atención y retiran los avisos anteriores de próximos recordatorios, conservando el historial y los registros correspondientes según las reglas existentes. Laravel genera y PostgreSQL conserva los avisos sin depender del navegador.
8. Si el Paciente habilitó Web Push en un dispositivo compatible, después de persistir el aviso interno el backend revalida vigencia, programación, preferencias, cuenta y suscripción, y despacha la notificación al servicio push correspondiente sin depender de una pestaña abierta. No crea otro aviso interno por cada dispositivo ni por cada reintento.
9. El dispositivo presenta una notificación genérica, sin contenido clínico sensible, **si su sistema operativo/navegador y configuración lo permiten**. Aceptación del servicio push no se presenta como recepción o lectura confirmada. Pantalla, sonido y vibración dependen del dispositivo; no se fuerzan ni se garantizan.
10. Al abrir la notificación se accede al portal con autenticación/autorización y se consulta el estado vigente; el enlace no contiene credenciales/datos clínicos ni concede acceso por sí mismo. Reprogramación, cambio de ubicación o finalización invalidan despachos pendientes obsoletos; una notificación ya entregada puede quedar desactualizada y no se garantiza retirarla del teléfono.

**Flujos alternativos del canal Web Push**

- **Habilitar/revocar:** el Paciente autenticado decide por dispositivo y concede o revoca permiso/suscripción propia. Administración no puede hacerlo en su nombre. No disponer, denegar o revocar push no desactiva por sí mismo la consulta/generación interna según las preferencias existentes.
- **Sin soporte o instalación requerida:** explicar la condición de compatibilidad/permiso/instalación web y conservar los avisos internos; no afirmar que se entregarán al teléfono ni exigir app nativa.
- **Sin cuenta web o sin suscripción vigente:** conservar el aviso por ficha/cita, sin envío a un dispositivo no vinculado. Activar la cuenta no autoriza push retroactivo por sí solo: se requiere consentimiento y nueva evaluación de vigencia.
- **Red, suscripción vencida o rechazo del servicio push:** conservar el aviso interno y tratar el envío según su resultado real; no modificar la cita ni afirmar recepción. Reintentos futuros deben volver a validar vigencia y evitar duplicados por programación/dispositivo, sin garantizar entrega exactamente una vez ante respuesta incierta.

**Postcondición**

El Paciente consulta únicamente recordatorios internos propios, pertinentes y sin duplicación. Si autorizó dispositivos compatibles, el sistema realiza el despacho Web Push de los avisos elegibles; recepción, lectura, sonido y encendido de pantalla no se acreditan por haber despachado. El aviso interno no se elimina por fallo de push. No se conceden facultades de reprogramación/cancelación ni preferencias administrativas nuevas.

**Excepciones**

Reserva inexistente o cuyo registro falló: no generar un recordatorio como si se hubiera guardado correctamente. Sin cuenta: conservar la relación sin exponerla a terceros; no impide programar por Recepción. Preferencias internas deshabilitadas: no generar nuevos avisos; al habilitarlas solo citas aún pertinentes, sin duplicados. Sin avisos, cita finalizada o aviso no vigente antes de ejecutar/despachar: no generar ni enviar recordatorios de próxima atención, retirar de próximos cuando corresponda. Sesión inválida al consultar/vincular dispositivo, recurso/suscripción ajenos o fallo al guardar: denegar o informar sin confirmar éxito falso. Cuenta desactivada, suscripción o vínculo revocados: impedir nuevos despachos. Permiso denegado, ahorro de batería, «No molestar», falta de conectividad o restricciones del SO pueden impedir/retrasar presentación o encendido; no prometer otra cosa. No se usan Brevo, correo, SMS ni WhatsApp para citas; Web Push es la única excepción externa aprobada.

**Concreción posterior F1, no implementación por esta ficha:** frecuencia/librería/logout/corte de push/default interno ya aprobados arriba. README backend define contratos previstos y dos tablas propuestas (suscripciones/outbox), clasificación de respuestas, reintentos acotados y estado Incierto sin replay automático; no esquema/API/Service Worker/PWA funcional. Quedan verificación real Composer/allowlist/seguridad de conexión/configuración operativa y compatibilidad efectiva al implementar; no nuevas reglas clínicas ni envíos manuales administrativos.


### 4.12. Consultas y reportes administrativos (`CU-29` a `CU-29`)

<a id="cu-29"></a>
#### CU-29 — Generar reportes administrativos

**Trazabilidad de la ficha:** Alcance: 1.4.12. Proceso: PN24. Correspondencia: RF13. RN10, RN11, RN12, RN14. Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 99.

**Propósito**

Obtener los reportes de pacientes y citas requeridos para la administración de la clínica.

**Actores**

Administrador

**Iniciador**

Administrador

**Precondición**

El Administrador inició sesión; dispone de acceso a los reportes generales.

**Flujo principal**

1. El Administrador elige citas por fecha, médico, especialidad o estado; pacientes registrados; cantidades por período; o agenda diaria.
2. Establece fecha o rango de fechas y los filtros aplicables; utiliza únicamente los cinco estados definidos.
3. El servidor valida permisos y filtros y consulta la información persistida.
4. Presenta los resultados y totales pertinentes, incluidas Atendidas, Canceladas e Inasistencias del período.
5. Para la agenda diaria muestra los datos y restricciones de CU-20; para pacientes utiliza los datos administrativos permitidos.
6. Si se implementa la mejora opcional, permite exportar el resultado autorizado a CSV o PDF.

**Postcondición**

El reporte queda disponible para consulta sin modificar registros; la exportación conserva su carácter opcional.

**Excepciones**

Rango inválido o criterios incoherentes: solicitar corrección sin generar resultados engañosos. Sin datos: mostrar resultados vacíos y total cero cuando corresponda. Usuario sin permiso o fallo al generar: denegar o informar sin exponer datos internos.

**Notas y verificaciones de la ficha**

Cubrir todos los reportes mínimos de RF13. Consultas operativas de Recepción y Médico se realizan mediante CU-20 y CU-21 según sus atribuciones.


### 4.13. Bitácora del Sistema (`CU-30` a `CU-30`)

<a id="cu-30"></a>
#### CU-30 — Consultar bitácora del sistema

**Trazabilidad de la ficha:** Módulo y alcance: 1.4.13 Bitácora del Sistema. Decisión del proyecto compatible con RN12 y RN14, sin crear nuevos R o RN oficiales. Registro automático transversal. Ciclo: 2.  
**Estado documental:** Especificado; backend implementado y verificado localmente, frontend pendiente · **Ciclo:** 2 · **Fuente:** PDF, página 100; cierre backend 2026-10-21.

**Propósito**

Investigar eventos del sistema mediante una consulta autorizada de auditoría.

**Actores**

Administrador

**Iniciador**

Administrador

**Precondición**

El Administrador dispone de cuenta activa y sesión válida; el almacenamiento de auditoría está disponible.

**Flujo principal**

1. El Administrador abre Bitácora del Sistema.
2. El servidor comprueba su rol y consulta eventos persistidos en PostgreSQL.
3. Presenta los más recientes primero, con paginación.
4. El Administrador filtra por fecha o período, responsable, cuenta objetivo, módulo, operación, tipo de evento, resultado, IP cuando exista o recurso afectado.
5. El servidor valida los criterios y aplica filtros y paginación antes de devolver resultados.
6. El Administrador selecciona un evento y consulta identificador, fecha y hora, responsable, cuenta o recurso, módulo, operación, resultado y causa general segura.
7. Puede continuar consultando sin modificar los eventos.

**Postcondición**

El Administrador conoce el historial autorizado sin alterar los eventos ni obtener permisos clínicos adicionales.

**Excepciones**

Sin coincidencias: mostrar lista vacía. Filtros inválidos: solicitar corrección. Consulta fallida: informar sin exponer detalles internos. Otro rol, sesión inválida o intento de editar o eliminar: denegar desde el servidor. Sin responsable autenticado: identificar el origen como anónimo o proceso del sistema, sin inventar una identidad. No mostrar contraseñas, hashes, códigos, tokens, archivos de resultados ni contenido clínico innecesario.

**Notas y verificaciones de la ficha**

Comprobar acceso exclusivo del Administrador, filtros, paginación, ausencia de secretos y rechazo de cualquier edición o eliminación de eventos.

**Cierre backend local (2026-10-21):** fases 1–5 completadas. `GET /api/bitacora` aplica filtros estrictos, recursos históricos, paginación y fechas locales/UTC; `GET /api/bitacora/{id}` devuelve cabecera y proyección por familia/versión sin secretos, texto libre ni expansión de relaciones. Verificados concurrencia ante revocación, inmutabilidad API/SQL/Eloquent y rendimiento local con 5000 eventos ficticios. Integración con productores reales de Médicos/Especialidades, cuentas/invitaciones, Recepción/Pacientes/vinculación y autenticación: 3 pruebas/206 aserciones. Regresión completa final **987 pruebas/10910 aserciones correctas** en PostgreSQL aislado. Contrato/evidencia en README backend y contexto §§17.67–17.71. Frontend, aceptación de producción y eventos de módulos futuros pendientes; diagrama global aplazado expresamente.


**Avance frontend fase 1 (2026-10-21):** servicio GET de listado/detalle y utilidades de fechas/etiquetas preparados conforme al contrato; rutas protegidas exclusivas de Administrador, menú, título y retorno seguro tras login. Las vistas iniciales informan que listado/filtros/paginación (fase 2) y detalle (fase 3) están pendientes, sin consultar eventos aún. 44 pruebas nuevas y suite completa 565/33 archivos correctos, build correcto. Revisión/integración/cierre en fases 4–5; CU-30 frontend sigue parcial. Contexto §17.72 y análisis sincronizados, diagrama global aplazado.

**Avance frontend fase 2 (2026-10-21):** listado funcional con filtros principales/avanzados, criterios aplicados separados del borrador, limpiar/reiniciar página y paginación por meta del servidor. Cabecera con roles/IDs históricos, origen y fecha del hecho de sede, sin expandir perfiles. Estados de carga/vacío/error, 422 por campo/foco, 429 con espera, 401 central y descarte de consultas obsoletas verificados. 14 pruebas nuevas: feature 58/4 archivos y suite completa 579/34 archivos correctos, build correcto; HTTP simulado. Fase 3 detalle/retorno y fases 4–5 revisión/integración/cierre pendientes. Contexto §17.73 y análisis sincronizados; diagrama global aplazado.

**Avance frontend fase 3 (2026-10-21):** detalle seguro implementado, con fechas de sede y UTC original, roles/orígenes históricos, causa/IP/correlación/versión/evento relacionado y presentación explícita de cambios/metadatos/referencias autorizados, sin volcar JSON. Borrador/filtros/página se mantienen en estado React acotado a ambas rutas y se reconsulta el listado al regresar; salir del módulo descarta ese estado. ID inválido, 404/422, errores generales/429 y detalles obsoletos verificados. 28 pruebas nuevas; acumulado Bitácora 86/5 archivos y suite completa 607/35 archivos correctos, build correcto, HTTP simulado. Fases 4–5 revisión/integración/cierre pendientes; contexto §17.74 y análisis parcial sincronizados. Diagrama global aplazado.

**Avance frontend fase 4 (2026-10-21):** revisión de seguridad/teclado/responsive/accesibilidad completada con Chrome/HTTP ficticio interceptado: 72 comprobaciones de tamaño sin desbordamiento, 18 auditorías axe sin infracciones detectadas y permisos/revocación verificados. Corregidas reapertura/foco de avanzados tras 422 y normalización de fechas imposibles. 14 pruebas nuevas; acumulado Bitácora 100/6 archivos y suite completa 621/36 archivos correctos, build correcto. Contexto §17.75/análisis sincronizados. Fase 5 integración Laravel/PostgreSQL y cierre local pendientes; producción/diagrama global mantienen pendientes previos.

## 5. Reglas transversales que no se deben perder

### 5.1. Autenticación y verificación

- Acceso de todos los roles mediante **correo electrónico y contraseña**. Tres errores consecutivos bloquean 10 minutos, sin prolongar el bloqueo por nuevos intentos; políticas de cuenta pendiente, activa y desactivada independientes del estado administrativo del Paciente/Médico.
- **Política de contraseñas definitivas, decisión 2026-10-23 (VS-AUT-02):** en CU-03/CU-04/CU-05/CU-06 y sus caminos de invitación/vinculación/definitiva tras credencial temporal, exigir mínimo 12 caracteres con mayúscula, minúscula, número decimal y carácter especial; espacios no sustituyen el especial. Conservar confirmación, Unicode y límites del hash. No imponer la nueva composición en CU-01 ni al comprobar contraseña actual: las cuentas previas mantienen acceso. No cambia códigos ni credenciales temporales. Backend implementa la validación compartida; frontend alineado con requisitos Cumplido/Pendiente, coincidencia en vivo y bloqueo previo al envío (§17.116), conservando errores definitivos del servidor. Integración PostgreSQL pendiente de reejecutar con el servidor disponible.
- Paciente nuevo: proceso pendiente 24 horas, un código numérico de correo de 6 dígitos con 10 minutos de vigencia y 5 errores máximos, enviado por Brevo.
- Paciente previamente registrado por Recepción: identidad comprobada presencialmente; código de Recepción de 24 horas con 5 errores, luego correo+contraseña y segundo código de correo de 6 dígitos. Consumo único y vinculación atómica; preservar ficha e historial sin crear duplicados.
- Reenvíos del código de correo: mínimo 60 segundos entre envíos y máximo 5 por hora por proceso y dirección; un reenvío invalida el anterior y no amplía la vigencia del proceso principal.
- Invitación al personal: 24 horas y uso único. Recuperación de contraseña: enlace de 60 minutos, un solo uso, sin iniciar sesión automáticamente. Contraseñas de 12 caracteres como mínimo y hash seguro. Brevo **no** envía recordatorios de citas.

### 5.2. Citas y estados

- Una sede, modalidad Presencial; médico y especialidad activos y relacionados; consultorio fijo del Médico; duración inicial 15 minutos configurable. Validar disponibilidad del Médico y consultorio en servidor incluso bajo concurrencia.
- Cinco estados: `Programada`, `Confirmada`, `Atendida`, `Cancelada`, `Inasistencia`. Nueva cita → Programada; llegada Recepción Programada→Confirmada el mismo día; Médico propio Programada/Confirmada→Atendida tras atención; cancelación Recepción antes de la atención; Inasistencia Recepción desde Programada después de fin del intervalo y ausencia comprobada.
- Reprogramar es **operación**, no sexto estado; Recepción conserva Programada o convierte Confirmada→Programada. Citas Canceladas liberan disponibilidad sin borrarse. Estados finales Atendida/Cancelada/Inasistencia no admiten cambios ordinarios.
- Paciente inactivo no recibe citas nuevas; Recepción puede reprogramar su misma cita existente si es válida. Médico inactivo no recibe nuevas citas ni reprogramaciones a su favor, pero su cuenta activa puede completar operaciones previamente asignadas autorizadas.

### 5.3. Historia, laboratorio, auditoría

- Máximo una consulta por cita; registrar o corregir consulta no marca Atendida. Correcciones clínicas conservan versiones y permisos. Una consulta admite varias solicitudes de laboratorio.
- Documentos de laboratorio externos: Administrador carga/corrige, Médico solicitante revisa/publica o devuelve, Paciente accede únicamente a versión vigente y publicada; estados internos Pendiente, En revisión y Disponible.
- Recordatorios de citas: internos persistentes y automáticos, normalmente 24 horas antes, **más Web Push opcional aprobado el 2026-10-30** en dispositivos compatibles autorizados. Sin garantía de entrega/encendido de pantalla; no correo/SMS/WhatsApp/Brevo. Ampliación de CU-28 pendiente de implementación, no cambio de RF/RN oficiales.
- CU-30: consulta exclusiva del Administrador. Registrar eventos automáticos sin contraseñas, hashes, tokens, códigos, textos clínicos ni archivos. Auditoría general ≠ versiones clínicas detalladas.

## 6. Política de actualización durante el desarrollo

1. Antes de implementar un CU, verificar la ficha y las reglas relacionadas en este archivo y el examen; comprobar la realidad del repositorio y dependencias.
2. Al terminar un CU o grupo relacionado, actualizar `analisis-clases.md` con clases participantes y relaciones efectivamente utilizadas o previstas, indicando su estado real. Cuando se complete el módulo, integrar sus clases en `diagrama-clases.md` sin recrearlo desde cero.
3. Si se modifica el esquema, actualizar `diagrama-entidad-relacion.md` y verificar migraciones/relaciones. Actualizar `requerimientos.md` y `reglas-negocio.md` cuando exista una **decisión aprobada**, no por un ajuste técnico accidental.
4. Actualizar esta ficha **solo ante aclaraciones aprobadas** y conservar la identificación `CU-01` a `CU-30`. Nunca convertir un requisito pendiente en implementado por la sola existencia del documento.
5. Mantener el 4.5 académico reservado para desarrollo posterior según la decisión vigente; no agregar aquí diagramas UML generales en su lugar.

## 7. Fuente y límites

- **Fuente funcional:** capítulo 4 de `Clinica_Vida_Salud_Captura_Actualizada(3).pdf` (páginas 62–101), complementado por el Alcance y PN01–PN24 de ese mismo documento.
- **Fuente obligatoria superior:** enunciado oficial del examen de Ingeniería de Requisitos (RF01–RF13, RN01–RN14 y pruebas mínimas).
- **Documentos relacionados:** `contexto-maestro.md`, `requerimientos.md`, `reglas-negocio.md`, `diagrama-entidad-relacion.md`, `diagrama-clases.md`, `analisis-clases.md`. Si aún no existen, no suponer su contenido.
- **Estado técnico:** esta ficha no demuestra que exista un backend, frontend, migraciones ni pruebas. Comprobar el código antes de afirmar implementación.
