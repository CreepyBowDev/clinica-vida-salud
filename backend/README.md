# Backend — Clínica Vida Salud

## Estado de esta fase

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
- `routes/api.php` es el puente que carga `src/presentation/routes/api.php`; este último aún no declara rutas de negocio.
- `app/Providers/AppServiceProvider.php` registra `src/data/database/migrations/` mediante `loadMigrationsFrom`.
- Se conservaron los 19 archivos originales de `backend/`, incluidos `AGENTS.md`, `prueba.php` y los archivos de `src/`; se compararon sus hashes SHA-256.

Los archivos de `app/`, `bootstrap/`, `config/` y `routes/` son infraestructura del framework. Al desarrollar los CU, presentación adaptará HTTP, servicios aplicarán reglas y transacciones, repositorios accederán a Eloquent y modelos representarán las entidades aprobadas. Esta fase no agrega esas clases de negocio.

El esqueleto se obtuvo con `--no-install --no-scripts`. Se integraron únicamente los archivos necesarios; no se copiaron sus instrucciones de agentes, modelo `User`, factories, seeders, migraciones de usuarios/cache/jobs, aplicación Vite ni pantalla Blade inicial. Se retiraron los scripts que creaban SQLite o ejecutaban migraciones y npm. **No se ejecutó `migrate`, `install:api`, `db:seed` ni publicación de migraciones de Sanctum.**

## Sanctum, CORS y sesiones

- CORS permite el origen exacto `http://localhost:5173`, con credenciales. Rutas cubiertas: `api/*` y `sanctum/csrf-cookie`.
- Dominios stateful: `localhost:5173,localhost:8000`.
- `GET /sanctum/csrf-cookie` emite la cookie de sesión y `XSRF-TOKEN`; esta última es legible por JavaScript para el mecanismo CSRF de Laravel.
- Sesión `clinica_vida_salud_session`: cookie HttpOnly, SameSite=Lax y host-only (`SESSION_DOMAIN=null`). `Secure=false` corresponde al HTTP local aprobado; HTTPS y `Secure=true` deberán configurarse para producción.
- En la instalación inicial las sesiones eran temporales en archivo; **desde fase 2 usan la tabla nativa `sessions`, con contenido cifrado**. Caché en archivo, cola síncrona y mailer `array` en memoria se conservan.
- El disco local apunta a `storage/app/private`, sin rutas automáticas de entrega/subida de archivos ni enlace público creado.

**Integración actual y autenticación pendiente:** fase 2 registra `Usuario`, el guard de sesión `web`, el proveedor Eloquent `usuarios` y su uso por Sanctum. La tabla `sessions` y el manejador nativo se verificaron con sesiones anónimas y vinculadas a usuarios. `AuthenticateSession`, login/logout, bloqueo, estados/permisos y revocación automática se integrarán con sus CU. No se configura un broker paralelo de recuperación ni existen endpoints de credenciales o emisión de tokens. La eliminación por Usuario de varias sesiones se probó como soporte persistente, sin dar por completada su coordinación de negocio.

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

## Próximos pasos pendientes

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
