# AGENTS.md — Frontend de Clínica Vida Salud

Este archivo contiene las instrucciones específicas y obligatorias para OpenCode y otros agentes que trabajen en `frontend/` del proyecto **Sistema Web para la Gestión de Citas Médicas para la Clínica Vida Salud**.

Debe aplicarse **junto con `/AGENTS.md`**, ubicado en la raíz. El frontend implementa la experiencia de uso y consume la API de Laravel: **no decide por sí solo las reglas de negocio, no concede permisos y no accede directamente a PostgreSQL**.

No crear un segundo frontend en Laravel Blade, no copiar módulos de farmacia y no reemplazar React por otra tecnología sin una decisión explícita.

---

## 1. Fuentes y prioridades antes de modificar el frontend

Respetar la jerarquía establecida en `/AGENTS.md`:

1. Enunciado oficial del examen: `RF01`–`RF13` y `RN01`–`RN14`.
2. Decisiones más recientes y aprobadas de Clínica Vida Salud.
3. Documentación funcional vigente, casos de uso y procesos de negocio.
4. Documentación existente en `/docs/ai/`.
5. API, código, pruebas y convenciones efectivamente implementados.
6. Recomendaciones técnicas, solo cuando no contradigan las fuentes anteriores.

Consultar, según la tarea y **solo si existen**, estos archivos con nombres exactos:

```text
/docs/ai/contexto-maestro.md
/docs/ai/requerimientos.md
/docs/ai/reglas-negocio.md
/docs/ai/casos-de-us.md
/docs/ai/diagrama-entidad-relacion.md
/docs/ai/diagrama-clases.md
/docs/ai/analisis-clases.md
```

- Si un archivo aún no existe, no fingir haberlo leído ni inventar su contenido.
- No convertir una regla propia del proyecto en una supuesta exigencia textual del examen.
- No usar especificaciones antiguas que indiquen **Blade como frontend** o **nombre de usuario como alternativa para login**: las decisiones vigentes son React + Vite y acceso mediante **correo electrónico y contraseña**.
- Consultar `/backend/AGENTS.md` cuando se requiera comprender el contrato de un endpoint o una regla del servidor; no implementar lógica PHP en el frontend.
- Si una tarea exige un nuevo comportamiento no documentado y afecta reglas de negocio, identificarlo y solicitar la decisión pertinente; no asumirla silenciosamente.

---

## 2. Stack del frontend

```text
React
Vite
JavaScript (JSX)
HTML5 + CSS3
React Router
Axios
Context API para sesión/autenticación
pnpm (si así lo confirma el proyecto/lockfile)
```

- Mantener el frontend como aplicación React separada del backend PHP/Laravel.
- Vite se utiliza para desarrollo, compilación y gestión de recursos del frontend.
- React Router gestiona navegación; Axios centraliza las peticiones HTTP.
- Mantener JavaScript conforme al stack acordado. No migrar unilateralmente a TypeScript, Next.js, Redux o a una solución full-stack distinta.
- Antes de ejecutar comandos o instalar dependencias, comprobar `package.json`, lockfile y versiones reales del repositorio.
- No instalar una librería nueva si React, la plataforma web o las dependencias existentes resuelven razonablemente el problema.

---

## 3. Arquitectura obligatoria: Feature-Based + Component-Based

Mantener **exactamente la filosofía y organización de carpetas** establecida para el frontend del proyecto de referencia:

```text
Page / Component
      ↓
Feature Service
      ↓
src/api/api.js (Axios)
      ↓
API Laravel (Route → Middleware → Controller → Service → Repository → Eloquent)
      ↓
PostgreSQL
```

Responsabilidades:

- **Page:** coordina una pantalla, estados de carga, filtros, navegación y resultados de los servicios.
- **Componente de feature:** presenta una parte de la interfaz de su módulo y recibe datos y callbacks mediante props.
- **Componente global:** elemento de interfaz realmente reutilizable entre varios módulos.
- **Feature Service:** centraliza llamadas HTTP relacionadas con una funcionalidad; no ejecuta reglas críticas de negocio.
- **`api.js`:** define la configuración Axios común y los comportamientos transversales HTTP estrictamente necesarios.
- **Backend:** valida formato, permisos, titularidad, reglas, estados, disponibilidad y persistencia; es la fuente de verdad.

No colocar peticiones `axios` directamente y repetidamente en componentes si corresponden a un servicio del módulo. No duplicar en React una lógica de disponibilidad, autorización o estados que corresponda al Service de Laravel.

---

## 4. Estructura de carpetas del frontend

**Conservar los nombres en minúsculas y las carpetas base del frontend de referencia.** Las features se adaptan al ámbito clínico; se crean progresivamente según los CU en desarrollo.

```text
frontend/
├── AGENTS.md
├── public/
├── src/
│   ├── api/
│   │   └── api.js
│   ├── app/
│   │   └── router.jsx
│   ├── components/
│   ├── layouts/
│   │   ├── MainLayout.jsx
│   │   └── AuthLayout.jsx
│   ├── features/
│   │   ├── auth/
│   │   ├── usuarios/
│   │   ├── pacientes/
│   │   ├── medicos/
│   │   ├── especialidades/
│   │   ├── consultorios/
│   │   ├── horarios/
│   │   ├── citas/
│   │   ├── agenda/
│   │   ├── historia-clinica/
│   │   ├── laboratorio/
│   │   ├── recordatorios/
│   │   ├── reportes/
│   │   └── bitacora/
│   ├── hooks/
│   │   └── useAuth.js
│   ├── routes/
│   │   ├── ProtectedRoute.jsx
│   │   └── RoleRoute.jsx
│   ├── constants/
│   │   └── roles.js
│   ├── utils/
│   ├── styles/
│   │   └── global.css
│   ├── App.jsx
│   └── main.jsx
├── .env.example
├── .gitignore
├── index.html
├── package.json
├── pnpm-lock.yaml       # Si pnpm es el gestor confirmado
└── vite.config.js
```

