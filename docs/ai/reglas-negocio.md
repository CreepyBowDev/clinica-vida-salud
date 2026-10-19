# Reglas de negocio — Clínica Vida Salud

> **Ruta canónica:** `docs/ai/reglas-negocio.md`  
> **Proyecto:** Sistema Web para la Gestión de Citas Médicas para la Clínica Vida Salud.  
> **Fuente obligatoria:** enunciado oficial del Segundo Examen Parcial de Ingeniería de Requisitos (16 páginas).  
> **Fuente de decisiones aprobadas:** `Clinica_Vida_Salud_Captura_Actualizada(3).pdf` (104 páginas), apartados 1.4, 3.2–3.3 y 4.1–4.3, complementado por `contexto-maestro.md`, `requerimientos.md` y `casos-de-us.md`.  
> **Estado:** reglas **especificadas**, no necesariamente implementadas ni verificadas.  
> **Revisión de la consolidación:** 18 de octubre de 2026.

## 1. Propósito y convenciones

Este documento agrupa **restricciones y decisiones de negocio verificables**, que deben ser aplicadas en el backend independientemente de lo que envíe React. No define tablas finales, endpoints, clases, código ejecutado ni migraciones ya creadas. El detalle de cada interacción está en `casos-de-us.md`; los requisitos funcionales y técnicos, en `requerimientos.md`.

**Jerarquía de fuentes:** (1) examen oficial; (2) decisiones explícitamente aprobadas más recientes y compatibles con el examen; (3) documentación académica vigente; (4) documentos de trabajo `docs/ai/`; (5) implementación real, que constituye evidencia pero no puede cambiar requisitos silenciosamente. Ante una contradicción, **identificarla y resolverla con la fuente autorizada**, no inventar una regla.

Se distinguen dos familias de identificadores:

- **`RN01`–`RN14`:** reglas **oficiales** del examen, conservadas sin renumerar ni reinterpretar como nuevas exigencias.
- **`VS-XXX-NN`:** reglas **particulares de Clínica Vida Salud**, derivadas de las definiciones aprobadas. Estas claves facilitan la trazabilidad y **no son nuevos códigos RN del examen**. Si cambia una decisión, actualizar su regla con la referencia de la decisión; no duplicarla.

Las abreviaturas de proyecto son `GEN` (generales), `AUT` (autenticación), `PAC` (pacientes/perfil), `MED` (médicos/especialidades), `HOR` (consultorios/horarios), `CIT` (citas), `EST` (estados/asistencia), `HIS` (agenda/historial), `CLI` (clínica), `LAB` (laboratorio), `NOT` (recordatorios), `REP` (reportes), `AUD` (bitácora) e `INT` (integridad transversal).

**Aplicación:** toda operación protegida valida **sesión, rol, pertenencia/titularidad, estado y condiciones del caso**. Las reglas funcionales pertenecen a la capa de servicios de Laravel, acompañadas de controles de autorización y restricciones PostgreSQL cuando corresponda. Ocultar un botón en React **no equivale** a impedir la operación.

---

## 2. Reglas de negocio OFICIALES del examen (RN01–RN14)

Los siguientes enunciados preservan las reglas expresas de la sección **«Reglas del negocio»** del examen. Deben aplicarse también ante peticiones manipuladas o concurrentes.

| ID oficial | Enunciado del examen | Verificación mínima | Relación principal |
| --- | --- | --- | --- |
| **RN01** | Cada paciente tendrá un identificador único. | No hay dos fichas con el mismo identificador. | RF02; CU-04, CU-07 |
| **RN02** | Cada médico tendrá un identificador único. | No hay dos profesionales con el mismo identificador. | RF03; CU-10 |
| **RN03** | Una cita deberá estar asociada a un paciente y a un médico válidos. | Cita sin cualquiera de esas relaciones se rechaza. | RF06; CU-14 |
| **RN04** | El médico deberá estar activo para recibir nuevas citas. | No se asignan citas nuevas a médico inactivo. | RF03, RF06; CU-10, CU-14 |
| **RN05** | La cita deberá pertenecer al horario de atención del médico. | El intervalo entero cabe en el horario habilitado. | RF05–RF07; CU-12–CU-15 |
| **RN06** | No se podrán registrar citas en fechas pasadas. | Rechazar fechas/instantes anteriores a lo permitido. | RF05–RF07; CU-13, CU-14 |
| **RN07** | Un médico no podrá tener citas superpuestas. | Rechazar solapamiento del intervalo completo. | RF07; CU-12–CU-15 |
| **RN08** | La duración de la cita deberá respetar la configuración del sistema. | La duración se valida al registrar/reprogramar. | RF05, RF07; CU-12, CU-14, CU-15 |
| **RN09** | Las citas canceladas no ocuparán disponibilidad. | El turno de cita Cancelada puede ofrecerse nuevamente si es válido. | RF09; CU-13, CU-16 |
| **RN10** | Las citas atendidas conservarán su registro histórico. | Marcar Atendida no elimina la cita ni sus relaciones. | RF10–RF12; CU-19–CU-21 |
| **RN11** | No se eliminarán pacientes o médicos que tengan citas históricas asociadas; se desactivarán. | La baja conserva ficha e historia; no borra referencias. | RF02, RF03, RF12; CU-07, CU-10, CU-21 |
| **RN12** | Solo los usuarios autorizados podrán realizar cada operación. | El servidor deniega el rol o la relación no autorizados. | RF01; todos los CU protegidos |
| **RN13** | Las operaciones de programación y reprogramación deberán verificar la disponibilidad en el servidor. | Verificación al confirmar, no solo al mostrar turnos. | RF06–RF08; CU-14, CU-15 |
| **RN14** | Los datos se almacenarán de forma persistente en PostgreSQL. | La información confirmada permanece en PostgreSQL. | Transversal; RF01–RF13 |

**No se modifican ni sustituyen estas 14 reglas por las ampliaciones del proyecto.** Las especialidades, el consultorio, las verificaciones de correo y la bitácora se regulan a continuación como precisiones propias.

---

## 3. Reglas generales y límites del proyecto

