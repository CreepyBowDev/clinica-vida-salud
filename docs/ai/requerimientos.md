# Requerimientos — Clínica Vida Salud

> **Ruta canónica:** `docs/ai/requerimientos.md`  
> **Proyecto:** Sistema Web para la Gestión de Citas Médicas para la Clínica Vida Salud.  
> **Fuente obligatoria:** enunciado oficial «Examen 2 — Ingeniería de Requisitos — Sistema Web para la Gestión de Citas Médicas para la Clínica Vida Salud» (16 páginas).  
> **Fuente de precisiones del proyecto:** `Clinica_Vida_Salud_Captura_Actualizada(3).pdf` (104 páginas), capítulos 1, 3 y 4, y decisiones aprobadas reflejadas en `contexto-maestro.md` y `casos-de-us.md`.  
> **Estado:** especificación documental; **no implica implementación ni pruebas ejecutadas**.  
> **Revisión:** 18 de octubre de 2026.

## 1. Objetivo, lectura y jerarquía de fuentes

Este documento es la **referencia de requerimientos funcionales y técnicos** para desarrollar Clínica Vida Salud con React + Vite, Laravel y PostgreSQL. Debe servir a OpenCode para comprender **qué exige el examen**, **qué hemos definido adicionalmente** y **qué comprobaciones permiten verificarlo**. No sustituye a las fichas detalladas de los casos de uso ni al diseño de datos.

**Diferenciar siempre estas fuentes:**

1. **Obligatorio según el examen:** `RF01`–`RF13`, `RN01`–`RN14`, el perfil de paciente, condiciones técnicas, pruebas mínimas, documentación y entrega. **Se conservan sus identificadores y su significado**.
2. **Precisiones y ampliaciones aprobadas del proyecto:** desarrollan decisiones que el enunciado no fija de modo exhaustivo (ver secciones 5–8). **No se presentan como RF/RN textuales de la docente**.
3. **Diseño técnico:** rutas, clases, endpoints, tablas físicas, algoritmos y mecanismos específicos; se definen cuando corresponde y deben realizar los requerimientos, no sustituirlos.

Ante una discrepancia, contrastar el enunciado oficial y la decisión aprobada más reciente. No resolver contradicciones inventando funcionalidades ni modificando silenciosamente este archivo. La **numeración oficial** `RF01`–`RF13` y `RN01`–`RN14` permanece invariable; la numeración `CU-01`–`CU-30` corresponde exclusivamente a casos de uso.

**Clave de lectura:** cada RF contiene una descripción fiel del enunciado (parafraseada para consulta operativa sin cambiar sus obligaciones) y su trazabilidad. Las secciones posteriores describen complementos propios de Vida Salud.

## 2. Alcance, actores y límites

**Objetivo:** implementar un sistema web para registrar pacientes, médicos y especialidades; administrar horarios y citas; impedir conflictos de disponibilidad; gestionar asistencia, agenda, historial y reportes; y desarrollar las ampliaciones aprobadas de perfil, historia clínica, consultas, laboratorio, recordatorios y bitácora.

**Roles del examen (solo cuatro):**

| Rol | Responsabilidad principal aprobada | Límite de autorización |
| --- | --- | --- |
| **Administrador** | Configuración; cuentas y roles existentes del personal; estado de acceso de cuentas de Pacientes previamente verificadas; médicos, especialidades, consultorios y horarios; reportes; documentos de laboratorio; consulta de bitácora. | No crea cuentas de Pacientes ni cambia sus credenciales, rol o vinculación; no modifica antecedentes, diagnostica, marca Atendida, autoriza publicar resultados ni cambia fecha/hora de citas. |
| **Recepcionista** | Pacientes y registro administrativo de citas; reprogramación, cancelación, llegada e inasistencia; verificación presencial y emisión de códigos para vincular una ficha existente. | No define credenciales de pacientes, no administra cuentas del personal, no altera información clínica ni marca Atendida. |
| **Médico** | Agenda y pacientes asignados; consulta e historia clínica autorizadas; registro y actualización de antecedentes durante una atención real y autorizada; solicitudes y revisión de laboratorio; marca como Atendidas **sus propias citas** tras realizar la atención. | No administra cuentas ni cambia citas de terceros; no publica resultados ajenos. |
| **Paciente** | Registro/activación de cuenta, perfil permitido, reserva de citas **solo para sí**, seguimiento, historial propio, información clínica autorizada y recordatorios internos. | No se autoasigna roles, no reprograma ni cancela citas, no altera antecedentes, diagnósticos, tratamientos ni documentos de laboratorio. |

**Delimitación propia del proyecto:** una sede, varios consultorios identificados y atención únicamente **Presencial**. Excluidos pagos/cobros/pasarelas, farmacia, inventarios, recetas electrónicas, telemedicina, videollamadas, atención remota, múltiples sedes y administración dinámica de permisos. Exportar reportes CSV/PDF y excluir feriados/bloqueos de agenda son mejoras **opcionales**.

Las funciones clínicas y de laboratorio se incorporan como decisiones adicionales documentadas: no forman un nuevo conjunto de RF oficiales. Los cuatro roles conservan sus permisos fijos; la autenticación mediante **solo correo electrónico y contraseña** es la concreción elegida de la alternativa de identificación permitida por el examen.

---

## 3. Requisitos funcionales oficiales del examen (RF01–RF13)

### RF01 — Inicio de sesión y control de acceso