**La estructura es objetivo, no afirmación de que todos los archivos ya existan.** No crear carpetas ni archivos vacíos para todos los módulos anticipadamente.

- Reservar `src/components/` para piezas verdaderamente compartidas.
- Las carpetas `src/features/<modulo>/` representan **funciones de interfaz**, no tablas o un caso de uso por carpeta.
- Cuando dos CU estén estrechamente relacionados, pueden compartir una feature (por ejemplo, reserva y reprogramación dentro de `citas/`).
- No crear carpetas de farmacia, inventario, compras, ventas, FEFO, recetas electrónicas o vencimientos.
- No renombrar, mover ni reorganizar la estructura base sin solicitud expresa.
- Mantener la estructura compatible con los `AGENTS.md` de raíz y backend.

---

## 5. Features: organización interna

Organización mínima sugerida al implementar un módulo:

```text
src/features/citas/
├── pages/
│   ├── CitasPage.jsx
│   └── ReservarCitaPage.jsx
├── components/
│   ├── DisponibilidadTurnos.jsx
│   └── CitaDetalle.jsx
└── services/
    └── cita.service.js
```

- Crear subcarpetas adicionales (`hooks/`, `utils/`, `context/`, `styles/`) **solo si una necesidad real del módulo lo justifica**.
- No duplicar componentes que ya existen en otra feature si pueden reutilizarse sin romper límites; elevarlos a `src/components/` cuando de verdad sean compartidos.
- No mover toda la lógica a una sola página ni crear una abstracción genérica para un único uso.
- Conservar convenciones de nombres ya utilizadas en el repositorio.

---

## 6. Pages y componentes

**Pages** (`features/<modulo>/pages/`):

- Representan vistas completas, normalmente vinculadas a rutas.
- Solicitan datos a través de Services y coordinan estados de carga, error y vacío.
- Gestionan filtros, paginación visual, confirmaciones y navegación.
- Presentan los permisos y datos públicos que el backend concede.
- No contienen SQL, credenciales, validaciones de negocio definitivas ni cálculos autoritativos de horarios.

**Componentes específicos** (`features/<modulo>/components/`):

- Renderizan formularios, tablas, tarjetas, secciones y controles del módulo.
- Reciben props explícitas y comunican acciones mediante callbacks.
- Evitan depender de servicios externos cuando puedan ser presentacionales.

**Componentes globales** (`src/components/`):

- Pueden incluir `Button`, `Input`, `Modal`, `ConfirmDialog`, `Table`, `Pagination`, `Loading`, `EmptyState` o `ErrorMessage` cuando sean reutilizados.
- No convertir `src/components/` en una carpeta indiscriminada para todas las pantallas.

---

## 7. Services del frontend

Cada feature coloca sus servicios HTTP en `features/<modulo>/services/`.

Ejemplo conceptual:

```js
// src/features/citas/services/cita.service.js
import { api } from '../../../api/api';

export async function consultarDisponibilidad(filtros) {
  const response = await api.get('/disponibilidad', { params: filtros });
  return response.data;
}
```

Este endpoint es **ilustrativo**; antes de implementarlo comprobar rutas reales del backend y acuerdos de contrato. No inventar endpoints como si ya existieran.

- El Service recibe datos y llama a la API; devuelve el resultado sin mezclarlo con detalles visuales.
- No usa componentes React, `useNavigate`, JSX ni `setState`.
- No reimplementa permisos, transiciones ni reglas clínicas.
- Mantener la convención de respuesta que establezca la API (por ejemplo, `data`, `meta`, o `error`), sin asumir estructuras inexistentes.
- Consultar el backend antes de cambiar nombres de parámetros o formatos que otras pantallas consuman.

---

## 8. Configuración central de Axios

Ubicación obligatoria:

```text
src/api/api.js
```

Ejemplo de partida, **ajustable a la configuración real de Laravel/Sanctum**:

```js
import axios from 'axios';

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' }
});
```

- `withCredentials` permite el envío de cookies según origen/CORS.
- `withXSRFToken` debe coordinarse con la configuración CSRF de la SPA y la versión efectiva de Axios; no sustituye la configuración del servidor.
- La URL base se define **una sola vez**. No escribir `http://localhost` repetido en cada Service.
- No crear una instancia Axios por feature sin una justificación real.
- No colocar secretos del backend en el cliente.
- Si se implementan interceptores, mantenerlos pequeños, verificables y sin dependencia circular de `AuthContext`.
- No reintentar automáticamente operaciones de escritura después de errores de red/CSRF/conflicto, pues pueden haberse confirmado en el servidor y generar duplicaciones.

---

## 9. Variables de entorno y configuración pública

La URL HTTP del backend se configurará a través de:

```text
VITE_API_URL
```

Ejemplo **solo ilustrativo** para desarrollo:

```dotenv
VITE_API_URL=http://localhost:8000/api
```

- Comprobar puertos, origen y ruta real antes de utilizar este ejemplo.
- Toda variable `VITE_*` puede ser visible al navegador; **no contiene secretos**.
- Nunca exponer `APP_KEY`, `DB_PASSWORD`, `BREVO_API_KEY`, credenciales SMTP, tokens de sesión o datos privados en `.env` del frontend ni código compilado.
- Mantener `.env.example` sin valores sensibles. No versionar archivos `.env` que contengan información privada.

---

## 10. React Router y rutas protegidas

La configuración principal pertenece a:

```text
src/app/router.jsx
```

