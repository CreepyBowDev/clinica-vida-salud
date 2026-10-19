# AGENTS.md — Backend de Clínica Vida Salud

Este archivo define las **instrucciones específicas para agentes de IA que trabajen en `backend/`** del proyecto **Sistema Web para la Gestión de Citas Médicas para la Clínica Vida Salud**.

**Es obligatorio aplicar también `/AGENTS.md`, ubicado en la raíz del repositorio.** Este documento complementa al archivo raíz: no modifica el examen, las decisiones funcionales aprobadas ni los permisos establecidos. Su objetivo es conservar la **arquitectura por capas, el flujo de peticiones, la separación de responsabilidades y el manejo centralizado de errores**, adaptados correctamente a PHP, Laravel, Eloquent y PostgreSQL.

> **Estado de este documento:** define la arquitectura objetivo y las convenciones para el desarrollo. La estructura mostrada es una propuesta de organización del backend, **no una afirmación de que los archivos o carpetas ya existan**. Antes de crear o cambiar archivos, inspeccionar el repositorio y la versión instalada de Laravel.

---

## 1. Prioridad del examen y documentación funcional

Las funcionalidades deben cumplir el enunciado oficial del examen, incluidos **RF01–RF13** y **RN01–RN14**, y las decisiones más recientes aprobadas para Clínica Vida Salud. Ninguna recomendación técnica justifica contradecir una obligación del examen.

Consultar, si existen, los documentos de la **raíz** del repositorio:

```text
/docs/ai/contexto-maestro.md
/docs/ai/requerimientos.md
/docs/ai/reglas-negocio.md
/docs/ai/casos-de-us.md
/docs/ai/diagrama-entidad-relacion.md
/docs/ai/diagrama-clases.md
/docs/ai/analisis-clases.md
```

- `contexto-maestro.md`: arquitectura, decisiones vigentes y progreso real.
- `requerimientos.md`: RF/RN oficiales y ampliaciones diferenciadas.
- `reglas-negocio.md`: restricciones, transiciones, permisos, plazos y validaciones.
- `casos-de-us.md`: comportamientos aprobados de `CU-01` a `CU-30`.
- `diagrama-entidad-relacion.md`: entidades, relaciones y restricciones de persistencia.
- `diagrama-clases.md`: **modelo UML global y acumulativo**, actualizado al finalizar cada módulo.
- `analisis-clases.md`: **análisis por CU**, actualizado al finalizar cada CU o pequeño grupo relacionado.

No inventar el contenido de un archivo faltante ni afirmar que ya está implementado. Consultar las secciones relevantes, no releer documentos extensos sin necesidad. Ante una diferencia entre PDF antiguo, `docs/ai/` y decisiones posteriores, aplicar la jerarquía definida en `/AGENTS.md` y señalar el conflicto cuando no pueda resolverse con evidencia.

---

## 2. Stack y límites del backend

```text
Lenguaje:            PHP
Framework:           Laravel
Persistencia ORM:    Eloquent
Base de datos:       PostgreSQL (extensión PDO/pdo_pgsql)
Dependencias PHP:    Composer
Herramientas CLI:    Artisan
API:                 HTTP(S), JSON, respuestas y códigos HTTP coherentes
Autenticación SPA:   Sesiones/cookies Laravel; Sanctum stateful si corresponde
Correo transaccional: Brevo, únicamente para seguridad de cuentas
Frontend separado:  React + Vite + JavaScript
```

- El backend es la **única fuente de verdad** de reglas de negocio, autorización, estado y persistencia.
- El frontend consume la API; jamás se conecta directamente a PostgreSQL.
- No introducir Node.js/Express, Sequelize, MySQL, Zod, JWT personalizado ni `cookie-parser` en el backend.
- No usar Blade como interfaz funcional principal: el frontend aprobado es React.
- No incorporar tecnologías o librerías alternativas sin justificación y autorización cuando implique una nueva decisión arquitectónica.
- Verificar versión de PHP, Laravel, Composer, paquetes, `composer.json` y `bootstrap/app.php` antes de escribir instrucciones dependientes de una versión específica.

---

## 3. Arquitectura obligatoria: flujo por capas

**Mantener la arquitectura conceptual existente, adaptada a Laravel:**

```text
React (página / componente)
           ↓
Frontend Service / Axios
           ↓ HTTP(S) / JSON / cookie de sesión / CSRF
Laravel Route
           ↓
Middleware (sesión, rol, controles transversales)
           ↓
Controller + FormRequest / Validator
           ↓
Service (reglas de negocio y transacciones)
           ↓
Repository (operaciones de persistencia)
           ↓
Eloquent Model
           ↓
PostgreSQL
```

**Reglas no negociables:**

1. Las rutas no contienen lógica de negocio ni consultas Eloquent.
2. Los middlewares resuelven controles transversales; no sustituyen reglas de un CU.
3. Los controllers gestionan HTTP, reciben datos validados y delegan.
4. Los services toman las decisiones de negocio y coordinan transacciones.
5. Los repositories realizan las consultas y escrituras sobre modelos Eloquent.
6. Los models representan entidades y relaciones persistidas; no concentran flujos completos de CU.
7. PostgreSQL refuerza integridad y concurrencia mediante restricciones apropiadas.
8. Las respuestas al cliente y los errores se normalizan en los límites de presentación del backend.

No saltar capas por comodidad en operaciones de negocio. No agregar capas artificiales, interfaces vacías o abstracciones genéricas que no aporten una necesidad concreta.

---

## 4. Estructura de carpetas: replicar la organización de CADEFAR

**Decisión expresa del proyecto:** conservar la estructura de carpetas de CADEFAR **dentro de `backend/src/`, incluidos sus nombres en minúsculas y sus cuatro capas**. No cambiarla silenciosamente por `src/Presentation/`, `src/Business/`, `src/Data/` o `src/Shared/`, ni reorganizarla por funcionalidades. El lenguaje y framework sí cambian: aquí se utilizan PHP, Laravel y Eloquent.

```text
backend/
├── AGENTS.md
├── src/
│   ├── presentation/
│   │   ├── controllers/
│   │   ├── routes/
│   │   ├── middlewares/
│   │   └── dtos/
│   ├── business/
│   │   ├── services/
│   │   └── validators/
│   ├── data/
│   │   ├── models/
│   │   ├── repositories/
│   │   └── database/
│   │       ├── config/
│   │       ├── migrations/
│   │       └── seeders/
│   └── shared/
│       ├── errors/
│       ├── utils/
│       └── constants/
│
├── app/                   # Solo clases puente/infraestructura exigidas por Laravel
├── bootstrap/             # Bootstrap oficial de Laravel
├── config/                # Configuración oficial de Laravel
├── routes/                # Archivos de entrada que registran las rutas de src/presentation/routes/
├── database/              # Solo infraestructura/framework que sea imprescindible; sin duplicados
├── public/                # index.php y archivos públicos necesarios del framework
├── resources/             # Recursos requeridos por Laravel, no vistas Blade principales
├── storage/
├── tests/
├── vendor/                # Generado por Composer, fuera de Git
├── .env
├── .env.example
├── composer.json
└── artisan
```

