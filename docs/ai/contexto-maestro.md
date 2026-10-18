# Contexto maestro — Clínica Vida Salud

> **Proyecto:** Sistema Web para la Gestión de Citas Médicas para la Clínica Vida Salud  
> **Ubicación:** `docs/ai/contexto-maestro.md`  
> **Estado:** contexto funcional y tecnológico definido; la implementación debe comprobarse en el repositorio.  
> **Base documental:** enunciado oficial del Segundo Examen Parcial de Ingeniería de Requisitos y documentación del proyecto `Clinica_Vida_Salud_Captura_Actualizada(3).pdf` (104 páginas).  
> **Última consolidación documental:** 18 de octubre de 2026.

## 1. Propósito del archivo

Este documento proporciona a OpenCode y a los colaboradores el **contexto estable y vigente** para comprender el proyecto antes de desarrollar o modificar el software. Resume el objetivo, el alcance, las decisiones aprobadas, las responsabilidades, los límites de arquitectura y la forma de mantener la documentación sincronizada.

**No sustituye** al examen, a los requisitos completos, a las reglas de negocio, a las fichas de casos de uso, al DER ni al código. Ante un detalle no descrito aquí, consultar su fuente específica. No asumir que una funcionalidad documentada ya está implementada.

## 2. Jerarquía de fuentes y resolución de contradicciones

1. **Enunciado oficial del examen:** requisitos `RF01`–`RF13`, reglas `RN01`–`RN14`, condiciones técnicas y pruebas mínimas. No se pueden reducir ni contradecir.
2. **Decisiones explícitas y aprobadas más recientes:** concretan comportamientos que el examen no detalla, sin sustituir obligaciones oficiales.
3. **Documentación académica vigente:** Perfil, Marco Teórico, Modelo de Negocio y Captura de Requisitos, especialmente la versión indicada al inicio.
4. **`docs/ai/`:** documentación de trabajo sincronizada con las decisiones y avances efectivamente realizados.
5. **Código, migraciones, pruebas y configuración del repositorio:** evidencia de implementación; no justifican modificar silenciosamente requisitos aprobados.
6. **Buenas prácticas técnicas:** se aplican donde las fuentes anteriores no han definido una política.

Si las fuentes discrepan, identificar la diferencia y verificar cuál contiene la decisión aprobada más reciente. **No inventar reglas de negocio para resolver el desacuerdo** ni modificar RF/RN oficiales. Separar siempre *exigencia del examen*, *decisión propia del proyecto* y *detalle técnico por implementar*.

## 3. Objetivo y alcance

Construir un sistema web para una **clínica ficticia, con una única sede y atención exclusivamente presencial**, capaz de gestionar pacientes, médicos, especialidades, consultorios, horarios y citas; controlar asistencia; consultar agendas e historiales; generar los reportes exigidos; e integrar las ampliaciones aprobadas de perfil, historia clínica, consultas, laboratorio digital, recordatorios internos, seguridad de cuentas y bitácora.

- Interfaz profesional, accesible desde computadora y celular, con diseño **responsive**.
- Una sola sede con varios consultorios identificados; cada Médico dispone de **consultorio fijo** para sus horarios, y varios Médicos pueden compartir uno cuando no se superponen.
- Sin pagos ni cobros, farmacia, inventarios, recetas electrónicas, telemedicina, videollamadas, atención remota, múltiples sedes o administración dinámica de permisos.
- Exportación de reportes a CSV/PDF y exclusión de feriados/bloqueos de agenda: **mejoras opcionales**, no condiciones para cumplir el núcleo del examen.
- Las ampliaciones no deben comprometer la entrega de los `RF01`–`RF13` ni las pruebas mínimas del examen.

## 4. Tecnologías y arquitectura aprobadas

