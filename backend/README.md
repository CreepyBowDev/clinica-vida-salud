# Backend — Clínica Vida Salud

## Estado de esta fase

**Usuarios y autenticación — fase 8, backend cerrado:** CU-01–CU-06 implementados y revisados de forma integrada, incluidos acceso normal/restringido, administración de cuentas, ambos escenarios de registro/vinculación y credenciales. La emisión de Recepción cubre la parte de CU-07 requerida por CU-04. Análisis vigente en [`../docs/ai/analisis-clases.md`](../docs/ai/analisis-clases.md) y cierre en contexto §17.26/último apartado de este README. **`diagrama-clases.md` aplazado expresamente por el usuario.** React/AUT-21, entrega real de Brevo y CRUD administrativo restante de CU-07 siguen pendientes. Brevo HTTP está integrado y configurado privadamente; sus pruebas usan red simulada.

La población posterior mediante seeders está aplicada en desarrollo: catálogos/consultorios, conjunto ficticio de demostración y Administrador inicial activo por excepción de instalación. Su estado y comandos se describen en el último apartado; los estados anteriores son registros históricos.

Preparación local inicial verificada el **18 de octubre de 2026**. Las **fases 0–7 de datos**, completadas el **19 de octubre de 2026**, se registran al final de este documento y en `../docs/ai/diagrama-entidad-relacion.md`, §6.6–§6.13. Desarrollo contiene las **28 tablas de dominio, 239 columnas y 43 FK** del DER, además de `migrations` y dos exclusiones de citas. **Ningún CU-01 a CU-30 está implementado por este trabajo.**

| Componente | Versión comprobada | Preparación realizada |
| --- | --- | --- |
| PHP CLI | 8.5.11 | Instalación existente en `C:\php`; extensiones habilitadas |
| Composer | 2.10.3 | Instalado en el perfil del usuario y agregado a su `PATH` |
| Esqueleto `laravel/laravel` | 13.11.0 | Descargado a una carpeta temporal e integrado selectivamente |
| Laravel Framework | 13.37.0 | Instalado y fijado en `composer.lock` |
| Laravel Sanctum | 4.3.3 | Instalado; cookies, CSRF y middleware stateful preparados |
| PostgreSQL | 18.6 | Instalación existente; servicio comprobado en ejecución |
| PHPUnit | 12.5.40 | Pruebas de infraestructura |
| Laravel Pint | 1.32.1 | Comprobación de estilo |

Se instalaron **105 paquetes Composer**, incluidas dependencias de desarrollo. `vendor/` se genera localmente y está excluido de Git.

## Entorno local

- Backend: `http://localhost:8000`.
- Frontend futuro: `http://localhost:5173`.
- PostgreSQL: `localhost:5432`, base `clinica_vida_salud`.
- Zona horaria: `America/La_Paz`; locale de la aplicación: `es`.
- PHP habilitado: `curl`, `fileinfo`, `mbstring`, `openssl`, `pdo_pgsql` y `zip`; directorio de extensiones `C:/php/ext`.
- La validación HTTPS del instalador y de Composer funcionó sin desactivar TLS ni la comprobación de certificados.

### Arranque habitual

Desde `backend/`, en una terminal nueva:

```powershell
composer install
php artisan serve --host=localhost --port=8000
```

También se puede usar `composer dev`. Si la terminal todavía no reconoce Composer después de actualizar el `PATH`, abrir otra o invocar:

```powershell
php "$env:LOCALAPPDATA\Programs\Composer\bin\composer.phar" --version
```

La comprobación de arranque está en `http://localhost:8000/up`. La raíz `/` no tiene una interfaz funcional; React se preparará por separado.

El archivo `.env` local ya contiene la clave de aplicación generada y la credencial técnica de PostgreSQL. **No regenerar la clave como paso habitual de arranque.** En otra instalación, copiar `.env.example`, completar sus credenciales locales y ejecutar `php artisan key:generate` una sola vez. `composer setup` agrupa instalación, copia inicial del entorno y generación de clave; está destinado a una preparación inicial.

En una instalación nueva, ejecutar después `php artisan migrate` y `composer seed` antes de atender peticiones: el driver de sesión actual requiere la tabla `sessions`. En el entorno local existente esas migraciones y catálogos ya están aplicados.

### Base de datos y secretos

Se comprobó primero la existencia de la base. Al no existir, se creó `clinica_vida_salud` en UTF-8, con propietario técnico `clinica_vida_salud_app`. El rol tiene login, sin privilegios de superusuario, creación de bases, creación de roles ni replicación. No es un Usuario del dominio clínico.

La contraseña del rol técnico se generó aleatoriamente y quedó únicamente en `.env`. El acceso administrativo utilizado para preparar la base se obtuvo del archivo local `%APPDATA%\postgresql\pgpass.conf`, protegido para el usuario de Windows. No se incorporaron credenciales administrativas a la configuración de Laravel.

**Resultado:** conexión PDO y conexión desde Laravel correctas, **cero tablas de aplicación**, cero migraciones propias. Se compararon nombre, propietario, codificación y locale del catálogo de las otras bases antes y después, sin diferencias. No se modificaron otras bases.

`.env`, variantes privadas del entorno, `vendor/`, cachés generadas, sesiones y logs están excluidos de Git. `.env.example` conserva vacíos `APP_KEY` y `DB_PASSWORD`.

## Integración con la arquitectura acordada

- `composer.json` registra `Clinica\` → `src/`, con las cuatro capas en minúsculas y exige `ext-pdo`/`ext-pdo_pgsql`.
- `bootstrap/app.php` registra las entradas HTTP de Laravel y habilita `statefulApi()`.
- `routes/api.php` carga explícitamente `src/presentation/routes/auth.php`, `personal.php`, `usuarios.php` y `pacientes.php`, donde se declaran las rutas funcionales. Laravel aplica el prefijo `/api` y el grupo `api` desde el registro de `bootstrap/app.php`.
- `app/Providers/AppServiceProvider.php` registra `src/data/database/migrations/` mediante `loadMigrationsFrom`.
- Se conservaron los 19 archivos originales de `backend/`, incluidos `AGENTS.md`, `prueba.php` y los archivos de `src/`; se compararon sus hashes SHA-256.

Los archivos de `app/`, `bootstrap/`, `config/` y `routes/` son infraestructura del framework. Al desarrollar los CU, presentación adaptará HTTP, servicios aplicarán reglas y transacciones, repositorios accederán a Eloquent y modelos representarán las entidades aprobadas. Esta fase no agrega esas clases de negocio.

### Organización modular de rutas

```text
routes/
├── api.php                       # Carga central: auth → personal → usuarios → pacientes
├── web.php
└── console.php
src/presentation/routes/
├── auth.php                      # Acceso, contraseñas, registro y vinculación de Paciente
├── personal.php                  # Alta, reenvío y aceptación de invitaciones
├── usuarios.php                  # Consulta administrativa, estado, rol y temporal
└── pacientes.php                 # Emisión de código presencial; resto de CU-07 posterior
```

`routes/api.php` incluye cada archivo una sola vez, sin añadir otro prefijo `/api` ni repetir el grupo de middleware `api`. Cada archivo funcional conserva sus grupos, middlewares y restricciones. La aceptación pública de invitaciones está en `personal.php`, con la misma URL `/api/auth/invitaciones/aceptar`, fuera del grupo exclusivo del Administrador. El antiguo `src/presentation/routes/api.php` fue sustituido inicialmente por tres archivos; fase 7B agrega `pacientes.php` exclusivamente para la emisión autorizada de código de Recepción.

La comparación histórica antes/después del traslado verificó 13 declaraciones sin duplicados: cuatro en auth, tres en personal, cuatro en usuarios y dos de infraestructura (`/up` y `/sanctum/csrf-cookie`). Se preservaron métodos (incluido HEAD), URL, nombres, dominios, prefijos, acciones, orden de registro, middleware declarado/resuelto, parámetros, restricciones y valores predeterminados. También se comprobaron hashes idénticos de 106 archivos de lógica/infraestructura ajenos al traslado. Evidencia de regresión en contexto §17.20. La fase 5 añadió dos rutas de recuperación a auth.php, alcanzando entonces 15 declaraciones; el registro posterior se documenta en los cierres de cada fase.

Regresión posterior a la reorganización: **158 pruebas y 2062 aserciones correctas** (50,39 s) de autenticación, cambio de contraseña, invitaciones, cuentas y concurrencia; PostgreSQL aislado y correo simulado. Cambio exclusivamente estructural, sin clases, dependencias o funcionalidades nuevas.

El esqueleto se obtuvo con `--no-install --no-scripts`. Se integraron únicamente los archivos necesarios; no se copiaron sus instrucciones de agentes, modelo `User`, factories, seeders, migraciones de usuarios/cache/jobs, aplicación Vite ni pantalla Blade inicial. Se retiraron los scripts que creaban SQLite o ejecutaban migraciones y npm. **No se ejecutó `migrate`, `install:api`, `db:seed` ni publicación de migraciones de Sanctum.**

## Sanctum, CORS y sesiones

- CORS permite el origen exacto `http://localhost:5173`, con credenciales. Rutas cubiertas: `api/*` y `sanctum/csrf-cookie`.
- Dominios stateful: `localhost:5173,localhost:8000`.
- `GET /sanctum/csrf-cookie` emite la cookie de sesión y `XSRF-TOKEN`; esta última es legible por JavaScript para el mecanismo CSRF de Laravel.
- Sesión `clinica_vida_salud_session`: cookie HttpOnly, SameSite=Lax y host-only (`SESSION_DOMAIN=null`). `Secure=false` corresponde al HTTP local aprobado; HTTPS y `Secure=true` deberán configurarse para producción.
- En la instalación inicial las sesiones eran temporales en archivo; **desde fase 2 usan la tabla nativa `sessions`, con contenido cifrado**. Caché en archivo, cola síncrona y mailer `array` en memoria se conservan.
- El disco local apunta a `storage/app/private`, sin rutas automáticas de entrega/subida de archivos ni enlace público creado.

**Antecedente de fase 2 de datos:** se registraron `Usuario`, guard `web`, proveedor Eloquent `usuarios` y Sanctum; se comprobaron sesiones anónimas y vinculadas. La implementación posterior de **fase 1 de autenticación**, al final de este README, integra AuthenticateSession, login/me/logout, bloqueo y controles transversales. La recuperación, emisión de tokens y administración de cuentas permanecen en sus fases funcionales; no hay un broker paralelo de recuperación.

El futuro cliente Axios deberá usar credenciales, obtener primero la cookie CSRF y enviar la cabecera XSRF correspondiente. No se ha creado ni probado todavía ese frontend.

## Comandos de preparación ejecutados

Las rutas temporales de esta máquina se agrupan en:

```powershell
$stage = "$env:LOCALAPPDATA\Temp\opencode\clinica-laravel13-setup"
$composer = "$env:LOCALAPPDATA\Programs\Composer\bin\composer.phar"
```

1. Se inspeccionaron `git status --short`, herramientas PHP/Composer, servicio PostgreSQL, estructura del backend y `C:\php\php.ini`.
2. Se respaldó `php.ini` en `%LOCALAPPDATA%\Temp\opencode\clinica-php-ini-before-laravel13.ini`; se editaron extensiones, su directorio y zona horaria. Se ejecutó `php -m` para comprobarlas.
3. Se descargó el instalador de `getcomposer.org/installer` y su firma desde `composer.github.io/installer.sig`; se verificó SHA-384 antes de ejecutarlo:

```powershell
php "$stage\composer-setup.php" --install-dir="$env:LOCALAPPDATA\Programs\Composer\bin" --filename=composer.phar
php $composer --version
php $composer create-project 'laravel/laravel:^13.0' "$stage\skeleton" --no-install --no-scripts --no-interaction --prefer-dist
```

4. Se copió selectivamente la infraestructura, comprobando que cada destino estuviera libre. Se adaptaron scripts, autoload y configuración; se instaló desde el `composer.json` ya controlado:

```powershell
php $composer update --no-scripts --no-interaction --prefer-dist
php artisan package:discover --ansi
php artisan key:generate --ansi
php artisan vendor:publish --tag=sanctum-config --no-interaction
php $composer dump-autoload --strict-psr --no-interaction
php $composer update --lock --no-scripts --no-interaction
```

5. Se abrió el auxiliar local `guardar-pgpass.ps1` en PowerShell para la entrada oculta de la contraseña administrativa. Se verificó acceso con `psql -X -w -h localhost -p 5432 -U postgres -d postgres`. Los auxiliares temporales, sin contraseñas incrustadas, prepararon la base y verificaron Laravel:

```powershell
php "$stage\preparar-postgres.php" (Get-Location).Path
php "$stage\verificar-laravel.php" (Get-Location).Path
```

`preparar-postgres.php` consultó el catálogo y ejecutó únicamente `CREATE ROLE` y `CREATE DATABASE` al no existir los objetos correspondientes. No ejecutó DDL de tablas. Estos auxiliares son herramientas locales de esta preparación, no comandos de negocio ni parte de la aplicación versionada.

## Verificaciones y resultados finales

```powershell
php $composer diagnose
php $composer validate --strict
php $composer check-platform-reqs
php $composer audit
php artisan --version
php artisan about --only=environment,cache,drivers
php artisan route:list -v
php artisan test
php vendor/bin/pint --test app bootstrap/app.php bootstrap/providers.php config public routes src tests
git diff --check
```

