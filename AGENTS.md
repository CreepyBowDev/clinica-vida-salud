# AGENTS.md — Clínica Vida Salud

Este archivo contiene las instrucciones **generales y obligatorias** para OpenCode y cualquier agente de IA que trabaje en el repositorio del proyecto **Sistema Web para la Gestión de Citas Médicas para la Clínica Vida Salud**.

Su propósito es preservar los requisitos del examen, las decisiones aprobadas, la arquitectura acordada, la seguridad de la información médica y la coherencia entre frontend, backend y base de datos. No autoriza a implementar funcionalidades no solicitadas.

## 1. Identidad y alcance del proyecto

- **Tipo:** proyecto universitario de Ingeniería de Requisitos; sistema web cliente-servidor para una clínica privada ficticia.
- **Sede:** una sola sede; atención exclusivamente presencial y con consultorios identificados.
- **Actores/roles:** Administrador, Recepcionista, Médico y Paciente. No crear un quinto rol.
- **Casos de uso:** catálogo continuo `CU-01` a `CU-30`, organizado por módulos. No reutilizar identificadores ni renumerar unilateralmente.
- **Requisitos oficiales:** `RF01`–`RF13` y reglas `RN01`–`RN14` del enunciado del examen. Deben cumplirse íntegramente.
- **Ampliaciones aprobadas:** historia clínica, consultas, laboratorio digital, notificaciones internas, verificación de cuentas, recuperación de credenciales y bitácora; distinguirlas de exigencias literales del examen.
- **Fuera de alcance:** pagos, farmacia, inventario, recetas electrónicas, telemedicina, videollamadas, varias sedes, sistemas externos de recordatorios y permisos dinámicos.

El examen contempla un tiempo de desarrollo limitado. Priorizar siempre los requisitos obligatorios antes que las ampliaciones u optimizaciones opcionales. No reducir las funciones obligatorias por simplificar la implementación.

## 2. Stack técnico vigente

```text
Frontend:       React + Vite + JavaScript + HTML5 + CSS3
Navegación:     React Router
HTTP cliente:   Axios
Estado de login: Context API + hook useAuth
Backend:        PHP + Laravel
ORM:            Eloquent
Base de datos:  PostgreSQL (mediante PDO/pdo_pgsql en Laravel)
Correo:         Brevo, únicamente para mensajes transaccionales de seguridad
Comunicación:   HTTP(S), API y respuestas JSON
```

- React es el **frontend principal**. No desarrollar las vistas funcionales en Blade ni sustituir React por Blade sin una nueva decisión explícita.
- Laravel implementa la API, los procesos de negocio, la autenticación y la persistencia. El frontend **nunca** consulta PostgreSQL directamente.
- Utilizar **sesiones seguras de Laravel** y, si la topología SPA lo requiere, evaluar **Sanctum con cookies y protección CSRF** como solución preferente. Confirmar dominios, CORS y configuración antes de implementarlo. **No asumir JWT personalizado** ni almacenar tokens en `localStorage`.
- No trasladar librerías, comandos o patrones de otros stacks como sustitutos automáticos de Laravel, Eloquent o PostgreSQL.
- El frontend puede usar `pnpm` conforme a la configuración del proyecto; el backend usa Composer y Artisan. Antes de ejecutar comandos, comprobar los archivos reales del repositorio.

## 3. Jerarquía de instrucciones y fuentes

Antes de realizar cambios de comportamiento, consultar las fuentes que realmente existan y estén disponibles:

1. **Enunciado oficial del examen:** límites y obligaciones que no deben vulnerarse.
2. **Decisiones explícitas más recientes y aprobadas del proyecto:** concretan lo que el examen deja abierto.
3. **Documentación funcional vigente:** Alcance, Modelo de Negocio, RF/RN, matriz, casos de uso y correcciones aprobadas.
4. **Documentación de trabajo en `docs/ai/`:** `contexto-maestro.md`, `requerimientos.md`, `reglas-negocio.md`, `casos-de-us.md`, `diagrama-entidad-relacion.md`, `diagrama-clases.md` y `analisis-clases.md`, cuando estén disponibles.
5. **Código, migraciones, pruebas y convenciones existentes:** muestran lo implementado, pero no justifican contradecir un requisito.
6. **Convenciones técnicas generales:** solo para resolver aspectos que las fuentes anteriores no determinan.

**Si la solicitud actual contradice el examen**, identificar la contradicción y pedir aclaración; no implementar el conflicto silenciosamente. Si dos documentos difieren, verificar cuál contiene la decisión más reciente y aprobada. No considerar que una versión antigua de un PDF prevalece sobre una decisión posterior.

