import { api, csrfURL, obtenerEpocaSesion } from '../../../api/api';
import { ROLES } from '../../../constants/roles';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { esFechaCivil } from '../../agenda/utils/agendaFormat';
import { esUuid, ESTADOS_INTENTO, montoEntrada, urlProveedor } from '../utils/pagoFormat';
import { esperaPago } from '../utils/pagoError';

const exigir = condition => { if (!condition) throw new Error('No se pudo verificar la respuesta financiera.'); };
const texto = value => typeof value === 'string' && value.length > 0;
const instante = value => texto(value) && /Z$/.test(value) && Number.isFinite(Date.parse(value));
const uuid = value => { exigir(esUuid(value)); return value; };
export function precioPago(p) {
  exigir(p && ['monto_bob', 'monto_usd', 'tasa_bob_por_usd'].every(k => typeof p[k] === 'string' && /^(0|[1-9]\d*)\.\d{2}$/.test(p[k]))
    && p.moneda_original === 'BOB' && p.moneda_cobro === 'USD' && Number.isSafeInteger(p.centavos_bob) && Number.isSafeInteger(p.centavos_usd));
  const centavos = s => { const [a, b] = s.split('.'); return BigInt(a) * 100n + BigInt(b); };
  exigir(centavos(p.monto_bob) === BigInt(p.centavos_bob) && centavos(p.monto_usd) === BigInt(p.centavos_usd)
    && p.centavos_bob >= 700 && p.centavos_bob <= 700000 && p.centavos_usd >= 100 && p.centavos_usd <= 100000);
  return Object.fromEntries(['monto_bob', 'monto_usd', 'tasa_bob_por_usd', 'centavos_bob', 'centavos_usd', 'moneda_original', 'moneda_cobro'].map(k => [k, p[k]]));
}
export function resumenFinanciero(v) {
  if (v?.situacion_precio === undefined) return {};
  exigir(typeof v.incorporada_finanzas === 'boolean' && ['NoIncorporada', 'PendienteAsignacion', 'Asignado'].includes(v.situacion_precio)
    && typeof v.puede_asignar_monto === 'boolean' && (v.obligacion_id === null || esUuid(v.obligacion_id)));
  exigir(v.situacion_precio === 'Asignado' ? v.incorporada_finanzas && esUuid(v.obligacion_id) && v.precio : v.obligacion_id === null && v.precio === null);
  return { incorporada_finanzas: v.incorporada_finanzas, situacion_precio: v.situacion_precio,
    puede_asignar_monto: v.puede_asignar_monto, obligacion_id: v.obligacion_id, precio: v.precio ? precioPago(v.precio) : null };
}
function devolucion(v) {
  exigir(v && esUuid(v.id) && ['Pendiente', 'Procesando', 'Exitosa', 'Fallida', 'Incierta'].includes(v.estado) && Number.isSafeInteger(v.centavos_usd) && v.moneda === 'USD');
  return { id: v.id, estado: v.estado, centavos_usd: v.centavos_usd, moneda: v.moneda };
}
function intento(v) {
  exigir(v && esUuid(v.id) && esUuid(v.obligacion_id) && ['stripe', 'paypal'].includes(v.proveedor) && ESTADOS_INTENTO.includes(v.estado)
    && instante(v.creado_at) && instante(v.actualizado_at) && Array.isArray(v.cobros) && Number.isSafeInteger(v.centavos_usd) && v.moneda === 'USD');
  return { id: v.id, obligacion_id: v.obligacion_id, proveedor: v.proveedor, estado: v.estado, creado_at: v.creado_at,
    actualizado_at: v.actualizado_at, centavos_usd: v.centavos_usd, cobros: v.cobros.map(c => {
      exigir(esUuid(c.id) && typeof c.aplicado === 'boolean' && Number.isSafeInteger(c.centavos_usd) && instante(c.confirmado_at)
        && (c.incidencia === null || texto(c.incidencia)) && texto(c.referencia));
      return { id: c.id, aplicado: c.aplicado, centavos_usd: c.centavos_usd, confirmado_at: c.confirmado_at,
        incidencia: c.incidencia, referencia: c.referencia, devolucion: c.devolucion ? devolucion(c.devolucion) : null };
    }) };
}
function obligacion(v, rol) {
  exigir(v && esUuid(v.id) && esIdentificador(v.cita?.id_cita) && texto(v.cita.codigo_cita) && instante(v.cita.inicio_at)
    && ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'].includes(v.cita.estado) && instante(v.emitida_at) && instante(v.vencimiento_at)
    && ['exigible', 'liquidada', 'vencida', 'operacion_en_verificacion', 'puede_iniciar_pago'].every(k => typeof v[k] === 'boolean'));
  const proveedores = rol === ROLES.PACIENTE && Array.isArray(v.proveedores_disponibles) ? v.proveedores_disponibles : [];
  exigir(proveedores.every(p => ['stripe', 'paypal'].includes(p)) && (v.revision_pago === null || texto(v.revision_pago)));
  if (v.intento_actual !== null) exigir(esUuid(v.intento_actual?.id) && ['stripe', 'paypal'].includes(v.intento_actual.proveedor) && ESTADOS_INTENTO.includes(v.intento_actual.estado));
  return { id: v.id, cita: { id_cita: v.cita.id_cita, codigo_cita: v.cita.codigo_cita, inicio_at: v.cita.inicio_at, estado: v.cita.estado },
    precio: precioPago(v.precio), emitida_at: v.emitida_at, vencimiento_at: v.vencimiento_at, exigible: v.exigible, liquidada: v.liquidada,
    vencida: v.vencida, operacion_en_verificacion: v.operacion_en_verificacion,
    puede_iniciar_pago: rol === ROLES.PACIENTE && v.puede_iniciar_pago && proveedores.length > 0 && texto(v.revision_pago),
    proveedores_disponibles: proveedores, revision_pago: rol === ROLES.PACIENTE ? v.revision_pago : null,
    intento_actual: v.intento_actual ? { id: v.intento_actual.id, proveedor: v.intento_actual.proveedor, estado: v.intento_actual.estado } : null };
}
function pagina(v, proyectar) {
  const m = v?.meta;
  exigir(Array.isArray(v?.data) && m && Number.isInteger(m.current_page) && m.current_page > 0 && Number.isInteger(m.last_page) && m.last_page > 0
    && Number.isInteger(m.total) && m.total >= 0 && Number.isInteger(m.per_page) && m.per_page >= 1 && m.per_page <= 100);
  return { data: v.data.map(proyectar), meta: { current_page: m.current_page, last_page: m.last_page, total: m.total, per_page: m.per_page,
    from: m.from, to: m.to, calculado_at: m.calculado_at } };
}
function ambito({ rol, id_paciente }) {
  exigir([ROLES.ADMINISTRADOR, ROLES.PACIENTE].includes(rol));
  if (rol === ROLES.PACIENTE) { exigir(id_paciente === undefined); return {}; }
  exigir(esIdentificador(id_paciente)); return { id_paciente };
}
export async function consultarPagos({ tipo = 'obligaciones', id, rol, id_paciente, ...filtros }, signal) {
  exigir(['obligaciones', 'historial', 'detalle'].includes(tipo));
  const params = ambito({ rol, id_paciente });
  const permitidos = tipo === 'detalle' ? ['intentos_page', 'devoluciones_page'] : ['fecha_desde', 'fecha_hasta', 'page', 'per_page',
    ...(tipo === 'historial' ? ['proveedor', 'resultado'] : ['exigible', 'liquidada', 'vencida', 'operacion_en_verificacion'])];
  for (const k of permitidos) if (filtros[k] !== undefined && filtros[k] !== '') params[k] = filtros[k];
  for (const k of ['fecha_desde', 'fecha_hasta']) if (params[k]) exigir(esFechaCivil(params[k]));
  if (params.fecha_desde && params.fecha_hasta) exigir(params.fecha_desde <= params.fecha_hasta);
  const path = tipo === 'detalle' ? `/pagos/obligaciones/${uuid(id)}` : `/pagos/${tipo}`;
  const v = (await api.get(path, { params, signal, sessionBound: true })).data;
  if (tipo !== 'detalle') return pagina(v, tipo === 'historial' ? intento : x => obligacion(x, rol));
  const o = obligacion(v?.data, rol); exigir(o.id === id);
  return { ...o, intentos: pagina(v.data.intentos, intento), devoluciones: pagina(v.data.devoluciones, devolucion), calculado_at: v.meta?.calculado_at };
}
async function post(path, payload, headers = {}) {
  const epoch = obtenerEpocaSesion(), options = { sessionBound: true, sessionEpochExpected: epoch };
  try { await api.get(csrfURL, options); } catch (error) { error.pagoEnviado = false; throw error; }
  try { return await api.post(path, payload, { ...options, headers }); }
  catch (error) { error.pagoEnviado = true; throw error; }
}
export async function operarPago({ tipo, id, clave, proveedor, revision_pago }) {
  exigir(['iniciar', 'resultado', 'captura', 'cierre'].includes(tipo) && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(clave));
  const path = tipo === 'iniciar' ? `/pagos/obligaciones/${uuid(id)}/intentos` : `/pagos/intentos/${uuid(id)}/${tipo === 'captura' ? 'paypal/captura' : tipo}`;
  if (tipo === 'iniciar') exigir(['stripe', 'paypal'].includes(proveedor) && texto(revision_pago));
  const response = await post(path, tipo === 'iniciar' ? { proveedor, revision_pago } : {}, { 'Idempotency-Key': clave });
  try {
    const d = response.data?.data;
    exigir([200, 201, 202].includes(response.status) && d && esUuid(d.id) && esUuid(d.obligacion_id)
      && (tipo === 'iniciar' ? d.obligacion_id === id && d.proveedor === proveedor : d.id === id)
      && ['stripe', 'paypal'].includes(d.proveedor) && ESTADOS_INTENTO.includes(d.estado) && typeof d.aplicado === 'boolean'
      && typeof d.operacion_en_verificacion === 'boolean' && typeof d.puede_reintentar === 'boolean'
      && d.obligacion?.id === d.obligacion_id && typeof d.obligacion.exigible === 'boolean' && typeof d.obligacion.liquidada === 'boolean');
    const url = response.status !== 202 ? urlProveedor(d.accion, d.proveedor) : null;
    exigir(!d.accion || (response.status !== 202 && url !== null));
    return { status: response.status, retryAfter: esperaPago(response.headers), data: { id: d.id, obligacion_id: d.obligacion_id,
      proveedor: d.proveedor, estado: d.estado, aplicado: d.aplicado, accion: url ? { tipo: 'Redirigir', url } : null,
      obligacion: { id: d.obligacion_id, exigible: d.obligacion.exigible, liquidada: d.obligacion.liquidada },
      devolucion: d.devolucion ? devolucion(d.devolucion) : null, operacion_en_verificacion: d.operacion_en_verificacion,
      puede_reintentar: d.puede_reintentar, ultima_verificacion_at: d.ultima_verificacion_at } };
  } catch (error) { error.pagoEnviado = true; throw error; }
}
export async function proponerMonto(id, monto) {
  exigir(esIdentificador(id));
  const response = await post(`/citas/${id}/monto/propuesta`, { monto_bob: montoEntrada(monto) });
  const v = response.data?.data;
  exigir(response.status === 200 && texto(v?.propuesta) && v.propuesta.length <= 16384 && v.cita?.id_cita === id);
  return { propuesta: v.propuesta, precio: precioPago(v.precio), cita: v.cita };
}
export async function confirmarMonto(id, propuesta) {
  exigir(esIdentificador(id) && texto(propuesta));
  const response = await post(`/citas/${id}/monto`, { propuesta });
  try { exigir([200, 201].includes(response.status) && esUuid(response.data?.data?.id) && response.data.data.cita?.id_cita === id); }
  catch (error) { error.pagoEnviado = true; throw error; }
  return response.data;
}