| Verificación | Resultado |
| --- | --- |
| Composer: diagnóstico, manifiesto, lock y requisitos | Correctos; HTTPS operativo y requisitos de plataforma satisfechos |
| Auditoría Composer | Sin avisos de vulnerabilidades conocidos al comprobar |
| Autoload PSR-4 estricto | Correcto; prefijo `Clinica\` registrado |
| Migraciones | Ruta personalizada registrada; cero archivos de migración; ninguna ejecutada |
| Laravel → PostgreSQL | Conexión correcta a `clinica_vida_salud` con su rol técnico; cero tablas |
| PHPUnit | **4 pruebas, 23 aserciones, todas correctas** |
| Sintaxis PHP | **23 archivos**, sin errores, mediante `php -l` |
| Estilo Pint | **22 archivos de fuente**, correcto; cachés generadas excluidas de la comprobación |
| HTTP real, servidor Artisan | `/up`: **200**; cookie CSRF: **204**; preflight: **204** |
| Cookies/CORS | Cookie de sesión HttpOnly y XSRF presentes; credenciales y origen aprobado correctos; origen ajeno no autorizado por CORS |
| Preservación | 19 archivos originales con SHA-256 idéntico |
| Exclusiones Git | Entorno local, dependencias, caché y logs ignorados |

Las pruebas de `tests/Feature/InfrastructureTest.php` cubren salud, cookies, CORS y CSRF. El middleware real devuelve **419** ante un POST stateful sin token y **204** con token válido en una **ruta creada únicamente durante la prueba**. La aplicación normal solo registra `/up` y `/sanctum/csrf-cookie`.

En la preparación inicial, `phpunit.xml` reservó el nombre `clinica_vida_salud_test`, pero todavía no existían su base ni su rol; las cuatro pruebas iniciales no accedían a PostgreSQL. **La fase 0 descrita a continuación completa ese entorno y agrega pruebas reales de conexión y DDL.**

## Próximos pasos pendientes — registro de la preparación inicial

Este apartado conserva los pendientes de aquella preparación; el estado vigente de Usuarios/autenticación es el cierre de fase 8 al final del README.

- Administrador de instalación creado y comprobado por excepción aprobada; implementar las invitaciones/verificación ordinarias para las cuentas posteriores del personal.
- Iniciar los flujos funcionales sobre la persistencia preparada, priorizando usuarios y autenticación.
- Implementar autenticación, autorización y revocación de sesiones conforme a los CU aprobados.
- Preparar React/Axios, Brevo y cualquier infraestructura persistente cuando se aborde su alcance.
- Definir el programador de tareas y sus procesos cuando corresponda a CU-28.
- Configurar y verificar producción por separado.

Se actualizó `../docs/ai/contexto-maestro.md` con la preparación inicial. Actualmente el DER está disponible y sus decisiones físicas de fase 0 figuran en §6.6. `analisis-clases.md` y `diagrama-clases.md` siguen pendientes de elaboración progresiva al desarrollar los CU y módulos. No se declara cerrado ningún CU ni módulo.

## Fase 0 de datos — 2026-10-19

Registro del estado al cerrar fase 0; la implementación posterior de catálogos y configuración figura en el apartado de fase 1.

### Diseño e integración

- El DER registra los **28 nombres físicos de tablas**, criterios comunes de tipos, identificadores, FK, índices y timestamps, y los tipos/defaults/restricciones del primer bloque de cinco entidades. El diccionario lógico conserva las **28 entidades, 239 atributos y 43 relaciones**.
- Las decisiones concretas de longitudes y concurrencia de los bloques restantes se resolverán antes de sus migraciones. Continúan pendientes el retiro de habilitación de un par Médico–Especialidad y cualquier procedimiento posterior a publicación de laboratorio.
- Se comprobó el registro existente de migraciones en `src/data/database/migrations/` y el autoload `Clinica\` → `src/` con Composer estricto.
- `config/database.php` fija la zona de la conexión PostgreSQL a `UTC`; la aplicación y las reglas locales conservan `America/La_Paz`. Los instantes se enviarán con zona y precisión, según el DER.
- Se añadió el punto de entrada `src/data/database/seeders/DatabaseSeeder.php`. Aún no carga registros; coordinará los seeders de las fases siguientes.

Comandos del proyecto, desde `backend/`:

```powershell
php artisan make:migration <nombre> --path=src/data/database/migrations
php artisan migrate
composer seed
```

`composer seed` ejecuta `php artisan db:seed --class="Clinica\data\database\seeders\DatabaseSeeder"`. Laravel conserva su namespace predeterminado para `db:seed` sin clase; utilizar la invocación configurada. Las migraciones de negocio se crearán a partir de fase 1.

### PostgreSQL aislado para pruebas

Se creó `clinica_vida_salud_test` en UTF-8 con propietario `clinica_vida_salud_test`, independiente de `clinica_vida_salud_app`. El rol no tiene superusuario, creación de bases/roles, replicación, bypass RLS ni membresías; el acceso público a la base está revocado. La contraseña y una clave de aplicación independientes se generaron aleatoriamente y quedaron únicamente en `.env.testing`, excluido de Git.

Para reproducir la preparación en **otra instalación**:

1. Comprobar primero si ya existen la base y el rol; conservar cualquier entorno existente y verificar propietario/credenciales antes de cambiarlo.
2. Con una sesión administrativa de `psql`, crear los objetos que falten. Establecer la contraseña con `\password`, que solicita entrada oculta:

```sql
CREATE ROLE clinica_vida_salud_test LOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
\password clinica_vida_salud_test
CREATE DATABASE clinica_vida_salud_test OWNER clinica_vida_salud_test ENCODING 'UTF8' TEMPLATE template0;
REVOKE ALL ON DATABASE clinica_vida_salud_test FROM PUBLIC;
\connect clinica_vida_salud_test
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO clinica_vida_salud_test;
```

3. Copiar `.env.testing.example` a `.env.testing`, completar la credencial de pruebas y generar su clave una sola vez:

```powershell
Copy-Item .env.testing.example .env.testing
php artisan config:clear
php artisan key:generate --env=testing
```

No repetir la copia o generación de clave sobre el entorno local ya preparado. El ejemplo versionado mantiene vacíos `APP_KEY` y `DB_PASSWORD`.

### Verificación y ejecución de pruebas

```powershell
composer test -- --compact
composer seed -- --env=testing
```

- PHPUnit fuerza el entorno `testing`, PostgreSQL y el nombre de la base aislada; obtiene usuario/contraseña de `.env.testing`.
- `Tests\TestCase` rechaza configuración cacheada o una conexión con base, usuario o URL incompatibles **antes de ejecutar los hooks de pruebas de Laravel**. `composer test` limpia previamente la caché de configuración.
- `tests/Feature/DatabaseInfrastructureTest.php` verifica conexión real, propietario, privilegios mínimos, zona UTC, descubrimiento de la ruta de migraciones, ejecución Artisan y resolución del seeder raíz.
- La única migración auxiliar está en `tests/Fixtures/database/migrations/`; la prueba registra esa ruta temporalmente. Crea una tabla auxiliar, registra la migración y escribe un instante con zona y microsegundos **dentro de una transacción que se revierte**. Las futuras migraciones del dominio permanecerán en `src/`.
- Resultado: **6 pruebas y 39 aserciones correctas**; la comprobación negativa manual confirmó que el usuario de desarrollo es rechazado. Pint y autoload PSR-4 estrictos correctos.
- La base de desarrollo conserva **cero tablas**; la base de pruebas queda también sin tablas persistidas tras verificar el DDL. El catálogo de las otras bases se conservó.

La preparación local de la base se realizó con un auxiliar temporal sin credenciales incrustadas; la comprobación automatizada reproducible está en los tests del repositorio. No se han verificado todavía las carreras de reservas, activaciones, versiones o publicaciones: corresponden al desarrollo de sus fases y CU.

## Fase 1 de datos — 2026-10-19

### Implementación y estado de PostgreSQL

- **Cinco migraciones**, en `src/data/database/migrations/`, crean `roles`, `controles_intentos`, `consultorios`, `especialidades` y `configuracion_clinica`, con las **22 columnas** del primer bloque del DER.
- **Cinco modelos**, en `src/data/models/`, configuran los nombres de tabla/PK, asignación masiva limitada, casts y timestamps propios. No se anticipan relaciones con modelos de otras fases.
- Las PK, unicidades y CHECK tienen nombres explícitos; la especialidad usa un índice único sobre `lower(btrim(nombre))`. Se conservan tildes y presentación. Configuración admite solo ID 1, zona America/La_Paz y duración positiva.
- El seeder raíz coordina transaccionalmente `RolSeeder`, `EspecialidadSeeder` y `ConfiguracionClinicaSeeder`. La carga inserta los registros ausentes y conserva los existentes, también si están inactivos o tienen configuración modificada.

Se inspeccionó la base de desarrollo antes de aplicar las migraciones: estaba vacía. Se ejecutaron `php artisan migrate --no-interaction` y `composer seed -- --no-interaction`. Las **cinco migraciones quedaron aplicadas en el lote 1**, junto con el repositorio de infraestructura `migrations`.

| Tabla de dominio | Filas locales |
|---|---:|
| `roles` | 4 |
| `especialidades` | 3 |
| `configuracion_clinica` | 1 |
| `consultorios` | 0 |
| `controles_intentos` | 0 |

Los catálogos contienen exclusivamente los roles y especialidades iniciales aprobados. La configuración inicia con Clínica Vida Salud, duración 15 minutos y dirección NULL. Los IDs de roles/especialidades se generan en PostgreSQL; no constituyen constantes funcionales. Repetir la carga puede avanzar las secuencias, pero no cambia los IDs existentes ni duplica filas.

### Pruebas y reproducibilidad

```powershell
composer test -- --compact
php artisan migrate:status
composer seed
```

Resultado: **33 pruebas, 166 aserciones correctas**, incluyendo `tests/Feature/CatalogosInicialesTest.php`. Se verificaron restricciones mediante SQL directo, defaults, normalización con tildes, modelos/timestamps, idempotencia que preserva cambios previos y rollback completo de la carga ante un fallo provocado. También se comprobó la reversibilidad y reaplicación de migraciones en el entorno aislado.

Las pruebas de catálogos y la prueba de infraestructura ejecutan DDL dentro de transacciones revertidas; la base de pruebas queda sin tablas persistidas. No se ejecutaron `migrate:fresh`, borrados ni rollback sobre la base de desarrollo. La reejecución del seeder sobre desarrollo conservó las filas e identificadores.

Autoload PSR-4 estricto, estilo Pint y comparación del DER correctos. La documentación mantiene las 28 entidades, 239 atributos y 43 relaciones del diseño global; este primer bloque implementa cinco entidades sin FK. Los detalles de restricciones y clases están en el DER §6.7 y el avance en `contexto-maestro.md` §17.4.

Esta fase completa el bloque de persistencia inicial. Las operaciones administrativas, autenticación, permisos, APIs y reglas transaccionales de los CU se desarrollarán en sus módulos. `analisis-clases.md` y `diagrama-clases.md` siguen pendientes de elaboración al completar CU y módulos.

## Fase 2 de datos — 2026-10-19

Se implementaron cuatro migraciones, aplicadas como **lote 2**, y los modelos `Usuario`, `TokenAcceso` y `Bitacora`. `Sesion` usa directamente el manejador nativo de Laravel. El modelo `Rol` incorpora su relación con usuarios. El detalle físico y sus límites funcionales se registran en el DER §6.8.

| Tabla | Persistencia implementada |
|---|---|
| `usuarios` | Correo único normalizado, rol con FK, tres estados, hash opcional solo en Pendiente, cuenta Activa con hash/verificación, contadores y timestamps propios |
| `sessions` | Seis columnas nativas, Usuario opcional, índices por Usuario/actividad y FK restrictiva |
| `tokens_acceso` | Dos propósitos, hash único, fechas de emisión/expiración/consumo/invalidación y FK de Usuario |
| `bitacora` | Dos actores separados, evento anterior, JSONB de tipo objeto, INET/UUID y triggers que rechazan UPDATE, DELETE, TRUNCATE y referencias adelantadas/cíclicas |

Las cuatro tablas se incorporaron sin carga inicial. La base local tiene ahora **9 tablas de dominio, 72 columnas y 6 FK**, más `migrations`. Los catálogos/configuración previos se compararon antes y después, sin cambios. No se crearon cuentas ni eventos ficticios en desarrollo.

### Sesión y proveedor de usuarios

`config/auth.php` mapea el proveedor Eloquent al modelo de `src/`, con `id_usuario` y `password_hash`; Sanctum consulta el guard `web`. La asignación masiva protege IDs, roles, hashes y campos de control. Las contraseñas y hashes de tokens se ocultan en la serialización; las respuestas públicas futuras tendrán sus Resources/DTO explícitos.

El driver de sesión local, su default y `.env.example` usan **database**, conservando el cifrado. Se comprobó con el kernel HTTP local que CSRF devuelve 204 y persiste una sesión anónima cifrada; solo se eliminó la sesión auxiliar creada para esa comprobación. PHPUnit continúa con `array` en general y activa `database` en las pruebas correspondientes.

Crear las tablas y configurar el proveedor no completa el login: todavía faltan validación de estados/bloqueo, autorización, verificación de nombres y vínculos por rol, consumo único/reemisión de tokens, envío Brevo, `AuthenticateSession` y revocación automática transaccional. La emisión real de eventos validará tipos, autor y listas permitidas de metadatos seguros; un JSON de tipo objeto no demuestra por sí solo ausencia de secretos o datos clínicos.

### Verificaciones

```powershell
composer test -- --compact
php artisan migrate:status
```

Resultado: **68 pruebas y 335 aserciones correctas**. `UsuariosAuditoriaTest` comprueba esquema, proveedor, normalización, protección de campos/secretos, relaciones, FK, hash de token único, actores y correlación, triggers de inmutabilidad y soporte nativo de sesiones cifradas, expiración y eliminación por Usuario. Las pruebas de migraciones anteriores se adaptaron al conjunto disponible, siempre dentro de transacciones de la base aislada.

Reversión/reaplicación de migraciones y funciones probadas solo en el entorno de pruebas, que termina sin tablas persistidas. Autoload PSR-4 estricto, Pint y sintaxis PHP correctos. El DER conserva 28 entidades, 239 atributos y 43 relaciones; se actualizaron este README, DER §6.8 y `contexto-maestro.md` §17.5. No se completó un CU ni módulo funcional y el primer Administrador continúa pendiente de su procedimiento seguro.

## Fase 3 de datos — 2026-10-19

Se incorporaron **seis migraciones** (`000010`–`000015`, lote 3) y seis modelos en `src/data/` para Paciente, ContactoEmergencia, PreferenciaPaciente, CodigoActivacionPaciente, RegistroPacientePendiente y VerificacionCorreo. Usuario incorpora sus relaciones con ficha, códigos emitidos y verificaciones. El diseño físico detallado está en el DER §6.9.

| Persistencia | Garantía implementada |
|---|---|
| Paciente | CI único cuando exista, varios NULL permitidos; código único y cuenta opcional única; actividad independiente de la cuenta |
| Contacto/preferencia | Cero o una fila de cada tipo por ficha; preferencias explícitas sin opciones funcionales predeterminadas |
| Código de Recepción | Hash único, FK a ficha y emisor, contador 0–5, fechas explícitas y vigencia básica |
| Registro pendiente | Dos tipos compatibles con sus FK, datos/hash requeridos mientras esté abierto y cuatro barreras de exclusividad |
| Verificación | XOR compatible con ActivacionPaciente/CambioCorreo, contador 0–5 y referencias a proceso o cuenta |

**Exclusividad de reservas:** los índices parciales sobre Paciente, código, CI y correo normalizados incluyen solo procesos sin completar ni invalidar. Un vencido conserva su reserva hasta cerrarse explícitamente. El futuro Service deberá bloquear/revalidar e invalidar/reanudar antes de iniciar otro proceso. No hay índices dependientes de `now()` ni nuevas columnas para estados temporales.

El CI se recorta únicamente en espacios U+0020 exteriores; conserva signos, letras, espacios interiores y ceros iniciales. Correo propuesto/destino siguen la normalización de Usuario. Los modelos protegen campos de control y vínculos, ocultan hashes/datos temporales y usan fechas inmutables con zona/microsegundos. El nacimiento se serializa como fecha, sin hora.

### Verificaciones y estado local

- **111 pruebas, 585 aserciones correctas**. `PacientesActivacionTest` cubre esquema, unicidades, relaciones, campos protegidos, datos requeridos, defaults, XOR, FK y rollback.
- `ReservaActivacionConcurrenteTest` comprueba las cuatro claves con **dos conexiones PostgreSQL independientes**: espera, rechazo tras confirmación y aceptación tras rollback. Usa un esquema auxiliar exclusivo de pruebas y lo elimina al terminar.
- Las pruebas anteriores siguen correctas; la reversión/reaplicación de las quince migraciones ocurre únicamente dentro de la base aislada. Esta termina sin tablas ni esquemas auxiliares persistidos.
- Desarrollo: **15 tablas de dominio, 128 columnas y 15 FK**, además de `migrations`. Las seis tablas nuevas están vacías; los registros de las nueve anteriores se compararon antes/después y se conservaron. No se agregaron seeders de este bloque.
- Autoload PSR-4 estricto, Pint, sintaxis PHP y comprobación de espacios del diff correctos. El DER lógico conserva 28 entidades, 239 atributos y 43 relaciones.

Se actualizaron este README, DER §6.9 y `contexto-maestro.md` §17.6. CU-04/CU-07/CU-08 continúan pendientes: requieren sus Services, validación/autorización, contexto protegido, emisión y consumo atómico de códigos, reintentos/envíos Brevo, limpieza de vencidos y coordinación con altas administrativas simultáneas. Las preferencias de privacidad aún no tienen claves funcionales definidas. `analisis-clases.md` y `diagrama-clases.md` siguen pendientes de los CU/módulos funcionales. La siguiente fase es médicos, especialidades del médico y horarios.

## Fase 4 de datos — 2026-10-19

Se incorporaron tres migraciones (`000016`–`000018`, **lote 4**) y los modelos `Medico`, `MedicoEspecialidad` y `HorarioAtencion`, en `src/data/`. Usuario, Consultorio y Especialidad incorporan sus relaciones con médicos. El diseño físico y las restricciones se detallan en el DER §6.10.

| Tabla | Persistencia implementada |
|---|---|
| `medicos` | Código único, cuenta opcional única, consultorio opcional compartible, datos profesionales obligatorios y actividad independiente de la cuenta |
| `medico_especialidad` | PK compuesta Médico–Especialidad y dos FK restrictivas; pivote Eloquent sin ID sustituto ni timestamps |
| `horarios_atencion` | Varios intervalos por Médico/día, día 1–7, horas locales TIME(0), fin posterior al inicio y actividad |

Se conservan las nulabilidades del DER: un Médico puede registrarse sin cuenta o consultorio; ofrecer horarios reservables requerirá la asignación y validación del CU correspondiente. No se agregó unicidad al registro profesional o correo de contacto ni atributos de vigencia al pivote. La política para retirar la habilitación futura de un par Médico–Especialidad sigue pendiente.

### Verificaciones y estado local

- **140 pruebas, 752 aserciones correctas**. `MedicosHorariosTest` cubre esquema/tipos, PK compuesta, relaciones en ambas direcciones, escrituras técnicas del pivote acotadas por ambas claves, UQ/FK/CHECK, estados independientes, intervalos y rollback.
- Las cinco FK rechazan borrar o modificar claves referidas; los modelos protegen IDs, vínculos, códigos y actividad de asignación masiva.
- La reversión/reaplicación de las dieciocho migraciones se comprobó solo dentro de pruebas aisladas. La suite anterior, incluida concurrencia de activación, sigue correcta; la base de pruebas termina sin tablas ni esquemas auxiliares persistidos.
- Desarrollo: **18 tablas de dominio, 146 columnas y 20 FK**, además de `migrations`. Las tres nuevas están vacías; las quince tablas anteriores se compararon antes/después y conservaron sus registros. Sin nuevos seeders.
- Autoload PSR-4 estricto, Pint, sintaxis PHP y diff correctos. DER lógico conservado: 28 entidades, 239 atributos y 43 relaciones.

Se actualizaron este README, DER §6.10 y `contexto-maestro.md` §17.7. CU-09–CU-12 siguen pendientes de implementación funcional: roles/permisos, especialidades obligatorias, duración y consultorio válidos, exclusión concurrente de solapamientos, revisión de citas futuras y cambios de ubicación/avisos. El CHECK de intervalo no garantiza por sí solo disponibilidad ni ausencia de superposición. `analisis-clases.md` y `diagrama-clases.md` siguen pendientes de los CU/módulos funcionales. La siguiente fase comprende citas, cambios de citas y recordatorios.

## Fase 5 de datos — 2026-10-19

Se incorporaron tres migraciones (`000019`–`000021`, **lote 5**) y los modelos `Cita`, `CambioCita` y `Recordatorio`. Sus modelos relacionados incorporan las relaciones inversas. El diseño físico detallado está en el DER §6.11.

| Tabla | Persistencia implementada |
|---|---|
| `citas` | FK compuesta Médico–Especialidad, código único, cinco estados, modalidad Presencial, duración/ubicación efectivas y dos exclusiones de intervalos |
| `cambios_cita` | Autor, estados y snapshots administrativos; triggers que impiden UPDATE, DELETE y TRUNCATE |
| `recordatorios` | Cita y versión de programación únicas; generación explícita, lectura/invalidación opcionales y destinatario derivado de la cita |

### Protección de ocupación

PostgreSQL rechaza intervalos `[inicio, fin)` superpuestos por **Médico o consultorio**, incluso ante escrituras simultáneas. Los turnos contiguos son compatibles. Solo `Cancelada` queda fuera de la ocupación; Atendida e Inasistencia preservan su intervalo histórico.

La migración instala la extensión confiable **btree_gist** en `public` si falta. Se verificaron disponibilidad y privilegios antes de migrar. Una instalación nueva necesita permitir esa extensión o provisionarla previamente en ese esquema. La migración inversa conserva la extensión compartida; no se retira infraestructura ajena al revertir la tabla.

### Verificaciones y estado local

- **178 pruebas, 976 aserciones correctas**. `CitasRecordatoriosTest` cubre esquema, defaults, FK/UQ/CHECK, duración y ubicación históricas, estados, intervalos contiguos, inmutabilidad y rollback conjunto.
- `CitasConcurrentesTest` usa dos conexiones PostgreSQL y comprueba reservas/reprogramaciones incompatibles por ambos recursos, conservación del registro rechazado y unicidad concurrente de avisos. Los esquemas auxiliares y la extensión instalada solo para esas pruebas se retiran al terminar.
- La suite anterior sigue correcta y prueba reversión/reaplicación de las veintiuna migraciones solo dentro de transacciones aisladas. La base de pruebas termina sin tablas ni esquemas auxiliares persistidos.
- Desarrollo: **21 tablas de dominio, 180 columnas y 27 FK**, además de `migrations`, con dos exclusiones. Las dieciocho tablas previas conservaron sus registros; las tres nuevas quedaron vacías y no se agregaron seeders.
- Autoload PSR-4 estricto, Pint, sintaxis PHP y diff correctos. DER lógico conservado: 28 entidades, 239 atributos y 43 relaciones.

Se actualizaron este README, DER §6.11 y `contexto-maestro.md` §17.8. Los CU de citas y recordatorios siguen pendientes de Services/API: autorización, fecha/horario/actividad, transiciones, reprogramación con identidad/versión preservadas, historial y avisos atómicos, generación automática a 24 horas y preferencias. Las exclusiones no implementan por sí solas esos flujos; el error PostgreSQL `23P01` deberá normalizarse a HTTP 409 al exponer las operaciones. No se ha configurado un programador de avisos. `analisis-clases.md` y `diagrama-clases.md` siguen pendientes de los CU/módulos funcionales. La siguiente fase es consultas clínicas y sus versiones de consulta/antecedentes.

## Fase 6 de datos — 2026-10-19

Se incorporaron tres migraciones (`000022`–`000024`, **lote 6**) y los modelos `ConsultaMedica`, `VersionConsulta` y `VersionAntecedente`, con relaciones inversas en Cita, Paciente y Usuario. El diseño físico detallado está en el DER §6.12.

| Tabla | Persistencia implementada |
|---|---|
| `consultas_medicas` | Código único y una consulta como máximo por cita; Paciente/Médico derivados de Cita; inicio real opcional y creación obligatoria independientes |
| `versiones_consulta` | Número positivo único por consulta, autor/fecha, tipo Control/Enfermedad y snapshot clínico completo; textos opcionales admiten información todavía desconocida |
| `versiones_antecedente` | Número positivo único por Paciente, autor/fecha y consulta de origen obligatoria; antecedentes opcionales sin datos clínicos predeterminados |

Las dos tablas de versiones rechazan UPDATE, DELETE y TRUNCATE mediante triggers PostgreSQL. Cada corrección se inserta como versión nueva. Los modelos protegen asignación masiva y ocultan contenido clínico en serialización genérica; esto no sustituye autorización ni Resources explícitos. Las UQ impiden números repetidos, pero la asignación de la siguiente versión y la revalidación de la versión leída requerirán coordinación transaccional en el Service.

### Verificaciones y estado local

- **210 pruebas, 1214 aserciones correctas**. `ConsultasVersionesTest` verifica columnas exactas, seis FK restrictivas, UQ/CHECK/NOT NULL, relaciones, zona/microsegundos, datos desconocidos, versiones anteriores conservadas, inmutabilidad SQL/Eloquent y rollback conjunto.
- `ConsultasConcurrentesTest` comprueba con dos conexiones PostgreSQL la espera de escrituras competidoras, rechazo tras COMMIT y aceptación tras ROLLBACK para consulta por cita y número de versión por consulta/Paciente. Las versiones de antecedentes de distintas consultas de origen compiten igualmente por la secuencia del mismo Paciente.
- Guardar consulta y versiones conserva la cita Programada, no deduce `iniciada_en` ni copia datos clínicos a Bitácora. Son pruebas de persistencia; los eventos de negocio se integrarán en sus Services.
- La suite anterior verifica reversión/reaplicación de las veinticuatro migraciones dentro de una transacción aislada. Comprobación posterior: base de pruebas sin tablas en public, esquemas auxiliares ni btree_gist residual.
- Desarrollo: **24 tablas de dominio, 210 columnas y 33 FK**, además de `migrations` y las dos exclusiones de citas. Las veintiuna tablas previas conservaron sus registros; las tres nuevas quedaron vacías, sin seeders clínicos.
- Autoload PSR-4 estricto, Pint, sintaxis de los doce PHP afectados y diff correctos. Las secciones lógicas 2–4 del DER se compararon directamente en UTF-8 con HEAD, sin diferencias: 28 entidades, 239 atributos y 43 relaciones conservadas.

Se actualizaron este README, DER §6.12 y `contexto-maestro.md` §17.9. **CU-22/CU-23 siguen pendientes:** lectura autorizada, cuenta/rol/relación asistencial, coincidencia Paciente–Consulta–Cita y autor responsable, atención real, bloqueo de cambios administrativos incompatibles incluso con inicio desconocido, contenido/motivo de corrección, numeración coordinada y auditoría segura. `analisis-clases.md` y `diagrama-clases.md` siguen pendientes del desarrollo funcional. La siguiente fase de datos es laboratorio digital.

## Fase 7 de datos — 2026-10-19

Se incorporaron cuatro migraciones (`000025`–`000028`, **lote 7**) y los modelos `SolicitudLaboratorio`, `DocumentoLaboratorio`, `VersionResultado` y `RevisionResultado`, con relaciones inversas en ConsultaMedica, Usuario y Medico. Diseño físico en DER §6.13.

| Tabla | Persistencia implementada |
|---|---|
| `solicitudes_laboratorio` | Varias solicitudes por consulta, tres estados, punteros opcionales únicos y dos FK compuestas que garantizan pertenencia exacta de versión y revisión |
| `documentos_laboratorio` | Metadatos inmutables, ruta única, tamaño BIGINT positivo y SHA-256 hexadecimal de 64 caracteres; sin unicidad del contenido |
| `versiones_resultado` | Número positivo único por solicitud, documento/autor/fecha y origen opcional previo; asociaciones y sustituciones agregan versiones inmutables |
| `revisiones_resultado` | Médico, versión exacta, decisión Autorizar/Devolver y fecha; devolución con motivo obligatorio y revisiones inmutables |

Las FK circulares se añaden al finalizar la última migración; la inversa retira primero esas referencias. Cada versión actual pertenece a su solicitud y cada revisión publicada corresponde a esa misma versión. En revisión admite versión NULL para corregir una asociación sin falsear publicación. Disponible exige ambos punteros y rechaza cambios y eliminación ordinarios de su solicitud. Documentos, versiones y revisiones rechazan UPDATE/DELETE/TRUNCATE. El origen de una versión puede estar en otra solicitud, sin modificar la asociación anterior ni permitir autorreferencias o ciclos.

### Verificaciones y estado local

- **259 pruebas, 1609 aserciones correctas**, incluidas `LaboratorioPersistenciaTest`, `LaboratorioConcurrenteTest` y suite anterior. Se verificaron columnas/tipos, diez FK restrictivas, UQ/CHECK/NOT NULL, relaciones/casts, precisión temporal, serialización y asignación protegidas.
- Se probaron autorización directa sin devolución previa como escritura de persistencia, devolución/sustitución/nueva revisión, corrección de asociación con origen conservado, rechazo de versión ajena y revisión de otra versión, inmutabilidad y protección de solicitudes publicadas. Las pruebas no representan permisos o Services terminados.
- Dos conexiones/PID PostgreSQL comprueban contención por número de versión y ruta, rechazo tras COMMIT y aceptación tras ROLLBACK. Corrección/publicación compiten por la misma solicitud: escrituras SQL condicionadas a estado y versión rechazan una lectura desactualizada; las FK también rechazan publicar una revisión de la versión sustituida. El Service deberá aplicar y traducir ese protocolo.
- Fallos provocados revierten juntos metadatos nuevos, versión y punteros de corrección, o revisión y publicación. Los archivos no participan de rollback SQL: estos tests solo escriben metadatos ficticios; la coordinación de bytes privados queda pendiente de CU-25/CU-27.
- Reversión/reaplicación de las veintiocho migraciones comprobada solo dentro de transacción de pruebas. Base de pruebas posteriormente sin tablas public, esquemas auxiliares ni btree_gist residual.
- Desarrollo: **28 tablas de dominio, 239 columnas y 43 FK**, además de `migrations` y dos exclusiones. Las veinticuatro tablas previas conservaron sus registros; las cuatro nuevas están vacías. Sin seeders clínicos ni archivos de laboratorio creados.
- Autoload PSR-4 estricto, Pint sobre 77 archivos, sintaxis de catorce PHP afectados y diff correctos. Secciones lógicas 2–4 del DER comparadas directamente en UTF-8 con HEAD, sin diferencias.

Se actualizaron este README, DER §6.13 y `contexto-maestro.md` §17.10. **Se completó la preparación de persistencia prevista en fases 0–7**, conservando 28 entidades, 239 atributos y 43 relaciones. **CU-24–CU-27 siguen pendientes:** autorización del Médico solicitante, validación real del archivo y almacenamiento/entrega privada, decisión Autorizar antes de publicar, devolución previa que habilite corrección, numeración y revalidación coordinadas, publicación atómica y auditoría segura. No se han creado endpoints de carga/descarga. `analisis-clases.md` y `diagrama-clases.md` continúan pendientes del desarrollo de CU/módulos funcionales. El siguiente trabajo es la capa de negocio/API, priorizando usuarios y autenticación.

## Población de datos y demostración — 2026-10-19

### Comandos separados

Desde `backend/`:

```powershell
composer seed        # Roles, especialidades, configuración y tres consultorios iniciales
composer seed:admin  # Primer Administrador Activo, por excepción de instalación
composer seed:demo   # Conjunto ficticio, exclusivamente en la base local o de pruebas
```

`DatabaseSeeder` incorpora `ConsultorioSeeder`: `CONS-01`, `CONS-02` y `CONS-03`, con nombres Consultorio 1–3. Son datos iniciales para la clínica ficticia, no información obtenida de una sede real. Se insertan ausentes mediante `insertOrIgnore`, conservando IDs, nombres y actividad existentes. El seeder ordinario no llama a los otros dos comandos.

### Primer Administrador: excepción de instalación aprobada

Completar únicamente en `.env` privado:

```dotenv
CLINICA_ADMIN_CORREO=
CLINICA_ADMIN_NOMBRES=
CLINICA_ADMIN_APELLIDOS=
CLINICA_ADMIN_PASSWORD=
```

Después ejecutar `php artisan config:clear` y `composer seed:admin`. `config/seeders.php` centraliza la lectura del entorno; los ejemplos versionados no contienen identidades reales.

`AdministradorInicialSeeder` valida los datos y una contraseña privada de mínimo 12 caracteres, normaliza correo y coordina creación y evento SISTEMA mediante bloqueo PostgreSQL y transacción. Por **decisión explícita posterior del usuario**, sustituye la preparación Pendiente por una cuenta **Activa**, con hash Laravel, `correo_verificado_en` en la fecha de instalación y `cambio_clave_obligatorio=false`. No utiliza código, invitación ni envío de correo. El marcado es una **autorización del responsable de instalación, no una comprobación del buzón**. La contraseña de instalación es definitiva, no una credencial temporal.

El evento `INSTALACION_ADMIN_INICIAL` incluye únicamente referencias y metadatos de método SEEDER/verificación AUTORIZACION_INSTALACION; no contiene correo, contraseña ni hash. Una cuenta existente del mismo correo/rol se conserva íntegramente, incluso si sigue Pendiente o está desactivada: repetir el comando no activa, verifica ni restablece cuentas previas. Otro rol o un segundo Administrador de instalación distinto impiden crearla. En local/pruebas, `demo.admin@example.test` no cuenta como Administrador de instalación. Las cuentas posteriores del personal siguen la invitación ordinaria de 24 horas y un solo uso.

**Estado local:** cuenta creada con el correo proporcionado por el usuario y nombres Administrador / Vida Salud, configurados solo en `.env` privado. Se generó una contraseña aleatoria de 32 caracteres en `CLINICA_ADMIN_PASSWORD`; en PostgreSQL se comprobó su hash sin mostrar la clave. La segunda ejecución dejó filas y credenciales idénticas, conservando el dataset previo. Hay **7 usuarios (6 demo + 1 inicial) y 2 eventos de bitácora**. Este cambio no implementa endpoints de autenticación.

**Verificación posterior de esta excepción:** suite completa **274 pruebas y 1738 aserciones correctas**. Se comprueban hash, activación, marca de instalación, ausencia de secretos en el evento, rechazo de contraseña ausente/corta y reversión de cuenta/roles si falla la auditoría. Repetir con otra contraseña configurada no cambia credenciales ni reactiva una cuenta desactivada. Pint y sintaxis de tres PHP afectados correctos; `.env` excluido de Git y `git diff --check` correcto.

### Demostración reproducible

`DemostracionSeeder` verifica entorno y la identidad real PostgreSQL: `clinica_vida_salud`/`clinica_vida_salud_app` en local, o `clinica_vida_salud_test`/`clinica_vida_salud_test` en testing. Usa un bloqueo transaccional compartido con instalación, claves estables `DEMO-VS-*` y correos reservados `example.test`. Conserva registros existentes, contraseñas, estados, fechas y publicaciones; detecta vínculos incompatibles en claves reservadas en lugar de sobrescribirlos. Los archivos ya registrados se comprueban contra tamaño y SHA-256.

- Cuentas ficticias: `demo.admin@example.test`, `demo.recepcion@example.test`, `demo.medico1@example.test` a `demo.medico3@example.test` y `demo.paciente@example.test`. Se simulan activas/verificadas solo dentro del dataset local, sin afirmar verificación real ni enviar correo.
- `CLINICA_DEMO_PASSWORD` es opcional y privado, mínimo 12 caracteres. Si se configura **antes de la primera carga**, se guarda su hash Laravel. Si se omite, se genera un secreto aleatorio que no se muestra ni conserva en claro; no hay contraseña predeterminada conocida. Definirlo después no cambia hashes existentes: los seeders no restablecen credenciales. Los endpoints de login aún están pendientes.
- Tres consultorios adicionales `DEMO-VS-C01`–`C03`, tres médicos, una especialidad por médico y 21 intervalos de atención sin compartir consultorios. Cinco pacientes, uno con cuenta web y cuatro sin ella.
- Cinco citas con intervalos de 15 minutos y ubicación histórica explícita: una por cada estado. Nueve cambios históricos con actores de rol apropiado. La primera carga fija las fechas; repetirla después no desplaza turnos ni reconstruye historia.
- Una consulta vinculada a la cita Atendida, dos versiones de consulta y dos de antecedentes con el mismo Paciente/Médico responsable.
- Tres estudios: Pendiente, En revisión tras devolución y sustitución (nueva versión aún sin autorizar) y Disponible con revisión Autorizar de su versión exacta. Tres versiones/documentos y dos revisiones conservadas.
- Tres PDF legibles, generados sin dependencias adicionales y marcados **DOCUMENTO FICTICIO - SIN VALOR CLINICO**, bajo `storage/app/private/demo-seeders/`. Solo en disco privado; no se crean enlaces públicos. Los metadatos coinciden con los bytes. Ante fallo dentro de la carga se compensan únicamente los archivos nuevos antes de liberar el bloqueo; SQL no se presenta como rollback del sistema de archivos.
- Un evento SISTEMA identifica la carga del dataset, sin contenido clínico ni secretos. No se simulan sesiones, tokens, códigos, preferencias funcionales ni generación de recordatorios.

### Resultado comprobado

Carga ordinaria y demo aplicadas y repetidas en desarrollo. Las filas previas se conservaron y la segunda ejecución dejó filas y bytes idénticos. Dataset original: 4 roles, 3 especialidades, 1 configuración, **6 consultorios (3 iniciales + 3 demo), 6 usuarios ficticios, 3 médicos, 21 horarios, 5 pacientes, 5 citas, 9 cambios, 1 consulta, 2 versiones de consulta, 2 versiones de antecedentes, 3 solicitudes, 3 documentos/versiones de resultado, 2 revisiones y 1 evento de bitácora**. La instalación posterior agrega el Administrador y su evento: **7 usuarios y 2 eventos** en total.

- **270 pruebas, 1716 aserciones correctas**. `PoblacionDatosTest` comprueba idempotencia incluso al avanzar la fecha, conservación de ediciones/credenciales/archivos, roles y asociaciones, cronología de corrección y publicación, PDF/tamaño/hash, Administrador pendiente sin tokens, rechazo de promoción/segunda instalación y bloqueo en producción/contraseña inválida.
- Fallo SQL provocado revierte el dataset y elimina solo archivos nuevos; un archivo previo distinto se conserva. La suite anterior sigue correcta.
- Composer válido, autoload PSR-4 estricto, Pint sobre 82 archivos, sintaxis de siete PHP afectados y diff correctos. Esquema sin cambios: 28 tablas/239 columnas/43 FK, 28 migraciones aplicadas. DER lógico conservado.

Documentación sincronizada con DER §6.14 y contexto §17.11. No se completa ningún CU: los seeders son infraestructura de población, no Services ni APIs. Análisis y diagrama de clases siguen pendientes del desarrollo funcional.

## Ampliación de TokenAcceso y políticas de autenticación — 2026-10-19

Se añadió la migración incremental `src/data/database/migrations/2026_10_19_000029_allow_temporary_credentials_in_tokens_acceso.php`, aplicada en desarrollo como **lote 8**. Amplía `tokens_acceso_proposito_ck` con **`CredencialTemporal`**, conservando `InvitacionPersonal` y `RecuperacionClave`. Reutiliza hash, emisión, expiración, consumo e invalidación del modelo `TokenAcceso` existente; no requiere cambios del modelo ni columnas nuevas.

- Una sola sentencia ALTER TABLE sustituye el CHECK atómicamente. El `down` solo restaura el catálogo anterior si no existe ninguna fila `CredencialTemporal`; con historial temporal, incluso consumido o invalidado, PostgreSQL rechaza la reversión sin borrar ni recategorizar filas. Se probó reversión/reaplicación únicamente en la base aislada de pruebas.
- Comparación local antes/después: todas las filas y definiciones de columnas de las 28 tablas idénticas; se mantienen **239 columnas, 43 FK, 7 usuarios, 2 eventos y cero tokens**. No se ejecutaron seeders ni se enviaron correos en esta ampliación.
- **280 pruebas y 1772 aserciones correctas** en PostgreSQL aislado. `UsuariosAuditoriaTest` comprueba los tres propósitos, conservación, expiración obligatoria/válida, hash único entre propósitos, Eloquent y rechazo de reversión con historial temporal. Pint y sintaxis de los dos PHP afectados correctos.
- Sincronizados los cinco documentos existentes de `../docs/ai/`: CU-03/CU-05/CU-06 separados de la invitación inicial; sesión temporal restringida con cierre y nuevo login; cambios de rol con identidad e historial preservados; rechazo de obligaciones médicas incompatibles/no verificables y protección transaccional del último Administrador. Son políticas aprobadas del proyecto, no nuevos RF/RN oficiales ni controles implementados. Referencias: reglas §§4.3–4.4, DER §§3.4 y 6.15, contexto §17.14.

Quedan por definir duración, generación/entrega, intentos, reemisión/usos y consumo de la credencial, tratamiento de contraseña anterior al emitir y concurrencia con sesiones/cambios/recuperaciones. Admitir el propósito en PostgreSQL no implementa ese flujo. **Ningún CU queda completado**; análisis y diagrama de clases siguen pendientes del desarrollo funcional. CU-01/CU-02 pueden abordarse con los controles definidos, impidiendo acceso normal ante cambio obligatorio.

## Usuarios y autenticación — fase 1, backend de CU-01/CU-02 (2026-10-19)

### Contrato y uso desde la SPA

La API utiliza sesiones Laravel/Sanctum con cookies. Desarrollo está configurado para backend `http://localhost:8000` y frontend `http://localhost:5173`, con CORS de credenciales y comprobación exacta de origen. Las peticiones de la SPA necesitan Origin/Referer permitido; no hay acceso alternativo por bearer token.

