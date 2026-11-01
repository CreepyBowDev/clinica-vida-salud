# Primera versión funcional — validación local

**Fecha:** 2026-10-31. **Dictamen:** primera versión funcional validada en local, no certificación de producción ni entrega académica/documental completa. Sin cambiar numeración RF/RN/CU, reglas, dependencias o configuración persistente.

## 1. Regresión ejecutada

| Comprobación | Resultado y alcance |
| --- | --- |
| Frontend completo | `pnpm test --maxWorkers=4`: **3844/3844 pruebas, 136 archivos**, 197,71 s. La primera ejecución fue interrumpida por el límite de 120 s, sin resultado final; la ejecución completa posterior es la acreditada. |
| Build | `pnpm build` correcto; advertencia conocida de chunk >500 kB (936,45 kB), sin optimización/refactor ajeno. No existe script lint frontend; no se afirma ejecutado. |
| Backend completo | PHPUnit: **4522 pruebas, 64296 aserciones, 2 fallos**, 33 min 40,720 s. Ambos fallos eran inventarios de rutas de `MatrizPermisosBackendTest`, desactualizados tras agregar los siete GET de CU-29. El resto de casos de esa ejecución pasó. |
| Corrección dirigida backend | Se agregan únicamente los siete GET exclusivos de Administrador al dataset de esa prueba; no cambia lógica productiva. Repetición de **todo `MatrizPermisosBackendTest`: 751/751 pruebas, 3390 aserciones**, 10,284 s, incluyendo casos nuevos derivados del dataset. No se repitió la suite global tras esta corrección de prueba: no presentar la ejecución inicial como completamente verde ni sumar ambos recuentos como una sola suite. |
| Plataforma | `composer validate --no-check-publish --no-interaction` y `composer check-platform-reqs --no-interaction`: correctos; PHP 8.5.11 y requisitos instalados compatibles. Sin instalaciones/actualizaciones. |
| Integración real | **53 comprobaciones correctas** en Chrome headless: React/Vite → HTTP Laravel/Sanctum → PostgreSQL aislado. No adapter/CDP falso para API: CDP permite exclusivamente loopback y bloquea destinos externos. |
| Integridad | Preflight SQL antes/después: base y rol `clinica_vida_salud_test`, propietario sin privilegios elevados, `btree_gist` preinstalada y sin clientes concurrentes. Base habitual no conectada/migrada. Inventario/OID de objetos y esquemas iniciales conservados; esquema antiguo ajeno intacto. Esquema HTTP y credenciales efímeras eliminados; servidores propios cerrados. |

Comando backend global: `php -d memory_limit=768M vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --testsuite Feature --do-not-cache-result --colors=never`, con variables de proceso de testing, mail/cache de pruebas, `OPENSSL_CONF` y qpdf existente. No `composer test`, `config:clear`, `migrate:fresh`, `db:wipe` ni migraciones habituales. Las migraciones internas de tests se limitaron a la base exclusiva comprobada; el arnés HTTP migró solo su esquema nuevo.

## 2. Diez pruebas del examen por HTTP real

La transcripción oficial usada es `requerimientos.md` §8; no se modifica. El enunciado original PDF no se localizó en el repositorio. Pruebas sobre datos ficticios; identidades/catálogos/citas iniciales se preparan explícitamente como fixtures, no se presentan como altas verificadas mediante interfaz.

| Nº | Verificación ejecutada | Resultado |
| --- | --- | --- |
| 1 | Alta administrativa de paciente por API HTTP | 201, ID persistido; ficha sin cuenta web automática. |
| 2 | Repetir el mismo CI | 409. |
| 3 | Disponibilidad → propuesta → reserva | 200/200/201; nueva cita persistida. |
| 4 | Dos propuestas previas sobre el mismo turno; confirmar ambas | Primera 201, segunda 409. Coinciden Médico y consultorio; esta comprobación no aísla exclusivamente el conflicto del Médico. |
| 5 | Solicitar propuesta a las 23:00 fuera de horario 08:00–13:00 | 409; no reserva fuera de horario. |
| 6 | Cancelar la cita creada usando sus originales vigentes | 200, estado Cancelada. |
| 7 | Consultar historial de ese paciente | 200, conserva la misma cita Cancelada. |
| 8 | Consultar agenda por Médico/fecha | 200, incluye la cita registrada. |
| 9 | Abrir `/reportes` sin sesión y consultar API después de logout | Interfaz va a login; API devuelve 401. |
| 10 | Alta con nombre vacío | 422 del servidor. |