| Área | Decisión vigente |
| --- | --- |
| Frontend | **React, Vite, JavaScript, HTML5 y CSS3** |
| Navegación | React Router |
| HTTP cliente | Axios centralizado |
| Estado de autenticación | Context API y `useAuth` |
| Backend | **PHP y Laravel**, API HTTP/JSON |
| ORM | Eloquent ORM |
| Base de datos | **PostgreSQL** mediante `PDO/pdo_pgsql` |
| Autenticación SPA | Sesiones/cookies seguras de Laravel, con **Sanctum stateful** según la topología aprobada; protección CSRF |
| Correo transaccional | Brevo, exclusivamente para operaciones de seguridad de cuentas |
| Dependencias | Composer/Artisan para Laravel; gestor configurado en el frontend (preferencia `pnpm`) |

**React es el frontend principal, no Blade.** Laravel concentra validaciones definitivas, autorización, sesiones, reglas de negocio y acceso a PostgreSQL. React nunca accede directamente a la base de datos. No usar JWT personalizado ni almacenar secretos o tokens de sesión en `localStorage`.

### 4.1. Flujo técnico general

```text
React (Page / Component)
  -> Service del frontend
  -> Axios centralizado
  -> Laravel Route
  -> Middleware (sesión, autorización transversal, CSRF cuando corresponda)
  -> Controller + validación de entrada (FormRequest/Validator)
  -> Service (reglas de negocio, coordinación, transacciones)
  -> Repository (consultas y persistencia)
  -> Eloquent Model
  -> PostgreSQL
```

Mantener la separación de responsabilidades. Las páginas no deciden reglas críticas; los controladores no concentran la lógica de negocio; los repositorios no conceden permisos. Respuestas y errores HTTP/JSON deben ser coherentes y seguros. Leer también `backend/AGENTS.md` y `frontend/AGENTS.md`.

## 5. Organización prevista del repositorio

```text
/
├── AGENTS.md
├── frontend/
│   ├── AGENTS.md
│   └── src/
│       ├── api/
│       ├── app/
│       ├── components/
│       ├── layouts/
│       ├── features/
│       ├── hooks/
│       ├── routes/
│       ├── constants/
│       ├── utils/
│       └── styles/
├── backend/
│   ├── AGENTS.md
│   ├── src/
│   │   ├── presentation/{controllers,routes,middlewares,dtos}/
│   │   ├── business/{services,validators}/
│   │   ├── data/{models,repositories,database}/
│   │   └── shared/{errors,utils,constants}/
│   └── [infraestructura necesaria de Laravel]
└── docs/ai/
    ├── contexto-maestro.md
    ├── requerimientos.md
    ├── reglas-negocio.md
    ├── casos-de-us.md
    ├── diagrama-entidad-relacion.md
    ├── diagrama-clases.md
    └── analisis-clases.md
```

Conservar los nombres de carpetas y las **cuatro capas dentro de `backend/src/`**. Laravel mantiene sus archivos de arranque e infraestructura sin duplicar rutas, migraciones o modelos de negocio. En React utilizar **Feature-Based + Component-Based Architecture**. Una feature representa un módulo funcional, no necesariamente una tabla o un CU individual. Crear carpetas y archivos conforme sean necesarios, no anticipadamente.

## 6. Roles fijos y responsabilidades

Solo existen los siguientes **cuatro roles**. No crear roles, permisos configurables o excepciones individuales adicionales.

| Rol | Funciones principales | Prohibiciones y límites |
| --- | --- | --- |
| **Administrador** | Administra cuentas del personal y el estado de acceso de cuentas de Pacientes existentes y previamente verificadas según CU-03; gestiona roles del personal, médicos, especialidades, consultorios y horarios; consulta reportes y bitácora; carga/corrige documentos de laboratorio. | No crea cuentas de Pacientes ni cambia sus credenciales, rol o vinculación; no modifica antecedentes, diagnostica, publica resultados, marca Atendida ni reprograma fechas u horas. |
| **Recepcionista** | Registra/consulta pacientes, verifica identidad y emite códigos presenciales; programa, reprograma y cancela citas; confirma llegada y registra inasistencia. | No crea credenciales de pacientes, no administra cuentas del personal, no modifica contenido clínico, no marca Atendida. |
| **Médico** | Consulta su agenda y datos clínicos autorizados; registra/corrige sus consultas y los antecedentes de pacientes bajo su responsabilidad durante una atención real y autorizada por CU-23; solicita estudios; revisa, devuelve o autoriza publicar resultados que le corresponden; marca sus citas Atendidas. | No administra cuentas ni citas de terceros; no marca Atendida en citas ajenas. |
| **Paciente** | Registra/activa su propia cuenta, actualiza perfil permitido, reserva citas **solo para sí mismo**, consulta historial, información clínica autorizada, resultados publicados y recordatorios internos. | No se autoasigna rol del personal, no reprograma ni cancela citas, no modifica antecedentes, diagnósticos, tratamientos o resultados. |

