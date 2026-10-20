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
| **Usuarios y autenticación** | `CU-01` Iniciar sesión; `CU-02` Cerrar sesión; `CU-03` Administrar cuentas de usuario y su acceso; `CU-04` Registrar cuenta del Paciente y vincular su registro; `CU-05` Cambiar contraseña propia; `CU-06` Recuperar contraseña mediante correo electrónico. |
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

**Precisiones funcionales aprobadas:** PN02 / CU-03 incluye administrar únicamente el estado de acceso de cuentas de Pacientes previamente verificadas, además de las operaciones propias del personal. PN03 / CU-04 exige CI para crear o vincular una cuenta web; PN04 / CU-07 permite excepcionalmente fichas administrativas sin CI e incorporación posterior sobre la misma ficha. PN18 / CU-23 incluye mantener antecedentes clínicos durante una atención autorizada. Se conservan identificadores y ciclos; el título de CU-03, «Administrar cuentas de usuario y su acceso», se sincroniza por instrucción expresa del responsable del proyecto. El documento académico original no está disponible para cotejo directo. Estas precisiones están especificadas, no acreditan implementación.

## 8. Autenticación y cuentas

- Los cuatro roles inician sesión **solo con correo electrónico y contraseña**; el correo de acceso es único y verificado y es independiente del correo de contacto de la ficha del Paciente.
- Contraseña de **mínimo 12 caracteres**, almacenada únicamente mediante hash seguro de Laravel; nunca en texto plano ni duplicada en Paciente o Médico.
- **Tres errores consecutivos de contraseña** bloquean el acceso **10 minutos**; intentos durante el bloqueo no extienden su duración; al vencer se reinicia el contador, al igual que después de un acceso exitoso. Añadir límites de peticiones por origen.
- Diferenciar cuenta **pendiente**, **activa** y **desactivada** del **bloqueo temporal**. Una cuenta pendiente no entra a funciones normales; una desactivada no accede ni desde sesiones anteriores. Recuperar contraseña no reactiva cuentas desactivadas.
- El Administrador crea y administra cuentas **del personal** (Administrador, Recepcionista y Médico); vincula cuentas médicas con el registro profesional. El primer Administrador se establece mediante instalación segura.
- Decisión vigente posterior de instalación: el primer Administrador se crea **Activo**, con identidad y contraseña en el entorno privado, hash Laravel, correo marcado verificado por autorización de instalación y sin cambio obligatorio. No hay código, invitación ni comprobación automática del buzón para esta única instalación. Sustituye la decisión anterior de cuenta Pendiente; las cuentas posteriores del personal conservan su invitación ordinaria y las cuentas demo son identidades separadas. Véase §17.12.
- En CU-03 puede consultar datos administrativos mínimos y desactivar/reactivar el acceso de cuentas web de Pacientes existentes y previamente verificadas. No crea esas cuentas ni modifica Usuario, rol Paciente, contraseña, correo de acceso, vínculo original o datos históricos; tampoco activa cuentas pendientes ni añade permisos. Suspender/rehabilitar significa desactivar/reactivar con los estados existentes, sin crear Suspendida.
- Cuenta, ficha administrativa y bloqueo temporal son independientes. Desactivar una cuenta invalida sus sesiones; reactivarla desde Desactivada conserva el correo verificado y solo habilita el acceso administrativo: requiere nuevo login, no elimina un bloqueo vigente ni activa la ficha. Si la ficha sigue inactiva, no admite nuevas reservas. Ambas operaciones se auditan mediante referencias y metadatos seguros.
- Personal nuevo: **invitación de 24 horas**, un solo uso, enviada por Brevo; confirma control del correo y establece primera contraseña sin exigir una anterior inexistente. Reenvío controlado sobre la **misma cuenta**, invalidando la invitación anterior. Es distinto del restablecimiento administrativo posterior.
- `CU-03`: restablecimiento administrativo exclusivo del personal, previa identidad y sin conocer/exigir contraseña anterior; el sistema genera credencial temporal segura con vencimiento y uso controlado. Tras validarla, solo permite formulario/envío de contraseña definitiva y cierre, también restringidos en backend. Al completar: guardar hash, invalidar credencial, eliminar cambio obligatorio, revocar sesiones incompatibles, registrar metadatos seguros, cerrar la sesión restringida y exigir nuevo login normal. No activa cuentas desactivadas ni omite verificaciones o bloqueos. Soporte de TokenAcceso en §17.14; parámetros aprobados e implementación de fase 6 en §17.23/reglas §4.4 (20 caracteres, entrega presencial única, 30 minutos, reingreso con sesión restringida única y reemisión 60 s/cinco por hora).
- `CU-05`: cambio ordinario de los cuatro roles con sesión normal válida, comprobación de contraseña actual, nueva y confirmación, invalidación de sesiones anteriores y renovación segura de la actual. No incluye primera contraseña, recuperación ni finalización de la sesión restringida de CU-03.
- `CU-06`: recuperación para los cuatro roles mediante **enlace de 60 minutos y un solo uso** enviado a correo verificado, respuesta no enumerativa, nuevo enlace invalida el anterior, invalidación de sesiones y **nuevo inicio de sesión obligatorio**. El restablecimiento administrativo de CU-03 es exclusivo del personal tras identificación; gestionar el acceso de Pacientes no permite cambiar sus contraseñas. La alternativa para un Paciente sin acceso a su correo verificado continúa pendiente de definición.
- El Paciente puede cambiar su **correo de acceso** en `CU-08` confirmando contraseña y verificando un código en la nueva dirección; el anterior conserva vigencia hasta concluir el cambio. Cambiar correo de contacto no cambia automáticamente el correo de acceso.
- Brevo se usa para **correo de seguridad de cuentas**. No se usa para recordatorios de citas ni envío de información clínica.
- **Roles del personal, política aprobada:** Administrador ↔ Recepcionista; acceso al rol Médico solo con registro profesional legítimo de la misma persona. Para abandonar Médico, resolver antes citas futuras y obligaciones asistenciales incompatibles; si su resolución no puede verificarse con el modelo existente, rechazar la transición insegura e informar la limitación, sin inventar estados clínicos. La inactivación profesional necesaria se realiza expresamente por CU-10, coordinando con nuevas reservas; no se deriva automáticamente del rol. Conservar identidad, vínculo e historia, sin convertir Paciente ↔ personal ni reasignar Médico A → B, ni alterar citas/resultados automáticamente. Revocar sesiones, exigir nuevo login y auditar cambios.
- **Último Administrador, protección aprobada:** desactivaciones, cambios de rol y restablecimientos administrativos no pueden dejar sin al menos un Administrador Activo, con hash, activación/verificación aplicable completada y sin restricción exclusiva de cambio obligatorio. Incluir la propia cuenta y comprobar transaccionalmente el estado resultante incluso con operaciones simultáneas. Un bloqueo pasajero no equivale a desactivación ni excluye del cómputo administrativo, sin permitir login durante el bloqueo. No garantiza disponibilidad permanente ante olvidos/incidentes; conserva la instalación inicial activa de §17.12.

### 8.1. Registro de pacientes y doble verificación

**CI obligatorio en CU-04, por decisión propia del proyecto:** todo registro nuevo o vinculación web requiere CI no vacío y válido, con validación de presencia, formato y unicidad normalizada en frontend y servidor. RF02 conserva la unicidad del documento cuando corresponde, sin exigirlo universalmente a toda ficha administrativa. No permitir completar CU-04 sin CI; orientar a Recepción, sin documentos ficticios ni alternativas de vinculación web sin documento.

**Paciente nuevo:** completa CI obligatorio, datos y credenciales; el servidor comprueba que el CI no corresponda a una ficha previa que deba vincularse y que el correo sea único. Se abre registro **pendiente hasta 24 horas**; Brevo envía código de correo de **6 dígitos**, válido **10 minutos**, máximo **5 intentos incorrectos**. Solo tras verificarlo y revalidar unicidades se confirma atómicamente una **ficha única y cuenta vinculada de rol Paciente**, sin requerir código de Recepción ni crear datos clínicos ficticios.

**Paciente ya registrado por Recepción, con CI y sin cuenta:**

1. Recepción comprueba presencialmente identidad/CI y que la ficha existente no tenga cuenta web.
2. Emite un código de activación **alfanumérico aleatorio de 12 caracteres**, válido **24 horas**, máximo **5 intentos incorrectos**, de un solo uso; un nuevo código invalida el anterior.
3. El Paciente introduce **CI, código de Recepción, correo electrónico y contraseña** en la web.
4. El servidor valida y reserva de forma segura la vinculación; Brevo envía el **segundo código**, numérico de 6 dígitos, válido 10 minutos y máximo 5 intentos incorrectos.
5. El Paciente introduce el segundo código; la verificación completa **atómicamente** el vínculo con el mismo `id_paciente` y consume ambos códigos.
6. Se conservan citas, consultas, historial y resultados autorizados. Hasta finalizar no hay acceso a funciones protegidas.

**Reenvíos:** mínimo **60 segundos** entre envíos de código de correo y máximo **5 envíos por hora por proceso y dirección**, además de límites por origen. Sustituir el código invalida el anterior y **no prolonga** las 24 horas del proceso principal. Un código de Recepción vencido/agotado requiere nueva emisión tras comprobación presencial.

**Privacidad e integridad:** conocer el CI o verificar el correo no prueba, por sí solo, titularidad de una ficha anterior; mensajes públicos no deben revelar historia, nombres ni estado de cuenta. No duplicar Pacientes ni cuentas. Proteger reintentos y solicitudes simultáneas con restricciones y transacciones.

**Excepción administrativa de CU-07:** Recepción puede registrar sin CI, manteniendo `Paciente.documento_identidad = NULL` e identificador interno único, con revisión administrativa razonable de posibles fichas previas, sin documentos ficticios ni fusiones automáticas por semejanza de datos. La ficha conserva las operaciones presenciales autorizadas, incluidas citas conforme a sus reglas. Al obtener CI, el Paciente acude a Recepción: se identifica la ficha original, se comprueba el documento, el servidor valida formato/unicidad y se incorpora a la misma ficha con trazabilidad. No cambia su identificador ni contenido clínico; conserva citas, consultas, antecedentes, solicitudes, resultados, recordatorios e historial. No crea otra ficha o cuenta ni verifica correo automáticamente. La vinculación web posterior requiere el flujo ordinario con CI y ambos códigos. Resolver identidades dudosas queda pendiente del diseño administrativo; la ausencia de CI no se resuelve con una vía alternativa web.

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

- Los recordatorios son **automáticos, exclusivamente internos y persistidos en PostgreSQL**. Se generan **24 horas antes de la fecha y hora programadas** de la cita; si se registra exitosamente con menos de 24 horas de anticipación, se generan después de guardar correctamente la reserva en la base de datos o en la siguiente ejecución del programador de tareas, siempre que el aviso siga siendo válido y el intervalo no haya finalizado. Una reserva fallida no genera recordatorio. **No dependen del estado Confirmada ni modifican la cita**: una Programada puede generar su aviso y una Confirmada conservar los vigentes. Guardar la reserva mantiene su estado inicial Programada; Confirmada significa exclusivamente llegada presencial verificada por Recepción.
- La frecuencia específica del programador se definirá durante el diseño técnico; cada ejecución revalida las condiciones de vigencia. No se exige intervención manual de Paciente o Recepción ni sesión activa del Paciente. Los avisos pueden asociarse a su ficha y cita **sin cuenta web**; cuando la active, se muestran solo avisos propios autorizados de citas futuras vigentes.
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

> **Importante:** este documento se creó a partir del examen, la documentación académica y las instrucciones de arquitectura. La tabla siguiente conserva el estado documental inicial. La preparación técnica posterior comienza en **17.2**; el cierre actual del backend de Usuarios/autenticación está en **17.26**. Instalar el framework o definir un módulo documentalmente no acredita su implementación.

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

### 17.2. Preparación local del backend — 2026-10-18

**Alcance aprobado:** instalación y configuración, sin desarrollar ni marcar como implementado ningún CU-01 a CU-30. Laravel 13 confirmado por el usuario, backend `http://localhost:8000` y frontend futuro `http://localhost:5173`.