**`docs/ai/` es una carpeta planificada explícitamente en la raíz del repositorio**, independiente de `frontend/` y `backend/`. Comprobar qué archivos están realmente disponibles antes de leerlos; si aún no existen, no inventar su contenido ni presentarlos como documentación aprobada. Informar si falta alguno indispensable. La actualización progresiva de los documentos de análisis y diagramas, cuando existan, sí forma parte del trabajo de desarrollo definido en la sección siguiente.

Las referencias antiguas a **Blade como frontend** o a **nombre de usuario para iniciar sesión** están superadas: el proyecto acordó React + Vite y acceso mediante **correo electrónico y contraseña**. Señalar los textos antiguos cuando corresponda actualizarlos; no extender una modificación de código a una reescritura documental no solicitada.

## 4. Documentación central en `docs/ai/` y evolución durante el desarrollo

La carpeta **`/docs/ai/`** pertenece a la **raíz del proyecto**, al mismo nivel que `frontend/` y `backend/`. Constituye la base documental de referencia para desarrollar el software y mantener sincronizadas las decisiones con el código. Está prevista con **estos nombres exactos**:

```text
/docs/ai/
├── contexto-maestro.md
├── requerimientos.md
├── reglas-negocio.md
├── casos-de-us.md
├── diagrama-entidad-relacion.md
├── diagrama-clases.md
└── analisis-clases.md
```

**Responsabilidades de cada archivo:**

| Documento | Finalidad y criterio de mantenimiento |
| --- | --- |
| `contexto-maestro.md` | Contexto global, alcance vigente, decisiones aprobadas, stack, arquitectura y progreso real del proyecto. Actualizar cuando cambie una decisión aprobada o el estado de desarrollo. |
| `requerimientos.md` | RF/RN oficiales y requisitos o ampliaciones aprobadas, distinguiendo su origen. **No editar ni renumerar** los RF/RN del examen por conveniencia técnica. |
| `reglas-negocio.md` | Restricciones verificables, permisos, validaciones, estados, plazos, límites y reglas de cada módulo. Actualizar solo cuando una regla esté aprobada o se aclare sin contradicción. |
| `casos-de-us.md` | Catálogo continuo `CU-01` a `CU-30`, actores, flujos, alternativas y relaciones con requisitos. Mantener identificadores estables y actualizar solo casos afectados. |
| `diagrama-entidad-relacion.md` | Modelo entidad-relación de PostgreSQL: entidades/tablas, PK, FK, cardinalidades, restricciones y relaciones relevantes. **Evoluciona conforme se aprueben e implementen cambios de base de datos**; debe poder contrastarse con migraciones y modelos Eloquent. |
| `diagrama-clases.md` | **Diagrama UML de clases global y acumulativo**, elaborado **progresivamente al completar cada módulo**. Incorporar clases y relaciones efectivamente diseñadas o implementadas en ese módulo; integrar con lo anterior, sin producir diagramas inconexos o anticipar módulos aún no desarrollados. |
| `analisis-clases.md` | **Análisis de clases por caso de uso**. Desarrollar **al completar un CU o un conjunto coherente de CU** durante la implementación; identificar clases participantes, responsabilidades, colaboraciones y relaciones necesarias, basándose en requisitos y solución real. |

### 4.1. `diagrama-clases.md`: actualización después de cada módulo

1. Al terminar un módulo funcional (por ejemplo, **Usuarios y autenticación**, `CU-01` a `CU-06`), inspeccionar las clases PHP/Laravel y las estructuras efectivamente utilizadas en backend y frontend cuando sean pertinentes al modelo documentado.
2. **Actualizar el diagrama global existente**, no sustituirlo por un esquema aislado del módulo: agregar sus clases, asociaciones, dependencias, herencias/composiciones cuando existan y las cardinalidades o multiplicidades justificadas.
3. Reflejar las responsabilidades por capas cuando el diagrama las represente: Controller, Service, Repository, Model, otras clases realmente necesarias. **No inventar clases o patrones únicamente para llenar un diagrama**.
4. Comprobar que las relaciones del módulo nuevo sean coherentes con módulos ya integrados; conservar nomenclatura consistente con el código y el análisis vigente.
5. Distinguir expresamente lo **implementado** de lo **previsto**, si hay elementos aún pendientes. No presentar como terminadas clases que todavía no existen.
6. Mantener el diagrama en un formato de origen editable dentro del archivo Markdown, con su explicación mínima cuando sea necesaria; no depender exclusivamente de una imagen sin fuente modificable.

