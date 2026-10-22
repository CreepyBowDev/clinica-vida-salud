# Frontend — Clínica Vida Salud

## Bitácora CU-30 — fase 4: seguridad de interfaz, responsive y accesibilidad (2026-10-21)

**Revisión completada:** Chrome headless con React/Vite reales y respuestas HTTP interceptadas (datos ficticios, sin Laravel/PostgreSQL ni cookies reales del servidor en esta fase). Nueve estados: listado, avanzados, validación 422, vacío, denegado 403, detalle con 100 IDs de especialidad, estados de cuenta, formato desconocido y evento inexistente. **72 comprobaciones de tamaño** (1440/1024/961/960/768/700/390/320 px) sin desbordamiento de documento/contenido; **18 auditorías axe-core 4.10.3** (1440/390 px) sin infracciones detectadas. Capturas revisadas de listado desktop y detalle móvil. No se necesitó cambiar el CSS existente de Bitácora.

**Teclado/permisos/sesión:** en Chrome se verifica apertura/cierre de avanzados con Espacio, Tab a responsable y, al cerrar después de 422, salto a tamaño de página; campos inválidos reciben foco. Tres roles ajenos no generan consultas de auditoría al abrir la ruta; revalidación a sesión no autenticada retira detalle y dirige a login. Sin solicitudes de escritura ni excepciones JavaScript en ese recorrido. Evidencia local fuera de Git en `%LOCALAPPDATA%/Temp/opencode/bit4-evidence.json`, capturas `bit4-*.png` y guion `bit4-browser.cjs`; no constituye aceptación de producción ni certificación completa de accesibilidad.

**Correcciones focalizadas:** BitacoraFilters utiliza el desplegable nativo y abre/enfoca solo al recibir errores nuevos; un 422 ya mostrado no lo reabre al intentar cerrarlo ni roba foco al cambiar otro control. bitacoraFormat rechaza fechas imposibles que Date normalizaría silenciosamente (30 de febrero, 31 de abril, 24:00), conservando presentación válida de días bisiestos y precisión UTC original.

**Regresión:** 9 escenarios nuevos en tests/pages/BitacoraSafety.test.jsx y 5 de fechas en tests/utils; cubren revocación y nuevo login con filtros limpios, cambio a los otros roles, fallo/restauración de comprobación de sesión, logout con respuesta pendiente, foco/semántica/solo lectura y regresiones de ambos ajustes. La activación nativa por Espacio y tabulación de details cerrado se comprueban en Chrome, pues jsdom no las reproduce completamente. Acumulado Bitácora **100 pruebas/6 archivos**, suite completa **621 pruebas/36 archivos correctos**, build correcto con aviso conocido del bundle de 576,32 kB.

README/contexto §17.75/análisis parcial/ficha CU-30 sincronizados. Fases frontend 1–4 completadas; integración con Laravel/PostgreSQL y cierre local pendientes de fase 5. Backend/DER sin cambios; diagrama global y actualización PHP siguen aplazados.

## Bitácora CU-30 — fase 3: detalle y retorno al listado (2026-10-21)

**Implementado:** BitacoraDetailPage valida el ID antes de consultar, consume consultarEvento y presenta carga/errores seguros y detalle mediante BitacoraEventData. Cabecera con fechas del hecho/registro de sede y UTC original de seis decimales, origen, responsable/objetivo/roles históricos, resultado/causa general conocida, IP/procedencia, correlación, versión e ID de evento relacionado sin expansión ni enlace implícito.

El componente presenta únicamente campos conocidos del contrato: referencias como identificadores, nombres de campos modificados sin valores personales/clínicos, altas sin CI, habilitaciones/retiradas, estados/vínculos antes/después, IDs de Paciente/registro de activación y metadatos de instalación/pruebas. No hay visor genérico de JSON. Claves desconocidas se omiten y formatos distintos de 1 conservan cabecera sin interpretar información adicional. Los metadatos explican que autorización de instalación o simulación no acreditan control real del buzón.

BitacoraWorkspace mantiene borrador/criterios/página mediante estado local y Outlet context exclusivo de ambas rutas, sin guardar eventos ni escribir en URL, history.state, localStorage o sessionStorage. Se conserva al ir a detalle y volver (enlace o historial), se reconsulta el listado y se descarta al salir del módulo/desmontar el área protegida. Acceso directo al detalle funciona sin listado previo y vuelve a criterios iniciales. ID inválido local no genera HTTP; 404/422 orientan al listado, otros errores permiten consulta manual y 429 conserva espera. Cambio de ID cancela/descarta detalles previos.

**Verificación:** 28 pruebas nuevas en tests/pages/BitacoraDetail.test.jsx; navegación/listado previos ajustados a la nueva consulta real del cliente. **86 pruebas en 5 archivos de Bitácora** incluidas en la suite completa: **607 pruebas/35 archivos correctos**. Build correcto con aviso conocido del bundle de 576,30 kB. Adaptador HTTP simulado con Router/AuthProvider/servicios/Axios del cliente reales; no acredita aún integración con Laravel/PostgreSQL ni revisión visual completa en navegador.

README, contexto §17.74, análisis parcial y ficha CU-30 sincronizados. Frontend fases 1–3 implementadas; fases 4–5 revisión de seguridad/responsive/accesibilidad e integración/cierre pendientes. Backend/DER sin cambios; diagrama global y actualización PHP siguen aplazados.

## Bitácora CU-30 — fase 2: listado, filtros y paginación (2026-10-21)

**Implementado:** BitacoraPage consulta el servicio real del frontend y coordina borrador de filtros separado de los criterios aplicados. BitacoraFilters es presentacional y ofrece período, módulo y resultado, con criterios avanzados para responsable/objetivo, origen, operación, tipo de evento, IP y recurso. Permite 10/20/50/100 eventos por página; aplicar o limpiar reinicia la página. No hay consultas por cada pulsación. Las fechas locales se envían sin conversión; Laravel valida criterios, combina filtros y determina totales/orden.

BitacoraTable presenta evento/fecha del hecho en America/La_Paz, módulo/operación, responsable y cuenta objetivo con IDs/roles históricos diferenciados, origen sistema/anónimo, resultado y enlace de detalle. Conserva el orden del servidor, sin deducir nombres ni consultar fichas. Se reutilizan FormField/SelectField/Button, useCatalogQuery y CatalogResults: carga/vacío, errores seguros, campos 422 (apertura de avanzados y foco), 429 con Retry-After/reintento manual, invalidación central 401 y cancelación/descarte de respuestas obsoletas. Paginación por meta; no se siguen URLs de links. Estilos específicos en features/bitacora/styles, con disposición móvil apoyada en catalogs.css.

**Verificación:** 14 pruebas nuevas de comportamiento en tests/pages/BitacoraList.test.jsx y navegación previa adaptada al listado funcional. Feature completa **58 pruebas/4 archivos correctos**; suite frontend **579 pruebas/34 archivos correctos**; build correcto con aviso conocido del bundle de 566,55 kB. Pruebas con Router/AuthProvider/servicio/Axios reales del cliente y adaptador HTTP simulado. No acredita aún integración con Laravel/PostgreSQL ni revisión visual completa en navegador.

Contexto §17.73, análisis parcial y ficha CU-30 sincronizados. Detalle y retorno con conservación de filtros/página se implementarán en fase 3; revisión de seguridad/responsive/accesibilidad e integración/cierre en fases 4–5. El enlace ya navega a la vista inicial de detalle. Backend/persistencia/DER sin cambios; diagrama global y actualización PHP siguen aplazados.

## Bitácora CU-30 — fase 1: servicio, rutas y navegación (2026-10-21)

**Completada la base del frontend.** La feature `src/features/bitacora/` incorpora `services/bitacora.service.js`: `listarBitacora` consume GET `/bitacora` y conserva `data/meta/links`; `consultarEvento` consume GET `/bitacora/{id}` y devuelve `data`. Ambas usan Axios central, cookies, `sessionBound` y AbortSignal, sin peticiones CSRF ni reintentos. Solo se envían filtros permitidos por el contrato de `backend/README.md`; se omiten vacíos, se recortan espacios ASCII y se conserva el caso. IDs de detalle se validan mediante `esIdentificador` existente; BIGINT/fechas/JSON de respuesta permanecen sin conversión.

Rutas `/administracion/bitacora` y `/administracion/bitacora/:id` bajo ProtectedRoute/RoleRoute de Administrador. MainLayout incorpora menú exclusivo, estado activo, título y foco compartido. `destinoSeguro` admite listado/detalle para regresar después de login. Las páginas iniciales indican explícitamente qué funcionalidad está pendiente y todavía no consultan eventos: listado/filtros/paginación corresponden a fase 2 y detalle a fase 3.

`utils/bitacoraFormat.js` prepara fechas ISO UTC del contrato para presentación en `America/La_Paz` (sin alterar el original) y etiquetas de actor/resultado; ausencia o valores desconocidos producen «No disponible». No se persisten eventos ni se añaden dependencias.

**Pruebas:** tres archivos en `tests/services/`, `tests/pages/` y `tests/utils/`, **44 pruebas correctas** con adaptador HTTP simulado y Router/AuthProvider reales. Cubren filtros, cadenas BIGINT, precisión original, errores HTTP, cancelación/sesiones anteriores, cuatro roles, restauración/sesión restringida, retorno seguro, navegación y cruce de día UTC/La Paz. Suite completa `pnpm test`: **565 pruebas/33 archivos correctos**. `pnpm build` correcto con aviso conocido del bundle principal, ahora 558,16 kB. Sin script de lint instalado. Integración con Laravel/PostgreSQL y revisión responsive completas quedan para fases 4–5.

Contexto §17.72, análisis parcial y ficha CU-30 sincronizados. Backend, persistencia y DER sin cambios; diagrama global y actualización PHP mantienen su aplazamiento.

## Organización de pruebas — fase 4 completada (2026-10-21)

**Reorganización de las cinco features terminada.** Cada prueba pertenece a `src/features/<modulo>/tests/<responsabilidad>/`, separada del código funcional. La convención obligatoria se documenta en `AGENTS.md` §5.1 y se aplica también a futuras pruebas de componentes/hooks. Se crean subcarpetas únicamente cuando contienen archivos.

| Feature | Archivos de pruebas | Distribución dentro de `tests/` |
| --- | ---: | --- |
| auth | 11 | pages (6), services (3), context (1), utils (1) |
| usuarios | 3 | pages (2), services (1) |
| pacientes | 8 | pages (5), services (3) |
| medicos | 5 | pages (4), services (1) |
| especialidades | 2 | pages (1), services (1) |

Los 29 archivos conservan nombres, casos y aserciones; la comparación contra Git confirma que solo cambian rutas relativas. No quedan pruebas fuera de `tests/` dentro de features. `src/layouts/Navigation.test.jsx` conserva su ubicación y `src/test/setup.js` el setup compartido. Vitest descubre todos los archivos sin ajustes de configuración.

```powershell
pnpm test
pnpm test src/features/auth/tests
pnpm build
```

**Verificación final:** **521 pruebas correctas/30 archivos**, build correcto con el aviso conocido del bundle de 556,88 kB y `git diff --check` correcto. Referencias documentales revisadas; se actualiza la ruta de AuthIntegration en este README. Contexto §17.66 registra la decisión técnica aprobada y el cierre de las cuatro fases. Sin nuevos CU ni cambios de clases funcionales/persistencia, por lo que análisis/DER/diagrama global no requieren modificación. Las secciones siguientes conservan la cronología de cada fase.

## Reorganización de pruebas — fase 3: Médicos y Especialidades (2026-10-21)