| Operación | Petición | Resultado |
| --- | --- | --- |
| Preparar CSRF | GET `/sanctum/csrf-cookie` | 204; cookies de sesión HttpOnly y XSRF-TOKEN para la cabecera CSRF. |
| Iniciar sesión | POST `/api/auth/login`, JSON con `correo_acceso` y `password` | 200; sesión regenerada y `data` con `id_usuario` como string, `correo_acceso`, `nombre_visible` y `rol`. |
| Restaurar sesión | GET `/api/auth/me` | 200 con el mismo Resource; 401 sin sesión válida. No recibe un ID de usuario seleccionable. |
| Cerrar sesión | POST `/api/auth/logout` | 204 sin JSON; invalida la sesión actual y renueva CSRF. |

El cliente conserva cookies y envía `Accept: application/json`, credenciales y `X-XSRF-TOKEN` en escrituras. Resolver CSRF contra la raíz del backend, no contra `/api/sanctum/csrf-cookie`. Logout repetido es idempotente con CSRF válido; un 419 exige renovar el mecanismo sin reenvío automático de escrituras. Login desde sesión normal ya válida devuelve 409 para impedir un intercambio silencioso de identidad.

- Credenciales/estado no admitidos devuelven el mismo 401 genérico, sin revelar bloqueo o existencia de correo. Me devuelve 401 por ausencia, expiración/revocación o contexto incompatible; falta de permiso con sesión válida es 403.
- Entradas inválidas: 422 con mensajes por campo; CSRF ausente/vencido: 419; límites técnicos: 429 y Retry-After accesible desde la SPA mediante CORS. Los errores usan `success: false`, `error.code` y `error.message`, con detalles seguros solo cuando proceden. Los fallos inesperados usan 500 seguro.
- Tres contraseñas incorrectas consecutivas bloquean diez minutos. El bloqueo no se extiende por intentos posteriores; expiración/éxito reinician contador. El limitador técnico independiente usa 20 solicitudes/minuto/IP y 5/minuto/correo+IP, configurable en `config/seguridad.php`, con ámbitos HMAC persistidos en ControlIntentos.
- Sesiones database cifradas; el guard nativo regenera ID/CSRF e inicializa huella HMAC. Login guarda y comprueba la fila en la transacción del Usuario/auditoría antes de confirmar éxito. La primitiva de revocación múltiple exige transacción y debe coordinarse con el bloqueo de Usuario en los CU consumidores futuros. Un guardado tardío no recrea una sesión revocada.
- Cuenta, ficha y bloqueo permanecen independientes. Paciente/Médico con ficha inactiva y cuenta habilitada pueden autenticarse para operaciones autorizadas; el acceso clínico exige su propio rol/vínculo. Cambio obligatorio impide acceso normal mientras se desarrolla fase 6 del plan vigente.