El sistema debe permitir acceso a usuarios autorizados mediante credenciales. Debe **validar las credenciales y la contraseña**, iniciar una sesión segura, identificar el rol, mostrar opciones acordes a sus permisos, permitir cerrar sesión e impedir acceder a páginas internas sin una sesión válida. Las contraseñas deben almacenarse con hash seguro de PHP y verificarse correctamente; Laravel puede emplear sus funciones `Hash::make` y `Hash::check` como implementación equivalente.

**Concreción vigente:** los cuatro roles utilizan **correo electrónico y contraseña**, sin alternativa por nombre de usuario; autorización efectiva en servidor, además del menú condicionado por rol.

**Trazabilidad:** `CU-01`, `CU-02`, `CU-03`, `CU-05`, `CU-06`; PN01–PN02; Alcance 1.4.1. **Comprobar:** no ingresar sin sesión o con un rol incorrecto; cierre invalida la sesión; contraseñas no legibles en base de datos.

### RF02 — Gestión de pacientes

El sistema debe registrar, listar, buscar, modificar, consultar citas anteriores y desactivar pacientes **sin eliminar sus historiales**. Datos: código único; CI/documento único **cuando corresponda**; nombres, apellidos, fecha de nacimiento, sexo según formulario, teléfono, correo de contacto opcional, dirección opcional y estado Activo/Inactivo. Se rechaza el registro duplicado con el mismo documento proporcionado.

**Concreción vigente:** fichas administrativas independientes de cuentas web; Recepción puede registrar pacientes sin cuenta ni correo de acceso. El Paciente puede registrarse en la web y vincularse con ficha existente mediante el procedimiento aprobado, conservando identificador e historia.

**Trazabilidad:** `CU-04`, `CU-07`, `CU-08`, `CU-21`; PN03–PN05, PN16; Alcance 1.4.2. **Comprobar:** no duplicar CI ni ficha; desactivar conserva citas; búsqueda por CI, nombre y apellido.

### RF03 — Gestión de médicos

El sistema debe registrar, listar, buscar por nombre o especialidad, modificar, consultar agenda y activar/desactivar médicos. Datos mínimos: código, número de registro profesional, nombres, apellidos, teléfono, correo, una o varias especialidades y estado Activo/Inactivo. Un Médico inactivo **no recibe nuevas citas** y conserva las históricas.

**Trazabilidad:** `CU-10`, `CU-20`; PN07–PN08, PN16; Alcance 1.4.3. **Comprobar:** desactivación impide nuevas reservas sin eliminar las anteriores.

### RF04 — Gestión de especialidades médicas

Administrar las especialidades con **identificador, nombre, descripción opcional y estado**, impidiendo nombres duplicados después de normalizarlos. El examen ofrece como ejemplos Medicina General, Pediatría, Ginecología, Odontología y Cardiología; **son ejemplos**, no una lista cerrada obligatoria.

**Concreción vigente:** Vida Salud prevé al menos Pediatría, Medicina General y Geriatría, con posibilidad de registrar otras especialidades conforme al catálogo administrado.

**Trazabilidad:** `CU-09`, `CU-10`; PN06–PN07; Alcance 1.4.3. **Comprobar:** duplicados normalizados rechazados; especialidades inactivas no se ofrecen en nuevas reservas.

### RF05 — Gestión de horarios de atención

El Administrador define los días y horas de atención por Médico y la **duración configurable** de la cita; el sistema calcula espacios disponibles. La hora final debe ser posterior a la inicial. Rechazar horarios inválidos, fechas pasadas, intervalos que no caben y turnos fuera de la atención. Excluir feriados/bloqueos es opcional.

**Concreción vigente:** duración inicial **15 minutos** (valor propio del proyecto, no duración impuesta por el examen); consultorio fijo por Médico y disponibilidad coordinada con la ocupación de ambos recursos.

**Trazabilidad:** `CU-11`, `CU-12`, `CU-13`; PN08–PN10; Alcance 1.4.4. **Comprobar:** configuración válida, capacidad horaria y disponibilidad correcta.

### RF06 — Registro de citas médicas

Recepción debe registrar una cita seleccionando **Paciente, Especialidad, Médico, fecha, hora disponible y motivo breve opcional**. Generar **código único de cita** y fecha de creación. Tras seleccionar Médico y fecha mostrar solo horarios disponibles. **Volver a verificar disponibilidad en servidor antes de guardar**.

**Concreción vigente:** además, Paciente puede reservar **su propia cita** desde la web; modalidad siempre Presencial, sede única y consultorio asignado; validar especialidad y Médico.

**Trazabilidad:** `CU-13`, `CU-14`; PN10–PN11; Alcance 1.4.5. **Comprobar:** generar cita Programada válida con código y fecha de creación.

### RF07 — Prevención de citas duplicadas

Impedir citas **superpuestas o incompatibles de un Médico**, impedir que excedan el horario y validar intervalos completos si hay duraciones variables. La validación es obligatoria en el **servidor**; transacciones o restricciones apropiadas de la base de datos constituyen una protección deseable frente a simultaneidad.

**Concreción vigente:** también se impide superposición de **Consultorio**, incluso entre Médicos distintos, y se exige protección real ante reservas/reprogramaciones concurrentes mediante Laravel y PostgreSQL.

**Trazabilidad:** `CU-12`–`CU-15`; PN09–PN12; Alcance 1.4.4–1.4.6. **Comprobar:** intervalos parcialmente superpuestos y dos solicitudes simultáneas no originan reservas incompatibles.

### RF08 — Reprogramación de citas