**Cadencia obligatoria:** actualizar `diagrama-clases.md` **cada vez que se complete un módulo**, y también cuando un cambio posterior modifique sus clases o relaciones. No esperar hasta finalizar todo el sistema.

### 4.2. `analisis-clases.md`: actualización después de uno o varios CU

1. Al finalizar **cada caso de uso**, o un **grupo pequeño de casos de uso relacionados** trabajado como una misma unidad, agregar o revisar su análisis antes de considerar cerrado ese avance.
2. Identificar claramente `CU-XX`, nombre, módulo, actores, objetivo y relaciones con RF/RN pertinentes; describir las clases participantes y **qué responsabilidad cumple cada una**.
3. Mostrar cómo colaboran las clases para ejecutar el flujo principal y los alternativos relevantes, incluida la validación, autorización, persistencia y los errores cuando corresponda. Distinguir clases de interfaz/frontera, control o negocio y entidad/datos **solo donde esa clasificación resulte útil**, sin inventar clases por obligación metodológica.
4. Explicar las relaciones entre clases y las dependencias con otros casos o módulos; reutilizar análisis existentes en lugar de duplicar definiciones contradictorias.
5. El análisis debe partir del CU aprobado y ser coherente con lo realmente diseñado/implementado. Si un análisis previo cambia, actualizar las entradas afectadas y dejar claros los CU pendientes de análisis.
6. **No esperar al cierre del módulo completo** para comenzar `analisis-clases.md`: construirlo de manera incremental mientras se implementan los CU.

**Ejemplo de ritmo:** implementar `CU-01` y `CU-02` → documentar su análisis de clases; continuar con otros CU relacionados → completar sus análisis; terminar `CU-01` a `CU-06` → integrar todas las clases y relaciones del módulo en el **diagrama global** de `diagrama-clases.md`.

### 4.3. `diagrama-entidad-relacion.md`: coherencia con PostgreSQL

- Actualizar el DER siempre que una implementación aprobada agregue o cambie tablas, claves, relaciones o restricciones de integridad.
- Contrastar sus elementos con migraciones, modelos Eloquent y las reglas de negocio; documentar nombres y cardinalidades reales.
- Diferenciar **DER (persistencia)**, **diagrama de clases (estructura y colaboraciones del software)** y **análisis de clases (participación por caso de uso)**; uno no sustituye a los otros.
- No agregar tablas ficticias solo para representar reportes, recordatorios o auditoría: crear o documentar almacenamiento cuando su implementación y requisitos lo justifiquen.

### 4.4. Reglas de sincronización y cierre de tareas

- **Antes de implementar:** consultar `contexto-maestro.md`, `requerimientos.md`, `reglas-negocio.md` y `casos-de-us.md` en lo que corresponda a la tarea, además de los análisis y diagramas ya existentes para evitar duplicaciones.
- **Mientras se implementa:** si el diseño aprobado cambia, mantener las representaciones afectadas sincronizadas; no alterar requisitos o reglas por iniciativa propia para justificar el código.
- **Al terminar un CU o grupo de CU:** actualizar `analisis-clases.md` y el DER si se modificó la persistencia; verificar correspondencia entre código, casos y pruebas.
- **Al terminar un módulo:** actualizar e integrar `diagrama-clases.md`; revisar que todos sus CU tengan análisis de clases, que el DER esté vigente y que las relaciones no contradigan módulos anteriores.
- **Cuando se modifique un módulo ya cerrado:** actualizar de nuevo sus entradas y diagramas afectados; la documentación es **viva**, no un entregable que solo se redacta al final.
- **Si aún falta alguno de estos archivos:** no inventar documentación como si ya existiera ni afirmar que está actualizado. Avisar y solicitar crearlo si su ausencia impide cumplir el cierre documental. No bloquear cambios menores por documentos que no estén disponibles cuando no sean necesarios.
- **En el informe final al usuario:** mencionar qué CU o módulo terminó, qué archivos de `docs/ai/` se actualizaron y cuáles quedaron pendientes, sin declarar cerrado el análisis o diagrama si no se revisó.

---

## 5. Jerarquía de AGENTS.md

```text
/AGENTS.md           → reglas globales: examen, alcance, seguridad y colaboración
/backend/AGENTS.md   → Laravel, capas, API, Eloquent, transacciones y excepciones
/frontend/AGENTS.md  → React, features, peticiones, formularios y experiencia
```