| Elemento | Estado verificado |
| --- | --- |
| PHP existente | 8.5.11; `curl`, `fileinfo`, `mbstring`, `openssl`, `pdo_pgsql` y `zip` habilitados; zona `America/La_Paz` |
| Composer | 2.10.3, instalado en el perfil del usuario; firma SHA-384 del instalador y acceso HTTPS comprobados |
| Laravel / Sanctum | Framework 13.37.0 y Sanctum 4.3.3; 105 paquetes instalados mediante Composer, incluidos los de desarrollo |
| PostgreSQL existente | 18.6, servicio en ejecución; base nueva `clinica_vida_salud` creada y conexión PDO/Laravel comprobada |
| Rol técnico PostgreSQL | `clinica_vida_salud_app`, propietario de la base, sin privilegios elevados de clúster; secreto aleatorio únicamente en `.env` local |
| Esquema | **Cero tablas de aplicación y cero migraciones ejecutadas**; otras bases conservadas |
| Arquitectura | `Clinica\` → `src/`; `routes/api.php` carga los archivos de rutas de presentación (organización modular vigente en §17.20); proveedor registra `src/data/database/migrations/` |
| Infraestructura SPA | Sanctum stateful, CORS con origen exacto y credenciales, cookie CSRF y sesiones cifradas en archivo |
| Servicios provisionales | Caché en archivo, cola síncrona y mailer en memoria; no requieren tablas ni envían correo |
| Pruebas | PHPUnit 12.5.40: **4 pruebas, 23 aserciones correctas**; sintaxis PHP de 23 archivos y Pint sobre 22 fuentes correctos |
| Comprobación HTTP real | `/up` → 200; `/sanctum/csrf-cookie` → 204; preflight CORS → 204; cookie de sesión HttpOnly y XSRF comprobadas |
| Dependencias / Git | Composer diagnose, validate, check-platform-reqs y audit correctos; secretos y artefactos ignorados; 19 archivos originales conservados por SHA-256 |

**Evidencia y comandos:** `backend/README.md`, `backend/composer.json`, `backend/composer.lock`, `backend/bootstrap/app.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/config/`, `backend/.env.example`, `backend/phpunit.xml` y `backend/tests/Feature/InfrastructureTest.php`.

**Límites y pendientes:** el modelo de Usuario, los guards/providers de autenticación, `AuthenticateSession`, login, registro, permisos y revocación efectiva de múltiples sesiones se implementarán junto a sus CU y el DER aprobado. Las sesiones en archivo son provisionales. No se copiaron modelos, migraciones ni frontend del esqueleto; no se ejecutaron migraciones ni se publicaron las de Sanctum. Brevo y React no están configurados. La base/rol aislados indicados para futuras pruebas de persistencia todavía no existen; las pruebas actuales no acceden a la base.

**Documentación de aquella preparación:** este registro y `backend/README.md`. Se comprobaron presentes `requerimientos.md`, `reglas-negocio.md` y `casos-de-us.md`. Al cerrar esa instalación todavía no se habían elaborado `analisis-clases.md`, `diagrama-clases.md` ni `diagrama-entidad-relacion.md`. El avance posterior del DER y de datos se registra en 17.3; no se declara cerrado un CU o módulo por preparar infraestructura.

### 17.3. Fase 0 de datos — 2026-10-19

**Alcance aprobado:** concretar el diseño físico inicial e integrar migraciones, seeders y PostgreSQL aislado para pruebas, antes de desarrollar las tablas de fase 1. Estado: **Verificado** para esta preparación técnica; ningún CU o módulo funcional completado.

| Elemento | Evidencia y resultado |
|---|---|
| DER lógico | `diagrama-entidad-relacion.md` conserva 28 entidades, 239 atributos y 43 relaciones |
| Diseño físico inicial | DER §6.6: mapeo explícito de las 28 tablas; criterios de tipos, IDs, FK, índices y timestamps; tipos, defaults y restricciones del primer bloque de cinco entidades |
| Integración Laravel | Ruta personalizada de migraciones comprobada; autoload PSR-4 estricto correcto; `composer seed` resuelve el seeder raíz de `src/data/database/seeders/`, todavía sin cargas |
| Tiempo y persistencia | Conexión PostgreSQL en UTC; aplicación/reglas locales en America/La_Paz; microsegundos y desplazamiento comprobados mediante escritura/lectura real |
| Entorno de pruebas | Base y rol independientes `clinica_vida_salud_test`, propietario sin privilegios elevados ni membresías; acceso público revocado; secretos aleatorios solo en `.env.testing` ignorado |
| Aislamiento de PHPUnit | Entorno/base PostgreSQL fijados; credenciales privadas; rechazo de configuración cacheada o identidad incompatible antes de hooks de pruebas; rechazo del usuario de desarrollo comprobado |
| Pruebas | 6 pruebas, 39 aserciones correctas; incluye las cuatro HTTP previas y dos de infraestructura PostgreSQL; migración auxiliar y seeder ejecutados con DDL transaccional revertido |
| Preservación | Desarrollo conserva cero tablas; prueba auxiliar sin tablas persistidas en la base aislada; catálogo de las otras bases conservado |

**Documentación sincronizada:** DER §6.6, `backend/README.md` y este registro. No se crean análisis ni diagramas de clases al no haberse completado CU o módulos.

**Siguiente fase:** `Rol`, `ControlIntentos`, `Consultorio`, `Especialidad` y `ConfiguracionClinica`: migraciones de dominio, modelos, seeders de catálogos/configuración y pruebas de sus restricciones. Después: usuarios/auditoría; pacientes/activación; médicos/horarios; citas/recordatorios; clínica; laboratorio, según el mapeo del DER.

**Pendientes:** decisiones físicas específicas de fases 2–7 y garantías transaccionales de sus flujos; retirar habilitación futura de Médico–Especialidad sin romper historial; cualquier operación posterior a publicación de laboratorio; procedimiento seguro del primer Administrador y datos concretos de consultorios. Las sesiones de aplicación siguen provisionalmente en archivo hasta implementar autenticación y revocación. Las pruebas de esta fase no acreditan concurrencia de negocio ni implementación de los CU.

### 17.4. Fase 1 de datos — 2026-10-19

**Alcance aprobado:** migraciones, modelos Eloquent, seeders y pruebas del primer bloque de cinco entidades, sobre el diseño físico de fase 0. Estado: **Implementado y verificado** como bloque de persistencia; ningún CU o módulo funcional completado.

| Elemento | Evidencia y resultado |
|---|---|
| Tablas | `roles`, `controles_intentos`, `consultorios`, `especialidades`, `configuracion_clinica`: 22 columnas de dominio, sin FK en este bloque |
| Migraciones | Cinco archivos `2026_10_19_000001` a `000005` en `backend/src/data/database/migrations/`; aplicados en desarrollo como lote 1 tras comprobar que la base estaba vacía |
| Modelos | `Rol`, `ControlIntentos`, `Consultorio`, `Especialidad`, `ConfiguracionClinica` en `backend/src/data/models/`, con PK/tablas explícitas, casts, timestamps propios y asignación masiva limitada |
| Integridad | PK/UQ/CHECK y NOT NULL/longitudes; especialidades únicas por `lower(btrim(nombre))`; configuración ID 1, zona fija y duración positiva |
| Carga inicial | Seeder raíz transaccional y tres seeders: 4 roles, 3 especialidades y 1 configuración; consultorios y controles de intentos vacíos |
| Idempotencia | Reejecución comprobada en pruebas y desarrollo; conserva IDs y datos existentes. En pruebas conserva especialidad inactiva/configuración modificada y revierte toda la carga ante un fallo |
| Pruebas | **33 pruebas, 166 aserciones correctas** en PostgreSQL; SQL directo inválido, defaults, normalización, modelos/casts/instantes y reversibilidad de migraciones en transacción aislada |
| Verificaciones complementarias | Autoload PSR-4 estricto y Pint correctos; cinco migraciones aplicadas según Artisan; base de pruebas sin tablas persistidas al terminar |
| DER | Modelo lógico conservado: 28 entidades, 239 atributos y 43 relaciones. Implementación física inicial registrada en §6.7 |

**Documentación sincronizada:** `diagrama-entidad-relacion.md` §6.7, `backend/README.md` y este registro. Los CU administrativos y de autenticación aún requieren sus capas de negocio, presentación, autorización y auditoría; no se declaran CU-01, CU-09, CU-11 o CU-12 terminados ni se crean todavía sus análisis/diagrama de clases como módulos completos.

**Siguiente fase de datos:** Usuario, Sesion, TokenAcceso y Bitacora. Concretar sus tipos/defaults/restricciones y soporte de sesiones nativas antes de implementar. Permanecen las decisiones funcionales pendientes ya registradas, el procedimiento seguro del primer Administrador y los datos concretos de consultorios. No se crearon cuentas ni datos de pacientes en esta fase.

### 17.5. Fase 2 de datos — 2026-10-19

**Alcance:** Usuario, Sesion, TokenAcceso y Bitacora: diseño físico, migraciones, modelos e integración persistente con Laravel. Estado: **Implementado y verificado** como bloque de datos; CU-01–CU-06 y CU-30 continúan pendientes de desarrollo funcional.

| Elemento | Evidencia y resultado |
|---|---|
| Migraciones | `2026_10_19_000006` a `000009`, aplicadas como lote 2; datos de fase 1 preservados |
| Esquema acumulado | 9 tablas de dominio, 72 columnas y 6 FK, más el repositorio `migrations`; modelo lógico global de 28 entidades/239 atributos/43 relaciones conservado |
| Modelos | Usuario, TokenAcceso y Bitacora en `src/data/models/`; relaciones con Rol/Usuario y autorreferencia; Sesion usa el manejador nativo sin modelo adicional |
| Autenticación técnica | Guard `web`, proveedor `usuarios`, modelo y columnas reales registrados en `config/auth.php`; Sanctum consulta ese guard; todavía sin endpoints de acceso |
| Sesiones | Driver local database con contenido cifrado; escritura/lectura anónima y con Usuario, expiración, destrucción y eliminación por Usuario comprobadas; revocación automática y AuthenticateSession pendientes de los CU |
| Usuarios/tokens | Unicidad normalizada de correo, estados/defaults y FK; hash de token único, propósitos y vigencia básica. Consumo único y coordinación de credenciales pendientes de Services |
| Bitácora | FK de responsable/objetivo/evento anterior, checks e índices; triggers contra UPDATE/DELETE/TRUNCATE y referencias adelantadas/cíclicas. Emisión automática, autor auténtico, metadatos seguros y consulta autorizada pendientes |
| Pruebas | 68 pruebas, 335 aserciones correctas; suite anterior y UsuariosAuditoriaTest. DDL/datos se revierten en base aislada; reversibilidad de migraciones/funciones comprobada |
| Comprobación adicional | Kernel HTTP local: CSRF 204 con sesión anónima cifrada persistida; sesión auxiliar destruida por su ID exacto |
| Calidad | Autoload PSR-4 estricto, Pint y sintaxis PHP correctos; sin seeders de cuentas/tokens/sesiones/eventos ni datos ficticios en desarrollo |

**Documentación sincronizada:** DER §6.8, `backend/README.md` y este registro. No se elaboran todavía `analisis-clases.md` ni `diagrama-clases.md`: ningún CU o módulo funcional se considera terminado por este bloque de persistencia.

**Siguiente fase:** Paciente, ContactoEmergencia, PreferenciaPaciente, CodigoActivacionPaciente, RegistroPacientePendiente y VerificacionCorreo. Continúan pendientes los flujos completos de autenticación, bloqueo/revocación, invitación/recuperación, auditoría, el procedimiento seguro del primer Administrador y las decisiones funcionales abiertas ya registradas.

### 17.6. Fase 3 de datos — 2026-10-19

**Alcance:** Paciente, ContactoEmergencia, PreferenciaPaciente, CodigoActivacionPaciente, RegistroPacientePendiente y VerificacionCorreo. Estado: **persistencia implementada y verificada**; CU-04, CU-07 y CU-08 no se consideran completos.

| Elemento | Evidencia y resultado |
|---|---|
| Migraciones/modelos | Seis migraciones `2026_10_19_000010`–`000015`, aplicadas como lote 3; seis modelos de `src/data/` y relaciones con Usuario |
| Acumulado | 15 tablas de dominio, 128 columnas y 15 FK, más `migrations`; 28 entidades/239 atributos/43 relaciones lógicas conservadas |
| Ficha y preferencias | Cuenta y CI opcionales únicos, código único; contacto/preferencia opcionales únicos; datos de preferencia explícitos sin defaults funcionales |
| Normalización | CI recortado solo en espacios U+0020 exteriores, sin alterar signos/letras/ceros; correos propuesto/destino según criterio de Usuario |
| Proceso pendiente | Nuevo/Vinculacion coherentes con FK, hash/datos temporales requeridos al estar abierto; cuatro UQ parciales por ficha/código/CI/correo |
| Exclusividad y vencimiento | Reserva conservada hasta completar/invalidar explícitamente; un vencido debe cerrarse transaccionalmente antes de otro proceso. Sin predicados dependientes del reloj |
| Verificación | XOR de FK compatible con propósito, contadores 0–5 y vigencia básica; consumo, reenvío y reglas entre tablas pendientes de los Services |
| Pruebas | 111 pruebas, 585 aserciones correctas; PacientesActivacionTest y ReservaActivacionConcurrenteTest más suite anterior |
| Concurrencia | Dos conexiones PostgreSQL distintas comprueban espera, rechazo tras COMMIT y aceptación tras ROLLBACK para las cuatro claves; esquema auxiliar aislado eliminado al terminar |
| Preservación | Nueve tablas previas comparadas antes/después sin diferencias; nuevas tablas vacías, sin seeders ni identidades de ejemplo en desarrollo |
| Calidad | Autoload PSR-4 estricto, Pint, sintaxis PHP y diff comprobados; base de pruebas sin tablas ni esquemas auxiliares persistidos |

**Documentación sincronizada:** DER §6.9, `backend/README.md` y este registro. Análisis/diagrama de clases siguen pendientes del desarrollo funcional; ningún CU o módulo se declara terminado por este bloque.

**Pendientes del bloque:** rol/identidad/vínculos, formato y autorización de datos, claves permitidas de privacidad, reintentos con contexto protegido, reemisión y cierre de procesos/códigos, limpieza de secretos, correo Brevo y límites de envío, consumo atómico y coordinación del registro web con altas administrativas concurrentes. La exclusividad de las reservas probada no sustituye esos flujos. **Siguiente fase de datos:** Medico, MedicoEspecialidad y HorarioAtencion; se conserva la decisión pendiente sobre retiro de habilitación futura del par Médico–Especialidad.

### 17.7. Fase 4 de datos — 2026-10-19

**Alcance:** Medico, MedicoEspecialidad y HorarioAtencion. Estado: **persistencia implementada y verificada**; no se completan CU-09–CU-12.

| Elemento | Evidencia y resultado |
|---|---|
| Migraciones/modelos | Tres migraciones `2026_10_19_000016`–`000018`, aplicadas como lote 4; Medico, pivote MedicoEspecialidad y HorarioAtencion en `src/data/` |
| Acumulado | 18 tablas de dominio, 146 columnas y 20 FK, más `migrations`; 28 entidades/239 atributos/43 relaciones lógicas conservadas |
| Médico | Cuenta opcional única, consultorio opcional compartible, código único y actividad independiente; sin nuevas unicidades de registro profesional o correo |
| Especialidades del Médico | PK compuesta y dos FK restrictivas; N:M con pivote Eloquent en ambas direcciones, sin ID sustituto, timestamps o vigencias |
| Horarios | Día 1–7 y fin posterior al inicio, TIME(0) local, varios intervalos por Médico/día; no agrega consultorio duplicado |
| Relaciones | Usuario–Medico 1:0..1, Consultorio–Medico 1:N, Medico–Especialidad N:M y Medico–Horario 1:N |
| Pruebas | 140 pruebas, 752 aserciones correctas; MedicosHorariosTest más suite anterior. PK/FK/UQ/CHECK, pivote, intervalos, campos protegidos, estados y rollback |
| Preservación | Quince tablas previas comparadas antes/después sin diferencias; tres nuevas vacías, sin nuevos seeders ni datos de ejemplo en desarrollo |
| Calidad | Autoload PSR-4 estricto, Pint, sintaxis PHP y diff comprobados; base de pruebas sin tablas ni esquemas auxiliares persistidos |

**Documentación sincronizada:** DER §6.10, `backend/README.md` y este registro. Análisis/diagrama de clases siguen pendientes del desarrollo funcional; ningún CU/módulo se declara terminado por la persistencia.

**Pendientes:** autorización administrativa, rol/vínculo de cuenta, al menos una especialidad por alta, formato de datos, consultorio/duración válidos y protección concurrente de horarios/asignaciones frente a solapamientos y cambios que afecten citas/avisos. Los CHECK y FK no implementan esos flujos. Se conserva pendiente la política de retiro de habilitación futura del par Médico–Especialidad. **Siguiente fase de datos:** Cita, CambioCita y Recordatorio.

### 17.8. Fase 5 de datos — 2026-10-19

**Alcance:** Cita, CambioCita y Recordatorio. Estado: **persistencia implementada y verificada**; CU-13–CU-21 y CU-28 no se consideran completados.

| Elemento | Evidencia y resultado |
|---|---|
| Migraciones/modelos | Tres migraciones `2026_10_19_000019`–`000021`, aplicadas como lote 5; Cita, CambioCita, Recordatorio y relaciones inversas en `src/data/` |
| Acumulado | 21 tablas de dominio, 180 columnas y 27 FK, más `migrations`; 28 entidades/239 atributos/43 relaciones lógicas conservadas |
| Cita | Código único, FK compuesta al par Médico–Especialidad, cinco estados y modalidad Presencial; duración efectiva coherente con inicio/fin y ubicación histórica explícita |
| Concurrencia de ocupación | Dos exclusiones GiST por Médico/consultorio, intervalos `[inicio,fin)`; solo Cancelada libera ocupación. Atendida/Inasistencia conservan su intervalo |
| Infraestructura | btree_gist confiable, disponible y con privilegios inspeccionados; instalada en public por migración. La inversa conserva la extensión compartida |
| Historial | CambioCita con estados/snapshots y autor; triggers rechazan UPDATE/DELETE/TRUNCATE |
| Avisos | UQ cita/versión y FK restrictiva a Cita; destinatario derivado sin exigir cuenta web; generación automática todavía pendiente |
| Pruebas | 178 pruebas, 976 aserciones correctas; CitasRecordatoriosTest/CitasConcurrentesTest más suite anterior |
| Concurrencia verificada | Dos conexiones/PID comprueban espera/rechazo de reservas y reprogramaciones por ambos recursos, aceptación tras rollback y UQ concurrente de recordatorios |
| Preservación/calidad | Dieciocho tablas previas comparadas sin diferencias; tres nuevas vacías. Autoload estricto, Pint, sintaxis PHP, diff e aislamiento comprobados |

**Documentación sincronizada:** DER §6.11, `backend/README.md` y este registro. Ningún CU/módulo se declara terminado; análisis/diagrama de clases siguen pendientes de esos flujos funcionales.

**Pendientes:** roles/titularidad, fecha/horarios/estados activos, transiciones y atención real, identidad/versión al reprogramar, cambios y auditoría coordinados con avisos, modificación concurrente de horarios/asignaciones y generación/consulta de recordatorios con preferencias. El conflicto `23P01` se traducirá a HTTP 409 en el límite de presentación cuando existan endpoints. No se fijó frecuencia del programador ni preferencia ausente. **Siguiente fase de datos:** ConsultaMedica, VersionConsulta y VersionAntecedente.

### 17.9. Fase 6 de datos — 2026-10-19

**Alcance:** ConsultaMedica, VersionConsulta y VersionAntecedente. Estado: **persistencia implementada y verificada**; CU-22/CU-23 siguen pendientes de implementación funcional.

| Elemento | Evidencia y resultado |
|---|---|
| Migraciones/modelos | Tres migraciones `2026_10_19_000022`–`000024`, aplicadas como lote 6; tres modelos y relaciones inversas en Cita, Paciente y Usuario |
| Acumulado | 24 tablas de dominio, 210 columnas y 33 FK, más `migrations` y dos exclusiones de citas; modelo lógico de 28 entidades/239 atributos/43 relaciones conservado |
| Consulta | Código y cita únicos; inicio real opcional, independiente de creación; Paciente/Médico derivados de Cita |
| Versiones | Número positivo único por consulta o Paciente, autor/fecha explícitos, snapshots completos; origen de antecedentes obligatorio |
| Conservación | Triggers PostgreSQL rechazan UPDATE/DELETE/TRUNCATE de versiones, incluidos metadatos; seis FK restrictivas |
| Modelos | Asignación masiva protegida, instantes inmutables con zona/microsegundos y contenido clínico oculto en serialización genérica |
| Pruebas | 210 pruebas, 1214 aserciones correctas; ConsultasVersionesTest/ConsultasConcurrentesTest y suite anterior |
| Concurrencia verificada | Dos conexiones/PID: espera, rechazo UQ tras COMMIT y aceptación tras ROLLBACK de consulta duplicada y números de versión competidores; antecedentes de distintas consultas compiten por el mismo Paciente |
| Atomicidad | Fallo revierte consulta y ambas clases de versiones; guardar clínica no cambia estado de cita ni deduce inicio real |
| Preservación/calidad | Veintiuna tablas anteriores comparadas sin diferencias; nuevas vacías, sin seeds clínicos. Autoload estricto, Pint, sintaxis PHP, diff e identidad/limpieza de pruebas comprobados |

**Documentación sincronizada:** DER §6.12, `backend/README.md` y este registro. Reversión/reaplicación de las veinticuatro migraciones comprobada solo en pruebas aisladas; base de pruebas sin tablas public, esquemas auxiliares ni btree_gist residual. Las secciones lógicas 2–4 del DER se compararon en UTF-8 con HEAD, sin diferencias.

**Pendientes:** autorización de lectura/escritura y relación asistencial, coincidencia Paciente–Consulta–Cita y autor Médico responsable, atención real y rechazo de cambios administrativos incompatibles aunque inicio sea NULL, contenido y motivo de corrección, asignación de números y revalidación bajo bloqueo del padre, auditoría segura. Las UQ evitan números duplicados, pero no implementan por sí solas coordinación lógica de ediciones. `analisis-clases.md` y `diagrama-clases.md` siguen pendientes del desarrollo de CU/módulos. **Siguiente fase de datos:** SolicitudLaboratorio, VersionResultado, DocumentoLaboratorio y RevisionResultado; sin anticipar operaciones posteriores a publicación.

### 17.10. Fase 7 de datos — 2026-10-19

**Alcance:** SolicitudLaboratorio, DocumentoLaboratorio, VersionResultado y RevisionResultado. Estado: **persistencia implementada y verificada**; CU-24–CU-27 siguen pendientes de implementación funcional.

| Elemento | Evidencia y resultado |
|---|---|
| Migraciones/modelos | Cuatro migraciones `2026_10_19_000025`–`000028`, aplicadas como lote 7; cuatro modelos y relaciones inversas en ConsultaMedica, Usuario y Medico |
| Acumulado final de datos | 28 tablas de dominio, 239 columnas y 43 FK, más `migrations` y dos exclusiones de citas; corresponden al inventario lógico del DER |
| Pertenencia | Dos FK compuestas inmediatas: versión actual de la misma solicitud y revisión publicada de esa versión exacta; se añaden al terminar la última migración |
| Estados/publicación | Pendiente, En revisión y Disponible; punteros coherentes con estado. Disponible rechaza modificaciones y eliminación ordinarias de la solicitud |
| Conservación | Documento, versión y revisión inmutables por triggers contra UPDATE/DELETE/TRUNCATE; origen previo de versión, incluso entre solicitudes, sin ciclos |
| Archivos | Solo metadatos; ruta única, tamaño BIGINT positivo y SHA-256 de 64 hexadecimales. Sin carga real, ruta pública ni validación de bytes implementadas |
| Pruebas | 259 pruebas, 1609 aserciones correctas; LaboratorioPersistenciaTest/LaboratorioConcurrenteTest y suite anterior |
| Concurrencia verificada | Dos conexiones/PID: UQ de ruta/número frente a COMMIT/ROLLBACK; corrección/publicación concurrentes con revalidación SQL de estado/versión y rechazo de revisión desactualizada |
| Atomicidad | Fallo revierte metadatos, versión y punteros de corrección, o revisión/publicación; la coordinación de bytes externos se implementará con CU-25 |
| Preservación/calidad | Veinticuatro tablas previas sin diferencias; nuevas vacías, sin semillas/archivos clínicos. Autoload estricto, Pint, sintaxis PHP, diff e identidad/limpieza de pruebas comprobados |

**Documentación sincronizada:** DER §6.13, `backend/README.md` y este registro. Las veintiocho migraciones se revirtieron/reaplicaron solo en transacción aislada; base de pruebas sin tablas public, esquemas auxiliares ni btree_gist residual. Secciones lógicas 2–4 del DER idénticas a HEAD en comparación UTF-8 directa.

**Cierre de las fases de datos 0–7:** tablas, columnas y FK del inventario consolidado están creadas en desarrollo. Esto no completa RF, PN ni CU: faltan Services, Repositories, Controllers, autorización, validación funcional y frontend. En laboratorio siguen pendientes Médico solicitante, cuenta/rol/sesión, devolución previa para corregir, validación y entrega privada de bytes, decisión Autorizar de la revisión publicada, numeración/revalidación bajo bloqueo, auditoría segura y coordinación de archivos/transacciones. Las pruebas SQL de carreras no son una API funcional.

**Siguiente trabajo:** iniciar negocio/API, priorizando usuarios y autenticación y definiendo el procedimiento seguro del primer Administrador al abordar su creación. `analisis-clases.md` y `diagrama-clases.md` siguen pendientes de desarrollar y cerrar CU/módulos. Conservan su estado pendiente la política futura de retiro Médico–Especialidad, cualquier procedimiento posterior a publicación y la alternativa para Paciente sin acceso a su correo verificado.

### 17.11. Población mediante seeders — 2026-10-19

**Estado al cerrar la población original:** carga inicial ampliada y demostración ficticia aplicadas y verificadas. Se mantiene el esquema de 28 tablas, 239 columnas y 43 FK; sin nuevas migraciones ni CU funcionales completados. La preparación Pendiente descrita abajo fue sustituida por la excepción de instalación de §17.12.

- `ConsultorioSeeder` agrega tres consultorios iniciales CONS-01–CONS-03 mediante el seeder ordinario, conservando registros existentes. Roles/especialidades/configuración mantienen su carga idempotente.
- `AdministradorInicialSeeder`, mediante `composer seed:admin`, valida identidad privada de `config/seeders.php`, normaliza correo y prepara una cuenta Pendiente, sin hash/verificación/token. Reejecuciones no cambian estado o credenciales y no promueven otros roles ni crean otro Administrador de instalación. Transacción y bloqueo PostgreSQL compartido con demo; evento SISTEMA sin secretos. **No ejecutado en desarrollo por falta de correo/nombres reales proporcionados**; probado con identidad ficticia aislada. Invitación/verificación siguen pendientes.
- `DemostracionSeeder`, mediante `composer seed:demo`, solo permite la base/rol del proyecto en local o testing. Códigos DEMO-VS, correos example.test y documentación explícitamente ficticios. Seis cuentas simulan activación/verificación solo para el dataset; no verifican correo real ni envían mensajes. Contraseña opcional del entorno privado (mínimo 12); al omitirse se usa secreto aleatorio no divulgado. No sobrescribe hashes al repetir.
- Dataset: 3 consultorios demo adicionales, 3 médicos/especialidades, 21 horarios compatibles, 5 pacientes (uno con cuenta), 5 citas en los cinco estados, 9 cambios con actores adecuados, 1 consulta, 2 versiones de consulta/antecedentes, 3 solicitudes, 3 documentos/versiones de resultado, 2 revisiones y 1 evento de instalación del dataset. Sin sesiones, códigos, tokens, preferencias inventadas ni avisos automáticos.
- Tres PDF ficticios legibles en disco privado, sin enlaces públicos y con MIME/tamaño/SHA correctos; devolución y sustitución conservan historia, y Disponible apunta a su revisión Autorizar. Se compensan archivos nuevos ante fallo durante la carga, sin tratar archivos como parte del rollback SQL.
- Carga y segunda ejecución en desarrollo: datos previos conservados y filas/bytes idénticos al repetir. Hay 6 consultorios en total; los 6 usuarios son ficticios y distintos del Administrador de instalación pendiente de configurar. Fechas se fijan al cargar por primera vez y no se desplazan al repetir.
- **270 pruebas, 1716 aserciones correctas**, con `PoblacionDatosTest` y suite anterior. Idempotencia, conservación, rol/origen/publicación, estados, fechas, hashes/PDF, fallos con compensación y rechazo de usos indebidos comprobados. Composer, autoload estricto, Pint, sintaxis y diff correctos.

**Documentación:** `backend/README.md` detalla comandos, configuración y limitaciones; DER §6.14 registra población sin alterar modelo lógico. Análisis/diagrama de clases siguen pendientes de CU/módulos funcionales. Los seeders no sustituyen autorización, flujos de estados, invitación, login ni entrega autorizada de documentos.

### 17.12. Primer Administrador activo por instalación — 2026-10-19

**Decisión explícita aprobada:** el seeder completa la instalación del primer Administrador sin código ni invitación. Su identidad y contraseña proceden únicamente del entorno privado; se marca Activa, con fecha de correo verificado por autorización del responsable de instalación y sin cambio de clave obligatorio. Esta marca no acredita comprobación del buzón. Se sustituye la decisión Pendiente de §17.11; el alta ordinaria de otras cuentas del personal conserva invitación de 24 horas y un solo uso.

**Condiciones funcionales de la instalación controlada:** procedimiento local reservado al responsable autorizado, correo de acceso único e identificador de Usuario con rol Administrador y permisos fijos. No usar formulario público, credenciales predeterminadas ni contraseñas legibles en archivos versionados, seeders, logs o documentación. La creación debe ser idempotente, sin sobrescribir cuentas/credenciales existentes ni reutilizarse como alta general de Administradores. La autorización administrativa de correo verificado no es prueba técnica del control del buzón mediante enlace/código o Brevo; se recomienda usar un correo legítimo bajo control del responsable para CU-06. No aplica a Pacientes ni omite CU-04 o recuperación ordinaria. La forma de suministrar credenciales pertenece al diseño técnico; la solución existente se describe a continuación. El acceso normal requiere CU-01, verificación de hash, sesiones, estado y autorización funcionando, no solo la cuenta en PostgreSQL.

**Implementación aplicada:** `AdministradorInicialSeeder` valida contraseña mínima de 12 caracteres, usa `Hash::make` y confirma cuenta y evento SISTEMA `INSTALACION_ADMIN_INICIAL` en una transacción con bloqueo PostgreSQL. El evento identifica método SEEDER y AUTORIZACION_INSTALACION, sin correo, hash ni contraseña. Las reejecuciones preservan cuentas existentes, incluso pendientes/desactivadas, y no restablecen credenciales. No promueve otro rol ni crea otra identidad de instalación.

**Desarrollo comprobado:** correo proporcionado por el usuario y nombres Administrador / Vida Salud configurados solo en `.env`. Contraseña aleatoria de 32 caracteres almacenada allí como `CLINICA_ADMIN_PASSWORD`; hash comprobado sin divulgar la clave. Cuenta activa/verificación de instalación/evento confirmados; segunda ejecución idéntica y datos previos preservados. Total: 7 usuarios (6 demo y 1 inicial) y 2 eventos. No se enviaron correos ni se emitieron tokens.

**Verificaciones:** suite completa **274 pruebas, 1738 aserciones correctas**; hash/estado/verificación de instalación, contraseña ausente/corta, auditoría sin secretos, rollback ante fallo de evento y conservación de credenciales/desactivación al repetir. Pint, sintaxis de tres PHP afectados y diff correctos; `.env` ignorado por Git.

**Alcance:** instalación lista; CU-01–CU-06 siguen pendientes de negocio/API. Documentación sincronizada: README, reglas VS-AUT-06/07, precisiones de requerimientos §5.1, excepción CU-03 y DER §6.14. Sin cambios del modelo lógico ni migraciones. Análisis y diagrama global de clases siguen pendientes del desarrollo funcional.

### 17.13. Sincronización documental de instalación y CI — 2026-10-19

**Decisiones aprobadas sincronizadas, sin implementación funcional nueva:** se conserva el primer Administrador activo de §17.12 y se distingue CI obligatorio en CU-04 de ficha administrativa excepcional sin CI en CU-07, con incorporación posterior sobre el mismo identificador e historial. La denominación de CU-03 se actualiza por instrucción expresa. No se alteran RF01–RF13, RN01–RN14, CU-01–CU-30, PN01–PN24, roles, estados ni el inventario del DER de 28 entidades, 239 atributos y 43 relaciones.

**Documentación sincronizada:** contexto, requerimientos, reglas, casos de uso, interpretación funcional del DER y los tres AGENTS. No se modifica software, configuración, datos ni modelo lógico. Los registros anteriores de fases se conservan como historia; las referencias a primer Administrador pendiente o a su procedimiento todavía por definir quedan superadas por §17.12, y las formulaciones anteriores de CI opcional no autorizan CU-04 sin documento. Esto no completa CU-01, CU-03, CU-04 ni CU-07; análisis y diagrama de clases siguen pendientes del desarrollo funcional.

**Pendientes al cerrar esta sincronización de CI:** transición de roles, último Administrador y finalización de sesión restringida todavía no estaban aprobados en ese momento; quedan sustituidos por las decisiones posteriores de §17.14. Continúan pendientes los detalles técnicos de credenciales temporales, retiro de asociaciones Médico–Especialidad, resolución administrativa de identidades dudosas, operaciones posteriores a publicación y alternativa para Paciente sin acceso al correo verificado. La vinculación web sin CI no es alternativa pendiente: no está permitida por la decisión vigente.

### 17.14. Políticas de CU-03/CU-05 y ampliación de TokenAcceso — 2026-10-19

**Aprobación funcional posterior:** se consolidan los procedimientos independientes de restablecimiento administrativo temporal del personal (CU-03), cambio ordinario con contraseña actual/sesión normal (CU-05), recuperación por correo sin contraseña anterior, 60 minutos y uso único (CU-06), e invitación para primera contraseña (CU-03). Se aprueban cierre de la sesión restringida con nuevo login, transiciones del personal con preservación profesional, rechazo de obligaciones incompatibles/no verificables y protección transaccional del último Administrador, incluidos restablecimientos. Son precisiones del proyecto, no RF/RN literales ni funciones implementadas. Se mantienen el primer Administrador activo, CI web obligatorio y ficha administrativa excepcional sin CI.

**Ampliación mínima autorizada:** añadir `CredencialTemporal` a `TokenAcceso.proposito`, junto a `InvitacionPersonal` y `RecuperacionClave`, reutilizando hash y fechas de emisión/expiración/consumo/invalidación. La migración nueva `2026_10_19_000029_allow_temporary_credentials_in_tokens_acceso` amplía únicamente el CHECK PostgreSQL; la original se conserva. El modelo Eloquent existente ya maneja esos campos y no necesita duplicación ni nuevas columnas. El inventario sigue en 28 entidades, 239 atributos y 43 relaciones; cambia el catálogo de un atributo, documentado en DER §§3.4 y 6.15, sin editar `.drawio`.

**Límites vigentes antes de implementar el flujo temporal de CU-03:** concretar duración de la credencial, generación y entrega, intentos/reemisión/usos, momento de consumo y tratamiento de contraseña anterior al emitir; coordinar revocación, expiración y solicitudes de cambio/recuperación concurrentes. No hereda plazos de invitación o recuperación. No existen estados de cierre clínico nuevos: sin evidencia suficiente de resolución se rechaza el cambio de rol. Rol/actividad profesional siguen separados y CU-15 no autoriza trasladar la cita a otro Médico. La corrección extraordinaria de identidad y retiro Médico–Especialidad continúan pendientes. CU-01/CU-02 pueden abordarse con los controles definidos, impidiendo acceso normal ante cambio obligatorio mientras se desarrolla su flujo específico.

**Verificación y aplicación:** migración `000029` aplicada en desarrollo como **lote 8**, comprobando antes/después igualdad de filas y definiciones de columnas en las 28 tablas; permanecen 239 columnas, 7 usuarios, 2 eventos y cero tokens. Suite completa en PostgreSQL aislado: **280 pruebas y 1772 aserciones correctas**, incluidas reversión/reaplicación, catálogo, vigencia obligatoria, hash único y preservación del historial. Pint y sintaxis de los dos PHP afectados correctos. Detalle en DER §6.15 y README.

**Alcance real:** documentación funcional y soporte persistente del nuevo propósito; no se implementan emisión/entrega de credenciales, login/logout, controles de último Administrador, transiciones, Services ni API por este avance. Ningún CU queda completado. Análisis/diagrama de clases siguen pendientes de los CU/módulos funcionales.

### 17.15. Fase 0 de Usuarios y autenticación — diseño de CU-01/CU-02 (2026-10-19)

**Estado:** diseño del primer bloque preparado en `analisis-clases.md`, creado como documento incremental. CU-01/CU-02 siguen pendientes de implementación/verificación funcional; el documento distingue modelos/framework existentes, clases previstas, contratos y pruebas todavía por construir.

**Inspección real:** PHP 8.5.11, Laravel 13.37.0 y Sanctum 4.3.3; guard web/proveedor Usuario, sesiones database cifradas y configuración local coherente para backend `http://localhost:8000` y React futuro `http://localhost:5173`. Las rutas registradas son `/up` y `/sanctum/csrf-cookie`; no hay endpoints de negocio. Frontend todavía contiene solo su AGENTS. AuthenticateSession está sin integrar y el mailer efectivo es array; Brevo sigue siendo el proveedor aprobado para seguridad de cuentas, con integración en fase 4.

