# AGENTS.md — Backend de Clínica Vida Salud

Complementa y **nunca contradice** `/AGENTS.md`, que es obligatorio. **Versión compacta (2026-10-31):** mismas reglas, prosa reducida. El detalle funcional vive en `docs/ai/`; el detalle técnico de módulos ya implementados (CU-28 F1–F3, laboratorio, gestión de citas) está en `README.md` — localizarlo con `grep`, nunca leerlo completo.

## 1. Stack y límites

```text
PHP · Laravel · Eloquent · PostgreSQL (PDO/pdo_pgsql) · Composer · Artisan · API HTTP/JSON
Sesiones Laravel; Sanctum stateful si la topología SPA lo permite · Brevo solo seguridad de cuentas
Frontend separado: React + Vite + JavaScript
```

- Prohibido introducir Node.js/Express/Sequelize, MySQL, Zod, JWT propio o `cookie-parser` en el backend.
- No usar Blade como interfaz funcional ni añadir dependencias/**migraciones** por comodidad sin autorización (ej.: `minishlink/web-push` no está instalado; no instalarlo sin pedirlo).
- Verificar versiones reales (`composer.json`, `bootstrap/app.php`) antes de escribir código dependiente de versión.

## 2. Flujo obligatorio por capas

**Pagos — ampliación aprobada 2026-11-05:** consultar `../docs/ai/pasarela-pagos.md` antes de CU-31–CU-34 o ampliación monetaria CU-14–CU-16. Solo Stripe test/PayPal Sandbox, BOB interno/USD externo, precio individual Bs 7,00–7.000,00 y tasa exacta 7:1 HALF_UP. Aprobación documental no autoriza código, dependencias, migraciones ni operaciones externas de fases siguientes. No extrapolar permisos administrativos originales al pago de citas ajenas ni añadir `Vencida` como estado persistido/asistencial.

```text
Route → Middleware → Controller (+FormRequest) → Service → Repository → Eloquent Model → PostgreSQL
```

1. Rutas: solo URL, método, controller y middlewares. Sin lógica ni consultas.
2. Middlewares: sesión, rol, cuenta habilitada, CSRF/origen, throttle. No sustituyen reglas de un CU.
3. Controllers: HTTP, entrada validada, delegación y respuesta. Sin reglas de negocio ni transacciones.
4. Services: reglas del CU, permiso sobre el recurso, transiciones, transacciones, excepciones de negocio.
5. Repositories: consultas y escrituras Eloquent, locks, paginación. Sin decisiones de negocio.
6. Models: tabla, PK, relaciones, casts, `fillable/guarded`, `hidden`. Sin flujos completos.
7. PostgreSQL: unicidad, FK, CHECK, exclusión y concurrencia como última barrera.

No saltar capas en operaciones de negocio ni crear abstracciones genéricas (`BaseRepository`) sin necesidad real.

## 3. Estructura y namespaces

```text
src/presentation/{controllers,routes,middlewares,dtos}
src/business/{services,validators}
src/data/{models,repositories,database/{config,migrations,seeders}}
src/shared/{errors,utils,constants}
```

- Carpetas en minúsculas y nombres de CADEFAR; no sustituirlas por `Presentation/Business/Data/Shared`.
- Módulos existentes dentro de `controllers/`, `dtos/`, `services/` y `repositories/`: `usuarios`, `pacientes`, `medicos`, `consultorios`, `horarios`, `citas`, `agenda`, `historia_clinica`, `laboratorio`, `recordatorios`, `bitacora`. Crear carpeta solo cuando tenga archivos.
- Cada clase se define **una vez** en su módulo dueño (p. ej. `CuentaRequest`, `ClaveRequest`, `RespuestaError` en `dtos/usuarios/`); los demás módulos importan su namespace. Sin alias de compatibilidad.
- `validators/` es plano, sin subcarpetas de módulo.
- Autoload PSR-4 `"Clinica\\": "src/"` con mayúsculas exactas: `Clinica\business\services\medicos\MedicoService`. Ejecutar `composer dump-autoload` tras altas o movimientos.
- `app/`, `bootstrap/`, `config/`, `routes/`, `database/`, `public/`, `resources/`, `storage/`, `tests/` son infraestructura del framework: puentes mínimos, sin duplicar controllers, services, repositories, models, rutas ni migraciones.
- `config/auth.php` debe apuntar al modelo real de usuario de `src/data/models/`.

## 4. Rutas y middlewares

- Los archivos de rutas del negocio viven en `src/presentation/routes/` y se registran explícitamente desde `routes/api.php` (sin duplicar endpoints).
- Middlewares propios en `src/presentation/middlewares/`, registrados con el mecanismo de la versión instalada.
- Identificar públicos frente a autenticados; aplicar `auth:sanctum`, cuenta habilitada, sesión normal, rol y `throttle` según corresponda. No inventar alias no registrados.
- Diseñar endpoints por caso de uso y necesidad real de React, no por tabla ni por clase.

## 5. Validación, DTOs y Form Requests

| Responsabilidad | Lugar |
| --- | --- |
| Tipo, formato, obligatoriedad, rangos | FormRequest / Validator |
| Acceso al endpoint y autorización de la petición | `authorize()`, policies, middleware |
| Existencia, pertenencia, estados, reglas clínicas | **Service** |
| Unicidad, FK, exclusión, concurrencia | **PostgreSQL** |
| Estructuras de respuesta | `JsonResource` / DTO de presentación |

- Usar `validated()` o estructura explícita; nunca persistir `request->all()`.
- Validar siempre en servidor aunque React valide; el cliente no fija `rol`, `estado`, hashes ni identificadores.
- Rechazar filtros y cuerpos no previstos en endpoints de solo lectura.
- Formato de CI/correo no prueba identidad.

## 6. Services

- Implementan flujo principal y alternativos: precondiciones, permiso de recurso, transiciones, efectos y transacciones.
- No dependen de `Request`/`Response` ni de estructuras de React; reciben datos validados y un actor claro.
- Abren transacciones para operaciones atómicas y lanzan `AppError` reconocible ante conflictos esperados.
- Sin dependencias circulares entre Services; reutilizar Services existentes antes de duplicar lógica.

## 7. Repositories

- Encapsulan SQL/Eloquent: búsquedas, filtros, paginación, agregados, escrituras, `lockForUpdate`, keyset.
- **No** deciden si una reserva es válida ni permisos: solo devuelven datos o persisten.
- Consultas parametrizadas siempre; sin concatenar entrada del cliente.

## 8. Models

- Relaciones Eloquent, casts, PK no convencional y `guarded` restrictivo.
- No ocultar flujos de negocio en observers, accessors o mutators.
- Proteger serialización: `hidden` no basta, el Resource decide qué se expone.

## 9. Migraciones, seeders y PSR-4

- Migraciones propias en `src/data/database/migrations/`, registradas con `loadMigrationsFrom(...)`; al generarlas usar `--path=src/data/database/migrations`. Sin duplicados en `backend/database/migrations/`.
- Seeders propios en `src/data/database/seeders/`; `DatabaseSeeder` solo como puente.
- IDs generados por PostgreSQL (identidad/secuencia); nunca `MAX(id)+1`.
- PostgreSQL, no MySQL: sin `AUTO_INCREMENT` ni convenciones Sequelize.
- Sin borrado físico de pacientes, médicos, citas ni registros clínicos con historial.
- Seeds solo con catálogos y datos ficticios; nunca contraseñas de producción ni pacientes reales.
- Nunca `migrate:fresh`, `db:wipe` ni `drop` sobre entornos persistentes sin autorización.

## 10. Transacciones y concurrencia

```text
Todos los pasos válidos → COMMIT · Cualquier paso necesario falla → ROLLBACK
```

- **Citas:** proteger intervalos `[inicio, fin)` del Médico y del consultorio frente a reservas y reprogramaciones simultáneas (locks y/o restricción de exclusión). Una consulta de disponibilidad no reserva: revalidar y escribir de forma atómica. No alterar duración ni ubicación históricas.
- **Registro/activación:** reservar el proceso pendiente, consumir ambos códigos y vincular en una sola transacción; sin duplicar cuentas ni fichas. Si la verificación falla, dejar estado recuperable y sin acceso.
- **Clínica:** evitar dos consultas para la misma cita; mantener coherentes solicitud, versión documental, revisión y estado. No publicar una versión sustituida.
- **Outbox de recordatorios:** aviso, auditoría y despachos iniciales se confirman juntos; los Pendientes obsoletos se descartan sin reabrir terminales. La generación nunca modifica Cita, consulta ni preferencias.
- **Efectos externos** (correo, push) nunca forman parte del rollback SQL: despachar después del commit (`afterCommit` u outbox), registrar fallos y permitir reintentos sin duplicar.

## 11. Errores centralizados

- Excepción de dominio propia (`AppError` en `src/shared/errors/`) con **código estable** y mensaje seguro; la traducción a HTTP se resuelve centralmente en `bootstrap/app.php`.
- Mantener la semántica de FormRequest, autenticación, autorización y `ModelNotFoundException`, normalizando la salida.
- Sin `try/catch` por método para reenviar la misma excepción; capturar solo con recuperación o contexto.

| Situación | HTTP |
| --- | --- |
| Petición mal formada | 400 |
| Sin sesión / credenciales inválidas | 401 |
| Autenticado sin permiso | 403 |
| Recurso inexistente o no accesible | 404 |
| Conflicto (horario ocupado, unicidad, transición, push obsoleto) | 409 |
| Validación de campos | 422 |
| CSRF ausente o caducado | 419 |
| Límite o bloqueo por intentos | 429 |
| Error inesperado (o push no configurado) | 500 / 503 |

Contrato de error: `{"success":false,"error":{"code","message","details?"}}`. Éxito: `data` (+ `meta` de paginación). Sin trazas, SQL, rutas internas, tokens ni datos clínicos. `204` sin cuerpo. No convertir un conflicto esperado en `500`. Códigos propios vigentes incluyen `PUSH_NO_CONFIGURADO` (503) y `PUSH_SUSCRIPCION_OBSOLETA` (409).

## 12. Autenticación SPA, estados y contraseñas

- Login solo con correo y contraseña; `/api/auth/me` devuelve usuario público y rol sin hash ni datos clínicos.
- Cookies de sesión protegidas, CSRF y validación de origen; sin tokens en `localStorage`/`sessionStorage`.
- Distinguir: pendiente de verificación (solo activación), activa, desactivada (sin login ni sesiones previas) y bloqueo temporal (atributo de autenticación, no desactivación).
- Contraseña mínima 12, hash con `Hash`; 3 fallos consecutivos → 10 minutos; los intentos durante el bloqueo no lo extienden; éxito o expiración reinician el contador; rate limit por IP.
- Logout invalida sesión del servidor y regenera CSRF. Cambio o recuperación de contraseña y desactivación invalidan sesiones anteriores y exigen nuevo login.
- Responder genéricamente para no revelar existencia de correo o cuenta.
- Primer Administrador: instalación local idempotente, correo único, hash, sin valores por defecto versionados; no es alta general de Administradores.

## 13. Registro, códigos y Brevo

- Paciente nuevo: CI obligatorio, código de correo de 6 dígitos, registro pendiente 24 h, sin duplicar fichas.
- Paciente ya registrado: Recepción verifica presencialmente y emite código de 12 alfanuméricos (24 h, 5 intentos, un uso); el Paciente aporta CI + código + correo + contraseña y verifica el código de correo (10 min, 5 intentos); el vínculo es atómico y consume ambos códigos.
- Reenvío: 60 s entre envíos y máximo 5 por hora por proceso y correo, más límites por origen; nunca amplía la vigencia principal.
- Códigos y enlaces guardados con hash/resumen protegido; jamás en claro, en bitácora o en logs.
- CI o correo por sí solos no prueban identidad ni permiten enumerar cuentas.
- Brevo **solo** para seguridad de cuentas; nunca avisos de cita, historia ni resultados.

## 14. Citas, roles y estados

| Desde | Hacia | Responsable | Condición |
| --- | --- | --- | --- |
| Nueva | Programada | Admin, Recepción o Paciente (para sí) | Reserva válida |
| Programada | Confirmada | Admin o Recepción | Llegada real el día de atención |
| Programada/Confirmada | Atendida | Admin o Médico responsable | Atención realizada, ejecutor real |
| Programada/Confirmada | Cancelada | Admin o Recepción | Antes de la atención |
| Programada | Inasistencia | Admin o Recepción | Intervalo finalizado y ausencia comprobada |
| Confirmada | Programada | Admin o Recepción | Reprogramación válida |

- `Atendida`, `Cancelada` e `Inasistencia` son finales para flujos ordinarios.
- La cita guarda código único, Paciente, especialidad, Médico, fecha, duración efectiva, modalidad Presencial, consultorio y estado; conserva duración y ubicación históricas.
- Comprobar en servidor: fecha válida, horario habilitado, Médico activo y de la especialidad, y ausencia de solapamiento por Médico y por consultorio.
- Recepción y Paciente no marcan `Atendida`. El Paciente nunca reprograma ni cancela.
- Cancelar no elimina el registro, libera el intervalo y retira el aviso vigente.
- Los cambios de consultorio u horario no deben invalidar ni reprogramar citas futuras en silencio: identificar conflictos.

## 15. Historia clínica y laboratorio

- Máximo una consulta por cita (restricción de unicidad). Registrar consulta no marca `Atendida`.
- CU-22: solo consulta (Médico con relación asistencial, Administrador, Paciente el propio). CU-23: Médico responsable o Administrador registran o corrigen antecedentes con versiones, fechas y ejecutor real; Recepción y Paciente no.
- No crear consultas ficticias ni registrar atención de citas `Cancelada`/`Inasistencia`.
- Laboratorio: `Pendiente → En revisión → Disponible`; la devolución con motivo permanece `En revisión`. Publicar directamente si el documento es correcto; corregir y volver a revisar si no.
- El Paciente ve `Pendiente` hasta la publicación y solo descarga su versión vigente publicada; verificar autorización **también al entregar bytes**.
- Archivos en almacenamiento privado, fuera de rutas públicas; validar tipo real, tamaño y asociación; nunca confiar en MIME/extensión declarados.

## 16. Recordatorios internos y Web Push (CU-28)

- **Ampliación aprobada 2026-11-07:** manual exclusivo Admin para la próxima cita futura Programada/Confirmada, previa confirmación y comparación de revisión en servidor. Un manual por cita/programación compartido entre Admin, separado del automático mediante origen; UUID durable, aviso/auditoría/outbox atómicos y dispatcher/transporte existentes. Se permite >24h solo al manual; preferencias/opt-in/corte de inicio intactos. 000050 solo autorizada en esquema aislado; nunca editar migraciones anteriores. Las menciones históricas inferiores a fases pendientes no describen la presencia actual del transporte/dispatcher/SW; tampoco acreditan configuración ni entrega real.
- Generación automática por scheduler cada minuto (`recordatorios:generar`, lote 1–1000), sin sesión de Paciente ni pestaña abierta; `withoutOverlapping` es apoyo, la unicidad y las transacciones son la protección real.
- Ventana `inicio_at − 24 h ≤ ahora < fin_at`, estados `Programada`/`Confirmada`, preferencia habilitada (ausencia de fila = habilitada, sin crear fila). Ventana en UTC con microsegundos, sin redondeos.
- Un automático y, opcionalmente, un manual por cita y programación; la reejecución del generador es idempotente y recupera huecos automáticos sin duplicar ni descartar manuales por su origen. Reprogramar invalida los avisos anteriores; nunca se resucita un aviso invalidado.
- El aviso interno no exige cuenta web, ficha activa ni Médico activo. Web Push exige consentimiento explícito, cuenta activa vinculada, autorización vigente y `ahora < inicio_at`; canal deshabilitado o clave pública inválida no impide el aviso interno.
- Payload push genérico sin datos clínicos; suscripciones y claves en almacenamiento protegido, nunca en logs ni URLs.
- Lo devuelto/transmitido no puede retirarse con garantía; no confundir aceptación del servicio con entrega o lectura.
- Fases pendientes (transporte real, dispatcher, frontend/Service Worker) requieren autorización expresa; no instalar la librería ni generar claves privadas sin pedirlo.

## 17. Bitácora y reportes

- Registrar eventos de autenticación, cuentas, códigos, pacientes, médicos, citas, consultas (metadatos), laboratorio y recordatorios; solo el Administrador consulta, con filtros y paginación.
- Solo lectura: sin endpoints de edición o borrado. Sin contraseñas, hashes, tokens, códigos, cuerpos clínicos ni diagnósticos.
- Eventos exitosos se registran con la transacción confirmada; actor SISTEMA cuando no hay usuario.
- Reportes (CU-29): consultas agregadas en Repositories con filtros validados y paginación; no duplicar datos en tablas de reportes.

## 18. Contratos API para React

- Respuestas uniformes: `data` (+ `meta`), `201` al crear, `200` al leer, `204` sin cuerpo, `Cache-Control: no-store, private` en datos personales.
- Listados con filtros, orden y paginación validados contra lista permitida; límites razonables; sin interpolar `orderBy` del cliente.
- Distinguir `401`, `403` y `419` para orientar al frontend; documentar los cambios de contrato en `README.md`.

## 19. Pruebas

- Usar PHPUnit/Laravel Feature y Unit con base de datos aislada; **nunca** migraciones destructivas sobre datos reales.
- Una sola batería dirigida al final de la fase, con salida a archivo y solo el resumen en el chat; nada de suites globales rutinarias.
- Priorizar: roles y acceso a recursos ajenos; bloqueo de 3 intentos; códigos y vencimientos; CI/correo únicos y una cuenta por Paciente; solapamientos y reprogramación concurrente; transiciones permitidas y prohibidas; consulta única; publicación/devolución de laboratorio; generación de recordatorios y outbox; bitácora sin secretos; contratos de error y paginación.
- Simular Brevo y servicios externos; nunca enviar correos reales. La concurrencia real se verifica con PostgreSQL, no con SQLite.
- No declarar probado lo que no se ejecutó; informar fallos de entorno.

## 20. Seguridad

- Mínimo privilegio y autorización de recurso en cada consulta o descarga.
- Validar subidas, almacenar en privado y proteger contra acceso por identificador ajeno.
- Cookies HttpOnly/Secure/SameSite según HTTPS y topología; sin tokens en URLs.
- No registrar `request->all()` ni cabeceras completas; logs técnicos separados de la bitácora funcional.
- `APP_DEBUG=false` en producción; nunca exponer excepciones internas.

## 21. Cierre de tarea

Antes: inspeccionar código y pruebas afectadas, consultar RF/RN/CU y contratos ya consumidos por React, detectar decisiones ambiguas.
Durante: cambios mínimos, validación en la capa correcta, transacciones donde hay concurrencia, errores y respuestas coherentes, pruebas del comportamiento.
Después: ejecutar verificaciones, revisar roles/recursos/integridad, mantener la cadena migración→modelo→repositorio→service→API→frontend→pruebas cuando cambie la persistencia, actualizar `docs/ai/analisis-clases.md` (y DER/UML si corresponde), relevo en `docs/ai/estado-fase.md` e informar archivos, pruebas, pendientes y límites.

Regla final: **coherente con el examen, seguro con datos clínicos, por capas, verificable y con la complejidad mínima suficiente.** Ante dos soluciones válidas, elegir la más simple que cumpla estas condiciones.