Este árbol **combina la organización específica solicitada con los archivos que Laravel realmente necesita**. No copiar archivos Node.js de CADEFAR (`app.js`, `server.js`, `package.json`, `.sequelizerc` o modelos Sequelize): sus equivalentes pertenecen al arranque y herramientas de Laravel.

### 4.1. Rutas y middlewares

- Los archivos que agrupen las rutas del negocio se organizarán en `src/presentation/routes/`, siguiendo CADEFAR.
- `backend/routes/api.php` y los mecanismos de bootstrap reales de Laravel **registrarán explícitamente** esos archivos. No declarar por duplicado los mismos endpoints en ambas ubicaciones.
- Middleware propio del proyecto: `src/presentation/middlewares/`, registrado con los mecanismos que admita la versión concreta de Laravel.
- Las rutas y middlewares conservan el mismo lugar conceptual que en CADEFAR; nunca implementan las reglas centrales de un caso de uso.

### 4.2. Controladores, validación y DTO

- Controllers: `src/presentation/controllers/`.
- Los DTO y las clases de entrada HTTP de presentación (por ejemplo, Form Requests de Laravel) pueden residir en `src/presentation/dtos/`, sin crear automáticamente una carpeta `Requests/` adicional.
- Validadores de negocio reutilizables sin dependencia de HTTP: `src/business/validators/`.
- Services: `src/business/services/`.
- Repositories: `src/data/repositories/`.
- Models Eloquent: `src/data/models/`.
- Errores y utilidades transversales: `src/shared/errors/`, `src/shared/utils/`, `src/shared/constants/`.

### 4.3. Migraciones, seeders y configuración de datos

- La **ubicación objetivo única** de migraciones propias del proyecto es `src/data/database/migrations/`; para seeders propios, `src/data/database/seeders/`, igual que en CADEFAR.
- Laravel no ejecuta automáticamente migraciones en ubicaciones personalizadas: **registrar expresamente esa ruta** con el proveedor/mecanismo adecuado para la versión instalada (por ejemplo, `loadMigrationsFrom(...)`), y comprobar que `php artisan migrate` las encuentre.
- Cuando se generen migraciones nuevas con Artisan, indicar la ruta personalizada (por ejemplo, con `--path=src/data/database/migrations` cuando la versión lo soporte), sin crear por accidente otra migración equivalente en `backend/database/migrations/`.
- Para seeders, utilizar namespaces/autoload explícitos y asegurar que `php artisan db:seed` o su invocación configurada ejecute realmente los seeders previstos. El `DatabaseSeeder` convencional de Laravel podrá mantenerse solo como **puente de arranque**, nunca como una segunda definición del catálogo de datos.
- `src/data/database/config/` se reserva para configuración/accesorios de datos realmente propios del proyecto; los archivos que Laravel **exija** en `backend/config/` deben seguir allí. No duplicar la configuración oficial en dos lugares.
- No utilizar `migrate:fresh`, eliminación destructiva o `Schema::drop` sobre datos reales sin autorización expresa.

### 4.4. Composer, namespaces e integración

- Mapear `src/` en el autoload PSR-4 de `composer.json`, por ejemplo `"Clinica\\": "src/"`, respetando **mayúsculas/minúsculas exactas** de carpetas y namespaces.
- Al conservar directorios en minúsculas como CADEFAR, una clase ubicada en `src/business/services/CitaService.php` puede utilizar el namespace `Clinica\business\services`, con nombre de clase `CitaService` en PascalCase. Verificar el autoload en Linux y ejecutar `composer dump-autoload` cuando corresponda.
- El código auxiliar que Laravel obligue a mantener en `app/`, `bootstrap/`, `routes/` y `config/` debe ser mínimo y **no reemplazará** la ubicación de la lógica propia del proyecto en `src/`.
- Si los modelos de autenticación están en `src/data/models/`, registrar el modelo correcto en `config/auth.php` y en cualquier configuración requerida; no generar un segundo modelo `User` para la misma cuenta.
- No crear una versión paralela de cada Controller, Service, Repository, Model, middleware, migración o ruta en una carpeta convencional de Laravel.
- Antes de desarrollar una función nueva, inspeccionar el repositorio real. No afirmar que una carpeta o clase exista si aún no se ha creado.

### 4.5. Prioridad de estructura

**Primero conservar el árbol y el flujo de CADEFAR. Segundo, integrar ese árbol con Laravel de forma funcional y verificable.** Si una pieza de infraestructura debe quedarse en una ubicación obligatoria del framework, documentar por qué, crear solo el puente imprescindible y **no cambiar la arquitectura ni los nombres de carpetas de `src/`**. Si una integración no es viable, explicarla antes de improvisar una reestructuración.

---

## 5. Laravel Routes

Las rutas deben:

- Declarar URL, método HTTP y Controller.
- Aplicar middlewares adecuados de sesión, acceso, tasa de solicitudes y otros controles transversales.
- Identificar los endpoints públicos frente a los que requieren autenticación.
- Ser coherentes en nombres y en su agrupación por módulo.

Ejemplo **conceptual**, sujeto a la configuración real de Sanctum y middlewares del proyecto:

```php
Route::middleware('auth:sanctum')
    ->post('/citas', [CitaController::class, 'store']);
```

**Nota:** `auth:sanctum` y cualquier middleware propio de roles requieren su configuración correspondiente. No inventar alias que no estén registrados. Las rutas no consultan Eloquent, no realizan transacciones y no determinan por sí mismas si una reserva está disponible.

No crear un endpoint por cada clase o tabla. Diseñar operaciones a partir de los casos de uso aprobados y las necesidades reales de React.

---

## 6. Middlewares y autorización transversal

Usar middleware para responsabilidades compartidas:

- Autenticación y comprobación de sesión válida.
- Rechazo de cuentas desactivadas y activaciones pendientes.
- Autorización preliminar por uno de los **cuatro roles fijos**.
- Límites de solicitudes, protección CSRF/CORS y otras protecciones aplicables.
- Identificador de correlación de peticiones cuando sea útil para observabilidad.

Separar dos niveles de autorización:

1. **Permiso de rol (middleware/policy):** ¿puede el rol ejecutar la clase de acción?
2. **Permiso sobre el recurso (policy y/o Service):** ¿esa cita, consulta, paciente, médico o archivo corresponde realmente al actor?

No confiar en un `idPaciente`, `idMedico`, `estado`, `rol` o `usuarioId` enviado por el cliente cuando deba derivarse de la sesión o comprobarse contra la base de datos.

Una cuenta desactivada no puede continuar realizando operaciones aunque conserve una cookie anterior. Comprobar el estado en las peticiones protegidas y disponer de un mecanismo efectivo de invalidación de sesiones. La inactividad de una ficha de Paciente o registro de Médico **no equivale automáticamente** a desactivar su cuenta.

---

## 7. Controllers: adaptadores HTTP

