# Diagrama entidad-relación — Clínica Vida Salud

> **Ruta de destino:** `docs/ai/diagrama-entidad-relacion.md`  
> **Proyecto:** Sistema Web para la Gestión de Citas Médicas para la Clínica Vida Salud  
> **Fuente estructural primaria:** `DER_Clinica_Vida_Salud_Reorganizado_Corregido(2).drawio` (archivo recibido, **dos pestañas**, **28 entidades**, **239 atributos** y **43 relaciones gráficas**)  
> **Referencia académica:** `Clinica_Vida_Salud_Coherencia_Actualizada(2).pdf` (117 páginas) y enunciado oficial del Segundo Parcial de Ingeniería de Requisitos  
> **Alcance:** especificación del modelo lógico e instrucciones para futuros modelos Eloquent, migraciones, seeders y validaciones. **No acredita implementación.**

## 1. Propósito, precedencia y reglas de uso

Este documento permite que OpenCode traduzca el **DER corregido** a la base de datos PostgreSQL y a la capa de datos Laravel **sin inventar requisitos**. Contiene un inventario fiel de los atributos que figuran en Draw.io, sus nulabilidades, claves y catálogos, además de las reglas recogidas en la segunda pestaña del mismo archivo. Las guías de implementación están señaladas como **propuestas técnicas pendientes**, no como hechos implementados.

**Orden de autoridad:** (1) enunciado oficial del examen para RF01–RF13 y RN01–RN14; (2) decisiones funcionales aprobadas más recientes; (3) documento académico vigente; (4) DER corregido para el esquema lógico; (5) documentación técnica interna; (6) implementación existente, que es evidencia del estado del código, pero no autoriza cambiar los requisitos. Las decisiones aprobadas recogidas en los documentos funcionales `docs/ai/` conservan la prioridad de su fuente; no quedan subordinadas al DER por su ubicación. Ante una contradicción, **detener el cambio afectado, documentar el conflicto y pedir una decisión**. No inventar tablas, relaciones, reglas ni permisos para solucionarlo unilateralmente.

Esta revisión consolida las aclaraciones del modelo lógico sin aprobar decisiones físicas pendientes. Los archivos `.drawio` y PDF citados no están disponibles en el repositorio para este contraste: las cifras y referencias de origen se conservan como antecedente documental, sin afirmar una nueva comprobación contra esos originales ni la precedencia entre versiones académicas no disponibles.

El examen requiere PHP y PostgreSQL, con conexión por PDO/`pdo_pgsql` o equivalente aprobado. La solución del proyecto utilizará Laravel (Eloquent y migraciones), React y notificaciones internas. **No se permite añadir nuevos roles, estados de citas, RF/RN oficiales ni CU.** Los módulos de consulta clínica, laboratorio, seguridad ampliada y recordatorios son decisiones aprobadas del proyecto, no nuevos requisitos literales del enunciado.

**Alcance excluido:** pagos, farmacia, inventario, telemedicina, múltiples sedes y recordatorios por correo, SMS, WhatsApp o push externo. La clínica atiende presencialmente en una sede con varios consultorios.

### 1.1. Convenciones

- **PK** = clave primaria; **FK** = clave foránea; **UQ** = unicidad; **IDX** = índice. Una marca compuesta significa ambas propiedades; donde un par constituye la FK o UQ, se indica explícitamente.
- **Obligatorio** = metadato `nullable=false` en la fila original; **Sí** en la columna «NULL» significa `nullable=true`. **El hecho de admitir `NULL` no elimina condiciones funcionales de obligatoriedad por estado o rol.**
- Los nombres de entidades y atributos se copian del DER, incluidos `inicio_at`, `fin_at`, `password_hash`, `id_version_actual` y `id_revision_publicada`. No renombrarlos silenciosamente para ajustarlos a convenciones Laravel.
- `String`, `Text`, `BigInt`, `TimestampTZ`, `Date`, `Time`, `Integer`, `Boolean`, `JSONB` representan tipos lógicos del DER. Sus conversiones concretas a columnas PostgreSQL y longitudes no indicadas deben acordarse en el **diseño físico**. Los tipos nativos explícitos (`VARCHAR(120)`, `INET`, `UUID`, `SMALLINT`, `TIMESTAMPTZ`, etc.) sí son parte del modelo indicado.
- Campos temporales: almacenar instantes con referencia inequívoca y presentarlos en **`America/La_Paz`**. En especial, no reemplazar `TIMESTAMPTZ` por fechas locales ambiguas.
- Los estados y listas de valores del DER son **listas cerradas** cuando así se expresan. No crear categorías adicionales por conveniencia de implementación.
- Ninguna FK, CHECK o índice representa automáticamente las autorizaciones de rol, consistencia entre múltiples tablas, vigencias de códigos o concurrencia. Se requerirán validaciones de servidor, transacciones y restricciones de PostgreSQL específicas.

## 2. Inventario completo (28 entidades)

| Área | Entidades del DER |
|---|---|
| Acceso y seguridad | `Rol`, `Usuario`, `Sesion`, `TokenAcceso`, `ControlIntentos` |
| Pacientes y activación | `Paciente`, `ContactoEmergencia`, `PreferenciaPaciente`, `CodigoActivacionPaciente`, `RegistroPacientePendiente`, `VerificacionCorreo` |
| Organización médica | `Consultorio`, `Medico`, `Especialidad`, `MedicoEspecialidad`, `HorarioAtencion`, `ConfiguracionClinica` |
| Citas y seguimiento | `Cita`, `CambioCita`, `Recordatorio` |
| Registro clínico | `ConsultaMedica`, `VersionConsulta`, `VersionAntecedente` |
| Laboratorio digital | `SolicitudLaboratorio`, `VersionResultado`, `DocumentoLaboratorio`, `RevisionResultado` |
| Auditoría transversal | `Bitacora` |

**Importante:** no crear una entidad `HistoriaClinica` duplicada ni una tabla de reportes por defecto. La historia clínica se obtiene mediante Paciente, antecedentes versionados, consultas y laboratorio; los reportes RF13 se calculan a partir de las tablas pertinentes.

## 3. Diccionario de datos exacto del DER

Los siguientes nombres, tipos lógicos, nulabilidades, referencias, listas cerradas y restricciones de unicidad se extraen de los **metadatos editables de las tablas Draw.io**, no se infieren únicamente del dibujo. El nombre físico de cada tabla, **excepto `sessions` y `bitacora`**, debe definirse coherentemente en las migraciones y reflejarse en el mapeo de modelos. Si alguna fila no especifica una longitud, no se presenta una longitud inventada como requisito.

### 3.1. `Rol`

Cuatro filas fijas; sin CRUD de tipos de roles ni permisos configurables.

**Restricciones únicas declaradas:** `(nombre)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_rol` | `BigInt` | No | — | — |
| UQ | `nombre` | `String` | No | — | Valores: `Administrador`, `Recepcionista`, `Médico`, `Paciente` |

### 3.2. `Usuario`

Contraseña NULL solo durante invitación pendiente; obligatoria para una cuenta activa.

**Equivalencia de estado:** el valor persistido `Pendiente` de `Usuario.estado_cuenta` corresponde a la condición funcional **Pendiente de verificación**. Es la misma condición, no un estado adicional ni una autorización de acceso protegido; se conserva la lista cerrada del diccionario.

**Restricciones únicas declaradas:** `(correo_acceso)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_usuario` | `BigInt` | No | — | — |
| FK | `id_rol` | `BigInt` | No | `Rol` | — |
| UQ | `correo_acceso` | `String` | No | — | Único tras normalización uniforme, también ante escrituras simultáneas; estrategia física a definir. |
| — | `password_hash` | `String` | Sí | — | — |
| — | `estado_cuenta` | `String` | No | — | Valores: `Pendiente`, `Activa`, `Desactivada` |
| — | `correo_verificado_en` | `TimestampTZ` | Sí | — | — |
| — | `cambio_clave_obligatorio` | `Boolean` | No | — | — |
| — | `intentos_fallidos` | `Integer` | No | — | — |
| — | `bloqueado_hasta` | `TimestampTZ` | Sí | — | — |
| — | `creado_en` | `TimestampTZ` | No | — | — |
| — | `actualizado_en` | `TimestampTZ` | No | — | — |
| — | `nombres_personal` | `VARCHAR(120)` | Sí | — | Obligatorio cuando: Rol Administrador o Recepcionista sin entidad personal propia; Obligatorio y no vacío para personal administrativo; no duplica los nombres de Medico o Paciente. |
| — | `apellidos_personal` | `VARCHAR(120)` | Sí | — | Obligatorio cuando: Rol Administrador o Recepcionista sin entidad personal propia; Obligatorio y no vacío para personal administrativo; no duplica los nombres de Medico o Paciente. |

### 3.3. `Sesion`