Este archivo es aplicable a todo el repositorio. Dentro de `frontend/` y `backend/` consultar además sus respectivos `AGENTS.md` **cuando existan**. Las instrucciones locales complementan las generales; no pueden alterar los requisitos oficiales ni las decisiones de negocio aprobadas. Si todavía no existen, no inventar sus contenidos.

## 6. Arquitectura general y límites entre capas

```text
React Page / Component
        ↓
Frontend Service / Axios
        ↓  HTTP(S) / JSON / cookies de sesión
Laravel Route → Middleware → Controller → Service → Repository → Eloquent Model
                                                              ↓
                                                          PostgreSQL
```

- **Frontend:** presentación, navegación, validaciones de experiencia, carga, errores y formularios. No decidir reglas críticas ni confiar en la ocultación de botones como autorización.
- **Route/Middleware:** enrutar; comprobar autenticación, autorización y protecciones transversales.
- **Controller:** adaptar HTTP, validar la entrada, llamar al Service y devolver una respuesta. No consultar Eloquent directamente desde el Controller para operaciones de negocio.
- **Service:** aplicar reglas de negocio, comprobar relaciones, orquestar repositorios y controlar operaciones atómicas.
- **Repository:** encapsular el acceso a datos mediante Eloquent y consultas parametrizadas; no tomar decisiones clínicas ni de autorización de negocio.
- **Model:** representar relaciones y restricciones de persistencia sin concentrar procesos complejos.
- **PostgreSQL:** última barrera de unicidad, integridad referencial y consistencia.

La separación de capas es una decisión del proyecto. Adaptarla a la estructura real de Laravel sin romper `artisan`, Composer, rutas, configuración, migraciones ni el autoload. No copiar estructuras incompatibles con el framework. Los detalles de integración se precisarán en `backend/AGENTS.md`.

## 7. Organización funcional y catálogo de casos de uso

Mantener **numeración continua** y correspondencia entre casos, módulos, actores y reglas:

| Módulo | Casos |
| --- | --- |
| Usuarios y autenticación | `CU-01` a `CU-06` |
| Pacientes y perfil personal | `CU-07` y `CU-08` |
| Médicos y especialidades | `CU-09` y `CU-10` |
| Consultorios y horarios | `CU-11` y `CU-12` |
| Búsqueda y reserva de citas | `CU-13` y `CU-14` |
| Reprogramación y cancelación | `CU-15` y `CU-16` |
| Asistencia y estados | `CU-17` a `CU-19` |
| Agenda e historial | `CU-20` y `CU-21` |
| Historia clínica y consultas | `CU-22` y `CU-23` |
| Laboratorio digital | `CU-24` a `CU-27` |
| Recordatorios internos | `CU-28` |
| Reportes administrativos | `CU-29` |
| Bitácora del Sistema | `CU-30` |

No crear automáticamente una entidad, tabla, carpeta frontend o endpoint por cada CU. Organizar por responsabilidad funcional y necesidad real. La versión vigente de cada caso de uso determina sus flujos exactos.

## 8. Roles, permisos y relaciones de identidad

- **Administrador:** gestiona cuentas del personal, médicos, especialidades, consultorios, horarios, reportes y bitácora. En laboratorio puede cargar/corregir documentos y sus asociaciones, **no** interpretar ni alterar contenido clínico.
- **Recepcionista:** registra pacientes; verifica identidad y emite códigos de activación; programa, reprograma y cancela citas; confirma llegadas e inasistencias.
- **Médico:** consulta su agenda y la historia autorizada de pacientes que le corresponden; registra consultas, solicita estudios, revisa y autoriza resultados. **Solo el Médico responsable** puede marcar sus citas como `Atendida`.
- **Paciente:** registra/activa su propia cuenta, actualiza su perfil, reserva únicamente sus propias citas y consulta su información autorizada. **No** reprograma ni cancela sus citas.

Los cuatro roles y sus permisos son **fijos**. El Administrador asigna roles existentes al personal, pero no crea roles ni configura permisos dinámicos. Aplicar autorización **en el servidor**, incluyendo relación con el paciente, cita, médico y documento solicitado.

Diferenciar cuenta de acceso, ficha de Paciente y registro profesional del Médico. Sus estados pueden estar relacionados, pero **no son el mismo estado**.

## 9. Autenticación, credenciales y vinculación