### Verificación y alcance

Pruebas de API: `tests/Feature/AutenticacionTest.php`. Pruebas de procesos/conexiones PostgreSQL independientes: `tests/Feature/AutenticacionConcurrenteTest.php`, con fixtures de navegador, worker y barreras de respuesta. Solo se usan base/rol `clinica_vida_salud_test` y datos ficticios; las pruebas de CSRF conservan esa conexión aislada aunque ejerciten el middleware real fuera de la omisión habitual de testing.

```powershell
php artisan test --compact --no-ansi tests/Feature/AutenticacionTest.php tests/Feature/AutenticacionConcurrenteTest.php
php artisan test --compact --no-ansi
php artisan route:list --path=api/auth -v
```

Autoload PSR-4 estricto y Composer validados; Pint sobre los 26 PHP afectados y sintaxis comprobados. Documentación actualizada en análisis de clases y contexto §17.16. La API ordinaria está implementada; React/recorrido en navegador real corresponden a la **etapa frontend posterior**, según la reorganización de contexto §17.17, y el módulo backend completo sigue en desarrollo. Brevo, invitaciones, recuperación, administración de cuentas y credencial temporal se desarrollan en las fases acordadas. El esquema permanece en 28 entidades/239 atributos/43 relaciones; este bloque no requiere migraciones. El diagrama global de clases corresponde al cierre backend del módulo.

**Resultado final de fase 1:** suite completa con **324 pruebas y 2216 aserciones correctas** (106,58 s). Las tres rutas de autenticación están registradas y `git diff --check` es correcto. Desarrollo conserva siete usuarios, dos eventos y cero tokens, con 29 migraciones; no se ejecutaron seeders ni envíos de correo.

## Plan vigente — completar backend antes de frontend

Las fases siguientes sustituyen la antigua fase 2 de React. Son independientes de las fases históricas de preparación de datos de este README.

| Fase | Entrega |
| --- | --- |
| 0–1 | Diseño y API de login/me/logout; completadas. |
| 2 | Cambio de contraseña propia CU-05; implementado, detalle siguiente. |
| 3 | Alta/invitación del personal CU-03 e integración Brevo de seguridad; implementada con pruebas simuladas. |
| 4 | Administración de acceso/roles CU-03 y protección del último Administrador; implementada. |
| 5 | Recuperación por correo CU-06 para los cuatro roles; implementada con correo simulado. |
| 6 | Restablecimiento administrativo temporal del personal CU-03 implementado; política aprobada y contratos al final de este README/contexto §17.23. |
| 7A / 7B | Ambos escenarios de CU-04 implementados; 7B incluye emisión autorizada de código por CU-07. |
| 8 | Integración/pruebas y cierre backend completados; diagrama global aplazado por solicitud expresa del usuario. |