**Completada:** Médicos tiene cuatro archivos en `tests/pages/` y uno en `tests/services/`; Especialidades, uno en `tests/pages/` y uno en `tests/services/`. Se conservan nombres y casos, ajustando imports y mocks, incluidas las referencias entre features, Auth, Router, Axios y constantes compartidas.

**Verificado:** `pnpm test src/features/medicos/tests src/features/especialidades/tests`: **142 pruebas/7 archivos correctos**; build correcto con aviso conocido del bundle de 556,88 kB. Los 29 archivos de pruebas de las cinco features actuales están dentro del `tests/` de su módulo, sin pruebas mezcladas en carpetas funcionales. Pendiente fase 4: regresión completa, documentación definitiva de la convención y revisión de referencias documentales. Sin cambios funcionales, de contratos o persistencia.

## Reorganización de pruebas — fase 2: Usuarios y Pacientes (2026-10-21)

**Completada:** todos los archivos de pruebas de ambos módulos se encuentran en su `tests/` correspondiente. Usuarios: dos archivos en `tests/pages/` y uno en `tests/services/`; Pacientes: cinco en `tests/pages/` y tres en `tests/services/`. Se conservan nombres y casos, ajustando imports, mocks e importaciones `vi.importActual` a la nueva ubicación, incluidas referencias a Auth y Axios compartido.

**Verificado:** `pnpm test src/features/usuarios/tests src/features/pacientes/tests`: **225 pruebas/11 archivos correctos**. Build correcto con el aviso conocido del bundle de 556,88 kB. Sin pruebas fuera de `tests/` en estos dos módulos; Auth conserva el traslado de fase 1. Pendientes: fase 3 Médicos/Especialidades y fase 4 revisión/documentación global. Sin cambios funcionales ni cierre adicional de CU; análisis/DER/diagrama global no requieren cambios por este traslado.

## Reorganización de pruebas — fase 1: Auth (2026-10-21)

Se adopta progresivamente `src/features/<modulo>/tests/<responsabilidad>/` para todos los archivos de pruebas de cada feature. Inventario inicial: 29 archivos en features (Auth 11, Usuarios 3, Pacientes 8, Médicos 5 y Especialidades 2); un archivo adicional de layouts permanece fuera de este alcance.

**Fase 1 completada:** las once pruebas de Auth están en `auth/tests/`: seis archivos en `pages/`, tres en `services/`, uno en `context/` y uno en `utils/`. Se conservan nombres y casos, ajustando únicamente imports y rutas de mocks. Vitest descubre la ubicación sin cambiar su configuración y `src/test/setup.js` continúa como setup compartido.

**Verificado:** `pnpm test src/features/auth/tests`: **152 pruebas/11 archivos correctos**; `pnpm build` correcto con aviso conocido del bundle principal de 556,88 kB. Fases siguientes: Usuarios/Pacientes, Médicos/Especialidades y revisión/documentación global de la convención. No cambia comportamiento, contratos ni clases funcionales; este traslado no cierra nuevos CU ni modifica DER/diagrama global.

## Médicos y especialidades — fase 5: cierre administrativo local (2026-10-21)

Detalle ofrece «Crear cuenta para este médico» solo sin vínculo. CU-03 recibe `id_medico` como cadena, valida parámetro único/reconsulta y fija rol Médico/registro; vínculo existente impide alta. No infiere ID/estado de cuenta desde el booleano. Correo de acceso se introduce expresamente, separado del contacto; Laravel valida vinculación al guardar. Resultados inciertos bloquean repetición hasta revisar vínculo vigente.

**Integración real:** Chrome headless → React/Axios → Laravel → PostgreSQL, base/rol de pruebas aislados y esquema aleatorio con todas las migraciones (incluidas 000030–000032); solo correo sustituido por captura local. **17 comprobaciones**: catálogo/paginación/duplicado/edición/estado, alta de Médico con catálogo inactivo, 409 obsoleto/adopción, retiro/rehabilitación con historia, rechazo por cita futura/rollback, cuenta/invitación/aceptación/login/restauración y separación actividad/acceso. SQL final confirma un Médico nuevo, cita conservada, tres pares históricos y 25 eventos sin contraseña/token de prueba. Respuestas reales 200/201/204/401/403/409/419; negativas de permisos/CSRF también mediante fetch autenticado.

**Responsive/accesibilidad:** diez vistas a 320/1440 px, tres en límites 700/701, 960/961, 1100/1101 y 1920, confirmaciones móviles, texto al 200 % y espaciado ampliado. **52 comprobaciones sin overflow global/controles fuera de ancho y 23 auditorías axe sin infracciones automáticas**; selección por teclado, menú/Escape/retorno de foco, salto al contenido y semántica de tabla móvil comprobados. Capturas revisadas; sin excepciones JS ni Web Storage. Se corrigen corte de códigos largos, selección apilable y separación de enlaces de retorno. No equivale a certificación integral.

**Cierre:** `pnpm test` **521 pruebas/30 archivos**, 12 nuevas; build correcto, aviso conocido del bundle 556,88 kB. PHPUnit focalizado MedicosEspecialidadesIntegracionTest: **2 pruebas/140 aserciones**. Plan administrativo frontend CU-09/CU-10 completado localmente. README/análisis/casos/contexto §17.65 sincronizados; DER sin cambios nuevos, diagrama global expresamente aplazado. Auxiliares/evidencias `med5*` fuera de Git; esquema/secretos de prueba retirados y servidores temporales detenidos al finalizar. Consultorios/horarios/agenda y aceptación Railway/Brevo real conservan sus alcances posteriores; actualización PHP sigue aplazada.

## Médicos — fase 4 frontend (2026-10-21)

Detalle incorpora activación/inactivación profesional con confirmación y explicación de independencia de cuenta, rol, catálogo y sesiones. Se consume PATCH `/medicos/:id/estado` con versión exacta; vínculo incompatible dirige a gestión de cuentas y requiere revisar estado actual. No se alteran automáticamente citas ni obligaciones anteriores.

Nueva ruta de Administrador `/administracion/medicos/:id/especialidades`: consulta el conjunto habilitado, permite agregar/rehabilitar o retirar mediante catálogo paginado existente y mantiene como mínimo una habilitación, incluso con Médico/catálogo inactivos. Antes de guardar muestra adiciones y retiros; PUT `/medicos/:id/especialidades` envía el conjunto completo y `actualizado_en_original`. Rechazo 409 por citas futuras conserva selección y muestra que no se guardó ningún cambio. La comprobación de citas y la integridad histórica corresponden al backend. Versión obsoleta/resultado incierto exigen consulta y adopción explícita; nueva adopción reinicia confirmación. Éxito reconsulta detalle.

**Verificación:** `pnpm test`: **509 pruebas correctas/29 archivos**, 34 nuevas respecto de fase 3 (11 servicios y 23 pantallas). `pnpm build` y `git diff --check` correctos; aviso conocido del bundle principal (553,88 kB). Recorridos con servicios/Axios reales y transporte ficticio; no acreditan carreras PostgreSQL, consulta real de citas ni integración Laravel. Fase 5 pendiente: vínculo con creación de cuenta existente, integración real y revisión visual/responsive/accesible. Diagrama global aplazado expresamente.

## Médicos — fase 3 frontend (2026-10-21)

Alta (`/administracion/medicos/nuevo`), detalle (`/administracion/medicos/:id`) y edición de datos (`/administracion/medicos/:id/editar`) para Administrador. Formularios de registro profesional, nombres, apellidos, teléfono y correo de contacto; selección de una o varias especialidades iniciales con búsqueda/paginación, incluidas inactivas, selección persistente entre páginas y retirada individual antes del alta. Código generado por servidor, sin creación automática de cuenta, consultorio u horarios. Edición de datos mantiene independientes actividad, habilitaciones y cuenta; correo de contacto no cambia acceso, ni se exige unicidad no acordada de correo/registro profesional. Detalle muestra asociaciones habilitadas e históricas y estado del catálogo por separado.

Servicios `consultarMedico`, `crearMedico` y `actualizarMedico` sobre Axios/CSRF existentes, IDs BIGINT como cadenas, versión exacta y payloads explícitos. Borradores conservados ante errores; versión obsoleta exige consulta/adopción explícita. Resultados inciertos bloquean reenvío hasta revisar el registro; alta orienta al listado. Sin reintentos automáticos y con reconsulta de detalle después de éxito.

**Verificación:** `pnpm test`: **475 pruebas correctas/28 archivos**, incluidas 32 nuevas (12 de servicios, 20 de pantallas). `pnpm build` y `git diff --check` correctos. Aviso conocido de bundle principal: 545,88 kB. Se usan servicios/Axios reales con transporte ficticio; integración Laravel y revisión visual en navegador pendientes del cierre integrado. Siguiente fase 4: actividad profesional/habilitaciones; fase 5: vínculo con cuentas e integración. Diagrama global continúa aplazado expresamente.

## Especialidades — fase 2 frontend (2026-10-21)

Gestión de catálogo CU-09 para Administrador: alta en `/administracion/especialidades/nueva`, detalle en `/administracion/especialidades/:id` y edición en `/administracion/especialidades/:id/editar`. El listado enlaza a detalle y alta. Nombre obligatorio hasta 120 caracteres y descripción opcional hasta 2000, sin caracteres de control conforme al contrato actual (incluidos saltos de línea). Alta no permite imponer estado; detalle permite activación/inactivación con confirmación explícita y explica preservación de asociaciones/citas/historial.

`especialidad.service.js` incorpora consulta, creación, edición y estado con CSRF, listas explícitas de campos, IDs de texto y versión UTC de seis decimales conservada exactamente. Duplicado 409 permite corregir el nombre manteniendo borrador; edición obsoleta bloquea envío y consulta la versión vigente para comparación/adopción explícita. Los resultados inciertos de alta bloquean duplicación y orientan al catálogo; en edición/estado requieren revisar versión actual. Sin reintentos automáticos de escritura. Después de éxito se consulta nuevamente el detalle, con aviso de la operación confirmada.

**Verificación:** `pnpm test`: **443 pruebas correctas/26 archivos**, 34 nuevas respecto de fase 1; `pnpm build` y `git diff --check` correctos. Bundle principal 532,15 kB, con aviso conocido de tamaño. Pruebas de recorrido con Router/AuthContext/servicios/Axios reales y adaptador HTTP ficticio; no equivalen a integración Laravel ni revisión visual en navegador, pendientes del cierre integrado. Diagrama global aplazado; próximas fases: datos/alta de Médicos, actividad/habilitaciones y vínculo con creación de cuentas.

## Médicos y especialidades — fase 1 frontend (2026-10-21)

Listados exclusivos del Administrador en `/administracion/medicos` y `/administracion/especialidades`, con enlaces del menú, títulos y destino seguro tras login. Servicios de feature consumen GET `/medicos` y `/especialidades` mediante Axios central y `sessionBound`, con cancelación y descarte de respuestas obsoletas.

Especialidades permite buscar nombre y estado del catálogo; Médicos, nombre/apellidos, actividad profesional y especialidad habilitada. El selector consulta todo el catálogo mediante búsqueda y paginación, representa inactivas y conserva la selección al cambiar de página. La paginación utiliza `meta`, sin seguir URLs recibidas. Se mantienen IDs BIGINT como texto y versiones sin conversión. Estados de carga, vacío, errores seguros, validación y espera `Retry-After` con reconsulta manual. Tablas adaptables a tarjetas bajo 700 px, etiquetas visibles y foco en resultados al paginar.