- Los cuatro roles inician sesión **solo con correo electrónico y contraseña**; no usar nombre de usuario como alternativa.
- Contraseñas con longitud mínima de **12 caracteres**, almacenadas con hash seguro de Laravel; nunca registrar ni devolver contraseñas ni hashes.
- **Tres** contraseñas incorrectas consecutivas producen bloqueo de **diez minutos**. Los intentos durante el bloqueo no lo prolongan; al expirar o al autenticarse correctamente, el contador se reinicia.
- Distinguir cuenta `pendiente de verificación`, `activa`, `desactivada` y la restricción temporal por bloqueo. Recuperar contraseña no reactiva una cuenta desactivada.
- Administrador crea las cuentas del personal. El trabajador recibe invitación temporal para verificar su correo y establecer su contraseña; si usa una credencial temporal, debe cambiarla obligatoriamente.
- Un Paciente puede tener ficha y citas sin cuenta web; admite **como máximo una cuenta vinculada**. El CI localiza y evita duplicaciones, pero **no prueba por sí solo la identidad**.
- **Paciente nuevo:** registro web y verificación mediante código de correo enviado por Brevo.
- **Paciente previamente registrado:** Recepción verifica presencialmente su identidad y entrega un código; el Paciente introduce **CI + código de Recepción + correo + contraseña**; después verifica un **segundo código enviado por Brevo**. Solo entonces se activa y vincula la cuenta, sin duplicar la ficha ni perder su historial.
- Código de Recepción: alfanumérico aleatorio de 12 caracteres; **24 horas**, **cinco intentos**, un solo uso; reemplazarlo invalida el anterior.
- Código de correo: numérico de seis dígitos; **diez minutos**, **cinco intentos**, un solo uso; reenvío con **60 segundos de espera** y **máximo cinco envíos por hora** por proceso y dirección, más límites por origen.
- Registro pendiente de Paciente nuevo: **24 horas**; reintentos sobre el mismo proceso sin crear fichas definitivas duplicadas. El reenvío no amplía su vigencia.
- Invitación inicial al personal: **24 horas**, un solo uso; una nueva emisión invalida la anterior.
- Recuperación de contraseña: **enlace temporal de 60 minutos**, un solo uso; una nueva emisión invalida la anterior. Los códigos de verificación de correo **no** sustituyen ese enlace.
- Cambio y recuperación de contraseña invalidan sesiones anteriores; recuperar exige volver a iniciar sesión. Desactivar una cuenta también debe impedir usar sesiones anteriores.
- El consumo de códigos y la vinculación deben protegerse frente a solicitudes simultáneas y estados parciales.

Nunca confirmar públicamente si un CI o correo pertenece a una cuenta o a una ficha médica. Los mensajes de registro y recuperación no deben facilitar enumeración de usuarios. **Brevo solo envía correos transaccionales de seguridad**.

## 10. Gestión de citas, consultorios y estados

- Atención exclusivamente **presencial**, en una sede y con consultorio identificado.
- Cada Médico tiene un consultorio fijo asignado; pueden compartir un mismo consultorio en **horarios no superpuestos**. Solo el Administrador gestiona las asignaciones.
- Duración inicial de cita: **15 minutos**, configurable. Ninguna cita puede quedar fuera del horario habilitado ni en fecha pasada.
- Toda reserva o reprogramación debe verificar en el **servidor** paciente, médico activo, especialidad, horario, duración y disponibilidad simultánea de **Médico y consultorio**. Proteger también la concurrencia de peticiones.
- Paciente únicamente reserva para sí mismo; Recepción programa para un Paciente registrado. Solo Recepción reprograma y cancela.
- Los cinco estados de cita son: **Programada, Confirmada, Atendida, Cancelada e Inasistencia**. No agregar otros.
- Alta inicial → `Programada`. Recepción verifica llegada: `Programada → Confirmada`.
- Médico responsable después de atender: `Programada → Atendida` o `Confirmada → Atendida`.
- Recepción cancela antes de la atención: `Programada/Confirmada → Cancelada`.
- Recepción registra ausencia comprobada tras terminar el turno: `Programada → Inasistencia`.
- Reprogramar `Programada` conserva `Programada`; reprogramar `Confirmada` vuelve a `Programada`. No es un sexto estado.
- `Atendida`, `Cancelada` e `Inasistencia` son finales para los flujos ordinarios. No inventar correcciones excepcionales.
- La cancelación libera el intervalo, pero no elimina la cita. Preservar ubicación, duración e historia de las atenciones anteriores.
- Paciente inactivo: no recibe nuevas citas, tampoco por Recepción; con cuenta activa puede consultar su historial autorizado. Médico profesionalmente inactivo: no recibe nuevas citas ni reprogramaciones a su favor; con cuenta activa puede completar operaciones previamente asignadas y autorizadas. **Desactivar su cuenta** sí impide el acceso.