**Diseño técnico de la primera entrega:** POST `/api/auth/login`, GET `/api/auth/me` y POST `/api/auth/logout`; Resource público uniforme, IDs BIGINT como cadenas, control exacto de origen y CSRF, errores JSON centralizados, sesión normal y logout idempotente. Login denegado mantiene respuesta genérica, incluido bloqueo de cuenta; 429 corresponde al limitador técnico independiente. Parámetros iniciales del diseño: 20 solicitudes/minuto por IP y 5/minuto por correo+IP, con ControlIntentos y claves HMAC; no son RF/RN ni alteran tres errores/diez minutos.

**Punto de coordinación identificado:** StartSession guarda normalmente después del Controller. La implementación debe guardar sesión autenticada antes de liberar la transacción/bloqueo de Usuario, coordinar revocaciones con la misma cuenta, integrar AuthenticateSession e inicializar su huella. Probar que una escritura tardía no recrea sesión revocada; no basta borrar sesiones sin verificar ese ciclo. Contadores/bloqueos y auditoría de fallos esperados deben confirmarse antes de convertir el resultado en excepción HTTP.

**Plan inicial de trabajo** (independiente de las fases históricas de datos; sustituido por la decisión backend primero de §17.17):

| Fase | Entrega | Estado al cerrar fase 0 |
| --- | --- | --- |
| 0 | Diseño del bloque CU-01/CU-02 | Preparado; contratos y matriz en analisis-clases. |
| 1 | Autenticación del backend, sesiones y controles de CU-01/CU-02 | Pendiente. |
| 2 | Base React e integración de login/restauración/logout | Pendiente. |
| 3 | Cambio ordinario de contraseña, CU-05 | Pendiente. |
| 4 | Alta e invitación del personal, parte de CU-03; integración Brevo | Pendiente. |
| 5 | Estados de acceso, roles y último Administrador, parte de CU-03 | Pendiente. |
| 6 | Recuperación por correo de los cuatro roles, CU-06 | Pendiente. |
| 7 | Restablecimiento administrativo temporal del personal, CU-03 | Pendiente de parámetros y desarrollo. |
| 8A / 8B | CU-04: Paciente nuevo / vinculación a ficha existente | Pendiente; 8B requiere emisión autorizada del código en CU-07. |
| 9 | Integración y cierre del módulo, incluido diagrama global acumulativo | Pendiente. |