Ubicación objetivo: `src/presentation/controllers/`.

Los Controllers:

- Reciben `Request`, parámetros, query y el usuario autenticado cuando corresponda.
- Delegan validación estructural en Form Requests o validadores equivalentes.
- Llaman al Service apropiado mediante inyección de dependencias.
- Transforman el resultado en `JsonResource` u otra respuesta JSON definida.
- Usan códigos HTTP coherentes: `201` para creación, `200` para resultado, `204` para operación sin contenido, según el contrato.
- Dejan que las excepciones lleguen al manejador central cuando no tienen una recuperación concreta que hacer.

Ejemplo conceptual:

```php
public function store(ProgramarCitaRequest $request, CitaService $service)
{
    $cita = $service->programar($request->validated(), $request->user());
    return (new CitaResource($cita))->response()->setStatusCode(201);
}
```

Los Controllers **no** deben implementar comprobaciones de solapamiento, transiciones de estados, autorización clínica compleja, hashes, envío de documentos ni transacciones de negocio. No utilizar un `try/catch` genérico por método solo para volver a lanzar la misma excepción.

---

## 8. Form Requests, Validators y DTOs

Separa las validaciones por tipo:

| Responsabilidad | Lugar apropiado |
| --- | --- |
| Tipo, longitud, campo obligatorio, formato, rango básico | `FormRequest` / Validator de Laravel |
| Comprobaciones de acceso al endpoint y autorización de solicitud | FormRequest `authorize()`, policies y middleware, según corresponda |
| Existencia o pertenencia de relaciones, cambios de estado, reglas clínicas | **Service** |
| Unicidad, claves, integridad referencial y restricciones de concurrencia | **PostgreSQL** como última barrera |
| Estructuras de respuesta públicas | `JsonResource` / DTO de presentación |

- Validar en el **servidor** todo lo que React envía, aunque React ya valide formularios.
- Utilizar `validated()` o una estructura de entrada explícita; evitar persistir `request->all()` sin lista permitida.
- Los DTOs no son obligatorios para toda operación: crearlos solo si simplifican contratos o evitan acoplamiento.
- No confundir validación del formato del CI o correo con prueba de identidad real.
- Las credenciales, roles o campos de control enviados indebidamente por el frontend no deben sobreescribir valores que determine el servidor.

---

## 9. Services: centro de la lógica de negocio

Ubicación objetivo: `src/business/services/`.

Responsabilidades:

- Implementar el flujo principal y las excepciones de un caso de uso.
- Verificar precondiciones, permisos específicos del recurso y reglas vigentes.
- Consultar y coordinar repositorios o Services existentes cuando realmente sea necesario.
- Decidir las transiciones permitidas y los efectos secundarios.
- Abrir y coordinar transacciones para operaciones atómicas.
- Generar excepciones de negocio reconocibles ante conflictos esperados.
- Coordinar eventos de auditoría, correo o tareas asincrónicas según sus garantías de consistencia.

Ejemplos de preguntas que debe resolver el Service:

```text
¿El paciente está activo y puede recibir una nueva cita?
¿El médico está activo, asociado a la especialidad y autorizado?
¿Están libres el médico y el consultorio durante todo el intervalo?
¿La cita admite esta transición de estado y este actor puede ejecutarla?
¿El usuario autenticado es el médico responsable de esa atención?
¿Este paciente ya tiene una cuenta vinculada?
¿Siguen válidos los dos códigos para activar la cuenta?
¿El documento a publicar es la versión revisada por el médico solicitante?
```

Los Services **no** deben depender de clases HTTP (`Request`, `Response`) ni de estructuras internas de React. Preferir datos validados y un contexto/actor autenticado expresado de forma clara. Evitar dependencias circulares entre Services.

---

## 10. Repositories: acceso a datos

Ubicación objetivo: `src/data/repositories/`.

Funciones propias:

- Consultas de búsqueda, filtros, listado, paginación y agregados.
- Consultas Eloquent y Query Builder, incluidas relaciones y proyecciones.
- Inserciones, actualizaciones y recuperación de registros.
- Consultas con bloqueos de base de datos cuando el Service coordine una transacción.
- Persistencia de relaciones y versiones, sin alterar arbitrariamente la semántica del negocio.

**No mover reglas del CU a un Repository.** Ejemplo:

```text
Repository: obtener citas que se superponen al intervalo solicitado.
Service: decidir si se rechaza una nueva reserva por ese solapamiento.
```

No crear genéricos `BaseRepository` o interfaces para cada modelo de forma automática. Una clase de repositorio debe existir porque encapsula una responsabilidad de datos real.

---

## 11. Eloquent Models

Ubicación objetivo: `src/data/models/` (o ubicación real ya adoptada y configurada).

- Representan tablas, claves, relaciones, casts, atributos y comportamientos de persistencia sencillos.
- Utilizar relaciones Eloquent (`belongsTo`, `hasMany`, etc.) y evitar consultas manuales redundantes cuando no aporten valor.
- Usar mass assignment de forma restrictiva (`fillable`/`guarded`) y no permitir que el cliente fije identificadores, hashes, roles o estados privilegiados libremente.
- No esconder flujos completos de negocio en observers, accessors o mutators.
- Proteger serialización de secretos y relaciones sensibles; no depender solamente de `hidden` si un Resource debe controlar explícitamente campos públicos.
- No convertir los Models en sustitutos del Service ni de las restricciones PostgreSQL.

---

## 12. Base de datos PostgreSQL y migraciones

- Usar **migraciones Laravel** para crear o modificar el esquema; revisar primero el esquema real y el DER.
- Preferir IDs generados por PostgreSQL (identidades/secuencias o mecanismo real elegido). Nunca calcular `MAX(id) + 1`.
- Mantener PK/FK, índices, `UNIQUE`, `NOT NULL`, checks y reglas referenciales cuando correspondan.
- La unicidad del **CI cuando corresponda**, de correo de acceso, y la relación de **máximo una cuenta web por Paciente** deben contar con restricciones que resistan concurrencia.
- Garantizar **como máximo una consulta médica por cita**, también mediante una restricción de unicidad apropiada.
- Almacenar la duración y ubicación efectivas de cada cita para proteger el historial frente a futuros cambios de configuración.
- No borrar físicamente pacientes, médicos, citas y registros clínicos que deban conservar historia.
- Usar consultas parametrizadas; no concatenar SQL con entradas del cliente.
- PostgreSQL no es MySQL: no utilizar `AUTO_INCREMENT`, `sequelize.sync()`, convenciones de Sequelize ni SQL dependiente de MySQL.
- No crear tablas a partir de nombres de módulos o reportes por inercia. **Sí** crear las estructuras reales necesarias para sesiones, activaciones, notificaciones internas, resultados, versiones y bitácora cuando las reglas y el diseño aprobados lo requieran.
- Los seeders deben crear catálogos permitidos y, cuando corresponda, el primer Administrador de forma segura; nunca insertar contraseñas de producción o datos reales de pacientes.

---

## 13. Transacciones, integridad y concurrencia