La protección del último Administrador se aplica en cada operación que lo requiera, incluidos restablecimientos. Se mantienen los rechazos aprobados al abandonar Médico con obligaciones incompatibles/no verificables. La documentación se actualiza por CU/fase; el cierre backend se distingue del recorrido completo posterior con React. AUT-21 se conserva pendiente para esa etapa frontend.

## Usuarios y autenticación — fase 2 backend, CU-05

**PUT `/api/auth/password`**, para cualquiera de los cuatro roles con cuenta habilitada y sesión normal, origen permitido y CSRF. JSON de entrada:

```json
{
  "current_password": "Contraseña actual ficticia",
  "password": "Nueva frase ficticia 456",
  "password_confirmation": "Nueva frase ficticia 456"
}
```

Al confirmar devuelve **204 sin cuerpo** y cookies renovadas: mantiene la sesión actual de forma segura con un ID y CSRF nuevos, revocando todos los IDs anteriores de ese Usuario. El cliente debe utilizar las cookies/cabecera CSRF nuevas. La identidad se deriva de la sesión; los campos ajenos como id_usuario/rol no se utilizan. No se modifica la cuenta de otro Usuario ni se duplica el hash en Paciente/Médico.

- Mínimo **12 caracteres**, confirmación exacta, conservación de espacios/caso/Unicode y máximo técnico de 1024 caracteres. Con el algoritmo bcrypt vigente se valida además máximo **72 bytes y ausencia de NUL**, para evitar truncamiento o fallo al guardar. No se cambia algoritmo ni contraseñas existentes.
- Contraseña actual incorrecta: **422 AUTH_CLAVE_ACTUAL_INVALIDA**, con evento DENEGADO y sin modificar hash/sesiones ni contador de login. Validación de campos: 422 VALIDACION_ERROR con details; errores restantes mantienen el contrato central.
- Límite técnico propio: 20 solicitudes/minuto/IP y 5/minuto/Usuario, compartidas entre dispositivos e IP de ese Usuario, con ámbitos HMAC persistentes separados de login y 429/Retry-After. No prolonga la ventana ni desactiva/bloquea la cuenta. Parámetros en config/seguridad.php.
- Cuenta/rol/vínculo/huella, condición normal y fila de sesión vigente se comprueban otra vez después del bloqueo de Usuario. Hash, revocación, persistencia de sesión renovada y auditoría de éxito confirman juntos; el fallo necesario revierte todo y limpia ambos guards web/Sanctum.
- No cambia estados administrativos, vínculos ni bloqueo temporal de login. Invitación inicial, recuperación y sesión restringida temporal mantienen sus flujos independientes en fases posteriores. No se envían correos por CU-05.

Clases nuevas: CambiarClaveRequest, ClaveController, ClaveService y LimitarCambioClave. Reutiliza y amplía los Services/Repositories de fase 1. Contrato, colaboraciones y evidencia en analisis-clases §14 y contexto §17.17. No se agregan tablas, columnas, modelos ni migraciones; DER vigente y diagrama global pendiente de fase 8.

```powershell
php artisan test --compact --no-ansi tests/Feature/CambioClaveTest.php tests/Feature/AutenticacionTest.php tests/Feature/AutenticacionConcurrenteTest.php
php artisan route:list --path=api/auth -v
```

**Verificación focalizada de fase 2:** 83 pruebas y 1015 aserciones correctas, incluidas conexiones/procesos PostgreSQL independientes y CSRF real. Composer validate y autoload PSR-4 estrictos, Pint/sintaxis de los 32 PHP acumulados, cuatro rutas y formato/JSON/referencias documentales comprobados. Desarrollo conserva siete usuarios, dos eventos, cero tokens y 29 migraciones, con bcrypt; no se ejecutaron seeders ni envíos de correo.

**Resultado final de fase 2:** suite completa con **363 pruebas y 2787 aserciones correctas** (133,65 s). Se ejecutó con margen de 300 s después de que el límite inicial de 120 s interrumpiera la primera ejecución. Backend de CU-05 completado y verificado; el módulo completo continúa con fase 3.

## Usuarios y autenticación — fase 3 backend, alta/invitación del personal (CU-03 parcial)

### API y recorrido

| Operación | Acceso/entrada | Respuesta |
| --- | --- | --- |
| POST `/api/personal` | Administrador normal con sesión/CSRF. `rol`, `correo_acceso`; `nombres_personal`/`apellidos_personal` para Administrador o Recepcionista; para Médico solo `id_medico` existente y libre, sin sustituir sus nombres. | 201: `data` con id_usuario string, correo_acceso, rol y estado_cuenta Pendiente; `meta.invitacion` con envio y expira_en. |
| POST `/api/personal/{id}/invitacion` | Mismo actor; solo personal pendiente de primera contraseña y con vínculo válido. ID BIGINT positivo de ruta. | 200 con el mismo Usuario y nueva invitación que invalida la anterior. |
| POST `/api/auth/invitaciones/aceptar` | Anónimo, origen permitido y CSRF; token, password y password_confirmation. | 204 sin cuerpo: correo verificado/contraseña establecida/cuenta Activa; exige login posterior, no autentica automáticamente. |

Ejemplo ficticio de alta administrativa (el Administrador no asigna una contraseña):

```json
{
  "rol": "Recepcionista",
  "correo_acceso": "trabajador@example.test",
  "nombres_personal": "Persona",
  "apellidos_personal": "Ficticia"
}
```

La API nunca devuelve la invitación en claro. El titular recibe por correo un enlace de 24 horas y un solo uso, generado con 256 bits aleatorios y guardado como HMAC-SHA256 en TokenAcceso/InvitacionPersonal. El enlace previsto es `FRONTEND_URL/activar-personal#token=...`: usa fragmento para que el servidor web no reciba el secreto por GET. La pantalla React todavía no existe; el contrato del backend recibe el token por POST JSON junto con la primera contraseña y confirmación. La política se comparte con CU-05 mediante ClaveRequest, sin exigir contraseña anterior para invitación.

Se revalida al Administrador bajo bloqueo de su cuenta/sesión persistida. Médico requiere el mismo registro profesional existente y sin vínculo a otra cuenta; se bloquea y vincula sin alterar nombres, actividad, consultorio u horarios. Los correos normalizados cuentan con unicidad PostgreSQL. Se rechazan alta de Pacientes, Médico inexistente/ocupado, roles adicionales y reenvío a cuentas activas/desactivadas/Paciente. La instalación del primer Administrador no se reutiliza ni se modifican sus credenciales.

### Envío y recuperación ante fallos

Alta/vínculo/token/eventos se guardan en una transacción; **el envío ocurre con DB::afterCommit después del commit exterior**, nunca dentro de un rollback SQL. La respuesta distingue:

- `meta.invitacion.envio = simulado`: transporte array local/de pruebas, sin salida externa.
- `aceptado`: el transporte aceptó el mensaje; no prueba entrega al buzón ni verificación del correo.
- `fallido`: no se confirmó el envío; la cuenta permanece Pendiente y permite reenvío administrativo controlado.
- `pendiente`: callback diferido por una transacción exterior todavía abierta.

El resultado de envío se audita por separado sin secretos. No hay reintentos SMTP automáticos ni cola/outbox nueva. Una interrupción después del commit se recupera reemitiendo sobre la misma cuenta. Un fallo de auditoría posterior al envío puede devolver 500 sin deshacer correo/cuenta ya confirmados. Reenviar invalida el enlace anterior y emite otro con su propia vigencia de 24 horas; un fallo SQL antes de confirmar la nueva emisión conserva el enlace previo. La aceptación y reemisión comparten bloqueo de Usuario antes del token, evitando doble consumo y estados parciales.

Parámetros técnicos iniciales en `config/seguridad.php`: espera de 60 segundos y cinco emisiones/hora por cuenta, incluidas inicial/fallidas; límites por acción de 20 solicitudes/minuto/IP y cinco/minuto/ámbito (Admin o token), mediante HMAC y ControlIntentos. 429/Retry-After no prolonga ventana. Estos valores no son nuevos RF/RN ni parámetros del flujo temporal todavía pendiente.

### Brevo HTTP y configuración privada

Por decisión posterior a fase 5, el mailer Laravel `brevo` usa el transporte propio `brevo-http`, registrado con Mail::extend. Envía JSON a `https://api.brevo.com/v3/smtp/email` mediante el cliente HTTP nativo de Laravel, sin instalar SDK. Verificación HTTPS obligatoria, timeout de conexión de 3 segundos y total de 10 segundos, sin redirecciones ni reintentos automáticos. Esta configuración sustituye a Brevo SMTP; los resultados históricos de fases 3/5 corresponden al transporte anterior. Las variables están declaradas sin secretos en `.env.example`; el `.env` privado no se modificó.

Para utilizar envío real, el responsable debe configurar privadamente:

- `SEGURIDAD_MAILER=brevo`.
- `BREVO_API_KEY`, una API key HTTP de Brevo, cargada en services.brevo.key; no es una clave SMTP.
- `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` con remitente autorizado por Brevo.
- `FRONTEND_URL` con el destino real de la SPA; producción exige HTTPS y su configuración de cookies/CORS correspondiente.

El valor predeterminado de seguridad sigue siendo `array`, admitido solo en local/testing y señalado como simulado. No se admite el transporte log/failover para correos de seguridad ni se simula entrega con array en producción. No se realizaron envíos reales ni se verificó entrega a buzón. La plantilla Blade es exclusivamente de correo, no una interfaz funcional. Brevo permanece reservado a seguridad, nunca citas, recordatorios o contenido clínico.

La API requiere remitente, destinatario, asunto y contenido HTML; autentica con cabecera `api-key`, sin credenciales en URL. Solo una respuesta **201 con messageId válido** confirma aceptación por Brevo; no demuestra llegada al buzón. Errores HTTP, confirmación incompleta o fallos de conexión producen errores saneados sin copiar respuestas, claves o enlaces del proveedor. La cuenta/token ya confirmados se conservan para reemisión controlada. Un timeout no demuestra si el proveedor llegó a aceptar el correo; se evita reintentarlo automáticamente.

### Verificación y alcance

`tests/Feature/InvitacionPersonalTest.php` cubre los tres roles, validaciones, correo/vínculo, hash/verificación pendientes, primera contraseña sin login automático, uso único/vigencia/reenvío/límites, conflictos, errores SQL/auditoría/SMTP, callback solo después del commit exterior y rollback sin correo, transporte Brevo simulado y exclusión de configuraciones inseguras. `AutenticacionConcurrenteTest` añade procesos independientes para doble consumo, altas con correo duplicado, dos cuentas compitiendo por el mismo Médico y reenvío frente a aceptación.

```powershell
php artisan test --compact --no-ansi tests/Feature/InvitacionPersonalTest.php tests/Feature/AutenticacionConcurrenteTest.php tests/Feature/CambioClaveTest.php tests/Feature/AutenticacionTest.php
php artisan route:list --path=personal -v
php artisan route:list --path=api/auth -v
```

Regresión focalizada de fases 1–3: **124 pruebas y 1519 aserciones correctas**, con PostgreSQL aislado y correos simulados. Análisis de clases §15 y contexto §17.18 sincronizados. No se agregan tablas, columnas, modelos ni migraciones: permanecen 28 entidades, 239 atributos y 43 relaciones; DER vigente y diagrama global pendiente de fase 8. **CU-03 completo sigue pendiente** de fases 4/6; frontend posterior. La siguiente entrega es administración de acceso/roles y último Administrador.

**Resultado final de fase 3:** suite completa con **404 pruebas y 3291 aserciones correctas** (146,07 s). Composer validate/autoload PSR-4 estrictos, Pint de 46 PHP, sintaxis de 47 PHP incluida la plantilla, siete rutas API, formato/JSON/referencias documentales y git diff --check correctos. Desarrollo conserva siete usuarios, dos eventos, cero tokens y 29 migraciones; seguridad usa transporte array y no se enviaron correos reales ni se ejecutaron seeders. Entrega real con Brevo pendiente de configuración privada y comprobación autorizada.

## Usuarios y autenticación — fase 4 backend, acceso/roles y último Administrador (CU-03 parcial)

### Contratos de cuenta

Todos requieren Administrador con sesión normal y origen permitido; los PATCH exigen CSRF.

| Endpoint | Uso / respuesta |
| --- | --- |
| GET `/api/usuarios` | Filtros opcionales rol, estado_cuenta y correo_acceso exacto normalizado; page/per_page, 20 por defecto y 100 máximo. Orden ID descendente fijo. 200 data/links/meta. |
| GET `/api/usuarios/{id}` | Datos administrativos mínimos, ID BIGINT validado. 200 data; 404 para inexistente o Paciente sin verificación/vínculo previo. |
| PATCH `/api/usuarios/{id}/estado` | Cuerpo solo estado_cuenta=Activa o Desactivada. 200 data; exige transición real desde estado opuesto y credencial/verificación/vínculo legítimos. |
| PATCH `/api/usuarios/{id}/rol` | Cuerpo rol=Administrador/Recepcionista/Médico; para Médico id_medico y, si es vínculo inicial, identidad_verificada=true. 200 data; conserva identidad y revoca sesiones. |

Ejemplos de cuerpos ficticios:

```json
{"estado_cuenta": "Desactivada"}
```

```json
{"rol": "Recepcionista"}
```

```json
{"rol": "Médico", "id_medico": "123", "identidad_verificada": true}
```

La declaración de identidad corresponde a una comprobación real del Administrador, no a una prueba automática por coincidencia de nombres. El backend también exige correspondencia de nombres/apellidos normalizados por caso/espacios, registro profesional existente/libre y vínculo histórico compatible; rechaza diferencias sin corregir identidades automáticamente. Con vínculo histórico solo acepta el mismo Médico y lo conserva al retirarle el rol. No crea horarios, consultorios, especialidades, citas o profesionales ni activa la ficha.

Para Paciente, el Resource devuelve únicamente id_usuario, correo_acceso, rol, estado_cuenta y correo_verificado_en, sin CI, ficha, clínica, hashes, contadores o tokens. Para personal agrega nombres administrativos/nombre visible e ID profesional histórico. Los Requests por cuenta rechazan campos adicionales y toman el objetivo exclusivamente de la ruta. No cambian correo, credenciales, identidad o rol del Paciente ni convierten Paciente↔personal.

Desactivar/reasignar rol revoca **todas** las sesiones en la misma transacción del cambio y auditoría. Reactivar también elimina cualquier sesión anterior: exige nuevo login, conserva hash/correo/verificación/bloqueo e identidad y no activa la ficha administrativa/profesional. No habilita ni cancela invitaciones Pendiente mediante cambio de estado. Los cambios sobre la cuenta propia cierran su contexto actual.

### Continuidad administrativa y límite clínico

Antes de modificar se toma un bloqueo advisory transaccional PostgreSQL reservado `(736201, 1)`, luego se bloquea/revalida actor y sesión vigente y después el objetivo. Tras aplicar el cambio se exige al menos un Administrador Activa/verificado/con hash/sin cambio obligatorio/con identidad administrativa utilizable. El bloqueo pasajero de login no lo excluye del cómputo. Si no queda ninguno, **409 ULTIMO_ADMINISTRADOR** y rollback, incluso de un vínculo profesional tentativo. El futuro restablecimiento temporal debe reutilizar este protocolo antes de restringir cuentas; no es un trigger para SQL externo.