**Verificación:** `pnpm test`: **409 pruebas correctas/25 archivos**, incluidas 30 nuevas de servicios y pantallas; `pnpm build` y `git diff --check` correctos. El build conserva el aviso del bundle principal mayor de 500 kB (519,80 kB). Esta fase se verifica con servicios simulados y adaptador Axios de prueba; integración Laravel y revisión visual en navegador de las nuevas pantallas siguen pendientes. Altas, detalles, ediciones, actividad/habilitaciones y enlace con creación de cuenta se implementarán en fases posteriores. Avance parcial CU-09/CU-10; diagrama global aplazado por decisión expresa.

## Mejora visual y responsive — fase 4 completada (2026-10-21)

**Plan visual de cuatro fases completado para las pantallas existentes.** Se conserva la identidad clínica acordada y se revisa el conjunto después de las mejoras de acceso, cuentas y Pacientes. Las secciones inferiores mantienen la cronología de cada fase.

**Correcciones de navegación:** `AuthLayout` y `MainLayout` restablecen el desplazamiento al cambiar de pantalla. El layout público enfoca el contenido al cambiar de ruta y su destino «Saltar al contenido» admite foco sin añadir una parada a Tab. La carga inicial pública conserva el recorrido de teclado desde la cabecera. `src/layouts/Navigation.test.jsx` incorpora dos pruebas de regresión para desplazamiento/foco en navegación pública y autenticada; `src/styles/global.css` evita el contorno de foco del contenedor público completo, manteniendo el de sus controles.

**Verificación final:** `pnpm test`: **379 pruebas correctas/23 archivos**; `pnpm build` y `git diff --check` correctos. Persiste el aviso conocido del bundle principal (~508 kB). Revisión Chrome headless sobre React con transporte API ficticio: 23 variantes públicas/autenticadas a 320/1440 px, seis vistas representativas en los límites 700/701, 960/961, 1100/1101, 1280 y 1920 px, texto raíz al 200 % y espaciado ampliado en seis vistas. **108 comprobaciones de distribución sin desbordamiento global ni controles fuera de ancho**, y **47 auditorías axe-core 4.10.3 sin infracciones automáticas**. Se verifican navegación desde el final de páginas largas, foco en contenido, salto al contenido, Tab/Shift+Tab/Escape en ayuda, devolución de foco, menú y cierre al cambiar de breakpoint, y reducción de movimiento. Sin excepciones JS ni Web Storage. Capturas representativas de escritorio amplio y transición del menú revisadas.

Esta revisión automatizada no equivale a una certificación completa de accesibilidad ni a una nueva integración Laravel/PostgreSQL o validación Railway. Auxiliares/evidencias fuera de Git y servidor temporal retirado. Documentación sincronizada en contexto §17.53 y análisis de fronteras; diagrama global con aplazamiento expreso. La fase termina la revisión visual de las vistas construidas, sin declarar implementados los módulos futuros.

## Mejora visual y responsive — fase 3 completada (2026-10-21)

Revisión específica de las áreas autenticadas existentes:

- **Cuentas:** filtros, paginación y acciones adaptables; tabla con filas apiladas hasta 700 px conservando correo, ID, rol, estado y detalle visibles. Semántica de tabla comprobada en escritorio y móvil. Detalle administrativo en tres/dos/una columnas según ancho; formularios de acciones con longitud de lectura limitada.
- **Personal:** composición de ayuda/formulario equilibrada, nombres/apellidos emparejados cuando hay espacio y botón principal completo. Invitaciones, confirmaciones y entrega temporal usan la base visual compartida.
- **Pacientes:** ficha con identidad y acciones a ancho completo, contacto y emergencia paralelos en escritorio y apilados hasta 1100 px. Edición y perfil con ancho máximo coherente de 1000 px. Activación presencial limitada a 900 px, con entrega legible y acción de ocultar adaptable.
- **Correo de acceso:** solicitud y verificación en columnas independientes en escritorio, secuencia vertical en tablet/móvil, código destacado y separaciones claras de las ayudas.
- **Inicio/áreas de los cuatro roles:** ancho de lectura limitado, distribución proporcional y textos de disponibilidad apilados en móvil.

Archivos principales: páginas/componentes y estilos de `src/features/usuarios/` y `src/features/pacientes/`, `HomePage.jsx` y `src/styles/workspace.css`. Se ajustó la espera asíncrona del foco en una prueba existente de login.

**Verificado:** regresión completa **377 pruebas/22 archivos** y build correctos; aviso conocido de bundle principal ~508 kB. Chrome headless con React y transporte API ficticio: once variantes de vistas a 320/390/768/1024/1440, inicio general/área para cuatro roles a 320/390/1440 y estados adicionales; 100 capturas/comprobaciones sin overflow global ni controles fuera de ancho. **32 auditorías axe-core 4.10.3 sin infracciones automáticas**. Tabla de cuentas con cuatro encabezados y doce celdas de muestra en árbol accesible en los cinco anchos. Recorridos: alta/reenvío, estado de cuenta, entrega/ocultación temporal y de activación, estado de ficha, edición con 409/adopción/guardado, perfil y cambio de correo hasta login. Vacío/500/403 y denegación de ruta revisados a 320 px; Tab/foco visibles, sin Web Storage ni excepciones JS. Capturas representativas revisadas. No acredita nueva integración Laravel ni despliegue. Auxiliares fuera de Git y servidor temporal retirado.

Contexto §17.52 y referencia visual en `analisis-clases.md` sincronizados. **Siguiente:** fase 4 de revisión integral. Diagrama global aplazado expresamente; sin cierre funcional adicional.

## Mejora visual y responsive — fase 2 completada (2026-10-21)

Acceso y seguridad adaptados a la base visual de la fase 1:

- **Registro/vinculación:** ancho propio de hasta 680 px, campos agrupados en ficha/datos personales, contacto y acceso, con dos columnas solo cuando hay espacio real. Indicador compartido de los dos pasos: datos y verificación del correo.
- **Verificación:** código de seis dígitos más legible, acciones principal/reenvío separadas y condiciones de vigencia/reenvío ordenadas. Se conserva la respuesta neutra sobre la entrega de correo.
- **Recuperación, invitación y acceso restringido:** ancho de lectura consistente, ayudas, enlaces de retorno y confirmaciones uniformes. El panel ilustrado se retira hasta 960 px para priorizar la tarea; en escritorio acompaña al formulario largo sin estirarse con él.
- **Cambio de contraseña autenticado:** formulario con espacio proporcional y botón completo; una columna en tablet/móvil, manteniendo la información sobre renovación de sesión.

Archivos principales: `src/layouts/AuthLayout.jsx`, páginas de `src/features/auth/pages/`, `RegistrationProgress.jsx`, `RegistrationSession.jsx`, `styles/access.css`, `styles/registration.css` y el icono de correo compartido.

**Verificado:** `pnpm test`: **377 pruebas correctas/22 archivos**. `pnpm build` correcto con aviso conocido de bundle >500 kB (~507 kB). Chrome headless con React y API ficticia: ocho vistas en 320/390/768/1024/1440 px sin overflow global ni controles fuera del ancho; registro horizontal en 960×600. Dieciséis auditorías axe-core 4.10.3 sin infracciones automáticas a 390/1440; capturas revisadas. Ambos recorridos de registro, código inválido/espera, confirmaciones, enlaces inválidos, salida restringida, sesión existente/no disponible y validación 422 comprobados. Tab/foco visible y mostrar/ocultar contraseña por teclado correctos; secretos limpiados, tokens retirados de URL, sin Web Storage ni excepciones JS. Transporte simulado exclusivamente para revisión visual, sin nueva integración Laravel ni despliegue remoto. Auxiliares/capturas fuera de Git y servidor temporal retirado.

README, contexto §17.51 y referencia de fronteras de CU-04 en `analisis-clases.md` sincronizados. **Siguiente:** fase 3, demás áreas autenticadas; después fase 4, revisión integral. El diagrama global conserva su aplazamiento expreso; no se cierra ningún CU adicional.

## Mejora visual y responsive — fase 1 completada (2026-10-21)

Evolución del diseño existente con `frontend-design`: se conservan el verde clínico, los fondos claros y Source Sans 3. Tokens de separación/radios/controles y tamaño de título afinados; gutters fluidos, botones y campos compartidos consistentes y modal con padding adaptable. Sin nuevas dependencias.

- **Estructura:** lateral de 248 px con navegación persistente en escritorio; menú compacto hasta 960 px, dos columnas en tablet y una en móvil. Cabecera de área de 80 px, identidad legible y contenido con más espacio útil. Escape cierra el menú y devuelve el foco; navegar o cambiar de breakpoint lo cierra. El foco inicial del contenido ya no desplaza la cabecera al cargar la ruta.
- **Login de referencia:** panel de marca refinado conservando su ilustración, mejor separación de campos y dos enlaces de primer acceso con áreas táctiles independientes.
- **Listado de Pacientes de referencia:** filtros en cuatro columnas a partir de 1280 px, dos en anchos intermedios y una en móvil. Tabla en escritorio y filas apiladas hasta 700 px, conservando nombre/código/CI/estado/«Ver ficha» y semántica de tabla en el árbol de accesibilidad. Sin desplazamiento horizontal para consultar o abrir las fichas.
- **Formulario de referencia:** `PatientForm` agrupa identidad, contacto y emergencia; CI con ayuda a ancho completo, nombres/apellidos y fecha/sexo emparejados en escritorio, una columna en móvil. Su reutilización aplica esas mejoras a edición y perfil. Validaciones y operaciones se conservan.

**Verificado:** regresión completa de 377 pruebas/22 archivos; después de los últimos ajustes de distribución/foco, 81 pruebas focalizadas correctas. `pnpm build` correcto con el aviso existente de bundle superior a 500 kB (~504 kB). Chrome headless con React real y respuestas API ficticias interceptadas solo para la revisión visual: login/listado/alta en 320, 390, 768, 1024 y 1440 px, sin overflow global ni controles fuera del ancho; estados vacío/error a 320 px; estructura compartida de los cuatro roles a 390/1440. Capturas revisadas, seis auditorías axe-core 4.10.3 sin infracciones automáticas en las tres vistas a 390/1440, tabla presente con sus cinco encabezados/quince celdas de muestra en todos los anchos, Tab/Escape/cierre de menú y reducción de movimiento comprobados. Sin excepciones JS ni Web Storage. Auxiliares/capturas fuera de Git; servidor temporal de revisión retirado. No acredita una nueva integración con Laravel ni un despliegue Railway.

**Siguiente:** fase 2 para el resto de acceso/seguridad; fase 3 para las demás áreas autenticadas; fase 4 de revisión integral. La base compartida ya beneficia a esas vistas, pero su revisión visual específica sigue pendiente. Contexto actualizado en §17.50; sin nuevos CU, cambios de API o persistencia y sin cambios en análisis de clases/DER. Diagrama global mantiene el aplazamiento expreso.

## Railway: React y API por el mismo origen

El `Dockerfile` compila React con Node/pnpm y `VITE_API_URL=/api`, y sirve `dist/` mediante Caddy. `Caddyfile` reenvía `/api`, `/api/*` y `/sanctum/csrf-cookie` al backend definido en `BACKEND_ORIGIN`; conserva Origin, Referer, cookies, cabecera CSRF, cuerpo y rutas. Solo cambia Host al destino para el enrutado de Railway. Las respuestas de Laravel no pasan por el fallback de React ni se cachean; las rutas UI sí permiten recarga de la SPA.

### Servicio frontend en Railway