Recepción puede cambiar fecha y hora recuperando la cita, mostrando nuevas disponibilidades, verificando que el Médico pueda atender, evitando conflictos, guardando la modificación y conservando la integridad. Una cita Atendida o Cancelada **no admite reprogramación directa ordinaria**; el sistema debe definir su política.

**Concreción vigente:** solo Recepción reprograma citas Programadas/Confirmadas antes de atender; conserva código, paciente, Médico y especialidad. Confirmada regresa a Programada; una Programada permanece Programada. Atendida, Cancelada e Inasistencia son estados finales ordinarios.

**Trazabilidad:** `CU-15`; PN12; Alcance 1.4.6. **Comprobar:** ante conflicto, no perder la cita anterior ni crear una duplicada.

### RF09 — Cancelación de citas

Permitir cancelar una cita Programada, conservarla **sin borrado físico**, reflejar el estado Cancelada, preferiblemente registrar el motivo y **liberar el intervalo**. Una cita ya Atendida no puede cancelarse como si no hubiera sucedido.

**Concreción vigente:** Recepción cancela Programada o Confirmada antes de la atención; no existe cancelación del Paciente.

**Trazabilidad:** `CU-16`, `CU-21`; PN13; Alcance 1.4.6. **Comprobar:** conserva registro, historial y disponibilidad liberada.

### RF10 — Control de asistencia

Implementar los **cinco estados oficiales** de cita y controlar transiciones válidas:

| Estado oficial | Significado del examen |
| --- | --- |
| `Programada` | Cita reservada. |
| `Confirmada` | Asistencia confirmada. |
| `Atendida` | Consulta realizada. |
| `Cancelada` | Cita cancelada. |
| `Inasistencia` | Paciente no se presentó. |

Las operaciones incompatibles, como marcar una Cancelada como Atendida sin procedimiento autorizado, deben rechazarse. Las transiciones operativas aprobadas se especifican en la sección 6 y en `reglas-negocio.md`.

**Trazabilidad:** `CU-17`, `CU-18`, `CU-19`; PN14–PN15; Alcance 1.4.7. **Comprobar:** rechazar cualquier transición no autorizada o ejecutada por actor incorrecto.

### RF11 — Consulta de agenda médica

Permitir consultar la agenda del Médico por fecha con **hora, código de cita, nombre del Paciente, especialidad, estado y motivo breve cuando el rol esté autorizado**. Filtrar por Médico, fecha y estado.

**Trazabilidad:** `CU-20`; PN16; Alcance 1.4.8. **Comprobar:** filtros correctos y sin exposición de motivos a usuarios no autorizados.

### RF12 — Historial de citas por paciente

Consultar las citas de un Paciente con **fecha/hora, Médico, especialidad, estado y fecha de registro**, incluyendo citas pasadas y Canceladas sin perder trazabilidad.

**Trazabilidad:** `CU-07`, `CU-21`; PN04, PN16; Alcance 1.4.8. **Comprobar:** historial completo del Paciente correcto y acceso por titularidad/rol.

### RF13 — Reportes

Generar, como mínimo:

1. Citas Programadas para una fecha.
2. Citas por Médico.
3. Citas por Especialidad.
4. Citas por estado.
5. Pacientes registrados.
6. Cantidad de citas Atendidas, Canceladas y con Inasistencia en un período.
7. Agenda diaria de cada Médico.

Los reportes deben admitir **fecha o rango de fechas** y mostrar totales cuando corresponda. Exportación CSV/PDF: **opcional**.

**Trazabilidad:** `CU-29` (y consulta operativa `CU-20`–`CU-21`); PN24; Alcance 1.4.12. **Comprobar:** cobertura de los siete tipos, filtros y totales correctos.

---

## 4. Reglas del negocio oficiales del examen (RN01–RN14)

Estas **14 reglas son obligatorias** y no podrán modificarse al implementar. Se reproducen fielmente en su significado; sus verificaciones detalladas y decisiones adicionales corresponden a `reglas-negocio.md`.

| ID | Regla oficial | Evidencia/área de control |
| --- | --- | --- |
| **RN01** | Cada paciente tendrá un identificador único. | `CU-04`, `CU-07`; claves únicas. |
| **RN02** | Cada médico tendrá un identificador único. | `CU-10`; claves únicas. |
| **RN03** | Una cita deberá estar asociada a un paciente y a un médico válidos. | `CU-14` y FK. |
| **RN04** | El médico deberá estar activo para recibir nuevas citas. | `CU-13`–`CU-15`. |
| **RN05** | La cita deberá pertenecer al horario de atención del médico. | `CU-12`–`CU-15`. |
| **RN06** | No se podrán registrar citas en fechas pasadas. | `CU-13`, `CU-14`. |
| **RN07** | Un médico no podrá tener citas superpuestas. | `CU-14`, `CU-15`; concurrencia. |
| **RN08** | La duración de la cita deberá respetar la configuración del sistema. | `CU-12`, `CU-14`, `CU-15`. |
| **RN09** | Las citas canceladas no ocuparán disponibilidad. | `CU-16` y cálculo de turnos. |
| **RN10** | Las citas atendidas conservarán su registro histórico. | `CU-19`, `CU-21`. |
| **RN11** | No se eliminarán pacientes o médicos que tengan citas históricas asociadas; se desactivarán. | `CU-07`, `CU-10`. |
| **RN12** | Solo los usuarios autorizados podrán realizar cada operación. | Transversal; todos los CU protegidos. |
| **RN13** | Las operaciones de programación y reprogramación deberán verificar la disponibilidad en el servidor. | `CU-14`, `CU-15`. |
| **RN14** | Los datos se almacenarán de forma persistente en PostgreSQL. | Migraciones, repositorios y pruebas de persistencia. |