Retirar Médico exige inactividad profesional expresa previa por CU-10 y ausencia comprobable de obligaciones: no admite citas Programada/Confirmada/Atendida ni consulta vinculada a cualquiera de sus citas. Atendida, consulta existente o iniciada_en=NULL no demuestran cierre documental. Una consulta con solicitudes de laboratorio también impide retiro. El modelo actual solo permite comprobar ausencia de atenciones o únicamente Cancelada/Inasistencia sin consulta; el resto devuelve **409 MEDICO_OBLIGACIONES_NO_RESUELTAS**. Conserva vínculo, historia y autores, sin cancelar, trasladar o inventar cierres. Los futuros flujos de reservas/actividad deben respetar el mismo bloqueo de Médico; esos módulos aún están pendientes.

Eventos de éxito: responsable/objetivo/roles y cambios_seguros con antes/después de rol, estado e ID profesional, sin clínica ni secretos. Conflictos autorizados se auditan como denegados tras revertir; errores SQL/auditoría devuelven 500 seguro sin cambio parcial. 401/403/419/422/404/409 conservan su semántica centralizada.

### Verificación y alcance

AdministracionCuentaTest cubre cuatro roles, sesiones múltiples/reactivación/bloqueos, cuenta propia/último Admin, identidad/vínculo histórico, rechazos clínicos, consulta mínima/filtros/paginación, campos ajenos y fallos de auditoría/revocación. AutenticacionConcurrenteTest prueba con procesos PostgreSQL independientes retiros simultáneos de dos últimos Admin, retiros cruzados, actor revocado mientras espera coordinación, respuesta tardía de login frente a desactivación y competencia por mismo vínculo profesional.

```powershell
php artisan test --compact --no-ansi tests/Feature/AdministracionCuentaTest.php tests/Feature/AutenticacionConcurrenteTest.php tests/Feature/InvitacionPersonalTest.php tests/Feature/CambioClaveTest.php tests/Feature/AutenticacionTest.php
php artisan route:list --path=api/usuarios -v
```

Regresión focalizada conjunta: **157 pruebas y 2053 aserciones correctas**, más **1 prueba/9 aserciones** de rollback del vínculo tentativo por último Administrador. **Suite completa: 438 pruebas y 3834 aserciones correctas** (135,66 s).

Comprobaciones de cierre: Composer validate/autoload PSR-4 estrictos, Pint de 55 PHP, sintaxis de 56 PHP/Blade, cuatro rutas nuevas de cuentas, formato/JSON/referencias documentales y git diff --check correctos. Desarrollo conserva siete usuarios, dos eventos, cero tokens y 29 migraciones; transporte de seguridad array. No se ejecutaron seeders, migraciones de desarrollo o correos reales ni se modificaron credenciales privadas en esta fase.

Análisis §16 y contexto §17.19 sincronizados. DER contrastado y sin cambios de esquema/modelos/migraciones: 28 entidades, 239 atributos, 43 relaciones. Diagrama global todavía pendiente de fase 8. CU-03 completo requiere el restablecimiento temporal de fase 6; la **siguiente entrega es fase 5, recuperación por correo de los cuatro roles (CU-06)**. React sigue para después del backend.

## Usuarios y autenticación — fase 5 backend, CU-06 (2026-10-19)

### API de recuperación

Ambos endpoints son públicos, exigen origen SPA permitido y CSRF y se declaran en `src/presentation/routes/auth.php`. Obtener antes `GET /sanctum/csrf-cookie` y conservar cookies/cabecera XSRF, como en login.

**POST `/api/auth/password/forgot`** recibe:

```json
{
  "correo_acceso": "persona@example.test"
}
```

Normaliza el correo conforme al acceso actual. Responde **202**, privado/no-store, con el mismo cuerpo para cuenta elegible, inexistente, pendiente, desactivada o no vinculada, incluso si falla el envío:

```json
{
  "data": {
    "message": "Si corresponde, recibirás un enlace para recuperar tu contraseña."
  }
}
```

La consulta de cuenta, emisión y envío se ejecutan en terminación mediante `defer`. El cuerpo incluye Content-Length para que el cliente pueda completarlo antes del cierre de conexión. El 202 acepta la solicitud: no confirma existencia, emisión, entrega ni cambio de contraseña. Una prueba HTTP real con transporte bloqueado comprueba la finalización del cliente antes de liberar el envío. La topología productiva, incluidos buffering/proxy y finalización FastCGI, requiere su propia verificación de despliegue.

Cuando corresponde, emite un secreto aleatorio de 256 bits, persiste solo HMAC-SHA256 con propósito `RecuperacionClave`, vigencia de 60 minutos y consumo único. Cada emisión invalida las recuperaciones anteriores bajo bloqueo del mismo Usuario. La URL usa únicamente FRONTEND_URL configurado y `/recuperar-clave#token=...`; la pantalla React está pendiente. El secreto del fragmento debe enviarse por POST/JSON, nunca consultarse por GET.

**POST `/api/auth/password/reset`** recibe:

```json
{
  "token": "<secreto hexadecimal de 64 caracteres recibido por correo>",
  "password": "Nueva frase ficticia 456",
  "password_confirmation": "Nueva frase ficticia 456"
}
```

Tras confirmar devuelve **204 sin cuerpo** y exige nuevo login; no autentica al titular. El ID/rol/estado enviados no seleccionan ni modifican la cuenta. Token mal formado o contraseña inválida: 422 VALIDACION_ERROR; enlace inexistente, vencido, consumido, invalidado, de otro propósito o cuenta no elegible: 422 RECUPERACION_INVALIDA genérico. Origen ajeno: 403; CSRF: 419; límites: 429 con Retry-After; fallo necesario de persistencia/auditoría en consumo: 500 seguro con rollback.

### Reglas, coordinación y correo

- Los cuatro roles requieren cuenta Activa, correo previamente verificado, hash y vínculo de identidad compatible. La actividad profesional/ficha y el bloqueo temporal son independientes: se conservan, y recuperar no omite el bloqueo de login. Mientras fase 6 no defina la coordinación del flujo temporal, una cuenta con cambio obligatorio no emite ni consume recuperación; no se quita esa marca ni se alteran tokens CredencialTemporal por CU-06.
- Reutiliza `ClaveRequest`: mínimo 12 caracteres, confirmación, espacios/caso/Unicode preservados; con bcrypt actual, máximo 72 bytes y sin NUL. No requiere la clave anterior.
- El Service bloquea Usuario antes de TokenAcceso y revalida elegibilidad/vigencia/uso. Hash nuevo, consumo, invalidación de otras recuperaciones, revocación de todas las sesiones y evento de éxito confirman en una sola transacción. Conserva identidad, permisos, vínculos e historial. Si el navegador pertenece al titular, cierra también su contexto actual; una sesión de otra cuenta no se sustituye por la recuperada.
- CU-05 y los cambios administrativos de estado/rol invalidan las recuperaciones anteriores en su misma transacción. Se prueba la carrera con emisión/consumo, cambio ordinario, desactivación y respuestas de login tardías. Invitaciones y CredencialTemporal conservan propósitos separados.
- Parámetros técnicos en `config/seguridad.php`, separados de login/invitaciones: solicitud y consumo, cada acción con 20 peticiones/minuto/IP y cinco/minuto/ámbito (correo o token); solicitud además cinco/hora/correo, compartidas entre orígenes y aplicadas también a correos inexistentes. Controles persistidos como HMAC, sin correo/token en claro. Los rechazos no prolongan ventanas ni modifican contador/bloqueo de login. No se hereda la espera de 60 s de invitaciones como regla nueva de CU-06.
- Correo de seguridad reutiliza el mailer Brevo, actualmente HTTP, y su configuración privada descrita arriba. `DB::afterCommit` envía solo después de confirmar token/auditoría; resultado de envío se registra después en transacción independiente. Un fallo de transporte o de auditoría posterior al commit no revierte lo ya confirmado ni cambia el 202. Logs solo con tipo de excepción/correlación; sin correo, secreto, contraseña o SQL. No hay cola/outbox persistida ni reintento automático: ante interrupción o falta de entrega se solicita otro enlace, dentro de los límites.

### Pruebas y alcance

`RecuperacionClaveTest` cubre los cuatro roles, privacidad, claves, estados/vínculos, fronteras de vigencia, propósito/reemisión, revocación multidispositivo, bloqueo preservado, fallos SMTP/auditoría y coordinación con CU-05/acceso/rol. `AutenticacionConcurrenteTest` amplía las barreras PostgreSQL para consumos/emisiones simultáneos, emisión frente a consumo, cambio ordinario, desactivación y sesión tardía. `tests/Fixtures/recuperacion-http.php` es solo el servidor loopback de pruebas, con validación de base/rol/esquema aislados y transporte simulado.

```powershell
php artisan test --compact --no-ansi tests/Feature/RecuperacionClaveTest.php tests/Feature/AutenticacionConcurrenteTest.php
php artisan test --compact --no-ansi
php artisan route:list --path=api/auth -v
```

Documentación sincronizada: análisis §18 y contexto §17.21. DER contrastado, sin tablas, atributos, relaciones o migraciones nuevas: 28 entidades, 239 atributos, 43 relaciones. Diagrama global pendiente del cierre backend en fase 8. CU-06 tiene implementación backend con correo simulado; entrega real Brevo e integración React pendientes. La próxima fase del plan es la 6, cuyos parámetros funcionales deben concretarse antes de implementarla.

Comprobaciones de fase 5: Composer validate/autoload PSR-4 estrictos, Pint de 61 PHP del alcance, sintaxis de 66 PHP/Blade, UTF-8/cercas/JSON documentales y git diff --check correctos. Registro efectivo: 15 rutas, 20 combinaciones método/dominio/URI, sin duplicados. Lectura de desarrollo conserva mailer array, siete usuarios, dos eventos, cero tokens y 29 migraciones; no se ejecutaron seeders, migraciones de desarrollo ni envíos reales.

**Suite completa de fase 5: 480 pruebas y 4445 aserciones correctas** (152,40 s, margen de 300 s), con PostgreSQL aislado y correo simulado. Incluye las 36 pruebas de recuperación y seis carreras nuevas, además de toda la regresión previa.

## Adaptación a la API HTTP de Brevo — 2026-10-19

Cambio aprobado del transporte de los correos de seguridad de CU-03/CU-06. Se conserva el flujo:

```text
Service del CU → afterCommit → CorreoSeguridadService → Mailable/plantilla
    → mailer brevo → BrevoHttpTransport → cliente HTTP Laravel → API Brevo
```

`src/shared/utils/BrevoHttpTransport.php` adapta el correo ya renderizado a sender/to/subject/htmlContent. Se registra mediante un puente de infraestructura en `app/Providers/AppServiceProvider.php`; `config/mail.php` selecciona brevo-http y `config/services.php` lee BREVO_API_KEY. Conserva InvitacionPersonalMail y RecuperacionClaveMail, sus fragmentos de enlace y las respuestas/auditoría existentes. El transporte se limita a un destinatario de seguridad, sin copias o adjuntos. No incorpora dependencias ni migraciones.

Para habilitarlo en una instalación, configurar privadamente los valores siguientes, sin copiar secretos a documentación o al frontend:

```dotenv
SEGURIDAD_MAILER=brevo
BREVO_API_KEY=
MAIL_FROM_ADDRESS=remitente_verificado@example.test
MAIL_FROM_NAME="Clínica Vida Salud"
```

BREVO_API_KEY debe completarse con una clave nueva privada y MAIL_FROM_ADDRESS con el remitente real autorizado. El ejemplo no es una cuenta configurada. Tras editar el entorno local, ejecutar `php artisan config:clear`. MAIL_FROM del proyecto anterior corresponde aquí a MAIL_FROM_ADDRESS; MAIL_PROVIDER y RECOVERY_ENABLED no se utilizan. BREVO_SMTP_USERNAME/BREVO_SMTP_KEY dejan de ser necesarios para este transporte. El valor predeterminado sigue en array hasta habilitar explícitamente Brevo; no se configuraron secretos ni se realizó una entrega real durante la adaptación.

`BrevoHttpTransportTest` usa mailer Laravel real con Http::fake: valida endpoint/cabeceras/cuerpo, tiempos y HTTPS, 201/messageId, errores 400/401/429/503, redirección rechazada, estados inesperados, confirmación inválida y excepción de conexión/timeout sin datos externos encadenados. Las pruebas de invitación y recuperación comprueban aceptación/rechazo HTTP posterior al commit y reemisión, manteniendo la respuesta pública genérica de recuperación. Tests\TestCase y los workers bloquean peticiones HTTP externas sin simulación explícita; la prueba de navegador loopback conserva su transporte controlado.

Regresión focalizada: **94 pruebas y 1103 aserciones correctas** (14,84 s), con HTTP simulado y PostgreSQL aislado donde corresponde. Comando reproducible:

```powershell
php artisan test --compact --no-ansi tests/Feature/BrevoHttpTransportTest.php tests/Feature/InvitacionPersonalTest.php tests/Feature/RecuperacionClaveTest.php
```

Documentación sincronizada: contexto §17.22 y análisis §19, con colaboraciones actualizadas en §§15/18. El DER mantiene 28 entidades, 239 atributos y 43 relaciones; diagrama global pendiente de fase 8, sin cierre nuevo del módulo. Referencia del proveedor: https://developers.brevo.com/reference/sendtransacemail.

**Suite completa tras la adaptación: 501 pruebas y 4575 aserciones correctas** (145,67 s). Composer validate y autoload PSR-4 estrictos, sintaxis/Pint de los 14 PHP afectados, UTF-8/cercas/JSON documentales y git diff --check correctos. Revisión de desarrollo de solo lectura: transporte Brevo brevo-http, seguridad todavía array y API key sin configurar; siete usuarios, dos eventos, cero tokens y 29 migraciones. Activación del envío real y entrega a buzón pendientes de configuración privada; durante esta tarea solo se realizaron envíos simulados.

## Fase 6 backend — credencial temporal del personal, CU-03 (2026-10-19)

Política aprobada por el usuario e implementada en `CredencialTemporalService`, con validación reutilizable en `business/validators/CredencialTemporalValidator.php` e integración en login, sesiones y administración de estado/rol. Detalle de clases/colaboraciones en análisis §20; reglas §4.4 y contexto §17.23. Completa el bloque backend de restablecimiento del personal; no completa CU-04 ni el cierre integrado del módulo o React.

### Emisión administrativa y entrega presencial

```http
POST /api/usuarios/{id}/credencial-temporal
Content-Type: application/json
```

```json
{
  "identidad_verificada": true
}
```

Requiere Administrador con sesión **normal**, origen SPA autorizado, CSRF válido y límites. ID BIGINT de ruta; no admite IDs/campos privilegiados alternativos en cuerpo. El Administrador debe comprobar presencialmente identidad del trabajador antes de declarar true. Solo personal Activo/previamente verificado/con vínculo legítimo: Administrador, Recepcionista o Médico; nunca Paciente, cuenta Pendiente o Desactivada. Médico profesionalmente inactivo conserva este acceso si su cuenta sigue Activa.

