# Pagos — Fase 3 backend (2026-11-06)

**Continuación autorizada Fase4:** [entrega Stripe test](pagos-fase4-stripe.md), SDK oficial y coordinación mínima de cancelación/devolución/cobro tardío implementados localmente sobre esta base. Incorp./Stripe false por defecto; las afirmaciones inferiores «sin proveedor/devolución operativa/CU-32» describen el cierre histórico de F3, no ausencia actual de soporte backend. No activación habitual, pruebas externas ni resto Fase6.

**Implementación local verificada, pendiente de revisión del usuario.** Incluye reserva/asignación/emisión/consultas CU-31/33/34 y coordinación local con CU-15/16 expresamente autorizada. No CU-32, proveedores, devoluciones operativas, React ni Fases4–8. No módulo financiero completo. Complementa F1/F2 sin alterar RF01–RF13/RN01–RN14.

## 1. Incorporación y corte controlado

- `backend/config/pagos.php`: `PAGOS_INCORPORACION_HABILITADA`, **false por defecto**. El código exige boolean true; nunca se habilita al terminar una prueba ni por instalar la migración. No se modificaron archivos privados de entorno.
- Cuando está apagada, propuestas nuevas conservan v1 y las reservas siguen excluidas. No requieren monto y lo rechazan como campo adicional. Las lecturas de citas sin esquema financiero instalado devuelven NoIncorporada sin consultar tablas inexistentes; activar emisión requiere esquema F2 disponible.
- Con true, propuestas v2 cifradas incluyen incorporación y precio histórico revisado para personal; Paciente sin precio. La marca se escribe exclusivamente en INSERT. Ninguna fecha implícita/backfill ni reclasificación.
- Una propuesta **no confirmada** cuya incorporación no coincide con el interruptor actual exige revisión nueva (`CITA_PROPUESTA_OBSOLETA`), también para reserva propia: no incorporar silenciosamente selecciones anteriores al corte. Precio administrativo permanece dentro del token; confirmar no admite monto libre.
- Antes de comprobar el corte se resuelve replay autorizado. Citas ya confirmadas conservan su incorporación/precio originales incluso después de cambiar el interruptor, reprogramar o cancelar. Resultado/replay nunca emiten deuda nueva.
- Se conserva el UUID de propuesta como clave de confirmación. Disponibilidad/solapamientos/identidad/motivo y reconciliación de reserva existentes se mantienen. No se autoriza activar el entorno habitual ni se ha migrado allí.

## 2. Endpoints implementados

Prefijo `/api`; sesiones/origen/CSRF/cuenta/rol existentes. No rutas Checkout/intentos/captura/cierre/webhooks. Consultas con no-store/private y DTO explícito.

| Método/ruta | Permiso y contrato efectivo |
| --- | --- |
| POST reservas/propuesta | Con incorporación habilitada, personal exige `monto_bob`; Paciente rechaza monto e id_paciente. Respuesta añade precio revisado/null. Apagada conserva reserva anterior. |
| POST citas | `propuesta,motivo_breve?`; Cita+marca+obligación administrativa+auditoría en transacción. Propia incorporada Programada sin obligación. 201/200 replay. |
| POST reservas/resultado | Solo propuesta; recuperación autorizada sin escritura financiera, con resumen vigente de la misma cita. |
| POST citas/{id}/monto/propuesta | Administrador/Recepcionista; solo monto_bob. Revisión cifrada con UUID/actor/rol/cita/monto y revisión de programación/timestamp/estado. JSON objeto; sin query/campos extra. |
| POST citas/{id}/monto | Mismos roles; solo propuesta. 201 obligación/200 replay. No PATCH de monto ni endpoint de edición. |
| GET pagos/obligaciones | Paciente propio; Administrador exige id_paciente. Filtros F1: exigible/liquidada/vencida/operacion_en_verificacion 0/1, fecha_desde/hasta, page/per_page. |
| GET pagos/obligaciones/{uuid} | Mismos permisos; Administrador exige id_paciente. Colecciones intentos/devoluciones paginadas a20, intentos_page/devoluciones_page. Ajena/inexistente/selección incompatible →404. |
| GET pagos/historial | Mismos permisos; proveedor/resultado/fecha_desde/hasta/page/per_page. Historial real de intentos locales, inicialmente vacío si no existen hechos. |
| GET mis-citas / pacientes/{id}/citas / citas/{id} | Resumen autorizado: incorporada_finanzas,situacion_precio,puede_asignar_monto,obligacion_id,precio. Filtro situacion_precio=no_incorporada/pendiente_asignacion/asignado en listados existentes. Médico conserva proyección sin finanzas; agenda global no se amplía. |

