# AGENTS.md — Clínica Vida Salud

Instrucciones obligatorias para todo el repositorio. **Versión compacta (2026-10-31).** No cambia ninguna regla de negocio: conserva aquí lo que evita errores graves y deriva el detalle a `docs/ai/`. Si este archivo y `docs/ai/` difieren, prevalece la decisión aprobada más reciente y se reporta el conflicto.

## 1. Alcance

- Sistema web de gestión de citas médicas para una clínica privada ficticia; **proyecto universitario de Ingeniería de Requisitos**.
- Una sede, atención **exclusivamente presencial**, consultorios identificados.
- Cuatro roles fijos: **Administrador, Recepcionista, Médico, Paciente**. Nunca un quinto rol ni permisos dinámicos.
- Catálogo continuo `CU-01`–`CU-34`: treinta originales intactos y ampliación aprobada Pagos CU-31–CU-34. No renumerar ni reutilizar identificadores.
- Requisitos oficiales `RF01`–`RF13` y reglas `RN01`–`RN14`: cumplimiento íntegro, sin editar ni renumerar.
- Fuera de alcance: pagos reales/producción, farmacia, inventario, recetas electrónicas, telemedicina, varias sedes, recordatorios por correo/SMS/WhatsApp. Excepciones aprobadas: **Web Push opcional** CU-28 (2026-10-30), sin app nativa ni garantía de entrega; **Pagos académicos** CU-31–CU-34 (2026-11-05), Stripe Checkout test y PayPal Sandbox independientes, sin dinero real. Ver `docs/ai/pasarela-pagos.md`; Fase 0 solo documental, fases siguientes requieren autorización.
- Distinguir siempre **exigencia del examen**, **ampliación aprobada** y **recomendación técnica**. Priorizar lo obligatorio sobre ampliaciones.

| Módulo | Casos |
| --- | --- |
| Usuarios y autenticación | CU-01–CU-06 |
| Pacientes y perfil | CU-07, CU-08 |
| Médicos y especialidades | CU-09, CU-10 |
| Consultorios y horarios | CU-11, CU-12 |
| Búsqueda y reserva | CU-13, CU-14 |
| Reprogramación y cancelación | CU-15, CU-16 |
| Asistencia y estados | CU-17–CU-19 |
| Agenda e historial | CU-20, CU-21 |
| Historia clínica y consultas | CU-22, CU-23 |
| Laboratorio digital | CU-24–CU-27 |
| Recordatorios internos | CU-28 |
| Reportes | CU-29 |
| Bitácora | CU-30 |
| Pagos académicos — aprobado, no implementado | CU-31–CU-34 |

## 2. Stack

React + Vite + JavaScript + React Router + Axios + Context API (`useAuth`) · PHP + Laravel + Eloquent · PostgreSQL (PDO) · Brevo solo para seguridad de cuentas · HTTP/JSON.

- React es el frontend funcional; no sustituirlo por Blade.
- Sesiones Laravel y, si la topología SPA lo permite, Sanctum stateful con CSRF. Nunca JWT propio ni tokens en `localStorage`/`sessionStorage`.
- Frontend con el gestor de su lockfile; backend con Composer y Artisan. Verificar versiones reales antes de depender de ellas.

## 3. Fuentes y jerarquía

1. Enunciado del examen. 2. Decisiones aprobadas más recientes. 3. Documentación funcional (alcance, modelo, RF/RN, matriz, CU). 4. `docs/ai/`. 5. Código, migraciones y pruebas. 6. Convenciones técnicas generales.

- Si la petición contradice el examen: señalarlo y pedir aclaración; no implementar el conflicto en silencio.
- `/AGENTS.md` aplica a todo; `frontend/AGENTS.md` y `backend/AGENTS.md` complementan y **no pueden contradecirlo**.
- `docs/ai/`: `contexto-maestro.md` (decisiones, arquitectura, progreso), `requerimientos.md` (RF/RN oficiales, no editar), `reglas-negocio.md` (restricciones verificables), `casos-de-us.md` (CU-01–CU-34 y matriz de permisos §3.1), `pasarela-pagos.md` (ampliación académica aprobada), `diagrama-entidad-relacion.md`, `diagrama-clases.md`, `analisis-clases.md`, `estado-fase.md` (relevo).
- Si un documento falta: no inventar su contenido ni afirmar que está actualizado.
- Referencias antiguas a Blade como frontend o a nombre de usuario para login están superadas: React + correo/contraseña.

## 4. Economía de contexto (obligatoria)