Único almacén nativo de sesiones Laravel; tabla física sessions. Admite sesiones anónimas. Revocación por eliminación/invalidación y autorización del servidor, sin un segundo mecanismo.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id` | `VARCHAR(255)` | No | — | — |
| FK | `user_id` | `BIGINT` | Sí | `Usuario` | — |
| — | `ip_address` | `VARCHAR(45)` | Sí | — | — |
| — | `user_agent` | `TEXT` | Sí | — | — |
| — | `payload` | `TEXT` | No | — | — |
| IDX | `last_activity` | `INTEGER` | No | — | Segundos Unix de última actividad; índice nativo de Laravel. |

**Nombre físico obligado por el DER:** `sessions`. No crear una segunda tabla de sesiones ni cambiar estos nombres nativos para adaptarlos al idioma del resto de las tablas.

### 3.4. `TokenAcceso`

**Restricciones únicas declaradas:** `(token_hash)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_token` | `BigInt` | No | — | — |
| FK | `id_usuario` | `BigInt` | No | `Usuario` | — |
| — | `proposito` | `String` | No | — | Valores: `InvitacionPersonal`, `RecuperacionClave` |
| UQ | `token_hash` | `String` | No | — | — |
| — | `emitido_en` | `TimestampTZ` | No | — | — |
| — | `expira_en` | `TimestampTZ` | No | — | — |
| — | `consumido_en` | `TimestampTZ` | Sí | — | — |
| — | `invalidado_en` | `TimestampTZ` | Sí | — | — |

### 3.5. `ControlIntentos`

Control técnico por proceso/correo/origen; sin códigos, contraseñas ni datos clínicos.

**Restricciones únicas declaradas:** `(clave_ambito_hash)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_control` | `BigInt` | No | — | — |
| UQ | `clave_ambito_hash` | `String` | No | — | — |
| — | `inicio_ventana` | `TimestampTZ` | No | — | — |
| — | `cantidad_intentos` | `Integer` | No | — | — |
| — | `bloqueado_hasta` | `TimestampTZ` | Sí | — | — |
| — | `actualizado_en` | `TimestampTZ` | No | — | — |

### 3.6. `Paciente`

Sin credenciales. CI único cuando exista; cuenta opcional y como máximo una.

**Restricciones únicas declaradas:** `(id_usuario)`, `(codigo_paciente)`, `(documento_identidad)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_paciente` | `BigInt` | No | — | — |
| FK/UQ | `id_usuario` | `BigInt` | Sí | `Usuario` | UQ condicionada: NOT NULL |
| UQ | `codigo_paciente` | `String` | No | — | — |
| UQ | `documento_identidad` | `String` | Sí | — | UQ condicionada: NOT NULL; CI único cuando se proporciona; varios NULL autorizados. No se sustituyen por CI ficticios. |
| — | `nombres` | `String` | No | — | — |
| — | `apellidos` | `String` | No | — | — |
| — | `fecha_nacimiento` | `Date` | No | — | — |
| — | `sexo` | `String` | Sí | — | — |
| — | `telefono` | `String` | No | — | — |
| — | `correo_contacto` | `String` | Sí | — | — |
| — | `direccion` | `Text` | Sí | — | — |
| — | `activo` | `Boolean` | No | — | — |
| — | `creado_en` | `TimestampTZ` | No | — | — |
| — | `actualizado_en` | `TimestampTZ` | No | — | — |

### 3.7. `ContactoEmergencia`

**Restricciones únicas declaradas:** `(id_paciente)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_contacto` | `BigInt` | No | — | — |
| FK/UQ | `id_paciente` | `BigInt` | No | `Paciente` | — |
| — | `nombre` | `String` | No | — | — |
| — | `parentesco` | `String` | No | — | — |
| — | `telefono` | `String` | No | — | — |

### 3.8. `PreferenciaPaciente`

Preferencias internas ya definidas; no alteran roles ni autorizaciones clínicas.

**Restricciones únicas declaradas:** `(id_paciente)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_preferencia` | `BigInt` | No | — | — |
| FK/UQ | `id_paciente` | `BigInt` | No | `Paciente` | — |
| — | `recordatorios_habilitados` | `Boolean` | No | — | — |
| — | `preferencias_privacidad` | `JSONB` | No | — | — |
| — | `actualizado_en` | `TimestampTZ` | No | — | — |

### 3.9. `CodigoActivacionPaciente`

**Restricciones únicas declaradas:** `(codigo_hash)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_codigo_activacion` | `BigInt` | No | — | — |
| FK | `id_paciente` | `BigInt` | No | `Paciente` | — |
| FK | `id_recepcionista` | `BigInt` | No | `Usuario` | — |
| UQ | `codigo_hash` | `String` | No | — | — |
| — | `emitido_en` | `TimestampTZ` | No | — | — |
| — | `expira_en` | `TimestampTZ` | No | — | — |
| — | `intentos_fallidos` | `Integer` | No | — | — |
| — | `consumido_en` | `TimestampTZ` | Sí | — | — |
| — | `invalidado_en` | `TimestampTZ` | Sí | — | — |

### 3.10. `RegistroPacientePendiente`

Reserva temporal de activación; no constituye una segunda cuenta ni una segunda ficha.

**Exclusividad del proceso:** los reintentos reanudan el mismo proceso válido mediante un contexto protegido. No pueden coexistir procesos independientes vigentes para el mismo Paciente o código de Recepción, ni para el mismo CI cuando exista o correo propuesto normalizados. La reserva, los vencimientos y la confirmación atómica se precisan en §5.3; su mecanismo físico sigue pendiente.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_registro_pendiente` | `BigInt` | No | — | — |
| FK | `id_paciente` | `BigInt` | Sí | `Paciente` | — |
| FK | `id_codigo_activacion` | `BigInt` | Sí | `CodigoActivacionPaciente` | — |
| — | `tipo_registro` | `String` | No | — | Valores: `Nuevo`, `Vinculacion` |
| — | `documento_identidad` | `String` | Sí | — | — |
| — | `correo_propuesto` | `String` | No | — | — |
| — | `password_hash_temporal` | `String` | Sí | — | Obligatorio cuando: Proceso pendiente y vigente |
| — | `datos_registro` | `JSONB` | Sí | — | Obligatorio cuando: Registro Nuevo pendiente |
| — | `iniciado_en` | `TimestampTZ` | No | — | — |
| — | `expira_en` | `TimestampTZ` | No | — | — |
| — | `completado_en` | `TimestampTZ` | Sí | — | — |
| — | `invalidado_en` | `TimestampTZ` | Sí | — | — |

### 3.11. `VerificacionCorreo`

Exactamente uno, compatible con `proposito`: `ActivacionPaciente` exige `id_registro_pendiente` informado e `id_usuario` NULL; `CambioCorreo` exige `id_usuario` informado e `id_registro_pendiente` NULL. Rechazar ambos presentes, ambos ausentes o una combinación incompatible con el propósito. Esta exclusividad XOR no cambia la nulabilidad individual de las FK.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_verificacion` | `BigInt` | No | — | — |
| FK | `id_registro_pendiente` | `BigInt` | Sí | `RegistroPacientePendiente` | — |
| FK | `id_usuario` | `BigInt` | Sí | `Usuario` | — |
| — | `proposito` | `String` | No | — | Valores: `ActivacionPaciente`, `CambioCorreo` |
| — | `correo_destino` | `String` | No | — | — |
| — | `codigo_hash` | `String` | No | — | — |
| — | `enviado_en` | `TimestampTZ` | No | — | — |
| — | `expira_en` | `TimestampTZ` | No | — | — |
| — | `intentos_fallidos` | `Integer` | No | — | — |
| — | `consumido_en` | `TimestampTZ` | Sí | — | — |
| — | `invalidado_en` | `TimestampTZ` | Sí | — | — |

### 3.12. `Consultorio`

**Restricciones únicas declaradas:** `(codigo_consultorio)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_consultorio` | `BigInt` | No | — | — |
| UQ | `codigo_consultorio` | `String` | No | — | — |
| — | `nombre` | `String` | No | — | — |
| — | `activo` | `Boolean` | No | — | — |

### 3.13. `Medico`

Alta sin consultorio permitida. Un consultorio fijo válido es obligatorio para horarios reservables y nuevas citas; compartible solo con horarios compatibles.

**Restricciones únicas declaradas:** `(id_usuario)`, `(codigo_medico)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_medico` | `BigInt` | No | — | — |
| FK/UQ | `id_usuario` | `BigInt` | Sí | `Usuario` | UQ condicionada: NOT NULL |
| FK | `id_consultorio` | `BigInt` | Sí | `Consultorio` | Obligatorio cuando: Antes de configurar horarios reservables o registrar nuevas citas; Opcional al registrar el Médico por CU-10; asignación fija mediante CU-11. |
| UQ | `codigo_medico` | `String` | No | — | — |
| — | `registro_profesional` | `String` | No | — | — |
| — | `nombres` | `String` | No | — | — |
| — | `apellidos` | `String` | No | — | — |
| — | `telefono` | `String` | No | — | — |
| — | `correo_contacto` | `String` | No | — | — |
| — | `activo` | `Boolean` | No | — | — |

### 3.14. `Especialidad`

**Restricciones únicas declaradas:** `(nombre)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_especialidad` | `BigInt` | No | — | — |
| UQ | `nombre` | `String` | No | — | — |
| — | `descripcion` | `Text` | Sí | — | — |
| — | `activa` | `Boolean` | No | — | — |

### 3.15. `MedicoEspecialidad`

Clave primaria compuesta; relación muchos a muchos.

**Restricciones únicas declaradas:** `(id_medico, id_especialidad)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK/FK | `id_medico` | `BigInt` | No | `Medico` | — |
| PK/FK | `id_especialidad` | `BigInt` | No | `Especialidad` | — |

**Claves:** PK compuesta (`id_medico`, `id_especialidad`). Eloquent no ofrece una PK compuesta nativa equivalente a una PK simple; conservar la PK compuesta en PostgreSQL y tratar la relación como pivote con operaciones compatibles. No introducir un ID sustituto sin aprobación.

**Decisión pendiente — retiro de habilitación futura:** una asociación referida por citas debe conservarse para mantener la FK compuesta y el historial. El modelo actual no define cómo retirar la habilitación de ese par para nuevas reservas sin eliminar su referencia histórica. La política y su mecanismo requieren definición y aprobación antes de implementar esa operación; no se añaden `activo`, `estado`, `fecha_fin` ni estructuras de vigencia por esta aclaración. La existencia del par y la actividad del Médico y de la Especialidad no resuelven por sí solas ese retiro específico.

### 3.16. `HorarioAtencion`

Varios intervalos por Médico; consultorio derivado de su asignación fija.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_horario` | `BigInt` | No | — | — |
| FK | `id_medico` | `BigInt` | No | `Medico` | — |
| — | `dia_semana` | `Integer` | No | — | — |
| — | `hora_inicio` | `Time` | No | — | — |
| — | `hora_fin` | `Time` | No | — | — |
| — | `activo` | `Boolean` | No | — | — |

### 3.17. `ConfiguracionClinica`

Una sola sede y una configuración vigente; no incorpora administración de múltiples sedes.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_configuracion` | `Integer` | No | — | — |
| — | `nombre_sede` | `String` | No | — | — |
| — | `direccion_sede` | `Text` | Sí | — | — |
| — | `zona_horaria` | `String` | No | — | Valores: `America/La_Paz` |
| — | `duracion_turno_minutos` | `Integer` | No | — | — |
| — | `actualizado_en` | `TimestampTZ` | No | — | — |

### 3.18. `Cita`

FK compuesta (id_medico,id_especialidad) hacia MedicoEspecialidad. Duración y ubicación conservadas por atención.

**Restricciones únicas declaradas:** `(codigo_cita)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_cita` | `BigInt` | No | — | — |
| UQ | `codigo_cita` | `String` | No | — | — |
| FK | `id_paciente` | `BigInt` | No | `Paciente` | — |
| FK | `id_medico` | `BigInt` | No | `MedicoEspecialidad(id_medico,id_especialidad)` | — |
| FK | `id_especialidad` | `BigInt` | No | `MedicoEspecialidad(id_medico,id_especialidad)` | — |
| FK | `id_consultorio` | `BigInt` | No | `Consultorio` | — |
| FK | `creada_por` | `BigInt` | No | `Usuario` | — |
| — | `inicio_at` | `TimestampTZ` | No | — | — |
| — | `fin_at` | `TimestampTZ` | No | — | — |
| — | `duracion_minutos` | `Integer` | No | — | — |
| — | `estado` | `String` | No | — | Valores: `Programada`, `Confirmada`, `Atendida`, `Cancelada`, `Inasistencia` |
| — | `motivo_breve` | `Text` | Sí | — | — |
| — | `modalidad` | `String` | No | — | Valores: `Presencial` |
| — | `sede_atencion` | `String` | No | — | — |
| — | `consultorio_atencion` | `String` | No | — | — |
| — | `version_programacion` | `Integer` | No | — | — |
| — | `creada_en` | `TimestampTZ` | No | — | — |
| — | `actualizada_en` | `TimestampTZ` | No | — | — |

### 3.19. `CambioCita`