Regla fundamental:

```text
Si todos los pasos de negocio son válidos → COMMIT.
Si falla un paso necesario               → ROLLBACK.
Nunca anunciar éxito ni dejar relaciones parciales.
```

Usar `DB::transaction()` y el mecanismo de bloqueo/restricción apropiado para operaciones que afectan varios registros relacionados.

### 13.1. Citas y consultorios

- Proteger los intervalos del Médico y del consultorio, incluidas reservas o reprogramaciones simultáneas.
- Una consulta inicial de disponibilidad **no reserva el turno**: revalidar y proteger la escritura atómica.
- Las protecciones pueden utilizar bloqueos transaccionales sobre recursos compartidos y/o restricciones PostgreSQL de no solapamiento; elegir y probar un mecanismo consistente con los intervalos configurados. No asumir que una transacción sin bloqueo evita una condición de carrera.
- Evitar solapamientos de intervalos `[inicio, fin)` para permitir citas contiguas compatibles cuando corresponda.
- No modificar la duración ni ubicación históricas por cambios de configuración posteriores.

### 13.2. Registro y activación web

- Proteger con restricciones y transacción la vinculación entre cuenta y ficha del Paciente.
- Reservar el proceso de activación pendiente para evitar dos solicitudes simultáneas.
- Verificar vigencias y límites, consumir ambos códigos y completar el vínculo sin crear dos cuentas ni fichas definitivas duplicadas.
- Si la verificación queda pendiente o falla, preservar estado recuperable y no habilitar acceso protegido.

### 13.3. Consulta clínica y resultados

- Evitar que peticiones concurrentes creen dos consultas para una cita.
- Mantener coherentes solicitud, versión documental, autorización médica y estado del laboratorio.
- No publicar una versión sustituida durante la revisión.
- En caso de fallo, conservar la versión válida anterior y la trazabilidad necesaria.

**No enviar correo o ejecutar procesos externos irreversibles como si formaran parte de un rollback SQL.** Usar mecanismos de despacho después de confirmar la transacción (`afterCommit`) u otra coordinación confiable; registrar fallos de envío y permitir reintentos controlados sin duplicaciones.

---

## 14. Manejo centralizado de errores: equivalente Laravel de AppError

Conservar la filosofía del `AppError` de la arquitectura original, **pero usando excepciones PHP y el manejador oficial de Laravel**.

- Las fallas esperadas del dominio pueden representarse con una excepción común propia, por ejemplo `AppError` en `src/shared/errors/`, o excepciones de negocio específicas que la extiendan.
- Cada excepción de negocio deberá expresar un **código estable de error** y un mensaje seguro; su traducción a status HTTP se resolverá centralmente.
- Integrar el renderizado JSON con el manejador de excepciones de la **versión instalada de Laravel** (por ejemplo, la configuración `withExceptions` en `bootstrap/app.php` cuando corresponda).
- No copiar `next(error)` de Express; Laravel propaga excepciones y las procesa con su manejador.
- No escribir `try/catch` en cada Controller para reenviar el mismo error. Capturar solo si hay recuperación, compensación, contexto seguro o traducción necesaria.
- Laravel puede generar automáticamente fallos de `FormRequest`, autenticación, autorización y `ModelNotFoundException`; mantener su semántica y **normalizar su salida sin ocultar el código correcto**.

### 14.1. Correspondencia HTTP

| Situación | HTTP |
| --- | --- |
| Petición mal formada | `400 Bad Request` |
| Credenciales inválidas / sesión ausente | `401 Unauthorized` |
| Actor autenticado sin permiso | `403 Forbidden` |
| Recurso inexistente o no accesible cuando convenga no revelar existencia | `404 Not Found` |
| Conflicto de cita, CI/correo único o transición inválida | `409 Conflict` |
| Error de validación de campos | `422 Unprocessable Content` |
| CSRF ausente o caducado en SPA Laravel | `419` (convención de Laravel) |
| Límite de solicitudes o bloqueo por intentos de acceso | `429 Too Many Requests` |
| Error inesperado del servidor | `500 Internal Server Error` |

No convertir un horario ocupado, un documento devuelto o un código vencido en un `500`. Evitar mensajes que revelen si un correo, CI o cuenta existe.

### 14.2. Contrato JSON recomendado

Mantener un formato uniforme para la API, documentado y consumible desde Axios:

**Éxito:** usar `JsonResource` o una respuesta consistente con `data` y, si procede, `meta` de paginación.

**Error:**

```json
{
  "success": false,
  "error": {
    "code": "CITA_HORARIO_OCUPADO",
    "message": "El horario seleccionado ya no está disponible.",
    "details": {}
  }
}
```

- `error.code`: identificador estable para distinguir tipos de error.
- `error.message`: mensaje comprensible, no técnico ni sensible.
- `error.details`: errores de campos u otra información segura cuando corresponda; puede omitirse si no aplica.
- No exponer traza, SQL, rutas internas, tokens, contraseñas, diagnósticos ni documentos privados.
- Evitar cambiar el formato sin actualizar las expectativas del frontend y las pruebas.
- No forzar cuerpo JSON en respuestas `204 No Content`.

### 14.3. Registro técnico de errores

- Mantener registros operativos útiles con contexto mínimo y un identificador de petición cuando exista.
- No registrar `request->all()` ni cabeceras completas por defecto.
- En producción, no exponer excepciones internas ni activar `APP_DEBUG=true`.
- Diferenciar **logs técnicos** de la **bitácora funcional de auditoría**. Sus objetivos y permisos no son equivalentes.

---

## 15. Autenticación para React SPA

**El acceso de los cuatro roles es solo por correo electrónico y contraseña.** No admitir nombre de usuario como alternativa.

Solución preferente, si la topología lo permite: **sesiones de Laravel + Sanctum en modo SPA stateful**, con cookies seguras y protección CSRF. Antes de implementarlo, verificar dominios, subdominios/puertos, CORS, `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`, `supports_credentials`, `SameSite` y HTTPS en producción. Si estas condiciones no están confirmadas, no inventar una estrategia de JWT.

Flujo orientativo para un frontend React separado:

```text
React solicita cookie CSRF cuando la configuración SPA la requiera.
      ↓
React envía POST de login con correo y contraseña.
      ↓
Laravel valida formato, cuenta, bloqueo temporal y credenciales.
      ↓
Laravel regenera el identificador de sesión al autenticarse.
      ↓
El navegador conserva cookies protegidas (no tokens en localStorage).
      ↓
React consulta /api/auth/me para recuperar usuario público y rol.
      ↓
Middleware y Service revalidan autorización en cada operación.
```