---

## 5. Perfil del Paciente y ampliaciones funcionales del proyecto

El examen contempla para el perfil: datos personales (nombre, documento, nacimiento y sexo cuando aplique), contacto (teléfono, correo y dirección), información médica (tipo de sangre, alergias, enfermedades crónicas, medicamentos y antecedentes), contacto de emergencia (nombre, parentesco y teléfono), gestión de citas (especialidad, Médico, fecha, hora, modalidad y estado), cuenta (contraseña, notificaciones y privacidad), registro/acceso, edición, reserva, seguimiento, recordatorios, historial médico y seguridad. **No todos esos detalles pertenecen al texto de RF02:** forman parte de la sección de perfil del enunciado y del Alcance aprobado.

Las siguientes precisiones son **decisiones propias** de Clínica Vida Salud, agrupadas por módulo. Describen comportamientos exigidos al software dentro del alcance aprobado, **sin crear RF oficiales nuevos**.

### 5.1. Usuarios y autenticación (`CU-01`–`CU-06`)

- Solo cuatro roles predefinidos, sin creación/edición de tipos de roles ni permisos dinámicos. Administrador crea/gestiona cuentas del personal; el Paciente crea su propia cuenta y jamás se autoasigna un rol de personal.
- **CU-03 / PN02:** el Administrador consulta datos administrativos mínimos y desactiva/reactiva únicamente el acceso de cuentas de Pacientes existentes y previamente verificadas. No puede crearlas, activar pendientes, restablecer sus contraseñas, cambiar correo, rol, permisos, identificador de Usuario o vínculo original, ni crear otra cuenta para la misma ficha. Antecedentes, citas, historial y resultados permanecen intactos; se auditan las operaciones sin secretos ni contenido clínico.
- **Correo electrónico verificado + contraseña** como único método ordinario de inicio de sesión de los cuatro roles; correo de acceso único e independiente del correo de contacto de la ficha.
- Contraseña de **mínimo 12 caracteres**, frases largas permitidas, hash seguro de Laravel. No duplicarla en Paciente o Médico, no mostrarla ni enviarla en texto plano como contraseña permanente.
- **Tres contraseñas incorrectas consecutivas** bloquean durante **10 minutos**; intentos durante bloqueo no lo prolongan; al terminar o autenticarse correctamente, el contador queda reiniciado. Límites adicionales de solicitudes por origen.
- Estados de la cuenta: Pendiente de verificación, Activa, Desactivada. El **bloqueo temporal** no es un estado administrativo y es independiente del estado de la ficha; desactivar invalida las sesiones existentes. Reactivar desde Desactivada solo habilita administrativamente el acceso con nuevo login y correo previamente verificado: no elimina bloqueos vigentes ni activa la ficha. Una ficha inactiva sigue impidiendo nuevas reservas. Suspender/rehabilitar equivale a desactivar/reactivar, sin crear Suspendida.
- Alta inicial del personal: **invitación segura de 24 horas**, de un solo uso, por Brevo para verificar correo y establecer contraseña. Reenvío invalida invitación previa y no crea segunda cuenta. Credencial temporal excepcional exige cambio antes de funciones normales.
- `CU-05` permite cambiar **contraseña propia** con comprobación de la anterior, salvo flujo restringido de contraseña inicial; invalidar sesiones anteriores.
- `CU-06` permite a los cuatro roles recuperar mediante **enlace de 60 minutos**, de un solo uso, a correo previamente verificado; nueva emisión invalida anterior; respuestas no revelan existencia de cuenta. Recuperación no inicia sesión automáticamente ni reactiva cuentas desactivadas. El restablecimiento administrativo de CU-03 es exclusivo del personal sin acceso a su correo verificado o sin uno verificado, tras comprobar identidad; no se aplica a Pacientes. Su alternativa si no pueden completar CU-06 sigue pendiente de definición y no se sustituye por administrar el estado de acceso.
- La recuperación exige volver a iniciar sesión. El cierre de sesión invalida credenciales de sesión. Todas las acciones protegidas verifican rol y vínculo con recurso en Laravel.
- **Brevo es solo para correos transaccionales de seguridad de cuentas**: invitación, verificación y recuperación; no para avisos de citas ni envío clínico.

### 5.2. Registro web y vinculación de pacientes (`CU-04`, `CU-07`)

**Paciente nuevo:** introduce datos y credenciales; se mantiene registro pendiente **24 horas** y verifica correo mediante **código numérico de seis dígitos**, válido **10 minutos**, con máximo **cinco intentos incorrectos**. Solo al completarse se activa la cuenta y se confirma su ficha única. **No requiere código de Recepción**.

**Paciente previamente registrado en Recepción, sin cuenta:**

1. Recepción verifica presencialmente identidad y documento y que la ficha no esté vinculada a otra cuenta.
2. Emite y entrega un código de activación aleatorio alfanumérico **de 12 caracteres**, válido **24 horas**, máximo **cinco errores**, de un solo uso y asociado al Paciente.
3. El Paciente introduce **CI, código de Recepción, correo y contraseña**; el servidor valida y reserva la vinculación sin dar acceso normal.
4. Brevo envía el **segundo código numérico del correo**: seis dígitos, **10 minutos**, máximo **cinco errores**.
5. Tras verificación correcta, una operación íntegra activa la cuenta, vincula **la ficha existente** y consume los códigos; conserva identificador, citas, historia y resultados autorizados sin duplicaciones.

