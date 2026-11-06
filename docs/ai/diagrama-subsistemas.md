# Implementación de la Arquitectura de Subsistemas

Cada diagrama presenta tres filas: **«Interface»**, con las páginas React del frontend Vite; **«Class»**, con las clases PHP seleccionadas del backend Laravel; y **«Table»**, con las tablas PostgreSQL principales. Los recuadros muestran componentes existentes, no elementos futuros.

Las líneas continuas resumen la comunicación HTTP mediante los servicios del frontend y Axios. Las discontinuas resumen dependencias de persistencia mediante Services, Repositories y modelos Eloquent; no implican acceso SQL directo desde los controladores. Se seleccionan las relaciones más representativas, sin dibujar todas las dependencias compartidas.

### P1. Usuarios y autenticación

**Estado: Implementado localmente (CU-01–CU-06), con los límites funcionales documentados.**

![P1. Usuarios y autenticación: interfaces, clases y tablas](diagrama-subsistemas/p1-usuarios-autenticacion.svg)

Las ocho interfaces seleccionadas cubren acceso y cierre de sesión, administración de cuentas, registro/vinculación de Pacientes, alta y activación del personal, cambio de contraseña y restablecimiento. `RegisterPatientPage.jsx` atiende tanto registro como vinculación; `VerifyRegistrationPage.jsx` completa la verificación y `ForgotPasswordPage.jsx` solicita la recuperación, sin añadir más cajas a la muestra.

Las líneas continuas resumen llamadas mediante servicios frontend, Axios y controladores HTTP hacia las seis clases de negocio representadas; no son llamadas PHP directas desde React. Las dependencias discontinuas pasan por repositorios y Eloquent. No se añaden símbolos de interfaces provistas/requeridas: las páginas React no son contratos UML de servicios.

`usuarios` también es compartida por autenticación, registro, invitaciones, cambio y recuperación: se omiten esos enlaces repetidos. La dependencia de `ClaveService.php` hacia `tokens_acceso` indica la invalidación de recuperaciones anteriores; esa tabla almacena credenciales temporales de seguridad, no JWT de sesión.

El Administrador conserva acceso a 28 de los 30 casos de uso, excepto CU-04 y CU-08; representar el registro en este paquete no le concede esa operación personal.

**Fuentes contrastadas:** páginas, `LogoutButton.jsx` y servicios de `frontend/src/features/auth/` y `frontend/src/features/usuarios/`; controladores, servicios y repositorios del módulo `usuarios` en el backend; migraciones de `backend/src/data/database/migrations/`; estados de CU-01–CU-06 en `docs/ai/diagrama-clases.md`, §5. El SVG conserva texto vectorial y nombres completos en etiquetas accesibles, aunque algunos se distribuyan en dos líneas.

### P2. Pacientes y perfil personal

**Estado: Implementado en código (CU-07–CU-08), verificado por inspección estática.**

![P2. Pacientes y perfil personal: interfaces, clases y tablas](diagrama-subsistemas/p2-pacientes-perfil-personal.svg)

La selección cubre alta y edición administrativa, emisión del código de activación, perfil propio y cambio verificado del correo de acceso. Administrador y Recepción gestionan fichas y códigos; solo el Paciente actualiza su perfil y correo de acceso. La ficha admite CI ausente en el registro administrativo, sin ofrecer vinculación web sin CI.

Se omiten las páginas de listado/detalle y `PatientActivationPage.jsx`, que incorpora `ActivationCodePage.jsx`, además de formularios, hooks, servicios HTTP y controladores intermediarios. `PacienteService.php` también gestiona el contacto de emergencia; `MiPerfilService.php` reutiliza `pacientes`. El cambio de correo reutiliza el módulo `usuarios`, verificaciones, sesiones y tokens de seguridad de P1; se omiten sus enlaces secundarios, coordinación del registro pendiente y bitácora. Correo de contacto y correo de acceso siguen siendo distintos.

### P3. Médicos y especialidades

**Estado: Implementado en código (CU-09–CU-10), verificado por inspección estática.**

![P3. Médicos y especialidades: interfaces, clases y tablas](diagrama-subsistemas/p3-medicos-especialidades.svg)

Las páginas administrativas permiten consultar, crear, editar y cambiar el estado profesional o de la especialidad, y gestionar las asociaciones habilitadas en `medico_especialidad`. Los dos Services se reutilizan entre páginas; no hay una clase por rol. Estas operaciones están restringidas al Administrador.

Se omiten `DoctorDetailPage.jsx`, `SpecialtyDetailPage.jsx`, formularios y capas HTTP/Repositories/Eloquent intermediarias. `MedicoService.php` también consulta especialidades, cuentas y citas para validar asociaciones, reactivaciones y retirada de especialidades; la coordinación administrativa y la bitácora son compartidas. El vínculo de cuenta y el estado profesional no son equivalentes; las referencias históricas no se eliminan al deshabilitar asociaciones.