La autorización definitiva corresponde a **Laravel en cada petición**, comprobando sesión, rol, estado de cuenta y **relación con el recurso**. Ocultar acciones en React solo mejora la interfaz, no constituye una barrera de seguridad.

## 7. Catálogo vigente de casos de uso

La numeración es **única y continua**, `CU-01` a `CU-30`. No renumerar, fusionar ni crear nuevos casos unilateralmente.

| Módulo | Casos de uso |
| --- | --- |
| **Usuarios y autenticación** | `CU-01` Iniciar sesión; `CU-02` Cerrar sesión; `CU-03` Administrar cuentas del personal y asignar roles; `CU-04` Registrar cuenta del Paciente y vincular su registro; `CU-05` Cambiar contraseña propia; `CU-06` Recuperar contraseña mediante correo electrónico. |
| **Pacientes y perfil personal** | `CU-07` Gestionar el registro administrativo de pacientes; `CU-08` Actualizar el perfil personal. |
| **Médicos y especialidades** | `CU-09` Gestionar especialidades médicas; `CU-10` Gestionar médicos y sus especialidades. |
| **Consultorios, horarios y disponibilidad** | `CU-11` Gestionar consultorios y su asignación fija; `CU-12` Configurar horarios y duración de citas. |
| **Registro y reserva de citas** | `CU-13` Buscar médicos y consultar disponibilidad; `CU-14` Programar o reservar una cita presencial. |
| **Reprogramación y cancelación** | `CU-15` Reprogramar una cita; `CU-16` Cancelar una cita. |
| **Estados y asistencia** | `CU-17` Confirmar la llegada del Paciente; `CU-18` Registrar la inasistencia del Paciente; `CU-19` Marcar una cita como Atendida. |
| **Agenda e historial** | `CU-20` Consultar la agenda médica; `CU-21` Consultar el historial y seguimiento de citas. |
| **Historia clínica y consultas** | `CU-22` Consultar la historia clínica autorizada; `CU-23` Registrar o actualizar una consulta médica. |
| **Laboratorio digital** | `CU-24` Solicitar estudios de laboratorio; `CU-25` Cargar o corregir documentos de laboratorio; `CU-26` Revisar y autorizar la publicación de resultados; `CU-27` Consultar solicitudes y descargar resultados autorizados. |
| **Recordatorios automáticos** | `CU-28` Consultar recordatorios internos de próximas citas. |
| **Reportes administrativos** | `CU-29` Generar reportes administrativos. |
| **Bitácora del Sistema** | `CU-30` Consultar bitácora del sistema. |

**Modelo de Negocio:** procesos `PN01`–`PN24`, sin añadir un proceso solo para registrar auditoría. **Alcance:** módulos `1.4.1`–`1.4.13`, con Bitácora en `1.4.13` y condiciones generales en `1.4.14`. **El apartado académico 4.5 se desarrollará en una actividad separada**; no presentarlo como diagrama general terminado.

**Precisiones funcionales aprobadas:** PN02 / CU-03 incluye administrar únicamente el estado de acceso de cuentas de Pacientes previamente verificadas, además de las operaciones propias del personal. PN18 / CU-23 incluye mantener antecedentes clínicos durante una atención autorizada. Se conservan identificadores, ciclos y títulos actuales; la denominación de CU-03 queda pendiente de cotejo con la versión académica actualizada, no disponible en el repositorio. Estas precisiones están especificadas, no acreditan implementación.