Historial administrativo inmutable: programación, intervalos, duración, ubicación y estados; sin contenido clínico.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_cambio_cita` | `BigInt` | No | — | — |
| FK | `id_cita` | `BigInt` | No | `Cita` | — |
| FK | `id_actor` | `BigInt` | No | `Usuario` | — |
| — | `operacion` | `String` | No | — | — |
| — | `realizado_en` | `TimestampTZ` | No | — | — |
| — | `estado_anterior` | `String` | Sí | — | — |
| — | `estado_nuevo` | `String` | No | — | — |
| — | `datos_anteriores` | `JSONB` | Sí | — | — |
| — | `datos_nuevos` | `JSONB` | No | — | — |
| — | `motivo` | `Text` | Sí | — | — |

### 3.20. `Recordatorio`

Destinatario derivado de Cita.id_paciente, incluso sin cuenta. Un aviso por cita y versión de programación.

**Restricciones únicas declaradas:** `(id_cita, version_programacion)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_recordatorio` | `BigInt` | No | — | — |
| FK | `id_cita` | `BigInt` | No | `Cita` | — |
| — | `version_programacion` | `Integer` | No | — | — |
| — | `generado_en` | `TimestampTZ` | No | — | — |
| — | `leido_en` | `TimestampTZ` | Sí | — | — |
| — | `invalidado_en` | `TimestampTZ` | Sí | — | — |

### 3.21. `Bitacora`

Nombre físico: bitacora. CU-30: consulta exclusiva del Administrador. Auditoría automática transversal, solo inserción y lectura ordinarias; sin secretos ni contenido clínico. Usuario responsable y cuenta objetivo son relaciones distintas y opcionales. Ampliación propia compatible con RN12 y RN14.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_evento` | `BIGINT` | No | — | Identificador único generado en PostgreSQL. |
| — | `tipo_evento` | `VARCHAR(80)` | No | — | Clasificación técnica controlada en constantes del backend; no representa un nuevo CU. |
| — | `tipo_actor` | `VARCHAR(12)` | No | — | Valores: `USUARIO`, `SISTEMA`, `ANONIMO`; Origen del evento; no crea roles funcionales. |
| FK | `id_usuario_responsable` | `BIGINT` | Sí | `Usuario` | Obligatorio cuando: tipo_actor = USUARIO; Responsable autenticado; NULL para SISTEMA o ANONIMO. |
| — | `rol_responsable` | `VARCHAR(24)` | Sí | — | Valores: `Administrador`, `Recepcionista`, `Médico`, `Paciente`; Obligatorio cuando: tipo_actor = USUARIO; Rol histórico al ocurrir el evento; no se actualiza retroactivamente. |
| FK | `id_usuario_objetivo` | `BIGINT` | Sí | `Usuario` | Cuenta afectada, distinta o igual al responsable. Una identidad inferida en login fallido nunca se atribuye como responsable. |
| — | `rol_objetivo` | `VARCHAR(24)` | Sí | — | Valores: `Administrador`, `Recepcionista`, `Médico`, `Paciente`; Obligatorio cuando: id_usuario_objetivo IS NOT NULL; Rol histórico de la cuenta objetivo; NULL cuando no existe cuenta objetivo. |
| — | `modulo` | `VARCHAR(40)` | No | — | Módulo existente relacionado con el evento; valores controlados. |
| — | `operacion` | `VARCHAR(60)` | No | — | Acción ejecutada o intentada; nomenclatura consistente. |
| — | `resultado` | `VARCHAR(16)` | No | — | Valores: `EXITO`, `FALLO`, `DENEGADO`, `ERROR`; EXITO: confirmada; FALLO: objetivo no alcanzado; DENEGADO: seguridad; ERROR: fallo técnico inesperado. |
| — | `causa_codigo` | `VARCHAR(64)` | Sí | — | Código seguro como CREDENCIALES_INVALIDAS, SIN_PERMISO, CUENTA_INACTIVA, CODIGO_EXPIRADO o TURNO_OCUPADO; sin SQL ni trazas. |
| — | `ocurrido_en` | `TIMESTAMPTZ` | No | — | Instante real del evento, originado en backend confiable. Campo del filtro por fecha; presentación America/La_Paz. |
| — | `registrado_en` | `TIMESTAMPTZ` | No | — | Predeterminado documentado: `clock_timestamp()`; Instante de inserción asignado por PostgreSQL. Orden principal junto con id_evento; no es fecha de modificación. |
| — | `direccion_ip` | `INET` | Sí | — | IPv4/IPv6 derivada de la petición en Laravel y proxies confiables; NULL si no está disponible. |
| — | `procedencia_ip` | `VARCHAR(24)` | Sí | — | Valores: `DIRECTA`, `PROXY_CONFIABLE`; Forma de obtención de la IP; no geolocalización. NULL cuando no puede determinarse. |
| — | `entidad_referida` | `VARCHAR(64)` | Sí | — | Entidad principal en lista permitida. Referencia documental heterogénea, sin FK universal. |
| — | `registro_referido` | `VARCHAR(64)` | Sí | — | Identificador interno del recurso principal. Requiere entidad_referida; validación del servicio. |
| — | `correlacion` | `UUID` | No | — | UUID generado por el backend por petición/operación o tarea. Compartido por sus eventos; NO UNIQUE. |
| FK | `id_evento_relacionado` | `BIGINT` | Sí | `Bitacora` | Evento anterior opcional, previamente persistido. FK inmediata y vínculo inmutable; sin autorreferencia directa ni ciclos. |
| — | `version_formato` | `SMALLINT` | No | — | Predeterminado documentado: `1`; Versión positiva del formato de auditoría; valor inicial 1. |
| — | `cambios_seguros` | `JSONB` | Sí | — | Objeto con cambios administrativos expresamente permitidos; no versiones clínicas ni datos secretos. |
| — | `metadatos_seguros` | `JSONB` | Sí | — | Objeto con metadatos operativos mínimos, claves permitidas, límites de tamaño y valores validados; nunca request/respuesta completa. |
| — | `referencias` | `JSONB` | Sí | — | Objeto de referencias adicionales a recursos internos autorizados; sin URLs firmadas, credenciales, documentos ni duplicación contradictoria de FK. |

**Nombre físico en el DER:** `bitacora` (no `bitacoras`). Efectuar escrituras automáticas y lecturas autorizadas; no exponer edición ni eliminación ordinarias.

### 3.22. `ConsultaMedica`

Paciente y Médico responsable derivados de Cita; no se duplican sus identificadores.

**Restricciones únicas declaradas:** `(codigo_consulta)`, `(id_cita)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_consulta` | `BigInt` | No | — | — |
| UQ | `codigo_consulta` | `String` | No | — | — |
| FK/UQ | `id_cita` | `BigInt` | No | `Cita` | — |
| — | `iniciada_en` | `TimestampTZ` | Sí | — | Instante real de inicio si se conoce; NULL no demuestra ausencia de atención ni habilita cambios administrativos. |
| — | `creada_en` | `TimestampTZ` | No | — | Creación obligatoria del registro; no sustituye ni completa automáticamente iniciada_en. |

### 3.23. `VersionConsulta`

Versiones completas e inmutables en almacenamiento clínico protegido; la mayor versión es la vigente.

**Restricciones únicas declaradas:** `(id_consulta, numero_version)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_version_consulta` | `BigInt` | No | — | — |
| FK | `id_consulta` | `BigInt` | No | `ConsultaMedica` | — |
| — | `numero_version` | `Integer` | No | — | — |
| FK | `id_autor` | `BigInt` | No | `Usuario` | — |
| — | `registrada_en` | `TimestampTZ` | No | — | — |
| — | `motivo_cambio` | `Text` | Sí | — | — |
| — | `tipo_consulta` | `String` | No | — | Valores: `Control`, `Enfermedad` |
| — | `motivo_consulta` | `Text` | No | — | — |
| — | `sintomas` | `Text` | Sí | — | — |
| — | `observaciones` | `Text` | Sí | — | — |
| — | `diagnostico` | `Text` | Sí | — | — |
| — | `indicaciones_tratamiento` | `Text` | Sí | — | — |

### 3.24. `VersionAntecedente`

Versiones clínicas inmutables con autor, fecha y consulta de origen obligatoria. El Paciente debe coincidir con ConsultaMedica → Cita; la bitácora general conserva solo referencias.

**Restricciones únicas declaradas:** `(id_paciente, numero_version)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_version_antecedente` | `BigInt` | No | — | — |
| FK | `id_paciente` | `BigInt` | No | `Paciente` | — |
| — | `numero_version` | `Integer` | No | — | — |
| FK | `id_autor` | `BigInt` | No | `Usuario` | — |
| — | `registrada_en` | `TimestampTZ` | No | — | — |
| — | `motivo_cambio` | `Text` | Sí | — | — |
| — | `tipo_sangre` | `String` | Sí | — | — |
| — | `alergias` | `Text` | Sí | — | — |
| — | `enfermedades_cronicas` | `Text` | Sí | — | — |
| — | `medicamentos` | `Text` | Sí | — | — |
| — | `enfermedades_familiares` | `Text` | Sí | — | — |
| — | `antecedentes_relevantes` | `Text` | Sí | — | — |
| FK | `id_consulta_origen` | `BigInt` | No | `ConsultaMedica` | Obligatorio cuando: Toda nueva versión creada mediante CU-23; Consulta real del mismo Paciente y del Médico responsable; no se prevén excepciones de migración no existentes. |

### 3.25. `SolicitudLaboratorio`

Paciente y Médico solicitante se derivan de ConsultaMedica y Cita. Los dos punteros deben pertenecer a esta solicitud.

**Restricciones únicas declaradas:** `(id_version_actual)`, `(id_revision_publicada)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_solicitud` | `BigInt` | No | — | — |
| FK | `id_consulta` | `BigInt` | No | `ConsultaMedica` | — |
| — | `estudio_solicitado` | `String` | No | — | — |
| — | `solicitada_en` | `TimestampTZ` | No | — | — |
| — | `estado_interno` | `String` | No | — | Valores: `Pendiente`, `En revisión`, `Disponible` |
| FK/UQ | `id_version_actual` | `BigInt` | Sí | `VersionResultado(id_solicitud,id_version_resultado)` | UQ condicionada: NOT NULL |
| FK/UQ | `id_revision_publicada` | `BigInt` | Sí | `RevisionResultado(id_revision,id_version_resultado)` | UQ condicionada: NOT NULL |

### 3.26. `VersionResultado`

En el circuito ordinario previo a publicación (§5.8), cada asociación o sustitución crea una versión; la versión de origen conserva la trazabilidad, incluso entre solicitudes.

**Restricciones únicas declaradas:** `(id_solicitud, numero_version)`, `(id_solicitud, id_version_resultado)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_version_resultado` | `BigInt` | No | — | — |
| FK | `id_solicitud` | `BigInt` | No | `SolicitudLaboratorio` | — |
| FK | `id_documento` | `BigInt` | No | `DocumentoLaboratorio` | — |
| — | `numero_version` | `Integer` | No | — | — |
| FK | `id_version_origen` | `BigInt` | Sí | `VersionResultado` | — |
| FK | `id_autor` | `BigInt` | No | `Usuario` | — |
| — | `registrada_en` | `TimestampTZ` | No | — | — |
| — | `motivo_cambio` | `Text` | Sí | — | — |

### 3.27. `DocumentoLaboratorio`

Archivo inmutable PDF o imagen; ruta privada y acceso autorizado, nunca enlace público directo.