1. Configurar **Root Directory: `/frontend`** y utilizar el **Dockerfile** de esa carpeta. Si el servicio tenía un comando de inicio personalizado (`pnpm preview`, `vite`, etc.), quitarlo para utilizar el `CMD` de la imagen. Quitar también overrides de build que sustituyan el proceso del Dockerfile.
2. Añadir esta variable **de servidor**, sin comillas ni barra final:

   ```dotenv
   BACKEND_ORIGIN=https://clinica-vida-salud-production.up.railway.app
   ```

   Debe ser únicamente el origen del backend, sin `/api`. No renombrarla a `VITE_*`: el navegador no necesita conocer el destino interno del proxy.
3. El Dockerfile fija `VITE_API_URL=/api` durante el build. Retirar la variable anterior con URL absoluta del servicio, o dejarla como `/api` para que la configuración visible coincida. No se usa Vite como servidor de producción.
4. El servidor escucha en `0.0.0.0:$PORT`; por defecto 8080. Usar ese puerto objetivo si Railway solicita uno y `/login` como healthcheck opcional. Railway termina HTTPS; Caddy atiende HTTP dentro del contenedor.
5. Volver a desplegar el frontend después de subir los archivos. No basta con cambiar la variable del build anterior.

### Variables relacionadas del backend

```dotenv
APP_URL=https://clinica-vida-salud-production.up.railway.app
FRONTEND_URL=https://striking-enchantment-production-a5d9.up.railway.app
SANCTUM_STATEFUL_DOMAINS=striking-enchantment-production-a5d9.up.railway.app,clinica-vida-salud-production.up.railway.app
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_PATH=/
```

**Eliminar `SESSION_DOMAIN`** para cookies host-only; no establecer `.up.railway.app`. Al solicitarse a través del proxy, tanto la cookie de sesión como XSRF-TOKEN quedan asociadas al origen del frontend. La primera continúa HttpOnly y la segunda permite a Axios adjuntar X-XSRF-TOKEN. Mantener la APP_KEY existente. Aplicar cambios/recrear la caché de configuración del backend mediante su despliegue. No se desactiva CSRF ni se introduce JWT o almacenamiento de sesión en Web Storage.

El navegador debe consultar `https://striking-enchantment-production-a5d9.up.railway.app/api/auth/me` y `/sanctum/csrf-cookie` en ese mismo host. Antes del login, `/api/auth/me` responde 401 normal; CSRF responde 204, login válido 200 y logout 204. Tras el cambio de arquitectura, limpiar datos de los sitios antiguos o probar en incógnito.

**Verificación local (2026-10-21):** build con `/api` correcto; Caddy 2.11.7 valida configuración y pasa comprobaciones de fallback SPA, assets/404, conservación de las dos cookies, Origin/Referer/Cookie/X-XSRF-TOKEN, cuerpo/query, status/Retry-After y ausencia de repetición del POST. Chrome con la compilación estática, Caddy, Laravel y PostgreSQL reales en esquema aislado: login/recarga/logout Recepcionista y Paciente, cabecera CSRF presente y guardado de perfil persistido, sin 419 ni excepciones JS/Web Storage. Auxiliares fuera de Git; fixture y servidores temporales retirados. Docker no está instalado localmente: la construcción de la imagen y el flujo HTTPS de Railway deben comprobarse al desplegar. No se declara resuelto el entorno remoto antes de esa comprobación.

## Estado actual: Pacientes fases 1–6 — integración local verificada

Implementados sesión, contraseñas, cuentas del personal/invitaciones, acceso/roles/credencial temporal, ambos recorridos de registro del Paciente (CU-04) y emisión presencial de Recepción (parte de CU-07). Revisión del cliente con 213 pruebas correctas y AUT-21 verificada en Chrome contra Laravel/PostgreSQL reales con identidades ficticias aisladas. El correo se capturó localmente: aceptación y entrega mediante Brevo permanecen pendientes. La cronología inferior conserva las limitaciones de cada fase; el resultado vigente está al final.

**Avance vigente de Pacientes:** plan frontend completado para gestión administrativa CU-07 y perfil/correo CU-08, con integración real local y regresión de **377 pruebas frontend**. La consulta de citas anteriores conserva su dependencia de CU-21; no se acredita RF02 íntegro por esta entrega. Cierre y límites de fase 6 a continuación de la cronología de Pacientes.

### Pacientes — fase 1 frontend (2026-10-20)

Recepción dispone de **Pacientes** en su menú y área, con listado en `/recepcion/pacientes` y detalle en `/recepcion/pacientes/:id`. Filtros por CI, nombres, apellidos y estado de ficha; búsqueda al enviar, limpieza y paginación por `meta` del servidor. Consultas GET `/api/pacientes` y `/api/pacientes/{id}` según contratos existentes. Presenta identidad, teléfono, correo de contacto, dirección y emergencia, incluidos datos ausentes; distingue ficha activa/inactiva de cuenta vinculada, sin inferir estado de acceso ni bloqueos.

Servicios con filtros permitidos, cookies y `sessionBound`; IDs como texto y timestamps intactos. Consultas cancelables y respuestas tardías descartadas; carga/vacío/error/reconsulta y 401/403/404/419/422/429/red/500. La UI no almacena fichas/filtros personales en Web Storage ni los agrega a URL. Activación existente mantiene emisión por ID y orienta al nuevo catálogo.

**Verificación actual:** `pnpm test`: **243 pruebas correctas/16 archivos** (30 nuevas); `pnpm build` correcto. Se corrigió una espera prematura en la prueba previa de invitación para esperar su confirmación. Chrome headless con API simulada recorrió listado → detalle → búsqueda vacía; Tab entre filtros, anchos 1440/768/390/320 sin overflow global, capturas revisadas y axe sin infracciones automáticas en listado/detalle a 1440/390. Sin excepciones JS. Esta revisión no acredita integración autenticada real de las nuevas consultas con Laravel/PostgreSQL.

**Siguientes fases:** 2 alta administrativa; 3 edición/CI/estado/activación desde ficha; 4 perfil propio; 5 correo verificado; 6 integración/responsive/cierre. Avance parcial de CU-07, sin cerrar CU-07/CU-08. Documentación: contexto §17.41 y análisis incremental; DER sin cambios y diagrama global aplazado expresamente. Comprobación real Brevo aplazada al despliegue por decisión del usuario.

### Pacientes — fase 2 frontend (2026-10-20)

**Registrar paciente** desde el listado abre `/recepcion/pacientes/nuevo`, exclusiva de Recepción. El formulario envía identidad/fecha civil, teléfono, sexo de texto opcional, correo de contacto/dirección y emergencia completa opcionales. Utiliza POST `/api/pacientes`, CSRF, cookies y campos permitidos; normaliza opcionales vacíos a NULL. No crea cuenta web, códigos ni preferencias. Solo 201 confirmado muestra éxito y enlace a la ficha persistida, preservando BIGINT textual.

**Duplicados y ausencia de CI:** búsqueda integrada por CI exacto o nombres/apellidos, incluidos inactivos, con paginación y enlaces a fichas en otra pestaña. Para alta sin CI se exige consulta exitosa y declaración de revisión; modificar identidad invalida lo revisado. No se fusionan fichas ni se presenta una coincidencia como prueba de titularidad o la falta de resultados como garantía de unicidad. El CI sigue siendo obligatorio para registro/vinculación web.

**Fallos:** doble envío bloqueado, 409 orientado a revisión, 422 por campo con conservación de datos y foco, 419 sin reenvío automático, 429 con espera, 403 sin logout y 401 retirando formulario. Un fallo de red/5xx o respuesta no confirmada conserva resultado incierto y exige consultar otra vez el registro antes de habilitar otro envío manual. No se afirma rollback ni ausencia de duplicados por una búsqueda. Datos solo en memoria, sin Web Storage.

**Verificación vigente:** **271 pruebas correctas/17 archivos**, 28 nuevas; `pnpm build` correcto. Incluye recorrido con servicios/Axios reales y transporte simulado (revisión → CSRF → alta sin CI → detalle) y revocación 401 al enviar. Chrome headless simulado revisa formulario con emergencia, confirmación y detalle; formulario/confirmación a 1440/768/390/320 sin overflow global, capturas revisadas y axe sin infracciones automáticas a 1440/390; teclado Tab correcto, un solo POST, Web Storage vacío y sin excepciones JS. Integración autenticada real de estas pantallas con Laravel/PostgreSQL aún pendiente.

**Siguiente entrega:** fase 3 edición/CI/estado/activación desde ficha. CU-07/CU-08 frontend conservan avance parcial. Documentación sincronizada en contexto §17.42 y análisis incremental; DER sin cambios y diagrama global aplazado expresamente.

### Pacientes — fase 3 frontend (2026-10-20)

Recepción puede editar desde la ficha mediante `/recepcion/pacientes/:id/editar`: contacto/emergencia y corrección de identidad con comprobación presencial, motivo y confirmación. El CI informado no se retira; incorporarlo o corregirlo advierte de la invalidación de códigos y vinculaciones pendientes, conservando cuenta e historial. Se envían únicamente diferencias permitidas por PATCH `/api/pacientes/{id}`.

El detalle permite desactivar/reactivar ficha con motivo y confirmación mediante PATCH `/api/pacientes/{id}/estado`. Se explica la independencia de cuenta web, sesiones, bloqueos y citas existentes. La activación desde `/recepcion/pacientes/:id/activacion` consulta la ficha, fija su ID y reutiliza `ActivationCodePage`; requiere CI y ausencia de cuenta vinculada, con comprobación final del servidor.

**Concurrencia y errores:** `actualizado_en_original` conserva los seis decimales sin pasar por `Date`. Conflicto obsoleto o resultado incierto bloquean nuevas escrituras y conservan el borrador/motivo mientras se consulta la versión actual. Cargarla y descartar el borrador exige una acción explícita; estado reinicia motivo/confirmación. Sin reenvíos automáticos ni Web Storage; campos seguros y espera Retry-After.

**Verificación vigente:** **306 pruebas correctas/18 archivos**, 35 nuevas; `pnpm build` correcto. Incluye permisos, payload parcial, CI, confirmaciones, microsegundos, 409 y revisión explícita con servicios/Axios reales y adaptador simulado, fallos inciertos y activación seleccionada. Chrome con API simulada completa CI → conflicto/revisión → estado → activación/ocultado; cuatro vistas a 1440/768/390/320 sin overflow global, capturas desktop/móvil revisadas para identidad y conflicto, axe sin infracciones automáticas a 1440/390, Web Storage vacío y sin excepciones JS.

**Siguiente entrega:** fase 4 perfil propio; después correo verificado e integración autenticada real de las pantallas con Laravel/PostgreSQL. CU-07/CU-08 siguen con cierre frontend pendiente. Contexto §17.43 y análisis incremental actualizados; diagrama global aplazado por decisión expresa. Brevo real sigue pendiente de despliegue.

### Pacientes — fase 4 frontend: Mi perfil (2026-10-20)

`/mi-perfil` está disponible exclusivamente para Paciente, desde el menú y su área personal. Consulta GET `/api/mi-perfil` y muestra identidad como solo lectura (CI, nombres, apellidos, nacimiento civil, sexo y estado), orientando correcciones a Recepción. Permite cambiar teléfono, correo de contacto, dirección, emergencia y preferencia de recordatorios mediante PATCH del mismo endpoint, sin ID del titular en URL/payload. Cuenta activa con ficha inactiva conserva estas operaciones; se informa de la restricción de nuevas reservas.

Reutiliza `PatientForm` limitado a contacto y emergencia y presenta recordatorios como elección explícita: NULL se mantiene «Sin preferencia registrada» sin inventar un valor. Retirar emergencia/deshabilitar avisos requiere confirmación. Correo de acceso se muestra separado y enlaza al cambio de contraseña ya existente. No carga información clínica. El cambio verificado de correo de acceso corresponde a fase 5.