Las operaciones de esta tabla usan HTTP desde el navegador con cookies/CSRF reales; las siete pantallas de reportes y los avisos se operan además mediante React. No se afirma que las diez operaciones administrativas se hayan completado todas mediante formularios React en esta comprobación. Sus pruebas UI existentes sí forman parte de la regresión frontend completa.

## 3. Recorridos de integración actuales

- **Administrador:** login con retorno seguro, siete reportes desde `/reportes`, selección histórica y totales SQL esperados; Programadas 2, por Médico 6, por especialidad 6, por estado Cancelada 1, fichas registradas 4, cantidades 1/1/1/3 y agenda diaria 6 antes de las escrituras de examen. Consulta administrativa de dos avisos del Paciente seleccionado, sin exponer el aviso de otro titular. Logout real.
- **Recepcionista:** login, fichas administrativas y acceso a agenda; ruta de reportes denegada y GET directo de reportes 403. Logout real.
- **Médico:** login, agenda propia con citas de PostgreSQL; GET de reportes 403. Logout real.
- **Paciente:** login, historial propio sin cita de otro titular, dos avisos propios por HTTP, canal externo apagado sin impedir avisos internos, configuración/listado propios de dispositivos vacíos y GET de reportes 403. Logout seguido de API 401.
- **CU-28:** generación de tres avisos antes de iniciar sesiones de navegador mediante Service real, dos del titular y uno de otra ficha; cero despachos push. No se depende de una pestaña abierta para crear avisos. No acredita un scheduler permanentemente operativo ni entrega Web Push.
- **Presentación:** foco de resultados acreditado con HTTP real; avisos sin desbordes a 390/1280 px. RFE-5 conserva evidencia previa de 68 comprobaciones Chrome de los siete reportes a 320/390/768/1280/1440 px con API simulada. No convertir emulación en dispositivo móvil real ni controles automáticos en validación con lector de pantalla.

Los primeros intentos del arnés HTTP necesitaron ajustar su expectativa de retorno tras login, esperar catálogos asíncronos, guardar instantes ficticios como UTC explícito y excluir OPTIONS de la detección de escrituras. No requirieron correcciones productivas; cada intento limpió su propio esquema/credenciales. La ejecución final de 53 comprobaciones es la acreditada.

## 4. Límites antes de entrega/producción

- La v1 funcional local no implica despliegue ni migración de la base habitual. Preparar el entorno de uso y sus migraciones requiere autorización independiente.
- Correos de seguridad/Brevo se bloquearon/simularon; llegada real al buzón sigue pendiente. Web Push es ampliación opcional: sin alta de suscripción, proveedor/entrega real, claves nuevas, sonido o encendido acreditados.
- Laboratorio/consulta/versiones/descargas y concurrencia real PostgreSQL se ejercitaron en la suite backend; no se repitieron todos sus recorridos mediante navegador HTTP independiente. Se conservan límites históricos de módulos, configuración HTTPS/cookies, almacenamiento privado, respaldos y operación final del scheduler.
- Sin auditoría de penetración/carga productiva, revisión manual con lector de pantalla o validación en todos los SO/dispositivos. Análisis de clases y diagramas finales siguen aplazados a tarea explícita; no declarar entrega académica completa por esta validación.
- No se cambió versión de `package.json`, no se creó tag/release, commit/push ni despliegue. Sin nuevas funcionalidades o refactors.

## 5. Archivos y evidencias

Cambios de esta validación: `backend/tests/Feature/MatrizPermisosBackendTest.php` (dataset de permisos), este documento, corrección de estados obsoletos en `casos-de-us.md` §3.1 y relevo `estado-fase.md`. Se conservan todos los cambios locales previos. No análisis/diagramas ni código productivo backend/frontend nuevo.

Evidencias fuera del repositorio, en `C:/Users/jesus/AppData/Local/Temp/opencode/`:

- `cierre-v1-frontend-tests-completo.log`, `cierre-v1-build.log`.
- `cierre-v1-backend-tests.log`, `cierre-v1-matriz-retest.log`.
- `cierre-v1-composer-validate.log`, `cierre-v1-platform.log`.
- `cierre-v1-preflight.php`, `cierre-v1-preflight-{before,after,final}.log`.
- `cierre-v1-http.php`, `cierre-v1-http-browser.{mjs,log,json}`, capturas `cierre-v1-http-*.png` y evidencia de los intentos de ajuste. Los scripts quedan sin credenciales; el manifiesto privado fue eliminado.