## 8. Autenticación y cuentas

- Los cuatro roles inician sesión **solo con correo electrónico y contraseña**; el correo de acceso es único y verificado y es independiente del correo de contacto de la ficha del Paciente.
- Contraseña de **mínimo 12 caracteres**, almacenada únicamente mediante hash seguro de Laravel; nunca en texto plano ni duplicada en Paciente o Médico.
- **Tres errores consecutivos de contraseña** bloquean el acceso **10 minutos**; intentos durante el bloqueo no extienden su duración; al vencer se reinicia el contador, al igual que después de un acceso exitoso. Añadir límites de peticiones por origen.
- Diferenciar cuenta **pendiente**, **activa** y **desactivada** del **bloqueo temporal**. Una cuenta pendiente no entra a funciones normales; una desactivada no accede ni desde sesiones anteriores. Recuperar contraseña no reactiva cuentas desactivadas.
- El Administrador crea y administra cuentas **del personal** (Administrador, Recepcionista y Médico); vincula cuentas médicas con el registro profesional. El primer Administrador se establece mediante instalación segura.
- En CU-03 puede consultar datos administrativos mínimos y desactivar/reactivar el acceso de cuentas web de Pacientes existentes y previamente verificadas. No crea esas cuentas ni modifica Usuario, rol Paciente, contraseña, correo de acceso, vínculo original o datos históricos; tampoco activa cuentas pendientes ni añade permisos. Suspender/rehabilitar significa desactivar/reactivar con los estados existentes, sin crear Suspendida.
- Cuenta, ficha administrativa y bloqueo temporal son independientes. Desactivar una cuenta invalida sus sesiones; reactivarla desde Desactivada conserva el correo verificado y solo habilita el acceso administrativo: requiere nuevo login, no elimina un bloqueo vigente ni activa la ficha. Si la ficha sigue inactiva, no admite nuevas reservas. Ambas operaciones se auditan mediante referencias y metadatos seguros.
- Personal nuevo: **invitación de 24 horas**, un solo uso, enviada por Brevo; confirma control del correo y establece contraseña. Reenvío controlado sobre la **misma cuenta**, invalidando la invitación anterior. Si se usan credenciales temporales por excepción, cambio obligatorio antes del uso normal.
- `CU-05`: cambio propio con comprobación de contraseña actual (salvo flujo restringido de credencial temporal), invalidación de sesiones anteriores y renovación segura de la sesión actual.
- `CU-06`: recuperación para los cuatro roles mediante **enlace de 60 minutos y un solo uso** enviado a correo verificado, respuesta no enumerativa, nuevo enlace invalida el anterior, invalidación de sesiones y **nuevo inicio de sesión obligatorio**. El restablecimiento administrativo de CU-03 es exclusivo del personal tras identificación; gestionar el acceso de Pacientes no permite cambiar sus contraseñas. La alternativa para un Paciente sin acceso a su correo verificado continúa pendiente de definición.
- El Paciente puede cambiar su **correo de acceso** en `CU-08` confirmando contraseña y verificando un código en la nueva dirección; el anterior conserva vigencia hasta concluir el cambio. Cambiar correo de contacto no cambia automáticamente el correo de acceso.
- Brevo se usa para **correo de seguridad de cuentas**. No se usa para recordatorios de citas ni envío de información clínica.

### 8.1. Registro de pacientes y doble verificación

**Paciente nuevo:** completa datos y credenciales; se abre registro **pendiente hasta 24 horas**; Brevo envía código de correo de **6 dígitos**, válido **10 minutos**, máximo **5 intentos incorrectos**. Solo tras verificarlo se confirma una **ficha única y cuenta vinculada**, sin requerir código de Recepción.

**Paciente ya registrado por Recepción y sin cuenta:**