Actualiza solo diferencias permitidas, con CSRF/cookies/sessionBound y `actualizado_en_original` intacto. Obsolescencia o resultado incierto conservan borrador y bloquean escrituras hasta revisión explícita de la versión propia actual, incluidas preferencias. Consulta cancelable, errores seguros por campo, Retry-After y retirada de datos al invalidarse sesión; sin Web Storage. Guardado confirmado reconsulta el perfil; una nueva tentativa retira el mensaje de éxito anterior.

**Verificación vigente:** **338 pruebas correctas/20 archivos**, 32 nuevas (23 interfaz/integración y 9 contrato); build correcto. Incluye Axios real con transporte simulado para GET → CSRF/PATCH → GET y 401 durante PATCH. Se ajustan esperas asíncronas anteriores de consulta de ficha, foco de activación y confirmación de invitación. Chrome con API simulada recorre edición de ficha inactiva, emergencia/preferencia, conflicto/adopción y confirmación de retirada/deshabilitación; tres vistas a 1440/768/390/320 sin overflow global, capturas de perfil móvil/conflicto desktop revisadas, axe sin infracciones automáticas a 1440/390, Tab entre contacto correcto, sin consultas administrativas, Web Storage vacío y sin excepciones JS.

Contexto §17.44 y análisis incremental sincronizados. Próxima fase 5: correo de acceso verificado; fase 6: integración autenticada real Laravel/PostgreSQL y cierre de CU-07/CU-08. DER sin cambios; diagrama global aplazado por decisión expresa. Comprobación real Brevo pendiente de despliegue.

### Pacientes — fase 5 frontend: cambio verificado del correo de acceso (2026-10-20)

Desde Mi perfil, el Paciente abre `/mi-perfil/correo`. La nueva solicitud requiere correo distinto, contraseña actual y confirmación de sustitución de una solicitud anterior. Consume POST `/api/mi-perfil/correo`, `/correo/reenviar` y `/correo/verificar` existentes. Presenta `envio` aceptado/simulado/fallido/pendiente sin afirmar llegada al buzón, junto al vencimiento del código según servidor; muestra espera de 60 segundos tras emisión y respeta Retry-After. Laravel determina límites, vigencia y consumo.

La verificación utiliza código de seis dígitos, preservando ceros iniciales; no envía destino, contraseña ni identificador. Se puede retomar la solicitud de la misma cuenta tras volver a iniciar sesión: el servidor selecciona la última solicitud y su destino, sin recuperar un proceso con CI/correo. El correo vigente permanece visible hasta confirmar; el correo de contacto es independiente. Código inválido/vencido/agotado tiene respuesta segura común, sin contadores locales autoritativos.

Solo 204 confirma el cambio. `AuthContext.completeEmailChange` coordina la escritura, invalida comprobaciones anteriores y limpia el contexto público con aviso de éxito al login, sin autenticación automática. Errores de red/5xx/respuesta no confirmada bloquean escrituras y exigen consultar explícitamente sesión/perfil antes de decidir otro envío, sin afirmar rollback o cambio exitoso. Contraseña y código se limpian tras sus operaciones, al salir y en pagehide; sin Web Storage, códigos ni contraseñas en URL.

**Verificación vigente:** **377 pruebas correctas/22 archivos**, 39 nuevas (26 interfaz/integración y 13 contratos). Incluye servicios/Axios reales con adaptador simulado para solicitud 202 → verificación 204 → login y 401 al verificar, carrera con comprobación /me anterior, roles, estados de transporte, reenvío, límites, secretos y resultado incierto. Build correcto con aviso de tamaño del bundle principal (~503 kB minificado). Chrome con API simulada completa Mi perfil → solicitud → confirmación → código → login; tres vistas a 1440/768/390/320 sin overflow global, capturas código móvil/confirmación desktop revisadas, axe sin infracciones automáticas a 1440/390, Tab correcto, Web Storage vacío y sin excepciones JS.

README/análisis/contexto §17.45 sincronizados. Fase 6 pendiente: integración autenticada real Laravel/PostgreSQL, revisión final y cierre frontend CU-07/CU-08. Persistencia/DER sin cambios; diagrama global aplazado expresamente. Entrega real Brevo permanece pendiente de despliegue según decisión del usuario.

### Pacientes — fase 6 frontend: integración real y cierre del plan (2026-10-20)

Tras iniciar PostgreSQL, Chrome headless recorrió React → Axios → Laravel → PostgreSQL reales. Se utilizó base/rol `clinica_vida_salud_test`, esquema aleatorio aislado, los cuatro roles y pacientes ficticios; frontend temporal en localhost:5174 y Laravel en localhost:8001. Sin interceptar respuestas de la API ni deshabilitar CSRF. Únicamente el transporte de correo se sustituyó por captura local para evitar envíos externos.

| Recorrido | Resultado verificado |
| --- | --- |
| Recepción | Listado, paginación, búsqueda, alta sin CI después de revisión explícita, detalle e incorporación del CI conservando ID. |
| Mantenimiento | Edición con 409 obsoleto real, borrador conservado/bloqueado, adopción explícita de versión actual y guardado posterior; desactivación/reactivación con motivo y confirmación. |
| Activación | Emisión desde ficha seleccionada con ID de solo lectura, código de 12 caracteres y ocultación tras comunicarlo. No crea cuenta. |
| Perfil | Cuenta activa/ficha inactiva editable; identidad solo consulta, contacto/emergencia/preferencia persistidos, correos independientes, 409 propio y confirmación de retirada de emergencia/deshabilitación. |
| Correo de acceso | Contraseña actual, solicitud 202/código capturado, correo anterior vigente hasta confirmar 204, retorno al login y nuevo acceso conservando ficha, contraseña y correo de contacto. |
| Protección | Roles ajenos rechazados sin cerrar sesión; sesión vencida retira datos privados; cookies HttpOnly/SameSite Lax, CSRF y CORS con credenciales reales. |

Respuestas reales observadas: **200/201/202/204/401/403/409/419/422/429**. Algunas comprobaciones negativas (CSRF ausente, campos prohibidos, permisos y límite de reenvío) se ejecutaron mediante fetch autenticado en el navegador; no se presentan como errores provocados desde controles normales. La comprobación SQL final verificó vínculo/identidad, cuenta activa con ficha inactiva, correo nuevo, contraseña conservada y bitácora sin contraseña ni códigos de la prueba.

**Verificación de cierre:** `pnpm test`: **377 pruebas correctas/22 archivos**; `pnpm build` correcto con el aviso conocido de bundle principal ~503 kB. Regresión focalizada Laravel de seis clases de Pacientes/perfil/correo: **134 pruebas/1272 aserciones correctas**. Las seis vistas reales (listado, detalle, alta, edición, perfil y correo) sin overflow global a 1440/768/390/320; axe sin infracciones automáticas a 1440/390, Tab comprobado en perfil, capturas listado desktop/perfil móvil revisadas. Sin excepciones JS ni Web Storage.

Auxiliares/evidencia no sensible en `C:\Users\jesus\AppData\Local\Temp\opencode\pac6*`, fuera de Git. Servidores temporales detenidos; esquema aislado y secretos temporales retirados al finalizar. No se requirieron correcciones del código funcional ni cambios de esquema. Análisis/contexto §17.47 y README backend sincronizados. Se completa el plan frontend del módulo dentro del alcance administrativo/perfil aprobado; CU-21, entrega real de Brevo/HTTPS de producción y diagrama global expresamente aplazado conservan su estado pendiente. El DER no cambia.

## Fase 1: base visual e inicio de sesión

React + Vite + JavaScript, React Router y Axios. Diseño con `frontend-design`, conforme a `AGENTS.md`. Primera pantalla funcional en `/login`; validación visual del usuario pendiente antes de extender el diseño.

### Desarrollo local

Requisitos: Node >=22.12 y pnpm 11.17.0; comprobados con Node 24.18.0. Las versiones efectivas quedan fijadas en `pnpm-lock.yaml`.

```powershell
pnpm install --frozen-lockfile
Copy-Item .env.example .env
pnpm dev
```

Abrir **http://localhost:5173/login**. Laravel debe estar ejecutándose por separado en **http://localhost:8000** y PostgreSQL disponible. La aplicación utiliza `VITE_API_URL` (por defecto local `http://localhost:8000/api`); esta variable es pública. No alternar `localhost` y `127.0.0.1`: cookies, CORS y Sanctum dependen del host. Puerto estricto 5173 para conservar el origen aprobado.

```powershell
pnpm test
pnpm build
pnpm preview
```

La compilación incluye fuentes locales; no consulta Google Fonts. Para otros entornos se debe proporcionar `VITE_API_URL` antes de compilar y coordinar HTTPS, cookies, CORS y dominios stateful con la configuración real del servidor. Esta entrega no configura producción.

## Sistema de diseño v0.1

**Dirección:** claridad clínica. Una identidad compartida para los cuatro roles, con diferencias de navegación/acciones según autorización. Superficies amplias, alineación izquierda, contenido funcional y color concentrado en identidad/acciones. Sin animaciones de entrada, degradados ni estadísticas decorativas. Composición de acceso en dos columnas; móvil prioriza el formulario y elimina la ilustración decorativa.

### Tokens reutilizables

Fuente de verdad: `src/styles/tokens.css`.

| Uso | Valor |
| --- | --- |
| Fondo / superficie | `#F5F8FA` / `#FFFFFF` |
| Texto / secundario | `#18333B` / `#526871` |
| Identidad / hover | `#146B68` / `#105653` |
| Borde estructural | `#D5E0E5`; campos usan `#81979F` para distinguir el control |
| Fondo editorial / ilustración | `#EAF3F1` / `#D1E6DF` |
| Error / éxito | `#A52E38` / `#206746`, con fondos semánticos claros |
| Información / advertencia | `#245B79` / `#805413`, con fondos semánticos claros |
| Tipografía | Source Sans 3 local; pesos 400/600/700, fallback system-ui |
| Tamaños | Texto 16 px; auxiliar 14 px; título fluido 28–36 px; interlineado 1,5 |
| Espaciado | 4, 8, 12, 16, 20, 24, 32, 40 y 48 px; margen lateral fluido 16–40 px |
| Radios | Controles 8 px; paneles/modales 14 px; panel editorial asimétrico 24/80 px, reducido en móvil |
| Interacción | Botones de 46 px mínimo; campos de 50 px; controles de icono de 44 px |

### Componentes implementados

- `Button`: principal, secundario, discreto y destructivo; carga identificada por texto/aria-busy y bloqueo del envío.
- `FormField`: etiqueta vinculada, ayuda/error asociados, required, autocomplete y error por campo. Contraseñas permiten pegar y mostrarse mediante un botón con nombre accesible.
- `Alert`: mensajes semánticos con icono/texto; error anunciado con role=alert. No depende solo del color.
- `Modal`: dialog nativo, título accesible, contención del foco, Escape, retorno al disparador y bloqueo del scroll de fondo. Usado en ayuda de acceso.
- `Brand` e `Icon`: identidad e iconos SVG locales, decorativos fuera del árbol accesible.
- `AuthLayout`: cabecera, ayuda, introducción visual, contenido y pie. Incluye enlace para saltar al contenido.

### Convenciones definidas para las siguientes pantallas

Se implementarán cuando exista una pantalla que las necesite:

- **Tablas:** HTML semántico, encabezado de 14 px/600, filas con padding 12–16 px, separadores horizontales, texto a la izquierda y números según significado; sin sombras por fila. Filtros visibles, conteo y paginación del servidor. En móvil, desplazamiento local identificable o detalle equivalente que preserve los datos.
- **Estados:** Programada, Confirmada, Atendida, Cancelada e Inasistencia conservan sus nombres oficiales cuando se desarrollen citas. Etiquetas con texto, nunca solo un punto de color. Cuenta, ficha y bloqueo se muestran por separado.
- **Vacío/carga/error:** indicar qué falta, qué está ocurriendo y la acción disponible; no inventar éxito, registros o permisos. Conservar el espacio del contenido cuando ayude a evitar saltos.
- **MainLayout:** implementado en fase 2 con navegación lateral en escritorio, menú accesible en móvil y cabecera contextual; mismo lenguaje visual para los cuatro roles.

## Flujo y contratos consumidos

`LoginPage → auth.service.js → api.js → Laravel`. La página coordina formulario/estado; los componentes presentan; el servicio realiza HTTP; Axios centraliza origen/cookies/CSRF. El backend sigue decidiendo cuenta, bloqueo, rol y autorización.

1. GET `/sanctum/csrf-cookie` con credenciales.
2. POST `/api/auth/login` con **solo** `correo_acceso` y `password`, usando `X-XSRF-TOKEN` de Axios. La contraseña no se recorta ni transforma.
3. Éxito 200: AuthContext conserva solo la proyección pública y dirige al inicio o destino interno seguro. El contexto restringido se dirige exclusivamente a `/clave-obligatoria`, sin asumir rol/identidad ni acceso ordinario.
4. GET `/api/auth/me` permite comprobar la sesión ante 409 o resultado incierto de red. No se reenvía automáticamente el login.
5. Cierre explícito: renovar CSRF y POST `/api/auth/logout`; el estado se limpia tras confirmación o rechazo 401 de una sesión ya inexistente. Fallos de red/500 no se anuncian como cierre exitoso.

No existen JWT, tokens en almacenamiento web, selección pública de rol, credenciales predeterminadas ni persistencia del estado de sesión en el cliente. Contraseñas se limpian después de cada envío y se ocultan de nuevo. El límite de 12 caracteres se aplicará al **establecer** contraseñas; login exige presencia y máximos del contrato, para admitir las credenciales autorizadas por el servidor.

Errores 401/403/409/419/422/429/500 y red tienen mensajes seguros. Se muestran únicamente errores de campos permitidos de 422; no se imprimen cuerpos HTTP técnicos. 429 respeta Retry-After numérico del servidor sin simular la política de bloqueo 3/10. Un nuevo envío manual obtiene CSRF otra vez; ninguna escritura se reintenta automáticamente.

## Verificación de fase 1

- **19 pruebas Vitest/Testing Library correctas:** cuatro de contrato HTTP y quince de interfaz; cuatro roles, acceso restringido, validación nativa/422 y foco, 401/403/419/500, 429, resultado incierto, duplicación y cierre confirmado/fallido.
- `pnpm build` correcto.
- Chrome headless real: anchos 1440, 768, 390 y 320 px sin desbordamiento horizontal; capturas desktop/móvil revisadas. Modal probado con Tab, Escape y retorno de foco.
- axe-core 4.10.3: **cero infracciones automáticas** WCAG 2 A/AA y 2.1 AA en la pantalla inicial móvil. Esto no sustituye revisión humana completa.
- Integración con Laravel local: formulario real obtiene cookies CSRF/HttpOnly y recibe rechazo 401 genérico con credenciales ficticias; petición sin CSRF recibe 419; `/auth/me` anónimo recibe 401. CORS con credenciales funciona. Sin excepciones JS durante el recorrido.
- Los accesos **exitosos** de cada rol se verifican con respuestas simuladas en pruebas; no se usaron credenciales de usuarios reales ni se crearon cuentas para este trabajo. AUT-21 completo sigue pendiente.

## Próximas fases

1. Base visual/login implementada; el usuario solicita continuar conservando la identidad.
2. Implementada: AuthContext/useAuth, restauración automática, MainLayout, rutas y navegación por rol.
3. Implementada con integración real pendiente: cambio ordinario y recuperación por enlace.
4. **Actual, implementada con integración real pendiente:** personal, invitaciones y consultas administrativas.
5. Estado/roles y credencial temporal/cambio obligatorio.
6. Registro nuevo, vinculación y emisión presencial.
7. Integración completa por rol, revisión final y entrega real de Brevo autorizada.

El inicio autenticado identifica explícitamente las funciones posteriores pendientes. No hay enlaces ficticios hacia registro/recuperación ni datos de negocio simulados en la aplicación. No se modificaron el backend o las reglas funcionales. El diagrama global sigue aplazado por decisión del usuario.

## Fase 2 — sesión, layout y permisos (2026-10-20)

### Componentes y responsabilidades

- `features/auth/context/AuthContext.jsx`: única fuente del estado **público** de autenticación en React. Estados loading/anonymous/authenticated/restricted/unavailable; valida y proyecta la respuesta del servidor, sin guardar credenciales ni información clínica.
- `hooks/useAuth.js`: acceso al contexto. Expone usuario, loading, isAuthenticated (solo acceso normal), isRestricted, login, logout y refreshSession.
- `SessionGate`: espera la comprobación inicial y ofrece reintento ante fallos de conexión/403/419/500. Un fallo operativo no se confunde con sesión ausente y no deja visible el área privada.
- `ProtectedRoute`, `GuestRoute` y `RoleRoute`: controlan acceso visual, destino posterior al login y denegación por rol. La autorización efectiva sigue en Laravel.
- `MainLayout`: cabecera con nombre/rol, navegación exclusiva del rol, contenido y cierre. Menú móvil con aria-expanded/controls, Escape y retorno de foco; navegación identifica la página activa y enfoca contenido al cambiar de ruta.
- `LogoutButton`: bloqueo de envíos duplicados y mensaje de resultado incierto si falla el cierre.
- `HomePage`, `AccessDeniedPage` y `RestrictedAccessPage`: inicio común/área de rol, acceso denegado sin logout y frontera mínima restringida. El cambio obligatorio real sigue pendiente para fase 5.
- `constants/roles.js`: cuatro roles fijos, sus áreas y lista permitida de destinos internos; no hay asignación pública de rol ni redirecciones externas.

### Rutas UI

| Ruta | Acceso |
| --- | --- |
| `/login` | Anónimo; sesión normal redirige a inicio/destino seguro y restringida a cambio obligatorio. |
| `/inicio` | Cualquiera de los cuatro roles con sesión normal comprobada. |
| `/administracion` | Administrador. |
| `/recepcion` | Recepcionista. |
| `/medico` | Médico. |
| `/paciente` | Paciente. |
| `/clave-obligatoria` | Exclusivamente sesión restringida; sin menú ni identidad ordinarios. |

Las cuatro áreas muestran una introducción, la proyección pública de cuenta y funciones previstas rotuladas «Próximamente». No conceden operaciones clínicas/administrativas ni llaman a endpoints nuevos. Una ruta de otro rol muestra acceso denegado conservando la sesión; una ruta desconocida muestra página no encontrada dentro del área normal. `/` conduce al inicio y sus controles de sesión.

### Restauración, errores y concurrencia del cliente

Al montar se consulta GET `/api/auth/me`, sin duplicar la restauración bajo StrictMode. No se muestran datos ni se redirige mientras la comprobación inicial está pendiente. Al volver a una pestaña/ventana visible se revalida; no se agrega polling ni se asume que una sesión siga válida por conservar un objeto en memoria.

Las peticiones autenticadas del servicio se marcan con **`sessionBound: true`**. Los servicios protegidos futuros deben usar esa opción de la instancia Axios común. Un interceptor de 401 notifica al contexto sin depender de React: limpia la identidad y retira la vista privada. Un 401 de login no provoca esa invalidación; 403 no cierra la sesión, y 419 nunca reenvía escrituras automáticamente.

Un contador de generación en HTTP y una secuencia de operaciones en AuthContext descartan respuestas de contextos anteriores: una restauración tardía no puede resucitar un logout y un 401 antiguo no expira un login nuevo. Las consultas simultáneas de restauración se comparten. No se introducen tokens propios, persistencia de usuario ni dependencias nuevas.

### Evidencia

- **43 pruebas correctas:** 4 de contrato HTTP, 15 de login adaptadas al contexto y 24 de sesión/rutas. Cubren espera inicial y StrictMode, cuatro roles, URLs manipuladas, restauración restringida, fallos de comprobación/reintento, almacenamiento falso ignorado, revocación al volver a la ventana, 401/403 protegidos, respuestas tardías, logout y destinos externos rechazados.
- `pnpm build` correcto.
- Chrome real con respuestas ficticias interceptadas para las áreas autenticadas: los cuatro roles restauran tras recarga, navegación manipulada se deniega, logout retira el área, sesión restringida no recibe navegación y revocación al volver al foco dirige a login.
- Layout revisado en capturas de escritorio/móvil y anchos 1440/768/390/320 sin overflow horizontal. Menú móvil y Escape/retorno de foco comprobados; control móvil oculto en escritorio.
- axe-core 4.10.3: **cero infracciones automáticas** WCAG 2 A/AA y 2.1 AA en inicio autenticado desktop y móvil. Sin excepciones JS en el recorrido.
- Laravel real, navegador sin sesión: acceso directo a `/inicio` recibe rechazo de `/auth/me` y redirige al login. Los éxitos autenticados/recargas por rol en esta fase se verifican con respuestas simuladas, sin usar cuentas reales. AUT-21 completo sigue pendiente.

Documentación sincronizada en contexto §17.28 y análisis incremental CU-01/CU-02. Siguiente entrega: **fase 3, cambio ordinario y recuperación de contraseña**. DER y diagrama global no cambian.

## Fase 3 — CU-05/CU-06 (2026-10-20)

### Pantallas y contratos

| Ruta UI | Acceso y operación |
| --- | --- |
| `/mi-cuenta/contrasena` | Sesión normal de cualquiera de los cuatro roles. PUT `/api/auth/password` con `current_password`, `password` y `password_confirmation`. La sesión restringida sigue en su flujo separado. |
| `/olvide-mi-contrasena` | Solicitud pública. POST `/api/auth/password/forgot` con `correo_acceso`; tras 202 se muestra exclusivamente el mensaje genérico «Si corresponde…», sin afirmar existencia ni entrega. |
| `/recuperar-clave#token=…` | Enlace emitido por Laravel. POST `/api/auth/password/reset` con `token`, `password` y `password_confirmation`. Sin login automático. |

`ChangePasswordPage`, `ForgotPasswordPage` y `ResetPasswordPage` coordinan formularios; `PasswordField` reutiliza etiquetas, ayuda y controles de visibilidad accesibles. `usePasswordSubmission` controla duplicación, errores seguros, foco y espera del `Retry-After` recibido. `password.service.js` obtiene CSRF antes de cada escritura y envía exclusivamente los campos del contrato por Axios; solo el cambio ordinario usa `sessionBound: true`.

`AuthContext.updatePassword` coordina el cambio/restablecimiento y consulta `/me` después del éxito. Descarta comprobaciones anteriores mediante la secuencia/generación existente. Laravel renueva la sesión actual al cambiar ordinariamente e invalida sesiones de la cuenta recuperada al restablecer; una sesión de otra cuenta en el mismo navegador se conserva únicamente si `/me` lo confirma. No se guardan contraseñas ni tokens en el contexto.

El enlace se captura antes de crear el router, retirando query y fragmento del historial actual. Solo se acepta un token hexadecimal de 64 caracteres en el fragmento; no se aceptan tokens por query. La entrega temporal al formulario se borra al montarlo; el token queda en memoria local hasta éxito, rechazo definitivo o desmontaje. Recargar exige abrir de nuevo el enlace del correo. No se coloca el secreto en campos ocultos, Router state, almacenamiento web ni logs.