- `GET /api/auth/me` es un endpoint recomendado para restaurar el estado de sesión del frontend, sin incluir hash, contraseña, tokens ni datos clínicos.
- Los endpoints `/sanctum/csrf-cookie` o equivalentes se usan **según la configuración real**; no asumir que están activos sin instalar/configurar Sanctum.
- Evitar sesiones JWT autogestionadas, tokens de acceso en `localStorage` o `sessionStorage` y cookies manipulables por JavaScript para almacenar secretos de autenticación.
- Aplicar protección CSRF para operaciones con cookies de sesión, validación de origen, y autorización real del lado servidor.
- Al cerrar sesión: invalidar la sesión del servidor y regenerar el token CSRF según el mecanismo de Laravel, además de expirar cookies cuando corresponda.
- En cambio/recuperación de contraseña, invalidar sesiones anteriores; en recuperación exigir **nuevo login**. En desactivación, rechazar inmediatamente cualquier petición protegida de esa cuenta.
- Elegir y probar un mecanismo efectivo de invalidación de **múltiples sesiones**, compatible con el driver de sesiones real (por ejemplo, persistencia de sesiones en base de datos y revocación por usuario cuando sea apropiado). No suponer que `Auth::logout()` invalida todas las sesiones de otros dispositivos.

---

## 16. Estados de acceso, contraseñas y bloqueo

Distinguir claramente:

- **Cuenta pendiente de verificación:** solo puede finalizar activación; no puede usar funciones protegidas normales.
- **Cuenta activa:** puede autenticarse de acuerdo con su rol, vínculo y condiciones administrativas/profesionales.
- **Cuenta desactivada:** no puede iniciar sesión ni continuar con sesiones previas.
- **Bloqueo temporal:** atributo/condición temporal de autenticación, no una desactivación administrativa.

Política aprobada:

- Contraseña mínima de **12 caracteres**, hash seguro mediante Laravel `Hash`; nunca guardar texto plano ni devolver hash al frontend.
- Tras **3 contraseñas incorrectas consecutivas**, bloquear por **10 minutos**.
- Los intentos durante bloqueo no extienden el bloqueo.
- Al finalizar los 10 minutos, contador nuevamente en cero; autenticación exitosa también lo reinicia.
- Incorporar límites por IP/origen y solicitud para mitigar ataques y bloqueos provocados deliberadamente.
- Responder genéricamente cuando corresponda, para no confirmar existencia de correo/cuenta.
- Recuperar contraseña no activa una cuenta desactivada ni sustituye validación de identidad.

Para trabajadores: solo el Administrador crea cuentas del personal; invitación segura al correo de **24 horas y un solo uso** para confirmar posesión y establecer contraseña. Credenciales temporales excepcionales exigen cambio obligatorio en el primer acceso.

Recuperación de contraseñas:

- Los cuatro roles —Administrador, Recepcionista, Médico y Paciente— pueden recuperar su contraseña mediante **CU-06** cuando dispongan de un correo de acceso previamente verificado. Brevo envía un **enlace de 60 minutos**, de un solo uso; emitir otro invalida el anterior. La recuperación no reactiva cuentas desactivadas, invalida las sesiones anteriores y exige un nuevo inicio de sesión, sin autenticar automáticamente.
- Si un trabajador —Administrador, Recepcionista o Médico— no tiene acceso a su correo verificado o todavía no dispone de uno verificado, puede solicitar el restablecimiento administrativo de **CU-03**. El Administrador debe comprobar su identidad y utilizar la invitación segura o credencial temporal autorizada, exigiendo establecer una contraseña nueva antes del acceso normal e invalidando las sesiones anteriores conforme a las reglas establecidas.
- **El restablecimiento administrativo se aplica exclusivamente a cuentas del personal; no autoriza al Administrador a restablecer contraseñas de Pacientes.** Si un Paciente pierde el acceso a su correo verificado y no puede completar CU-06, el procedimiento alternativo queda pendiente de una definición funcional específica; no inventarlo ni implementarlo automáticamente.
- El Administrador nunca consulta contraseñas anteriores ni se envían contraseñas permanentes en texto plano. Registrar los eventos relevantes en Bitácora sin contraseñas, hashes ni tokens.

**Administración del acceso de Pacientes (CU-03 / PN02):**

- Solo el Administrador con sesión válida puede consultar datos administrativos mínimos y desactivar/reactivar cuentas de Pacientes existentes y previamente verificadas. El servidor valida autorización, cuenta objetivo, verificación previa y vínculo original; no habilita cuentas pendientes ni crea una segunda cuenta.
- Desactivar invalida todas las sesiones anteriores y conserva el historial. Reactivar una cuenta administrativamente desactivada solo restituye la habilitación de acceso: exige nuevo login, conserva el correo previamente verificado y no elimina ni omite un bloqueo temporal vigente.
- Estado de cuenta, estado de ficha y bloqueo temporal son independientes. Reactivar la cuenta no activa la ficha ni habilita nuevas reservas si esta sigue inactiva. Usar los estados existentes: suspender significa desactivar y rehabilitar significa reactivar; no crear `Suspendida`.
- Conservar identificador de Usuario, rol Paciente, contraseña, correo de acceso, vínculo con el mismo Paciente, antecedentes, citas, historial y resultados. Esta operación no concede permisos adicionales, no cambia identidad o credenciales y no sustituye CU-04, CU-05 o CU-06. Registrar responsable, resultado y referencias seguras en Bitácora, sin secretos ni contenido clínico.

---

## 17. Registro de Paciente, códigos y Brevo

Reglas obligatorias para `CU-04` y `CU-07`:

### 17.1. Paciente nuevo

- Se registra en la web con sus datos, CI cuando corresponda, correo y contraseña.
- El servidor impide duplicar ficha o cuenta.
- Se envía un **código de verificación de correo de seis dígitos** mediante Brevo.
- El registro pendiente dura **24 horas** desde su inicio; reintentos sobre el mismo proceso, sin duplicar fichas definitivas.
- Solo después de verificar se activa la cuenta y se completa el vínculo con su ficha nueva.

### 17.2. Paciente ya registrado por Recepción

1. Recepción verifica **presencialmente** la identidad y encuentra su ficha.
2. Confirma que no tenga cuenta web vinculada.
3. Genera un código de activación **alfanumérico aleatorio de 12 caracteres**, **24 horas**, **5 intentos incorrectos**, un solo uso.
4. El Paciente introduce **CI, código de Recepción, correo y contraseña**.
5. El servidor verifica y **reserva** la activación para impedir consumo concurrente.
6. Brevo envía un **segundo código numérico de seis dígitos** al correo.
7. El Paciente introduce el código del correo; dura **10 minutos** y admite **5 errores**.
8. El servidor completa de forma atómica el vínculo y consume ambos códigos.
9. El Paciente entra posteriormente **solo con correo y contraseña**.

- Reenvío de código del correo: esperar **60 segundos** entre envíos y limitar a **5 por hora** por proceso y correo, además de controles por origen.
- Reenviar no amplía las 24 horas del registro o código principal.
- Sustituir un código invalida el anterior; uno vencido, usado o agotado no puede reutilizarse.
- Si vence el código de Recepción durante una activación pendiente, solicitar otro **presencialmente** previa identidad.
- Un Paciente con ficha histórica conserva identificador, citas, consultas y resultados autorizados; **no crear otra ficha**.
- CI o correo por sí solos **no demuestran identidad**. No permitir enumeración pública de cuentas o datos clínicos.
- Guardar códigos y enlaces de manera segura (hash o resumen criptográfico protegido apropiado al tipo de secreto), nunca en claro ni en bitácora.
- No entregar a Recepción herramientas para crear contraseña o cuenta web del Paciente.