- **Prohibido leer completos** `analisis-clases.md`, `contexto-maestro.md`, `diagrama-clases.md`, `README.md` o los `AGENTS.md` extendidos: cada uno ronda 100k–320k tokens. Usar `grep` y `read` con `offset`/`limit` (≤150 líneas).
- Leer solo lo necesario para la tarea; no releer archivos ya vistos ni repetir salidas conocidas.
- Pruebas: una sola ejecución dirigida al final, salida a archivo y en el chat solo el resumen. Sin suites globales rutinarias ni volcados de inventarios/reflexión completos.
- Escribir cada archivo una vez; cambios puntuales con `edit` y anclas cortas. No reescribir documentos completos.
- Búsquedas amplias ("dónde está X") se delegan al subagente `explore`.
- Al cerrar una fase: actualizar `docs/ai/estado-fase.md` (una página) y recomendar sesión nueva.

## 5. Arquitectura

`React → Service/Axios → Route → Middleware → Controller → Service → Repository → Eloquent → PostgreSQL`

El backend es la única fuente de verdad de reglas, autorización, estado y persistencia; React nunca accede a PostgreSQL. Detalle técnico en `backend/AGENTS.md`; pautas de interfaz en `frontend/AGENTS.md`.

## 6. Estructura

```text
/AGENTS.md · /frontend · /backend · /docs/ai
frontend/src: api/ app/ components/ layouts/ features/ hooks/ routes/ constants/ utils/ styles/
backend/src:  presentation/ business/ data/ shared/   (minúsculas, nombres CADEFAR)
```

- Backend: archivos agrupados por módulo dentro de `controllers/`, `dtos/`, `services/` y `repositories/`.
- `app/`, `bootstrap/`, `config/`, `routes/`, `database/`, `public/`, `resources/`, `storage/` de Laravel son infraestructura mínima, sin lógica duplicada.
- Comprobar la estructura real antes de crear, mover o renombrar carpetas; no crear módulos vacíos.

## 7. Reglas de negocio irrenunciables

Detalle completo y vigencias en `docs/ai/reglas-negocio.md` y `docs/ai/casos-de-us.md`.

**Acceso y credenciales**

- Login solo con correo y contraseña. Mínimo 12 caracteres, hash con `Hash`. Tres fallos consecutivos → bloqueo de 10 minutos; los intentos durante el bloqueo no lo extienden; el éxito o la expiración reinician el contador.
- Estados distintos: cuenta pendiente de verificación, activa, desactivada y bloqueo temporal. Desactivar invalida sesiones; recuperar contraseña no reactiva cuentas.
- Solo el Administrador crea cuentas de personal (invitación de 24 h, un uso). CU-06: enlace de 60 min, un uso, invalida el anterior y exige nuevo login. Restablecimiento administrativo (CU-03) solo para personal, nunca para Pacientes.
- Primer Administrador: solo instalación local idempotente, sin credenciales en el repositorio.
- Brevo **solo** envía correos de seguridad de cuentas. Nunca confirmar si un CI o correo existe.

**Pacientes y códigos**

- CU-04 exige CI. CU-07 permite ficha sin CI (nullable, con unicidad cuando se informa); al incorporar el CI después se actualiza **la misma ficha** con trazabilidad, sin crear otra ni vincular cuenta automáticamente.
- Máximo una cuenta web por Paciente; cuenta y ficha son entidades distintas. Una ficha puede existir sin cuenta.
- Código de Recepción: alfanumérico de 12, 24 h, 5 intentos, un uso; reemplazarlo invalida el anterior. Código de correo: 6 dígitos, 10 min, 5 intentos, un uso; emitir otro invalida el anterior y el reenvío espera 60 s con máximo 5 por hora. Registro pendiente: 24 h. Invitación de personal: nueva emisión invalida la anterior.
- Códigos y tokens se guardan con hash; nunca en claro, en logs ni en bitácora.

**Citas**

- Estados únicos: `Programada | Confirmada | Atendida | Cancelada | Inasistencia`. Sin otros estados ni reapertura de finales en flujos ordinarios.
- Duración inicial 15 min configurable; nunca fuera de horario ni en fecha pasada. Verificar en servidor médico activo, especialidad, horario, consultorio y **solapamiento por médico y por consultorio**, protegiendo concurrencia.
- El Paciente solo reserva para sí y no reprograma ni cancela. Recepción y Administrador programan, reprograman y cancelan. `Confirmada` = llegada presencial verificada. `Atendida` la marca el Médico responsable o el Administrador, con ejecutor real separado. Reprogramar `Confirmada` vuelve a `Programada`.
- Cancelar libera el intervalo pero no borra la cita. Preservar duración y ubicación históricas aunque cambie la configuración.
- Paciente inactivo no recibe nuevas citas; Médico inactivo no recibe nuevas citas ni reprogramaciones. Inactivar nunca borra historial.