### P4. Consultorios, horarios y disponibilidad

**Estado: Implementado en código (CU-11–CU-12 y consulta de disponibilidad compartida con CU-13), verificado por inspección estática.**

![P4. Consultorios, horarios y disponibilidad: interfaces, clases y tablas](diagrama-subsistemas/p4-consultorios-horarios-disponibilidad.svg)

Se representan edición de consultorios, asignación fija al Médico, horarios, duración vigente y consulta de turnos. La administración corresponde al Administrador; la disponibilidad está autorizada para Administrador, Recepción y Paciente. `DisponibilidadTurnos.jsx` se integra en `ReservarCitaPage.jsx`: consultar o seleccionar un turno no registra ni garantiza una cita; aquí no se desarrolla la reserva.

Se omiten listados/detalles, servicios HTTP, controladores, repositorios y modelos. Los enlaces inferiores seleccionan una dependencia por Service, no todas: asignación también consulta consultorios, horarios y citas, y registra cambios de ubicación explícitamente confirmados en `cambios_cita`; horarios y duración verifican cobertura y conflictos. Disponibilidad lee además configuración, horarios, consultorio, Médico, especialidad, asociación habilitada y Paciente. Se comparten `PlanificacionRepository.php`, `IntervalosAtencion.php`, autenticación y auditoría; no existen tablas independientes de disponibilidad ni una base por paquete.

### P5. Registro y reserva de citas médicas

**Estado: Implementado en código (CU-13–CU-14), verificado por inspección estática.**

![P5. Registro y reserva de citas médicas: interfaces, clases y tablas](diagrama-subsistemas/p5-registro-reserva-citas.svg)

`ReservarCitaPage.jsx` coordina catálogos, selección y propuesta; `DisponibilidadTurnos.jsx` consulta turnos orientativos y `ReservaRegistro.jsx` presenta la revisión, confirmación y consulta del resultado incierto. Paciente reserva solo para sí; Administrador y Recepción usan la misma página para un Paciente seleccionado. El servidor revalida al guardar y crea la cita `Programada`, no `Confirmada`.

Se omiten `ReservaCatalogo.jsx`, hooks, servicios HTTP, controladores y repositorios intermediarios; el enlace de `ReservaRegistro.jsx` pasa por callbacks y `useReservaRegistro`. `ReservaService.php` reutiliza `DisponibilidadService.php` antes de persistir mediante `CitaRepository.php`; se omite ese enlace interno y la lectura de ocupación de `citas` desde disponibilidad. También se omiten recursos compartidos de P4 (horarios, consultorio, configuración y asociaciones), ficha de Paciente, autenticación, propuesta firmada y bitácora. Repetir clases/tablas compartidas en estos diagramas no duplica el software ni crea tablas de turnos o propuestas.

### P6. Reprogramación y cancelación

**Estado: Implementado en código (CU-15–CU-16), verificado por inspección estática.**

![P6. Reprogramación y cancelación: interfaces, clases y tablas](diagrama-subsistemas/p6-reprogramacion-cancelacion.svg)

`ReprogramacionCita.jsx` y `CancelacionCita.jsx` se integran en `GestionCitaPage.jsx`, exclusiva de Administrador y Recepción; sus escrituras pasan por callbacks y `useGestionCita`, no por llamadas directas a PHP. Reprogramar consulta alternativas y conserva la misma cita, retornando a `Programada`; cancelar conserva el registro y libera el intervalo. Ambas operaciones verifican la revisión original y registran ejecutor y cambios en `cambios_cita`.

Se omiten la página contenedora, resumen, hooks y capas HTTP/Repositories/Eloquent. `GestionCitaService.php` reutiliza disponibilidad al revalidar el nuevo turno; los enlaces inferiores resumen lecturas/escrituras mediante `DisponibilidadRepository.php` y `CitaRepository.php`, sin dibujar cada recurso de P4. Se omiten consultas de identidad compartidas con agenda, comprobación de consulta clínica asociada, autenticación, coordinación de planificación, invalidación de avisos existentes y bitácora; cancelar no depende de consultar alternativas.

### P7. Estados de citas y control de asistencia

**Estado: Implementado en código (CU-17–CU-19), verificado por inspección estática.**

![P7. Estados de citas y control de asistencia: interfaces, clases y tablas](diagrama-subsistemas/p7-estados-citas-control-asistencia.svg)

Una página y un componente de confirmación compartidos atienden llegada, inasistencia y atención mediante `AsistenciaService.php`. Administrador y Recepción registran llegada (`Confirmada`) o ausencia comprobada (`Inasistencia`); Administrador o Médico responsable marcan `Atendida`, conservando ejecutor y responsable separados. La elegibilidad se decide en el servidor; no hay inasistencia automática ni consulta clínica creada por marcar atención.