## 11. Información clínica y laboratorio

- Cada cita admite **como máximo una consulta médica**; registrarla no marca automáticamente la cita como `Atendida`.
- La consulta corresponde al Médico responsable y puede incluir **varias solicitudes de laboratorio**.
- Respetar el acceso clínico por relación asistencial; Recepción no modifica diagnósticos y el Administrador no realiza actos clínicos.
- Resultados emitidos externamente: Administrador los incorpora como archivos protegidos; Médico solicitante revisa correspondencia y decide publicar o devolver para corrección.
- Estados internos del laboratorio: **Pendiente, En revisión, Disponible**. No confundirlos con los cinco estados de cita.
- Resultado correcto → Médico autoriza publicación **directamente**; resultado incorrecto → devolución motivada, corrección documental por Administrador y **nueva revisión médica**.
- El Paciente ve `Pendiente` hasta la publicación y solo puede consultar/descargar su documento **vigente y autorizado**. Nunca exponer rutas públicas de archivos clínicos.
- Conservar trazabilidad de correcciones y versiones de documentos. No sobrescribir silenciosamente información clínica relevante.

## 12. Notificaciones, reportes y bitácora

- Recordatorios de citas: **automáticos, persistentes e internos**, aunque el Paciente esté desconectado; no enviar por correo, SMS, WhatsApp ni push externo. Ajustar o invalidar recordatorios tras reprogramación, cancelación o atención.
- Reportes: cubrir los mínimos de `RF13` por fecha, Médico, especialidad, estado, pacientes y período, según la documentación vigente. Exportación CSV/PDF es opcional cuando así figure en el alcance.
- Bitácora: registrar automáticamente eventos relevantes de autenticación, cuentas, pacientes, médicos, citas, consultas y laboratorio. **CU-30** permite **solo al Administrador** consultar con filtros y paginación.
- La bitácora es de **solo lectura** en la aplicación: no editar ni eliminar eventos. No registrar contraseñas, hashes, tokens, códigos de activación, documentos completos ni detalles clínicos innecesarios.
- Una bitácora general **no concede al Administrador permisos adicionales** para modificar diagnósticos o publicar resultados.

## 13. Validación, errores e integridad

- Realizar validaciones de usabilidad en React, pero validar **de nuevo en Laravel** los datos, estados, permisos y reglas.
- Mantener **manejo centralizado de excepciones** y respuestas HTTP/JSON coherentes. Distinguir datos inválidos (`422`), no autenticado (`401`), prohibido (`403`), no encontrado (`404`), conflicto (`409`), demasiadas solicitudes (`429`) y error inesperado (`500`) cuando corresponda.
- No mostrar trazas, SQL, configuraciones, secretos, errores internos ni datos clínicos a clientes no autorizados.
- Usar transacciones y restricciones PostgreSQL para operaciones atómicas: reservas, reprogramaciones, vinculación de cuentas, consultas únicas y autorización/versionado documental cuando corresponda.
- Evitar condiciones de carrera: una simple comprobación previa no basta cuando dos peticiones pueden confirmar el mismo horario o activar la misma cuenta.
- No usar SQL concatenado con entradas del usuario; preferir consultas parametrizadas y Eloquent.
- Si una operación falla, no informar éxito ni dejar datos relacionados incongruentes. Registrar auditoría de resultados reales y no prometer éxito antes de confirmar una transacción.

Los detalles de clases de excepción, manejo de transacciones y contratos API se desarrollarán en `backend/AGENTS.md`.

## 14. Estructura del repositorio: igual organización que CADEFAR

**Decisión expresa:** utilizar la misma **organización de carpetas** del proyecto CADEFAR: raíz con `frontend/`, `backend/` y documentación, frontend feature-based y backend en cuatro capas dentro de `src/`. Adaptar **el contenido y el stack**, pero no renombrar sus carpetas para seguir convenciones distintas. La estructura mostrada es objetivo de desarrollo; comprobar qué existe realmente antes de crear archivos.