La documentación se actualiza por CU/grupo a lo largo de las fases, no solo en fase 9. No se presenta el módulo como terminado antes de integrar sus recorridos completos. El diseño de fase 0 no requiere cambios del DER ni crea clases PHP o pantallas funcionales. La siguiente tarea es implementar fase 1 contra los criterios definidos; parámetros temporales/Brevo/CU-07 se concretan en sus propias fases.

**Comprobaciones de esta fase:** listado de rutas y configuración efectiva leídos sin exponer secretos; `InfrastructureTest` con **4 pruebas y 23 aserciones correctas** (infraestructura health/CORS/CSRF, no pruebas funcionales de CU). Ejemplos JSON y estructura documental revisados; `git diff --check` correcto. Documentos modificados: análisis de clases, este contexto y referencia de estado en README del backend. El diagrama global sigue pendiente del cierre del módulo.

### 17.16. Fase 1 de Usuarios y autenticación — backend ordinario de CU-01/CU-02 (2026-10-19)

**Estado:** API implementada y probada para login, consulta de sesión y logout ordinarios. CU-01/CU-02 todavía requieren la interfaz/integración de fase 2; el módulo completo y el flujo temporal de CU-03 siguen pendientes. Las menciones a autenticación pendiente de §§17.2–17.15 son antecedentes anteriores a esta implementación.

| Endpoint implementado | Comportamiento |
| --- | --- |
| POST `/api/auth/login` | Correo/password validados, cuenta habilitada y vínculo legítimo; bloqueo de tres errores/diez minutos, límites técnicos persistentes, sesión regenerada y usuario público. |
| GET `/api/auth/me` | Restaura exclusivamente sesión válida del servidor; revalida cuenta/rol/vínculo/huella y rechaza cambio obligatorio como sesión normal. |
| POST `/api/auth/logout` | Invalida sesión actual, renueva CSRF y devuelve 204 sin cuerpo; repetición con contexto/CSRF válidos es idempotente. |

**Implementación por capas:** AutenticacionController, LoginRequest, UsuarioAutenticadoResource y RespuestaError; middlewares ExigirOrigenSpa, LimitarLogin, VerificarCuentaHabilitada, ExigirSesionNormal y ExigirRol; Services AutenticacionService/ControlIntentosService; Repositories Usuario/Sesion/ControlIntentos/Bitacora. Reutiliza Usuario, Rol, Paciente, Medico, ControlIntentos y Bitacora existentes. El análisis actualizado identifica las clases reales y sus colaboraciones; no añade interfaces vacías ni un modelo paralelo de sesión.

**Sesiones y errores:** AuthenticateSession integrado con el guard web y la huella HMAC que login ya inicializa en Laravel 13.37. La sesión database cifrada se guarda y comprueba en la misma conexión/transacción que el bloqueo del Usuario y el evento exitoso. La escritura tardía nativa no recrea la fila eliminada. Contadores/bloqueo/auditoría de denegación se confirman antes de producir 401. Las revocaciones futuras por usuario deben usar transacción/bloqueo de la cuenta; el Repository exige transacción. Desactivar/roles/contraseñas como operaciones administrativas siguen en sus fases.

**Protecciones:** procedencia SPA exacta comprobada antes de Sanctum, CSRF en escrituras, roles fijos, contexto de identidad actual, respuestas privadas no-store, errores JSON centralizados y logs técnicos mínimos sin SQL/bindings/cuerpos. Los límites configurables de `config/seguridad.php` aplican 20 solicitudes/minuto por IP y 5/minuto por correo+IP mediante ControlIntentos/HMAC, iguales para cuentas existentes/inexistentes; Retry-After se expone por CORS. Son parámetros técnicos, no nuevos RF/RN. Las cuentas con cambio obligatorio no obtienen acceso normal mientras se implementa fase 7.

**Evidencia de comportamiento:** AutenticacionTest usa cookies reales del Kernel, sesiones database cifradas, middleware CSRF real y reconstrucción de guard/Store por petición. AutenticacionConcurrenteTest utiliza procesos PHP/conexiones PostgreSQL independientes, con barreras y comprobación de espera por locks. Cubre contador concurrente, éxito/fallo serializados, desactivación frente a login, límites compartidos y revocación frente al guardado tardío. También se prueban fallos SQL/auditoría, hash cambiado antes del primer me, contexto ausente, varios dispositivos e independencia de cuenta/ficha/bloqueo. AUT-21 (navegador/React) sigue pendiente de fase 2.

**Verificación final:** suite completa `php artisan test --compact --no-ansi`: **324 pruebas y 2216 aserciones correctas** (106,58 s). Composer validate estricto, autoload optimizado con PSR-4 estricto, Pint, sintaxis de los 26 PHP afectados y `git diff --check` correctos; las tres rutas de autenticación están registradas. Lectura de control de desarrollo: siete usuarios, dos eventos, cero tokens y 29 migraciones; no se ejecutaron seeders ni correos. Los esquemas efímeros de ejecuciones interrumpidas se retiraron únicamente de la base aislada de pruebas.