| ID propio | Regla comprobable | Trazabilidad |
| --- | --- | --- |
| **VS-GEN-01** | La aplicación corresponde a **una sola sede**, Clínica Vida Salud, y únicamente ofrece citas **Presenciales**; la modalidad se registra sin permitir otras opciones. | 1.4, 1.4.5; CU-14 |
| **VS-GEN-02** | Existen **exactamente cuatro roles funcionales**: Administrador, Recepcionista, Médico y Paciente. Sus permisos son **fijos**; la aplicación no crea, modifica ni administra permisos individuales o tipos nuevos de rol. | RN12; CU-01, CU-03 |
| **VS-GEN-03** | El **Administrador** administra cuentas del personal y solo el estado de acceso de cuentas de Pacientes previamente verificadas, además de profesionales, especialidades, consultorios, horarios, reportes, documentos de laboratorio y consulta de bitácora. **No** modifica antecedentes, diagnostica, publica resultados ni marca citas Atendidas. | CU-03 / PN02, CU-09–CU-12, CU-25, CU-29, CU-30 |
| **VS-GEN-04** | La **Recepcionista** gestiona fichas administrativas y citas; verifica identidad, emite códigos de vinculación, registra llegada e inasistencia. **No** crea contraseñas del Paciente, no diagnostica, no marca Atendida y no administra cuentas del personal. | CU-07, CU-14–CU-18 |
| **VS-GEN-05** | El **Médico** consulta únicamente su agenda y los datos clínicos autorizados; registra su atención y mantiene antecedentes de pacientes bajo su responsabilidad durante una atención real y autorizada, solicita/revisa laboratorio y marca **solo sus citas** Atendidas después de atender. **No** administra cuentas ni horarios generales. | CU-19, CU-20, CU-22–CU-27; PN18 |
| **VS-GEN-06** | El **Paciente** reserva únicamente para su ficha vinculada, consulta información propia autorizada y edita su perfil permitido. **No** reprograma ni cancela citas ni accede a expedientes ajenos. | CU-04, CU-08, CU-14, CU-21, CU-22, CU-27, CU-28 |
| **VS-GEN-07** | Se excluyen pagos/pasarelas, farmacia, inventarios, recetas electrónicas, telemedicina, múltiples sedes y administración dinámica de permisos. Exportación CSV/PDF y feriados o bloqueos de agenda son **opcionales**, no deben desplazar los RF obligatorios. | Alcance 1.4 |

---

## 4. Usuarios, autenticación y seguridad de cuentas (CU-01–CU-06)

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-AUT-01** | Los cuatro roles inician sesión **solo con correo electrónico y contraseña**; el nombre de usuario **no** es alternativa de acceso. El correo de acceso es único y debe estar verificado para usar funciones protegidas. | RF01, RN12; CU-01 |
| **VS-AUT-02** | La contraseña se guarda mediante **hash seguro de Laravel**, nunca en texto plano ni duplicada en fichas de Paciente o Médico. Política mínima: **12 caracteres**, admitiendo frases largas; validar en el servidor. | RF01; CU-03–CU-06 |
| **VS-AUT-03** | Después de **tres contraseñas incorrectas consecutivas**, la cuenta sufre bloqueo de **10 minutos**. Los intentos durante ese período no lo amplían; al expirar se reinicia el contador, al igual que tras un acceso correcto. | CU-01 |
| **VS-AUT-04** | Estados de cuenta: **Pendiente de verificación**, **Activa**, **Desactivada**. Estado de cuenta, estado de ficha y **bloqueo temporal** son independientes. Una cuenta pendiente no accede a funciones normales; una desactivada no accede aun si el bloqueo vence o recupera contraseña. Reactivar no activa la ficha ni elimina o salta bloqueos vigentes. Suspender/rehabilitar significa desactivar/reactivar, sin crear Suspendida. | CU-01, CU-03–CU-07 |
| **VS-AUT-05** | Cada solicitud protegida comprueba sesión válida, rol y relación con el recurso; desactivar una cuenta invalida todas sus sesiones anteriores. El cierre de sesión invalida la sesión en el servidor. Una reactivación administrativa exige nuevo login y no restablece las sesiones invalidadas. | RN12; CU-01, CU-02, CU-03 |
| **VS-AUT-06** | El Administrador crea **cuentas del personal** con roles existentes; el personal confirma su correo y establece contraseña mediante **invitación de 24 horas y un solo uso**. Un reenvío invalida la invitación anterior y no duplica la cuenta. Única excepción inicial: Administrador provisionado durante instalación según VS-AUT-07. | CU-03 |
| **VS-AUT-07** | El usuario Médico del personal queda vinculado con el registro profesional correspondiente; los Pacientes crean sus propias cuentas por CU-04, no por la administración general de personal. El primer Administrador se crea en instalación local controlada por el responsable autorizado: cuenta Activa, correo único, contraseña privada de mínimo 12 caracteres, hash seguro, marca de correo verificado por autorización de instalación y sin cambio obligatorio. No usar formulario público, credenciales predeterminadas ni secretos versionados o registrados en logs. No exige código ni invitación y no acredita control del buzón; recomendar correo legítimo bajo control del responsable para recuperación. Registrar origen SISTEMA sin secretos. La creación es idempotente: conserva cuentas, credenciales y estado al repetir; no activa cuentas previas ni crea otra identidad de instalación. No es alta general de Administradores, no aplica a Pacientes ni completa CU-01. La solución técnica existente se documenta en contexto §17.12. | CU-03, CU-04; excepción de instalación |
| **VS-AUT-08** | CU-03 permite al Administrador restablecer credenciales exclusivamente del personal (Administrador, Recepcionista o Médico), previa comprobación de identidad y sin conocer/exigir la contraseña anterior. El sistema genera una credencial temporal segura, con vencimiento y uso controlado; tras validarla solo permite formulario de contraseña definitiva, envío/confirmación y cierre de sesión. La restricción se impone en backend, sin funciones ordinarias ni acceso clínico. Al completar: guardar el nuevo hash, invalidar/consumir definitivamente la credencial, eliminar cambio obligatorio, revocar sesiones incompatibles, registrar metadatos seguros, cerrar la sesión restringida y exigir nuevo login por CU-01. No convertirla automáticamente en normal. No aplica a Pacientes, no activa cuentas desactivadas ni omite verificaciones pendientes o bloqueos. Es distinto de la invitación para primera contraseña y de CU-05/CU-06. | CU-01, CU-02, CU-03; VS-AUT-27 |
| **VS-AUT-09** | CU-05 es el cambio ordinario propio para los cuatro roles: cuenta activa, sesión **normal** válida, contraseña actual, nueva válida de mínimo 12 caracteres y confirmación. Se invalidan sesiones anteriores y se renueva de forma segura la actual; no cambia identidad, rol ni permisos. La primera contraseña del personal por invitación pertenece a CU-03 y no exige una anterior inexistente; el cambio obligatorio por credencial temporal sigue VS-AUT-08, no hereda la renovación de sesión ordinaria. | CU-03, CU-05 |
| **VS-AUT-10** | La recuperación de los cuatro roles usa **correo previamente verificado** y enlace seguro de **60 minutos, un solo uso**, sin contraseña anterior; emitir otro invalida el anterior. Tras recuperar, se invalidan sesiones existentes y se exige **volver a iniciar sesión**. No se reactiva una cuenta desactivada. Es independiente del restablecimiento administrativo y de la invitación inicial. | CU-06 |
| **VS-AUT-11** | Las respuestas de acceso y recuperación **no revelan** si un correo tiene cuenta. Aplicar límites de solicitudes por correo/origen y registrar eventos relevantes sin contraseñas, códigos ni tokens. Si el trabajador carece de correo verificado o no tiene acceso, usar la verificación administrativa de CU-03. **El restablecimiento administrativo de credenciales es exclusivo del personal, nunca de Pacientes**; su alternativa sin acceso al correo verificado sigue pendiente de definición. | CU-01, CU-03, CU-06; CU-30 |
| **VS-AUT-12** | **Brevo** se utiliza para correos transaccionales de seguridad: verificación, invitaciones y recuperación. **No** envía recordatorios de citas ni información clínica. | CU-03, CU-04, CU-06, CU-28 |