1. Recepción comprueba presencialmente identidad/CI y que la ficha existente no tenga cuenta web.
2. Emite un código de activación **alfanumérico aleatorio de 12 caracteres**, válido **24 horas**, máximo **5 intentos incorrectos**, de un solo uso; un nuevo código invalida el anterior.
3. El Paciente introduce **CI, código de Recepción, correo electrónico y contraseña** en la web.
4. El servidor valida y reserva de forma segura la vinculación; Brevo envía el **segundo código**, numérico de 6 dígitos, válido 10 minutos y máximo 5 intentos incorrectos.
5. El Paciente introduce el segundo código; la verificación completa **atómicamente** el vínculo con el mismo `id_paciente` y consume ambos códigos.
6. Se conservan citas, consultas, historial y resultados autorizados. Hasta finalizar no hay acceso a funciones protegidas.

**Reenvíos:** mínimo **60 segundos** entre envíos de código de correo y máximo **5 envíos por hora por proceso y dirección**, además de límites por origen. Sustituir el código invalida el anterior y **no prolonga** las 24 horas del proceso principal. Un código de Recepción vencido/agotado requiere nueva emisión tras comprobación presencial.

**Privacidad e integridad:** conocer el CI o verificar el correo no prueba, por sí solo, titularidad de una ficha anterior; mensajes públicos no deben revelar historia, nombres ni estado de cuenta. No duplicar Pacientes ni cuentas. Proteger reintentos y solicitudes simultáneas con restricciones y transacciones.

## 9. Pacientes y médicos inactivos

- **Paciente inactivo:** no recibe nuevas citas, ni por reserva web ni por Recepción; sus citas existentes se conservan. Recepción puede reprogramar **la misma cita existente**, cuando sus condiciones ordinarias lo permitan, sin crear otra. Si la cuenta web sigue activa, consulta su historial y la información autorizada.
- **Médico inactivo:** no recibe nuevas citas ni reprogramaciones a su favor; conserva registros y agenda histórica. Si su cuenta permanece activa puede completar atenciones y revisiones de laboratorio **previamente asignadas**, según autorización. Desactivar la cuenta impide esas operaciones.
- Estado administrativo/profesional de la ficha y estado de cuenta son **independientes**. Nunca borrar automáticamente información histórica por inactivación.

## 10. Consultorios, horarios y citas

- Una sede, atención **Presencial** sin selector de modalidad. Cada Médico tiene consultorio fijo para ofrecer turnos y puede compartir el espacio cuando no existan horarios incompatibles.
- Duración inicial de citas: **15 minutos, configurable**. Cada cita conserva su duración almacenada; cambiar configuración no modifica reservas existentes. Las reprogramaciones aplican la duración vigente y vuelven a validar el intervalo completo.
- El Administrador gestiona horarios y consultorios; cambios incompatibles con citas futuras **se rechazan** hasta resolver conflictos. Puede autorizar cambios de ubicación de citas futuras Programadas/Confirmadas sin alterar fecha ni hora; **solo Recepción reprograma fechas/horas**.
- `CU-14`: Paciente reserva solo para sí, Recepción programa administrativamente; ambas rutas validan Paciente, Médico **activo**, especialidad activa y asociada, horario, duración y ocupación de **Médico y consultorio**.
- No admitir fechas pasadas, citas fuera de horario ni superposiciones, **incluso ante peticiones simultáneas**. La consulta de disponibilidad es orientativa: verificar al guardar bajo protección transaccional y restricciones adecuadas de PostgreSQL.
- `CU-15`: solo Recepción reprograma citas Programadas/Confirmadas antes de atención; conserva código, Paciente, Médico y especialidad. Programada sigue Programada; Confirmada vuelve a Programada. Actualiza recordatorios y no pierde el horario original si falla la operación.
- `CU-16`: solo Recepción cancela Programada/Confirmada antes de atención; Cancelada **libera disponibilidad** pero la cita permanece en el historial.

### 10.1. Cinco estados de las citas