Respuesta **201** con `data.id_usuario` como cadena, `data.credencial_temporal` y `data.expira_en`; `Cache-Control: no-store, private`. La credencial de **20 caracteres aleatorios** se entrega **una sola vez al Administrador**, para comunicación presencial. No se puede volver a consultar en listados/detalles ni se envía por correo; no registrar, guardar en el navegador ni tratarla como contraseña permanente. El servidor conserva solo HMAC-SHA256 separado por propósito en TokenAcceso/CredencialTemporal.

- **30 minutos** desde emisión para ingresar y completar; login/reingreso no prolongan vigencia.
- Emisión conserva hash anterior pero impide usarlo, marca cambio obligatorio, revoca todas las sesiones e invalida anteriores temporales/recuperaciones. No modifica bloqueo, intentos, correo, rol, identidad ni ficha/Médico.
- Protege al último Administrador con el protocolo global existente antes de bloquear actor/objetivo. Restricción de cuenta propia solo procede si queda otro Admin habilitado y cierra su propia sesión normal.
- **60 segundos** entre emisiones y **cinco emisiones/hora/cuenta**, compartidos entre Administradores/IP. Cada nueva emisión invalida la anterior.

### Login, estado restringido y cambio definitivo

Se ingresa por el **POST `/api/auth/login` existente**, con `correo_acceso` y `password` temporal. Aplica el bloqueo aprobado de tres errores/diez minutos y respeta bloqueos vigentes. Devuelve únicamente:

```json
{
  "data": {
    "acceso": "cambio_clave",
    "cambio_clave_obligatorio": true
  }
}
```

GET `/api/auth/me` restaura ese mismo estado mínimo en sesión restringida, sin perfil/rol/permisos ordinarios. En sesión normal conserva su contrato anterior. El payload de sesión permanece cifrado y solo añade referencia técnica del token/tipo de contexto. Se admite reingreso antes de completar dentro de la vigencia, con **una sola sesión restringida vigente**: el ingreso posterior revoca la anterior. La credencial se consume únicamente al confirmar el cambio definitivo.

```http
PUT /api/auth/password/temporal
Content-Type: application/json
```

Entrada: `password` y `password_confirmation`, mínimo 12 caracteres y los mismos límites técnicos de ClaveRequest/bcrypt; espacios/caso/Unicode preservados. No requiere contraseña anterior y la cuenta procede exclusivamente de la sesión. Solo sesión restringida vigente/origen/CSRF; una sesión normal recibe 403. Al confirmar devuelve **204 sin cuerpo**: guarda definitiva, consume/invalida temporales/recuperaciones, elimina cambio obligatorio y revoca/cierra sesiones en una transacción con auditoría. Exige **nuevo login** con la contraseña definitiva; no convierte automáticamente la sesión restringida en normal.

PUT `/api/auth/password` ordinario y las operaciones normales mantienen ExigirSesionNormal: una restringida válida recibe **403 AUTH_CAMBIO_CLAVE_OBLIGATORIO**. POST `/api/auth/logout` con sesión restringida vigente invalida la credencial, conserva cambio obligatorio y exige reemisión. Vencer sin completar también exige reemisión, sin restaurar el hash anterior. Cerrar una pestaña sin pedir logout no cierra la sesión del servidor; se permite el reingreso aprobado hasta vencer.

CU-06 no emite ni consume recuperaciones durante cambio obligatorio, y no elimina esa marca. Cambios administrativos de estado/rol invalidan temporales y sesiones; reactivar no restablece credenciales. Service revalida Usuario→Token/sesión persistida tras esperar bloqueos y vigencia tras calcular hash, protegiendo contra completados dobles, CU-05/CU-06 y cambios concurrentes. Las emisiones toman global→actor→objetivo→tokens; no se invierte el protocolo del último Admin.

### Errores, límites y evidencia

409 `CREDENCIAL_TEMPORAL_NO_PERMITIDA` para cuenta no elegible, 409 `ULTIMO_ADMINISTRADOR`, 404 objetivo inexistente, 422 campos inválidos, 403 sin permiso/origen y 429/Retry-After para límites. Login inválido conserva 401 genérico. Sesiones eliminadas pueden responder 419 por CSRF antes de autenticación en escrituras; refrescar CSRF no recupera acceso y me devuelve 401. Limitador técnico separado por acción: 20 solicitudes/minuto/IP y cinco/minuto/actor, HMAC/ControlIntentos/config/seguridad.php, sin ampliar ventanas ni bloquear login.

Pruebas focalizadas **67 pruebas/1042 aserciones correctas** (36,42 s): 21 de `CredencialTemporalTest` y 46 de `AutenticacionConcurrenteTest`, incluidas regresiones. Usan cookies/CSRF reales, PostgreSQL aislado, barreras/procesos independientes y correo simulado. Cubren roles/identidad, entrega/consumo, vencimiento/logout/reingreso único, reemisión/cupo compartido, bloqueo, último Admin paralelo/cruzado, CU-05/CU-06 concurrentes, revocación entre middleware/Service, guardado tardío y rollback de auditoría sin secretos.

```powershell
php artisan test --compact --no-ansi tests/Feature/CredencialTemporalTest.php tests/Feature/AutenticacionConcurrenteTest.php
```

No se requiere migración nueva: reutiliza propósito ya aplicado, **28 entidades/239 atributos/43 relaciones**, con referencia técnica de sesión en payload cifrado nativo. Documentación funcional, análisis, contexto y nota del DER sincronizados. El diagrama global de clases sigue pendiente de fase 8, al cerrar el módulo; siguiente entrega fase 7A/CU-04 nuevo y después 7B/vinculación.

**Configuración privada posterior a la adaptación HTTP:** por autorización expresa ya se guardaron API key/remitente/nombre en `.env` ignorado, se habilitó `SEGURIDAD_MAILER=brevo` y se comprobó carga efectiva tras config:clear, sin reproducir secretos. No se ha verificado externamente remitente/entrega ni enviado correo real. El usuario aplazó esa prueba al recorrido integrado con React; las pruebas automatizadas siguen simuladas. Esta fase no envía correo ni modifica `.env`.

**Cierre verificado de fase 6:** suite completa **538 pruebas y 5234 aserciones correctas** (161,61 s). Composer validate/autoload optimizado PSR-4 estrictos, Pint y sintaxis de 21 PHP correctos. Registro efectivo de 17 rutas/22 combinaciones método/dominio/URI sin duplicados (15 API y dos de infraestructura); `.env` ignorado. Formato/JSON/referencias documentales y git diff --check revisados al finalizar. Sin migraciones/seeders de desarrollo ni envío real; fase 6 backend completada y siguiente entrega 7A.

## Fase 7A backend — registro de Paciente nuevo, escenario A de CU-04

Implementa el registro nuevo y verificación de correo, conservando CI obligatorio, proceso de 24 h y activación atómica. Esta entrega no vinculaba fichas históricas automáticamente; escenario B/código de Recepción se incorporan en fase 7B, descrita a continuación. Evidencia histórica del análisis §21, reglas §4.1, CU-04 y contexto §17.24.

### Contratos

| Endpoint | Entrada / resultado |
| --- | --- |
| POST `/api/auth/registro` | Datos de registro y contraseña/confirmación. 202 genérico; cookie de contexto cifrada/HttpOnly. |
| POST `/api/auth/registro/reenviar` | Contexto de cookie, sin ID/correo para tomar un proceso ajeno. 202 idéntico, sin confirmar emisión/entrega. |
| POST `/api/auth/registro/verificar` | codigo como string de seis dígitos y cookie protegida. 204 al crear cuenta/ficha y consumir código; exige login posterior. |

Inicio acepta:

```json
{
  "documento_identidad": "000123-A lp",
  "nombres": "Paciente",
  "apellidos": "Ficticio",
  "fecha_nacimiento": "1990-01-01",
  "telefono": "70000000",
  "sexo": null,
  "correo_acceso": "paciente@example.test",
  "password": "Frase ficticia larga 123",
  "password_confirmation": "Frase ficticia larga 123"
}
```

CI, nombres, apellidos, nacimiento no futuro, teléfono y correo/contraseña son obligatorios. Sexo, dirección y correo de contacto son opcionales; contacto no se copia automáticamente desde correo de acceso. No se admiten rol/estado/IDs/datos_registro u otros campos adicionales en inicio. Política de contraseña compartida con ClaveRequest. CI conserva caso, signos, espacios interiores y ceros; solo se recortan espacios ASCII exteriores, string máximo 50 sin caracteres de control. No se inventa un patrón civil ni catálogo de sexo/edad/país.

Todos requieren origen SPA permitido y CSRF. Sesión autenticada: 409 AUTH_SESION_EXISTENTE. La cookie `clinica_registro_paciente` es HttpOnly, cifrada por Sanctum/EncryptCookies, host-only y de 24 h; Secure/SameSite según configuración. Contiene secreto opaco de 256 bits; la base conserva solo HMAC como metadato de datos_registro. No es sesión normal ni permite consultar perfil/historia. Conservar cookies en Axios; no extraer ni guardar secretos en localStorage. La sesión CSRF puede renovarse sin perder ese contexto de registro.

### Reintentos, códigos y activación

- Inicio y reenvío siempre responden solicitud recibida y posible código, aclarando que **no confirman envío** y orientando a reenvío/Recepción/acceso/vinculación. No revelan si CI/correo pertenecen a ficha/cuenta, ni devuelven ID de proceso o código. Hash de contraseña uniforme; consultas/reserva/código/correo mediante defer después de responder y Content-Length para delimitar cuerpo.
- Antes de verificar solo existe RegistroPacientePendiente/Nuevo. Un reintento con el mismo contexto conserva datos/clave/24 h originales; CI/correo no permiten reanudar otro proceso. Si se pierde la cookie, no se transfiere identidad a otro navegador basándose en esos datos.
- Código de **seis dígitos**, **diez minutos** como máximo, recortado al fin de las 24 h; HMAC ligado al proceso, cinco errores y un solo consumo. Al quinto error invalida ese código, sin crear cuenta.
- Reenvío: **60 segundos** y **cinco emisiones/hora por proceso y correo**, incluso entre contextos/IP o ante envío fallido. Nuevo código distinto de anteriores/invalida previos; no extiende registro. Los cupos se aplican internamente sin cambiar el 202 según existencia.
- Verificar con código correcto revalida unicidad, crea una cuenta Activa de rol Paciente con hash seguro y una ficha activa vinculada, consume código, limpia hash/datos temporales y audita en una transacción. Devuelve **204**, cierra contexto anónimo/borra cookie y exige **nuevo login**, sin autenticación automática.
- Vencimiento/incompatibilidad cierra proceso y limpia secretos al acceder; un nuevo inicio puede crear otro pendiente tras cerrar reservas vencidas. No borra fichas ni historia ni crea antecedentes/preferencias ficticios.

Errores: 422 VALIDACION_ERROR por campos; 422 REGISTRO_VERIFICACION_INVALIDA por código/contexto/conflicto no válido, sin enumeración; 403 origen, 419 CSRF y 429/Retry-After por limitador técnico (20/minuto/IP y cinco/minuto/correo o contexto por acción). No se crea otro rol ni se habilita cuenta del personal.

### Capas, concurrencia y correo

RegistrarPacienteRequest/VerificarRegistroPacienteRequest → RegistroPacienteController → RegistroPacienteService → RegistroPacienteRepository/UsuarioRepository → modelos existentes. La utilidad RegistroPacienteContexto concentra HMAC y VerificacionPacienteMail renderiza la plantilla de seguridad; el transporte HTTP Brevo se reutiliza sin dependencias nuevas.

Las transacciones comparten global PostgreSQL `(736202, 1)` antes de proceso/código; el correo sale solo afterCommit y fuera del bloqueo. Futura vinculación/altas administrativas que compitan por reservas deberán reutilizar el protocolo. Las UQ de CI/correo/cuenta siguen siendo barrera final para escritores externos: colisión 23505 revierte cuenta/ficha/consumo y devuelve 422 seguro. Fallo necesario de auditoría/escritura hace rollback íntegro; envío fallido conserva pendiente reintentable, se audita y nunca se anuncia entregado. Sin cola/outbox ni reintentos HTTP automáticos; comprobar buffering/proxy/finalización en despliegue para defer.

`RegistroPacienteTest`: **31 pruebas/323 aserciones correctas**. Se verificaron también seis carreras nuevas en `AutenticacionConcurrenteTest` junto con las previas, incluyendo conflictos reales de índices con altas administrativas/personal aún no confirmadas. Cubren código/proceso/contexto, normalización/roles, fronteras/límites, reanudación, sesión expirada, consumo único, cinco errores concurrentes, reenvío, rollback de auditoría y Brevo HTTP rechazado simulado. Ningún correo real se envió.

```powershell
php artisan test --compact --no-ansi tests/Feature/RegistroPacienteTest.php tests/Feature/AutenticacionConcurrenteTest.php
```

Documentación sincronizada en contexto/análisis/nota funcional del DER y corrección de referencia antigua de fase 6 en requerimientos. Sin nueva migración ni cambio de inventario: **28 entidades/239 atributos/43 relaciones**. Diagrama global pendiente de fase 8, CU-04 todavía parcial y entrega real Brevo/React posteriores conforme a lo acordado.

**Cierre verificado de fase 7A:** suite completa **575 pruebas y 5648 aserciones correctas** (178,35 s). Composer validate/autoload optimizado PSR-4 estrictos, Pint de 18 PHP, sintaxis de 19 PHP/Blade y 20 rutas/25 combinaciones método/dominio/URI sin duplicados. Formato/JSON/referencias documentales y git diff --check correctos; `.env` ignorado, sin migraciones/seeders de desarrollo ni correos reales. Siguiente entrega 7B.

## Fase 7B backend — vinculación histórica de CU-04 y emisión parcial de CU-07

La vinculación conserva la ficha y su historial: Recepción comprueba presencialmente identidad/CI y entrega una autorización; el Paciente confirma después su propio correo antes de crear la cuenta. Ficha inactiva puede vincularse sin activarse ni habilitar nuevas reservas. El CRUD administrativo restante de CU-07 se desarrollará posteriormente.

### Contratos para la integración React posterior

| Endpoint | Entrada | Resultado |
| --- | --- | --- |
| POST `/api/pacientes/{id}/codigo-activacion` | Solo `identidad_verificada: true`; ID de ficha de la ruta. Sesión normal de Recepcionista. | 201 con `data.id_paciente` como string, `codigo_activacion` y `expira_en` ISO-8601. Secreto visible una vez para entrega presencial; no-store. |
| POST `/api/auth/registro/vincular` | `documento_identidad`, `codigo_activacion`, `correo_acceso`, `password`, `password_confirmation`. Solicitante sin login. | 202 idéntico al registro Nuevo, sin confirmar existencia ni envío; cookie protegida de contexto. |
| POST `/api/auth/registro/reenviar` | Cookie de contexto, sin selector de proceso/cuenta. | Mismo 202 genérico para A/B. |
| POST `/api/auth/registro/verificar` | `codigo` de correo de seis dígitos y cookie. | 204 al confirmar el vínculo y consumir ambos códigos atómicamente. Cierra contexto/elimina cookie; exige nuevo login. |

