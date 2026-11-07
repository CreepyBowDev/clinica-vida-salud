# CU-29 — Aceptación local de PDF, Fase 3 (2026-11-07)

## Resultado y alcance

**Fase 3 aceptada en el entorno local aislado.** Se ejecutó React → Axios/XHR con cookies y CSRF → Laravel HTTP → PostgreSQL → Blob → descarga real de Chrome → lectura/rasterización del archivo descargado con Windows.Data.Pdf. Los recorridos positivos no sustituyeron la API ni el renderer. Ampliación opcional autorizada, no nueva exigencia RF/RN. No se modificó código funcional: los defectos encontrados pertenecían al arnés externo.

Evidencias y scripts reproducibles: `C:/Users/jesus/AppData/Local/Temp/opencode/reportes-pdf-f3-20261107/`. No versionar perfiles, credenciales, PDF ni capturas generados. Los cierres F1/F2 conservan sus resultados originales, no pasan a ser ejecuciones nuevas ni pases únicos verdes.

## Aislamiento efectivo y operaciones

- Antes de escribir: configuración sin caché y consulta SQL de identidad verificadas; base **clinica_vida_salud_test**, rol **clinica_vida_salud_test**, servidor **::1**. `preflight-before.json`, `setup.log`/código0 y `ownership.json` prueban preparación/propiedad. Nunca se utilizó la base habitual ni una remota.
- El usuario autorizó expresamente la excepción a «sin migraciones»: crear **solo** `prueba_reportes_pdf_f3_20261107_211fd8444ae4` y ejecutar allí las migraciones existentes, sin crear/modificar archivos de migración. Se excluyeron000040–000042/Pagos/recibos. `btree_gist` ya existía en public; no se instaló. Fixtures exclusivamente ficticias, 1535 citas/1003 pacientes al cierre.
- `search_path` comprobado en cada arranque; sesiones database en el esquema propio, almacenamiento/logs/caché file y perfiles Chrome bajo la evidencia. Correo array y HTTP cliente externo prohibido; CDP bloquea orígenes ajenos en la página. Credenciales de prueba se usan solo para login normal, sin sesiones simuladas ni tokens en web storage.
- Laravel se sirvió en localhost:18083 y Vite en localhost:5173, sin editar `.env`. PHP8.5.11/384MiB, Node24.18.0/pnpm11.17.0; renderer instalado de F1. Caché del limitador fijada **antes** de bootstrap y FileStore efectivo comprobado para la revalidación429.
- `preflight-final.json`: misma identidad, esquema efectivo propio y namespaces ajenos idénticos. El esquema propio y sus fixtures se **conservan identificados**, no se limpia ningún esquema ajeno. Servidores propios detenidos por PID/línea de comando comprobados (`owned-servers.json`, `servers-stopped.txt`); su terminación deliberada hace que los envoltorios de servidor informen código1, no un fallo de aceptación. No dependencias, configuración privada, remotos, commit ni push.

## Matriz integrada y contenido de archivos descargados

| Reporte | Consulta/exportación positiva real | Contenido PDF contrastado |
| --- | --- | --- |
| Programadas | Fecha; página2 de26; borrador cambia a otra fecha sin aplicar; GET PDF omite page/per_page |26 filas completas F3-N-0000–0025 ascendentes; no solo las seis visibles. Otra descarga de500 citas/63 páginas, sin truncamiento. |
| Por Médico | Rango, Médico2, especialidad1, Programada, fecha_desc |26 filas; extremos0025–0000, filtros/totales coherentes. |
| Por especialidad | Fecha, especialidad1, Médico2, fecha_desc |30 filas; extremos0029–0000, incluye los estados actuales. |
| Por estado | Fecha, Cancelada, Médico2, especialidad1, fecha_asc |Una fila0028 y total1. |
| Pacientes registrados | Fecha/nombre_asc; otra consulta de rango/activo0/registro_desc |500 fichas completas/37 páginas; otra descarga250 inactivos, códigos0499–0001. No son altas de cuenta. |
| Cantidades | Fecha; otra consulta de rango/Médico2/especialidad1 |168 Atendidas +168 Canceladas +168 Inasistencia =504; agregado completo aunque supera500 citas. |
| Agenda diaria | Fecha, Médico2, Programada; orden fijo |26 filas ascendentes, ubicación/duración y referencia de intersección diaria conservadas. |

- **Siete vacíos válidos descargados realmente**, total0 y mensaje explícito; cantidades conserva cuatro ceros. Se verificaron500 inclusive en pacientes/citas y exceso501 en los **seis listados** mediante API real:422 JSON sin bytes PDF; en React se comprobó Blob→JSON, alerta y ausencia de descarga parcial.
- El aviso de nueva lectura está en UI/PDF. `lectura-posterior.pdf` contiene un nombre ficticio cambiado **después** de consultar, mientras la tabla anterior conserva el nombre previo: diferencia real demostrada, no snapshot prometido.
-21 archivos completos recibidos por Chrome, con GUID/eventos completed, tamaño y SHA256; **19 se usan para aceptación**. Se preservan dos artefactos de escenarios mal preparados, **no** como pases: `vacio-cantidades.pdf` exportó el rango previamente aplicado/504, y `tras-espera429.pdf` no había provocado429. Los sustituyen `vacio-cantidades-correcto.pdf` y `tras-espera429-confirmada.pdf`.
- Los21 archivos pasan `qpdf12.4.2 --check`/código0; Windows.Data.Pdf los carga y rasteriza. `pdf-validation.json`/`pdf-summary.json` consolidan sin sumar duplicados; se corrigió un contenedor PowerShell value/Count del manifiesto, no los PDFs. Extracción de texto acotada a Dompdf/UTF16BE con CMap identidad verificado: totales, filas/extremos/orden, filtros, cabeceras repetidas, numeración completa y proyección administrativa mínima. **No es un parser general ni se confunde con qpdf**.