Los enlaces resumen `useAsistenciaCita`, servicio HTTP, controlador, `CitaRepository.php` y modelos `Cita`/`CambioCita`; la confirmación delega por callback de la página. Se omiten `AsistenciaResumen.jsx`, selección desde agenda/detalle, consulta de etiquetas de identidad y dependencias de autenticación, Médico, planificación, existencia de consulta clínica, avisos y bitácora. Las transiciones guardan estado e historial en la misma transacción; llegada conserva avisos existentes, mientras inasistencia/atención los invalidan.

### P8. Agenda e historial de citas

**Estado: Implementado en código (CU-20–CU-21), verificado por inspección estática.**

![P8. Agenda e historial de citas: interfaces, clases y tablas](diagrama-subsistemas/p8-agenda-historial-citas.svg)

`AgendaPage.jsx` consulta la agenda propia del Médico o la del profesional seleccionado por Administrador/Recepción. `HistorialCitas.jsx`, reutilizado por `MisCitasPage.jsx` e `HistorialCitasPage.jsx`, consulta citas propias del Paciente o el historial administrativo de una ficha seleccionada. `CitaDetallePage.jsx` presenta el detalle autorizado; los tres Services leen `citas`, sin tablas independientes de agenda o historial.

Se omiten las páginas contenedoras del historial, selección de Paciente, filtros, tablas visuales, `AgendaWorkspace`, hooks, servicios HTTP y controladores. Los enlaces inferiores resumen `AgendaRepository.php`, compartido por los tres Services; se omiten sus lecturas de `medicos`, `pacientes` y `especialidades`, y la autenticación. Las consultas aplican ámbito, filtros y paginación en el servidor; no modifican estados ni acreditan disponibilidad para reservar. Reutilizar piezas de otros paquetes no duplica el software.

### P9. Historia clínica y consultas médicas

**Estado: Implementado en código (CU-22–CU-23), verificado por inspección estática.**

![P9. Historia clínica y consultas médicas: interfaces, clases y tablas](diagrama-subsistemas/p9-historia-clinica-consultas-medicas.svg)

Historia, detalle y versiones internas comparten `HistoriaClinicaService.php`; el editor registra o corrige mediante `ConsultaMedicaService.php`, con una consulta por cita y versiones de contenido y antecedentes. Administrador y Médico responsable editan durante una atención real autorizada, conservando ejecutor y responsable; el Paciente solo consulta información vigente propia y Recepción no accede a clínica. Guardar no marca la cita `Atendida`.

Se omiten formularios, selección, hooks y capas HTTP/Repositories/Eloquent. Los enlaces seleccionan lectura de `consultas_medicas` y escritura de las dos tablas de versiones; también existen creación de consulta desde el Service de escritura y lectura de versiones desde el de historia. Se omiten la lectura de contexto del editor y su reconsulta tras guardar, ficha propia, citas, Médico, planificación, continuidad administrativa, autenticación, bitácora y metadatos de estudios. La historia se compone desde registros existentes: no hay una tabla `historia_clinica` independiente.

### P10. Laboratorio digital

**Estado: Implementado en código (CU-24–CU-27), verificado por inspección estática.**

![P10. Laboratorio digital: interfaces, clases y tablas](diagrama-subsistemas/p10-laboratorio-digital.svg)

La selección cubre solicitud de estudios sobre una consulta existente, carga/corrección administrativa, revisión/devolución/publicación y consulta/descarga protegida. `GestionDocumentoLaboratorio.jsx`, `GestionRevisionResultado.jsx` y `DescargarResultado.jsx` se integran en `SolicitudLaboratorioPage.jsx`. Administrador o Médico responsable solicitan y revisan, con ejecutor real separado; solo Administrador incorpora documentos. El Paciente solo descarga su versión vigente publicada; Administrador y Médico responsable acceden al documento vigente autorizado para su gestión. Recepción no accede.

Se omiten la página de detalle, formularios, versiones visuales, hooks y capas HTTP/Repositories/Eloquent. Se selecciona una dependencia de persistencia por Service: carga también crea `versiones_resultado` y actualiza solicitudes; revisión registra la decisión y actualiza la publicación; lectura combina las cuatro tablas. Los bytes PDF/imagen no están en PostgreSQL: `ArchivoLaboratorio.php` gestiona almacenamiento privado y apertura autorizada, sin URL pública. Se omiten sus enlaces, verificaciones de contexto clínico, autenticación, planificación, continuidad y bitácora; cargar no publica ni cambia la cita o la consulta.

### P11. Recordatorios automáticos

**Estado: Implementado en código (CU-28), incluidos avisos internos y Web Push opcional; operación y entrega real no verificadas en esta revisión.**