**Restricciones únicas declaradas:** `(ruta_privada)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_documento` | `BigInt` | No | — | — |
| FK | `cargado_por` | `BigInt` | No | `Usuario` | — |
| UQ | `ruta_privada` | `String` | No | — | — |
| — | `nombre_original` | `String` | No | — | — |
| — | `tipo_mime` | `String` | No | — | — |
| — | `tamano_bytes` | `BigInt` | No | — | — |
| — | `sha256` | `String` | No | — | — |
| — | `cargado_en` | `TimestampTZ` | No | — | — |

### 3.28. `RevisionResultado`

La decisión es un acto de revisión, no un cuarto estado de laboratorio. Devolver exige motivo.

**Restricciones únicas declaradas:** `(id_revision, id_version_resultado)`.

| Marca | Atributo exacto | Tipo del DER | NULL | Referencia | Condición / valores / notas |
|---|---|---|---|---|---|
| PK | `id_revision` | `BigInt` | No | — | — |
| FK | `id_version_resultado` | `BigInt` | No | `VersionResultado` | — |
| FK | `id_medico` | `BigInt` | No | `Medico` | — |
| — | `decision` | `String` | No | — | Valores: `Autorizar`, `Devolver` |
| — | `motivo` | `Text` | Sí | — | — |
| — | `revisada_en` | `TimestampTZ` | No | — | — |

## 4. Relaciones gráficas y claves foráneas (43)

Cada fila corresponde a un conector real de la primera pestaña del `.drawio`. Se listan **entidad principal**, **entidad dependiente**, y columna(s) de la FK. Las cardinalidades se determinan conjuntamente con NULL/UQ de la sección 3 y las reglas de la sección 5: no basta con dibujar una línea para imponerlas en PostgreSQL.

| Nº | Entidad referida | Entidad con FK | Atributo(s) del lado dependiente | Condición especial |
|---:|---|---|---|---|
| 1 | `Rol` | `Usuario` | `id_rol` | — |
| 2 | `Usuario` | `Sesion` | `user_id` | — |
| 3 | `Usuario` | `TokenAcceso` | `id_usuario` | — |
| 4 | `Usuario` | `Paciente` | `id_usuario` | — |
| 5 | `Paciente` | `ContactoEmergencia` | `id_paciente` | — |
| 6 | `Paciente` | `PreferenciaPaciente` | `id_paciente` | — |
| 7 | `Paciente` | `CodigoActivacionPaciente` | `id_paciente` | — |
| 8 | `Usuario` | `CodigoActivacionPaciente` | `id_recepcionista` | — |
| 9 | `Paciente` | `RegistroPacientePendiente` | `id_paciente` | — |
| 10 | `CodigoActivacionPaciente` | `RegistroPacientePendiente` | `id_codigo_activacion` | — |
| 11 | `RegistroPacientePendiente` | `VerificacionCorreo` | `id_registro_pendiente` | — |
| 12 | `Usuario` | `VerificacionCorreo` | `id_usuario` | — |
| 13 | `Usuario` | `Medico` | `id_usuario` | — |
| 14 | `Consultorio` | `Medico` | `id_consultorio` | — |
| 15 | `Medico` | `MedicoEspecialidad` | `id_medico` | — |
| 16 | `Especialidad` | `MedicoEspecialidad` | `id_especialidad` | — |
| 17 | `Medico` | `HorarioAtencion` | `id_medico` | — |
| 18 | `Paciente` | `Cita` | `id_paciente` | — |
| 19 | `Consultorio` | `Cita` | `id_consultorio` | — |
| 20 | `Usuario` | `Cita` | `creada_por` | — |
| 21 | `MedicoEspecialidad` | `Cita` | `id_medico,id_especialidad` | FK compuesta |
| 22 | `Cita` | `CambioCita` | `id_cita` | — |
| 23 | `Usuario` | `CambioCita` | `id_actor` | — |
| 24 | `Cita` | `Recordatorio` | `id_cita` | — |
| 25 | `Usuario` | `Bitacora` | `id_usuario_responsable` | — |
| 26 | `Cita` | `ConsultaMedica` | `id_cita` | — |
| 27 | `ConsultaMedica` | `VersionConsulta` | `id_consulta` | — |
| 28 | `Usuario` | `VersionConsulta` | `id_autor` | — |
| 29 | `Paciente` | `VersionAntecedente` | `id_paciente` | — |
| 30 | `Usuario` | `VersionAntecedente` | `id_autor` | — |
| 31 | `ConsultaMedica` | `SolicitudLaboratorio` | `id_consulta` | — |
| 32 | `VersionResultado` | `SolicitudLaboratorio` | `id_solicitud,id_version_actual` | FK compuesta |
| 33 | `RevisionResultado` | `SolicitudLaboratorio` | `id_revision_publicada,id_version_actual` | FK compuesta |
| 34 | `SolicitudLaboratorio` | `VersionResultado` | `id_solicitud` | — |
| 35 | `DocumentoLaboratorio` | `VersionResultado` | `id_documento` | — |
| 36 | `VersionResultado` | `VersionResultado` | `id_version_origen` | — |
| 37 | `Usuario` | `VersionResultado` | `id_autor` | — |
| 38 | `Usuario` | `DocumentoLaboratorio` | `cargado_por` | — |
| 39 | `VersionResultado` | `RevisionResultado` | `id_version_resultado` | — |
| 40 | `Medico` | `RevisionResultado` | `id_medico` | — |
| 41 | `Usuario` | `Bitacora` | `id_usuario_objetivo` | — |
| 42 | `Bitacora` | `Bitacora` | `id_evento_relacionado` | Un evento tiene 0..1 evento anterior; un evento previo puede ser referido por 0..* eventos posteriores. Vínculo inmutable, sin ciclos. |
| 43 | `ConsultaMedica` | `VersionAntecedente` | `id_consulta_origen` | Cada versión deriva de una ConsultaMedica; una consulta origina cero o muchas versiones de antecedentes. |

### 4.1. Cardinalidades y restricciones especialmente sensibles

- `Paciente.id_usuario` y `Medico.id_usuario`: FK opcionales y UQ. Un Paciente o Médico puede existir sin cuenta web; una cuenta vinculada no puede relacionarse con dos fichas del mismo tipo. Verificar además compatibilidad del **rol** con la entidad (no se deduce de una FK).
- `ContactoEmergencia.id_paciente` y `PreferenciaPaciente.id_paciente`: FK y UQ; máximo un registro de cada clase por Paciente.
- `MedicoEspecialidad`: N:M con PK compuesta (`id_medico`, `id_especialidad`). `Cita` referencia **ambos campos a la vez**, evitando seleccionar una especialidad ajena al Médico.
- `ConsultaMedica.id_cita`: FK/UQ; cada Cita tiene **0..1 consulta**. Una consulta puede dar origen a varias versiones clínicas y varias solicitudes de laboratorio.
- `VersionConsulta`: UQ (`id_consulta`, `numero_version`). `VersionAntecedente`: UQ (`id_paciente`, `numero_version`) y `id_consulta_origen` obligatorio.
- `Recordatorio`: UQ (`id_cita`, `version_programacion`), no una fila por Paciente/cuenta. El destinatario se deriva de `Cita.id_paciente`.
- `SolicitudLaboratorio`: mantener las referencias circulares **opcionales** `id_version_actual` e `id_revision_publicada`, resolviendo la pertenencia con **dos FK compuestas** y unicidades respaldadas: `VersionResultado(id_solicitud, id_version_resultado)` y `RevisionResultado(id_revision, id_version_resultado)`.
- `Bitacora`: dos FK diferentes hacia `Usuario`: actor responsable y cuenta objetivo. Ambas son opcionales; otra FK opcional refiere un evento anterior de la propia `Bitacora`.

## 5. Reglas de integridad y funcionamiento extraídas de la segunda pestaña

Se conservan las decisiones expresadas en la pestaña **«Reglas de integridad»**. Están divididas por tema. Se han omitido solamente repeticiones introductorias ya cubiertas en §1; donde Draw.io apunta a una sección inexistente, esta guía desarrolla la regla directamente, sin reproducir esa referencia rota.

### 5.1. ALCANCE DEL MODELO Y CONVENCIONES

- Modelo lógico propuesto basado en Clinica_Vida_Salud_Coherencia_Actualizada(2).pdf (117 páginas), el examen oficial y PN01–PN24 / CU-01–CU-30. No representa migraciones ejecutadas.
- PK: clave primaria. FK: clave foránea. UQ: unicidad. IDX: índice. Cardinalidades mínimas y máximas junto a los extremos; se conserva la notación numérica del modelo.
- Los tipos, valores admitidos, nulabilidad y restricciones también se conservan como datos editables de las filas y tablas de Draw.io.
- Los tipos lógicos se conservan; los campos nativos de Sesion y los tipos PostgreSQL indicados son decisiones de diseño. Longitudes restantes y migraciones se precisarán en el diseño físico.
- Los campos no marcados como opcionales en sus metadatos son obligatorios. Las unicidades opcionales admiten varios NULL y evitan repetir valores presentes.
- Las tablas de códigos, procesos pendientes, sesiones y límites son soporte técnico de los casos de uso existentes; no incorporan nuevas funciones.
- Una sede, varios consultorios y modalidad Presencial. No se incluyen pagos, telemedicina, farmacia, inventario ni administración de múltiples sedes.
- RF01–RF13 y RN01–RN14 se conservan. Los módulos clínico, documental y de seguridad adicionales corresponden a decisiones propias del proyecto.

### 5.2. USUARIOS, IDENTIDAD Y ROLES FIJOS

- Rol contiene únicamente Administrador, Recepcionista, Médico y Paciente. Los permisos se definen en el código; no existen tablas ni pantallas para configurarlos.
- Usuario.correo_acceso es único tras normalización uniforme, sin duplicados por mayúsculas/minúsculas; la estrategia física se definirá en PostgreSQL. El correo de contacto no autentica ni cambia el de acceso.
- Paciente.id_usuario es FK opcional y única: cada paciente tiene cero o una cuenta y una cuenta vinculada identifica un solo paciente. Su rol debe ser Paciente.
- Medico.id_usuario es FK opcional y única. El acceso profesional exige una cuenta habilitada con rol Médico y vinculación al registro profesional correspondiente.
- El Administrador asigna exclusivamente roles del personal compatibles. No convierte cuentas de Paciente en personal ni modifica su rol, contraseña, correo de acceso o vinculación.
- Paciente conserva código y CI único cuando se proporciona, sin CI ficticios; se admiten los casos sin CI ya autorizados. Ficha administrativa, cuenta web y bloqueo temporal son condiciones independientes.
- Usuario conserva exclusivamente el hash de la contraseña. password_hash solo admite NULL durante una invitación pendiente; una cuenta activa exige hash y correo verificado.
- Pendiente, Activa y Desactivada son condiciones de la cuenta; el valor persistido Pendiente equivale funcionalmente a Pendiente de verificación (§3.2), sin crear otro estado. El bloqueo temporal se registra aparte. No son estados de cita ni de laboratorio.
- Tres contraseñas incorrectas consecutivas bloquean diez minutos; los intentos durante el bloqueo no lo extienden. El éxito o vencimiento reinicia el contador.
- Las contraseñas tienen al menos doce caracteres y se procesan con los mecanismos de hash de Laravel. La desactivación de cuenta impide también usar sesiones anteriores.
- CU-03: Administrador solo desactiva/reactiva cuentas de Pacientes existentes y verificadas por CU-04; conserva identificador, rol, credenciales, vínculo y todos sus registros. No activa cuentas pendientes omitiendo verificaciones.
- Desactivar invalida sesiones; reactivar exige nuevo inicio de sesión, no restaura sesiones ni elimina bloqueos vigentes y no activa la ficha. Ficha inactiva impide nuevas reservas, pero una cuenta activa puede consultar su historial autorizado.
- Usuario.nombres_personal y apellidos_personal (VARCHAR(120)) son obligatorios y no vacíos para Administrador/Recepcionista sin entidad personal propia; opcionales en general. No se duplican nombres de Medico o Paciente.