## Sesión, errores e interfaz

- Tres roles no administradores × siete endpoints PDF: **21 respuestas403 JSON**, sin PDF; ninguna UI exportadora. Login y sesiones reales. Revocación efectiva de sesión de Administrador →401 en Axios; la respuesta retenida no descarga después de invalidarse el contexto.
- Carga, cancelar espera/foco restaurado y cambio de tipo con respuesta obsoleta se probaron reteniendo por CDP el **GET real**, no reemplazándolo. Exportación con Enter, revocación de URL y retiro de enlace verificados en Chrome. Las demás variantes de obsolescencia/identidad/pagehide y límites MIME/tamaño/nombres mantienen revisión de código y pruebas simuladas F2, no se atribuyen todas a un nuevo recorrido real.
-429 real: seis validaciones alcanzan el middleware; la descarga siguiente devuelve429/Retry-After, React deshabilita el botón/muestra la cuenta atrás y, tras esperar realmente, descarga correctamente. `integration-rate429-revalidation.*`/código0.
- PDF inválido y errores419/500: **inyección de fallos CDP explícita**, después del transporte real, sin falsificarlos como fallos espontáneos Laravel. Rechazo sin descargar, JSON recibido como Blob y alertas seguras comprobados.401/403/422/429 sí proceden del servidor real.
- En la topología de dos puertos, CORS no expone Content-Disposition: Chrome recibe el **nombre alternativo fijo seguro** previsto en §9. Todos los nombres sugeridos reales cumplen el patrón sin datos personales. No se cambió CORS ni el contrato para imponer el nombre con timestamp.
- Capturas UI1440/390px examinadas: controles, aviso, tablas/tarjetas, cantidades y mensajes legibles; sin desborde global en la comprobación móvil. Enter y foco al cancelar reales; aria-live/role alert en DOM, no certificación de lector de pantalla.
- Inspección visual de páginas seleccionadas de los siete tipos, incluidos pacientes1/19/37 y citas500 página32: acentos, nombres largos, columnas, cabeceras repetidas y pies legibles, sin recortes observados. No revisión exhaustiva de cada página, dispositivos físicos, otros navegadores/lectores, PDF/A, accesibilidad PDF o rendimiento productivo.

## Comandos, incidencias y resultados conservados

Una batería dirigida por grupos, con revalidación focal y completado de criterios aún no ejecutados; **no suites globales ni pase único verde**:

| Ejecución externa | Resultado real |
| --- | --- |
| `node integration.mjs` |14 grupos:2 correctos/12 fallidos. Selector con comillas inválidas y ruta Windows de descarga incorrecta del arnés. API de límites/permisos correcta, no repetida. |
| `F3_REVALIDATE=1 node integration.mjs` |12 grupos afectados:11 correctos/1 fallido. Ocho descargas completas. Se detectó intercepción de OPTIONS en vez de GET en escenarios retenidos/inválido. |
| `F3_ONLY=responses node integration.mjs` |Solo tres grupos afectados,3 correctos/código0; retención del GET y PDF inválido corregidos. |
| `F3_REMAINING=1 node integration.mjs` |Cinco grupos pendientes:4 correctos/1 fallido; cinco descargas. CDP exigía responsePhrase para419. El contraste de contenido detectó además que el supuesto vacío seguía exportando el rango aplicado. |
| `F3_FIX=1 node integration.mjs` |Solo error419/500 y vacío afectado:2 grupos correctos/código0; limpieza explícita de filtros del arnés, sin modificar producto. |
| `F3_EMPTY=1 node integration.mjs` |Cinco vacíos restantes descargados/código0. |
| `F3_RATE=1 node integration.mjs` |Falló la aserción de429: caché array resuelta durante bootstrap. El PDF sí se descargó, no certificaba espera. |
| `F3_RATE=1 F3_RATE_RETRY=1 node integration.mjs` |Único grupo afectado correcto/código0, FileStore persistente y429/espera/recuperación reales. |
| `node check-content.mjs` y filtros PDF_AFFECTED/PDF_EMPTY/PDF_RATE |Inicial103 comprobaciones:100 correctas/3 fallidas; dos por supuesto vacío mal preparado y una por nombre partido en líneas. Afectados revalidados16/16; vacíos restantes31/31; archivo tras429 confirmado9/9. No sumar comprobaciones solapadas. |
| `inspect-pdfs.ps1` |qpdf/WinRT sobre descargados; primer intento falló al llamar Dispose inexistente en PdfDocument. Corregido el arnés y continuada lectura;21 PDFs válidos únicos, no21 casos funcionales. |

Cada ejecución conserva JSON/log y, cuando corresponde, `*-exit.txt`; el script y filtros exactos quedan en la evidencia externa. No se repitieron Vitest/PHPUnit de F1/F2 ni se hizo build: **frontend/backend funcionales no cambiaron**. `git diff --check` al cierre y documentación sincronizada. No cambios de arquitectura/persistencia productiva: UML/DER/RF/RN/Pagos intactos. **Detenerse al cerrar Fase3; nueva sesión para cualquier otra tarea.**