- Centralizar el enrutamiento y organizar rutas públicas, protegidas y exclusivas por rol.
- Utilizar `ProtectedRoute.jsx` para verificar el estado de autenticación de la interfaz.
- Utilizar `RoleRoute.jsx` para mostrar rutas según los cuatro roles fijos.
- Preservar el destino solicitado cuando sea seguro y apropiado después de iniciar sesión.
- Evitar redirecciones mientras todavía se está restaurando la sesión.
- Mostrar una pantalla de acceso denegado al usuario autenticado sin permiso, en lugar de asumir que carece de sesión.
- No usar parámetros manipulables de la URL como única comprobación de titularidad de Paciente, consulta o resultado.

Las rutas concretas de UI se definen durante cada feature, alineadas con los casos de uso y contratos reales. No interpretar estas indicaciones como endpoints ya construidos.

---

## 11. Layouts y navegación

Conservar:

```text
src/layouts/MainLayout.jsx
src/layouts/AuthLayout.jsx
```

- `MainLayout` incorpora la estructura compartida (cabecera, menú, navegación y contenido) de las áreas autenticadas.
- `AuthLayout` organiza las pantallas públicas de acceso, registro, activación y recuperación.
- No duplicar menús y cabeceras dentro de cada página.
- Mostrar opciones por rol, **sin confundir visibilidad de menú con autorización real**.
- Evitar exponer accesos clínicos en menús de Recepción o de usuarios ajenos.
- El diseño será responsive para computadoras y celulares, según el alcance del examen.

---

## 12. Autenticación: sesiones Laravel, no JWT en React

Los cuatro roles ingresan con **correo electrónico y contraseña**. No incorporar login por nombre de usuario.

La estrategia preferente es **Laravel con sesiones y, si la SPA separada lo requiere, Sanctum stateful con cookies y CSRF**, de acuerdo con `/backend/AGENTS.md`. No codificar una estrategia alternativa de JWT por comodidad.

Flujo orientativo:

```text
React abre login.
      ↓
Si corresponde, obtiene cookie CSRF de Laravel/Sanctum.
      ↓
Envía correo y contraseña a la API por Axios con cookies.
      ↓
Laravel verifica cuenta, credenciales, estado y bloqueo.
      ↓
Laravel establece una sesión de servidor segura.
      ↓
React solicita usuario público mediante GET /api/auth/me (si está implementado).
      ↓
AuthContext actualiza estado público y la navegación responde al rol.
```

- El navegador administra cookies de sesión HttpOnly; React **no almacena tokens de sesión**.
- No guardar JWT, cookies de sesión, contraseñas o códigos en `localStorage`, `sessionStorage`, Context API o estado persistente.
- No asumir que `/sanctum/csrf-cookie`, `/api/auth/me` u otra ruta están disponibles sin verificar la configuración real.
- El backend es quien determina cuenta pendiente, activa, desactivada, bloqueada y permisos asociados.
- Cuando el backend invalida una sesión, la interfaz no puede seguir mostrando al usuario como autenticado tras detectar su rechazo.

---

## 13. AuthContext, useAuth y restauración de sesión

Usar **Context API únicamente para estado público de autenticación**. El contexto puede ubicarse dentro de la feature `auth/` si aún no existe, sin crear otro sistema global de estado.

Campos conceptuales:

```js
{
  usuario: null,
  isAuthenticated: false,
  loading: true,
  login: async () => {},
  logout: async () => {},
  refreshSession: async () => {}
}
```

El usuario público puede contener el ID, el rol, nombre visible, correo de la cuenta y **solo la información autorizada que el backend entregue**. Nunca hashes, tokens, contraseñas ni información clínica ajena.

`src/hooks/useAuth.js` proporciona acceso consistente al contexto.

Al recargar la aplicación:

1. Mantener `loading` mientras se consulta la sesión al backend.
2. Recuperar el usuario público con el endpoint existente (se recomienda `GET /api/auth/me`).
3. Si la sesión es válida, establecer el usuario y su rol.
4. Si devuelve `401`, limpiar estado público y mostrar la ruta pública o login.
5. No redirigir prematuramente antes de completar la comprobación.
6. Evitar llamadas duplicadas innecesarias provocadas por montajes repetidos o cambios de ruta.

No considerar que un usuario está autenticado únicamente porque quede un objeto en memoria o en algún almacenamiento del navegador.

---

## 14. ProtectedRoute, RoleRoute y permisos

Mantener archivos:

```text
src/routes/ProtectedRoute.jsx
src/routes/RoleRoute.jsx
src/constants/roles.js
```

Constantes **conceptuales** (identificadores concretos según contrato real de la API):

```js
export const ROLES = {
  ADMINISTRADOR: 'Administrador',
  RECEPCIONISTA: 'Recepcionista',
  MEDICO: 'Médico',
  PACIENTE: 'Paciente'
};
```

No inventar IDs numéricos de roles si el backend todavía no los fijó. No usar números mágicos ni comparaciones dispersas cuando existan constantes.

- **Administrador:** cuentas del personal, consulta administrativa mínima y desactivación/reactivación de cuentas de Pacientes previamente verificadas según CU-03, especialidades, médicos, consultorios, horarios, reportes, carga documental de laboratorio y consulta de bitácora.
- **Recepcionista:** registros administrativos de pacientes, generación de códigos de activación, programación, reprogramación, cancelación, confirmación de llegada e inasistencia.
- **Médico:** propia agenda, consultas y registro/actualización de antecedentes durante una atención real y autorizada según CU-23, y estudios autorizados; solo el responsable marca `Atendida` y autoriza publicar resultados propios.
- **Paciente:** activa cuenta, consulta y actualiza propio perfil, reserva para sí mismo, consulta historial y resultados publicados; no cancela ni reprograma.

**La protección visual de una ruta no sustituye la autorización del backend.** Cualquier usuario puede intentar una petición HTTP manual. Validar permisos y relaciones reales en Laravel.