### 5.3. ACTIVACIÓN, CORREO Y RECUPERACIÓN

- Paciente nuevo: un RegistroPacientePendiente de tipo Nuevo conserva los datos administrativos exigidos en datos_registro; no requiere código de Recepción.
- Vinculación: RegistroPacientePendiente exige id_paciente e id_codigo_activacion del mismo paciente. Antes, Recepción verifica presencialmente la identidad.
- La validación y reserva del código de Recepción deben ser atómicas: solo un proceso pendiente vigente puede reservarlo para ese Paciente. Mientras esté reservado, otro proceso independiente no puede utilizarlo. Reanudar exige el contexto protegido del mismo proceso, no solo conocer CI o correo.
- Para Nuevo y Vinculacion, impedir procesos independientes vigentes que compitan por el mismo CI cuando exista o correo propuesto, con normalización uniforme; para Vinculacion, también por el mismo Paciente o código de Recepción. Un reintento reutiliza el proceso válido sin reiniciar su vigencia ni sus límites; no expone datos de otro proceso ni crea una ficha definitiva anticipada.
- El código de Recepción tiene doce caracteres aleatorios criptográficos, vigencia de 24 horas, cinco errores y uso único. Solo se conserva su hash.
- Emitir otro código invalida el anterior y el proceso pendiente que lo utilice. La reserva del proceso no prolonga el vencimiento del código original.
- El registro nuevo pendiente vence a las 24 horas; la vinculación vence junto con el código de Recepción. Los datos temporales no se convierten en fichas duplicadas.
- VerificacionCorreo aplica la exclusividad XOR de §3.11: ActivacionPaciente → id_registro_pendiente informado e id_usuario NULL; CambioCorreo → id_usuario informado e id_registro_pendiente NULL. Ambos presentes, ambos ausentes y propósito incompatible son inválidos.
- El código de correo tiene seis dígitos, vigencia de diez minutos, cinco errores y uso único. Un reenvío invalida el anterior y no extiende las 24 horas del proceso.
- Los reenvíos respetan al menos sesenta segundos y un máximo de cinco por hora, por proceso y correo, además del control por origen en ControlIntentos.
- La activación revalida proceso, reserva, vigencias, intentos, unicidades y ausencia de cuenta vinculada dentro de la operación protegida; crea la cuenta y ficha nueva o vincula la ficha existente, marca el proceso completado y consume el código de correo y, en Vinculacion, también el de Recepción, en una misma transacción. Un fallo revierte esos cambios, sin vínculos parciales ni doble uso concurrente.
- PostgreSQL debe respaldar las unicidades y la exclusividad concurrente con restricciones adecuadas, coordinadas con transacciones y bloqueos Laravel. No basta consultar antes de insertar ni comprobar solo las FK opcionales. El diseño físico debe resolver la vigencia y el vencimiento sin asumir que un índice temporal se actualiza por el mero paso del tiempo; la estrategia concreta y sus pruebas siguen pendientes, sin añadir atributos al modelo lógico.
- Un fallo de envío no confirma una entrega inexistente ni habilita la cuenta; el proceso válido se conserva recuperable. Un reintento tras interrupción o respuesta incierta comprueba el estado persistido antes de repetir la confirmación y no crea otra cuenta ni consume otra vez los códigos. El correo externo se coordina tras confirmar la transacción correspondiente; no se trata como reversible mediante rollback SQL.
- El hash temporal se elimina al completar o caducar el proceso conforme a la política técnica; nunca se copia como credencial dentro de Paciente.
- Cambio de correo: exige sesión y contraseña actual; conserva el correo anterior hasta verificar el nuevo. Revalida unicidad al confirmar y no crea otra cuenta.
- El cambio de correo invalida las recuperaciones pendientes emitidas para el correo anterior. Verificar el correo no acredita por sí solo identidad civil.
- Invitación al personal: token de uso único, 24 horas, confirmación de correo y establecimiento de contraseña. Una reemisión invalida la anterior.
- Recuperación: token de uso único, sesenta minutos y cuenta activa con correo verificado. No inicia sesión ni reactiva cuentas; invalida sesiones anteriores al completarse.
- El cambio ordinario de contraseña invalida sesiones anteriores y renueva la actual. Las credenciales temporales excepcionales obligan al cambio antes del acceso normal.
- Brevo se utiliza solo para correos de seguridad. Los límites y respuestas genéricas no revelan si una persona o una ficha existe.
- Sesion representa exclusivamente la tabla nativa sessions de Laravel: id PK VARCHAR(255), user_id FK BIGINT opcional, ip_address VARCHAR(45) opcional, user_agent TEXT opcional, payload TEXT obligatorio y last_activity INTEGER obligatorio.
- user_id → Usuario.id_usuario; índices en user_id y last_activity. Se conservan los nombres esperados por el manejador nativo. Un Usuario tiene 0..* sesiones; cada sesión tiene 0..1 Usuario y puede ser anónima.
- Se usa un único almacén de sesiones, sin tabla paralela. La expiración usa last_activity y la configuración de Laravel; invalidación/eliminación y comprobaciones del servidor aplican CU-01, CU-02, CU-03, CU-05 y CU-06, sin persistir secretos en bitácora.
- La recuperación administrativa excepcional es exclusiva del personal. Los cuatro roles recuperan su contraseña por correo verificado mediante CU-06. Reactivar una cuenta no sustituye recuperación ni verificaciones de identidad.

### 5.4. ORGANIZACIÓN MÉDICA Y CONFIGURACIÓN

- MedicoEspecialidad tiene PK compuesta (id_medico, id_especialidad). Una especialidad puede relacionarse con varios médicos; cada Médico se registra con una o más especialidades.
- Conservar las asociaciones referidas por citas históricas. El retiro de la habilitación futura de un par Médico–Especialidad sigue pendiente según §3.15; no se implementa borrando el par ni se supone resuelto por inactivar todo el Médico o toda la Especialidad.
- Especialidad.nombre es único tras normalización; se incluyen inicialmente Pediatría, Medicina General y Geriatría. Su inactivación conserva las relaciones históricas.
- Medico.id_consultorio admite NULL durante el alta por CU-10. CU-11 asigna un consultorio fijo válido antes de horarios reservables o nuevas citas. Varios Médicos pueden compartirlo solo con horarios compatibles.
- HorarioAtencion admite varios intervalos por Médico; dia_semana utiliza 1=lunes a 7=domingo y hora_fin debe ser posterior a hora_inicio.
- ConfiguracionClinica tiene una sola fila (id_configuracion=1), zona America/La_Paz y duración inicial de quince minutos, configurable y siempre positiva.
- No se inventa una dirección física: direccion_sede es opcional hasta contar con información real. No existe un catálogo de sedes múltiples.
- Un cambio de horario se rechaza íntegramente si perjudica citas futuras. No reprograma ni cancela citas automáticamente.
- Cambiar consultorio fijo valida horarios compartidos y citas futuras. Solo actualiza ubicación de Programadas o Confirmadas sin atención iniciada, en una operación atómica. Una ConsultaMedica que documenta atención real impide ese cambio aunque iniciada_en sea NULL; aplicar §5.7, sin inferir ausencia de atención del estado de Cita.
- El cambio de ubicación conserva código, paciente, Médico, especialidad, fechas, duración y estado; registra CambioCita y actualiza el mismo recordatorio.
- Las citas históricas y sus ubicaciones no cambian. Cualquier conflicto impide aplicar parcialmente la modificación de consultorio.

### 5.5. CITAS, CONCURRENCIA Y TRAZABILIDAD ADMINISTRATIVA

- Cita tiene código único, paciente válido y FK compuesta (id_medico,id_especialidad) hacia MedicoEspecialidad; se mantiene el vínculo aun al inactivar un catálogo.
- Antes de reservar: Paciente, Médico y especialidad activos; pertenencia Médico–especialidad y consultorio fijo válido. El servidor revalida los datos y almacena el consultorio en Cita, preservando la ubicación histórica.
- inicio_at y fin_at se almacenan con referencia temporal inequívoca y se presentan en America/La_Paz; fin_at = inicio_at + duracion_minutos.
- No se reservan fechas pasadas ni intervalos fuera del horario habilitado. Se verifica el intervalo completo, no solo la igualdad de las horas iniciales.
- La duración y la ubicación de cada cita son instantáneas de esa programación; cambiar la configuración no altera retroactivamente las reservas existentes.
- Solo Paciente para sí mismo y Recepción reservan. Solo Recepción reprograma o cancela. El Administrador no obtiene esas operaciones por gestionar la configuración.
- La reprogramación conserva id y código, paciente, Médico y especialidad. Aplica la duración vigente y el consultorio válido, y aumenta version_programacion.
- Paciente inactivo no recibe nuevas citas, pero Recepción puede reprogramar una cita existente. Médico o especialidad inactivos impiden asignarles un nuevo intervalo.
- La reprogramación confirma el nuevo intervalo y libera el anterior atómicamente. La validación del servidor se coordina con protección de concurrencia en PostgreSQL.
- No se permiten intervalos superpuestos para un mismo Médico o consultorio, incluso con solicitudes simultáneas. Los intervalos contiguos sí son compatibles.
- Cancelada no ocupa disponibilidad. Los registros Atendida e Inasistencia no se reutilizan para reservar su intervalo; no se elimina ni altera su atención histórica.
- Diseño físico: transacciones coordinadas y restricciones para RF07/RN07/RN13; una consulta previa o transacción sin coordinación no basta. Se evaluarán exclusiones de rangos temporales por Médico y consultorio, con intervalos [inicio,fin).
- CambioCita conserva autor, fecha, operación, estados y valores administrativos anteriores/nuevos. No guarda diagnósticos ni sustituye las versiones clínicas.
- No se eliminan pacientes, médicos, especialidades, consultorios ni horarios referidos por historia; se desactivan y se restringen eliminaciones en cascada.
- Las restricciones de exclusión, si se adoptan, usarán tipos y operadores compatibles con PostgreSQL y excluirán Cancelada de la ocupación. Horario completo, vigencia de catálogos y cambios concurrentes requieren coordinación adicional; no se declaran implementadas.

### 5.6. CINCO ESTADOS Y CONTROL DE ASISTENCIA