Los endpoints usan origen SPA permitido y CSRF; Axios debe conservar cookies. No guardar códigos ni contexto en localStorage/sessionStorage. La declaración de identidad verificada representa la comprobación presencial de Recepción, no una validación automática de identidad por el documento. Emisión no admite cuentas ya vinculadas o fichas sin CI; Administrador/Médico/Paciente no pueden emitir. El acceso público exige CI/código/correo/clave y rechaza IDs, rol/estado, datos de perfil y otros campos ajenos. CI mantiene caso/signos/interiores/ceros y solo recorta espacios ASCII exteriores; también localiza fichas históricas con esa normalización sin reescribirlas.

### Autorizaciones, reserva y conservación

- Código presencial: **12 caracteres criptográficos alfanuméricos**, **24 horas**, **cinco errores**, **un solo uso**. Solo se persiste HMAC ligado a la ficha/separado por propósito. Reemplazarlo invalida anteriores y sus reservas/verificaciones de correo, limpiando secretos temporales en transacción.
- Inicio vincula el pendiente a la ficha/código originales y reserva exclusivamente para la cookie cifrada/HttpOnly/host-only de 7A. No consume aún el código presencial ni crea cuenta. El proceso vence en el plazo original de Recepción. Otra cookie no toma la reserva; la pérdida del contexto requiere asistencia/reemplazo presencial, sin transferencia por CI/correo.
- Reintento propio conserva correo, hash de contraseña y plazo; requiere autorización correcta y solo intenta reenvío controlado. El quinto error presencial invalida la autorización. Agotar o vencer el principal exige nueva comprobación presencial.
- Código del correo: **seis dígitos**, hasta **diez minutos** recortados al plazo principal, **cinco errores**. Reenvío **60 segundos/cinco emisiones por hora por proceso y correo**, compartido entre escenarios A/B y aun tras sustituciones o envíos fallidos. Sustituye el código anterior; no amplía las 24 horas principales. Agotar el correo permite reenvío mientras el principal siga válido.
- Confirmación comprueba ficha sin cuenta, CI original, ambos códigos y unicidad de correo; crea Usuario Activa/verificada de rol Paciente y revalida vigencias tras posibles esperas de escritura. Solo cambia el vínculo id_usuario y timestamp de la ficha; consume ambos códigos, limpia proceso y registra metadatos seguros. Conserva identificador, código, datos administrativos, estado activo/inactivo y todas las relaciones clínicas/administrativas.

### Capas, errores y concurrencia

Emisión: `EmitirCodigoActivacionRequest` → `CodigoActivacionController` → `CodigoActivacionService` → `CodigoActivacionRepository`/`RegistroPacienteRepository`; el Service revalida sesión/rol con `AutenticacionService` y audita. Vinculación: `VincularPacienteRequest` → `RegistroPacienteController` → `RegistroPacienteService`, compartiendo reenvío/verificación con 7A. `CodigoActivacionValidator` conserva las comprobaciones de documento/vigencia; `RegistroPacienteContexto` agrega HMAC con propósito de Recepción. Rutas en `pacientes.php` y `auth.php`, registradas una sola vez.

El global PostgreSQL `(736202,1)` precede ficha/proceso/códigos; emisión también revalida actor bajo bloqueo después de esperar. UQ parciales de ficha/código/CI/correo y finales de cuenta evitan reservas/vínculos duplicados. Emisión simultánea deja una sola autorización vigente; reemplazo y confirmación se serializan: si se vincula primero, no se reemite; si se sustituye primero, no se consume la autorización antigua. Error de escritura/UQ/auditoría hace rollback de cuenta/vínculo/consumos e invalidaciones. Correo solo afterCommit/fuera de locks; fallo mantiene proceso reintentable. No hay envíos reales durante pruebas.

Inicio/reenvío públicos mantienen el 202 sin distinguir CI/correo/código elegibles y sin confirmar entrega. Verificación: 422 `REGISTRO_VERIFICACION_INVALIDA` genérico. Emisión: 401 sesión, 403 rol/origen/restricción, 404 ficha inexistente, 409 `ACTIVACION_NO_PERMITIDA`, 419 CSRF, 422 campos y 429 límite técnico con Retry-After. Límites técnicos reutilizados: 20/minuto/IP y cinco/minuto por CI en vincular, por contexto en reenvío/verificar o por Recepcionista en emitir; no son un nuevo plazo funcional de reemisión. Bitácora registra emisión, inicio, intentos y vínculo con referencias seguras, sin códigos, hashes, claves, CI o información clínica.

### Verificación y cierre de fase

`VinculacionPacienteTest` comprueba permisos, identidad/CI, privacidad, sesión/CSRF/origen, reserva ajena, intentos/vencimientos/reenvíos, reanudación, reemplazo y fallos de correo/auditoría. Comprueba ficha inactiva y conservación exacta de filas de citas, consultas, antecedentes/versiones, solicitudes/documentos/resultados ficticios. Incluye normalización de CI histórico y rollback si el correo vence durante la escritura de cuenta. Ocho carreras nuevas en `AutenticacionConcurrenteTest` verifican reserva/consumo únicos, errores concurrentes, reemplazo, correo compartido con Nuevo, revocación de Recepción, colisión externa y emisión paralela.

```powershell
php artisan test --compact --no-ansi tests/Feature/VinculacionPacienteTest.php tests/Feature/RegistroPacienteTest.php tests/Feature/AutenticacionConcurrenteTest.php
```

Documentación sincronizada: `docs/ai/analisis-clases.md`, `contexto-maestro.md` y nota funcional de `diagrama-entidad-relacion.md`. Tres diagramas añadidos y **16 diagramas Mermaid** contrastados con métodos/atributos reales y renderizados. Sin migraciones ni cambios de inventario: **28 entidades/239 atributos/43 relaciones**. Ambos escenarios de CU-04 implementados en backend; el cierre integrado y diagrama global `docs/ai/diagrama-clases.md` corresponden a **fase 8**. React, entrega real de Brevo y CRUD restante de CU-07 siguen pendientes.

**Cierre verificado de fase 7B:** suite completa final **614 pruebas y 6193 aserciones correctas** (207,87 s): 31 pruebas nuevas de vinculación y ocho carreras sobre las 575 previas. Composer validate/autoload optimizado PSR-4 y ambigüedad estrictos, Pint y sintaxis de 19 PHP correctos. **22 rutas/27 combinaciones método-dominio-URI sin duplicados** (20 API y dos de infraestructura). Mermaid 10.9.5/Chrome headless validan los 16 diagramas sin solapamiento de nodos; tres nuevos revisados visualmente y expediente anterior conservado. UTF-8/cercas/JSON documental y git diff --check correctos. Secretos ignorados, sin migraciones/seeders de desarrollo ni correo real. Siguiente entrega: **fase 8 de integración/cierre backend**.

## Fase 8 — cierre integrado del backend de Usuarios y autenticación (2026-10-19)

**Estado:** backend de CU-01–CU-06 implementado e integrado, incluida la emisión de código de Recepción necesaria para CU-04 B. Por decisión expresa del usuario, **`docs/ai/diagrama-clases.md` se elaborará en otro momento**; no se crea ni se declara actualizado. El cierre de React y navegador real es posterior.

### Inventario consolidado para consumir la API

Todas las rutas `/api/*` exigen origen stateful permitido; sus escrituras requieren CSRF. Acceso «público» significa sin login requerido, no sin estas protecciones. Los IDs se proyectan como strings. Contratos de entrada/campos completos, límites y alternativas se conservan en las fases anteriores y en el análisis vigente por CU.

| Método / ruta | Acceso | Resultado de éxito |
| --- | --- | --- |
| POST `/api/auth/login` | Sin sesión previa; correo/contraseña | 200 `data` con usuario público o únicamente estado restringido de cambio obligatorio. |
| GET `/api/auth/me` | Sesión habilitada normal o restringida | 200 misma proyección normal/restringida; restaura estado sin habilitar funciones de otro rol. |
| POST `/api/auth/logout` | Contexto actual, también para limpiar sesión finalizada | 204 y revocación efectiva; cierre restringido invalida su temporal. |
| PUT `/api/auth/password` | Cualquiera de los cuatro roles con sesión normal | 204; renueva sesión actual y revoca anteriores, sin cambiar identidad/rol. |
| POST `/api/auth/password/forgot` | Público | 202 genérico; no confirma existencia/envío; procesamiento diferido. |
| POST `/api/auth/password/reset` | Token de recuperación válido | 204; consume enlace, revoca sesiones y exige nuevo login. |
| PUT `/api/auth/password/temporal` | Solo sesión restringida del personal | 204; consume temporal, cierra contexto y exige nuevo login normal. |
| POST `/api/auth/invitaciones/aceptar` | Público, invitación del personal | 204; establece primera clave/verifica correo/activa, sin login automático. |
| POST `/api/auth/registro` | Anónimo, Paciente nuevo | 202 genérico y cookie de contexto; sin cuenta/ficha definitiva hasta verificar. |
| POST `/api/auth/registro/vincular` | Anónimo, autorización presencial válida | 202 idéntico y reserva exclusiva; conserva ficha original. |
| POST `/api/auth/registro/reenviar` | Anónimo, contexto de cookie | 202 genérico, con límites internos compartidos por proceso/correo. |
| POST `/api/auth/registro/verificar` | Anónimo, contexto y código de correo | 204; crea cuenta/ficha nueva o vincula ficha histórica, exige login. |
| POST `/api/personal` | Administrador normal | 201 `data` y `meta.invitacion`; cuenta Pendiente y envío `aceptado`/`simulado`/`fallido` según resultado real, sin promesa de entrega. |
| POST `/api/personal/{id}/invitacion` | Administrador normal | 200 sobre la misma cuenta pendiente; nueva emisión invalida anterior. |
| GET `/api/usuarios` | Administrador normal | 200 `data/links/meta`; filtros permitidos y paginación limitada. |
| GET `/api/usuarios/{id}` | Administrador normal | 200 proyección administrativa mínima; sin credenciales ni clínica. |
| PATCH `/api/usuarios/{id}/estado` | Administrador normal | 200 nuevo estado; revoca sesiones y conserva vínculo/bloqueo/ficha. |
| PATCH `/api/usuarios/{id}/rol` | Administrador normal; solo transiciones del personal | 200 nuevo rol; revoca sesiones y conserva identidad/vínculos. |
| POST `/api/usuarios/{id}/credencial-temporal` | Administrador normal, identidad comprobada del personal | 201 secreto de entrega presencial única y vencimiento; nunca para Paciente. |
| POST `/api/pacientes/{id}/codigo-activacion` | Recepcionista normal, identidad/CI comprobados | 201 código presencial único y vencimiento; no crea cuenta. |
| GET `/sanctum/csrf-cookie` | Infraestructura de Sanctum | 204 y cookies para las peticiones stateful. |
| GET `/up` | Infraestructura de salud | 200; no representa una operación del dominio. |

`route:list` confirma **22 declaraciones/27 combinaciones método-dominio-URI sin duplicados**; GET incluye HEAD. Los cuatro archivos funcionales se registran una sola vez. Los 20 endpoints API corresponden al alcance anterior; no existe CRUD completo de pacientes ni endpoints clínicos implementados por este cierre.

### Resultado de la revisión y pruebas integradas

Se contrastaron CU/reglas, rutas, Controllers/FormRequests/Resources, Services, Repositories y modelos. Se mantienen permisos fijos, estado de cuenta separado de ficha/profesional, bloqueo 3/10, renovación/revocación de sesiones, protección del último Administrador, propósitos/vigencias de tokens, vinculación única y auditoría sin secretos. Se revisaron CORS con credenciales/origen exacto, CSRF, sesiones database cifradas y respuesta JSON segura con no-store; 204 sin cuerpo y 202 sin promesa de envío. El rechazo aprobado de retiro Médico con obligaciones no comprobables permanece vigente.

`tests/Feature/UsuariosAutenticacionIntegracionTest.php` añade **tres recorridos HTTP integrados**, **190 aserciones**, ejecutados correctamente en 1,68 s:

- Paciente nuevo → verificación → dos sesiones → CU-05 con renovación/revocación e invalidación de recuperación anterior → CU-06 → desactivación/reactivación administrativa → nuevo login/logout.
- Mismo recorrido para una ficha histórica **inactiva**, conservando la ficha original y su estado.
- Invitación de Recepcionista → temporal y exclusión de recuperación → cambio obligatorio/login → cambio autorizado a Médico con revocación → recuperación conservando identidad/rol/vínculo. Invitación y recuperación no intercambian propósitos; el Administrador mantiene su sesión propia.

Usan el Kernel y las cookies/CSRF reales, guard/Store restaurados por petición y sesiones database cifradas en PostgreSQL aislado. Se conserva la limitación técnica de solicitudes, avanzando el reloj entre etapas; Mail/HTTP están simulados. Complementan las pruebas específicas y carreras existentes, incluidas integridad histórica y rollback. No se detectó necesidad de cambiar lógica productiva, contratos o esquema. **28 entidades/239 atributos/43 relaciones**, con DER revisado sin cambios de persistencia.

Comandos de comprobación reproducibles:

```powershell
php artisan test --compact --no-ansi tests/Feature/UsuariosAutenticacionIntegracionTest.php
php artisan test --compact --no-ansi
php vendor/bin/pint --test app bootstrap/app.php bootstrap/providers.php config routes src tests
composer validate --strict
composer dump-autoload --optimize --strict-psr --strict-ambiguous
php artisan route:list
```

**Cierre verificado de fase 8:** suite completa **617 pruebas y 6383 aserciones correctas**, **229,82 s** (margen de ejecución 300 s). Pint comprueba **192 PHP fuente**; sintaxis correcta en **193 archivos** incluyendo artisan. Las cachés PHP generadas por Composer/Laravel se excluyen de la comprobación de estilo de fuentes. Composer validate, requisitos de plataforma y autoload PSR-4/ambigüedad estrictos correctos. Los 16 diagramas existentes pasan Mermaid 10.9.5/Chrome headless, correspondencia de métodos/atributos, 29 anclas/enlaces y conservación del expediente histórico. UTF-8/cercas/JSON documental, inventario README/rutas y git diff --check correctos. Sin cambios productivos/dependencias/persistencia, migraciones/seeders de desarrollo ni envíos reales; secretos privados preservados. **Backend cerrado y diagrama global aplazado según la decisión del usuario.**

### Pendientes posteriores al cierre backend

- **Diagrama global:** aplazado por decisión expresa; los 16 diagramas de análisis por procedimiento siguen disponibles, sin sustituirlo.
- **React/AUT-21:** pantallas, Axios/contexto de sesión, navegación por rol y prueba completa en navegador real, incluidos cookies, recarga, expiración y errores.
- **Correo real:** confirmar aceptación y llegada a buzón de invitación, verificación y recuperación. Brevo HTTP integrado/configurado privadamente, sin envío real durante estas pruebas.
- **Producción:** configuración HTTPS/dominios/CORS/cookies y comprobación de buffering/finalización de respuestas diferidas en la topología real; no hay despliegue verificado.
- **Otros alcances:** CRUD restante de CU-07, módulos siguientes y alternativa del Paciente sin acceso al correo no se incluyen ni se inventan en este cierre.

Documentación actualizada en `docs/ai/contexto-maestro.md` §17.26 y `docs/ai/analisis-clases.md` (cierre integrado y estado por CU). Requisitos/reglas/CU y DER estructural conservados; `diagrama-clases.md` no elaborado. **El backend queda cerrado; el módulo completo de cara al usuario exige la etapa frontend pendiente.**