**Vencimiento, reintentos y protección:** reenvío de código de correo con **al menos 60 segundos entre envíos** y **máximo cinco envíos por hora por proceso y correo**; un código nuevo invalida el anterior; no prolongar por reenvíos la vigencia principal de 24 horas. Código de Recepción vencido o con intentos agotados: nueva verificación presencial y emisión. Registro pendiente nuevo vencido: puede reiniciarse sin dejar una ficha definitiva duplicada. Si un CI ya está registrado, mensaje general sin revelar ficha, historia o cuenta. Para Paciente sin CI, identificación asistida según el caso. Protección ante peticiones concurrentes y restricción de **máximo una cuenta web por ficha**.

### 5.3. Pacientes y perfil (`CU-07`, `CU-08`, `CU-21`)

- Recepción registra, busca y actualiza datos **administrativos**. Puede emitir código de activación, pero no establecer contraseñas de pacientes.
- Paciente modifica sus datos personales, de contacto, emergencia y preferencias permitidas por CU-08; no modifica directamente antecedentes médicos, alergias clínicas, enfermedades crónicas, medicamentos/tratamientos, diagnósticos, consultas ni resultados. Recepción y Administrador no adquieren permisos clínicos.
- Cambiar **correo de acceso** en `CU-08`: comprobar contraseña actual, unicidad y código de verificación en el nuevo correo; el antiguo sigue siendo credencial hasta confirmar. Cambiar correo **de contacto** no cambia el de acceso.
- Paciente inactivo: **no recibe nuevas citas** propias ni por Recepción. Si su cuenta sigue activa, puede consultar información e historial autorizados; la inactivación no elimina citas. Recepción puede reprogramar **la misma cita ya existente** cuando las otras precondiciones lo permitan.

### 5.4. Médicos, especialidades, consultorios y horarios (`CU-09`–`CU-13`)

- Un Médico puede tener varias especialidades; una especialidad puede corresponder a varios Médicos. Solo especialidades activas con Médicos activos y horarios válidos se ofrecen para nuevas reservas.
- **Consultorio fijo asignado al Médico**, en única sede; varios Médicos pueden compartirlo en horarios compatibles. No superponer citas de un Médico **ni de un mismo consultorio**.
- Antes de cambiar consultorio fijo u horarios, detectar conflictos con citas futuras. Rechazar cambios incompatibles sin alterar parcialmente reservas. Cambiar consultorio puede actualizar únicamente la **ubicación** de citas futuras Programadas/Confirmadas cuando corresponda y sea seguro, sin modificar fecha/hora; solo Recepción reprograma.
- Duración inicial de **15 minutos**, configurable. Cada cita conserva duración de su registro; cambiar configuración no altera citas existentes; reprogramar aplica la duración vigente y revalida el intervalo completo.
- Médico profesionalmente inactivo: no recibe citas ni reprogramaciones a su favor. Si mantiene cuenta activa, solo puede consultar o completar atenciones/revisiones anteriores expresamente autorizadas; una cuenta desactivada impide accesos protegidos.

### 5.5. Registro, gestión y estados de citas (`CU-13`–`CU-21`)

- Recepción programa citas para Pacientes válidos; Paciente autenticado y activo reserva solo para su propio `id_paciente`. Se identifica una sede y **modalidad Presencial** sin selector de modalidades. Registrar código único y fecha de creación.
- Validar Médico y Especialidad activos, asociación profesional, horario y duración, consultorio y **doble ocupación**; comprobar en servidor de nuevo al confirmar, incluso bajo concurrencia.
- Citas nuevas comienzan en `Programada`. Los únicos otros estados son `Confirmada`, `Atendida`, `Cancelada`, `Inasistencia`; reprogramación **no es un sexto estado**.
- **Llegada real:** Recepción confirma `Programada → Confirmada` con identidad y presencia comprobadas **el mismo día local** y antes del final del intervalo; puede ser antes de la hora de inicio, no en otra fecha.
- **Atención:** solo Médico responsable registra `Programada/Confirmada → Atendida` después de atender, incluso si Recepción no confirmó llegada.
- **Cancelación:** solo Recepción, `Programada/Confirmada → Cancelada` antes de la atención, conserva historial y libera intervalo.
- **Inasistencia:** solo Recepción, `Programada → Inasistencia` tras terminar el turno y comprobar ausencia; no desde Confirmada.
- **Reprogramación:** solo Recepción antes de la atención para Programada/Confirmada; conserva el identificador y Programada queda Programada, Confirmada vuelve a Programada. Revalida ocupación y actualiza avisos. Atendida/Cancelada/Inasistencia son finales para acciones ordinarias.
- Conservar agenda, historial y ubicación histórica; no revelar motivos de consulta ni registros ajenos a usuarios sin permiso.

### 5.6. Historia clínica y consultas (`CU-22`, `CU-23`)

- Información clínica protegida: tipo de sangre, alergias, enfermedades crónicas, medicamentos y antecedentes familiares/relevantes, así como consultas autorizadas.
- **CU-23 / PN18:** únicamente el Médico responsable de una atención real y autorizada puede registrar, completar o corregir antecedentes clínicos; para ello consulta los existentes y documenta la información obtenida. El servidor valida cuenta, rol, relación con cita y Paciente y modificaciones permitidas. No crear consultas ficticias para justificar cambios. CU-22 permanece de consulta para Médico y Paciente según su autorización.
- **Máximo una consulta médica por cita**; pertenece al Paciente y Médico responsable; tipo control o enfermedad; motivo, síntomas, observaciones, diagnóstico e indicaciones cuando correspondan.
- Médico puede registrar consulta durante atención realizada o completar documentación después; no crear consulta como atención real de una cita Cancelada o con Inasistencia. **Registrar o modificar consulta no marca la cita Atendida**.
- Modificaciones clínicas relevantes de consultas y antecedentes conservan autor, fecha, motivo cuando corresponda y versiones anteriores y nuevas en almacenamiento protegido; no cambian automáticamente el estado de la cita. La bitácora general solo registra referencias y metadatos seguros, sin contenido médico sensible. Consulta de historia por relación asistencial; Paciente solo visualiza su información autorizada; Recepción y Administrador no modifican datos clínicos.