**Brevo se usa exclusivamente para correos de seguridad de cuentas.** No enviar avisos de cita, historia clínica o resultados médicos mediante Brevo.

---

## 18. Roles y permisos fijos del dominio

Existen exactamente cuatro roles:

| Rol | Backend autorizado |
| --- | --- |
| Administrador | Cuentas del personal y estado de acceso de cuentas de Pacientes previamente verificadas según CU-03, médicos, especialidades, consultorios, horarios, reportes, carga/corrección documental de laboratorio, bitácora |
| Recepcionista | Registro administrativo de pacientes y códigos de activación, programación, reprogramación, cancelación, llegadas e inasistencias |
| Médico | Agenda propia, historia clínica relacionada, consultas y mantenimiento autorizado de antecedentes según CU-23, solicitudes, revisión y autorización de laboratorio, marcar **sus propias** citas como Atendida |
| Paciente | Alta y activación propia, perfil, reserva **solo propia**, historial e información clínica autorizada, documentos publicados y avisos internos |

- No crear permisos editables, tablas de permisos dinámicos ni roles adicionales sin decisión explícita.
- No conceder al Administrador facultad para marcar citas `Atendida`, diagnosticar o autorizar resultados clínicos.
- No conceder a Recepción permisos de modificar información clínica o crear credenciales de pacientes.
- El Paciente **no puede reprogramar ni cancelar** citas.
- El Médico debe estar vinculado a su identidad profesional y a las citas/consultas que realmente le correspondan.

**Estados administrativos asociados:**

- Paciente inactivo: no recibe **nuevas citas**, ni por web ni por Recepción; con cuenta activa puede consultar su propio historial autorizado. Las citas ya existentes se conservan y se gestionan únicamente por flujos ordinarios autorizados.
- Médico inactivo: no recibe nuevas citas ni reprogramaciones a su favor; si mantiene cuenta activa puede completar atenciones y revisiones anteriores asignadas, de acuerdo con los CU vigentes. Una cuenta desactivada impide todo acceso protegido.
- Nunca eliminar historiales automáticamente por inactivar una ficha.

---

## 19. Consultorios, horarios y nuevas reservas (`CU-09` a `CU-16`)

- Una sede, exclusivamente presencial, con consultorios identificados; cada Médico tiene consultorio fijo, compartible solo en horarios compatibles.
- Una cita guarda código único, Paciente, especialidad, Médico, fecha, hora, duración efectiva, modalidad Presencial, consultorio y estado.
- Duración inicial **15 minutos**, configurable; los cambios posteriores de duración no alteran automáticamente citas registradas.
- El Médico debe estar activo, pertenecer a la especialidad y tener horario válido para **nuevas** citas.
- Paciente de nueva reserva debe estar activo. Desde web solo se permite reservar para el Paciente vinculado a la sesión.
- Comprobar en servidor horario completo, fecha válida, intervalos ocupados por **Médico o consultorio**, y condiciones simultáneas de concurrencia.
- Recepción es la única responsable de reprogramar o cancelar citas.
- Los cambios de consultorio u horario del Administrador que afecten citas futuras deben identificar conflictos y **no** invalidar o reprogramar citas silenciosamente.
- Una reprogramación mantiene el identificador de la cita; si estaba `Confirmada`, vuelve a `Programada`; actualiza avisos y conserva trazabilidad.
- Para una cita ya existente de Paciente inactivo, aplicar estrictamente el CU-15 aprobado para gestión del mismo registro, sin autorizar crear reservas adicionales.
- Cancelar no elimina el registro; libera el intervalo y retira el aviso de asistencia vigente.

---

## 20. Estados de citas, asistencia y agenda (`CU-17` a `CU-21`)

Los **únicos estados** de cita son:

```text
Programada | Confirmada | Atendida | Cancelada | Inasistencia
```

Transiciones ordinarias:

| Desde | Hacia | Responsable | Condición |
| --- | --- | --- | --- |
| Nueva cita | Programada | Recepción o Paciente para sí mismo | Reserva válida |
| Programada | Confirmada | Recepción | Llegada real, identidad comprobada, cita del día de atención |
| Programada/Confirmada | Atendida | Médico responsable | Atención efectivamente realizada |
| Programada/Confirmada | Cancelada | Recepción | Cancelación antes de atención |
| Programada | Inasistencia | Recepción | Intervalo finalizado y ausencia comprobada |
| Confirmada | Programada | Recepción | Reprogramación válida, pierde confirmación anterior |

Reprogramar una `Programada` conserva ese estado. `Atendida`, `Cancelada` e `Inasistencia` son finales para flujos ordinarios; no inventar una vía de reapertura.

- No marcar una cita `Atendida` automáticamente al guardar consulta; registrar atención clínica y cambio de estado son operaciones distintas.
- Recepción no marca `Atendida`; Administrador y Paciente tampoco.
- Agenda e historial son **consultas autorizadas** y no conceden privilegios de modificar datos.
- La ubicación y la duración históricas deben preservarse aunque luego cambie un consultorio o un horario.

---

## 21. Historia clínica y consulta médica (`CU-22` y `CU-23`)

- La historia clínica integra antecedentes, consultas y laboratorios sin confundirse con historial administrativo de citas.
- El Médico consulta información de pacientes vinculados a una atención autorizada; el Paciente solo la suya cuando sea autorizada.
- **CU-22 es exclusivamente de consulta. CU-23 / PN18** permite únicamente al Médico responsable registrar, completar o corregir antecedentes obtenidos durante una atención real y autorizada: tipo de sangre, alergias, enfermedades crónicas, medicamentos y antecedentes personales o familiares. Validar cuenta activa, sesión, rol y relación con la cita y el Paciente antes de leer o modificar.
- CU-08 no permite al Paciente editar antecedentes, diagnósticos, alergias clínicas, enfermedades crónicas, tratamientos o resultados. Administrador y Recepción tampoco modifican antecedentes. No crear consultas ficticias para justificar su actualización.
- Cada cita admite **máximo una consulta**, incluso frente a solicitudes concurrentes.
- El Médico responsable puede registrar la consulta durante la atención, sin exigir previamente `Atendida`; puede completar documentación de una atención realizada conforme a los CU.
- No registrar una atención inexistente para citas `Cancelada` o con `Inasistencia`.
- Actualizaciones clínicas importantes de consultas o antecedentes deben mantener responsable, fecha, motivo cuando corresponda y versiones anteriores y nuevas en almacenamiento clínico protegido. La bitácora general solo registra referencias y metadatos seguros, sin antecedentes, diagnósticos ni otro contenido clínico sensible. No sobrescribir silenciosamente información clínica previa.
- La modificación de una consulta o de antecedentes **no** cambia automáticamente el estado de la cita.
- Solo el Médico correspondiente puede registrar/modificar las consultas y solicitar los estudios asociados.
- Proteger consultas, antecedentes y documentos ante acceso por ID ajeno o URL manipulada.