**Documentación sincronizada:** `analisis-clases.md`, este contexto y README del backend. No cambia esquema, migraciones o modelos; el DER estructural continúa vigente. El diagrama global acumulativo se prepara al cerrar el módulo. Brevo sigue reservado para correos de seguridad en fase 4 y posteriores; esta fase no incorpora emisión de mensajes ni credenciales temporales.

**Reorganización posterior:** el frontend previsto inicialmente para fase 2 se aplaza por decisión expresa. Desde §17.17, fase 2 es CU-05 del backend; las referencias anteriores a números de fases conservan el plan histórico y no fijan el orden vigente.

### 17.17. Backend primero y fase 2 — CU-05 (2026-10-19)

**Decisión expresa:** completar primero el backend de Usuarios y autenticación (CU-01–CU-06); desarrollar React después. Se distingue cierre verificable del backend de la integración posterior de interfaz/navegador. Este orden sustituye el plan inicial de §17.15 sin modificar los CU, RF/RN ni políticas funcionales.

| Fase vigente | Entrega backend | Estado |
| --- | --- | --- |
| 0 | Diseño CU-01/CU-02 | Completada. |
| 1 | Login/me/logout ordinarios y controles de sesión | Completada; evidencia en §17.16. |
| 2 | Cambio de contraseña propia, CU-05 | Implementado; análisis en §14 de analisis-clases. |
| 3 | Alta/invitación de personal, CU-03; integración Brevo de seguridad | Implementada; evidencia y límites reales en §17.18. |
| 4 | Administración de acceso/roles y último Administrador, CU-03 | Implementada; evidencia y límites en §17.19. |
| 5 | Recuperación por correo de los cuatro roles, CU-06 | Implementada con correo simulado; evidencia y límites en §17.21. |
| 6 | Restablecimiento administrativo temporal del personal, CU-03 | Implementado; parámetros aprobados y evidencia en §17.23. |
| 7A / 7B | CU-04: Paciente nuevo / vinculación a ficha existente | Ambas implementadas (§§17.24–17.25); 7B incluye emisión autorizada de código por CU-07. |
| 8 | Integración y cierre backend del módulo | Completada (§17.26); diagrama global aplazado por solicitud expresa del usuario. |

En todas las fases se actualiza el análisis por CU/grupo y el DER si cambia persistencia. La protección del último Administrador se exige en cada operación que pueda afectarlo, incluidos restablecimientos, aunque su bloque principal sea fase 4. CU-04 y la emisión/integración del código de Recepción están implementados; el cierre backend se revisa en §17.26. Las limitaciones para abandonar el rol Médico siguen vigentes; los parámetros temporales se resolvieron en fase 6 (§17.23). Frontend y AUT-21 quedan para la etapa posterior, sin nueva fecha/numeración asumida. **Decisión posterior expresa:** el usuario aplaza `diagrama-clases.md` y solicita cerrar fase 8 sin ese archivo; no se considera elaborado ni actualizado.

**CU-05 implementado:** PUT `/api/auth/password`, con sesión normal, cuenta habilitada, origen permitido y CSRF. Recibe `current_password`, `password`, `password_confirmation`; devuelve 204 sin cuerpo al confirmar. La identidad proviene de la sesión, nunca de un ID de entrada. La nueva contraseña requiere mínimo 12 caracteres, sin recortar espacios/caso; límite técnico 1024 caracteres y, con bcrypt vigente, máximo 72 bytes y ausencia de NUL para impedir truncamiento o error del algoritmo. No se modifica el algoritmo ni se reescriben hashes existentes.

**Capas/transacción:** CambiarClaveRequest → ClaveController → ClaveService; reutiliza AutenticacionService, UsuarioRepository, SesionRepository y BitacoraRepository. Bloquea la cuenta y revalida estado, rol/vínculo, cambio obligatorio, huella y existencia/vigencia actual de la fila de sesión después de adquirir el bloqueo. Guarda hash, revoca todas las filas anteriores del Usuario, regenera/persiste la sesión actual con ID/CSRF/huella nuevos y confirma evento de éxito en la misma transacción. No modifica identidad, rol, ficha, bloqueo temporal de login ni otras cuentas. Los fallos necesarios revierten todo y limpian actores web/Sanctum en memoria; limpiar solo web dejaba un actor cacheado para el guard por defecto y se corrigió con evidencia concurrente.

**Denegaciones/límites:** contraseña actual incorrecta devuelve 422 `AUTH_CLAVE_ACTUAL_INVALIDA`, con evento DENEGADO confirmado y sin mutar hash/sesiones/contador de login. Campos inválidos devuelven 422 VALIDACION_ERROR con detalles seguros. LimitarCambioClave reutiliza ControlIntentosService y contadores PostgreSQL/HMAC con ámbitos distintos de login: 20 solicitudes/minuto/IP y 5/minuto/Usuario, compartidas entre dispositivos/orígenes; valores técnicos en config/seguridad.php. 429/Retry-After no prolonga ventana ni bloquea la cuenta o las sesiones normales.

**Verificación/documentación:** CambioClaveTest ejercita HTTP/cookies y CSRF real en PostgreSQL aislado; AutenticacionConcurrenteTest incorpora cambios simultáneos, revocación entre middleware y Service, login con guardado tardío frente a cambio y guardado tardío de la sesión renovada frente a revocación. Se actualizan analisis-clases, este contexto y README. No cambia persistencia (28 entidades, 239 atributos, 43 relaciones), modelos ni migraciones; DER vigente y diagrama global pendiente del cierre backend.

**Comprobaciones focalizadas:** CambioClaveTest, AutenticacionTest y AutenticacionConcurrenteTest: **83 pruebas y 1015 aserciones correctas**. Composer validate estricto y autoload optimizado con PSR-4 estricto, Pint de los 32 PHP acumulados, sintaxis PHP, cuatro rutas registradas y validación documental de formato/JSON/referencias correctos. Control de desarrollo por lectura: bcrypt, siete usuarios, dos eventos, cero tokens y 29 migraciones; no se ejecutaron seeders ni correos.

**Cierre de verificación de fase 2:** suite completa con **363 pruebas y 2787 aserciones correctas** (133,65 s). La primera ejecución se interrumpió por el límite de 120 s; se repitió con margen de 300 s y terminó correctamente. Backend ordinario de CU-05 completado/verificado; CU-03/CU-04/CU-06 y el cierre backend del módulo continúan pendientes según la tabla vigente. La siguiente entrega es fase 3.

### 17.18. Fase 3 backend — alta e invitación del personal, parte de CU-03 (2026-10-19)

**Estado real:** implementados alta pendiente de Administrador/Recepcionista/Médico, reenvío administrativo y aceptación pública de invitación para verificar correo/establecer primera contraseña. Se integra SMTP Brevo con componentes nativos de Laravel, sin dependencias nuevas. No se probó entrega real a buzón ni se configuraron secretos privados: los envíos se simulan. CU-03 completo sigue pendiente de administración de acceso/roles/último Administrador y restablecimiento temporal (fases 4/6). React sigue para la etapa posterior.

| Endpoint | Acceso y resultado |
| --- | --- |
| POST `/api/personal` | Administrador con sesión normal, cuenta/contexto actuales y CSRF; crea una sola cuenta Pendiente y emite invitación. 201 con datos administrativos mínimos y meta.invitacion. |
| POST `/api/personal/{id}/invitacion` | Mismo actor; solo cuenta del personal pendiente de primera contraseña y vínculo legítimo. 200 con el mismo identificador y una nueva invitación; invalida la anterior. |
| POST `/api/auth/invitaciones/aceptar` | Contexto anónimo, origen permitido y CSRF; token, password y password_confirmation. Activa y verifica únicamente la cuenta del token; 204 sin login automático. |

**Autorización/persistencia:** el Service revalida al Administrador después de bloquear su Usuario y comprobar su sesión persistida. No hay alta pública de personal ni creación/restablecimiento de Pacientes. Médico requiere registro profesional existente y sin cuenta; se bloquea ese registro antes de vincular. Sus nombres provienen del registro profesional; no se crea otro Médico ni se activan ficha/horarios/consultorio. El correo se normaliza y su índice único resuelve carreras. Los campos privilegiados enviados no fijan hash/estado/verificación. La instalación del primer Administrador permanece separada e idempotente, sin ejecutarla de nuevo.

**Invitación:** secreto aleatorio de 256 bits (64 caracteres hexadecimales), resumen HMAC-SHA256 en TokenAcceso con propósito InvitacionPersonal, 24 horas y un solo uso. No se devuelve token/enlace por API ni se registra en Bitácora. El enlace de correo se construye solo con FRONTEND_URL configurado y usa `/activar-personal#token=...`, previsto para la futura SPA; el secreto del fragmento no se envía al servidor web por GET. El backend lo recibe por POST/JSON. Reemisión y aceptación bloquean el mismo Usuario y luego el token; un reenvío nuevo sustituye al anterior y comienza su propia vigencia de 24 horas. Aceptar exige cuenta todavía Pendiente, sin hash/verificación/cambio obligatorio, rol personal y vínculo compatible; guarda hash/fecha/Activa, consume token, invalida otros, revoca sesiones previas y renueva el contexto anónimo. Exige login posterior, preservando bloqueos temporales y actividad de la ficha.

**Correo después del commit:** alta/vínculo/token/auditoría confirman juntos. DB::afterCommit envía únicamente tras el commit exterior y registra INVITACION_ENVIO en una transacción posterior. No hay reintentos SMTP automáticos ni promesa de rollback del envío. La API distingue meta.invitacion.envio `simulado`, `aceptado` (por transporte, no prueba de entrega al buzón), `fallido` y `pendiente` (callback diferido por transacción exterior). Un fallo de envío conserva cuenta/token pendientes y registra FALLO, con reenvío controlado sobre la misma cuenta. Si el proceso se interrumpe después del commit, el Administrador puede reemitir; no existe outbox/cola persistida nueva. Un fallo de auditoría posterior al envío puede dar 500 sin deshacer el correo o la cuenta ya confirmados; no se anuncia un rollback externo inexistente.

**Integración Brevo:** mailer nativo `brevo` vía smtp-relay.brevo.com:587 con TLS obligatorio y timeout de 10 s. Configuración privada BREVO_SMTP_USERNAME/BREVO_SMTP_KEY, remitente MAIL_FROM_ADDRESS autorizado y SEGURIDAD_MAILER=brevo para envío real. `.env.example` solo añade nombres/vacíos; `.env` no se modifica. El valor predeterminado de seguridad es array, permitido solo en local/testing; no se permite log/failover que pudiera volcar enlaces secretos, ni simular éxito con array en producción. El frontend de producción requiere HTTPS. La vista Blade es exclusivamente plantilla del correo, no interfaz funcional.

**Validación/límites:** ClaveRequest concentra la política ya vigente y la comparten CU-05 y aceptación de invitación: mínimo 12 caracteres/confirmación, espacios/caso preservados, límite técnico de bcrypt sin truncamiento. Reenviar valida BIGINT de la ruta, no un ID del cuerpo. Parámetros técnicos iniciales en config/seguridad.php: espera de 60 s, máximo cinco emisiones/hora por cuenta (incluye inicial/fallidas), 20 solicitudes/minuto/IP y cinco/minuto/ámbito de Administrador o token, con HMAC/ControlIntentos y separación de acciones. No son nuevos RF/RN ni límites de credencial temporal. Enlace inexistente/vencido/usado/invalidado/incompatible: 422 genérico, sin correo/identidad; duplicado/vínculo/conflicto administrativo: 409; cuenta no encontrada para Administrador: 404; límites: 429/Retry-After. Auditoría conserva responsable/objetivo/roles con las restricciones existentes, sin secretos.

**Evidencia focalizada:** InvitacionPersonalTest más regresión de autenticación/cambio y AutenticacionConcurrenteTest: **124 pruebas y 1519 aserciones correctas**. Incluye tres roles del personal, rechazo de roles ajenos, invitación/activación/reenvío, fronteras de vigencia/límites, fallos SQL/auditoría/SMTP, despacho solo tras commit exterior y rollback sin correo, transporte Brevo simulado, consumo concurrente único, correo único concurrente, vínculo profesional exclusivo y reenvío frente a aceptación. Los workers validan PostgreSQL aislado y simulan el correo. Documentación sincronizada: análisis §15, este contexto y README. No cambia esquema/modelos/migraciones; siguen 28 entidades, 239 atributos y 43 relaciones. DER vigente; diagrama global al cierre backend del módulo.

**Cierre de verificación de fase 3:** suite completa con **404 pruebas y 3291 aserciones correctas** (146,07 s, margen de 300 s). Composer validate/autoload PSR-4 estrictos, Pint de 46 PHP, sintaxis de 47 PHP incluida la plantilla Blade, siete rutas API y validación documental de formato/JSON/referencias correctos; git diff --check correcto. Control de desarrollo por lectura: transporte de seguridad array, siete usuarios, dos eventos, cero tokens y 29 migraciones. No se ejecutaron seeders ni correos reales ni se modificaron credenciales privadas. Bloque backend de alta/invitación completado con simulación de correo; entrega real y CU-03 completo siguen pendientes según lo indicado.

**Siguiente entrega:** fase 4 — administración de acceso, roles y protección del último Administrador; conservar rechazos por obligaciones médicas incompatibles/no verificables y no anticipar el flujo temporal de fase 6.

### 17.19. Fase 4 backend — administración de acceso/roles y continuidad administrativa (2026-10-19)

**Estado real:** implementados listado/detalle administrativos de cuentas, desactivación/reactivación y cambios entre roles del personal, con revocación de sesiones, trazabilidad y protección concurrente del último Administrador. Es otro bloque de CU-03 (RF01, RN12/RN14; VS-AUT-04/05/23–27), no el cierre de todo CU-03: restablecimiento temporal permanece en fase 6. Recuperación CU-06 es la siguiente entrega; React continúa para después del backend.