```text
/
├── AGENTS.md
├── frontend/
│   ├── AGENTS.md
│   ├── public/
│   ├── src/
│   │   ├── api/
│   │   ├── app/
│   │   ├── components/
│   │   ├── layouts/
│   │   ├── features/         # Módulos de Clínica Vida Salud
│   │   ├── hooks/
│   │   ├── routes/
│   │   ├── constants/
│   │   ├── utils/
│   │   ├── styles/
│   │   ├── App.jsx
│   │   └── main.jsx
│   ├── package.json
│   └── vite.config.js
├── backend/
│   ├── AGENTS.md
│   ├── src/
│   │   ├── presentation/
│   │   │   ├── controllers/
│   │   │   ├── routes/
│   │   │   ├── middlewares/
│   │   │   └── dtos/
│   │   ├── business/
│   │   │   ├── services/
│   │   │   └── validators/
│   │   ├── data/
│   │   │   ├── models/
│   │   │   ├── repositories/
│   │   │   └── database/
│   │   │       ├── config/
│   │   │       ├── migrations/
│   │   │       └── seeders/
│   │   └── shared/
│   │       ├── errors/
│   │       ├── utils/
│   │       └── constants/
│   ├── app/                  # Puentes del framework Laravel
│   ├── bootstrap/
│   ├── config/
│   ├── routes/
│   ├── database/             # Infraestructura Laravel sin duplicar migraciones
│   ├── public/
│   ├── resources/
│   ├── storage/
│   ├── tests/
│   ├── composer.json
│   └── artisan
└── docs/
    └── ai/
        ├── contexto-maestro.md
        ├── requerimientos.md
        ├── reglas-negocio.md
        ├── casos-de-us.md
        ├── diagrama-entidad-relacion.md
        ├── diagrama-clases.md
        └── analisis-clases.md
```

- El frontend debe seguir **Feature-Based + Component-Based**, como CADEFAR; sustituir sus features farmacéuticas por las funciones de Clínica Vida Salud. Mantener carpetas `api/`, `app/`, `components/`, `layouts/`, `features/`, `hooks/`, `routes/`, `constants/`, `utils/` y `styles/`.
- El backend debe conservar **exactamente** las cuatro carpetas `src/presentation/`, `src/business/`, `src/data/` y `src/shared/` y sus subcarpetas de CADEFAR, con esos nombres en minúsculas. No sustituirlas por `Presentation`, `Business`, `Data`, `Shared`, ni redistribuir los servicios por otra estructura sin permiso.
- Laravel exige archivos de arranque, configuración y herramientas propias; se conservarán únicamente como **infraestructura del framework**. Los controladores, servicios, repositorios, modelos y migraciones de negocio seguirán la organización de `src/` del ejemplo; el `backend/AGENTS.md` establece cómo registrarlos con Laravel/Composer/Artisan.
- No duplicar la definición de rutas, modelos, migraciones, servicios o repositorios entre `src/` y las carpetas de infraestructura de Laravel.
- El frontend no tendrá acceso directo a PostgreSQL. El flujo seguirá `React → Service/Axios → Route → Middleware → Controller → Service → Repository → Eloquent → PostgreSQL`.
- Antes de crear, mover o renombrar carpetas, comprobar la estructura real y pedir confirmación si una exigencia del framework impide seguir el patrón acordado.
- No crear todos los módulos o archivos vacíos anticipadamente: incorporar cada elemento cuando sea necesario para un caso de uso.


## 15. Protocolo de trabajo para agentes

Para cada tarea de desarrollo:

1. Leer la solicitud y delimitar exactamente su alcance.
2. Inspeccionar archivos, estructura, convenciones y `AGENTS.md` aplicables.
3. Consultar los RF, RN, CU y decisiones relacionadas **solo en la extensión necesaria**.
4. Identificar actor autorizado, precondiciones, flujo normal, errores, restricciones y efectos históricos.
5. Señalar contradicciones o decisiones realmente faltantes antes de inventar una solución de negocio.
6. Proponer la solución más sencilla que mantenga las capas y los contratos existentes.
7. Implementar el cambio mínimo, incluidos tests y migraciones pertinentes.
8. Verificar permisos, errores, integridad, concurrencia, persistencia y datos sensibles.
9. Registrar progresivamente en `docs/ai/` el **análisis de clases de los CU terminados**, el **DER si cambia la persistencia**, y el **diagrama global de clases al cerrar el módulo**, conforme a la sección 4.
10. Ejecutar las verificaciones posibles; si alguna no puede ejecutarse, indicarlo claramente.
11. Informar qué archivos se cambiaron, qué se verificó, qué documentación se sincronizó y qué queda pendiente.

No transformar una tarea pequeña en un refactor general. No modificar otros módulos solo para uniformar estilos o aplicar patrones nuevos.