### 5.7. Laboratorio digital (`CU-24`–`CU-27`)

**Distribución de responsabilidades:** `CU-24` solicita estudios (Médico); `CU-25` carga o corrige documentos (Administrador); `CU-26` revisa, devuelve o autoriza resultados (Médico solicitante); `CU-27` consulta y descarga resultados autorizados (Médico o Paciente según su relación).

- Una consulta puede originar **varias solicitudes** de estudios. El documento externo emitido por laboratorio es PDF o imagen legible, protegido y asociado a su solicitud.
- Administrador **carga o corrige asociación/documento**, sin alterar contenido clínico ni publicar. Médico solicitante **revisa**, puede **autorizar directamente** si está correcto o **devolver con motivo** para nueva corrección y revisión.
- Estados internos exclusivos del laboratorio: `Pendiente`, `En revisión`, `Disponible`; el Paciente ve **Pendiente** hasta publicación. Solo descarga documento **vigente y autorizado** cuando esté Disponible.
- Versiones anteriores/erróneas permanecen protegidas; publicar una versión sustituida se rechaza. Los documentos nunca se servirán mediante rutas públicas ni permisos inferidos de React.

### 5.8. Recordatorios internos (`CU-28`)

- Notificaciones **automáticas, persistidas en PostgreSQL y únicamente internas**: regla propia del proyecto de aviso **24 horas antes de la fecha y hora programadas** de la cita. Para una reserva registrada exitosamente con menos de 24 horas de anticipación, generar después de guardarla correctamente en la base de datos o en la siguiente ejecución del programador de tareas, siempre que el recordatorio siga siendo válido y el intervalo no haya finalizado. Una reserva fallida no genera aviso. **La generación no depende del estado Confirmada ni modifica la cita**: una Programada puede generar su recordatorio y una Confirmada conservar los vigentes. Guardar la reserva mantiene su estado inicial Programada, sin confirmar automáticamente la llegada presencial, que corresponde exclusivamente a Recepción.
- La frecuencia específica del programador se definirá durante el diseño técnico; cada ejecución revalida vigencia, estados y preferencias. Generar no requiere intervención manual de Paciente o Recepción ni sesión activa del Paciente. Los avisos pueden asociarse a la ficha y cita sin cuenta web; si posteriormente se activa la cuenta se muestran solo recordatorios propios autorizados de citas futuras vigentes.
- Reprogramación y cambio autorizado de consultorio actualizan o invalidan el aviso; cancelar, Atendida, Inasistencia o turno finalizado dejan de mostrarlo como próximo. No duplicar por reintentos; respetar preferencias internas.
- **No** enviar recordatorios por Brevo, correo, WhatsApp, SMS ni notificaciones push externas.

### 5.9. Reportes (`CU-29`)

- Debe incluir todos los mínimos de `RF13` con filtros, totales y permisos del Administrador. No hace falta crear una tabla permanente `Reporte` para generar consultas.
- Exportación CSV/PDF permanece como mejora opcional.

### 5.10. Bitácora del Sistema (`CU-30`)

- Registrar automáticamente eventos relevantes de autenticación, administración, pacientes, médicos, citas, consultas **solo como metadatos**, laboratorio y seguridad.
- El **Administrador** es el único actor que consulta la bitácora general; ordenar por fecha descendente, paginar y filtrar por período, responsable, cuenta objetivo, módulo, operación, tipo, resultado, IP y registro afectado cuando corresponda.
- No permitir edición o eliminación **ordinarias**; preservar integridad. No guardar contraseñas, hashes, códigos, tokens, archivos clínicos, diagnósticos, historiales completos ni cuerpos sensibles de peticiones.
- Distinguir actor autenticado, evento anónimo y proceso del sistema cuando no existe usuario responsable. Las operaciones exitosas deben reflejar transacciones realmente confirmadas; los intentos rechazados se auditan de manera segura sin registrar éxitos falsos.
- El diseño exacto de campos, índices y restricciones de bitácora se consulta en el **DER vigente**. Una propuesta de campos aún no integrada o migrada no se considerará implementación.

---

## 6. Transiciones de citas acordadas (complemento propio, sin reemplazar RF10)

| Estado origen | Estado destino | Responsable | Condición |
| --- | --- | --- | --- |
| Alta de cita | `Programada` | Recepcionista o Paciente para sí | Reserva válida y disponibilidad confirmada. |
| `Programada` | `Confirmada` | Recepcionista | Llegada e identidad verificadas, mismo día de la cita, intervalo no finalizado. |
| `Programada` | `Atendida` | Médico responsable | Atención efectivamente realizada, aun sin confirmación previa de llegada. |
| `Confirmada` | `Atendida` | Médico responsable | Atención efectivamente realizada. |
| `Programada` | `Cancelada` | Recepcionista | Antes de realizarse la atención. |
| `Confirmada` | `Cancelada` | Recepcionista | Antes de realizarse la atención. |
| `Programada` | `Inasistencia` | Recepcionista | Intervalo finalizado y ausencia comprobada. |
| `Confirmada` | `Programada` | Recepcionista | Reprogramación válida antes de la atención. |
| `Programada` | `Programada` | Recepcionista | Reprogramación válida, sin cambiar estado. |