En el panel administrativo, CU-03 separa las acciones del personal de las cuentas de Pacientes: estas últimas solo permiten consultar datos mínimos y desactivar/reactivar acceso si ya fueron verificadas. No ofrecer creación, cambio de contraseña, correo, rol, permisos o vinculación, ni activación de cuentas pendientes. Suspender y rehabilitar corresponden a Desactivada y Activa, sin un estado nuevo. Desactivar invalida sesiones; reactivar exige nuevo login y no activa la ficha ni elimina bloqueos temporales vigentes. La interfaz representa la respuesta del servidor y conserva independientes cuenta, ficha y bloqueo.

---

## 15. Módulos y casos de uso: catálogo vigente

Conservar la numeración continua del proyecto:

| Módulo de negocio | CU |
| --- | --- |
| Usuarios y autenticación | `CU-01` a `CU-06` |
| Pacientes y perfil personal | `CU-07` a `CU-08` |
| Médicos y especialidades | `CU-09` a `CU-10` |
| Consultorios y horarios | `CU-11` a `CU-12` |
| Búsqueda y reserva de citas | `CU-13` a `CU-14` |
| Reprogramación y cancelación | `CU-15` a `CU-16` |
| Asistencia y estados | `CU-17` a `CU-19` |
| Agenda e historial | `CU-20` a `CU-21` |
| Historia clínica y consultas | `CU-22` a `CU-23` |
| Laboratorio digital | `CU-24` a `CU-27` |
| Recordatorios internos | `CU-28` |
| Reportes administrativos | `CU-29` |
| Bitácora del Sistema | `CU-30` |

No crear una pantalla, una feature o un endpoint por cada CU automáticamente. Algunos casos comparten páginas o servicios; otros requieren varias pantallas. La decisión depende del flujo funcional.

---

## 16. Registro web, activación y recuperación de cuentas

Los formularios deben representar **exactamente dos caminos distintos**:

### Paciente nuevo (`CU-04`)

1. Introduce datos de registro, **CI obligatorio**, correo y contraseña.
2. El frontend envía la solicitud al servidor y muestra el paso de verificación de correo.
3. El sistema envía un **código de seis dígitos** mediante Brevo.
4. El Paciente introduce el código y completa la verificación.
5. Solo después de la activación podrá usar funciones protegidas.

**No solicitar código de Recepción para un Paciente realmente nuevo.**

El CI es obligatorio tanto para registro nuevo como para vinculación web por decisión propia de Clínica Vida Salud; no es una exigencia literal de RF02 para toda ficha administrativa. Validar su presencia y formato conforme a las reglas vigentes, explicar «El CI es obligatorio para el registro web» y orientar a Recepción si falta. No ofrecer omitirlo ni una vinculación web alternativa sin CI; el servidor también valida presencia, formato y unicidad, y conocer el CI no prueba titularidad de una ficha.

Los formularios administrativos de Recepción de CU-07 sí permiten excepcionalmente una ficha sin CI, con documento NULL e identificador interno único. Presentar la revisión de posibles duplicados sin fusionar por semejanza de nombres. Permitir incorporar después el CI a la **misma ficha**, según comprobación y respuesta del servidor, preservando historial y sin generar una cuenta web automáticamente. La ficha conserva las operaciones presenciales autorizadas; esta excepción no es autorregistro web.

### Paciente previamente registrado por Recepción (`CU-04` y `CU-07`)

1. Recepción verifica presencialmente la identidad y el CI de la ficha y entrega el código de activación.
2. El Paciente abre «Ya tengo un código de activación».
3. Introduce **CI, código de Recepción, correo electrónico y contraseña**.
4. Laravel valida la primera autorización y establece el proceso pendiente.
5. Brevo envía el **segundo código numérico** al correo proporcionado.
6. El Paciente introduce el segundo código y confirma.
7. El backend activa/vincula la única cuenta permitida y conserva citas e historial.

- **Código de Recepción:** alfanumérico de 12 caracteres; **24 h**, cinco intentos, un solo uso; se renueva únicamente en Recepción tras verificar identidad.
- **Código de correo:** seis dígitos; **10 min**, cinco intentos y un solo uso; el reenvío respeta **60 segundos entre envíos** y **máximo cinco por hora** por proceso y correo, más límites del backend por origen.
- **Registro de Paciente nuevo:** proceso pendiente de **24 h**; solicitar otro código de correo no reinicia esas 24 h.
- **Invitación del personal:** enlace temporal de **24 h**, un solo uso, para verificar correo y establecer contraseña.
- **Primer Administrador:** única excepción de instalación local controlada; se prepara Activo con credenciales privadas, sin formulario público ni invitación ordinaria. La marca de correo verificado por autorización de instalación no demuestra control del buzón y no aplica a Pacientes. No mostrar login como funcional solo porque la cuenta exista; consultar contexto §17.12 y la API real.
- **Recuperación:** enlace seguro de **60 min**, un solo uso, con posterior inicio de sesión; **no** reutilizar los códigos numéricos para recuperar contraseñas.
- Aplicar la política de contraseña mínima de **12 caracteres** como ayuda visual, y dejar al backend su verificación definitiva.

**Protección contra enumeración:** no mostrar información de un paciente, cuenta, citas o historial porque se haya escrito un CI o correo. Usar mensajes genéricos acordes con el flujo autorizado. No distinguir públicamente «correo no registrado» de «correo registrado» en recuperación.

**Estados de interfaz:** pendiente, enviando, código enviado (solo tras confirmación del backend), verificando, vencido, agotado, error de envío, reenvío habilitado y activación completa. No declarar éxito si la API no lo confirmó.

No almacenar códigos o contraseñas persistentemente en el navegador ni incluirlos en URL, analytics o logs.