**Dinero:** string estricto, Bs7–7000 inclusive/máximo dos decimales, validado con ImportePagoRule/ImportePago. `monto_bob` se excluye del trim global para no aceptar espacios indebidamente. Snapshot BOB/USD enteros y tasa7:1 HALF_UP única; `ImportePago.precio()` proyecta históricos sin reconversión/configuración.

## 3. Asignación, idempotencia y coordinación

- Solo cita incorporada/no Cancelada/sin obligación. Inicio pasado, Atendida/Inasistencia no impiden asignar. Estado asistencial inalterado. Emisión real, vencimiento ordinario al inicio: puede nacer vencida regularizable.
- `id_obligacion` toma el UUID del token emitido por servidor. Bajo lock de Cita, la obligación única se consulta con lock; replay coteja UUID/ejecutor/precio. Otra revisión ya asignada →PAGO_PRECIO_YA_ASIGNADO; revisión de cita cambiada →PAGO_REVISION_OBSOLETA. Token ligado a actor/rol/cita; manipulación →422 o403. No nueva tabla/migración para idempotencia.
- Reserva emite sobre la Cita nueva de su transacción. Asignación usa continuidad→cuenta→planificación→Cita→obligación, compatible con gestión. Índice UQ de F2 es última barrera. Obligación y PAGO_OBLIGACION_EMITIDA se confirman juntos; fallo financiero/auditoría revierte todo.
- Coordinación autorizada previa conservada: cancelación retira exigibilidad/enlaza CambioCita real; reprogramación ajusta vencimiento únicamente exigible/impagada; mismo precio/tasa/USD/pago. Si cancelar gana, asignación no emite; si asignación gana, cancelación anula exigibilidad. Rollback del primer escritor permite al segundo continuar.
- Auditoría guarda ejecutor y referencias locales, nunca token, cuerpo clínico o evidencia externa. La proyección HTTP previa de bitácora no se amplía automáticamente; referencias financieras nuevas quedan persistidas.
- Futuros aplicadores financieros deberán bloquear obligación antes de registrar aplicado. No se acredita coordinación con escritores SQL que eludan el protocolo. Ninguna llamada externa bajo lock ni fuera de él en esta fase.

## 4. Consultas y entrega parcial honesta

- Autorización por rol y recurso en Service; filtros SQL por vínculo/paciente antes de materializar datos. Recepción solo precio/asignación/resumen; Médico sin proyecciones nuevas; Administrador consulta pero no paga por el titular.
- Obligaciones sin precio o legado no se fabrican: se localizan en citas. Cancelada sin precio sigue PendienteAsignacion con permiso false. Precio/asignación no decide atención ni añade estado asistencial.
- Liquidada deriva de Cobro.aplicado. Vencida=exigible&&!liquidada&&ahora>=vencimiento_at; comparación UTC con microsegundos y calculado_at del servidor. No estado persistido ni bloqueo asistencial.
- Elegibilidad de toda obligación: **puede_iniciar_pago=false, revision_pago=null**. Intento actual mínimo explícito/null. Sin permisos/botones operativos CU-32.
- Fechas de filtros: días inclusivos La Paz convertidos a intervalo UTC semiabierto por emisión/creación del intento. Orden fecha/UUID descendentes, per_page20/máximo100/page máximo1000000. Detalle pagina intentos/devoluciones por separado. No cuerpos GET ni extras/sort libre.
- Recursos no serializan Models íntegros; intentos/cobros/devoluciones solo lista pública permitida. Sin cuenta_contexto, claves, evidencia/payload/URLs externas ni contenido clínico. No se insertan intentos/cobros/devoluciones para poblar lecturas.

## 5. Archivos y capas