No se permiten cambios ordinarios desde `Atendida`, `Cancelada` o `Inasistencia`. No generar nuevos estados por la actividad de laboratorio, la disponibilidad o la reprogramación. **Las transiciones de esta tabla son decisiones de Vida Salud que concretan RF10.**

---

## 7. Requerimientos técnicos y de calidad exigidos por el examen

**Conservar las condiciones oficiales:**

| Área | Obligación del enunciado | Decisión concreta aprobada |
| --- | --- | --- |
| Backend | PHP | Laravel sobre PHP, servicios y repositorios. |
| Base de datos | PostgreSQL | Eloquent ORM, migraciones Laravel y constraints. |
| Conexión | PDO con `pdo_pgsql` o alternativa autorizada | Laravel/Eloquent mediante `pdo_pgsql`. |
| Frontend | HTML5 y CSS3 | React + Vite, componentes con HTML5 y CSS3. |
| Validación de interfaz | JavaScript opcional en el apartado técnico; el bloque final del examen solicita validaciones JavaScript y PHP | React/JavaScript en cliente, Laravel/PHP en servidor. |
| Servidor local | Apache/XAMPP compatible o entorno PHP equivalente | Entorno PHP compatible para Laravel; Vite por separado. |
| Protección SQL | Consultas parametrizadas | Eloquent/Query Builder y SQL parametrizado. |
| Validación | Datos revisados en cliente y servidor | Frontend informa; Laravel decide definitivamente. |
| Sesiones | Uso de sesiones para acceso | Laravel Sanctum SPA con cookies/sesiones y protección CSRF conforme al despliegue. |
| Errores | No mostrar credenciales ni detalles internos | Errores HTTP/JSON uniformes, excepciones seguras. |
| Modelo de datos | Base normalizada, sin duplicación innecesaria | DER, PK/FK, unicidades, integridad y PostgreSQL. |
| Arquitectura | Separación razonable entre presentación, negocio y datos | React → Axios → Laravel → Controller → Service → Repository → Eloquent. |
| Presentación | Diseño profesional y acceso desde navegador | Interfaz responsive y formularios accesibles. |
| Desarrollo | Buenas prácticas y comentarios pertinentes | Mantener código legible y pruebas de comportamiento. |

**Condiciones técnicas derivadas del proyecto y buenas prácticas aplicables:** proteger sesiones y cookies; validar servidor en todas las rutas; transacciones y concurrencia en reservas, activaciones y publicaciones; almacenar resultados de laboratorio de forma privada; no colocar secretos en variables `VITE_*`; usar `America/La_Paz` al interpretar fechas locales de atención; aplicar índices y restricciones según DER vigente. No inventar requisitos de rendimiento, disponibilidad porcentual, certificaciones médicas o retención legal no presentes en las fuentes.

### 7.1. Documentación y diseño que exige el examen

El examen prevé las fases académicas: Perfil, Marco Teórico, Modelo de Negocio, FT Captura de Requisitos, FT Análisis, FT Diseño (arquitectura, secuencias y datos), FT Implementación y FT Pruebas; exige un **diagrama de clases** y su posterior realización mediante PostgreSQL. Código organizado/comentado en GitHub y entrega de URL y QR del repositorio; portada, índice actualizado y bibliografía.

Vida Salud llevará documentación adicional de trabajo en `docs/ai/`. Antes de implementar un módulo, establecer un diseño inicial suficiente; al terminar un CU o grupo, actualizar `analisis-clases.md`; al cerrar un módulo, integrar sus clases en `diagrama-clases.md`; cuando cambie persistencia, actualizar `diagrama-entidad-relacion.md`. Esto **no significa** que el diseño de clases oficial pueda omitirse, ni que ya exista una implementación.

El apartado académico **4.5 Estructurar Modelo de Casos de Uso** está expresamente reservado para desarrollarse por separado: **no completarlo en esta tarea**.

---

## 8. Pruebas mínimas oficiales del examen

Las **diez pruebas obligatorias** del enunciado deben estar cubiertas por pruebas manuales o automatizadas verificables; las ampliaciones no pueden sustituirlas.

| Nº del examen | Prueba | Resultado esperado |
| --- | --- | --- |
| 1 | Registrar un paciente nuevo. | El paciente queda guardado. |
| 2 | Registrar paciente con CI duplicado. | El sistema rechaza el duplicado. |
| 3 | Programar una cita en horario disponible. | La cita queda registrada. |
| 4 | Programar otra cita superpuesta para el mismo Médico. | El sistema impide el conflicto. |
| 5 | Intentar reservar fuera del horario médico. | El sistema rechaza la cita. |
| 6 | Cancelar una cita. | Cambia a Cancelada y conserva historial. |
| 7 | Consultar historial de un Paciente. | Aparecen sus citas registradas. |
| 8 | Buscar citas de un Médico por fecha. | Se muestran resultados correctos. |
| 9 | Ingresar a una página interna sin sesión. | Acceso denegado. |
| 10 | Ejecutar operación con datos inválidos. | El servidor valida y rechaza la operación. |

