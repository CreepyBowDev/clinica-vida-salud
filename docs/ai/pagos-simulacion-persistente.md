# Pagos académicos simulados con persistencia — 2026-11-06

## Decisión y límites

Ampliación académica expresamente aprobada por el usuario, no exigencia del examen. Sustituye la opción A y la restricción exclusivamente visual para las obligaciones elegibles de citas creadas mediante el sistema. No sustituye los proveedores externos ni acredita Stripe/PayPal. La fixture `DEMO-CITA-001` permanece exclusivamente en `/pagos/demostracion`; el nuevo recorrido no utiliza su código ni importe.

Autorizado: implementación backend/frontend/pruebas, creación de migración000041, definición de configuración apagada y escritura de pruebas en PostgreSQL aislado. No autorizado: aplicar000041 en base habitual/remota, activar flags o modificar `.env`, proveedores/credenciales/red externa, nuevas dependencias, RF/RN/Word/PDF/diagramas académicos, commit/push/despliegue. Migraciones36–39 pendientes en base habitual no forman parte de esta entrega.

## Persistencia y separación

- Misma obligación, precio histórico, conversión exacta, relaciones de intentos/cobros/operaciones/devoluciones y liquidación única existentes. No segundo libro financiero ni estado asistencial nuevo.
- `SimulacionPagoService`: titularidad, revisión del precio ligada a actor/obligación/importe con vigencia15min, inicio, aprobación, confirmación Stripe representativa, captura PayPal representativa y cierre sin cobro. Transacción y locks Cita→obligación→intento/operación. Bitácora en la misma transacción; sin datos de formulario ni evidencia externa.
- `SimulacionPagoRepository`: disponibilidad del esquema y recuperación de solicitudes idempotentes. Reutiliza consultas/escrituras comunes de `StripePagoRepository`, ya compartidas con PayPal, sin usar gateways ni aplicadores de evidencia externa.
- Contexto `proveedor=simulado`, `entorno=local`, `cuenta_contexto=academico`; origen inmutable `Simulado`, modalidad `stripe|paypal`, escenario `completado|pendiente`. No se reclasifican intentos existentes: el nuevo campo identifica los contextos externos que ya permitía000040.
- Cobro aplicado con referencia local `SIM-{UUID}` obligatoria en PostgreSQL; referencias de inicio/pago externas nulas. Operaciones locales sin claves/referencias externas. No insertar eventos de proveedor ni simular verificaciones.
- Migración000041 añade campos/restricciones y trigger de historia; mantiene FK y unicidad de intento activo/cobro aplicado. Amplía tipos de operación solo para contexto simulado. No modifica000040. `down()` rechaza la reversión automática: retirar historia financiera requiere revisión/autorización, nunca borrar para poder revertir.
- Despacho externo acepta únicamente Stripe/PayPal; recuperadores e inbox conservan sus contextos concretos. Una operación simulada no llega al fallback Stripe. La evidencia externa tardía conserva el tratamiento de cobro aplicado/incidencia existente; cierra un intento simulado activo sin despacharlo a red.

## Elegibilidad y coordinación

Solo Paciente titular, sesión normal/cuenta habilitada, JSON cerrado, origen SPA, CSRF y throttle. Administrador consulta por paciente; Recepción/Médico no pagan ni reciben permisos nuevos. No aceptar estados de éxito, importes ni identidad del Paciente enviados por el cliente.

- Incorporada/con precio/exigible/no liquidada: admite inicio si la seguridad financiera compartida acredita ausencia de operaciones incompatibles. Vencidas, Atendidas e Inasistencia siguen regularizables sin bloqueo asistencial.
- Cancelada/no exigible/liquidada: no nuevo pago. Cita sin precio: asignación existente por personal; nunca importe inventado. Citas antiguas excluidas: sin incorporación, deuda ni precio retroactivos.
- Intento externo activo/incierto, escritura pendiente o cierre histórico sin prueba de ausencia de cobro: bloquea inicio académico. Un cierre terminal por etiqueta no lo evade.
- Misma clave/solicitud recupera el intento/resultado persistido; una clave usada con datos/acción distintos produce409. Tras perder respuesta, consultar obligación o recuperar la misma operación; después de recargar, usar el intento vigente del detalle.
- Escenario pendiente conserva intento sin liquidar y sin polling. Cierre académico explícito sin cobro conserva historia y permite revalidar elegibilidad. Nunca cierra operaciones externas.
- Cancelación real posterior cierra el intento académico activo y, si hay cobro simulado aplicado, crea/termina una devolución total **simulada**, idempotente, vinculada al cambio real de cita. Todo local y atómico, sin transferencia bancaria ni solicitud externa. Reprogramación conserva pago/precio; inasistencia no devuelve.