---

## 17. Inicio de sesión, bloqueo, cierre y sesiones inválidas

- Login por **correo electrónico y contraseña**, sin nombre de usuario.
- Después de tres contraseñas incorrectas consecutivas el backend bloquea la cuenta durante **diez minutos**. El frontend muestra la respuesta segura y el tiempo restante solo si el servidor lo proporciona de forma adecuada.
- No incrementar tiempos ni contadores localmente como fuente de verdad; esos cálculos pertenecen a Laravel.
- Un inicio de sesión correcto limpia el estado de error visual.
- Logout debe llamar al endpoint que invalida la sesión en el **servidor**, esperar su resultado y limpiar el estado de sesión; no limitarse a borrar el Context.
- Si la sesión ha expirado o la cuenta se desactivó, limpiar datos privados visibles de esa sesión y redirigir apropiadamente sin exponer detalles.
- No implementar «reautenticación automática» después de recuperación de contraseña: el usuario debe volver a iniciar sesión.
- Cambios sensibles de correo o credenciales deben seguir los casos y validaciones definidos por el backend.

---

## 18. Formularios y validación de entradas

- Validar en React campos obligatorios, formatos, longitudes y coherencia sencilla para ofrecer una buena experiencia.
- Mostrar errores por campo cuando el servidor los entregue (`422`) y mensajes generales en errores de negocio.
- No confiar en la validación del navegador para autorizaciones ni integridad de datos.
- Enviar exclusivamente campos permitidos; no enviar IDs autogenerados, hashes, roles autoasignados, estados impuestos por el servidor o valores clínicos de otros pacientes.
- Evitar envíos duplicados: deshabilitar temporalmente el botón o manejar un estado `submitting`, sin reemplazar la idempotencia/atomicidad del servidor.
- Antes de operaciones sensibles o potencialmente irreversibles, solicitar confirmación visual cuando mejore la seguridad o evite errores.
- Mantener los datos escritos en el formulario ante errores corregibles, **salvo contraseñas, códigos y otros secretos que deba ser prudente limpiar**.
- No utilizar mensajes de error distintos que permitan deducir la existencia de cuentas en el flujo público.

---

## 19. Manejo de errores HTTP y comunicación con Laravel

El backend definió manejo centralizado de excepciones y un contrato JSON uniforme. El frontend debe **consumir ese contrato sin cambiarlo arbitrariamente**.

Un error de dominio puede seguir esta forma, cuando la API real la confirme:

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

| Respuesta | Comportamiento esperado del frontend |
| --- | --- |
| `400` | Informar que la petición no es válida cuando corresponda. |
| `401` | Tratar como sesión no autenticada; limpiar el estado público y dirigir a login cuando proceda. |
| `403` | Mostrar acceso denegado; no asumir automáticamente sesión cerrada. |
| `404` | Mostrar recurso no encontrado o respuesta neutra que no exponga su existencia. |
| `409` | Mostrar conflicto (por ejemplo, horario ocupado), conservar la operación previa y permitir seleccionar alternativa. |
| `419` | Indicar sesión/CSRF caducado; renovar el mecanismo CSRF si corresponde, sin reenviar automáticamente escrituras sensibles. |
| `422` | Mostrar errores de campos y permitir corregir el formulario. |
| `429` | Respetar límites de intentos o reenvíos; mostrar espera sin revelar cuentas o datos sensibles. |
| `500` | Mostrar error general seguro y permitir reintentar cuando corresponda. |
| Sin conexión/timeout | Informar el fallo sin afirmar que el servidor no ejecutó la operación. |

- Mostrar el `error.message` **solo si el contrato garantiza que es un mensaje seguro**; nunca imprimir cuerpos técnicos indiscriminadamente.
- Nunca mostrar stack traces, consultas SQL, rutas privadas, detalles internos de Laravel, tokens, códigos ni información clínica.
- Utilizar `error.code` para decisiones UI estables cuando exista; no inspeccionar textos de error para inferir comportamientos críticos.
- Tratar errores de red de operaciones de escritura como **resultado incierto** hasta consultar el estado persistido; un timeout puede suceder después de que el servidor guardó la operación.
- Diferenciar mensajes de validación, conflicto, permiso, sesión y error del servidor. No convertir todos en «Ocurrió un error 500».
- No hacer interceptores que entren en bucles de redirección, hagan solicitudes duplicadas o filtren respuestas protegidas.

---

## 20. Estado local, estado global y datos del servidor

Utilizar `useState`, `useReducer` cuando ayude al formulario y props para el estado local de cada pantalla.

- Context API: principalmente para autenticación y necesidades globales justificadas.
- No introducir Redux, Redux Toolkit, Zustand u otra librería de estado global sin una necesidad concreta y aprobada.
- No mantener copias globales desactualizadas de pacientes, diagnósticos, citas o informes médicos.
- La API es la fuente de verdad de horarios, citas, estados y registros; React representa lo que devuelve.
- Después de guardar, invalidar/reconsultar los datos de vista afectados cuando corresponda.
- Cancelar peticiones obsoletas o ignorar sus respuestas cuando una navegación/filtro nuevo deje sus resultados fuera de contexto.
- No guardar datos clínicos sensibles en mecanismos de persistencia del navegador por comodidad.

---

## 21. Citas, disponibilidad y concurrencia (`CU-13` a `CU-19`)