## 16. Cambios en datos y documentación

- Usar **migraciones de Laravel** para cambios de esquema; no editar estructuras productivas manualmente ni utilizar recreación destructiva de tablas.
- No borrar físicamente pacientes, médicos o citas con historial, ni resultados/versiones que deban conservarse.
- Mantener consistencia entre **migración → modelo → repositorio → servicio → API → frontend → pruebas** cuando la modificación lo exija.
- Si una implementación contradice el documento vigente, **identificarlo**. No modificar RF/RN oficiales ni cambiar decisiones de negocio por cuenta propia para ajustarlas al código.
- Mantener `docs/ai/` como **documentación de trabajo acumulativa**: análisis de clases por CU o grupos de CU, diagrama global de clases al finalizar módulos y DER ante cambios reales de persistencia. Esta sincronización es parte del cierre de las tareas de desarrollo aprobadas.
- No añadir roles, estados de cita o laboratorio, modalidades de atención ni permisos que no hayan sido aprobados.

## 17. Seguridad y secretos

- Nunca incluir en commits ni respuestas: `.env`, credenciales, `APP_KEY`, contraseñas, claves de Brevo, tokens, secretos de sesión, respaldos sensibles o información real de pacientes.
- Mantener secretos en variables de entorno del servidor; `VITE_*` es **público en el navegador**, nunca debe contener secretos.
- Aplicar protección CSRF, cookies seguras, autorización en servidor, límites de intentos y validación de archivos según la arquitectura implementada.
- No usar datos clínicos reales en ejemplos, fixtures o pruebas; utilizar pacientes y documentos **ficticios**.
- No almacenar tokens de sesión ni contraseñas en `localStorage` o `sessionStorage`.
- Evitar registrar cuerpos completos de peticiones que puedan contener información clínica, credenciales o códigos.

## 18. Git, dependencias y despliegue

- No hacer `commit`, `push`, crear ramas, cambiar remotos ni desplegar automáticamente, salvo solicitud expresa.
- No ejecutar comandos destructivos ni sobrescribir cambios locales sin inspección y autorización.
- Conservar `.gitignore` y no versionar archivos temporales, secretos ni resultados clínicos de pruebas.
- Usar dependencias necesarias y compatibles; consultar primero las instaladas. Frontend: gestor establecido por su `package.json`/lockfile; backend: Composer.
- No afirmar que el proyecto está desplegado, probado o funcional si no se verificó.

## 19. Comprobaciones antes de dar una tarea por terminada

- ¿Cumple el examen y los RF/RN aplicables?
- ¿Respeta el caso de uso y las decisiones más recientes?
- ¿Usa únicamente los cuatro roles y sus permisos?
- ¿Respeta la arquitectura de capas y no duplica lógica crítica?
- ¿Valida en el servidor y maneja correctamente respuestas/errores?
- ¿Conserva la confidencialidad de las fichas, consultas y resultados?
- ¿Evita duplicaciones, superposiciones y conflictos simultáneos?
- ¿Conserva historial, versiones y eventos de auditoría necesarios?
- ¿No modifica archivos ajenos a la tarea ni introduce dependencias gratuitas?
- ¿Se ejecutaron las pruebas y verificaciones pertinentes?
- ¿Se actualizó `analisis-clases.md` para los CU finalizados y `diagrama-clases.md` si se completó un módulo?
- ¿El `diagrama-entidad-relacion.md` refleja las migraciones y relaciones implementadas, cuando corresponda?
- ¿Se informó qué documentos `docs/ai/` faltan o aún están pendientes, sin afirmar avances inexistentes?

Si alguna respuesta es negativa o desconocida, explicarlo y no dar por comprobado lo que no se verificó.

## 20. Comunicación y criterio final

- Responder en **español**, salvo que el usuario solicite otro idioma.
- Resumir los cambios relevantes, los archivos afectados, las pruebas ejecutadas, la sincronización de `docs/ai/` y las limitaciones.
- Explicar brevemente **qué hace cada capa, por qué se usa y cómo se relaciona con las demás** cuando el usuario esté aprendiendo.
- Diferenciar siempre entre **exigencia del examen**, **decisión aprobada del proyecto** y **recomendación técnica no aprobada**.
- No presentar suposiciones como decisiones definitivas ni sustituir silenciosamente una regla por una alternativa técnica.

**Objetivo:** construir un sistema clínico claro, seguro, verificable y mantenible, que cumpla primero el examen y respete todas las decisiones aprobadas, con la complejidad estrictamente necesaria.
