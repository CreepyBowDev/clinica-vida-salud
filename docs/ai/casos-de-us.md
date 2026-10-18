# Casos de uso — Clínica Vida Salud

> **Ruta canónica:** `docs/ai/casos-de-us.md`.  
> **Base vigente:** `Clinica_Vida_Salud_Captura_Actualizada(3).pdf` (104 páginas), especialmente capítulo 4, apartados 4.1–4.3.  
> **Referencia superior:** examen oficial del Segundo Parcial de Ingeniería de Requisitos, `RF01`–`RF13` y `RN01`–`RN14`.  
> **Estado documental:** los 30 casos están **especificados** en el PDF; su implementación y pruebas deben verificarse en el repositorio.  
> **Revisión documental:** 18 de octubre de 2026.

## 1. Objetivo y forma de uso

Fuente operativa de consulta para implementar los **30 casos de uso** con la numeración y reglas de la documentación académica. Contiene sus fichas funcionales, **no representa código implementado**, esquemas físicos definitivos ni contratos HTTP aprobados. Antes de trabajar un CU, consultar además `contexto-maestro.md`, `requerimientos.md`, `reglas-negocio.md`, el DER, el examen oficial y los AGENTS correspondientes. No convertir automáticamente cada paso en un endpoint ni inventar permisos por inferencia.

**Precedencia:** el examen es obligatorio; las decisiones aprobadas más recientes desarrollan lo no especificado; este archivo reproduce las fichas de la versión fuente. En caso de diferencia, contrastar la fuente y pedir definición en lugar de alterar silenciosamente requisitos. Los plazos, códigos, Brevo, módulos clínicos y bitácora son decisiones del proyecto cuando el examen no los exige expresamente.

**Precisiones aprobadas incorporadas:** CU-03 / PN02 incluye la administración exclusiva del estado de acceso de cuentas de Pacientes previamente verificadas; CU-23 / PN18 incluye el mantenimiento médico de antecedentes durante una atención autorizada. Se conservan títulos, identificadores y ciclos existentes. La denominación académica actualizada de CU-03 queda pendiente de cotejo con el Word vigente, no disponible en el repositorio; no se presume que estas precisiones ya figuren en las páginas del PDF citado ni que estén implementadas.

**Navegación:** cada caso tiene ancla de encabezado `CU-XX`; los números no se reinician por módulo. El **ciclo** es el registrado en 4.2/4.3; no equivale al estado de implementación.

## 2. Actores y límites comunes

| Actor | Responsabilidad y prohibiciones principales |
| --- | --- |
| Administrador | Cuentas de personal, roles predefinidos y estado de acceso de cuentas de Pacientes previamente verificadas; médicos, especialidades, consultorios, horarios, reportes, bitácora y documentación de laboratorio. No cambia credenciales, rol o vínculo de Pacientes, modifica antecedentes, diagnostica, publica resultados, marca Atendida ni reprograma fechas u horas. |
| Recepcionista | Fichas de pacientes, verificación presencial, códigos de activación, programación/reprogramación/cancelación, llegadas e inasistencias. No administra credenciales de pacientes ni modifica contenidos clínicos. |
| Médico | Agenda e información clínica autorizadas, consultas médicas propias y mantenimiento de antecedentes durante una atención real y autorizada, solicitudes y revisión/publicación de laboratorio y Atendida solo de citas propias. |
| Paciente | Registro/activación de su cuenta, perfil permitido, reserva únicamente para sí, historial, información clínica autorizada, resultados publicados y recordatorios internos. No edita antecedentes, reprograma ni cancela citas. |

En los flujos protegidos, el backend valida **sesión, rol, estado de cuenta y relación con el recurso**; React no sustituye la autorización. Brevo es servicio externo transaccional, no rol nuevo. Los procesos automáticos (notificaciones/auditoría) no crean un quinto actor.

## 3. Catálogo, correspondencia y priorización