- Estados exclusivos de Cita: Programada, Confirmada, Atendida, Cancelada e Inasistencia. Reprogramada no es un estado.
- Recepción: Programada → Confirmada con presencia e identidad verificadas, el mismo día local y antes del fin del intervalo. Se permite llegada anticipada ese día.
- Después del fin no se confirma ese turno; si sigue Programada y no hubo atención, Recepción puede reprogramar la misma cita y confirmar el nuevo turno válido.
- Recepción: Programada → Inasistencia solo tras finalizar el intervalo y comprobar ausencia. No se aplica desde Confirmada.
- Médico responsable: Programada o Confirmada → Atendida tras realizar la atención. Es un permiso fijo; no requiere confirmación previa de Recepción.
- Recepción puede cancelar Programada o Confirmada antes de la atención. Una cita con atención iniciada no se cancela ni reprograma por los procesos ordinarios. Si ConsultaMedica documenta atención real, la prohibición se mantiene aunque iniciada_en sea NULL y Cita aún figure Programada o Confirmada.
- Reprogramar Programada mantiene Programada; reprogramar Confirmada la devuelve a Programada. Atendida, Cancelada e Inasistencia son finales en las operaciones ordinarias.
- Consultar o completar una consulta y marcar Atendida son operaciones independientes; guardar el registro clínico no cambia automáticamente el estado de Cita.

### 5.7. HISTORIA CLÍNICA Y VERSIONES PROTEGIDAS

- La historia clínica es la vista integrada de Paciente, VersionAntecedente, ConsultaMedica y laboratorio. No se crea una tabla duplicada de historia ni de reportes.
- ConsultaMedica.id_cita es FK única: cada cita admite cero o una consulta y cada consulta pertenece exactamente a una cita.
- Paciente y Médico se derivan de la cita; el servidor valida al profesional responsable, sin duplicar identificadores que puedan contradecirse.
- La consulta puede empezar durante la atención con cita Programada o Confirmada, o registrarse/completarse después de Atendida. Nunca para Cancelada o Inasistencia.
- VersionConsulta conserva tipo Control/Enfermedad, motivo, síntomas, observaciones, diagnóstico e indicaciones según corresponda; los campos clínicos se completan progresivamente.
- VersionAntecedente conserva tipo de sangre, alergias, enfermedades crónicas, medicamentos y antecedentes personales y familiares, representados por antecedentes_relevantes y enfermedades_familiares. Paciente, Recepción y Administrador no los editan.
- Las versiones (id_consulta,numero_version) y (id_paciente,numero_version) son únicas. Se inserta una versión completa por cambio, sin sobrescribir la anterior.
- La mayor versión confirmada es la vigente. La numeración y escritura se protegen frente a actualizaciones concurrentes para no perder cambios.
- Las correcciones conservan autor, fecha, motivo cuando corresponda y datos previos/nuevos en almacenamiento clínico protegido. La bitácora general solo guarda referencias.
- Cada nueva VersionAntecedente exige id_consulta_origen → ConsultaMedica.id_consulta. La consulta y su Cita deben corresponder al mismo Paciente de la versión; id_autor debe ser el Usuario del Médico responsable, con cuenta activa y sesión válida.
- Médico profesionalmente inactivo con cuenta activa puede completar atenciones y revisiones ya asignadas; una cuenta desactivada impide todo acceso protegido.
- El Paciente consulta solo su información autorizada. Las preferencias de privacidad no conceden acceso a terceros ni modifican los permisos fijos.
- Una consulta origina 0..* versiones de antecedentes y cada nueva versión tiene exactamente una consulta real. El alta o corrección se guarda íntegramente por CU-23, sin consultas ficticias, cambios de estado de Cita ni sobrescritura de versiones anteriores.
- ConsultaMedica.creada_en es obligatoria; iniciada_en admite NULL si se desconoce el inicio real. No se copia automáticamente creada_en ni se inventan horas. NULL no prueba que no hubo atención: una consulta que documenta atención real impide reprogramar, cancelar o cambiar ordinariamente su ubicación de forma incompatible, aunque Cita siga Programada o Confirmada. El servidor comprueba la atención documentada y las precondiciones bajo coordinación transaccional; no autoriza esas operaciones únicamente por iniciada_en IS NULL ni cambia automáticamente el estado de Cita.
- La pertenencia Paciente–consulta–Cita y la autorización del autor se verifican transaccionalmente en el servidor; una FK aislada no las demuestra. No se prevén registros migrados sin origen: cualquier migración real exigirá una política explícita sin excepciones para nuevas versiones.

### 5.8. LABORATORIO, CORRECCIÓN Y PUBLICACIÓN

**Límite del circuito ordinario (CU-25 y CU-26):** la carga inicial parte de Pendiente; la devolución, corrección de asociación y sustitución se realizan en En revisión, antes de publicar. Disponible no admite por estos CU retiro de publicación, devolución, sustitución, reasociación ni modificación del resultado ya publicado. Cualquier procedimiento posterior a la publicación requiere definición funcional y autorización adicionales; no se deduce de conservar versiones ni se incorpora una transición de retorno.

- Una consulta puede originar cero o varias solicitudes; cada solicitud corresponde a un estudio y deriva paciente/Médico de la consulta y la cita.
- Estados internos exclusivos: Pendiente, En revisión y Disponible. Autorizar/Devolver son decisiones de revisión y no agregan estados.
- DocumentoLaboratorio contiene metadatos del archivo privado; VersionResultado conserva su asociación, número de versión, autor, fecha, origen y motivo de cambio.
- Cada nueva asociación o sustitución agrega una versión inmutable. Los documentos anteriores permanecen protegidos; nunca se sobrescribe el archivo para ocultar el cambio.
- FK compuesta propuesta: SolicitudLaboratorio(id_solicitud,id_version_actual) → VersionResultado(id_solicitud,id_version_resultado), con UQ del par referenciado. La versión actual pertenece necesariamente a la misma solicitud; admite NULL antes de la carga.
- Una corrección de asociación previa a publicación conserva la versión de origen, crea la asociación corregida y actualiza las solicitudes afectadas en una transacción trazable, revalidando que ninguna esté Disponible. No reasigna ni altera resultados publicados.
- La solicitud devuelta permanece En revisión durante la corrección. El documento incorrecto no se publica ni queda disponible al Paciente.
- RevisionResultado siempre refiere una versión exacta. Solo el Médico solicitante puede Autorizar o Devolver un resultado En revisión; Devolver exige motivo, autor y fecha y mantiene En revisión, sin retirar publicaciones existentes.
- FK compuesta propuesta: SolicitudLaboratorio(id_revision_publicada,id_version_actual) → RevisionResultado(id_revision,id_version_resultado), con UQ del par. La revisión publicada corresponde a la versión actual y, por la otra FK, a la misma solicitud.
- La publicación revalida simultáneamente versión, estado y autorización; si la versión cambió desde la revisión, se rechaza y exige revisar la nueva.
- El Administrador carga inicialmente en Pendiente o corrige asociaciones o sustituye documentos devueltos en En revisión, pero no interpreta, modifica contenido clínico ni autoriza la publicación; CU-25 no le permite modificar un resultado Disponible.
- La corrección de un documento devuelto no autoriza automáticamente su publicación. El Médico debe revisar nuevamente la versión corregida.
- El Paciente ve Pendiente para los estados internos Pendiente y En revisión; solo ve Disponible y descarga la versión vigente con autorización de publicación válida.
- Las versiones equivocadas o no publicadas nunca se sirven al Paciente. Toda consulta o descarga comprueba sesión, rol y relación; las rutas no son públicas.
- La publicación exige además decisión Autorizar del Médico solicitante, versión vigente y revisión finalizada. Es una validación transaccional de autorización y estado; las FK o un CHECK de una fila no verifican por sí solos estas condiciones entre tablas.
- Insertar primero la solicitud Pendiente con ambos punteros NULL; después documento y versión, y asignar versión actual en En revisión. Crear la revisión de esa versión; solo al autorizar se asigna revisión publicada y Disponible en una misma transacción.
- Disponible exige ambos punteros no nulos; una revisión publicada exige versión actual. Pendiente y En revisión mantienen id_revision_publicada NULL. Devolver y corregir antes de publicar conserva las revisiones anteriores y mantiene En revisión sin publicación; la versión corregida exige una nueva revisión médica. Esta regla no autoriza retirar una publicación ni regresar desde Disponible.

### 5.9. RECORDATORIOS, REPORTES Y BITÁCORA

- Recordatorio pertenece a Cita y su destinatario se deriva de Cita.id_paciente: puede generarse aunque el paciente aún no tenga cuenta web.
- UQ(id_cita,version_programacion) asegura idempotencia: como máximo un recordatorio por cita y programación. Leer o regenerar no duplica el aviso.
- Generación automática 24 horas antes del inicio. Con menos anticipación, después del guardado exitoso de la reserva o en la siguiente ejecución del programador, mientras sea pertinente y antes del fin del turno. Nunca por una reserva fallida.
- La reserva inicia Programada; generar el aviso no exige Confirmada. Confirmada significa exclusivamente llegada presencial verificada por Recepción (CU-17). Cancelada, Atendida e Inasistencia no generan nuevos avisos de asistencia.
- La reprogramación aumenta version_programacion, invalida el aviso anterior y recalcula el nuevo. El cambio autorizado de consultorio actualiza el mismo aviso.
- Cancelación, Atendida, Inasistencia o fin del turno retiran el aviso de próximas atenciones, conservando registros históricos. Solo se muestran avisos vigentes y pertinentes.
- PreferenciaPaciente controla recordatorios internos; su ausencia utiliza el valor predeterminado definido en la aplicación. Deshabilitar impide nuevas emisiones.
- Al habilitar de nuevo solo se consideran citas futuras pertinentes y se respeta la unicidad. No hay recordatorios por correo, WhatsApp, SMS ni push externo.
- Los reportes RF13, agenda e historial se obtienen mediante consultas de las tablas existentes; no se duplican resultados en tablas de reportes o historial de citas.
- Bitacora registra automáticamente eventos de los módulos existentes. Se conserva una sola entidad, ampliada para CU-30, sin nuevos RF, RN, casos de uso ni roles.
- id_usuario se renombra a id_usuario_responsable. USUARIO exige responsable autenticado; SISTEMA y ANONIMO lo dejan NULL. Cuenta objetivo es una segunda FK independiente.
- CU-30 es exclusivo del Administrador: consulta, filtros, paginación y detalle de eventos; sin edición ni eliminación, ni permisos clínicos adicionales.
- cambios_seguros, metadatos_seguros y referencias excluyen secretos y contenido clínico; las versiones de consultas, antecedentes y documentos permanecen en sus entidades protegidas.
- Las reglas entre varias tablas, roles, transiciones y vencimientos requieren validación del servidor y transacciones; el dibujo no las implementa automáticamente.
- Los avisos se generan y conservan sin sesión activa ni acción manual; solo se consultan con cuenta y titularidad válidas. No usan correo ni canales externos. La frecuencia del programador se definirá durante el diseño técnico.

## 6. Contrato para el diseño físico PostgreSQL (trabajo futuro)