### 4.1. Registro y vinculación de cuentas de pacientes (CU-04 y CU-07)

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-AUT-13** | Una ficha `Paciente` puede existir **sin cuenta web**, pero podrá vincularse como máximo a **una** cuenta propia; una cuenta Paciente no puede vincularse a dos fichas. No duplicar identificadores ni registros históricos. | RN01, RF02; CU-04, CU-07 |
| **VS-AUT-14** | **Paciente nuevo por web:** exige CI no vacío y los datos aprobados; validar formato y unicidad del CI normalizado y correo en el servidor, además de la validación de interfaz. Si el CI corresponde a una ficha previa, no crear otra: aplicar vinculación autorizada. Inicia un proceso **pendiente por 24 horas**. Recibe un **código de correo de seis dígitos**, vigente **10 minutos**, y solo tras verificarlo se completa de forma atómica una ficha y su cuenta de rol Paciente, sin datos clínicos ficticios. **No** exige código de Recepción. | CU-04 |
| **VS-AUT-15** | **Paciente ya registrado por Recepción, con CI válido y sin cuenta:** Recepción comprueba **presencialmente su identidad**, correspondencia del CI y ausencia de cuenta; emite código criptográficamente seguro, alfanumérico de **12 caracteres**, ligado a su ficha, válido **24 horas**, con **cinco errores máximos**. Conocer únicamente el CI no autoriza vincular ni leer datos históricos. | CU-04, CU-07 |
| **VS-AUT-16** | En la activación de un Paciente existente se introducen obligatoriamente **CI + código de Recepción + correo + contraseña**. El servidor valida correspondencia entre CI, ficha y código, unicidad del correo y ausencia de cuenta vinculada; reserva de forma segura el proceso. Brevo envía **otro código de seis dígitos** para probar control del correo. Solo se vincula al validar ambos controles vigentes y revalidar la exclusividad, con consumo atómico. Ningún código sustituye al otro. | CU-04 |
| **VS-AUT-17** | El **código de correo** vence en **10 minutos** y acepta como máximo **cinco errores** por código. Reenvíos: mínimo **60 segundos** entre envíos y máximo **cinco por hora** por proceso/correo, junto con límites por origen. Un código nuevo invalida el anterior. | CU-04, CU-08 |
| **VS-AUT-18** | Un nuevo código de Recepción invalida el anterior; al expirar o agotar intentos debe emitirse otro **tras nueva verificación presencial**. Un registro pendiente o reenvío **no prolonga** las 24 horas de la activación principal. | CU-04, CU-07 |
| **VS-AUT-19** | En una vinculación existente, el código de Recepción queda **reservado** durante la activación pendiente y se **consume definitivamente** junto al código de correo cuando la vinculación se completa. No se permite que otro proceso use el mismo código reservado. | CU-04 |
| **VS-AUT-20** | Los registros pendientes no permiten acceso protegido. Las solicitudes simultáneas, errores de envío y transacciones fallidas no deben originar una segunda cuenta, una ficha duplicada o una vinculación parcial; los reintentos reutilizan el proceso válido. | CU-04 |
| **VS-AUT-21** | Cuando un CI ya pertenece a una ficha, no revelar por el registro público si existen citas, datos clínicos o cuenta; mostrar orientación **genérica** sobre asistencia en Recepción, acceso o recuperación. El código de correo confirma el buzón, **no** identidad civil. | CU-04 |
| **VS-AUT-22** | El CI es **obligatorio para crear o vincular una cuenta web** en CU-04 por decisión de Clínica Vida Salud, no por exigencia literal de RF02 para toda ficha. Sin CI, rechazar el registro/vinculación web y orientar a Recepción, sin alternativas web ni documentos ficticios. CU-07 permite excepcionalmente ficha administrativa con documento NULL e identificador interno único, con operaciones presenciales autorizadas. La unicidad del CI se exige cuando se proporciona; su incorporación posterior conserva la misma ficha según VS-PAC-01. | RF02; CU-04, CU-07 |

### 4.2. Administración del acceso de cuentas de Pacientes (CU-03)

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-AUT-23** | Solo el Administrador con sesión válida consulta datos administrativos mínimos y desactiva/reactiva cuentas de Pacientes **existentes y previamente verificadas**. El servidor valida permiso, cuenta y vínculo; desactivar invalida sesiones y reactivar solo procede desde Desactivada con correo previamente verificado, nuevo login y respeto de bloqueos vigentes según VS-AUT-04/05. No activa cuentas pendientes ni la ficha administrativa; una ficha inactiva sigue impidiendo nuevas reservas. Ambas operaciones se registran en Bitácora con referencias y metadatos seguros. | CU-03 / PN02; CU-01, CU-07, CU-30 |
| **VS-AUT-24** | Administrar el acceso conserva identificador de Usuario, **rol Paciente**, contraseña, correo de acceso, vínculo con el mismo Paciente y sus antecedentes, citas, historial y resultados. No crea cuentas adicionales, asigna permisos, cambia identidad/credenciales, omite verificaciones ni constituye recuperación de identidad o contraseña. El Paciente sigue creando/verificando su cuenta por CU-04 y gestionando sus credenciales por CU-05/CU-06 y su correo por CU-08. | CU-03–CU-08; VS-AUT-11, VS-AUT-13 |

### 4.3. Cambios de rol y continuidad administrativa — políticas aprobadas