| Endpoint | Contrato |
| --- | --- |
| GET `/api/usuarios` | Solo Administrador normal. Filtros rol/estado_cuenta/correo_acceso exacto normalizado; page/per_page (20 predeterminado, 100 máximo), orden fijo por ID descendente. Resource con data/links/meta. |
| GET `/api/usuarios/{id}` | Detalle administrativo mínimo, ID BIGINT validado. Pacientes sin verificación previa o sin vínculo legítimo no se exponen (404). |
| PATCH `/api/usuarios/{id}/estado` | estado_cuenta=Activa o Desactivada. Solo transición entre esos estados con hash/verificación y relación legítimos; no habilita ni desactiva una invitación Pendiente por esta operación. 200 con data administrativa. |
| PATCH `/api/usuarios/{id}/rol` | rol Administrador/Recepcionista/Médico. Médico exige id_medico y, para vínculo inicial, identidad_verificada=true después de comprobación administrativa. 200; revoca sesiones del titular y exige nuevo login. |

**Capas efectivas:** CuentaController/Requests/Resource → AdministracionCuentaService → ContinuidadAdministrativaService y repositorios de Usuario/Médico/Sesión/Bitácora → modelos existentes/PostgreSQL. No se añaden tablas, campos, relaciones ni modelos. Los cuerpos de las operaciones por cuenta rechazan campos adicionales, incluidos credenciales, correo, ficha/rol indebidos y selectores de objetivo; el ID procede exclusivamente de la ruta. La proyección de Paciente contiene solo ID de Usuario, correo de acceso, rol, estado y fecha de verificación, sin CI, ficha, datos clínicos, contadores, hashes o vínculos. Para personal agrega nombres administrativos/nombre visible e ID profesional histórico cuando existe.

**Acceso:** desactivar borra todas las sesiones en la misma transacción del estado y auditoría; reactivar también asegura que no sobrevivan sesiones anteriores y requiere nuevo login. Conserva hash, correo/verificación, rol, identidad, vínculo, contador/bloqueo temporal e historial; no activa fichas ni Médico. Se rechazan el estado repetido, la habilitación de Pendiente y relaciones incompatibles. Sobre la propia cuenta cierra y renueva el contexto anónimo, sin conservar permisos por guard cacheado. Pacientes no pueden convertirse en personal ni se les modifican credenciales o identidad por este contrato.

**Último Administrador:** bloqueo advisory transaccional PostgreSQL reservado `(736201, 1)` ANTES de bloquear actor/objetivo; después se revalida Admin/sesión persistida, se aplica el cambio y se comprueba al menos un Admin Activa/verificado/con hash/sin cambio obligatorio y con identidad administrativa utilizable. El bloqueo temporal de login no lo excluye del cómputo. Si el resultado lo incumple, 409 ULTIMO_ADMINISTRADOR y rollback, también de un vínculo profesional tentativo. El bloqueo se libera automáticamente al commit/rollback; no requiere entidad/atributo nuevo. Futuro restablecimiento de fase 6 debe reutilizar ese orden/protocolo antes de restringir una cuenta. No es un trigger universal para SQL externo ni una garantía de disponibilidad ante olvido/bloqueos. Invitación/activación agregan habilitaciones y no retiran las existentes.

**Identidad profesional:** Administrador↔Recepcionista conserva identidad. Para entrada inicial a Médico se requiere la comprobación expresa del Administrador (`identidad_verificada=true`) y correspondencia estricta de nombres/apellidos tras comparar caso/espacios, además de registro existente/libre bajo bloqueo. La comparación no acredita identidad civil por sí sola; ante diferencias no se corrige/fusiona automáticamente. Con vínculo histórico solo se acepta ese mismo Médico; no se reasigna Médico A→B ni se pierde el vínculo al salir. No se crean registros, horarios, consultorios, especialidades o citas ni se activa al profesional.

**Retiro de Médico:** exige profesional ya inactivo expresamente por CU-10; esta API no implementa ni ejecuta esa inactivación. Bajo bloqueo del Médico se comprueba que no existan citas Programada/Confirmada/Atendida ni consultas vinculadas a cualquiera de sus citas. Atendida, consulta existente e iniciada_en=NULL no prueban cierre; si hay documentación/atención no verificable, 409 MEDICO_OBLIGACIONES_NO_RESUELTAS. Solo se acepta ausencia comprobable de obligaciones con el modelo actual (sin atenciones, o únicamente Cancelada/Inasistencia sin consulta); se preservan todos los registros y el vínculo, y los nombres administrativos se toman de la misma identidad profesional. Una solicitud de laboratorio implica consulta, por lo que también impide el retiro mientras no exista cierre verificable. No se cancela/traslada/reasigna ni se guarda una transición pendiente. Los futuros flujos de reserva/CU-10/asistencia deben coordinarse con el mismo bloqueo de Médico antes de nuevas obligaciones o actividad; esos módulos aún no se implementan.

**Auditabilidad/errores:** cambios de cuenta/rol/vínculo y revocaciones confirman junto al evento EXITO. cambios_seguros guarda solo antes/después de rol, estado_cuenta e ID profesional; no nombres, correos, contenido clínico ni secretos. El responsable conserva su rol de actuación incluso en cambios propios. Denegaciones de negocio tras autorización se registran después de revertir la operación mediante causa y referencias seguras; fallos SQL/auditoría devuelven 500 seguro sin éxito ni cambio parcial. 401/403 de acceso, 419 CSRF, 422 de campos/rangos/operaciones ajenas, 404 de objetivo no accesible y 409 de transición/identidad/obligaciones/continuidad mantienen el contrato centralizado.

**Evidencia:** AdministracionCuentaTest comprueba cuatro roles y múltiples dispositivos, bloqueo temporal preservado, último Admin incluyendo cuenta propia/otros pendientes o restringidos, proyección mínima, campos prohibidos, identidad/vínculo/historial profesional, ausencia de cierre clínico y rollback ante auditoría/SQL de revocación. AutenticacionConcurrenteTest agrega procesos PostgreSQL independientes para dos retiros simultáneos (desactivar/cambiar rol/combinado), desactivaciones cruzadas, actor revocado mientras espera coordinación, respuesta tardía de login frente a desactivación y dos asignaciones compitiendo por un Médico. Regresión focalizada de fases 1–4: 157 pruebas/2053 aserciones en ejecución conjunta más una prueba adicional de rollback del vínculo por último Admin (9 aserciones), todas correctas. Resultado de suite completa y comprobaciones de cierre se registran al finalizar su ejecución.

**Documentación:** analisis-clases §16 y README actualizados. DER contrastado con Usuario/Sesión/Médico/Bitácora y sus migraciones, sin cambio estructural: 28 entidades, 239 atributos y 43 relaciones. diagrama-clases.md todavía no existe y corresponde al cierre backend en fase 8; módulo incompleto. No se modifican reglas funcionales aprobadas ni se ejecutan seeders/envíos reales.

**Suite completa de fase 4:** **438 pruebas y 3834 aserciones correctas**, 135,66 s con margen de 300 s. Incluye las regresiones de persistencia, autenticación, invitación y cambio ordinario de clave junto con la administración nueva.

**Comprobaciones de cierre:** Composer validate/autoload PSR-4 estrictos correctos; Pint de 55 PHP, sintaxis de 56 PHP/Blade, cuatro rutas nuevas de cuentas, formato UTF-8/cercas Markdown/ejemplos JSON/referencias PHP de documentación y git diff --check correctos. Control de desarrollo de solo lectura: siete usuarios, dos eventos, cero tokens, 29 migraciones y transporte de seguridad array. No se ejecutaron seeders, migraciones de desarrollo ni envíos reales durante esta fase; credenciales privadas preservadas. El bloque de fase 4 queda verificado, con los límites clínicos y alcance parcial de CU-03 indicados arriba.

### 17.20. Refactorización estructural de rutas previa a fase 5 (2026-10-19)

Por solicitud expresa, `routes/api.php` pasa de cargar un archivo único a incluir directamente, en orden, `src/presentation/routes/auth.php`, `personal.php` y `usuarios.php`. Sustituyen el anterior archivo api.php de presentación. Se mantiene la organización CADEFAR en src/presentation/routes y el registro Laravel de bootstrap, sin modificar lógica ni contratos.

- auth.php: login, me, logout y cambio de contraseña (cuatro rutas).
- personal.php: aceptación pública de invitación con prefijo auth y URL original `/api/auth/invitaciones/aceptar`, separada del grupo administrativo de alta/reenvío de personal (tres rutas).
- usuarios.php: listado/detalle/estado/rol (cuatro rutas).
- Las rutas de infraestructura `/up` y `/sanctum/csrf-cookie` conservan su registro actual. No existen otros módulos con rutas funcionales definidas que deban dividirse.

**Equivalencia comprobada:** captura del registro completo antes/después, interceptando cada declaración antes de una posible sustitución en RouteCollection, sin duplicados de método/dominio/URI ni nombres. Las 13 declaraciones y sus 18 combinaciones de método/dominio/URI (incluido HEAD) son idénticas, en el mismo orden: métodos, URI, nombre, dominio, prefijo, acción/configuración, middleware declarado/resuelto, parámetros, restricciones where y valores predeterminados. Hashes SHA-256 de 106 archivos de src ajenos a rutas y bootstrap/rutas web/consola idénticos. Las capturas y el script de comprobación son temporales, fuera del repositorio; no contienen datos de cuentas ni secretos.

Se ejecutó `php artisan route:list` antes y después, con 13 rutas registradas en ambos casos; sintaxis PHP y Pint correctos para los cuatro archivos de rutas. Se conservan permisos, rate limits, origen/CSRF y expresiones BIGINT sin añadir prefijos o middlewares duplicados. Documentación sincronizada: README, análisis §17 y este contexto. No cambia persistencia/DER ni clases del módulo; diagrama global sigue pendiente de fase 8. El plan funcional continúa con fase 5, CU-06.

**Regresión posterior al traslado:** AutenticacionTest, CambioClaveTest, InvitacionPersonalTest, AdministracionCuentaTest y AutenticacionConcurrenteTest: **158 pruebas y 2062 aserciones correctas** (50,39 s), con PostgreSQL aislado y correo simulado. Formato/JSON/referencias documentales y git diff --check correctos. No se añadieron pruebas o dependencias; se ejecutaron las pruebas de comportamiento existentes de los módulos afectados.

### 17.21. Fase 5 backend — recuperación por correo, CU-06 (2026-10-19)

**Implementación:** POST `/api/auth/password/forgot` y POST `/api/auth/password/reset`, públicos con origen SPA/CSRF, en auth.php; routes/api.php conserva su carga modular. Petición con correo normalizado devuelve 202 genérico privado/no-store; cuenta/token/SMTP se procesan después mediante defer. El cuerpo delimitado con Content-Length permite completarlo antes del cierre de conexión. Prueba HTTP real loopback con transporte bloqueado confirma respuesta completa antes de liberar el envío; no sustituye verificar buffering/proxy/finalización FastCGI en producción. No se informa existencia/elegibilidad ni resultado SMTP. El 202 no acredita emisión o entrega.

**Elegibilidad/seguridad:** cuatro roles con cuenta Activa, correo verificado, hash y vínculo compatible; ficha/Médico pueden estar inactivos. Bloqueo temporal y contador de login se conservan, sin reactivar cuentas ni modificar identidad/permisos. Se mantiene denegado cambio obligatorio mientras fase 6 concreta la coordinación temporal pendiente: CU-06 no elimina esa marca ni consume/invalida CredencialTemporal. No se considera resuelto por esta fase el procedimiento temporal del personal.

**Enlace/consumo:** secreto aleatorio de 256 bits, solo HMAC-SHA256 en TokenAcceso/RecuperacionClave, 60 minutos, uso único; nueva emisión invalida anteriores. Enlace FRONTEND_URL + `/recuperar-clave#token=...`, recibido por POST/JSON, sin token en API/bitácora/logs. Restablecer no requiere clave anterior; comparte ClaveRequest con CU-05/invitación. Bajo bloqueo Usuario→Token revalida estado, rol/vínculo, propósito y fechas; guarda hash, consume e invalida otras recuperaciones, borra sesiones y confirma auditoría atómicamente. 204 sin cuerpo y nuevo login. Contexto actual del titular se cierra, sin sustituir una sesión ajena por la recuperada. Fallos necesarios revierten hash/token/sesiones; enlaces inválidos reciben 422 genérico. Campos inválidos 422, origen 403, CSRF 419 y límite 429/Retry-After, según contrato centralizado.

**Coordinación:** CU-05 y administración de estado/rol invalidan recuperaciones en su misma transacción. Se conserva orden Usuario antes de Token; no se cambia el protocolo global→actor→objetivo de continuidad administrativa. Pruebas PostgreSQL independientes cubren doble consumo, doble emisión, emisión/consumo, cambio ordinario, desactivación y login con guardado tardío; no se recrean sesiones revocadas ni se cambia dos veces con el mismo enlace.

**Límites técnicos iniciales:** por acción solicitud/consumo, 20/minuto/IP y cinco/minuto/correo o token; solicitud además cinco/hora/correo, compartido entre IP y aplicado igual a correos inexistentes. Configurables en seguridad.php, persistidos con HMAC en ControlIntentos y separados de otros flujos. No prolongan ventanas ni bloquean la cuenta. No son RF/RN nuevos ni parámetros de CredencialTemporal.

**Correo/fallos:** CorreoSeguridadService comparte transporte validado y añade RecuperacionClaveMail/plantilla exclusiva de seguridad. Emisión/invalidación/auditoría confirman antes de enviar con DB::afterCommit. Resultado de envío se audita después; fallo SMTP o auditoría posterior no revierte token/correo ni expone detalles al solicitante. Sin cola/outbox o reintentos automáticos: se admite nueva solicitud controlada si falta entrega o se interrumpe el proceso. Brevo se simula; entrega real y topología productiva siguen pendientes.

**Clases/documentación:** análisis §18 y README actualizados; TokenAccesoRepository incorpora operaciones específicas de recuperación; ClaveService y AdministracionCuentaService reutilizan su invalidación. Sin modelos, esquema o migraciones nuevos: DER contrastado y vigente, 28 entidades, 239 atributos y 43 relaciones. Diagrama global aún no existe y corresponde al cierre backend de fase 8; CU-03/CU-04 y módulo completo permanecen pendientes. Próxima fase 6 necesita las definiciones expresas de §17.14, sin deducir duración/entrega/consumo de otros flujos. React continúa después del backend.

