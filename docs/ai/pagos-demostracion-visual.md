# Demostración visual de pagos (2026-11-06)

## Acceso y alcance

- Ruta SPA independiente: `/pagos/demostracion`. Menú **Pagos → Demostración de pagos**, exclusivamente Paciente y Administrador con sesión normal, mediante `ProtectedRoute`/`RoleRoute` existentes. Login conserva el destino local permitido. Recepción/Médico conservan navegación y denegación. No CU ni permisos financieros nuevos.
- Administrador explora un recorrido ficticio del Paciente, sin seleccionar ficha, suplantar identidad ni pagar por un titular. **Consultar pagos** mantiene `/pagos`, sus datos reales y su elegibilidad backend; no incorpora un modo demo.
- Aviso en todos los pasos: «Demostración académica con datos ficticios. No realiza cobros ni modifica citas o pagos».

## Recorridos

Fixture estática `DEMO-CITA-001`, Bs 70,00 / tasa académica 7:1 = USD 10,00. No es tarifa predeterminada ni obligación emitida. El ejemplo de cita permanece Programada; ningún resultado modifica atención.

1. Elegir Stripe o PayPal y escenario completado/pendiente; revisar explícitamente BOB/tasa/USD antes de iniciar.
2. Inicio simulado → representación local de salida al proveedor, sin navegar fuera del portal.
3. Stripe: retorno simulado **sin éxito automático** → consulta humana de resultado ficticio → completado simulado o pendiente.
4. PayPal: aprobación ficticia **sin captura** → retorno → consulta explícita → aprobación sin pago → revisión de Bs 70,00/USD 10,00 → captura humana simulada → nueva consulta de resultado ficticio. El escenario pendiente se detiene antes de ofrecer captura.
5. Pendiente permanece sin polling, éxito ni otro inicio. Reiniciar vuelve a selección inicial y solo descarta memoria; salir/recargar también la descarta.

## Barreras técnicas

`DemostracionPagosPage` usa estado React local y fixture congelada `demostracionPagoFixture`; transiciones condicionadas por el paso actual. Reutiliza únicamente `PrecioPago`/formato puro y componentes presentacionales globales (alertas, botones, campos, revisión, encabezado). No importa hooks/servicios operativos de Pagos, no crea backend ni motor financiero, no interpreta query/fragmento como datos de obligación o resultados. Source Sans 3/tokens/MainLayout y responsive existentes, sin CSS global nuevo.

No endpoints financieros/clínicos, SQL, SDK, iframe, credenciales, enlaces Stripe/PayPal, temporizadores, polling, web storage, logs de cuerpos o datos reales. El efecto de la página solo mueve el foco entre pasos. Los guards mantienen la restauración normal de autenticación; sus llamadas no son operaciones financieras. `destinoSeguro` añade únicamente la ruta local exacta para los dos roles aprobados. Sin cambios de `.env`, flags/defaults, APP_ENV, restricciones local/testing, elegibilidad, contratos API, backend, dependencias, locks ni migraciones.

## Verificación realizada

- Node24.18.0/pnpm11.17.0; lock: React19.3.1, React Router7.18.4, Vite8.3.4, Vitest4.1.12. Skill local leída desde `frontend/.opencode/skills/frontend-design/SKILL.md`.
- Final dirigida: `pnpm test src/features/pagos src/constants/navigation.test.js src/layouts/ModuleNavigation.test.jsx src/features/auth/tests/pages/AdministrativeNavigation.test.jsx --maxWorkers=4`: **287 pruebas/9 archivos correctos, 7,96s, código nativo0**. Incluye11 nuevas de demo y regresión operativa existente, no suite global. Espías HTTP/fetch/XHR/window.open y almacenamiento prueban aislamiento; React Router lee su clave interna `remix-router-transitions` al construirse, sin persistencia de demo. Guards visitante/restringido/cuatro roles, consulta sin éxito por retorno, captura humana, pendiente con avance de reloj120s sin timers, reinicio/remontaje y separación del acceso real.
- `pnpm build`: **código0**, JS995,24kB/gzip264,08kB. Warning previo chunk>500kB conservado; no script lint instalado.
- **Chrome headless real, frontend local, autenticación ficticia interceptada**, no servidor Laravel/SQL/proveedor:80 comprobaciones de layout (10 estados ×1440/768/390/320px ×texto100/200%),20 axe sin violaciones automáticas, teclado Enter en revisión/captura/reinicio y foco de transición; capturas escritorio/móvil examinadas. Cuatro solicitudes de autenticación permitidas/interceptadas, **0 peticiones financieras/externas observadas**,0 excepciones,0 web storage; código0. Recepción/Médico denegados también en navegador. No certificación exhaustiva ni dispositivo físico.
- Intentos preparatorios no acreditados: primer arnés Chrome falló por escape de expresión JavaScript del propio arnés; primera batería detectó aserción excesiva de almacenamiento del framework, causando fallos de limpieza en cascada. Corregidos solo arnés/prueba, sin relajar producto; no sumar esos pases al final.
- `git -c core.autocrlf=false diff --check`: código0. Logs/códigos/JSON/PNG y arnés descartable fuera del repo: `C:/Users/jesus/AppData/Local/Temp/opencode/pagos-demo-*` (tests finales `pagos-demo-tests-final-*`, build, browser y `pagos-demo-browser-evidence.json`). Anotaciones PowerShell NativeCommandError por stderr informativo no sustituyen los códigos nativos guardados.

## Publicación pendiente, no ejecutada

Desplegar **solo el frontend actualizado**: con el gestor/lock existentes, `pnpm install --frozen-lockfile` si el entorno necesita instalar, y `pnpm build`; publicar el nuevo `dist/` completo/atómico conservando assets y fallback SPA a `index.html` para `/pagos/demostracion`. Si el servicio usa el `frontend/Dockerfile` existente, reconstruir/desplegar su imagen frontend (incluye build/Caddy); no es necesario cambiar ese Dockerfile ni configuración financiera. El Caddyfile versionado ya contiene fallback SPA; eso **no acredita** la configuración del sitio publicado, que no fue inspeccionado.

Conservar origen/API/proxy/autenticación actualmente autorizados: para entrar se necesita sesión válida del rol aprobado; la demo no elude login. No habilitar Stripe/PayPal ni ejecutar migraciones para visualizarla. Comprobar después del despliegue autorizado enlace, recarga directa, guards y red en el sitio. No hubo commit, push, despliegue ni cambios remotos. Esto es una **demostración visual**, no integración externa ni aceptación de Stripe/PayPal. La aceptación externa F8 y pendientes previos permanecen independientes.