Validación visual de mínimo 12 caracteres y coincidencia; Laravel conserva las comprobaciones definitivas. Las contraseñas se limpian al terminar cada envío HTTP. Errores 422 por campo, contraseña actual incorrecta y enlace inválido/vencido/usado se presentan sin cuerpos técnicos. 419, 429, fallos de servidor y red no producen éxito ni reenvíos automáticos; un fallo de red comunica resultado incierto.

### Evidencia y pendientes

- **81 pruebas correctas en ejecuciones focalizadas:** 43 de regresión anterior y 38 de fase 3 (22 de pantallas, nueve de contratos HTTP y siete de captura del enlace). Incluyen cuatro roles, restricción, validación/foco, duplicación, Retry-After, errores, StrictMode, respuesta tardía, limpieza de secretos y nuevo login tras recuperación.
- `pnpm build` correcto. No hay script de lint configurado.
- Chrome headless con respuestas simuladas: solicitud genérica, cambio con reconsulta de sesión, recuperación sin login automático, token retirado de URL/DOM y no recuperable tras recarga. Sin excepciones JavaScript.
- Capturas desktop/móvil revisadas; cambio sin overflow en 1440/768/390/320 px y solicitud sin overflow en 1440/390 px.
- Axe-core 4.10.3: cero infracciones automáticas WCAG 2 A/AA y 2.1 AA en las tres pantallas a 1440 y 390 px; no sustituye una auditoría humana completa.
- **Integración real de fase 3 pendiente:** el intento de navegador no completó la comprobación de sesión y PostgreSQL no escuchaba en el puerto local esperado (5432). No se acredita un rechazo real de reset ni cambios reales de credenciales. Entrega Brevo y AUT-21 completo permanecen pendientes.

Análisis incremental CU-05/CU-06 y contexto §17.29 sincronizados. Siguiente fase: **4, personal, invitaciones y consulta administrativa**. El diagrama global continúa aplazado expresamente; no hubo cambios de persistencia.

## Fase 4 — consulta de cuentas, alta e invitaciones, CU-03 parcial (2026-10-20)

### Rutas y contratos

| Ruta UI | Comportamiento |
| --- | --- |
| `/administracion/cuentas` | Solo Administrador normal. GET `/api/usuarios` con filtros opcionales de rol, estado y correo exacto, y paginación del servidor. |
| `/administracion/cuentas/nueva` | POST `/api/personal` con correo/rol y nombres/apellidos para Administrador o Recepcionista; para Médico, solo referencia `id_medico` existente. Sin contraseña administrativa ni alta de Paciente. |
| `/administracion/cuentas/:id` | GET `/api/usuarios/{id}` con datos mínimos autorizados. Reenvío inicial mediante POST `/api/personal/{id}/invitacion`, solo ofrecido para personal pendiente sin correo verificado y con confirmación visual. |
| `/activar-personal#token=…` | Aceptación pública de invitación mediante POST `/api/auth/invitaciones/aceptar` con token, nueva contraseña y confirmación. Si hay sesión normal o restringida, exige cerrarla primero. |

El backend sigue validando permisos, unicidad, vínculo profesional y elegibilidad en cada operación. No existe aún un endpoint de catálogo de médicos; el alta pide el identificador profesional previo como texto, sin convertir BIGINT a Number ni crear registros profesionales. Los identificadores se validan antes de construir rutas.

### Organización y estados

- `features/usuarios/pages/`: listado, alta y detalle. Componentes específicos para tabla, errores y resultado de envío; estilos locales en `styles/accounts.css`.
- `usuario.service.js`: llamadas con la instancia Axios común y `sessionBound: true`; CSRF previo a escrituras, campos explícitos según rol y sin reintentos automáticos. La página nunca sigue URLs de paginación devueltas por la API: usa `meta` para solicitar la página al endpoint fijo.
- `useAccountQuery`: carga, datos, vacío/error, cancelación con AbortController e ignorado de respuestas de filtros/rutas anteriores. Al cambiar filtros o actualizar detalle retira la respuesta previa hasta recibir la nueva.
- `useAccountSubmission`: exclusión de envíos duplicados, errores seguros, foco y espera conforme al Retry-After recibido. 422 conserva campos corregibles; un timeout de alta orienta a consultar la cuenta por correo exacto antes de repetir.
- `SelectField` reutilizado en filtros y alta, con el mismo lenguaje visual de `FormField`. Tabla semántica desplazable localmente por teclado/táctil y aviso móvil; filtros se apilan sin perder información. Menú marca únicamente la sección activa.
- Paciente recibe solo proyección administrativa mínima, sin nombres clínicos, documento, vínculo profesional o invitación; no hay formularios de creación/contraseña/rol de Paciente.
- Resultados de invitación distinguen **simulado**, **aceptado por proveedor**, **fallido** y **pendiente**. Alta confirmada permanece pendiente aunque falle el envío; se ofrece consulta de la misma cuenta. Reenvío invalida el enlace anterior según Laravel y vuelve a consultar detalle, sin crear otra cuenta.
- `AcceptInvitationPage` usa controles de contraseña y captura del fragmento existentes. Retira el token antes del router y de la entrega temporal al montarse; solo memoria local, sin campos ocultos ni almacenamiento web. Ausencia/vencimiento/uso/revocación orienta a Administración; éxito confirmado exige login, sin autenticación automática. La sesión activa puede cerrarse conservando el token local mientras la página siga montada.

### Verificación y avance

- **131 pruebas correctas en ejecuciones focalizadas:** 81 anteriores y 50 nuevas (22 de interfaz administrativa, 16 de servicios/contratos, 11 de aceptación y una adicional de captura del enlace).
- Incluyen denegación para los otros tres roles, filtro/paginación, cancelación/respuestas tardías, vacío/403/404/500, BIGINT, exclusión de campos, roles de alta, envío simulado/aceptado/fallido/pendiente, duplicación, 422/foco, conflicto de correo, resultado incierto y Retry-After de reenvío. Aceptación cubre StrictMode, sesión normal/restringida, enlace no disponible, 419/422/429/500/red, limpieza de claves y login posterior.
- `pnpm build` y `git diff --check` correctos; sin script de lint configurado.
- Chrome headless con respuestas simuladas: consulta y denegación de roles, alta con envío fallido, reenvío simulado sobre la misma cuenta, detalle mínimo de Paciente y aceptación sin login automático. Token retirado de URL/DOM y ausente tras recarga; sin excepciones JavaScript.
- Listado, alta y detalle sin overflow global a 1440/768/390/320 px; tabla conserva desplazamiento local. Aceptación revisada a 1440/390/320 px. Capturas desktop/móvil revisadas.
- Axe-core 4.10.3: cero infracciones automáticas WCAG 2 A/AA y 2.1 AA en las cuatro pantallas a 1440 y 390 px. No equivale a una auditoría humana completa.
- PostgreSQL sigue sin escucha local en el puerto esperado 5432; integración autenticada real, AUT-21 y entrega Brevo siguen pendientes. No se crearon cuentas reales ni se enviaron correos en las verificaciones.

Análisis incremental CU-03 y contexto §17.30 actualizados. **Siguiente fase 5:** estado de acceso, roles y credencial temporal/cambio obligatorio. CU-03 y el módulo frontend aún no se declaran completos. Diagrama global aplazado expresamente y persistencia/DER sin cambios.

## Fase 5 — acceso, roles y credencial temporal (2026-10-20)

`AccountDetailPage` incorpora `AccountActions` con confirmaciones de desactivación/reactivación, asignación de roles del personal y emisión presencial de credencial temporal. Consume PATCH `/api/usuarios/{id}/estado`, PATCH `/api/usuarios/{id}/rol` y POST `/api/usuarios/{id}/credencial-temporal`, con CSRF, `sessionBound: true` y campos explícitos. Laravel conserva las decisiones sobre último Administrador, identidad profesional, obligaciones médicas y elegibilidad.

- Paciente solo admite desactivar/reactivar cuentas previamente verificadas; no tiene cambio de rol ni credencial temporal. Reactivar no activa la ficha ni elimina bloqueos.
- El cambio a Médico conserva el vínculo histórico o pide ID profesional como texto y comprobación presencial para un vínculo inicial. Retirar Médico comunica las restricciones asistenciales sin mostrar datos clínicos.
- `AuthContext.mutateAccount` descarta comprobaciones anteriores y reconsulta `/me` tras una escritura confirmada. Los cambios propios que revocan sesión retiran el área y exigen login.
- `CredentialReceiptHost` reemplaza el área enrutada por una entrega aislada de una sola consulta, incluso al emitir sobre la propia cuenta y revocarse la sesión. El secreto se mantiene solo en memoria local del componente, fuera de AuthContext, URL, Router state y almacenamiento web; se retira al ocultar, cambiar de ruta o abandonar la página. Se comunica vencimiento con zona UTC explícita y entrega presencial.
- `/clave-obligatoria` permite PUT `/api/auth/password/temporal` con nueva contraseña y confirmación, sin ID ni contraseña anterior. Tras 204 limpia la sesión restringida y exige nuevo login. El cierre sin completar advierte que invalida la temporal. No hay navegación normal con acceso restringido.
- Errores seguros por código/campo, limpieza de contraseñas, foco, duplicación bloqueada y Retry-After; ningún fallo de red produce éxito ni reenvío automático.

**Verificación:** `pnpm test`: **167 pruebas correctas en 11 archivos**, 36 nuevas de esta fase. `pnpm build` correcto. Chrome headless con respuestas simuladas verifica estado de Paciente, cambio a Médico, emisión para otra cuenta y propia, ocultación del secreto y cambio obligatorio con login posterior. Cinco vistas revisadas a 1440/768/390/320 px sin overflow global; axe-core 4.10.3 sin infracciones automáticas WCAG 2 A/AA y 2.1 AA a 1440/390 px, sin excepciones JavaScript. Capturas desktop/móvil revisadas; estas comprobaciones no equivalen a una auditoría humana completa.

Contexto §17.31 y análisis incremental CU-03/CU-05 sincronizados. Integración real autenticada de fases 3–5, AUT-21 y entrega Brevo pendientes: PostgreSQL sigue sin escucha local en 5432. No se utilizaron cuentas reales ni correos externos. El módulo frontend aún no se declara cerrado; **siguiente fase 6: registro/vinculación del Paciente y emisión autorizada de Recepción**. Diagrama global aplazado expresamente; persistencia y DER sin cambios.

## Fase 6 — CU-04 y emisión parcial de CU-07 (2026-10-20)

| Ruta UI | Contrato y alcance |
| --- | --- |
| `/registro` | Paciente nuevo: POST `/api/auth/registro`, CI obligatorio, datos personales/contacto y contraseña. No exige código de Recepción. |
| `/vincular-ficha` | Ficha existente: POST `/api/auth/registro/vincular`, exclusivamente CI, código presencial de 12 caracteres, correo y contraseña/confirmación. |
| `/verificar-registro` | Ambos escenarios: POST `/api/auth/registro/verificar` con `codigo` de seis dígitos como texto; reenvío mediante POST `/api/auth/registro/reenviar` sin selector de proceso/correo. |
| `/recepcion/activacion-web` | Solo Recepcionista normal: POST `/api/pacientes/{id}/codigo-activacion` con `identidad_verificada: true`. Confirmación de sustitución y entrega presencial única. |