- Mostrar únicamente opciones de reserva permitidas al Paciente para sí mismo; Recepción programa para pacientes administrativamente registrados y activos.
- La pantalla de búsqueda muestra **disponibilidad orientativa**. Un turno visible **no garantiza** su reserva.
- La confirmación real exige que Laravel verifique paciente, médico activo, especialidad, horario, consultorio y ausencia de superposición al guardar.
- Citas presenciales de una sola sede, con consultorio fijo del Médico.
- Los cinco estados son **Programada, Confirmada, Atendida, Cancelada, Inasistencia**. No inventar un sexto estado de cita.
- Paciente **no** cancela ni reprograma desde el frontend; esos controles corresponden a Recepción.
- Recepción registra llegada e inasistencia; **únicamente el Médico responsable** marca `Atendida`.
- Reprogramar conserva la cita y puede retornar `Confirmada` a `Programada`. Cancelar no elimina la historia.
- Ante `409` por conflicto concurrente, mostrar el problema, actualizar disponibilidad y permitir otra elección; no simular que la cita fue confirmada.
- Paciente con ficha inactiva no recibe nuevas reservas ni por Recepción; si su cuenta sigue activa consulta su historial autorizado.
- Médico profesionalmente inactivo no recibe nuevas citas ni reprogramaciones a su favor, pero puede completar operaciones anteriores autorizadas si su cuenta sigue activa.

---

## 22. Médicos, especialidades, consultorios y horarios (`CU-09` a `CU-12`)

- Solo Administrador administra médicos, especialidades, consultorios, asignaciones y horarios.
- Especialidades inactivas no se ofrecerán para nuevas citas; conservar referencias históricas.
- Consultorios pueden compartirse entre Médicos únicamente en horarios compatibles; mantener consultorio fijo por Médico.
- La duración inicial de cita es 15 minutos y puede configurarse; no modificar en pantalla duraciones históricas al cambiar la configuración vigente.
- Al modificar consultorio u horario, mostrar citas futuras afectadas y conflictos **según el resultado del backend**; no actualizar silenciosamente reservas anteriores.
- Si el cambio exige fecha/hora distinta, la reprogramación corresponde a Recepción por CU-15; no crear ese permiso para el Administrador.
- Mantener la sede única y no ofrecer modalidades virtuales.

---

## 23. Pacientes, perfil, agenda e historial (`CU-07`, `CU-08`, `CU-20`, `CU-21`)

- El Paciente ve y edita solo datos personales, de contacto, emergencia y preferencias permitidas de su propia ficha; no edita antecedentes, alergias registradas clínicamente, enfermedades crónicas, medicamentos/tratamientos, diagnósticos ni resultados. Recepción gestiona el registro administrativo, sin permisos clínicos ni de administración de contraseñas o acceso de cuentas de Pacientes.
- El Paciente no puede cambiar arbitrariamente el identificador de su ficha o vincularse con otra persona.
- Diferenciar **correo de contacto** y **correo de acceso**. Cambiar el primero no cambia el segundo; cambiar el correo de acceso exige el procedimiento de verificación definido por el servidor.
- Un Paciente puede tener ficha y citas antiguas sin cuenta web. La activación conserva historial y no crea un segundo registro.
- El Paciente consulta su historial de citas; Recepción consulta historial administrativo según permisos; Médico consulta su propia agenda.
- Si una cuenta activa corresponde a una ficha de Paciente inactiva, puede consultar historial e información autorizada, pero no registrar nuevas reservas.
- Mostrar motivo de consulta breve únicamente cuando el rol y el vínculo lo permiten.

---

## 24. Historia clínica y consultas (`CU-22`, `CU-23`)

- Acceso clínico exclusivamente cuando exista autorización y relación asistencial; no exponer historias ajenas en tablas administrativas.
- Cada cita tiene como máximo **una consulta**; una consulta puede originar varias solicitudes de laboratorio.
- Registrar o actualizar una consulta no cambia automáticamente a `Atendida`.
- El Médico responsable puede registrar, completar o corregir antecedentes durante una atención real y autorizada por CU-23, y completar/corregir información de atenciones previas conforme a las reglas aprobadas. Consultar primero los antecedentes existentes y enviar solo modificaciones permitidas; Laravel valida cuenta, rol y relación asistencial y conserva versiones, responsables y fechas. No crear consultas ficticias ni marcar Atendida automáticamente al guardar antecedentes.
- CU-22 permanece de consulta para Médico y Paciente dentro de sus permisos. La edición clínica de CU-23 no se ofrece a Administrador, Recepción o Paciente; la bitácora general solo contiene referencias y metadatos seguros, sin contenido de antecedentes.
- Mostrar antecedentes, diagnósticos y observaciones solo a usuarios autorizados por la API; no usar estado de frontend para conceder acceso.
- Formularios médicos deben evitar filtraciones en errores, mensajes, consola, analytics y persistencia del navegador.
- No hacer copia de seguridad local de datos clínicos en `localStorage` o `sessionStorage`.

---

## 25. Laboratorio digital y documentos protegidos (`CU-24` a `CU-27`)

- El Médico solicita estudios y revisa los resultados correspondientes.
- El Administrador incorpora el PDF o imagen emitido externamente y puede corregir asociaciones o documentos devueltos, sin alterar interpretación médica.
- Únicamente el Médico solicitante revisa, devuelve para corrección y autoriza publicar su resultado.
- Estados internos: **Pendiente**, **En revisión**, **Disponible**; no convertirlos en estados de cita.
- Un documento correcto se publica directamente tras autorización; un documento incorrecto sigue el circuito de devolución, corrección y nueva revisión.
- El Paciente ve **Pendiente** hasta que esté **Disponible**, sin acceso al archivo En revisión.
- La descarga y vista previa deben consumirse desde endpoints protegidos con nueva comprobación de autorización; **no construir ni exponer URL pública de almacenamiento**, ni confiar en enlaces de archivos previsibles.
- Para descargas autorizadas, manejar respuestas binarias (`blob`) de manera controlada, nombres de archivo seguros y liberar URLs temporales creadas por el navegador cuando ya no se usen.
- Validar en el cliente formato, tamaño y selección de archivo como apoyo; la aceptación definitiva corresponde a Laravel.
- Nunca almacenar PDF, imágenes o datos de pacientes en caches persistentes no autorizadas.