Precisiones propias del proyecto aprobadas posteriormente; no son RF/RN literales del examen ni acreditan implementación.

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-AUT-25** | CU-03 permite Administrador ↔ Recepcionista, y el paso a Médico solo con registro profesional legítimo de la misma persona y vínculo inicial o histórico compatible, no asignado a otra identidad. Validar autorización y requisitos del rol destino; conservar Usuario, identidad, atribución histórica y vínculo profesional. No convertir Paciente ↔ personal ni reasignar una cuenta del Médico A al Médico B. Todo cambio autorizado revoca sesiones, exige nuevo login y registra responsable/resultado sin contenido clínico. Asignar Médico no crea horarios, especialidades, consultorios ni citas automáticamente ni habilita reservas sin sus condiciones ordinarias. | CU-03, CU-10; VS-AUT-27 |
| **VS-AUT-26** | Para abandonar Médico por Administrador/Recepcionista, comprobar y resolver antes citas futuras y obligaciones asistenciales incompatibles, incluidas atenciones y revisiones de laboratorio propias pendientes. Si existe obligación o no puede verificarse su resolución con el modelo vigente, rechazar la transición insegura e informar la limitación sin contenido clínico innecesario; no inventar cierres, indicadores o estados ni guardar una transición pendiente. No cancelar, reprogramar, trasladar o reasignar citas/resultados automáticamente. Conservar registro y vínculo profesional, citas, consultas, versiones y autores. Rol, cuenta y actividad profesional son independientes: la inactivación necesaria se realiza expresamente por CU-10, coordinando la comprobación con nuevas reservas y operaciones asistenciales, no por efecto automático del cambio de rol. | CU-03, CU-10, CU-14–CU-16, CU-19, CU-23–CU-26 |
| **VS-AUT-27** | Impedir desactivaciones, cambios de rol y restablecimientos administrativos que dejen sin al menos un Administrador habilitado para acceso normal: rol Administrador, Activa, activación/verificación aplicable completada, hash existente y sin limitación exclusiva de cambio obligatorio. Incluir operaciones sobre la propia cuenta. Un bloqueo pasajero no equivale a desactivación ni elimina su habilitación administrativa para este cómputo, aunque CU-01 rechace el login mientras dure. La protección no garantiza disponibilidad permanente ante bloqueos u olvidos. Validar el estado resultante transaccionalmente entre Services/PostgreSQL, también ante operaciones simultáneas; contar antes sin coordinación no basta. Conservar la excepción del primer Administrador activo de VS-AUT-07. | CU-03; VS-AUT-03, VS-AUT-04, VS-AUT-08, VS-AUT-25 |

### 4.4. Credencial temporal — soporte aprobado y diseño técnico pendiente

Se aprueba ampliar `TokenAcceso.proposito` con **`CredencialTemporal`**, separado de `InvitacionPersonal` y `RecuperacionClave`, reutilizando hash, emisión, expiración, consumo e invalidación. No añade entidades, atributos, relaciones, roles o estados. El catálogo no implementa por sí solo emisión, autorización ni consumo atómico; su soporte físico se registra en el DER §6.15.

Quedan por definir antes de implementar el flujo temporal de CU-03: vigencia concreta, mecanismo criptográfico y entrega segura, límites de intentos/reemisión/usos y momento de consumo, revocación, tratamiento de contraseña anterior al emitir, verificación pendiente, expiración y coordinación con sesiones y recuperaciones/cambios concurrentes. No deducir plazos de los de invitación/recuperación ni usar sus propósitos para este flujo. No almacenar secretos legibles en logs, bitácora o archivos versionados. La falta de criterios comprobables de cierre clínico sigue siendo una limitación, no permiso para retirar el rol Médico. Estos pendientes no impiden diseñar e implementar CU-01/CU-02 con los controles definidos y denegación de funciones normales ante cambio obligatorio.

---

## 5. Pacientes, perfil e inactivación (CU-07, CU-08 y CU-21)

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-PAC-01** | Recepción registra, busca por CI/nombre/apellido, modifica y desactiva la **ficha administrativa**. Puede registrar excepcionalmente sin CI, almacenando NULL, nunca un documento ficticio o ajeno. Revisar posibles fichas previas para reducir duplicados: nombres, nacimiento o teléfono no prueban identidad ni autorizan fusiones automáticas; la resolución de identidades dudosas queda pendiente de diseño administrativo. Al obtener CI, Recepción comprueba identidad y documento; el servidor valida formato y unicidad y se actualiza la misma ficha con trazabilidad, conservando identificador interno, citas, consultas, antecedentes y resultados, sin modificación clínica ni cuenta web automática. La ficha sin CI conserva operaciones presenciales autorizadas; emitir código web requiere CI y los controles de VS-AUT-15. Correo de contacto y dirección pueden ser opcionales en alta administrativa. | RF02, RN01, RN11; CU-07 |
| **VS-PAC-02** | La ficha de Paciente y la cuenta de acceso **no son el mismo registro**; el correo de contacto es distinto del correo de acceso y cambiar el primero no cambia credenciales. | CU-04, CU-07, CU-08 |
| **VS-PAC-03** | El Paciente edita únicamente **sus** datos personales, contacto, emergencia y preferencias permitidas. No cambia mediante CU-08 antecedentes médicos, alergias registradas clínicamente, enfermedades crónicas, medicamentos/tratamientos, diagnósticos, consultas ni resultados; solo consulta su información clínica autorizada. | RN12; CU-08, CU-22 |
| **VS-PAC-04** | Para cambiar su **correo de acceso** debe confirmar contraseña actual, verificar nuevo correo con el código aprobado, validar que sea único al iniciar y al confirmar, y conservar el correo anterior hasta completar el proceso; nunca crear otra cuenta. | CU-08 |
| **VS-PAC-05** | Un Paciente con ficha **inactiva no recibe citas nuevas**, ni por cuenta propia ni por Recepción. Si la cuenta web sigue activa, puede **consultar** solo sus citas e información histórica autorizada. Desactivar ficha no equivale automáticamente a desactivar la cuenta. | CU-07, CU-14, CU-21, CU-22 |
| **VS-PAC-06** | La inactivación no borra citas, consultas, solicitudes ni resultados. **La misma cita existente**, si sigue Programada o Confirmada y aún no fue atendida, puede reprogramarse por Recepción cumpliendo CU-15, pero no se crean otras reservas. | RN11; CU-15, CU-21 |

---

## 6. Médicos y especialidades (CU-09 y CU-10)

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-MED-01** | Cada Médico tiene código único y número de registro profesional; puede pertenecer a **una o varias especialidades**, y cada especialidad a varios Médicos. La asociación se comprueba al programar. | RF03–RF04, RN02; CU-09, CU-10, CU-14 |
| **VS-MED-02** | El Administrador administra especialidades con identificador, nombre, descripción opcional y estado; **rechaza nombres normalizados duplicados**. | RF04; CU-09 |
| **VS-MED-03** | Una especialidad **inactiva** no se ofrece para **nuevas reservas ni nuevas reprogramaciones**; conserva asociaciones y citas históricas. Reactivarla solo habilita turnos si existen Médicos activos, horarios y disponibilidad válidos. | CU-09, CU-13–CU-15 |
| **VS-MED-04** | Un Médico **inactivo** no recibe nuevas citas ni reprogramaciones a su favor, pero conserva agenda y registros históricos. Activar/inactivar ficha profesional es distinto de activar/desactivar cuenta. | RN04, RN11; CU-10, CU-14, CU-15 |
| **VS-MED-05** | Si conserva cuenta activa, un Médico profesionalmente inactivo puede **consultar información autorizada y completar atenciones o revisiones de laboratorio previamente asignadas**; si su cuenta se desactiva pierde esas operaciones protegidas. | CU-10, CU-19, CU-23, CU-26 |
| **VS-MED-06** | No eliminar físicamente un Médico con citas históricas. Cambiar su especialidad o consultorio no reescribe retrospectivamente la especialidad o ubicación de citas anteriores. | RN11; CU-10, CU-11 |