**Información clínica y laboratorio**

- Máximo una consulta por cita. Guardar la consulta no cambia el estado de la cita.
- CU-22 es de consulta. CU-23 permite al Médico responsable o al Administrador registrar, completar o corregir antecedentes con versiones y ejecutor real. Recepción y Paciente no editan contenido clínico.
- Laboratorio: `Pendiente → En revisión → Disponible`. El Paciente ve `Pendiente` hasta la publicación y solo descarga su versión vigente publicada. Documentos en almacenamiento privado, con autorización también al entregar los bytes.
- La bitácora registra metadatos y referencias, nunca diagnósticos ni contenido clínico.

**Recordatorios (CU-28)**

- Avisos internos persistentes, automáticos por scheduler cada minuto, 24 h antes, sin depender de sesión del Paciente; un aviso por cita y programación.
- Web Push es opcional, exige consentimiento explícito y solo se despacha antes de `inicio_at`. Nunca correo, SMS, WhatsApp ni Brevo.

**Roles y autorización**

- Administrador: 28/30 CU originales (todos salvo CU-04 y CU-08); en Pagos solo CU-34, consulta por Paciente. Recepcionista: registro administrativo, códigos, citas, llegadas e inasistencias. Ambos fijan monto individual por ampliación CU-14, sin pagar por el titular. Médico: su agenda, historia autorizada, consultas, antecedentes, laboratorio propio y marcar sus propias citas como Atendida, sin permisos financieros nuevos. Paciente: funciones propias previas y CU-31–CU-33; solo paga sus citas.
- Pagos aprobados, no implementados: Bs 7,00–7.000,00 por cita, máximo dos decimales; 7,00 BOB/USD, enteros/HALF_UP; obligación única con precio histórico. Reserva propia sin precio hasta asignación del personal, incluso después del inicio si no cancelada. Vencimiento calculado al inicio, regularizable sin bloqueo asistencial/recargos; reprogramación conserva precio/pago. Cancelación retira exigibilidad y devuelve automáticamente el cobro aplicado al medio original en pruebas, incluido cobro tardío; inasistencia no devuelve. Doble cobro: registrar ambos/aplicar uno/incidencia, sin devolución adicional automática. Citas antiguas excluidas; no marcar pagos o devoluciones manualmente como exitosos.
- Separar siempre **usuario ejecutor**, **Paciente afectado** y **Médico responsable**. Nunca simular sesiones ni atribuir autoría.
- Autorización en servidor, distinguiendo permiso de rol y permiso sobre el recurso concreto.

## 8. Seguridad e integridad

- Validar en el servidor todo lo que envía React; no confiar en `idPaciente`, `rol`, `estado` ni identificadores del cliente.
- Manejo centralizado de errores con 400/401/403/404/409/419/422/429/500. Sin trazas, SQL, secretos ni datos clínicos en respuestas.
- Transacciones y restricciones PostgreSQL para reservas, activación de cuentas, consulta única y versionado documental: una comprobación previa no basta ante concurrencia.
- Nunca versionar `.env`, claves, tokens, respaldos ni datos clínicos reales. `APP_DEBUG=false` en producción; `VITE_*` es público.
- Datos de prueba ficticios: nunca pacientes reales en seeds, fixtures, logs ni capturas.

## 9. Git y operaciones peligrosas

- Sin `commit`, `push`, ramas, merge/rebase, despliegue ni cambios remotos sin solicitud explícita.
- Sin `migrate:fresh`, `db:wipe`, `schema:drop`, borrados masivos ni migraciones sobre bases habituales/remotas sin autorización.
- Conservar los cambios locales ajenos a la tarea; no borrar trabajo ajeno sin inspeccionar.

## 10. Cierre de tarea

1. `analisis-clases.md`: entrada del CU o grupo al terminarlo (con `edit`, sin reescribir el archivo).
2. Al cerrar módulo: integrar `diagrama-clases.md` acumulativo; actualizar el DER si cambió la persistencia.
3. Sincronizar `reglas-negocio.md`, `casos-de-us.md`, `requerimientos.md`, `contexto-maestro.md` y `README` solo si cambió una decisión o el estado.
4. Verificar permisos, errores, concurrencia, datos sensibles y pruebas dirigidas ejecutadas.
5. Escribir el relevo en `docs/ai/estado-fase.md`.
6. Informar: archivos cambiados, pruebas ejecutadas, docs sincronizados, pendientes y lo no verificado.

## 11. Comunicación

Responder en español. Resumir cambios, archivos afectados, pruebas y limitaciones; distinguir examen, decisión aprobada y recomendación. No presentar como hecho lo no verificado ni dar por terminada una tarea sin comprobaciones.