| CU | Nombre | Actores | Ciclo | Riesgo | Ficha PDF |
| --- | --- | --- | :---: | --- | --- |
| [CU-01](#cu-01) | Iniciar sesión | Administrador, Recepcionista, Médico, Paciente | 1 | Alto | p. 70 |
| [CU-02](#cu-02) | Cerrar sesión | Administrador, Recepcionista, Médico, Paciente | 1 | Medio | p. 71 |
| [CU-03](#cu-03) | Administrar cuentas del personal y asignar roles | Administrador | 1 | Alto | p. 72 |
| [CU-04](#cu-04) | Registrar cuenta del Paciente y vincular su registro | Paciente | 1 | Alto | p. 73 |
| [CU-05](#cu-05) | Cambiar contraseña propia | Administrador, Recepcionista, Médico y Paciente | 1 | Alto | p. 75 |
| [CU-06](#cu-06) | Recuperar contraseña mediante correo electrónico | Administrador, Recepcionista, Médico y Paciente | 1 | Alto | p. 76 |
| [CU-07](#cu-07) | Gestionar el registro administrativo de pacientes | Recepcionista | 1 | Alto | p. 77 |
| [CU-08](#cu-08) | Actualizar el perfil personal | Paciente | 1 | Alto | p. 78 |
| [CU-09](#cu-09) | Gestionar especialidades médicas | Administrador | 1 | Medio | p. 79 |
| [CU-10](#cu-10) | Gestionar médicos y sus especialidades | Administrador | 1 | Alto | p. 80 |
| [CU-11](#cu-11) | Gestionar consultorios y su asignación fija | Administrador | 1 | Alto | p. 81 |
| [CU-12](#cu-12) | Configurar horarios y duración de citas | Administrador | 1 | Alto | p. 82 |
| [CU-13](#cu-13) | Buscar médicos y consultar disponibilidad | Recepcionista, Paciente | 1 | Medio | p. 83 |
| [CU-14](#cu-14) | Programar o reservar una cita presencial | Recepcionista, Paciente | 1 | Alto | p. 84 |
| [CU-15](#cu-15) | Reprogramar una cita | Recepcionista | 2 | Alto | p. 85 |
| [CU-16](#cu-16) | Cancelar una cita | Recepcionista | 2 | Alto | p. 86 |
| [CU-17](#cu-17) | Confirmar la llegada del Paciente | Recepcionista | 2 | Medio | p. 87 |
| [CU-18](#cu-18) | Registrar la inasistencia del Paciente | Recepcionista | 2 | Alto | p. 88 |
| [CU-19](#cu-19) | Marcar una cita como Atendida | Médico | 2 | Alto | p. 89 |
| [CU-20](#cu-20) | Consultar la agenda médica | Administrador, Recepcionista, Médico | 1 | Medio | p. 90 |
| [CU-21](#cu-21) | Consultar el historial y seguimiento de citas | Recepcionista, Paciente | 1 | Medio | p. 91 |
| [CU-22](#cu-22) | Consultar la historia clínica autorizada | Médico, Paciente | 2 | Alto | p. 92 |
| [CU-23](#cu-23) | Registrar o actualizar una consulta médica | Médico | 2 | Alto | p. 93 |
| [CU-24](#cu-24) | Solicitar estudios de laboratorio | Médico | 2 | Medio | p. 94 |
| [CU-25](#cu-25) | Cargar o corregir documentos de laboratorio | Administrador | 2 | Alto | p. 95 |
| [CU-26](#cu-26) | Revisar y autorizar la publicación de resultados | Médico | 2 | Alto | p. 96 |
| [CU-27](#cu-27) | Consultar solicitudes y descargar resultados autorizados | Médico, Paciente | 2 | Alto | p. 97 |
| [CU-28](#cu-28) | Consultar recordatorios internos de próximas citas | Paciente | 2 | Medio | p. 98 |
| [CU-29](#cu-29) | Generar reportes administrativos | Administrador | 2 | Medio | p. 99 |
| [CU-30](#cu-30) | Consultar bitácora del sistema | Administrador | 2 | Medio | p. 100 |

**Nota sobre el ciclo:** el examen y la priorización indican entregas por fases; el catálogo identifica funciones, no el orden exacto de programación. Todos los casos aquí son especificaciones, no tareas terminadas.

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
4. Si el acceso es válido, reinicia el contador de errores, genera una sesión segura e identifica uno de los cuatro roles fijos.
5. Muestra el panel correspondiente y comprueba sesión, permiso y relación con el recurso en cada petición posterior.
6. Registra el resultado de autenticación en la bitácora, sin guardar la contraseña.

**Postcondición**

Existe una sesión válida únicamente para una cuenta habilitada; los datos de acceso se conservan solo en la cuenta.

**Excepciones**

Credenciales incorrectas, cuenta inexistente, pendiente, desactivada o bloqueada: denegar con un mensaje general sin revelar si existe el correo. Durante el bloqueo no se autentica ni se amplían sus diez minutos. Al terminar, se reinicia el contador y se permiten nuevos intentos si la cuenta sigue activa. Una cuenta desactivada no recupera acceso por vencer el bloqueo. El cambio obligatorio de credencial temporal solo habilita el procedimiento restringido de CU-05.

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
#### CU-03 — Administrar cuentas del personal y asignar roles

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
6. Para cambios administrativos conserva el identificador y la historia; desactivar impide también el uso de sesiones anteriores.
7. Si el trabajador no puede recuperar su acceso por correo, el Administrador verifica su identidad y emite una invitación a un correo comprobado o una credencial temporal entregada por un medio seguro. Exige cambiarla antes de usar las funciones normales.
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

**Postcondición**

Las cuentas del personal quedan creadas o actualizadas con los permisos del rol existente; no se modifican permisos generales ni individuales. En cuentas de Pacientes solo cambia el estado administrativo de acceso: identidad, credenciales, verificación previa, vínculo y datos históricos permanecen intactos, al igual que el estado de la ficha y los bloqueos temporales vigentes. La desactivación invalida sesiones y la reactivación exige nuevo login.

**Excepciones**

Correo duplicado, datos inválidos, vínculo profesional incompatible o rol no permitido: rechazar. Invitación vencida, utilizada o envío fallido: permitir reenvío controlado por el Administrador sobre la misma cuenta e invalidar la anterior. La recuperación automática de los cuatro roles se realiza por CU-06; el restablecimiento administrativo es exclusivo del personal y no revela contraseñas anteriores. Las cuentas del Paciente se crean y verifican por CU-04, sin registro público del personal. Cuenta de Paciente inexistente, pendiente de verificación, vínculo incompatible, reactivación sin desactivación previa o petición de cambiar contraseña, correo, rol, permisos o vinculación: rechazar. No omitir bloqueos vigentes ni activar la ficha al reactivar acceso. Fallo o cambio simultáneo: no confirmar cambios parciales ni registrar éxito inexistente. Un Paciente sin acceso a su correo verificado no obtiene recuperación administrativa; la alternativa sigue pendiente de definición. La primera cuenta de Administrador se configura de forma segura durante la instalación.

<a id="cu-04"></a>
#### CU-04 — Registrar cuenta del Paciente y vincular su registro

**Trazabilidad de la ficha:** Alcance: 1.4.1 y 1.4.2. Proceso: PN03. Correspondencia: RF01, RF02. RN01, RN12, RN14. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 73.

**Propósito**

Crear la cuenta propia del Paciente y vincularla con una única ficha, sin exponer información ajena.

**Actores**

Paciente

**Iniciador**

Paciente, antes de autenticarse; Recepción interviene previamente mediante CU-07 cuando existe una ficha sin cuenta.

**Precondición**

No se requiere sesión. El solicitante dispone de un correo al que puede acceder. Puede existir una ficha administrativa previa sin cuenta web.

**Flujo principal**


**ESCENARIO A. PACIENTE NUEVO**

1. El Paciente introduce CI cuando corresponda, nombres, apellidos, fecha de nacimiento, sexo según formulario, teléfono, correo y contraseña; la dirección es opcional.
2. El servidor valida los campos y la unicidad del CI y del correo de acceso. Si no existe ficha previa, inicia un registro pendiente de 24 horas.
3. Envía por Brevo un código de correo de seis dígitos, vigente diez minutos.
4. El Paciente introduce el código. El servidor valida vigencia, intentos y uso, y completa de forma atómica una sola ficha y su cuenta activa. No exige código de Recepción.

**ESCENARIO B. FICHA PREEXISTENTE SIN CUENTA**

1. Si el CI ya está registrado, el sistema impide una ficha duplicada y muestra un mensaje general con las opciones de asistencia, acceso, recuperación y Ya tengo un código.
2. Recepción comprueba presencialmente la identidad y genera mediante CU-07 el código de activación asociado a la ficha existente.
3. El Paciente introduce CI, código de Recepción, correo electrónico y contraseña.
4. El servidor verifica correspondencia, vigencia, intentos y ausencia de cuenta vinculada; reserva de forma segura la activación pendiente para ese proceso.
5. Envía por Brevo un segundo código, numérico de seis dígitos, que verifica exclusivamente el correo indicado.
6. El Paciente introduce el segundo código. El servidor comprueba ambos controles y su vigencia y completa la cuenta, la vinculación y el consumo de los códigos en una operación atómica.
7. En ambos escenarios informa la activación y permite iniciar sesión mediante correo y contraseña; registra el evento en la bitácora.

**Postcondición**

Existe como máximo una cuenta por Paciente, vinculada a su único identificador. La ficha previa conserva citas, consultas y resultados autorizados. El registro pendiente no permite acceso protegido.

**Excepciones**

CI previo: no revelar nombre, historia, citas ni existencia o estado de cuenta; no vincular automáticamente. Con cuenta ya vinculada, impedir una segunda y ofrecer opciones generales de acceso o recuperación. Código incorrecto, vencido, usado o con cinco errores: rechazar. Si corresponde al de Recepción, exigir otro emitido tras verificar identidad. Para el de correo, permitir reenvío dentro de los límites y vigencia principal. Interrupción: reanudar el mismo proceso mediante un contexto protegido, sin duplicar fichas ni permitir que otra solicitud reutilice el código reservado. Envío fallido: informar sin confirmar un envío inexistente. Registro principal vencido: invalidar la activación pendiente; en B se necesita otro código de Recepción y en A puede reiniciarse el registro. Cambio simultáneo o fallo al guardar: no dejar vínculos parciales. Sin CI, aplicar identificación administrativa asistida sin duplicar registros.

**Notas y verificaciones de la ficha**

El código de Recepción acredita la autorización de vinculación; el del correo acredita control del buzón. Ninguno habilita por sí solo acceso clínico. Verificar el correo no prueba identidad civil.

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

Cuenta activa y sesión válida; se conoce la contraseña actual. Una sesión restringida puede exigir sustituir la credencial temporal antes del acceso normal.

**Flujo principal**

1. El usuario abre la seguridad de su cuenta.
2. Introduce su contraseña actual, la nueva y su confirmación.
3. El servidor comprueba titularidad, contraseña actual y política común de contraseñas.
4. Guarda el nuevo hash mediante Laravel sin duplicarlo en la ficha de Paciente o Médico.
5. Invalida las sesiones anteriores y renueva de forma segura la sesión actual; si el cambio era obligatorio, elimina esa restricción.
6. Registra el cambio en la bitácora sin incluir contraseñas ni hashes y confirma la operación.

**Postcondición**

La nueva contraseña queda vigente y la anterior deja de autenticar; no cambian rol, permisos, identificadores ni información relacionada.

**Excepciones**

Contraseña actual incorrecta, nueva contraseña inválida o confirmación diferente: rechazar sin cambios. Sesión inválida o cuenta ajena: denegar. Error al guardar: no confirmar el cambio. El restablecimiento administrativo del personal pertenece a CU-03, no a este caso.

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

No se necesita sesión. Para emitir el enlace debe existir una cuenta activa con correo verificado y cumplirse los límites de solicitudes.

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

Recepcionista

**Iniciador**

Recepcionista

**Precondición**

Recepción tiene una sesión válida; el Paciente puede existir sin cuenta web. La emisión de código exige comprobar presencialmente su identidad.

**Flujo principal**

1. Recepción lista o busca pacientes por CI, nombre o apellido antes de registrar otro.
2. Selecciona registrar, modificar, desactivar, consultar citas anteriores o generar un código de activación web.
3. Para la ficha conserva código único, CI cuando corresponda, nombres, apellidos, nacimiento, sexo según formulario y teléfono; correo de contacto y dirección son opcionales.
4. Para emitir el código localiza la ficha, verifica el documento y comprueba que no tenga cuenta vinculada.
5. El servidor genera un código aleatorio criptográfico alfanumérico de 12 caracteres, asociado al identificador del Paciente, vigente 24 horas y con un máximo de cinco errores. Lo almacena de forma segura e invalida el anterior y su activación pendiente si se sustituye.
6. Presenta el nuevo código para entregarlo de forma segura al titular y registra emisión o sustitución en la bitácora, sin su valor secreto.
7. Para las demás operaciones valida datos y permisos, guarda la ficha sin eliminar historia y permite consultar citas anteriores por CU-21.

**Postcondición**

La ficha mantiene su identidad e historia; el código autoriza iniciar CU-04, pero no crea por sí mismo una cuenta ni publica información clínica.

**Excepciones**

Paciente inexistente, identidad no comprobada, cuenta ya vinculada o petición sin permiso: no emitir código. CI duplicado o datos inválidos: rechazar cambios y permitir revisar el registro existente. Código vencido o agotado: solo Recepción puede emitir otro tras verificar nuevamente la identidad. Recepción no establece contraseñas del Paciente, no accede a ellas ni modifica diagnósticos. La desactivación no elimina fichas, citas ni cuentas relacionadas.

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

Cuenta activa, sesión válida y vinculación con la ficha personal. No se permite editar directamente antecedentes médicos, alergias registradas clínicamente, enfermedades crónicas, medicamentos/tratamientos, diagnósticos ni resultados de laboratorio.

**Flujo principal**

1. El Paciente abre Mi perfil; el servidor recupera sus datos personales, contacto de emergencia e información de salud autorizada.
2. Modifica datos personales, de contacto o emergencia y las preferencias internas permitidas; la información clínica autorizada se presenta solo para consulta.
3. El servidor verifica titularidad, campos y unicidad, sin cambiar el identificador ni la vinculación.
4. Guarda los cambios y registra las modificaciones administrativas relevantes en la bitácora.
5. El correo de contacto se conserva separado del de acceso; el cambio de contraseña se realiza exclusivamente por CU-05.

**Flujo alternativo**

CAMBIO DEL CORREO DE ACCESO
1. El Paciente autenticado confirma su contraseña actual e introduce el nuevo correo.
2. El servidor comprueba unicidad y crea una solicitud protegida, sin sustituir el correo vigente.
3. Brevo envía al nuevo correo un código según la política existente: seis dígitos, diez minutos, cinco errores y los límites de reenvío ya definidos.
4. El Paciente introduce el código; el servidor valida su contexto, vigencia y uso.
5. Antes de confirmar vuelve a comprobar la unicidad y la cuenta, para impedir cambios simultáneos incompatibles.
6. Actualiza únicamente el correo de acceso y consume el código de forma íntegra; mantiene la cuenta y la ficha vinculada, e invalida solicitudes de recuperación asociadas al correo anterior.
7. Registra el cambio sin códigos ni contraseñas. Hasta entonces solo el correo anterior permite iniciar sesión.

**Postcondición**

El perfil o correo de acceso quedan actualizados solo tras sus validaciones. No cambian contraseña, rol, permisos, antecedentes, alergias clínicas, enfermedades crónicas, medicamentos/tratamientos, diagnósticos, consultas, resultados ni vínculos de la cuenta.

**Excepciones**

Contraseña actual incorrecta, correo ocupado, código inválido, vencido o agotado, envío fallido o cuenta sin autorización: rechazar el cambio de acceso. Interrupción o conflicto simultáneo: conservar el correo anterior y evitar cambios parciales o cuentas duplicadas. Los reenvíos respetan las políticas existentes. Ficha ajena o intento de editar antecedentes u otros campos clínicos: denegar. El correo de contacto no verifica ni modifica automáticamente el de acceso.


### 4.3. Médicos y especialidades (`CU-09` a `CU-10`)

<a id="cu-09"></a>
#### CU-09 — Gestionar especialidades médicas

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

<a id="cu-10"></a>
#### CU-10 — Gestionar médicos y sus especialidades

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

El médico y sus relaciones quedan actualizados; la inactivación conserva la información anterior. Si el Médico queda inactivo no recibe nuevas citas ni reprogramaciones a su favor. Con cuenta activa puede completar atenciones y revisiones ya asignadas; la desactivación de la cuenta impide todo acceso protegido.

**Excepciones**

Código duplicado, especialidad inválida o datos incompletos: no guardar y señalar el problema. Intento de eliminación con historial: aplicar desactivación en lugar de eliminar sus registros.

**Notas y verificaciones de la ficha**

Un médico puede pertenecer a varias especialidades y cada especialidad a varios médicos; el servidor verifica su asociación al guardar citas.


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


### 4.5. Registro y reserva de citas presenciales (`CU-13` a `CU-14`)

<a id="cu-13"></a>
#### CU-13 — Buscar médicos y consultar disponibilidad

**Trazabilidad de la ficha:** Alcance: 1.4.3 a 1.4.5. Proceso: PN10. Correspondencia: RF03, RF05, RF06. RN04, RN05, RN06, RN07, RN08, RN12 Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 83.

**Propósito**

Encontrar médicos activos de una especialidad y conocer sus turnos presenciales disponibles.

**Actores**

Recepcionista, Paciente

**Iniciador**

Recepcionista, Paciente

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

<a id="cu-14"></a>
#### CU-14 — Programar o reservar una cita presencial

**Trazabilidad de la ficha:** Alcance: 1.4.5. Proceso: PN11. Correspondencia: RF05, RF06, RF07. RN03, RN04, RN05, RN06, RN07, RN08, RN12 RN13, RN14. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 84.

**Propósito**

Registrar una cita válida y sin conflictos para el propio Paciente o para un paciente seleccionado por Recepción.

**Actores**

Recepcionista, Paciente

**Iniciador**

Recepcionista, Paciente

**Precondición**

Existe sesión autorizada y un paciente válido; el Paciente dispone de su cuenta vinculada. Hay turnos calculados mediante CU-13. El registro administrativo del Paciente está activo, tanto para reservas propias como para programación por Recepción.

**Flujo principal**

1. Recepción selecciona al paciente; si actúa el Paciente, el sistema utiliza exclusivamente su registro vinculado.
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


### 4.6. Reprogramación y cancelación (`CU-15` a `CU-16`)

<a id="cu-15"></a>
#### CU-15 — Reprogramar una cita

**Trazabilidad de la ficha:** Alcance: 1.4.6. Proceso: PN12. Correspondencia: RF05, RF07, RF08. RN04, RN05, RN06, RN07, RN08, RN10, RN12 RN13, RN14. Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 85.

**Propósito**

Cambiar fecha y hora de una cita sin perder integridad, historial ni la disponibilidad de los recursos.

**Actores**

Recepcionista

**Iniciador**

Recepcionista

**Precondición**

Recepción tiene sesión válida. La cita está Programada o Confirmada sin atención realizada; Médico y especialidad están activos. Se admite la misma cita de un Paciente inactivo, sin crear otra.

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

**Trazabilidad de la ficha:** Alcance: 1.4.6. Proceso: PN13. Correspondencia: RF09. RN09, RN10, RN12, RN14. Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 86.

**Propósito**

Cancelar una reserva antes de la atención, liberar su turno y conservar el registro histórico.

**Actores**

Recepcionista

**Iniciador**

Recepcionista

**Precondición**

Recepción inició sesión; existe una cita Programada o Confirmada cuya atención todavía no se realizó.

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

Cita Atendida, Cancelada o con Inasistencia: rechazar el cambio ordinario. Intento del Paciente, Administrador u otro rol: denegar, incluso mediante petición directa. Fallo al actualizar: conservar la situación anterior y no informar una cancelación exitosa.

**Notas y verificaciones de la ficha**

Nunca cancelar una atención realizada como si no hubiera ocurrido; no hay eliminación física del registro.


### 4.7. Estados de citas y control de asistencia (`CU-17` a `CU-19`)

<a id="cu-17"></a>
#### CU-17 — Confirmar la llegada del Paciente

**Trazabilidad de la ficha:** Alcance: 1.4.7. Proceso: PN14. Correspondencia: RF10. RN12, RN14. Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 87.

**Propósito**

Registrar que Recepción verificó la presencia efectiva del Paciente para su cita.

**Actores**

Recepcionista

**Iniciador**

Recepcionista

**Precondición**

Recepción tiene sesión válida; el Paciente está presencialmente identificado, la cita está Programada, corresponde al mismo día local y su intervalo no ha finalizado. No existe atención, cancelación o inasistencia registradas.

**Flujo principal**

1. Recepción localiza la cita y verifica la presencia e identidad del Paciente.
2. Solicita Confirmar llegada.
3. El servidor utiliza la fecha y hora local de la clínica, America/La_Paz, y valida el mismo día, turno no finalizado, estado Programada y atención no registrada.
4. Permite llegada anticipada dentro del mismo día; vuelve a comprobar el estado ante cambios simultáneos.
5. Registra Confirmada, fecha y hora y responsable; genera el evento de auditoría y muestra el estado.
6. No crea una consulta ni marca Atendida: CU-19 permanece exclusivo del Médico responsable.

**Postcondición**

La cita pasa únicamente de Programada a Confirmada por llegada presencial verificada, con registro de responsable y momento.

**Excepciones**

Paciente ausente, otro día, turno finalizado, atención realizada, cita Cancelada, Atendida, con Inasistencia o ya Confirmada: rechazar. Si llega después del fin y la cita sigue Programada sin atención, Recepción podrá reprogramar mediante CU-15 y confirmar solo cuando el nuevo turno cumpla las condiciones; no se cambia un estado final por este procedimiento. Otro rol o modificación simultánea: denegar o mostrar el estado vigente. No se introduce un estado de tardanza.

**Notas y verificaciones de la ficha**

Probar llegada anticipada el mismo día, fecha futura o pasada y llegada tras el fin. CU-18 conserva Inasistencia desde Programada, ausencia comprobada y turno finalizado; CU-19 permite Atendida desde Programada tras una atención real

<a id="cu-18"></a>
#### CU-18 — Registrar la inasistencia del Paciente

**Trazabilidad de la ficha:** Alcance: 1.4.7. Proceso: PN14. Correspondencia: RF10. RN12, RN14. Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 88.

**Propósito**

Registrar la ausencia comprobada cuando ya terminó el intervalo de una cita.

**Actores**

Recepcionista

**Iniciador**

Recepcionista

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

**Trazabilidad de la ficha:** Alcance: 1.4.7 y 1.4.9. Proceso: PN15. Correspondencia: RF10. RN10, RN12, RN14. Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 89.

**Propósito**

Registrar que el Médico responsable realizó la atención de su propia cita.

**Actores**

Médico

**Iniciador**

Médico

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

Otro médico, Administrador, Recepción o Paciente: denegar la operación. Cancelada, Inasistencia, Atendida o atención no realizada: rechazar el cambio ordinario.

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

**Postcondición**

Se muestra la agenda solicitada sin conceder acceso a citas o datos no autorizados.

**Excepciones**

Médico ajeno solicitado por un Médico o parámetros inválidos: denegar o solicitar corrección. Sin citas: mostrar un resultado vacío; no tratarlo como error ni crear registros.

**Notas y verificaciones de la ficha**

La reserva, el registro clínico y los cambios de estado requieren sus propios casos de uso y controles.

<a id="cu-21"></a>
#### CU-21 — Consultar el historial y seguimiento de citas

**Trazabilidad de la ficha:** Alcance: 1.4.2 y 1.4.8. Proceso: PN16. Correspondencia: RF02, RF12. RN01, RN10, RN11, RN12, RN14. Ciclo: 1.  
**Estado documental:** Especificado · **Ciclo:** 1 · **Fuente:** PDF, página 91.

**Propósito**

Recuperar las citas de un paciente y seguir su situación sin perder las pasadas o canceladas.

**Actores**

Recepcionista, Paciente

**Iniciador**

Recepcionista, Paciente

**Precondición**

Existe sesión válida; Recepción consulta el paciente localizado y el Paciente solo su registro vinculado.

**Flujo principal**

1. Recepción abre el historial del paciente; el Paciente abre Mis citas.
2. El servidor verifica la autorización y la relación entre cuenta y paciente.
3. Recupera todas las citas asociadas, incluidas las pasadas y canceladas.
4. Muestra fecha y hora, médico, especialidad, estado, fecha de registro, modalidad Presencial y consultorio conservado.
5. El usuario abre un detalle autorizado o consulta las citas pendientes y concluidas según los cinco estados.
6. El sistema mantiene las ubicaciones históricas aunque cambie el consultorio fijo del médico.

**Postcondición**

El historial queda disponible en modo consulta sin alterar citas ni registros clínicos.

**Excepciones**

Paciente ajeno solicitado por una cuenta Paciente: denegar sin exponer datos. Paciente sin citas: mostrar el historial vacío; conservar su registro personal.

**Notas y verificaciones de la ficha**

El Paciente no reprograma ni cancela desde Mis citas. El historial administrativo no reemplaza la historia clínica.


### 4.9. Historia clínica y consultas médicas (`CU-22` a `CU-23`)

<a id="cu-22"></a>
#### CU-22 — Consultar la historia clínica autorizada

**Trazabilidad de la ficha:** Alcance: 1.4.9. Proceso: PN17. Correspondencia: Perfil del Paciente y decisiones clínicas del proyecto. RN12, RN14 Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 92.

**Propósito**

Acceder a los antecedentes y atenciones pertinentes del paciente conforme a la relación de atención y la titularidad.

**Actores**

Médico, Paciente

**Iniciador**

Médico, Paciente

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

Recepción no obtiene acceso clínico por gestionar reservas ni el Administrador por gestionar el acceso de cuentas. CU-22 no permite editar antecedentes; su registro, ampliación o corrección corresponde exclusivamente al Médico responsable durante una atención real y autorizada por CU-23. El Paciente solo consulta su información clínica autorizada.

<a id="cu-23"></a>
#### CU-23 — Registrar o actualizar una consulta médica

**Trazabilidad de la ficha:** Alcance: 1.4.9. Proceso: PN18. Correspondencia: Perfil del Paciente y decisiones clínicas del proyecto. RN12, RN14 Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 93.

**Propósito**

Documentar la atención realizada por el Médico responsable y registrar, completar o corregir los antecedentes clínicos obtenidos durante esa atención autorizada, con un máximo de una consulta por cita.

**Actores**

Médico

**Iniciador**

Médico

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

Cita Cancelada o con Inasistencia, atención no realizada o ficticia, cita ajena, cuenta desactivada o datos inválidos: rechazar. Administrador, Recepción y Paciente no pueden modificar antecedentes ni otros datos clínicos, incluso mediante petición directa. Segunda consulta simultánea: rechazar y abrir la existente. El Médico profesionalmente inactivo con cuenta activa puede completar atenciones ya asignadas, sin recibir nuevas citas. Un fallo no confirma consulta, antecedentes ni versión parcial. Las versiones clínicas permanecen protegidas.


### 4.10. Laboratorio digital (`CU-24` a `CU-27`)

<a id="cu-24"></a>
#### CU-24 — Solicitar estudios de laboratorio

**Trazabilidad de la ficha:** Alcance: 1.4.9 y 1.4.10. Proceso: PN19. Correspondencia: Perfil del Paciente y decisiones clínicas del proyecto. RN12 RN14. Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 94.

**Propósito**

Registrar los estudios requeridos para una atención y relacionarlos con su consulta clínica.

**Actores**

Médico

**Iniciador**

Médico

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

**Trazabilidad de la ficha:** Alcance: 1.4.10. Proceso: PN21. Correspondencia: Perfil del Paciente y decisiones clínicas del proyecto. RN12, RN14 Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 96.

**Propósito**

Comprobar la correspondencia del resultado y autorizar su publicación o devolverlo para corrección.

**Actores**

Médico

**Iniciador**

Médico

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

**Trazabilidad de la ficha:** Alcance: 1.4.9 y 1.4.10. Proceso: PN22. Correspondencia: Perfil del Paciente y decisiones clínicas del proyecto. RN12 RN14. Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 97.

**Propósito**

Consultar el seguimiento de estudios y acceder a documentos de resultado únicamente con autorización.

**Actores**

Médico, Paciente

**Iniciador**

Médico, Paciente

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
#### CU-28 — Consultar recordatorios internos de próximas citas

**Trazabilidad de la ficha:** Alcance: 1.4.11. Proceso: PN23. Correspondencia: Perfil del Paciente y decisiones clínicas del proyecto. RN12, RN14 Ciclo: 2.  
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 98.

**Propósito**

Consultar los avisos automáticos de próximas atenciones desde la cuenta del Paciente.

**Actores**

Paciente

**Iniciador**

Paciente para consultar; generación automática interna sin sesión.

**Precondición**

Existen Paciente y cita pertinente, con o sin cuenta web. Generar no requiere sesión; consultar exige cuenta activa vinculada y sesión válida. Se respetan preferencias internas.

**Flujo principal**

1. El sistema calcula el aviso a 24 horas del inicio en la fecha y hora local de la clínica.
2. Una reserva registrada con menos de 24 horas de anticipación genera el aviso inmediatamente después de que su registro se haya guardado y confirmado satisfactoriamente en la base de datos, o en la siguiente ejecución programada inmediata pertinente, antes del fin del turno. No depende de cambiar al estado Confirmada: una cita Programada puede generar su recordatorio. La reserva nueva permanece inicialmente Programada; guardarla no confirma automáticamente la llegada presencial, que corresponde exclusivamente a Recepción.
3. Aplica preferencias y guarda un único aviso por Paciente, cita y programación: especialidad, Médico, fecha, hora, Presencial, Clínica Vida Salud y consultorio.
4. Sin cuenta web, conserva la relación interna; al activarla muestra solo avisos propios todavía vigentes.
5. Al consultar Notificaciones, el servidor valida sesión y titularidad.
6. Reprogramar recalcula y actualiza o invalida el aviso anterior; cambiar una ubicación autorizada actualiza el existente.
7. Cancelada, Atendida, Inasistencia o turno finalizado retiran los avisos próximos. Laravel y PostgreSQL generan y conservan los avisos sin depender del navegador.

**Postcondición**

El Paciente consulta únicamente recordatorios internos propios, pertinentes y sin duplicación; no obtiene facultades de reprogramación ni cancelación.

**Excepciones**

Sin cuenta: conservar la relación sin exponerla a terceros; no impide programar por Recepción. Preferencias deshabilitadas: no generar nuevos avisos. Al habilitarlas se aplican las reglas a citas aún pertinentes, sin duplicados. Sin avisos o cita no pertinente: mostrar la sección sin próximos recordatorios. Sesión inválida, aviso ajeno o fallo al guardar: denegar o informar sin confirmar éxito falso. No se usan Brevo, correo, SMS, WhatsApp ni push externo para citas.


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
**Estado documental:** Especificado · **Ciclo:** 2 · **Fuente:** PDF, página 100.

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


## 5. Reglas transversales que no se deben perder

### 5.1. Autenticación y verificación

- Acceso de todos los roles mediante **correo electrónico y contraseña**. Tres errores consecutivos bloquean 10 minutos, sin prolongar el bloqueo por nuevos intentos; políticas de cuenta pendiente, activa y desactivada independientes del estado administrativo del Paciente/Médico.
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
- Recordatorios de citas: **internos**, persistentes, automáticos, normalmente 24 horas antes; no enviar correo/SMS/WhatsApp/push externo.
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