---

## 7. Consultorios, horarios y disponibilidad (CU-11–CU-13)

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-HOR-01** | La sede tiene varios consultorios identificados; cada Médico requiere **consultorio fijo asignado para ofrecer turnos**. Un mismo consultorio puede compartirse por distintos Médicos **sin solapar su ocupación**. | CU-11–CU-14 |
| **VS-HOR-02** | Solo el Administrador gestiona asignación fija de consultorios, días/horas de atención y duración. Los horarios deben tener fin posterior al inicio, y la duración debe ser positiva y caber en el intervalo. | RF05, RN05, RN08; CU-11, CU-12 |
| **VS-HOR-03** | Duración inicial: **15 minutos, configurable**. Cada cita conserva su duración al registrarse; cambiar la configuración no altera citas existentes. Una reprogramación aplica la duración vigente y revalida el intervalo entero. | CU-12, CU-14, CU-15 |
| **VS-HOR-04** | Un cambio de horario que deje citas futuras fuera del rango o produzca conflicto de Médico/consultorio **se rechaza completo**; no reprograma, cancela ni modifica reservas automáticamente. | CU-12 |
| **VS-HOR-05** | Un cambio de consultorio fijo requiere revisar horarios y citas futuras. El Administrador solo puede aprobar **cambios expresos de ubicación** para citas futuras Programadas/Confirmadas sin atención iniciada, conservando código, fecha, hora, Paciente, Médico, especialidad, duración y estado. | CU-11 |
| **VS-HOR-06** | Un cambio de ubicación o asignación incompatible **no se guarda parcialmente**. Las fechas y horas solo se alteran por **Recepción mediante CU-15**. La ubicación histórica de citas pasadas/finales se mantiene. | CU-11, CU-15 |
| **VS-HOR-07** | La disponibilidad se calcula con horarios, duración, estado de ocupación de citas y **ambos recursos: Médico y consultorio**. La consulta de turnos es informativa y no reserva un espacio. | RN05–RN08, RN13; CU-12–CU-14 |
| **VS-HOR-08** | Feriados y bloqueos opcionales solo se incorporarán si están implementados explícitamente; no se supondrá su existencia ni se presentarán como obligación del examen. | RF05; CU-12 |

---

## 8. Registro, reprogramación y cancelación de citas (CU-13–CU-16)

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-CIT-01** | El Paciente reserva **solo para sí**; Recepción programa para el Paciente seleccionado. Ambos usan las mismas validaciones definitivas en el servidor. | RF06, RN03, RN12; CU-14 |
| **VS-CIT-02** | Una cita nueva requiere Paciente válido y activo, Médico activo, especialidad activa **asociada al Médico**, horario válido, consultorio fijo y turno completo disponible. El motivo breve es opcional. | RF06–RF07, RN03–RN08; CU-14 |
| **VS-CIT-03** | Al registrar se genera **código único**, fecha de creación, estado **Programada**, modalidad **Presencial**, sede y consultorio. Se conserva la duración aplicada y la ubicación asignada. | RF06, RF10; CU-14 |
| **VS-CIT-04** | No se registran turnos en pasado, fuera del horario ni que superpongan **Médico o consultorio**, incluso cuando los inicios difieran pero los intervalos se crucen. Comprobar nuevamente al guardar. | RN05–RN08, RN13; CU-13, CU-14 |
| **VS-CIT-05** | La disponibilidad entre consulta y confirmación puede cambiar; una reserva o reprogramación concurrente incompatible **se rechaza íntegramente** sin dejar doble asignación. Laravel/PostgreSQL deben brindar protección efectiva, no solo comprobación visual. | RN07, RN13; CU-14, CU-15 |
| **VS-CIT-06** | **Solo Recepción** reprograma citas **Programadas o Confirmadas** antes de la atención; cambia fecha/hora **sobre la misma cita**, conservando código, Paciente, Médico y especialidad. Valida Médico, especialidad, horario y consultorio nuevamente. | RF08; CU-15 |
| **VS-CIT-07** | Reprogramar una Programada conserva `Programada`; reprogramar una Confirmada la devuelve a `Programada`. Aplica duración vigente, actualiza avisos y mantiene la programación anterior íntegra si falla. | CU-15 |
| **VS-CIT-08** | Una cita existente de un Paciente inactivo puede ser reprogramada por Recepción bajo CU-15 si todavía es elegible; un Médico o especialidad inactivo **no** puede ser destino de una reprogramación. | CU-15 |
| **VS-CIT-09** | **Solo Recepción** cancela una cita **Programada o Confirmada** antes de la atención. Cambia a Cancelada, conserva el registro y libera disponibilidad; el motivo de cancelación es **opcional**, aunque preferible. | RF09, RN09; CU-16 |
| **VS-CIT-10** | No se reprograman ni cancelan por procesos ordinarios citas **Atendidas, Canceladas o con Inasistencia**. No inventar un proceso extraordinario de corrección de estados que el proyecto no ha aprobado. | CU-15, CU-16, CU-17–CU-19 |

---

## 9. Estados de citas, llegada y asistencia (CU-17–CU-19)

### 9.1. Únicos cinco estados de cita

`Programada`, `Confirmada`, `Atendida`, `Cancelada`, `Inasistencia`.

| Estado origen | Acción/condición | Estado destino | Actor que la realiza |
| --- | --- | --- | --- |
| Sin cita | Reserva nueva válida | **Programada** | Paciente para sí o Recepción |
| **Programada** | Llegada presencial e identidad verificadas, **mismo día local** y antes del fin del turno | **Confirmada** | Recepcionista |
| **Programada** | Atención efectivamente realizada | **Atendida** | Médico responsable |
| **Confirmada** | Atención efectivamente realizada | **Atendida** | Médico responsable |
| **Programada** | Cancelación previa a la atención | **Cancelada** | Recepcionista |
| **Confirmada** | Cancelación previa a la atención | **Cancelada** | Recepcionista |
| **Programada** | Turno finalizado, ausencia comprobada | **Inasistencia** | Recepcionista |
| **Confirmada** | Reprogramación válida antes de la atención | **Programada** | Recepcionista |
| **Programada** | Reprogramación válida antes de la atención | **Programada** (sin transición) | Recepcionista |

Las transiciones no enumeradas están prohibidas para los flujos ordinarios. Los estados **Atendida, Cancelada e Inasistencia** son finales para esos flujos; `Reprogramada` **no existe** como sexto estado.