| Transición ordinaria | Responsable | Condición principal |
| --- | --- | --- |
| Alta → `Programada` | Paciente para sí o Recepción | Reserva autorizada y disponible |
| `Programada` → `Confirmada` | Recepcionista | Llegada presencial verificada, **mismo día local** y antes del fin del turno |
| `Programada` → `Atendida` | Médico responsable | Atención efectivamente realizada |
| `Confirmada` → `Atendida` | Médico responsable | Atención efectivamente realizada |
| `Programada`/`Confirmada` → `Cancelada` | Recepcionista | Antes de realizarse la atención |
| `Programada` → `Inasistencia` | Recepcionista | Intervalo finalizado, ausencia comprobada |
| `Confirmada` → `Programada` | Recepcionista | Reprogramación válida antes de la atención |

La reprogramación de una Programada conserva el mismo estado. **Atendida, Cancelada e Inasistencia son finales para los flujos ordinarios.** Registrar una consulta clínica no marca la cita automáticamente como Atendida. Usar la zona horaria de la clínica **`America/La_Paz`** para reglas locales de atención.

## 11. Agenda, perfil, historia clínica y consultas

- Las agendas muestran citas por Médico, fecha y estado dentro de los permisos. El historial del Paciente incluye citas pasadas y canceladas, ubicación, fecha de registro y trazabilidad.
- Los antecedentes clínicos y los datos personales del Paciente tienen controles diferenciados. Solo el Médico responsable registra, completa o corrige tipo de sangre, alergias, enfermedades crónicas, medicamentos y antecedentes personales o familiares durante una atención real y autorizada en CU-23 / PN18. Administrador, Recepción y Paciente no los modifican; CU-08 permite solo la edición personal autorizada y CU-22 permanece de consulta.
- Cada cita puede tener **cero o una consulta médica**; el Médico responsable la registra durante la atención o al completarla. No crear consultas para citas Canceladas o con Inasistencia.
- Una consulta puede generar **varias solicitudes de laboratorio**.
- Las modificaciones clínicas relevantes de consultas o antecedentes requieren conservar **autor, fecha, motivo cuando corresponda, versión anterior y nueva**, en almacenamiento clínico protegido. El servidor valida cuenta, rol y relación asistencial; no se crean consultas ficticias para cambiar antecedentes ni se modifica automáticamente el estado de la cita. La bitácora general registra solamente metadatos seguros y referencias, nunca contenido de antecedentes, diagnósticos u otra información médica sensible.
- La historia clínica se compone de información autorizada de antecedentes, consultas y resultados; no confundirla con el historial administrativo de citas.

## 12. Laboratorio digital

- El laboratorio emite sus resultados **externamente**; la aplicación no analiza muestras.
- El **Médico** solicita estudios asociados a una consulta. El **Administrador** carga, asocia y corrige PDFs o imágenes emitidos externamente; no altera su contenido clínico ni interpreta diagnósticos.
- El **Médico solicitante** revisa que el documento pertenezca al estudio/Paciente: si es correcto, **autoriza publicación directamente**; si es incorrecto, devuelve con motivo, Administración corrige o sustituye documento, y se exige **nueva revisión médica**.
- Estados internos de solicitud: **Pendiente, En revisión, Disponible**. No mezclarlos con los cinco estados de cita.
- Hasta `Disponible`, el Paciente ve **Pendiente** sin enlace al documento. Al publicar, solo accede a la **versión vigente y autorizada** de sus propios resultados.
- Almacenar archivos de forma privada, servirlos con autorización en cada consulta/descarga; no exponer rutas públicas. Conservar versiones y trazabilidad de carga, corrección, devolución y autorización.

## 13. Recordatorios internos y reportes

- Los recordatorios son **persistentes, automáticos y exclusivamente internos**. Se programan **24 horas antes del inicio** de la cita; si se registra con menos anticipación, se generan inmediatamente después de que el registro de la reserva se haya guardado y confirmado satisfactoriamente en la base de datos, o en la siguiente ejecución programada inmediata pertinente. **No dependen del estado Confirmada**: una cita Programada puede generar su aviso. Guardar la reserva mantiene su estado inicial Programada; Confirmada significa exclusivamente llegada presencial verificada por Recepción.
- Funcionan sin sesión activa y pueden relacionarse con una ficha de Paciente **sin cuenta web**; cuando esta se active, se muestran solo avisos todavía vigentes y propios.
- Reprogramar o cambiar ubicación actualiza/invalida avisos obsoletos; Cancelada, Atendida, Inasistencia o un turno concluido dejan de generar recordatorios de asistencia. Evitar duplicados y respetar preferencias internas.
- **No** enviar recordatorios por Brevo, correo, SMS, WhatsApp ni push externo.
- `CU-29` debe cubrir reportes mínimos de `RF13`: citas por fecha, Médico, especialidad y estado; pacientes registrados; conteo de Atendidas, Canceladas e Inasistencias por período; agenda diaria por Médico; filtros y totales cuando corresponda.