Lo siguiente es **guía de ejecución**, no estructura adicional exigida por el DER. Antes de generar código, leer los tres `AGENTS.md`, `docs/ai/contexto-maestro.md`, `docs/ai/requerimientos.md`, `docs/ai/reglas-negocio.md` y `docs/ai/casos-de-us.md`. El DER tiene prioridad sobre decisiones improvisadas de nombres y relaciones.

### 6.1. Convenciones y decisiones a documentar antes de migrar

1. Elegir y registrar el **nombre físico de las 26 tablas cuyo nombre físico no está fijado** en el DER. Son 28 entidades en total; **`Sesion` → `sessions`** y **`Bitacora` → `bitacora`** sí están fijados. No permitir pluralizaciones automáticas distintas de las escogidas.
2. Preservar nombres de **columnas** del diccionario. Si se requiere cualquier transformación de nombre, consignar explícitamente una tabla de correspondencia y actualizar documentación antes de migrar; no renombrar por convención implícita.
3. Acordar el tamaño máximo de cada `String` y el tipo entero de los ID; no deducir tamaños del nombre. Respetar las longitudes literales `VARCHAR(...)` declaradas. Seleccionar tipos PostgreSQL apropiados (`BIGINT`, `DATE`, `TIME`, `TIMESTAMPTZ`, `TEXT`, `JSONB`, `UUID`, `INET`, etc.).
4. PostgreSQL debe mantener el **mismo tipo** entre FK y columna referida y disponer de PK/UQ real para cada referencia. Para PostgreSQL `BOOLEAN` y `CHECK`/tipos restringidos, usar los valores indicados en la sección 3; no crear enum o estado diferente.
5. Definir índices de consulta para FK y filtros frecuentes, sin tratar todos los índices hipotéticos como ya aprobados. Especificar `UNIQUE`, `CHECK`, índices únicos normalizados y, cuando corresponda, índices parciales.
6. Registrar decisiones de `ON DELETE` y `ON UPDATE` sin aplicar cascadas destructivas al historial. La intención del DER es conservación histórica, desactivación administrativa y trazabilidad; no borrar citas, consultas, documentos ni bitácoras por desactivar usuarios.
7. Los timestamps propios (`creado_en`, `actualizado_en`, `registrada_en`, `ocurrido_en`, etc.) **no son** los `created_at`/`updated_at` convencionales de Laravel. Definir explícitamente en cada modelo si se desactivan timestamps automáticos o se mapean a los nombres del DER.
8. Cuando una restricción no pueda imponerse con una sola FK/CHECK (por ejemplo, roles, estado activo, Médico responsable, misma cita/solicitud o intervalos solapados), especificar cómo la garantiza el backend y cómo se prueba bajo concurrencia. No afirmar que una migración crea una regla que en realidad solo se verifica en el servicio.

### 6.2. Orden de dependencias sugerido para las migraciones

**Secuencia orientativa, no lista de migraciones ejecutadas:**

1. **Identidad y configuración sin dependencias de negocio:** `Rol`, `ControlIntentos`, `Consultorio`, `Especialidad`, `ConfiguracionClinica`.
2. **Usuarios e infraestructura:** `Usuario`, `Sesion` (tabla nativa), `TokenAcceso`; y después estructuras que dependen de Usuario/Paciente.
3. **Pacientes y activación:** `Paciente`, `ContactoEmergencia`, `PreferenciaPaciente`, `CodigoActivacionPaciente`, `RegistroPacientePendiente`, `VerificacionCorreo`.
4. **Médicos y horarios:** `Medico`, `MedicoEspecialidad`, `HorarioAtencion`.
5. **Citas y seguimiento:** `Cita` (incluida FK compuesta a `MedicoEspecialidad`), `CambioCita`, `Recordatorio`.
6. **Registro clínico:** `ConsultaMedica`, `VersionConsulta`, `VersionAntecedente`.
7. **Laboratorio en varias fases:** crear `SolicitudLaboratorio` con los campos `id_version_actual`/`id_revision_publicada` **nulos** y, si es necesario, diferir sus FK; crear `DocumentoLaboratorio`, `VersionResultado`, `RevisionResultado`; crear UQ compuestas e incorporar después las FK compuestas y condiciones entre columnas. El orden exacto dependerá del motor de migraciones y restricciones elegidas.
8. **Auditoría:** `Bitacora`, con ambas FK hacia Usuario y la autorreferencia de evento anterior. Su creación estructural no implica que las operaciones ya registren eventos.
9. **Restricciones finales y verificación:** índices normalizados, restricciones de horario y estados, integridad de laboratorio, reglas de concurrencia para citas, verificación de índices/FK/NOT NULL, así como la protección de datos clínicos y auditoría.

En un repositorio existente, **inspeccionar primero las migraciones aplicadas** y `migrations` en PostgreSQL; nunca borrar, reiniciar o recrear una base con datos sin autorización. La secuencia anterior no autoriza crear tablas de negocio antes de aprobar el diseño físico.

### 6.3. Restricciones compuestas y transacciones obligatorias

- **Cita ↔ MédicoEspecialidad:** `FOREIGN KEY (id_medico, id_especialidad) REFERENCES MedicoEspecialidad(id_medico, id_especialidad)` (nombres físicos reales según convención acordada). Verificar además Médico y Especialidad activos en el momento de una nueva reserva.
- **Retiro de asociación profesional:** preservar el par referenciado por citas; resolver la política pendiente de §3.15 antes de implementar el retiro de su habilitación futura. Esta revisión no añade columnas ni un mecanismo de vigencia.
- **Solicitud ↔ versión actual:** `FOREIGN KEY (id_solicitud, id_version_actual) REFERENCES VersionResultado(id_solicitud, id_version_resultado)`, con `UNIQUE(id_solicitud, id_version_resultado)` en la tabla referida.
- **Solicitud ↔ revisión publicada:** `FOREIGN KEY (id_revision_publicada, id_version_actual) REFERENCES RevisionResultado(id_revision, id_version_resultado)`, con `UNIQUE(id_revision, id_version_resultado)` en la tabla referida. La relación con la misma solicitud se obtiene conjuntamente con la FK de versión actual y la referencia de la revisión a esa versión.
- **Ciclo de laboratorio:** insertar solicitud en `Pendiente` con ambos punteros NULL, cargar Documento, crear Versión y apuntar la versión actual en `En revisión`, crear Revisión y, solo tras `Autorizar` por el Médico correspondiente, establecer `id_revision_publicada` y `Disponible` en una transacción. `Devolver` exige motivo y mantiene `En revisión` con `id_revision_publicada` NULL; la corrección exige nueva revisión. Revalidar estado y versión bajo protección concurrente para impedir que una devolución o sustitución compita con una publicación ya confirmada. CU-25 y CU-26 no autorizan retirar ni modificar un resultado `Disponible` (§5.8).
- **Reservas simultáneas:** transacción más protección de PostgreSQL ante superposiciones del Médico **y** consultorio. Con rangos `[inicio_at, fin_at)` los turnos contiguos son compatibles. Examinar exclusión de rangos y estrategia para cambios de horario; omitir Cancelada de la ocupación. **No considerar resuelto RF07/RN13 solo por consultar disponibilidad antes de insertar.**
- **Versiones clínicas:** controlar transaccionalmente `numero_version`, autor, Médico responsable y pertenencia a la Cita. Nunca sobrescribir versiones previas ni poner contenido clínico en `Bitacora`.
- **Códigos y tokens:** hash seguro, expiración, consumo de un solo uso e invalidación ante reemisiones, con protección de carreras entre verificaciones concurrentes.
- **Activaciones y XOR:** garantizar la reserva exclusiva y la confirmación atómica de §5.3 mediante restricciones PostgreSQL y coordinación transaccional Laravel; reutilizar el proceso válido ante reintentos. La condición de VerificacionCorreo debe comprobar tanto exactamente una FK presente como su correspondencia con proposito (§3.11), sin cambiar las nulabilidades del diccionario.
- **Unicidad normalizada:** correos de acceso y nombres de especialidad únicos después de normalización consistente; CI único cuando exista; múltiples NULL permitidos donde el DER lo autoriza. La aplicación y PostgreSQL deben aplicar exactamente la misma normalización.

### 6.4. Integración con Laravel 13 y modelos Eloquent

**Guía propuesta:** crear, al implementarse cada fase, un modelo Eloquent para cada entidad persistente necesaria, a excepción de `Sesion` si se administra íntegramente con el controlador nativo de sesiones. La ubicación de modelos y migraciones debe seguir la arquitectura `backend/src/` y los `AGENTS.md` del repositorio; no asumir que los directorios estándar de Laravel sustituyen esa arquitectura.

- Configurar `protected $table`, `protected $primaryKey`, autoincremento, `casts` y `timestamps` con los nombres exactos del DER. `TIMESTAMPTZ` y `JSONB` deben tener conversiones seguras.
- **Modelo Usuario:** integrar la autenticación Laravel con `correo_acceso` y `password_hash`; adaptar cómo el proveedor de autenticación obtiene el hash (no asumir una columna `password`). La cuenta activa debe estar verificada; proteger la cuenta de pacientes del CRUD del personal. `Sesion` corresponde a la **única tabla `sessions`** nativa y usa `user_id` nullable.
- **Relaciones de identidad:** `Usuario` pertenece a `Rol`; `Paciente`/`Medico` pertenecen opcionalmente a `Usuario`, y cada Usuario tiene como máximo una ficha vinculada compatible con su rol. `ContactoEmergencia` y `PreferenciaPaciente` son relaciones 1:0..1 con Paciente.
- **Profesionales y agenda:** `Medico` pertenece opcionalmente a `Consultorio`, N:M con `Especialidad` por `MedicoEspecialidad`, y 1:N con `HorarioAtencion`. La clave primaria compuesta del pivote se mantiene en la base, aunque las operaciones Eloquent sobre pivotes exijan tratamiento específico.
- **Citas:** `Cita` pertenece a `Paciente`, `Consultorio` y un par válido Médico–Especialidad; posee muchos `CambioCita`, posibles recordatorios por versión y a lo sumo una `ConsultaMedica`. Respetar `Cita.inicio_at`/`fin_at`, `version_programacion` y los estados.
- **Clínica:** `ConsultaMedica` pertenece a `Cita`; tiene varias `VersionConsulta` y `SolicitudLaboratorio`. `VersionAntecedente` pertenece a `Paciente`, `Usuario` autor y a su `ConsultaMedica` origen; los datos de Médico/Paciente de la consulta se derivan de Cita.
- **Laboratorio:** `SolicitudLaboratorio` pertenece a ConsultaMedica; puede tener varias `VersionResultado`; cada versión apunta a `DocumentoLaboratorio`, autor y, opcionalmente, versión de origen. Cada `RevisionResultado` pertenece a la versión y al Médico revisor. Las dos referencias circulares de solicitud deben gestionarse sin asignaciones inconsistentes ni publicar versiones anteriores.
- **Recordatorios:** `Recordatorio` pertenece a Cita y **no necesita** FK adicional a Usuario ni Paciente. Consulta por Paciente significa unirse a Cita con el identificador vinculado; el generador automático no depende de una sesión web.
- **Bitácora:** `Bitacora` pertenece opcionalmente a Usuario responsable y Usuario objetivo, y opcionalmente al evento anterior. Configurar protección contra actualización/eliminación ordinarias, campos seguros permitidos y consulta restringida a Administrador mediante CU-30.
- `TokenAcceso` y `VerificacionCorreo` deben integrarse con los flujos aprobados, evitando crear almacenes redundantes por scaffolding predeterminado de Laravel (por ejemplo, otra tabla `password_reset_tokens` con lógica paralela).