**Comprobaciones de código/entorno:** Composer validate y autoload optimizado PSR-4 estrictos correctos; Pint de 61 PHP del alcance, sintaxis de 66 PHP/Blade, UTF-8/cercas Markdown/ejemplos JSON de los tres documentos y git diff --check correctos. Registro efectivo de 15 rutas y 20 combinaciones método/dominio/URI, sin duplicados. Lectura de desarrollo: mailer array, siete usuarios, dos eventos, cero tokens y 29 migraciones. Se preservaron credenciales privadas; no se ejecutaron seeders, migraciones de desarrollo o envíos reales.

**Suite completa de fase 5:** **480 pruebas y 4445 aserciones correctas**, 152,40 s con margen de 300 s, PostgreSQL aislado y correo simulado. Agrega 36 pruebas de recuperación (incluida HTTP real con barrera de envío) y seis carreras de recuperación a la regresión previa. Fase 5 backend ordinario verificada con los límites de correo/integración/flujo temporal indicados; no se declara completo el módulo.

### 17.22. Adaptación aprobada del transporte Brevo a API HTTP (2026-10-19)

**Decisión explícita:** usar la API HTTP de Brevo con API key, sustituyendo SMTP en los mensajes de seguridad actuales de CU-03/CU-06. Las menciones SMTP de §§17.18/17.21 son evidencia histórica de esas fases; el transporte vigente es HTTP. Se conserva el plan backend primero y no se alteran reglas, URLs de autenticación, tokens, plazos, permisos o respuestas públicas.

**Integración:** mailer Laravel brevo → BrevoHttpTransport en src/shared/utils → cliente HTTP nativo → POST https://api.brevo.com/v3/smtp/email. AppServiceProvider registra el driver brevo-http mediante un puente Mail::extend; config/mail.php selecciona transporte y config/services.php lee BREVO_API_KEY. Se conservan Mailable/plantillas y from centralizado; sin SDK/dependencias nuevas. El adaptador solo admite un destinatario de seguridad, sin copias/adjuntos. Petición JSON con api-key en cabecera y sender/to/subject/htmlContent, TLS verificado, conexión 3 s y total 10 s, sin redirecciones/reintentos automáticos. Solo 201/messageId válido significa aceptado por proveedor, nunca comprobación de entrega a buzón.

**Fallos/privacidad:** TransportException saneada para rechazo, estado inesperado, confirmación incompleta y conexión/timeout; no se copian cuerpos/cabeceras externos ni se encadena excepción de conexión con potenciales secretos. CorreoSeguridadService valida API key para Brevo y conserva array solo local/testing. Services mantienen afterCommit, auditoría posterior e invitación fallida recuperable; recuperación mantiene 202 genérico. Emisión/token confirmados no se revierten ante fallo externo, sin prometer que timeout pruebe ausencia de envío. Reemisión sigue controlada por los límites de cada CU.

**Configuración privada:** SEGURIDAD_MAILER=brevo, BREVO_API_KEY nueva privada, MAIL_FROM_ADDRESS autorizado y MAIL_FROM_NAME=Clínica Vida Salud; FRONTEND_URL conserva destino configurado. MAIL_PROVIDER/RECOVERY_ENABLED no se usan; MAIL_FROM del proyecto anterior se corresponde con MAIL_FROM_ADDRESS. .env.example declara clave vacía y elimina variables Brevo SMTP. No se modificó .env ni se reutilizó la clave expuesta en conversación; default array y entrega real pendientes de configuración/verificación privada.

**Pruebas/documentación:** BrevoHttpTransportTest valida mailer real con Http::fake y casos de éxito/error/autenticación/estructura/tiempos/privacidad; invitación y recuperación verifican rechazo/reemisión con HTTP simulado y transacciones existentes. TestCase y workers bloquean peticiones externas no simuladas. Regresión focalizada: **94 pruebas y 1103 aserciones correctas** (14,84 s). README, análisis §§15/18/19 y este contexto actualizados. Sin cambios del DER/persistencia: 28 entidades, 239 atributos, 43 relaciones; diagrama global pendiente de fase 8. CU-03 temporal, CU-04 y cierre completo del módulo siguen pendientes.

**Cierre verificado:** suite completa **501 pruebas y 4575 aserciones correctas**, 145,67 s con margen de 300 s. Agrega 19 pruebas del transporte y dos de rechazo/reemisión de invitación/recuperación. Composer validate/autoload optimizado PSR-4 estrictos, sintaxis/Pint de 14 PHP y validación documental/git diff --check correctos. Desarrollo por lectura conserva siete usuarios, dos eventos, cero tokens y 29 migraciones; mailer de seguridad array, transporte Brevo brevo-http y API key sin configurar. No se ejecutaron seeders, migraciones o correos reales ni se modificaron secretos; entrega real pendiente.

### 17.23. Fase 6 backend — restablecimiento administrativo temporal de CU-03 (2026-10-19)

**Aprobación expresa e implementación:** credencial criptográfica aleatoria de **20 caracteres**, visible una sola vez al Administrador para entrega presencial tras comprobar identidad; **30 minutos** desde emisión para ingresar y completar. Reingreso hasta completar dentro del mismo plazo, con una sola sesión restringida vigente; un único cambio definitivo. Conserva bloqueo aprobado de tres errores/diez minutos, sin reiniciar ni saltar el existente al emitir. Reemisión tras **60 segundos**, máximo **cinco emisiones/hora/cuenta** compartidas entre Admin/orígenes, invalidando anteriores. Vencer o logout expreso sin completar mantiene cambio obligatorio y requiere reemisión; nunca restaura contraseña anterior. Son decisiones específicas de fase 6, no RF/RN nuevos ni parámetros deducidos de invitación/recuperación. Reglas §4.4 y CU-03 sincronizados.

| Contrato backend | Acceso / resultado |
| --- | --- |
| POST `/api/usuarios/{id}/credencial-temporal` | Admin normal, identidad_verificada=true y BIGINT de ruta, origen/CSRF/límites; 201 data.id_usuario/credencial_temporal/expira_en, entrega única privada no-store. |
| POST `/api/auth/login` | Credencial temporal solo con cambio obligatorio y cuenta/token válidos. 200 data.acceso=cambio_clave y cambio_clave_obligatorio=true, sin perfil/rol. Login normal conserva contrato previo. |
| GET `/api/auth/me` | Sesión normal conserva usuario público; restringida solo estado mínimo del cambio. Revalida cuenta/token/fecha/fila persistida, sin acceso normal. |
| PUT `/api/auth/password/temporal` | Solo sesión restringida vigente; password/password_confirmation según política compartida; 204, cierre y nuevo login obligatorio. Identidad desde sesión, no del cuerpo. |
| POST `/api/auth/logout` | Logout restringido expreso invalida temporal vigente y sesiones; conserva marca/hash previo inhabilitado hasta nueva emisión. 204 con origen/CSRF válidos. |

**Capas:** CredencialTemporalController y FormRequests de emisión/completado → CredencialTemporalService; CredencialTemporalValidator concentra elegibilidad/vigencia reutilizada por AutenticacionService. CredencialTemporal genera y resume con HMAC-SHA256 separado por propósito; TokenAccesoRepository consulta/emite/invalida/consume. SesionRepository guarda normal=false y referencia de token en payload cifrado, con persistencia/huella previas al commit. UsuarioAutenticadoResource limita login/me restringidos. Middlewares ExigirSesionRestringida/LimitarCredencialTemporal y ExigirSesionNormal separan permisos; este último devuelve 403 AUTH_CAMBIO_CLAVE_OBLIGATORIO. AdministracionCuentaService invalida también temporales al cambiar estado/rol. Análisis de responsabilidades/colaboraciones efectivo en §20 de analisis-clases.

**Elegibilidad/consistencia:** solo personal Activo, previamente verificado y con identidad/vínculo legítimos. No se restablece Paciente, no se activa Pendiente/Desactivada ni se modifica actividad profesional. Emisión conserva el hash anterior pero lo inhabilita, marca cambio obligatorio, invalida temporales/recuperaciones y revoca sesiones. Reutiliza orden global `(736201, 1)` → actor/sesión persistida → objetivo → tokens, y verifica Admin normal resultante, incluyendo emisión propia y simultánea. Completado bajo Usuario→Token revalida sesión persistida y vigencia, también tras calcular hash; guarda definitiva, consume/invalida, quita marca, revoca/cierra y audita atómicamente, sin conversión automática en sesión normal. Fallos necesarios no entregan token ni confirman cambios parciales; limpieza web/Sanctum ante rollback.

**Coordinación y límites:** CU-06 no emite ni consume recuperaciones mientras exista cambio obligatorio; las anteriores se invalidan al emitir. CU-05 ya validado revalida bajo bloqueo y no puede saltar la restricción recién emitida. Reemisión y cambios administrativos de estado/rol invalidan sesiones/tokens; reactivar no resucita la credencial. Controles técnicos por acción: 20 solicitudes/minuto/IP y cinco/minuto/actor, HMAC/ControlIntentos separados y ventanas no extensibles, configurables en seguridad.php; no sustituyen intentos de login. CSRF de una sesión eliminada puede producir 419 antes de autenticación; refrescar CSRF no restaura permisos. Login inválido conserva 401 genérico; emisión no elegible 409 y último Admin 409, sin datos clínicos.

**Evidencia focalizada:** **67 pruebas y 1042 aserciones correctas**, 36,42 s. CredencialTemporalTest cubre entrega/uso único, tres roles del personal/exclusión Paciente, identidad/CSRF/origen, inhabilitación de hash anterior, vigencia/reingreso/sesión única/logout, reemisión/límites compartidos entre Admin/IP, bloqueo preservado y tres errores/diez minutos, cambio con nuevo login, CU-06 excluido, invalidación por estado/rol, rollback por auditoría y ausencia de secretos. AutenticacionConcurrenteTest usa procesos/conexiones PostgreSQL independientes con barreras/locks para doble emisión/consumo, continuidad simultánea temporal/estado/rol, emisiones cruzadas, completado/reemisión, CU-05/CU-06 concurrentes, revocaciones posteriores al middleware y guardados tardíos normales/restringidos. Pruebas solo en base/rol aislados y correo simulado.

**Documentación/estado:** requerimientos, reglas, casos, análisis §20, contexto, nota funcional del DER y README sincronizados. DER sin cambios estructurales: **28 entidades, 239 atributos y 43 relaciones**, reutilizando migración 000029 ya aplicada; no se requiere migración nueva. Diagrama global no existe y corresponde al cierre backend del módulo en fase 8. Bloques backend de CU-03 implementados con límites de transición médica ya documentados; CU-04, integración final backend y React permanecen pendientes. Siguiente entrega: **fase 7A, registro de Paciente nuevo**; 7B integra código de Recepción/vinculación.

**Actualización privada posterior a §17.22:** el usuario autorizó y se completó la configuración privada de API key/remitente/nombre y SEGURIDAD_MAILER=brevo en `.env` ignorado; se limpió caché y comprobó carga efectiva sin imprimir secretos. No se envió correo real ni se verificó externamente el remitente o la entrega. Por decisión del usuario, esa prueba se realizará con el recorrido integrado del futuro frontend. La fase 6 no envía correos ni modifica esa configuración.

**Cierre verificado de fase 6:** suite completa `php artisan test --compact --no-ansi`: **538 pruebas y 5234 aserciones correctas**, 161,61 s, con margen de 300 s. Añade 21 pruebas de credencial temporal y 16 escenarios concurrentes a las 501 anteriores. Composer validate/autoload optimizado PSR-4 estrictos, Pint y sintaxis de los 21 PHP afectados correctos. Registro efectivo: 17 rutas / 22 combinaciones método/dominio/URI, sin duplicados (15 rutas API y dos de infraestructura). `.env` continúa ignorado; no se ejecutaron migraciones/seeders de desarrollo ni correos reales. Formato/JSON/referencias documentales y git diff --check comprobados al finalizar. Fase 6 backend completada; módulo global, CU-04 y React pendientes según el plan.

### 17.24. Fase 7A backend — registro de Paciente nuevo, escenario A de CU-04 (2026-10-19)

**Implementación:** POST `/api/auth/registro`, POST `/api/auth/registro/reenviar` y POST `/api/auth/registro/verificar`, en auth.php, con origen SPA/CSRF y rechazo de sesiones autenticadas. Inicio/reenvío devuelven 202 genérico delimitado por Content-Length; reserva/consulta/código/correo se procesan mediante defer tras responder, sin revelar si CI/correo existe o confirmar entrega. El solicitante recibe cookie opaca cifrada/HttpOnly/host-only de 24 h independiente de la sesión normal; su HMAC se guarda como metadato en datos_registro. No se envía ID de proceso ni código/secretos en JSON. Verificar recibe código y contexto de cookie, devuelve 204 al completar, elimina contexto y exige nuevo login.

**Política conservada:** CI obligatorio con normalización vigente (solo espacios ASCII exteriores, sin alterar caso/signos/interiores/ceros) y límites estructurales, sin formato civil ni catálogo de sexo inventados. Nombres/apellidos/nacimiento/teléfono/correo/password/confirmación obligatorios; sexo, dirección y correo de contacto opcionales conforme a nulabilidad existente; contraseña comparte ClaveRequest. Datos/control/roles/IDs adicionales en inicio se rechazan. Ningún Usuario o Paciente definitivo se crea hasta verificar. Pendiente dura 24 h; código de seis dígitos, HMAC ligado al proceso, diez minutos o fin principal si es anterior, cinco errores, reenvío 60 s/cinco por hora por proceso y correo. Código nuevo distinto de anteriores/invalida previos; reintentos no modifican datos/clave/24 h originales ni toman reservas ajenas. Inicio para ficha existente orienta genéricamente a Recepción/vinculación sin duplicar.

**Capas/consistencia:** RegistrarPacienteRequest/VerificarRegistroPacienteRequest → RegistroPacienteController → RegistroPacienteService → RegistroPacienteRepository y UsuarioRepository → modelos existentes. RegistroPacienteContexto concentra HMAC; VerificacionPacienteMail/CorreoSeguridadService envían seguridad mediante transporte HTTP aprobado después del commit. Global PostgreSQL `(736202, 1)` → proceso → código coordina inicio/reenvío/consumo, sin mantener locks durante correo. La futura fase 7B/altas administrativas que compitan por reservas debe reutilizarlo. Verificación revalida unicidad final, crea cuenta Activa de rol Paciente/ficha activa enlazadas, consume/invalida código, limpia datos/hash temporales y audita en una transacción. UQ CI/correo/cuenta resisten escrituras externas al protocolo; 23505 devuelve 422 genérico con rollback íntegro. Fallos de auditoría no dejan cuenta, ficha o consumo parcial. No hay login automático, antecedentes ficticios ni cambio de ficha histórica.