## Contrato HTTP aditivo

- `POST /api/pagos/obligaciones/{uuid}/simulacion`: cuerpo `{modalidad,escenario,revision}`, cabecera `Idempotency-Key` UUIDv4.201 nuevo/200 replay.
- `POST /api/pagos/simulacion/intentos/{uuid}/{aprobar|confirmar|capturar|cerrar}`: cuerpo `{}`, cabecera UUIDv4;200 resultado/replay. Aprobar PayPal no liquida; capturar requiere aprobación y acción humana separada. GET/retorno/montaje nunca escriben pagos.
- Respuesta `data`: `id,obligacion_id,origen,estado,modalidad,escenario,aprobada,liquidada,repetida`. Sin URLs de proveedor/evidencias, tarjetas, contraseñas o secretos. Errores centralizados vigentes;503 apagado/producción/esquema ausente,409 revisión/estado/idempotencia/conflicto,403 rol,404 recurso ajeno,422 campos,419 CSRF.
- Consultas financieras actuales añaden `simulacion={habilitada,puede_iniciar,revision}`, `origen_liquidacion`, contexto del intento activo y origen/modalidad del historial; devoluciones incluyen origen. Filtro de historial `proveedor=simulado`. Administrador siempre recibe capacidad académica deshabilitada.
- `liquidada` sigue derivándose de un cobro aplicado; no booleano de pago escrito desde React. Todas las respuestas personales mantienen `Cache-Control: no-store, private`.

## Interfaz

`/pagos/obligaciones/:id/pagar`, título **Pagar**, solo Paciente. Enlace desde obligación elegible o intento académico recuperable. Fuente autoritativa: `consultarPagos`/`operarSimulacion`; recuperación por API después de recarga/nueva sesión, no web storage.

Reutiliza formularios precargados/editables, validación pura, foco en primer error y revisión BOB/tasa/USD. Etiquetas naturales en este recorrido. Solo comparte los valores ficticios del formulario con el ejemplo estático, nunca su obligación/precio. Tarjeta de ceros, correos `@example.test` y clave `DEMO-…`: los campos no se envían ni persisten. Aviso visible de simulación/no datos reales y aclaración de que no es Checkout/autenticación auténtica.

Inicio persistido→salida representada→retorno sin éxito. Confirmación local Stripe explícita; PayPal aprobación persistida sin pago→revisión→captura humana. Pendiente sin polling; cierre sin cobro revisado. «Pagada — simulación académica» solo al reconsultar liquidación persistida. Administrador observa el mismo cobro/historial, sin botones de pago. Source Sans3/tokens/MainLayout y responsive conservados, sin rediseño.

## Activación y publicación pendientes

1. Revisar código, pruebas y limitaciones. No publicar como integración externa acreditada.
2. Solicitar autorización específica para aplicar **únicamente** `src/data/database/migrations/2026_11_06_000041_add_simulacion_academica_to_pagos.php` en la base académica habitual. Antes: inspección de conexión/entorno/migraciones/dependencias/restricciones, respaldo y plan de locks; comando por ruta exacta, nunca migrate general. Esta entrega no ejecuta ese paso.
3. Solicitar autorización separada para activar `PAGOS_SIMULACION_HABILITADA=true` exclusivamente en entorno académico local. El nuevo default es false y el servicio exige además `APP_ENV=local|testing` y esquema instalado. No habilitar Stripe/PayPal ni modificar sus flags/restricciones. En producción el servicio permanece rechazado.
4. Publicar backend y frontend compatibles, autoload y `pnpm build` según infraestructura existente; conservar API/cookies/CSRF/fallback SPA. La activación requiere migración/configuración autorizadas; solo subir frontend no habilita pagos persistentes.
5. Validación académica con cuentas/datos ficticios: registrar cita habitual con monto, pagar como titular, recargar/nueva sesión, consultar el mismo registro como Administrador y comprobar origen/estado asistencial/historial. No ejecutar pruebas de escritura sobre datos habituales.