### 9.2. Reglas de verificación

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-EST-01** | `Confirmada` indica **llegada efectiva**, no respuesta por teléfono, web ni correo. Recepción confirma solo cita Programada del **mismo día local** y antes de finalizar el intervalo, después de comprobar presencialmente identidad y llegada. Puede llegar antes de la hora exacta el mismo día. | RF10; CU-17 |
| **VS-EST-02** | `Inasistencia` solo procede desde `Programada`, **después de finalizar** el intervalo y con ausencia comprobada por Recepción. No se aplica desde Confirmada; no inventar tolerancia temporal no aprobada. | RF10; CU-18 |
| **VS-EST-03** | `Atendida` únicamente la registra el **Médico responsable**, después de realizar la atención, desde Programada **o** Confirmada. No exige confirmación previa de Recepción. | RF10, RN12; CU-19 |
| **VS-EST-04** | Registrar o corregir una consulta médica **no marca automáticamente** la cita como Atendida; son operaciones independientes. | CU-19, CU-23 |
| **VS-EST-05** | El servidor comprueba estado origen, actor, vínculo con la cita y precondición en cada transición; se registran responsable y fecha cuando corresponde. | RN12; CU-17–CU-19 |
| **VS-EST-06** | Para reglas dependientes de día/hora de atención se utiliza la zona horaria local **`America/La_Paz`**; no tratar zona horaria del navegador como autoridad. | CU-17, CU-18, CU-28 |

---

## 10. Agenda e historial (CU-20–CU-21)

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-HIS-01** | Administrador, Recepción y Médico consultan agendas dentro de sus atribuciones; **el Médico solo la suya**. Filtros: Médico, fecha y estado. Motivo breve solo para usuarios autorizados. | RF11, RN12; CU-20 |
| **VS-HIS-02** | Recepción puede consultar el historial administrativo del paciente que gestiona; el Paciente solo el de su **ficha vinculada**. Incluye fecha/hora, Médico, especialidad, estado, fecha de registro y ubicación. | RF12, RN12; CU-21 |
| **VS-HIS-03** | El historial conserva citas pasadas y canceladas; al cambiar consultorio del Médico no se modifican las ubicaciones históricas. Una consulta administrativa no altera estados ni sustituye la historia clínica. | RN10–RN11; CU-20, CU-21 |

---

## 11. Historia clínica y consultas médicas (CU-22–CU-23)

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-CLI-01** | El Médico consulta solo historias de pacientes relacionados con su atención autorizada; el Paciente accede a su propia información permitida. **CU-22 es exclusivamente de consulta**, sin edición. Recepción no adquiere acceso clínico por gestionar citas ni el Administrador por gestionar el acceso de cuentas. | RN12; CU-22 |
| **VS-CLI-02** | Cada cita admite **como máximo una consulta médica**, vinculada a su Paciente y Médico responsable. Se rechaza una segunda consulta, incluso ante peticiones simultáneas. | CU-23 |
| **VS-CLI-03** | El Médico registra una consulta de **atención realmente realizada** durante o después de la cita (Programada, Confirmada o Atendida, según situación); no puede crearla para una cita Cancelada o con Inasistencia. | CU-23 |
| **VS-CLI-04** | Una consulta puede ser de **control** o **enfermedad**, y tener **varias solicitudes de laboratorio**. Registrar/actualizar la consulta no cambia el estado administrativo de la cita. | CU-23, CU-24 |
| **VS-CLI-05** | Modificaciones clínicas relevantes de consultas y antecedentes conservan autor, fecha/hora, motivo cuando corresponda, **versión anterior y nueva** en almacenamiento clínico protegido. La bitácora general solo conserva el evento, referencias y metadatos seguros, **no** antecedentes, diagnósticos u otro contenido médico sensible. | CU-23 / PN18, CU-30 |
| **VS-CLI-06** | El Médico profesionalmente inactivo con cuenta activa puede completar/corregir atenciones propias ya asignadas. La **desactivación de la cuenta** sí le impide cualquier operación protegida. | CU-10, CU-23 |
| **VS-CLI-07** | Historia clínica (antecedentes, consultas, resultados autorizados) e historial de citas (fechas y estados) son consultas **distintas**, aunque relacionadas con la ficha del Paciente. | CU-21–CU-23 |
| **VS-CLI-08** | Únicamente el **Médico responsable de una atención real y autorizada** registra, completa o corrige antecedentes: tipo de sangre, alergias, enfermedades crónicas, medicamentos y antecedentes personales o familiares. En CU-23 consulta primero los existentes; el servidor valida cuenta, rol, relación con cita/Paciente y modificaciones. No crear consultas ficticias ni una segunda consulta por cita, ni cambiar automáticamente su estado al guardar antecedentes. Administrador, Recepción y Paciente no los editan; aplicar el versionado de VS-CLI-05. | CU-08, CU-22, CU-23 / PN18; VS-CLI-02–VS-CLI-05 |

---

## 12. Laboratorio digital (CU-24–CU-27)

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-LAB-01** | El sistema no realiza análisis médicos ni emite resultados de laboratorio: únicamente gestiona **solicitudes y documentos emitidos externamente**. | CU-24–CU-27 |
| **VS-LAB-02** | Solo el Médico responsable registra solicitudes de estudio **a partir de una consulta existente**. Una consulta puede originar varias solicitudes; cada una conserva Paciente, Médico, estudio y fecha. | CU-24 |
| **VS-LAB-03** | El Administrador **carga/asocia** PDF o imagen legible emitida externamente y **corrige** asociaciones o sustituye versiones devueltas; no diagnostica, modifica contenido clínico ni autoriza publicación. | CU-25 |
| **VS-LAB-04** | Los estados internos de solicitud de laboratorio son **exactamente** `Pendiente`, `En revisión`, `Disponible`; son distintos de los cinco estados de `Cita`. | CU-24–CU-27 |
| **VS-LAB-05** | Crear la solicitud la deja `Pendiente`; al cargar un resultado pasa a `En revisión`. Una devolución y corrección permanecen `En revisión`, sin publicación automática. | CU-24, CU-25, CU-26 |
| **VS-LAB-06** | Solo el **Médico solicitante** puede revisar/autorizar o devolver resultado. Si el documento es correcto, **autoriza publicación directamente**; si es incorrecto, devuelve con motivo y exige corrección documental y nueva revisión. | CU-26 |
| **VS-LAB-07** | `Disponible` solo después de autorizar expresamente **la versión vigente revisada**. Si se reemplazó el archivo durante la revisión, exigir revalidación; no publicar documento distinto del autorizado. | CU-26 |
| **VS-LAB-08** | El Paciente ve estado **Pendiente** mientras internamente la solicitud esté Pendiente o En revisión. No puede consultar/descargar documentos no publicados, erróneos ni versiones anteriores. | CU-27 |
| **VS-LAB-09** | Cada descarga/lectura de archivo comprueba sesión, rol, relación con la solicitud y publicación de la versión vigente; **no usar rutas públicas**. | CU-25–CU-27 |
| **VS-LAB-10** | Cargas, devoluciones, sustituciones y publicaciones conservan autor, fechas, motivo y versiones/documentos anteriores de forma protegida; ningún fallo confirma cambios parciales. | CU-25, CU-26 |