**Límites/fallos:** 20 solicitudes/minuto/IP y cinco/minuto/ámbito por acción (correo en inicio o contexto en reenvío/verificación), HMAC/ControlIntentos y ventanas no extensibles, independientes de cuentas existentes; 429/Retry-After. Límites de emisión por proceso/correo se cumplen internamente sin diferenciarlos en la respuesta 202, evitando enumeración. Código/contexto inválido o conflicto: 422 REGISTRO_VERIFICACION_INVALIDA, formato 422, sesión existente 409, origen 403 y CSRF 419. Respuesta siempre aclara que no confirma envío y orienta a reenvío/Recepción; fallos de Brevo se registran sin detalles ni secretos y preservan proceso reintentable. Sin cola/outbox ni envío real; se mantiene decisión de comprobar entrega en integración React.

**Evidencia:** RegistroPacienteTest con **31 pruebas/323 aserciones correctas**; seis carreras nuevas en AutenticacionConcurrenteTest, verificadas junto con las previas. Cubren reserva/consumo únicos, cinco errores concurrentes, reenvío contra verificación y colisiones reales de índices únicos con transacciones de alta administrativa/personal aún sin confirmar, sin vínculos parciales. Las pruebas específicas cubren datos/roles/normalización, cookie protegida y sesión expirada, 10 min/24 h/60 s/cupos y vigencia recortada, reanudación/ajeno, rollback de auditoría y rechazo HTTP de Brevo simulado. Prueba de Kernel confirma ausencia de reserva/correo antes de terminar la respuesta genérica. Producción conserva la comprobación pendiente de buffering/finalización ya indicada para defer.

**Documentación/estado:** análisis §21, contexto, README y nota de uso funcional del DER actualizados; se corrige referencia antigua en requerimientos a parámetros temporales ya resueltos por fase 6, sin cambiar RF/RN. Sin migraciones/tablas/columnas/relaciones nuevas: **28 entidades, 239 atributos y 43 relaciones**. Diagrama global continúa pendiente de cierre backend de fase 8. CU-04 no se declara completo: siguiente entrega **7B, ficha histórica y código de Recepción de CU-07**; React y recorrido real del correo siguen posteriores.

**Cierre verificado:** suite completa **575 pruebas y 5648 aserciones correctas**, 178,35 s con margen de 300 s. Son 31 pruebas específicas y seis carreras nuevas sobre la suite de 538 previa. Composer validate/autoload optimizado PSR-4 estrictos, Pint de 18 PHP, sintaxis de 19 PHP/Blade, UTF-8/cercas Markdown/JSON/referencias y git diff --check correctos. Registro efectivo: 20 rutas/25 combinaciones método/dominio/URI, sin duplicados; 18 API y dos de infraestructura. `.env` ignorado y configuración privada preservada; no se ejecutaron migraciones/seeders de desarrollo ni envíos reales. Fase 7A backend completada; CU-04/7B, cierre integrado backend/diagrama global y frontend siguen pendientes.

### 17.25. Fase 7B backend — vinculación de Paciente histórico y código de Recepción (2026-10-19)

**Entrega implementada:** escenario B de CU-04 y emisión parcial de CU-07. POST `/api/pacientes/{id}/codigo-activacion` exige sesión normal habilitada de **Recepcionista**, origen SPA/CSRF, ficha con CI válido/sin cuenta y `identidad_verificada=true` tras la comprobación presencial humana. Devuelve 201 con identificador de ficha, código y vencimiento para entrega presencial una sola vez, bajo no-store; no crea cuenta ni modifica identidad/actividad. Administrador no hereda este permiso. POST `/api/auth/registro/vincular` recibe exclusivamente CI, código de Recepción, correo y contraseña/confirmación, con la misma respuesta 202 genérica/cookie protegida de 7A. Reutiliza reenvío/verificación públicos; al confirmar responde 204, elimina contexto y exige nuevo login.

**Política aplicada:** código de Recepción criptográfico alfanumérico de 12 caracteres, 24 horas/cinco errores/uso único, almacenado como HMAC-SHA256 ligado al ID de Paciente y separado del propósito del correo. Sustitución invalida autorizaciones anteriores y sus procesos/códigos de correo, limpiando hashes/datos temporales. El proceso Vinculacion vence junto con el código presencial original; inicio/reintentos/reenvíos no amplían ese plazo. CI conserva normalización vigente, incluso fichas antiguas con espacios ASCII exteriores. No se admiten IDs ni datos demográficos/control desde la vinculación pública.

**Reserva/confirmación:** exclusión compartida `(736202,1)` con 7A antes de ficha/proceso/códigos; emisión revalida además actor/sesión bajo bloqueo después de esperar. UQ parciales reservan ficha/código/CI/correo. Otra cookie no toma la reserva ni agota sus intentos; reintento propio conserva correo/clave/contexto/plazo y requiere código presencial correcto. Segundo código por correo conserva seis dígitos/diez minutos/cinco errores, reenvío 60 s/cinco emisiones por hora por proceso y correo, con cupo compartido entre escenarios A/B y contextos. Caducidad o agotamiento del código principal exige acudir a Recepción; agotar el código de correo permite reenvío dentro del plazo principal.

**Atomicidad/historia:** confirmación comprueba ambas autorizaciones vigentes, ficha/CI original y ausencia de cuenta, crea Usuario Activa/verificada de rol Paciente y vuelve a validar ambas vigencias después de posibles esperas de escritura. Asigna id_usuario a la **misma ficha**, consume ambos códigos, completa/limpia proceso y audita en una transacción. Preserva estado de ficha, identidad, citas, consultas, antecedentes y laboratorio; no crea otra ficha ni autentica automáticamente. UQ finales resisten altas externas al protocolo; 23505 devuelve 422 genérico con rollback de cuenta/vínculo/consumos. Falla de auditoría revierte también sustituciones. El envío externo se mantiene afterCommit, fuera de locks y simulado en pruebas; un fallo conserva proceso recuperable.

**Capas/contratos:** `EmitirCodigoActivacionRequest` → `CodigoActivacionController` → `CodigoActivacionService` → `CodigoActivacionRepository`, `RegistroPacienteRepository`, `AutenticacionService` y Bitácora. `VincularPacienteRequest` → `RegistroPacienteController` → `RegistroPacienteService` reutiliza repositorios/modelos existentes; `CodigoActivacionValidator` comprueba CI/vigencia y `RegistroPacienteContexto` agrega HMAC de Recepción. Nueva entrada `pacientes.php`, registrada una vez en routes/api.php. Código/CI/correo/contexto inválidos no se distinguen públicamente por el 202; verificar usa 422 genérico. Emisión protegida distingue 401/403/404/409/419/422/429. Controles técnicos: 20 solicitudes/minuto/IP y cinco/minuto por CI en vinculación, contexto en reenvío/verificación o Recepcionista en emisión; no sustituyen las cinco equivocaciones del código presencial. Bitácora conserva solo referencias seguras/responsables/resultados, sin códigos, claves, CI ni contenido clínico.

**Evidencia automatizada:** `VinculacionPacienteTest` cubre rol exclusivo, identidad/CI, entrada permitida, cookie/CSRF/origen, privacidad, intentos/vigencias/reenvíos, sustitución, reanudación y contexto ajeno, fallos de correo/auditoría, expiración durante escritura y normalización histórica. Comprueba conservación exacta de filas de citas, consultas, versiones clínicas, solicitudes/documentos/resultados ficticios y de una ficha inactiva. Ocho carreras nuevas en `AutenticacionConcurrenteTest` cubren reserva exclusiva, doble consumo, sustitución contra confirmación, incrementos concurrentes, correo compartido con Nuevo, revocación de Recepción mientras espera, UQ externa y emisiones simultáneas. Base/rol PostgreSQL y esquemas aislados; redes simuladas. Resultados finales en el cierre de verificación de esta sección.

**Documentación/estado:** análisis vigente CU-04 y emisión parcial CU-07 actualizado con tres diagramas horizontales adicionales (16 en total); contexto, README y nota funcional del DER sincronizados. Sin cambios funcionales de RF/RN, tablas/atributos/relaciones o migraciones: **28 entidades, 239 atributos y 43 relaciones**. Escenarios A/B de CU-04 implementados en backend. Siguiente fase **8**, integración/cierre backend y `diagrama-clases.md` global, todavía inexistente; CRUD administrativo restante de CU-07, React y entrega real de Brevo siguen posteriores. Las referencias a 7B pendiente en cierres anteriores describen su estado histórico.

**Cierre verificado de 7B:** suite completa final `php artisan test --compact`: **614 pruebas y 6193 aserciones correctas**, 207,87 s con margen de 300 s; añade **31 pruebas de vinculación y ocho carreras** a las 575 anteriores. Composer validate y autoload optimizado PSR-4/ambigüedad estrictos, Pint de 19 PHP y sintaxis de los 19 archivos afectados correctos. Registro efectivo: **22 rutas/27 combinaciones método-dominio-URI, sin duplicados**, 20 API y dos de infraestructura. Los 16 diagramas pasan Mermaid 10.9.5 y renderizado Chrome headless/DOM real sin solapamientos de nodos; tres nuevos revisados visualmente, métodos/atributos contrastados y expediente previo conservado íntegro. UTF-8, cercas, JSON documental y git diff --check correctos. `.env`/`.env.testing` permanecen ignorados; sin migraciones/seeders de desarrollo ni correo real. Fase 7B backend completada; fase 8/diagrama global e integración React/Brevo pendientes.

### 17.26. Fase 8 — integración y cierre backend de Usuarios y autenticación (2026-10-19)

**Decisión y alcance:** el usuario solicita finalizar fase 8 y pospone expresamente `diagrama-clases.md` para otro momento. Se cierra el **backend** de CU-01–CU-06, con la emisión parcial de CU-07 requerida para vinculación, sin declarar completo el módulo de interfaz ni crear/actualizar el diagrama global. Los registros anteriores conservan su estado histórico.

**Revisión integrada:** se contrastaron casos/reglas vigentes, análisis, rutas y contratos con Controllers/FormRequests/Resources, Services, Repositories, modelos y pruebas efectivos. CU-01/CU-02 normal/restringido; CU-03 alta/invitación, consulta/estado/roles, temporal y último Administrador; CU-04 A/B con autorización presencial; CU-05 ordinario y CU-06 de los cuatro roles cuentan con implementación y evidencia. Se conservan bloqueos 3/10, unicidades, permisos mínimos, vínculo/estado de ficha independientes, códigos/tokens con propósito y vigencia, revocación de sesiones, errores normalizados, auditoría sin secretos y transacciones PostgreSQL. El retiro del rol Médico mantiene el rechazo aprobado cuando el cierre asistencial no puede comprobarse.

**Pruebas nuevas de interacción:** `UsuariosAutenticacionIntegracionTest` añade tres recorridos con Kernel HTTP/cookies y CSRF reales, sesiones database cifradas y esquema PostgreSQL aislado. Dos recorren Paciente nuevo o histórico/inactivo → verificación → dos logins → CU-05 con renovación/revocación e invalidación de recuperación anterior → CU-06 → desactivación/reactivación de CU-03 → nuevo login/logout. Otro recorre invitación de Recepcionista → temporal con exclusión de recuperación → cambio obligatorio/login → cambio a Médico con revocación → recuperación conservando vínculo/rol. Verifica que invitación/recuperación no intercambien propósitos, que no exista login automático ni resurrección de cookies y que la sesión del Administrador continúe separada. Correo simulado y límites técnicos conservados; **3 pruebas/190 aserciones correctas**, 1,68 s en ejecución focalizada.

**Contratos/inventario:** README consolida las 20 rutas API y dos de infraestructura, con acceso y resultado. `route:list` confirma **22 declaraciones/27 combinaciones método-dominio-URI sin duplicados**. Se mantienen cuatro archivos funcionales registrados una vez. Errores con code/message/details seguros, 204 sin cuerpo, no-store y 202 no enumerativo en registro/recuperación. No se modificó lógica productiva ni persistencia: **28 entidades, 239 atributos y 43 relaciones**, con los modelos y migraciones ya existentes; DER revisado sin cambios necesarios. Arquitectura, fuentes y contratos mantienen los análisis CU-01–CU-06 y los 16 diagramas por procedimiento existentes.

**Documentación sincronizada:** contexto, análisis de clases vigente (matriz de cierre y colaboraciones integradas) y README. `diagrama-clases.md` se mantiene aplazado; no se altera el documento académico, DER `.drawio`, RF/RN ni reglas funcionales. El cierre funcional backend no acredita el cierre del diagrama ni del frontend.

**Pendientes posteriores explícitos:** interfaz React/Axios y prueba AUT-21 en navegador real; entrega real de mensajes mediante Brevo; configuración HTTPS/dominios/cookies/CORS y comprobación de buffering/finalización de respuestas diferidas en el entorno de producción. Correo permanece configurado privadamente y simulado en pruebas, sin afirmar llegada a buzón. CRUD restante de CU-07, módulos siguientes y procedimiento alternativo del Paciente sin correo siguen sus alcances propios. Ninguno autoriza ampliar permisos o inventar recuperaciones clínicas/administrativas en este cierre.

**Cierre verificado:** suite completa `php artisan test --compact --no-ansi`: **617 pruebas y 6383 aserciones correctas**, 229,82 s con margen de 300 s; incorpora las tres pruebas integradas a las 614 previas. PHP 8.5.11, Laravel 13.37.0/Sanctum 4.3.3; Composer validate, requisitos de plataforma y autoload optimizado con PSR-4/ambigüedad estrictos correctos. Pint sobre **192 PHP fuente** y sintaxis de **193 archivos** (incluye artisan; excluye cachés generadas) correctos. Los 16 diagramas existentes pasan Mermaid 10.9.5/Chrome headless; métodos/atributos contrastados, 29 anclas/enlaces y expediente previo conservados. UTF-8/cercas/JSON documental e inventario de 22 rutas coincidente con README comprobados; git diff --check correcto. No se modificó lógica productiva, dependencias, esquema ni secretos; sin migraciones/seeders de desarrollo ni envíos reales. **Fase 8 backend completada con diagrama global expresamente aplazado.** Los pendientes posteriores anteriores no se declaran resueltos por esta regresión.

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