---

## 22. Laboratorio digital (`CU-24` a `CU-27`)

- Cada consulta puede generar varias solicitudes de laboratorio. El estudio se realiza **fuera del sistema** por el laboratorio emisor.
- El Administrador registra un PDF o imagen legible y puede corregir asociación o sustituir un documento devuelto; **no** edita el contenido médico del resultado.
- Únicamente el Médico **solicitante** comprueba correspondencia y decide autorizar publicación o devolver con motivo.
- Estados internos de cada solicitud:

```text
Pendiente → En revisión → Disponible
```

La devolución o corrección permanece `En revisión`; no crea un cuarto estado.

**Flujo correcto:**

1. Documento correcto en revisión → el Médico autoriza directamente y pasa a `Disponible`.
2. Documento incorrecto → Médico devuelve con motivo → Administrador corrige/sustituye → nueva revisión médica → solo entonces se publica si corresponde.

- El Paciente ve `Pendiente` mientras el resultado no está publicado, aun cuando el estado interno sea `En revisión`.
- El Paciente solo descarga la **versión vigente publicada** de sus propias solicitudes; nunca las versiones devueltas o privadas.
- Comprobar autorización **también al entregar los bytes del archivo**, no solo al renderizar una lista.
- Documentos clínicos en disco privado o almacenamiento protegido, fuera de rutas públicas; validar tipo real, tamaño, formato y asociaciones. No confiar solo en MIME/extensión declarada por el navegador.
- Preservar trazabilidad de cargas, sustituciones, autorizaciones, fechas y responsables, con protección ante concurrencia sobre la versión revisada.

---

## 23. Recordatorios internos (`CU-28`)

- Los recordatorios son **persistidos en PostgreSQL** y se generan automáticamente, sin intervención manual del Paciente o Recepción ni necesidad de una sesión activa del Paciente.
- Anticipación definida: **24 horas antes de la fecha y hora programadas** de la cita. Si se registra exitosamente con menos de 24 horas de anticipación, generar el aviso después de guardar correctamente la reserva en la base de datos o en la siguiente ejecución del programador de tareas, siempre que el recordatorio siga siendo válido conforme a las condiciones establecidas y el intervalo de atención no haya finalizado. No generar avisos de una reserva cuyo registro haya fallado; revalidar vigencia, estados y preferencias también al ejecutar el programador, evitando duplicados para la misma cita y programación.
- **La generación no depende del estado `Confirmada` ni modifica el estado de la cita: una cita `Programada` puede generar su recordatorio y una `Confirmada` puede conservar sus avisos vigentes.** La reserva nueva permanece inicialmente `Programada`; guardarla no realiza automáticamente la transición a `Confirmada`, que significa exclusivamente llegada presencial verificada por Recepción.
- Relacionar aviso con el identificador del Paciente y la cita, incluso si todavía no tiene cuenta web; al activar la cuenta, mostrar únicamente avisos propios que correspondan a citas futuras vigentes.
- Reprogramación recalcula el momento del aviso según la nueva fecha y hora y actualiza o invalida avisos desactualizados, incluido el consultorio cuando corresponda. Cancelación, `Atendida`, `Inasistencia` o intervalo finalizado impiden nuevos avisos de próxima asistencia y retiran los anteriores de próximos recordatorios, conservando el historial según las reglas existentes.
- El programador de tareas de Laravel puede generar los avisos; hacer las tareas idempotentes y resistentes a repeticiones. **La frecuencia específica del programador se definirá durante el diseño técnico**, sin fijarla arbitrariamente en estos documentos.
- **No enviar recordatorios mediante correo, SMS, WhatsApp, Brevo ni push externo.**

---

## 24. Reportes (`CU-29`)

- Cubrir reportes mínimos de `RF13`, con filtros autorizados por fecha, Médico, especialidad, estado, pacientes y período.
- Consultas agregadas y filtros complejos pertenecen a Repositories/Query Builder; el Service coordina la lógica del reporte y el Controller responde.
- No duplicar todos los datos en tablas `reportes` sin justificación; un reporte normalmente es una consulta sobre información existente.
- Exportar CSV o PDF solo si se encuentra dentro del alcance opcional acordado.
- Aplicar paginación o límites adecuados y permisos de acceso a datos administrativos.

---

## 25. Bitácora del Sistema (`CU-30`)

- Registrar automáticamente eventos relevantes de autenticación, cambios de cuentas y roles, códigos emitidos/consumidos, pacientes, médicos, citas, consultas clínicas **como metadatos**, resultados y autorizaciones de laboratorio.
- Solo el **Administrador** consulta la bitácora general, con filtros y paginación, del más reciente al más antiguo.
- No exponer operaciones normales de edición o eliminación de eventos.
- No registrar contraseñas, hashes, códigos, tokens, enlaces secretos, cuerpos completos de formularios clínicos, diagnósticos ni archivos sensibles.
- Registrar responsable real (o evento del sistema/anónimo), fecha/hora, tipo, operación, entidad afectada, resultado y metadatos seguros cuando correspondan.
- Los eventos exitosos de operaciones de negocio deben reflejar **transacciones confirmadas**. Los intentos fallidos de seguridad que no confirman una operación pueden registrarse mediante un canal seguro independiente.
- La bitácora general no sustituye una trazabilidad clínica documental más específica y no concede al Administrador privilegios para interpretar o cambiar información clínica.
- Proteger contra alteración no autorizada y definir una política técnica de conservación sin inventar plazos exigidos por el examen.

---

## 26. API: recursos, filtros y contratos para React

- Construir endpoints y recursos según necesidades de `CU-01` a `CU-30`, no según cada tabla.
- Para listados utilizar filtros, orden y paginación validados; imponer límites razonables y no interpolar campos `orderBy` recibidos directamente sin lista permitida.
- En respuestas JSON incluir únicamente campos permitidos; preferir `JsonResource` sobre exponer modelos Eloquent completos indiscriminadamente.
- Respetar `201` al crear, `200` para lectura/resultado, `204` sin cuerpo cuando corresponda.
- Evitar respuestas que cambian arbitrariamente entre objetos, arrays y strings para el mismo endpoint.
- Acordar y documentar desde el backend contratos que React pueda consumir de modo uniforme.
- Diferenciar errores `401` (sesión ausente), `403` (sin permiso) y `419` (CSRF) para orientar correctamente el frontend.
- No incluir diagnósticos o resultados clínicos como parte de listados administrativos si no están autorizados.

---

## 27. Pruebas obligatorias por comportamiento

Usar **pruebas automatizadas de Laravel** (Feature y Unit) cuando el proyecto las tenga habilitadas; comprobar configuración y base de datos aislada para pruebas. No ejecutar migraciones destructivas contra bases de datos con información real.

Priorizar pruebas de:

- Roles, sesión, estados de cuentas, invalidación de sesiones y acceso a recursos ajenos.
- Bloqueo de 3 intentos durante 10 minutos, reinicio del contador y rate limits.
- Registro nuevo y vinculación de existente con ambos códigos, vencimientos, reenvíos, errores y simultaneidad.
- CI/correo únicos y una sola cuenta por Paciente.
- Invitación del personal y recuperación de contraseña con un solo uso.
- Reserva y reprogramación simultáneas; sin superposiciones de Médico o consultorio.
- Transiciones permitidas/prohibidas; Recepción no puede marcar `Atendida` y Paciente no cancela/reprograma.
- Consulta única por cita, restricciones de Médico responsable y trazabilidad de correcciones.
- Publicación directa y devolución/corrección/nueva revisión de resultados; descargas sin permiso deben fallar.
- Recordatorios de 24 horas, citas próximas, cambios de horario, cancelación y generación sin sesión.
- Bitácora solo para Administrador, eventos correctos, ausencia de secretos y rechazo de cambios.
- Contratos de errores HTTP/JSON y paginación.

Simular Brevo y otros servicios externos en pruebas mediante mecanismos del framework; nunca enviar correos reales involuntariamente. Para detectar problemas de concurrencia utilizar pruebas sobre un motor y entorno capaces de reproducir la semántica real de PostgreSQL; SQLite no sustituye estas verificaciones.

---

## 28. Actualización progresiva de `docs/ai/`

La documentación de análisis **se desarrolla junto con el software**, no toda al final. Respetar los nombres exactos definidos en `/AGENTS.md`.

### Al terminar un CU o pequeño grupo de CU

1. Identificar CU y cambios realmente completados.
2. Actualizar `/docs/ai/analisis-clases.md` con clases participantes, responsabilidades, colaboraciones, relaciones y flujo implementado.
3. Revisar migraciones, modelos y restricciones; si hubo cambios de persistencia, actualizar `/docs/ai/diagrama-entidad-relacion.md`.
4. Si surgieron cambios funcionales aprobados, sincronizar las secciones correspondientes de contexto, reglas y casos sin modificar unilateralmente los RF/RN oficiales.
5. Mantener correspondencia entre pruebas, código y documentación.

### Al terminar un módulo funcional

1. Actualizar **el diagrama global acumulativo** `/docs/ai/diagrama-clases.md`, integrando las clases de ese módulo con las anteriores.
2. Revisar que todos los CU finalizados del módulo estén presentes en `analisis-clases.md`.
3. Comprobar relaciones entre Controllers, Services, Repositories, Models y demás clases efectivamente empleadas; no inventar componentes ni indicar que otros módulos pendientes ya fueron implementados.
4. Verificar consistencia del DER con migraciones, claves, relaciones y restricciones reales.
5. Si después se modifica un módulo ya terminado, actualizar sus entradas y relaciones afectadas.

**No bloquear cambios menores por documentos todavía inexistentes que no sean necesarios para la tarea; sí reportar su ausencia y no afirmar una actualización que no ocurrió.** Si el usuario solicita iniciar esos documentos, crearlos usando la documentación aprobada y distinguir planificación de implementación.

---

## 29. Seguridad, secretos y datos médicos

- Nunca versionar `.env`, credenciales, `APP_KEY`, secretos de Brevo, cookies o sesiones reales, claves privadas, respaldos sensibles o documentos clínicos auténticos.
- Mantener `APP_DEBUG=false` en producción y no exponer logs técnicos por HTTP.
- Usar políticas de mínimo privilegio y autorización de recursos en cada petición de consulta/descarga.
- Aplicar validación segura de subidas de archivos, almacenamiento privado, defensas frente a acceso por identificadores ajenos y control de cargas excesivas.
- Respetar sesiones y cookies HttpOnly, Secure y SameSite según HTTPS y topología real; no incluir tokens en URLs ni respuestas cuando no corresponda.
- No usar datos clínicos reales en seeds, capturas, logs de prueba o documentación de ejemplo.
- Evitar exposición de correos/CI en respuestas públicas y registro de peticiones.
- Cualquier acceso a datos de pacientes debe seguir su relación legítima con el usuario, no solo el rol general.

---

## 30. Dependencias, Git y operaciones peligrosas

- Usar Composer para PHP y Artisan para tareas Laravel; no instalar paquetes redundantes por moda.
- Antes de migraciones o refactors revisar dependencias, impacto sobre datos existentes y archivos modificados.
- No ejecutar `migrate:fresh`, `db:wipe`, `schema:drop`, borrados masivos ni equivalentes sobre entornos persistentes sin autorización explícita.
- No ejecutar `git commit`, `push`, `merge`, `rebase`, crear ramas, despliegues ni cambios remotos automáticamente salvo solicitud explícita.
- Respetar el estilo del código real y normas PHP compatibles (PSR-12 y PSR-4); no renombrar clases públicas sin actualizar sus consumidores.
- No borrar ni reconstruir información histórica para simplificar la implementación.

---

## 31. Procedimiento antes y después de cada tarea

**Antes:**

1. Inspeccionar rutas, Controllers, Services, Repositories, Models y pruebas afectados.
2. Consultar `/AGENTS.md` y los RF/RN/CU/reglas vigentes relacionados.
3. Confirmar la versión instalada de Laravel y las convenciones reales.
4. Identificar actor, acceso al recurso, estados y efectos históricos.
5. Revisar contratos API ya consumidos por React y dependencias con otros módulos.
6. Detectar decisiones ambiguas antes de implementar reglas nuevas.

**Durante:**

1. Aplicar cambios mínimos, conservando la separación por capas.
2. Usar validación estructural y de negocio en su lugar correcto.
3. Proteger operaciones atómicas y concurrencia cuando corresponda.
4. Mantener errores y respuestas coherentes y no filtrar datos sensibles.
5. Añadir o actualizar pruebas relevantes.

**Después:**

1. Ejecutar comprobaciones de PHP, Laravel y pruebas que estén disponibles.
2. Verificar roles, recursos, integridad, concurrencia, errores y auditabilidad.
3. Actualizar `analisis-clases.md` para CU o grupo terminado; DER cuando cambie la base; `diagrama-clases.md` al completar módulo.
4. Comprobar que las clases y nombres de los diagramas correspondan al código efectivo.
5. Informar qué archivos cambiaron, pruebas ejecutadas, pendientes y documentos `docs/ai/` actualizados.

No presentar como funcional algo que no se ejecutó ni como actualizado un diagrama que aún no se revisó.

---

## 32. Regla final

El backend de Clínica Vida Salud debe ser:

```text
Coherente con el examen
Fiel a los 30 casos de uso aprobados
Seguro con información personal y clínica
Organizado por capas
Integrable con React
Consistente bajo concurrencia
Trazable y verificable
Compatible con Laravel y PostgreSQL
Mantenible sin complejidad innecesaria
```

**Ante dos soluciones técnicamente válidas, elegir la más simple que cumpla esas condiciones.** No alterar decisiones aprobadas para adaptar el negocio a una implementación improvisada.