---

## 13. Recordatorios internos (CU-28)

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-NOT-01** | Los avisos de citas son **automáticos, persistidos en PostgreSQL y exclusivamente internos**, generados sin intervención manual de Paciente o Recepción y sin depender de una sesión ni del navegador. No usar Brevo, correo, SMS, WhatsApp o notificaciones push para citas. | CU-28 |
| **VS-NOT-02** | Generar automáticamente un recordatorio interno **24 horas antes de la fecha y hora programadas** de la cita. Si una reserva se registra exitosamente con menos de 24 horas de anticipación, generar el aviso después de guardarla correctamente en la base de datos o en la siguiente ejecución del programador de tareas, siempre que el recordatorio siga siendo válido conforme a las condiciones establecidas y el intervalo de atención no haya finalizado. Una reserva cuyo registro haya fallado no genera aviso. **La generación no depende del estado Confirmada ni modifica el estado de la cita**: una Programada puede generar su recordatorio y una Confirmada conservar los vigentes. La reserva nueva permanece inicialmente Programada; Confirmada significa exclusivamente llegada presencial verificada por Recepción. La frecuencia específica del programador se definirá durante el diseño técnico; cada ejecución revalida vigencia, estados y preferencias. | CU-28 / PN23; VS-NOT-03–VS-NOT-06 |
| **VS-NOT-03** | Mantener **un aviso vigente por Paciente, cita y programación**, respetando preferencias internas y evitando duplicados por reintentos del programador. | CU-28 |
| **VS-NOT-04** | La ausencia de cuenta web no impide generar ni persistir el aviso asociado al identificador del Paciente y la cita; al activar la cuenta, solo se muestran avisos propios autorizados de **citas futuras vigentes**. Sin cuenta no se expone el aviso a terceros. | CU-28 |
| **VS-NOT-05** | Reprogramar recalcula el momento del aviso según la nueva fecha y hora y actualiza o invalida el anterior; cambiar de consultorio actualiza ubicación. Cancelada, Atendida, Inasistencia o intervalo finalizado **impiden nuevos recordatorios de próxima atención y retiran los anteriores de próximos avisos**, conservando el historial de la cita y los registros correspondientes según las reglas existentes. | CU-11, CU-15, CU-16, CU-28 |
| **VS-NOT-06** | Si están deshabilitadas las preferencias internas, no crear nuevos avisos; al reactivarlas aplicar las reglas a citas aún pertinentes, sin avisos duplicados. | CU-08, CU-28 |

---

## 14. Reportes administrativos (CU-29)

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-REP-01** | El Administrador puede obtener **todos** los reportes mínimos RF13: citas programadas para fecha, por Médico, especialidad y estado; pacientes registrados; conteos de Atendidas, Canceladas e Inasistencias por período; agenda diaria de cada Médico. | RF13; CU-29 |
| **VS-REP-02** | Los filtros por fecha/rango y agrupaciones se validan en servidor; mostrar totales cuando corresponde y **cero/lista vacía** si no hay datos, sin confundirlo con error. | CU-29 |
| **VS-REP-03** | Reportes generales administrativos son del Administrador; las consultas operativas de Recepción y Médico se realizan bajo los límites propios de CU-20/CU-21. Exportación CSV/PDF es **opcional**. | RN12, RF13; CU-20, CU-21, CU-29 |

---

## 15. Bitácora del Sistema (CU-30)

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-AUD-01** | El sistema registra **automáticamente** eventos relevantes de acceso, cuentas, pacientes, médicos, horarios, citas, consultas clínicas **como metadatos** y laboratorio. No se agrega un CU manual de “registrar evento”. | CU-30; 1.4.13 |
| **VS-AUD-02** | **Solo Administrador** puede consultar el historial general en **solo lectura**, con filtros por fecha, responsable, cuenta objetivo, módulo, operación, tipo, resultado, IP y recurso, y paginación/orden desde más reciente. | RN12; CU-30 |
| **VS-AUD-03** | La aplicación no ofrece edición ni eliminación ordinaria de eventos. La integridad debe protegerse también en persistencia; la lectura no otorga permisos clínicos adicionales. | CU-30 |
| **VS-AUD-04** | Nunca registrar contraseñas, hashes, códigos, tokens, sesiones secretas, solicitudes completas, documentos, diagnósticos ni contenidos clínicos innecesarios. Las versiones clínicas permanecen en sus propias entidades protegidas. | CU-23, CU-25–CU-27, CU-30 |
| **VS-AUD-05** | El evento registra autor auténtico **o origen anónimo/sistema** cuando corresponda; no inventar identidades. Los resultados exitosos reflejan operaciones realmente confirmadas; los intentos fallidos pueden persistir sin confirmar la operación rechazada. | CU-01, CU-04, CU-30 |
| **VS-AUD-06** | Los campos, índices y restricciones físicos detallados de Bitácora se comprobarán en el **DER vigente** y migraciones reales. No considerar implementada una propuesta de ampliación del DER solo por estar documentada. | CU-30; DER |

---

## 16. Persistencia, integridad, seguridad y concurrencia transversal

| ID propio | Regla comprobable | Relación |
| --- | --- | --- |
| **VS-INT-01** | Las validaciones de React son auxiliares; Laravel realiza validación definitiva y autorización en **cada petición**. No confiar en identificadores de Paciente, Médico, rol o recurso enviados por el navegador. | RN12, RN13; transversal |
| **VS-INT-02** | PostgreSQL y las migraciones deberán proteger al menos la unicidad de identificadores, CI cuando corresponda, correo de acceso, **una cuenta por Paciente**, y **una consulta por cita**, con claves/restricciones adecuadas. | RN01–RN03, RN14; CU-04, CU-07, CU-23 |
| **VS-INT-03** | Una reserva/reprogramación no puede confirmar superposiciones de **Médico ni consultorio** en peticiones simultáneas. Se requiere control transaccional o restricción de intervalos adecuada, no solo consultar si está libre y luego insertar. | RN07, RN13; CU-14, CU-15 |
| **VS-INT-04** | Operaciones compuestas (vinculación de cuentas, reprogramación, cambio de consultorio, versiones clínicas/laboratorio) se confirman **íntegras o se revierten**; nunca informar éxito de una operación parcial. | RN14; CU-04, CU-11, CU-15, CU-23, CU-26 |
| **VS-INT-05** | No eliminar físicamente fichas o registros con historial que las reglas obligan a conservar; no permitir cascadas destructivas sobre citas, consultas, documentos o bitácora. | RN10, RN11; transversal |
| **VS-INT-06** | Los archivos clínicos permanecen en almacenamiento protegido; listados y respuestas JSON solo entregan datos autorizados. Las rutas públicas, los logs técnicos y las respuestas de error nunca contienen secretos o datos clínicos no autorizados. | RN12; CU-22–CU-27 |
| **VS-INT-07** | Errores previsibles no deben crear cambios parciales. Distinguir, según el contrato de API, falta de sesión (`401`), prohibición (`403`), validación (`422`), conflicto (`409`), límites (`429`) y errores del servidor (`500`); cuando corresponda, CSRF (`419`). **Estos códigos son decisiones técnicas, no RN oficiales.** | RN12; AGENTS backend/frontend |
| **VS-INT-08** | Los datos persistentes viven en PostgreSQL mediante Laravel/Eloquent y consultas parametrizadas; no exponer claves, credenciales ni cadenas SQL en mensajes. Las operaciones deben ser verificables mediante pruebas funcionales y de concurrencia. | RN14, requisitos técnicos |