![P11. Recordatorios automáticos: interfaces, clases y tablas](diagrama-subsistemas/p11-recordatorios-automaticos.svg)

`RecordatoriosPage.jsx` consulta avisos propios del Paciente o del paciente seleccionado por Administrador; no los genera. `AltaPush.jsx` y `DispositivosPush.jsx` permiten consentimiento, alta y revocación exclusivamente del titular. El scheduler invoca cada minuto `RecordatorioService.php` para generar avisos internos y preparar el outbox, y `DespachoPushService.php` para transmitirlo; no existe llamada React al despacho. Los avisos internos no exigen cuenta web; el envío push revalida cuenta, consentimiento y cita antes de su inicio.

Se selecciona una dependencia de persistencia por Service; generación también lee suscripciones y crea envíos, y despacho consulta avisos/suscripciones y actualiza el outbox. Se omiten hooks, servicios HTTP, controladores, repositorios, modelos, citas, preferencias, autenticación, planificación y bitácora compartidas. El transporte existente `TransportePush.php` usa Web Push/VAPID; `pushBrowser.js` registra `public/push/sw.js`, que muestra texto genérico y abre `/recordatorios` sin caché clínica. No se dibujan como interfaces React ni se simula comunicación HTTP del frontend con el dispatcher. El canal está deshabilitado por defecto: configuración efectiva, claves, scheduler operativo y entrega a dispositivos no comprobados; aceptación no garantiza entrega, lectura ni sonido.

### P12. Consultas y reportes administrativos

**Estado: Implementado en código (CU-29), verificado por inspección estática; sin exportación CSV/PDF opcional en el alcance revisado.**

![P12. Consultas y reportes administrativos: interfaces, clases y tablas](diagrama-subsistemas/p12-consultas-reportes-administrativos.svg)

`ReportesPage.jsx` reúne las siete consultas: citas programadas por fecha, por Médico, especialidad y estado, pacientes registrados, cantidades de Atendidas/Canceladas/Inasistencia y agenda diaria. Solo Administrador accede; el servidor aplica filtros, períodos, paginación y agregaciones. Las dos tablas representadas son compartidas: no existe una tabla `reportes` ni se duplican datos para este paquete.

Se omiten filtros/tablas visuales, hook compartido de agenda, servicio HTTP, controlador y `ReporteRepository.php`/modelos. El repositorio también lee `medicos` y `especialidades`; para agenda diaria `ReporteService.php` delega en `AgendaService.php` y `AgendaRepository.php` de P8. Estos enlaces, autenticación y proyecciones administrativas se omiten para mantener legibilidad; las flechas inferiores no atribuyen la agenda diaria a ReporteRepository.

### P13. Bitácora del sistema

**Estado: Implementado en código (CU-30), verificado por inspección estática.**

![P13. Bitácora del sistema: interfaces, clases y tablas](diagrama-subsistemas/p13-bitacora-sistema.svg)

Listado y detalle consultan `BitacoraService.php`, exclusivo del Administrador y de solo lectura. Se aplican filtros y paginación, con orden descendente por `registrado_en` e `id_evento`. No hay acciones HTTP para editar o borrar; la migración protege la tabla contra actualización, eliminación y truncado.

Se omiten `BitacoraWorkspace.jsx`, filtros, tabla visual, hooks, servicios HTTP, controlador, `BitacoraRepository.php` y modelo `Bitacora`. Los demás módulos registran eventos mediante el repositorio compartido, no mediante estas páginas lectoras; se omiten esos enlaces y la autenticación. La consulta presenta metadatos y referencias seguras, no documentos, credenciales ni contenido clínico.

## Resumen de los paquetes inspeccionados

- **Implementados en código:** P1–P13; P1 conserva su calificación local aprobada y P2–P13 se contrastaron estáticamente en sus respectivas revisiones. No equivale a certificación funcional o de producción.
- **Parciales o pendientes de implementación:** ninguno identificado en las piezas principales representadas. P11 incluye transporte, dispatcher y frontend push existentes; su configuración efectiva, operación del scheduler y entrega real quedan sin verificar, no se confunden con piezas futuras. P12 no incluye la exportación opcional.
- **Cobertura y coherencia documental:** trece paquetes en orden cubren CU-01–CU-30; disponibilidad se comparte entre P4/P5 y CU-13. Nombres y enlaces de los trece SVG revisados; componentes, servicios y tablas compartidos representan reutilización sobre una única PostgreSQL, no software duplicado. Se conservan los cinco estados de cita, `Confirmada` como llegada y estados de laboratorio separados; los estados del outbox no son estados de cita.
- **Límite de la revisión:** P1–P10 permanecen intactos; revisión global documental, no nueva auditoría funcional exhaustiva. Fase 4 terminada documentalmente, pendiente de aprobación del usuario; la entrega completa no se declara aprobada.