## 14. Bitácora del Sistema (`CU-30`)

- La bitácora registra automáticamente eventos relevantes de autenticación, cuentas, pacientes, médicos, configuración, citas, consultas **como metadatos** y ciclo documental de laboratorio.
- **Solo el Administrador** consulta el registro general, ordenado del más reciente, con filtros y paginación por fecha, responsable, cuenta objetivo, módulo, operación, tipo, resultado, IP y recurso afectado cuando estén disponibles.
- La consulta es **solo lectura**: no existe edición ni eliminación ordinarias de eventos. La auditoría no otorga permisos clínicos adicionales.
- Nunca registrar contraseñas, hashes, códigos de activación/verificación, tokens, archivos, diagnósticos, cuerpos clínicos completos ni secretos. Las versiones clínicas detalladas pertenecen a sus entidades protegidas.
- Reflejar resultados reales: no registrar éxito antes de confirmar la operación. Los intentos fallidos de seguridad pueden requerir persistencia independiente de la transacción rechazada.
- **Diseño de datos de bitácora:** contrastar con la versión vigente del DER y sus reglas de integridad; no asumir que una propuesta de ampliación de campos ya fue implementada o aprobada en una migración.

## 15. Validaciones, errores, persistencia y seguridad transversal

- Validaciones iniciales en React, **definitivas en Laravel**. Autorizar rol, titularidad, estados y precondiciones en cada endpoint, incluidos archivos.
- Respuestas HTTP/JSON consistentes: `401` sin sesión, `403` prohibido, `404` no encontrado, `409` conflicto, `422` validación, `429` demasiadas solicitudes, `500` fallo inesperado; contemplar CSRF `419` cuando corresponda.
- Centralizar la gestión de excepciones y no exponer stack traces, consultas SQL, credenciales ni datos sensibles.
- Reforzar unicidad de CI cuando corresponde, correo de acceso, cuenta única por Paciente y consulta única por cita mediante restricciones PostgreSQL, además de validaciones del servicio.
- Utilizar migraciones Laravel, Eloquent, consultas parametrizadas, transacciones y protección frente a condiciones de carrera para operaciones compuestas (citas, vinculación, versiones y publicación).
- Las sesiones se invalidan al cerrar sesión, desactivar una cuenta o recuperar contraseña, y en los cambios de contraseña según la política definida. No almacenar secretos de backend en variables `VITE_*`.
- Usar datos ficticios en desarrollo y pruebas. No exponer documentos médicos en archivos públicos ni registrar datos clínicos en logs técnicos.

## 16. Documentos de `docs/ai/` y mantenimiento incremental

| Archivo | Contenido y momento de actualización |
| --- | --- |
| `contexto-maestro.md` | Este resumen: revisar cuando cambie una **decisión aprobada**, stack, alcance, arquitectura o **estado real de implementación**. |
| `requerimientos.md` | RF/RN oficiales completos y ampliaciones aprobadas diferenciadas; no alterar su numeración. |
| `reglas-negocio.md` | Reglas verificables, políticas, restricciones, transiciones y excepciones aprobadas. |
| `casos-de-us.md` | Catálogo y fichas vigentes `CU-01`–`CU-30`, sin renumerar. |
| `diagrama-entidad-relacion.md` | DER con entidades, PK/FK, cardinalidades, restricciones; sincronizar con el esquema y las migraciones. |
| `analisis-clases.md` | **Después de cada CU terminado o pequeño grupo relacionado:** participantes, responsabilidades, colaboraciones, errores y resultados reales. |
| `diagrama-clases.md` | **Al completar un módulo:** integrar clases y relaciones al **único diagrama UML global acumulativo**; actualizar también si cambian clases anteriores. |