---

## 17. Matriz breve de trazabilidad por módulos

| Módulo / reglas propias | Casos de uso | RF/RN oficiales especialmente relacionados |
| --- | --- | --- |
| General y permisos `VS-GEN` | CU-01–CU-30 | RF01, RN12 |
| Usuarios y autenticación `VS-AUT` | CU-01–CU-07, CU-08 | RF01–RF02, RN01, RN12, RN14 |
| Pacientes y perfil `VS-PAC` | CU-04, CU-07–CU-08, CU-21–CU-22 | RF02, RF12; RN01, RN11, RN12 |
| Médicos y especialidades `VS-MED` | CU-09–CU-10, CU-13–CU-15 | RF03–RF04; RN02, RN04, RN11 |
| Consultorios/horarios `VS-HOR` | CU-11–CU-15 | RF05–RF08; RN05–RN08, RN13 |
| Reservas/reprogramación `VS-CIT` | CU-13–CU-16 | RF06–RF09; RN03–RN09, RN13 |
| Estados/asistencia `VS-EST` | CU-17–CU-19 | RF10; RN10, RN12 |
| Agenda/historial `VS-HIS` | CU-20–CU-21 | RF11–RF12; RN10–RN12 |
| Consultas clínicas `VS-CLI` | CU-22–CU-24 | Perfil del Paciente y ampliación clínica; RN12, RN14 |
| Laboratorio `VS-LAB` | CU-24–CU-27 | Ampliación documental; RN12, RN14 |
| Recordatorios `VS-NOT` | CU-28 | Perfil del Paciente y ampliación; RN12, RN14 |
| Reportes `VS-REP` | CU-29 | RF13; RN12, RN14 |
| Bitácora `VS-AUD` | CU-30 y registro transversal | Ampliación compatible con RN12, RN14 |
| Integridad `VS-INT` | Transversal | RN01–RN14 según la operación |

---

## 18. Pruebas y criterios de revisión al desarrollar

1. **Comprobar los diez escenarios mínimos del examen** descritos en `requerimientos.md` (sección 8): alta Paciente, CI duplicado, reserva disponible, superposición, fuera de horario, cancelación e historial, historial por paciente, agenda por Médico y fecha, acceso sin sesión y validación de datos inválidos.
2. **Usuarios:** probar bloqueo de 3 intentos/10 minutos, cuentas pendientes/inactivas, cookies y sesiones revocadas, roles fijos, invitación al personal, cambio/recuperación y mensajes sin enumeración.
3. **Activación:** probar Paciente nuevo y existente, dos códigos independientes, agotamientos, vencimientos, reenvíos, cuenta ya vinculada, operaciones simultáneas, interrupciones y ausencia de duplicaciones.
4. **Citas:** probar solapamiento de Médico y consultorio, duración variable, cancelación liberadora, estados y autorizaciones, cambios de horario, reprogramación de Paciente inactivo y Médico/especialidad inactivos.
5. **Clínica y laboratorio:** probar una consulta única por cita, versiones de corrección, rechazo de datos clínicos ajenos, publicación directa del resultado correcto, devolución/revisión del incorrecto, descarga protegida y cambio concurrente de versión.
6. **Recordatorios/reportes/bitácora:** probar cálculo de 24 horas, citas con menor anticipación, avisos con Paciente sin cuenta, filtros y totales RF13, y auditoría consultable únicamente por Administrador sin exponer secretos.

**Estado documental inicial:** las reglas están definidas, **no** se consideran validadas por pruebas hasta contar con código y resultados reproducibles.

---

## 19. Mantenimiento y uso por OpenCode

**Antes de implementar:** leer `AGENTS.md`, el AGENTS del área, `contexto-maestro.md`, este archivo, `requerimientos.md`, `casos-de-us.md` y las fichas involucradas. Consultar el DER real al trabajar persistencia. Identificar regla propia, RN oficial, roles y pruebas correspondientes.

**Al implementar:** mantener en Laravel la decisión funcional; reforzar persistencia con PostgreSQL cuando aplique; expresar violaciones con errores definidos sin exponer datos internos. No sustituir reglas del examen por inferencias del DER o por lo más fácil de programar.

**Si aparecen discrepancias:** documentar la fuente y preguntar antes de redefinir estados, permisos, caducidades o plazos. No introducir un quinto rol, nuevos estados, nuevos RF/RN ni ampliaciones no aprobadas. Mantener las decisiones del proyecto separadas de los requisitos oficiales.

**Al finalizar un caso de uso o grupo:** comprobar pruebas, actualizar `docs/ai/analisis-clases.md` conforme a lo efectivamente implementado; al finalizar un módulo, integrar el diseño realizado a `diagrama-clases.md` general; si cambia el esquema, sincronizar `diagrama-entidad-relacion.md`. Si cambian decisiones aprobadas, actualizar este archivo junto a `requerimientos.md`, `casos-de-us.md` y `contexto-maestro.md`.

**Estados de seguimiento válidos:** `Especificado`, `En desarrollo`, `Implementado`, `Verificado`. No presentar un CU, clase o regla como implementado por aparecer en un documento. El apartado académico **4.5** se desarrolla por separado; este archivo no implica completarlo.

## 20. Referencias de consulta

- **Examen oficial** «Examen 2 — Ingeniería de Requisitos — Sistema web para la gestión de citas médicas»: sección de reglas `RN01`–`RN14`, requisitos `RF01`–`RF13`, exigencias técnicas y pruebas mínimas.
- **Documento académico vigente:** `Clinica_Vida_Salud_Captura_Actualizada(3).pdf`, 104 páginas: Alcance 1.4, procesos PN01–PN24 y fichas CU-01–CU-30.
- **Repositorio:** `docs/ai/contexto-maestro.md`, `docs/ai/requerimientos.md`, `docs/ai/casos-de-us.md`, `docs/ai/diagrama-entidad-relacion.md` (cuando exista), AGENTS raíz/frontend/backend.

> **Recordatorio:** una regla aprobada es una obligación funcional o restricción de negocio **a implementar**, no evidencia de que el software ya la cumpla.