---

## 26. Recordatorios internos (`CU-28`)

- Mostrar únicamente avisos propios del Paciente, procedentes de la API.
- El backend genera recordatorios automáticamente, **aunque el Paciente no esté conectado**.
- El proyecto establece un aviso **24 horas antes de la fecha y hora programadas** de la cita. Para reservas registradas exitosamente con menos de 24 horas de anticipación, el backend lo genera después de guardar correctamente la reserva en la base de datos o en la siguiente ejecución del programador de tareas, siempre que el aviso siga siendo válido y el intervalo no haya finalizado. La frecuencia del programador se definirá durante el diseño técnico; la interfaz no debe asumirla.
- La generación es independiente del estado `Confirmada` y no modifica la cita: las nuevas reservas permanecen inicialmente `Programadas`; las `Confirmadas` pueden conservar avisos vigentes. `Confirmada` significa exclusivamente llegada presencial verificada por Recepción, no guardado exitoso de una reserva.
- Los avisos son internos y persistidos en PostgreSQL, incluso sin cuenta web, asociados al Paciente y la cita. Tras activar su cuenta, el Paciente consulta solo avisos propios autorizados de citas futuras vigentes; la generación no requiere acciones manuales de Paciente o Recepción.
- Reprogramaciones recalculan el momento del aviso y actualizan o invalidan fecha, hora y consultorio, evitando duplicados. Canceladas, Atendidas, Inasistencia o intervalo finalizado impiden nuevos avisos de próxima atención y retiran los anteriores de próximos recordatorios, según la respuesta del backend.
- No crear recordatorios desde el navegador con `setTimeout` como fuente de verdad ni depender de la pestaña abierta.
- No enviar recordatorios mediante Brevo, email, WhatsApp, SMS ni push externo. **Brevo solo se usa en correos transaccionales de seguridad**.
- Mostrar estados vacíos o sin próximos avisos de forma comprensible, sin mensajes engañosos.

---

## 27. Reportes y bitácora (`CU-29`, `CU-30`)

**Reportes**:

- Consultar información autorizada por los filtros y períodos de `RF13`.
- Preferir resultados, totales y paginación calculados por el servidor.
- No recalcular en React cifras administrativas decisivas que ya determina Laravel.
- Si se implementa la exportación CSV/PDF opcional, consumir una descarga autorizada y respetar filtros de acceso.

**Bitácora**:

- Solo el **Administrador** tiene la pantalla de consulta `CU-30`.
- Mostrar eventos en orden descendente, filtros y paginación que llegan desde el servidor.
- La pantalla es de **solo lectura**: no implementar editar ni eliminar eventos.
- No mostrar secretos, códigos, hashes, tokens, documentos ni contenido clínico innecesario.
- La bitácora no autoriza al Administrador a realizar operaciones clínicas.

---

## 28. CSS, diseño responsive, accesibilidad y usabilidad

- Conservar `src/styles/` para estilos globales y ubicar estilos específicos junto al módulo o componente según la convención acordada.
- Mantener una identidad visual coherente y una interfaz clara para los cuatro roles.
- Priorizar formularios accesibles con etiquetas visibles, controles por teclado, foco perceptible, mensajes legibles y semántica HTML correcta.
- Mantener contraste suficiente y no comunicar errores o estados **solo mediante color**.
- Las tablas de citas, pacientes y reportes deben adaptarse a pantallas pequeñas sin ocultar de forma irrecuperable datos importantes.
- Dar confirmación visual antes de cambios delicados (desactivar cuenta, cancelar una cita, publicar un resultado), sin sustituir la autorización del servidor.
- No instalar librerías completas de UI/CSS por preferencia estética sin justificación.
- Evitar textos y componentes confusos: «Confirmada» significa llegada verificada por Recepción, no mera reserva confirmada por correo.

---

## 29. Fechas, horarios, formatos y datos mostrados

- Mostrar fechas y horas de las citas **según la zona horaria local de la sede clínica** y el contrato definido en la API.
- No transformar silenciosamente una fecha local en otra por una conversión UTC o `toISOString()` usada sin contexto.
- Verificar el formato de fecha/hora enviado o recibido antes de implementar utilidades de conversión.
- Mantener consistentes duración, horario, consultorio, médico y especialidad entre tablas, detalles y confirmaciones.
- No mostrar datos antiguos como vigentes después de guardar, reprogramar o cancelar; refrescar la información autorizada.
- No derivar permisos ni estados finales únicamente de etiquetas de interfaz.

---

## 30. Carga, estados vacíos, paginación y errores de red

Cada pantalla que consulta la API debe manejar, cuando corresponda:

1. Estado inicial y carga.
2. Datos recibidos.
3. Lista vacía o ausencia legítima de datos.
4. Errores esperados y errores inesperados.
5. Transiciones de guardado y confirmación.
6. Filtros, orden y paginación del backend.

- Evitar que un doble clic registre dos operaciones.
- No usar resultados desactualizados para autorizar una operación; el backend debe revalidar siempre.
- En peticiones interrumpidas, no afirmar que hubo rollback ni que la operación falló definitivamente sin comprobar.
- Preservar filtros razonablemente tras un `409` o `422` para que el usuario pueda corregir o volver a consultar.
- No exponer respuestas HTTP completas en pantalla ni imprimir datos privados en la consola.

---

## 31. Pruebas del frontend y coordinación con backend

Antes de cerrar un CU o grupo de CU:

- Verificar pantallas públicas y protegidas según los cuatro roles.
- Probar recuperación de sesión, login/logout y respuestas `401`, `403` y `419`.
- Probar errores de validación `422`, conflictos `409`, rate limits `429` y fallos de conexión.
- Probar registro nuevo y activación existente, mostrando correctamente ambos códigos, vencimientos y reenvíos según respuestas del backend.
- Probar que el Paciente no tenga acciones de cancelación/reprogramación y que Recepción no tenga acción de marcar Atendida.
- Probar resultados de disponibilidad que cambian antes de confirmar una reserva.
- Probar descargas protegidas de laboratorio y rechazo visual de documentos no publicados.
- Probar avisos internos actualizados tras cambios de citas.
- Probar responsive, navegación por teclado y mensajes comprensibles.
- No usar pacientes reales ni resultados médicos verdaderos en pruebas, capturas o datos de ejemplo.

Si existen herramientas de pruebas (por ejemplo, Vitest y React Testing Library), utilizarlas; añadirlas solo si se necesita y está aprobado. Preferir pruebas de comportamiento que puedan simular API/errores y, cuando haya integración disponible, comprobar contratos reales con Laravel.

No afirmar que una prueba pasó si no fue ejecutada.

---

## 32. Documentación progresiva de `/docs/ai/`

La documentación del análisis evoluciona **con el desarrollo del software**, conforme a `/AGENTS.md`:

**Al completar un caso de uso o un pequeño grupo relacionado:**

- Actualizar `/docs/ai/analisis-clases.md` con las clases participantes, responsabilidades, colaboraciones y flujos realmente diseñados/implementados. Mencionar componentes de frontera o UI si son relevantes para el análisis, **sin inventarlos como clases de dominio PHP**.
- Revisar si cambiaron contratos de API, casos de uso o reglas aprobadas; sincronizar su documentación correspondiente sin alterar RF/RN oficiales.
- Comunicar al backend cualquier cambio funcional que afecte modelos, tablas o relaciones. El DER se actualiza **según las modificaciones reales de PostgreSQL y Eloquent**, no por cambios meramente visuales de React.

**Al completar un módulo:**

- Actualizar `/docs/ai/diagrama-clases.md` como **diagrama global acumulativo**; integrar las clases del módulo con las ya existentes y reflejar dependencias pertinentes de frontend/backend solo cuando el alcance del diagrama lo requiera.
- Revisar que `analisis-clases.md` tenga los CU terminados del módulo.
- Mantener los diagramas editables en Markdown y distinguir elementos previstos de implementados.
- Actualizar `/docs/ai/contexto-maestro.md` si cambió el avance real o una decisión aprobada.

**Si los archivos todavía no existen:** no afirmar que se actualizaron. Informar su ausencia; crearlos cuando la tarea incluya iniciar esa documentación a partir de fuentes aprobadas. No bloquear una corrección visual pequeña por un documento inexistente no necesario para ella.

---

## 33. Dependencias, convenciones y Git

- Antes de modificar código, leer archivos afectados y mantener convenciones de nombres, imports y estilo del módulo.
- Usar `pnpm` si es el gestor confirmado por `package.json`/lockfile; no mezclar gestores arbitrariamente.
- Evitar dependencias circulares, imports innecesarios, abstracciones sin uso y estado global injustificado.
- No cambiar nombres de funciones, rutas o props públicas sin revisar sus consumidores.
- No guardar secretos, cookies, tokens ni datos reales de pacientes en archivos versionados o ejemplos.
- No crear ramas, hacer `commit`, `push`, despliegues ni operaciones destructivas sin petición explícita.
- No añadir pantallas, funcionalidades, librerías o capas solo por supuestas «buenas prácticas».

---

## 34. Protocolo de trabajo antes, durante y después

**Antes de implementar:**

1. Leer la tarea y el alcance del CU.
2. Consultar `/AGENTS.md` y este documento.
3. Revisar los `docs/ai/` pertinentes disponibles y el examen cuando corresponda.
4. Inspeccionar el código real de la feature, Router, Service y `api.js`.
5. Confirmar endpoint, contrato JSON, autenticación, rol y titularidad necesarios.
6. Verificar componentes reutilizables y evitar duplicación.
7. Preguntar si una regla funcional esencial no está decidida.

**Durante:**

1. Implementar en `features/<modulo>/`, respetando la separación Page/Component/Service/API.
2. Validar campos para experiencia de usuario; dejar seguridad y reglas al backend.
3. Manejar carga, errores, sesiones y accesibilidad.
4. Mantener datos sensibles protegidos y no afirmar éxitos no confirmados.
5. Agregar pruebas del comportamiento relevante.

**Después:**

1. Ejecutar las verificaciones disponibles (`lint`, `build`, pruebas), según scripts instalados.
2. Revisar permisos visuales, manejo de `401/403/409/419/422/429`, estados y navegación.
3. Actualizar `analisis-clases.md` cuando se cierre el CU o grupo y `diagrama-clases.md` al completar módulo; coordinar DER si cambió persistencia.
4. Comprobar que los documentos representan lo realmente implementado.
5. Informar archivos cambiados, pruebas ejecutadas, documentación actualizada y pendientes.

---

## 35. Regla final

El frontend de Clínica Vida Salud debe ser:

- **coherente con el examen** y los RF/RN oficiales;
- **fiel a los 30 casos de uso** y a sus decisiones aprobadas;
- **organizado como el frontend de referencia**, con React Feature-Based + Component-Based;
- **compatible con el backend Laravel por capas** y sus contratos de peticiones/errores;
- **seguro con datos médicos** y cuidadoso con autenticación, permisos y descargas;
- **responsive, accesible y mantenible**;
- **documentado de forma progresiva**, caso de uso por caso de uso y módulo por módulo;
- **simple, sin sobrediseño ni funcionalidades inventadas**.

Ante varias soluciones válidas, preferir la de menor complejidad que cumpla estas condiciones.