**Pruebas complementarias aprobadas:** roles y titularidad; bloqueos y reintentos; activación de pacientes con los dos códigos y vencimientos; invitación del personal; recuperación y sesiones; consulta única por cita; consultas/versiones clínicas; publicación segura de laboratorio; recordatorios automáticos sin sesión; bitácora de solo lectura; validación frente a reservas concurrentes y consultorio compartido. Su ejecución y resultados **se acreditarán en el repositorio**; no se afirman realizados por existir esta lista.

---

## 9. Matriz de trazabilidad oficial → casos de uso

Esta matriz orienta la navegación y **no reemplaza** las referencias completas ni los flujos de `casos-de-us.md`.

| Requisito | CU principales | Proceso / alcance |
| --- | --- | --- |
| RF01 | CU-01, CU-02, CU-03, CU-05, CU-06 | PN01–PN02; 1.4.1 |
| RF02 | CU-04, CU-07, CU-08, CU-21 | PN03–PN05, PN16; 1.4.2 |
| RF03 | CU-10, CU-20 | PN07, PN16; 1.4.3 |
| RF04 | CU-09, CU-10 | PN06–PN07; 1.4.3 |
| RF05 | CU-11, CU-12, CU-13 | PN08–PN10; 1.4.4 |
| RF06 | CU-13, CU-14 | PN10–PN11; 1.4.5 |
| RF07 | CU-12, CU-13, CU-14, CU-15 | PN09–PN12; 1.4.4–1.4.6 |
| RF08 | CU-15 | PN12; 1.4.6 |
| RF09 | CU-16, CU-21 | PN13, PN16; 1.4.6 |
| RF10 | CU-17, CU-18, CU-19 | PN14–PN15; 1.4.7 |
| RF11 | CU-20 | PN16; 1.4.8 |
| RF12 | CU-21 | PN16; 1.4.8 |
| RF13 | CU-29 | PN24; 1.4.12 |

**Ampliaciones propias sin nuevo RF oficial:** `CU-22`–`CU-27` (historia clínica/consultas/laboratorio), `CU-28` (recordatorios internos; perfil de paciente del examen), `CU-30` (consulta de bitácora); activación con códigos y recuperación de credenciales concretan seguridad y registro. Se conserva un total de **30 casos de uso**, 13 módulos de alcance (`1.4.1`–`1.4.13`) y PN01–PN24 sin inventar PN25 de auditoría.

---

## 10. Estados, verificación y control de cambios de requisitos

Este archivo documenta **lo exigido y aprobado**, no el estado real de desarrollo. La tabla siguiente evita interpretar una especificación como una tarea concluida.

| Elemento | Estado documental | Estado de implementación |
| --- | --- | --- |
| RF01–RF13 | Definidos por enunciado oficial | **No verificado en código** |
| RN01–RN14 | Definidas por enunciado oficial | **No verificado en código** |
| Funciones complementarias de Vida Salud | Definidas en Alcance y CU-01–CU-30 | **No verificado en código** |
| Pruebas mínimas del examen | Definidas, diez casos | **Ejecución no verificada** |
| Diagrama de clases y DER | Requeridos/previstos; existe DER de referencia | **Migraciones no verificadas** |

**Antes de implementar:** consultar este archivo, `contexto-maestro.md`, `reglas-negocio.md`, `casos-de-us.md`, AGENTS correspondientes, el DER vigente y las fichas concretas. Identificar RF/RN relacionados, restricciones, actor, estado, pre/postcondiciones y pruebas de aceptación. No crear automáticamente un endpoint por requisito, una tabla por CU ni roles adicionales.

**Durante el desarrollo:** si se detecta una ambigüedad, contradicción o solicitud fuera de alcance, **documentarla y solicitar decisión** antes de alterar reglas. Los cambios aprobados deben sincronizar `requerimientos.md`, `reglas-negocio.md`, `casos-de-us.md` y, cuando correspondan, diagramas y pruebas. No cambiar el texto o identificador de RF/RN oficiales para acomodar una implementación.

**Al cerrar un caso o módulo:** aportar evidencia de código, pruebas ejecutadas y resultados; actualizar `analisis-clases.md` tras CU/grupo y `diagrama-clases.md` al terminar módulo; sincronizar DER ante cambios de persistencia. Reflejar avances verificados en `contexto-maestro.md` si afectan el estado global, y distinguir **Especificado / En desarrollo / Implementado / Verificado**.

## 11. Fuentes de consulta

- **Obligatoria:** examen oficial, 16 páginas; secciones 3–8 para roles, perfil, `RF01`–`RF13`, `RN01`–`RN14`, requerimientos técnicos y pruebas.
- **Académica del proyecto:** `Clinica_Vida_Salud_Captura_Actualizada(3).pdf`, 104 páginas; capítulos 1–4, con Alcance 1.4.1–1.4.14, procesos PN01–PN24 y fichas CU-01–CU-30.
- **En el repositorio:** `docs/ai/contexto-maestro.md`, `docs/ai/casos-de-us.md` y `docs/ai/reglas-negocio.md` (cuando exista); `AGENTS.md`, `backend/AGENTS.md` y `frontend/AGENTS.md`.
- **Persistencia:** versión vigente del DER `.drawio` y del archivo `docs/ai/diagrama-entidad-relacion.md` cuando se elabore; no deducir el esquema físico de esta especificación.

> **Regla final:** no confundir **exigencias oficiales**, **definiciones particulares de Clínica Vida Salud** y **decisiones técnicas**. Las tres deben ser coherentes, pero solo el enunciado define los RF y RN oficiales. Ningún módulo se considera implementado hasta verificarlo en el código y las pruebas.