`RegisterPatientPage`, `VerifyRegistrationPage` y `RegistrationSession` coordinan el recorrido público; `registration.service.js` limita campos y usa la instancia Axios/CSRF compartida. El navegador conserva el contexto HttpOnly del servidor sin leerlo, copiarlo a estado ni almacenarlo en Web Storage. Una sesión normal o restringida exige cierre previo; se informa la invalidación de temporal al salir del acceso restringido. Los enlaces de login/ayuda permiten elegir ambos recorridos y reabrir verificación en el mismo navegador tras una recarga, sin pedir ID/correo para recuperar un proceso.

Inicio/reenvío 202 muestran **solicitud recibida, sin confirmar envío ni existencia**. El frontend no infiere códigos vencidos/agotados o cuentas existentes a partir de respuestas genéricas. El rechazo `REGISTRO_VERIFICACION_INVALIDA` mantiene orientación segura hacia código reciente, reenvío y Recepción. Solo 204 de verificación muestra activación y exige login, sin autenticación automática. Se explican 24 h de proceso/autorización, diez minutos y cinco errores del correo, reenvío mínimo 60 s y cinco por hora; el servidor aplica los límites. El contador de espera solo representa `Retry-After` recibido y no simula límites ocultos ni acredita envío.

CI conserva ceros, caso y signos; la ayuda valida presencia y caracteres permitidos sin inventar patrón civil. Sexo es opcional y libre según API. Correo de contacto opcional permanece separado del acceso. Datos de perfil no se envían en vinculación. Contraseñas y código presencial se limpian tras envío; código de correo tras verificar/reenviar. No se guardan secretos en URL, Router state, logs o almacenamiento web. Respuestas de inicio tardías tras desmontaje no redirigen otra pantalla. Escrituras no se reintentan automáticamente.

`ActivationCodePage` y `activation.service.js` viven en `features/pacientes/`. Todavía no existe catálogo HTTP de pacientes: Recepción introduce el identificador interno textual de la ficha que ya comprobó presencialmente, nunca el CI como ID de ruta. Laravel verifica ficha, CI, vínculo y rol. La emisión no crea cuenta ni activa ficha; un reemplazo invalida la autorización y procesos anteriores. Se muestra el código solo en memoria local hasta ocultar/navegar/abandonar, con vencimiento UTC explícito. Un fallo de red no fabrica una entrega.

**Verificación:** 201 pruebas correctas en 13 archivos, 34 nuevas (24 de comportamiento y diez de contratos); build y `git diff --check` correctos. Incluyen anonimato previo/cierre de los cuatro roles y sesión restringida, ambos escenarios, CI obligatorio, campos explícitos, BIGINT textual, conservación de ceros, limpieza de secretos, respuesta tardía, duplicación, errores 419/422/429/500/red, reenvío genérico y permisos de Recepción. Ajustada una comprobación asíncrona anterior de limpieza del token de invitación para esperar su efecto de React.

Chrome headless con respuestas simuladas completa Nuevo y Vinculación → verificación → login posterior, reenvío y emisión/ocultación presencial. Cinco vistas comprobadas a 1440/768/390/320 px sin overflow global; capturas desktop/móvil revisadas. Axe-core 4.10.3 sin infracciones automáticas WCAG 2 A/AA y 2.1 AA a 1440/390 px; sin excepciones JavaScript. No equivale a auditoría humana completa.

Contexto §17.32 y análisis incremental CU-04/CU-07 sincronizados. Integración real de fases 3–6, AUT-21 y Brevo siguen pendientes: PostgreSQL sin escucha local en 5432. No se crearon cuentas reales ni enviaron correos externos. **Siguiente fase 7: revisión e integración final del módulo frontend**; CRUD administrativo restante de CU-07 es posterior. Diagrama global continúa aplazado expresamente; backend, persistencia y DER sin modificaciones.

## Fase 7 — revisión integrada y cierre condicionado (2026-10-20)

Revisados router/guards, sesión, servicios Axios, generación HTTP, enlaces secretos, errores y consultas cancelables. Se mantienen los 20 endpoints API del backend consolidado, cuatro roles fijos y separación Page/Component → Service → Axios → Laravel. No se detectó necesidad de cambiar código productivo en esta revisión.

`src/features/auth/tests/pages/AuthIntegration.test.jsx` añade **12 pruebas integradas del cliente**: se montan router, páginas, AuthProvider, servicios e interceptores reales y se sustituye únicamente el adaptador HTTP de Axios. Permite detectar incompatibilidades entre capas del frontend que las pruebas con servicios simulados no verifican. No comprueba cookies del navegador, CORS, PostgreSQL, reglas clínicas ni autenticación real de Laravel.

| CU / recorrido | Evidencia disponible | Pendiente real |
| --- | --- | --- |
| CU-01/CU-02 | Cuatro roles: login → destino → desmontaje/remontaje y `/me` → logout; 403 conserva sesión y 401 protegido retira el área mediante interceptor. | AUT-21: mismo recorrido con cookies cross-origin y Laravel. |
| CU-03 | Alta → detalle → reenvío sobre la misma cuenta con envío simulado; emisión sobre cuenta propia → `/me` 401 → entrega aislada → login. Pruebas específicas anteriores cubren estado, roles, último Administrador y restricciones de Paciente/Médico. | Escrituras y revocación reales; invitación/entrega de correo. |
| CU-04 | Nuevo y Vinculación → verificación 204 → login → CU-05 → logout, con campos reales de los servicios. | Persistencia de ficha/cuenta/vínculos, contexto de cookie y códigos reales de prueba. |
| CU-05 | Cambio ordinario → reconsulta de sesión; temporal → formulario restringido → 204 → nuevo login normal, sin elevar automáticamente la sesión. | Renovación/revocación multidispositivo en Laravel. |
| CU-06 | Solicitud genérica → enlace → reset 204 → nuevo login; también invitación aceptada sin login automático y retirada del secreto. | Entrega del enlace y consumo real de tokens/sesiones. |
| CU-07 parcial | Emisión de Recepción y entrega única verificadas en fase 6 con simulación. | Emisión real; CRUD administrativo restante fuera de este cierre. |

**Resultado actual:** `pnpm test` → **213 pruebas correctas en 14 archivos**; `pnpm build` y `git diff --check` correctos. La nueva suite complementa las 201 pruebas y evidencias de Chrome/axe/responsive de fases anteriores; no se presentan esas capturas históricas como una nueva ejecución en esta fase. Sin dependencias, cambios de backend, persistencia o reglas.

### Bloqueo comprobado y reanudación de AUT-21

Vite y Laravel escuchan en 5173 y 8000. `Get-Service` confirma **`postgresql-x64-18` detenido**, sin escucha en 5432. `Start-Service` falló porque esta sesión no puede abrir el servicio. No se modificó su configuración ni se ejecutaron migraciones/seeders.

Para habilitar la integración, iniciar el servicio desde Servicios de Windows o desde PowerShell con permisos de Administrador:

```powershell
Start-Service -Name 'postgresql-x64-18'
Get-NetTCPConnection -State Listen -LocalPort 5432
```

Con PostgreSQL disponible, reanudar en `http://localhost:5173` con Laravel en `http://localhost:8000`, manteniendo el mismo host:

1. Comprobar `/auth/me` anónimo, cookies CSRF/HttpOnly y CORS con credenciales; una escritura sin CSRF debe rechazarse, sin reintento automático.
2. Para cada rol de prueba: login → destino autorizado → recarga y `/me` → intento de ruta ajena → logout confirmado → ruta protegida denegada.
3. Comprobar sesión expirada/revocada al volver a la pestaña y diferencia entre 401, 403 y 419; errores de validación, conflicto y Retry-After deben conservar la semántica documentada.
4. Recorrer CU-03–CU-06 y emisión parcial de CU-07 con identidades ficticias: invitación, acceso/roles, temporal/cambio obligatorio, registro nuevo/vinculación, cambio ordinario y recuperación. Contrastar persistencia y revocación con las respuestas de la API.
5. Registrar por separado la aceptación de Brevo y la llegada efectiva a buzón; una respuesta simulada o aceptación del proveedor no acredita entrega.

Este era el bloqueo de §17.33; quedó resuelto en la ejecución siguiente. Diagrama global sigue aplazado expresamente y DER sin cambios.

### AUT-21 — integración local real completada (2026-10-20)

Tras iniciar PostgreSQL, Chrome headless/CDP ejecutó React en `http://localhost:5174` contra Laravel en `http://localhost:8001`. Se usaron base/rol `clinica_vida_salud_test` y un esquema aleatorio exclusivo, con migraciones existentes y cuentas ficticias. Los puertos, origen CORS, Sanctum y cookie propios de prueba se configuraron en un auxiliar temporal, sin editar configuración productiva. Solo se sustituyó CorreoSeguridadService para capturar mensajes localmente; autenticación, middleware, CSRF, API y persistencia fueron reales.

| Recorrido | Resultado comprobado |
| --- | --- |
| CU-01/CU-02 y AUT-21 | Login, `/me`, recarga, área propia, ruta ajena denegada sin logout y cierre confirmado para cuatro roles. Expiración del Paciente al volver al foco retira el área privada. Cookie de sesión HttpOnly/SameSite=Lax, CSRF legible y CORS con credenciales/origen exacto. |
| CU-03 | Alta de Recepcionista, detalle, aceptación de invitación, cambio a Administrador, desactivación/reactivación y nuevo login con rol persistido. Emisión y entrega única de temporal desde la interfaz. |
| CU-04/CU-07 parcial | Emisión presencial y ambos recorridos de registro/verificación con códigos reales del backend capturados localmente. Activación sin login automático. Ficha histórica mantiene ID/CI, estado inactivo y vínculo único. |
| CU-05 | Cambio ordinario persiste el hash y conserva la sesión actual; temporal dirige al formulario restringido sin menú y su finalización exige nuevo login. |
| CU-06 | Solicitud genérica, enlace real capturado, token retirado de URL y restablecimiento confirmado sin login automático. |

Se observaron respuestas reales 200/201/202/204/401/403/409/419/422/429; 419 se provocó omitiendo CSRF. Parte de los rechazos se comprobó mediante fetch desde el navegador, por lo que no se atribuye a esta ejecución toda la cobertura visual de errores de las pruebas del cliente. Un 429 durante el recorrido administrativo acelerado se mostró correctamente; el login final se completó tras la espera, sin desactivar límites. Sin excepciones JavaScript ni uso de Web Storage en el recorrido principal. La bitácora no contenía la contraseña inicial ni los códigos/tokens capturados comprobados.

**Verificación adicional:** `php artisan test --compact --no-ansi tests/Feature/UsuariosAutenticacionIntegracionTest.php` → **3 pruebas, 190 aserciones correctas**. Complementa el navegador con renovación/revocación de varias sesiones e integridad de vínculos; correo simulado. Las 213 pruebas frontend y build correctos de la revisión previa conservan su evidencia, sin presentarse como repetidos en esta ejecución documental.

**Limpieza/evidencia:** servidores temporales detenidos, esquema exclusivo y archivos de secretos retirados. Resúmenes sin secretos: `aut21-evidence.json`, `aut21-extra-evidence.json`, `aut21-routes-evidence.json` y `aut21-persistence-evidence.json` en la carpeta temporal local de OpenCode; no constituyen un nuevo runner versionado. No fue necesario corregir código productivo.

**Estado:** AUT-21 local aprobada; CU-01–CU-06 integrados localmente y emisión parcial de CU-07 verificada. Cierre externo pendiente de aceptación de Brevo y llegada efectiva a buzón; HTTPS/configuración de producción requieren su propia comprobación. CRUD restante de CU-07 conserva su alcance posterior. Contexto §17.34 y análisis incremental sincronizados; diagrama global aplazado por decisión expresa, sin cambios de esquema/DER.