**Diseño antes de implementar, documentación viva durante el desarrollo:** preparar el análisis y diseño necesarios para el módulo que se va a construir; no esperar al final del sistema. Tras implementar y verificar los CU, ajustar análisis y modelos para representar fielmente la solución. No inventar clases ni presentar como implementado lo que está solo planificado.

## 17. Estado real del proyecto y trabajo pendiente

> **Importante:** este documento se creó a partir del examen, la documentación académica y las instrucciones de arquitectura. **No se ha auditado aquí un repositorio de código ejecutable ni se han ejecutado pruebas.** Por tanto, los módulos listados deben tratarse como **definidos documentalmente**, no como implementados o aprobados técnicamente.

| Elemento | Estado comprobable al crear este archivo |
| --- | --- |
| Catálogo CU-01 a CU-30 | Documentado en la versión académica de referencia |
| Reglas RF01–RF13 y RN01–RN14 | Incorporadas en la documentación académica; deben mantenerse conforme al examen |
| Arquitectura React/Laravel/PostgreSQL | Decisión aprobada; implementación no verificada |
| DER | Existe un diseño `.drawio` de referencia; revisar su **versión vigente** antes de generar migraciones |
| `requerimientos.md`, `reglas-negocio.md`, `casos-de-us.md` | Pendientes de creación o de comprobación en el repositorio real |
| `analisis-clases.md` y `diagrama-clases.md` | Elaboración/actualización progresiva al desarrollar CU y módulos; no suponer avance realizado |
| Modelo UML académico del apartado 4.5 | Reservado para desarrollo separado |
| Implementación y pruebas de CU | **No verificadas**; consultar el código, migraciones y resultados de pruebas antes de actualizar este estado |
| Despliegue | No verificado |

### 17.1. Cómo registrar avances reales

Cuando se complete y verifique trabajo, actualizar esta sección con **fecha, módulo/CU, estado** (`Pendiente`, `En desarrollo`, `Implementado` o `Verificado`), evidencia concreta (rutas/servicios/tests/migraciones) y documentación sincronizada. No confundir *implementado* con *probado* ni marcar un módulo completo por haber creado carpetas.

Ejemplo de registro **solo como plantilla, no como avance real**:

```text
Fecha: AAAA-MM-DD
Módulo / CU: CU-XX — Nombre
Estado: Pendiente | En desarrollo | Implementado | Verificado
Evidencia: archivos y pruebas ejecutadas (con resultados)
Docs actualizados: analisis-clases.md; diagrama-clases.md si cierra módulo; DER si hay cambios
Pendientes / limitaciones: ...
```

## 18. Protocolo de implementación y cierre de tareas

1. Inspeccionar repositorio, `AGENTS.md` y documentación aplicable antes de escribir código.
2. Identificar CU, RF/RN, actor, precondiciones, flujo principal, alternativas, permisos y datos afectados.
3. Comprobar dependencias con otros módulos y fuentes de verdad. Señalar decisiones faltantes o contradicciones; **no inventar reglas**.
4. Implementar el cambio mínimo conservando la arquitectura y contratos existentes.
5. Probar validaciones, permisos, seguridad, respuestas de error, integridad, concurrencia y persistencia según el riesgo de la tarea.
6. Actualizar `analisis-clases.md` al cerrar CU o grupos pequeños, `diagrama-clases.md` al cerrar módulos, y DER si cambian entidades o relaciones.
7. Actualizar este archivo **solo si cambió el contexto global o existe avance real verificable**.
8. Informar archivos modificados, pruebas efectivamente ejecutadas, documentación sincronizada y pendientes.

No hacer commits, pushes, despliegues, migraciones destructivas ni exponer secretos sin autorización expresa. Priorizar el alcance obligatorio y un diseño mantenible antes que abstracciones innecesarias.