Las evidencias automatizadas y sus límites se registran en el relevo `estado-fase.md`; no sumar verificaciones históricas de F7/F8/demo como nuevas pruebas de esta ampliación.

## Verificaciones ejecutadas y límites

- Backend dirigido inicial: `php -d memory_limit=512M vendor/bin/phpunit --colors=never --do-not-cache-result --filter "PagosSimulacionTest|PagosFaseTresTest|PagosPersistenciaTest|PagosStripeTest|PagosPayPalTest|PagosCapacidadesPresentacionTest"`:174 pruebas/1811 aserciones,23 errores/13 fallos de preparación; **no completamente verde**. FaseTres/Stripe/PayPal sin fallos. Se corrigió el arnés legado para instalar41 después de40, el doble puro de capacidad académica, los cuerpos vacíos a{} sin relajar su validación, y el reloj/sesión de fixtures sin desactivar throttle.
- Revisión final solo de los tres archivos afectados: **45 pruebas/470 aserciones correctas,15,855s/108MB/código0**, sin notices tras usar stub en las pruebas puras. Cubre reserva habitual mediante API con precio140BOB/20USD (distinto de la fixture), persistencia entre peticiones/nueva sesión, lectura Admin del mismo cobro, cuatro roles/ajenos, CSRF/origen/entorno/campos, aprobación/captura/idempotencia, pendiente/cierre, incertidumbre externa, legado/sin precio, vencidas/estados asistenciales, cancelación/devolución y restricciones de origen/referencia. Dos procesos independientes compiten por inicio (uno permitido) y por confirmación con la misma clave (una operación/un cobro).
- Solo `clinica_vida_salud_test`, rol/propietario restringidos homónimos, pgsql loopback/PostgreSQL18.6, sin membresías ni privilegios elevados; preflight bloqueante, public sin tablas y esquema ajeno conservado. Nuevos tests usan esquemas aleatorios `prueba_simulado_*`; teardown exclusivamente sobre su esquema. Inventarios antes/después idénticos en cada ejecución. No migración ni prueba de escritura habitual/remota.
- Frontend: `pnpm test src/features/pagos src/constants/navigation.test.js src/layouts/ModuleNavigation.test.jsx src/features/auth/tests/pages/AdministrativeNavigation.test.jsx --maxWorkers=4`:335 pruebas/11 archivos,334 correctas/un fallo del catálogo que no conocía la nueva ruta. Se corrigió y revalidó el grupo afectado navegación/layouts:178/3/código0. No sumar solapamientos ni presentar la primera batería completamente verde. El nuevo archivo Pagar pasó íntegramente: consultas al remontar/Admin, revisión/formato/foco, salida/retorno sin pago, aprobación/captura, pendiente/cierre sin polling, roles/estados, respuesta perdida/misma clave, conflicto y ausencia de campos del formulario en POST. HTTP/auth simulados exclusivamente en pruebas.
- `pnpm build` final correcto/código0,248ms, warning existente de chunk>500kB. `composer dump-autoload --no-scripts` y sintaxis PHP correctos. No dependencias nuevas ni locks cambiados; no script lint instalado. Evidencias/códigos/preflight/inventarios en `C:/Users/jesus/AppData/Local/Temp/opencode/pagos-simulacion-*`.
- No nuevo recorrido Chrome real de extremo a extremo ni capturas/responsive en navegador/dispositivos físicos para esta ampliación. La cobertura React no equivale a aceptación integrada externa; el servidor/SQL real se verificó en PHPUnit. No proveedores reales, credenciales, webhooks externos, dinero, producción ni despliegue. No afirmar que la base habitual ya admite la simulación: faltan migración y activación específicamente autorizadas.
