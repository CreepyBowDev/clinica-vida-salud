# Backend — Clínica Vida Salud

## Estado de esta fase

Preparación local verificada el **18 de octubre de 2026**. Se instaló la infraestructura de Laravel, PostgreSQL y Sanctum. **Ningún CU-01 a CU-30 está implementado por este trabajo.**

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
- Sesiones temporales en archivo **con contenido cifrado**; caché en archivo, cola síncrona y mailer `array` en memoria. No requieren tablas ni envían correo.
- El disco local apunta a `storage/app/private`, sin rutas automáticas de entrega/subida de archivos ni enlace público creado.

**Pendiente de autenticación:** `config/auth.php` no define guard/provider/modelo ni recuperación de contraseña. Sanctum tiene su lista de guards vacía y `authenticate_session=null`: se habilitarán el guard de sesión y `AuthenticateSession` al implementar el modelo aprobado. El paquete registra su infraestructura interna, pero no existen endpoints de login, registro, permisos ni emisión de tokens. La revocación efectiva de múltiples sesiones deberá diseñarse y probarse junto con autenticación; el driver de archivo actual es provisional.

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

`phpunit.xml` utiliza sesiones/caché/correo en memoria y fija una conexión PostgreSQL distinta (`clinica_vida_salud_test`, con otro usuario y sin contraseña configurada). **Esta base y este rol de pruebas no se crearon.** Las pruebas actuales no acceden a PostgreSQL. Antes de añadir pruebas de persistencia o concurrencia, preparar y verificar ese entorno aislado.

## Próximos pasos pendientes

- Revisar y aprobar el DER final antes de crear modelos, migraciones, usuarios o relaciones.
- Implementar autenticación, autorización y revocación de sesiones conforme a los CU aprobados.
- Preparar React/Axios, Brevo y cualquier infraestructura persistente cuando se aborde su alcance.
- Definir el programador de tareas y sus procesos cuando corresponda a CU-28.
- Configurar y verificar producción por separado.

Se actualizó `../docs/ai/contexto-maestro.md` con esta preparación. `analisis-clases.md`, `diagrama-clases.md` y `diagrama-entidad-relacion.md` todavía no existen en `docs/ai/`; su elaboración queda para el diseño y desarrollo correspondientes. No se declara cerrado ningún CU ni módulo.