- Nuevos `config/pagos.php`; `business/validators/ImportePagoRule`; `shared/utils/PropuestaMonto`.
- Services nuevos `business/services/pagos/PrecioCitaService`, `ConsultaPagosService`, `ResumenFinancieroCitaService`; se conserva CoordinacionFinancieraCitaService.
- Reutilizado/ampliado `data/repositories/pagos/ObligacionPagoRepository`; ajustes de ReservaService, AgendaRepository, HistorialCitasService, ConsultaCitaService y BitacoraRepository.
- Rutas `presentation/routes/pagos.php` registradas en routes/api.php. Controllers `presentation/controllers/pagos/{MontoCitaController,ConsultaPagosController}`.
- DTOs `presentation/dtos/pagos/{MontoCitaRequest,ConsultaPagosRequest,ObligacionPagoResource,IntentoPagoResource,DevolucionPagoResource}`. Ajustes ReservaRequest, HistorialCitasRequest, CitaListadoResource y mapeo RespuestaError; bootstrap solo exceptúa monto_bob del trim.
- Ajustes mínimos de PropuestaReserva e ImportePago. Persistencia/Models/restricciones F2 y DER intactos; sin dependencias nuevas ni autenticación modificada.

## 6. Verificación ejecutada y límites

- PostgreSQL local18.6/testing, base/rol/propietario clinica_vida_salud_test sin superusuario/CREATEDB/CREATEROLE, sin DB_URL/config cacheada verificados antes de operaciones. public0 tablas pre/post; funciones financieras en public0 al final; incorporación false.
- Integración/persistencia usan migraciones bajo transacción y rollback. Las seis carreras usan commits reales en esquemas aleatorios propios y limpieza limitada a cada esquema, según infraestructura existente. No se crearon bases/roles ni se migró la base habitual/remota.
- Inspección final detectó **un esquema antiguo ajeno** prueba_auth_sx1lpauqw780c1tw con12 tablas y antigüedad de unos6,6 millones de transacciones; se conservó sin leer contenido ni eliminarlo. No se inventa inventario global previo: el control inicial había verificado public, no todos los namespaces.
- Nueva PagosFaseTresTest; seis casos financieros adicionales en GestionCitaConcurrenteTest. Ajustadas firmas de fixtures de pausa y activación solo del worker de pruebas, expectativas de AgendaPermisosAdministrativosTest y whitelist contractual de ConsultaCitaTest.
- Batería final dirigida **247 pruebas/2799 aserciones correctas**,63,606s/74MB, PHP8.5.11/PHPUnit12.5.40, código0. Incluye ImportePagoTest, PagosPersistenciaTest, ReservaIntegracionTest, GestionCitaIntegracionTest, PagosFaseTresTest, HistorialCitasTest, ConsultaCitaTest, AgendaPermisosAdministrativosTest y solo seis casos `test_financial_assignment_serializes` de GestionCitaConcurrenteTest. No suite global rutinaria.
- Primera ejecución dirigida:247/2623 con seis fallos de expectativas antiguas de campos en detalle (no fallos de casos nuevos); se actualizaron por contrato y se ejecutó la final arriba. Intento anterior de shell con quoting inválido no acredita pruebas.
- Roles/ajenos, montos/tipos/límites/espacios, replay/v1/corte, reserva propia, emisión atómica/rollback, asignación única/tardía/cancelada/legado, filtros/paginación/vencimiento preciso, privacidad y seis carreras con confirmación/rollback verificadas. Fixtures de cobros solo en pruebas de lecturas/coordinación; nunca pagos operativos ficticios.
- Pint --dirty --test final correcto; sintaxis47 PHP locales modificados/nuevos correcta; composer dump-autoload --no-scripts y registro estático de las cinco rutas financieras correctos. Logs externos en Temp/opencode/pagos-fase3-{pruebas,pruebas-final,pint-final,sintaxis,autoload,rutas-consulta,rutas-monto}.log.
- No prueba de navegador/React, cuentas/SDK/firma/proveedores, cobros/devoluciones/conciliación/worker, despliegue ni activación habitual. No render gráfico nuevo. Fases4–8 permanecen sin iniciar; esperar revisión/autorización expresa.