**No se deben autogenerar todas las entidades mediante convenciones o factories sin revisar los nombres físicos y los campos especiales.** El uso de `belongsToMany`, `belongsTo`, `hasMany`, `hasOne` depende de las FK reales. Las relaciones basadas en dos columnas y las validaciones de rol/estado requerirán consultas o servicios explícitos cuando Eloquent no las represente directamente.

### 6.5. Seeders: datos mínimos y límites

La definición exacta de seeders es una **propuesta para el futuro**. No se ejecuta desde este documento y no permite eludir verificación de cuentas ni generar identidades reales sin permiso.

| Seeder o conjunto | Datos admisibles | Precauciones |
|---|---|---|
| Roles | Exactamente cuatro: **Administrador, Recepcionista, Médico, Paciente** | Idempotente (no duplicar, renumerar arbitrariamente o agregar permisos configurables). |
| Configuración de clínica | Una fila `id_configuracion=1`, sede `Clínica Vida Salud`, zona `America/La_Paz`, `duracion_turno_minutos=15` | No inventar dirección física. Cambios de duración no modifican citas históricas. |
| Especialidades iniciales | **Pediatría, Medicina General, Geriatría** según reglas del DER | La lista puede ampliarse mediante el catálogo normal, pero nunca duplicar nombres normalizados. |
| Consultorios | Solo los aprobados o datos de ejemplo **identificados inequívocamente como ficticios** para desarrollo | No inventar una cantidad obligatoria de consultorios, ni una sede extra. |
| Usuario inicial de administración | Únicamente si se aprueba el procedimiento seguro de inicialización | No guardar cuentas/contraseñas reales en seeders versionados; no dejar claves conocidas en producción. |
| Datos demo de médicos, pacientes, citas, clínicos y laboratorio | Opcionales en entorno de pruebas, mediante factories o seeds delimitados | Nunca en producción por defecto; no simular historias médicas reales, aprobaciones clínicas ni acciones de un usuario sin mecanismos acordados. |

Probar que volver a ejecutar los seeders no duplique catálogos ni altere claves utilizadas por registros históricos. No crear recordatorios mediante seeders como sustituto del programador de tareas.

## 7. Validaciones de aceptación antes de implementar casos de uso

Esta matriz sirve para revisar **migraciones**, **modelos**, **servicios** y **pruebas** cuando existan. No implica que hoy se hayan ejecutado.

| Escenario | Validación exigida |
|---|---|
| Sesión anónima | `sessions.user_id` permite NULL; autenticar vincula usuario; cuenta desactivada no mantiene sesiones autorizadas. |
| Cuenta y ficha | Desactivar/reactivar cuenta no cambia ficha, bloqueo temporal, rol, correo ni vínculo. |
| Paciente duplicado | CI existente normalizado se rechaza; NULL permitido donde proceda. |
| Única cuenta del paciente | Dos cuentas para una misma ficha se rechazan incluso con intentos simultáneos. |
| Activación concurrente y reintentos | Dos procesos independientes vigentes no reservan el mismo Paciente, código, CI presente o correo normalizados; un reintento autorizado reanuda el proceso válido y una confirmación repetida no duplica cuenta, ficha ni consumo. Un fallo no deja vínculo parcial. |
| Propósito de verificación | Solo se aceptan ActivacionPaciente con registro pendiente y sin usuario, o CambioCorreo con usuario y sin registro pendiente; rechazar ambos presentes, ambos ausentes y propósito incompatible. |
| Cuenta pendiente | El valor persistido Pendiente representa Pendiente de verificación y no permite acceso protegido normal. |
| Médico sin consultorio | Alta administrativa válida; no se generan turnos reservables sin consultorio asignado. |
| Médico y especialidad | Una cita con par Médico–Especialidad no registrado falla por FK compuesta. |
| Asociación profesional histórica | Se conserva el par referenciado por citas; el retiro de su habilitación futura requiere resolver §3.15 antes de implementarse. |
| Horario y conflictos | No se aceptan solapamientos concurrentes para un Médico o Consultorio; bloques contiguos sí se aceptan. |
| Estados | Una nueva cita es Programada; Confirmada solo por llegada verificada; Atendida solo por Médico responsable. |
| Una consulta por cita | Segundo registro de ConsultaMedica para la misma Cita se rechaza. |
| Atención real con inicio desconocido | Una consulta que documenta atención real impide reprogramar, cancelar o cambiar ubicación de forma incompatible aunque iniciada_en sea NULL y Cita siga Programada/Confirmada; guardar clínica no marca Atendida automáticamente. |
| Versionado clínico | Dos actualizaciones conservan versiones previas, autor, fecha, relación con consulta y datos protegidos. |
| Antecedente de otro paciente | Se rechaza versión cuyo Paciente no coincide con ConsultaMedica → Cita. |
| Laboratorio ajeno | Una solicitud no puede apuntar a versión o revisión de otra solicitud o de otra versión. |
| Laboratorio no publicado | El Paciente no accede a documentos pendientes o devueltos. |
| Laboratorio publicado | CU-25 y CU-26 rechazan retiro, devolución, sustitución, reasociación o modificación de Disponible; una devolución previa mantiene En revisión sin publicación y una corrección exige nueva revisión médica. |
| Recordatorio próximo | Generación automática con 24h o reserva guardada con menos anticipación; no espera a Confirmada. |
| Aviso repetido | `UNIQUE(id_cita,version_programacion)` evita duplicación concurrente. |
| Reprogramación | Aumenta versión de programación, invalida aviso desactualizado, preserva historial y valida disponibilidad. |
| Bitácora | Registra resultado, actor/objetivo, origen y referencias seguras; no secretos ni historia clínica. |
| Datos persistentes | Operaciones comprobables en PostgreSQL, con FK, UQ, índices y tipos adecuados. |
| Reejecución | Migraciones ejecutadas de manera controlada; seeders idempotentes y sin pérdida de datos. |

## 8. Trazabilidad hacia el examen y los casos de uso

| Requisito o grupo | Entidades principales | Precisión |
|---|---|---|
| RF01 — Acceso y control | `Rol`, `Usuario`, `Sesion`, `TokenAcceso`, `ControlIntentos` | Cuatro roles fijos; autenticación segura y validación de servidor. |
| RF02 — Pacientes | `Paciente`, `ContactoEmergencia`, `PreferenciaPaciente` | Datos administrativos, CI opcional/único, conservación histórica. |
| RF03–RF04 — Médicos y especialidades | `Medico`, `Especialidad`, `MedicoEspecialidad` | Médico activo y catálogo sin nombres duplicados. |
| RF05 — Horarios | `HorarioAtencion`, `Consultorio`, `ConfiguracionClinica` | Duración configurable y turnos dentro de horario. |
| RF06–RF07 — Reservas y duplicaciones | `Cita`, `MedicoEspecialidad`, `Consultorio`, `HorarioAtencion` | Código único; concurrencia y no superposición. |
| RF08–RF10 — Reprogramación, cancelación y estados | `Cita`, `CambioCita` | Cinco estados, historial y disponibilidad. |
| RF11–RF12 — Agenda e historial | `Cita` y relaciones de Médico/Paciente | Consultas y filtros sobre los datos existentes. |
| RF13 — Reportes | `Cita`, `Paciente`, `Medico`, `Especialidad` | Reportes calculados, sin tabla redundante. |
| CU-03–CU-07 — Cuentas y verificación | `Usuario`, `Paciente`, `TokenAcceso`, `CodigoActivacionPaciente`, `RegistroPacientePendiente`, `VerificacionCorreo`, `ControlIntentos` | Identidad, cuenta/ficha/bloqueo independientes y activación controlada. |
| CU-22–CU-23 — Historia y consulta | `ConsultaMedica`, `VersionConsulta`, `VersionAntecedente` | Médico responsable, una consulta por Cita, versiones protegidas. |
| CU-24–CU-27 — Laboratorio | `SolicitudLaboratorio`, `VersionResultado`, `DocumentoLaboratorio`, `RevisionResultado` | Autoriza el Médico; los documentos no publicados no se exponen. |
| CU-28 — Recordatorios | `Recordatorio`, `Cita`, `PreferenciaPaciente` | 24 horas o tras reserva cercana persistida; notificaciones internas. |
| CU-29–CU-30 — Reportes y auditoría | Tablas de negocio y `Bitacora` | Reportes agregados; bitácora consultable solo por Administrador. |

RN01–RN14 permanecen como reglas **oficiales**, sin renumeración; las demás precisiones corresponden al diseño aprobado de la clínica. **No modificar el enunciado para acomodarlo a una migración existente.**

## 9. Flujo de trabajo obligatorio para OpenCode

1. Leer este `.md` completo y localizar el archivo `.drawio` de referencia. Leer también las reglas funcionales y los `AGENTS.md` antes de crear una migración.
2. Comprobar el estado real del backend Laravel/PostgreSQL: esquema existente, migraciones aplicadas, tablas estándar, rutas configuradas y convenciones. **No afirmar que una tabla está creada por aparecer en un diagrama.**
3. Proponer una fase pequeña con lista de entidades afectadas, columnas exactas, PK/FK/UQ/IDX, restricciones de servidor, riesgo de migración y pruebas.
4. Resolver y registrar decisiones físicas pendientes (nombres de tablas, longitudes de tipos lógicos, cascadas, índices, estrategia de concurrencia) con aprobación cuando afecten reglas o integridad histórica.
5. Implementar migraciones respetando dependencias y sin duplicar tablas nativas de Laravel; después definir modelos, relaciones y casts conforme al esquema implementado.
6. Preparar seeders **solo cuando corresponda**, sin datos sensibles hardcodeados ni roles extra, y probar idempotencia.
7. Ejecutar pruebas de esquema y casos de borde relevantes; comprobar en PostgreSQL las restricciones efectivas, no solo los comentarios del código.
8. Actualizar documentación de estado indicando qué existe, qué se probó y qué permanece pendiente. No marcar RF, PN o CU como completados por tener una migración o un modelo.
9. No alterar el `.drawio` ni este archivo para legitimar errores de implementación. Si el DER necesita una corrección real, proponerla explícitamente y mantener sincronizadas las referencias después de aprobarla.

## 10. Control de integridad final de esta documentación

- **28 entidades**, **239 atributos** y **43 conectores** descritos a partir del DER corregido; ninguna entidad inventada.
- Se conservan todos los identificadores, FK simples y compuestas, restricciones UQ, atributos opcionales y listas cerradas indicadas en el Draw.io.
- La documentación distingue los datos fuente de las **orientaciones propuestas para Laravel/PostgreSQL**.
- No hay cambios de código ni migraciones generadas por elaborar este archivo.
- Si futuras modificaciones cambian el `.drawio`, actualizar conjuntamente este diccionario y su trazabilidad; nunca dejar descripciones contradictorias.
