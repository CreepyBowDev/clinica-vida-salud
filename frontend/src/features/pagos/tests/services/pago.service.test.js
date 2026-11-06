import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL, avanzarSesion } from '../../../../api/api';
import { consultarPagos, operarPago, precioPago, proponerMonto, confirmarMonto } from '../../services/pago.service';
import { proponerReserva } from '../../../citas/services/reserva.service';
import { consultarCita, consultarHistorialPaciente } from '../../../agenda/services/agenda.service';
import { cita as citaAgenda, detalle as detalleAgenda, pagina as paginaAgenda } from '../../../agenda/tests/utils/agendaFixtures';
import { propuesta, seleccion } from '../../../citas/tests/utils/reservaFixtures';
import { O, I, KEY, precio, pagina, obligacion, detalle, operacion, intento } from '../utils/pagoFixtures';

const original = api.defaults.adapter;
let calls, task;
const ok = (config, data, status = 200, headers = {}) => ({ config, status, headers, data });
beforeEach(() => {
  calls = []; task = null;
  vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new Error('Red externa bloqueada'))));
  api.defaults.adapter = async config => {
    calls.push(config);
    if (config.url === csrfURL) return ok(config, '');
    if (task) return task(config);
    throw new Error('Petición no simulada bloqueada');
  };
});
afterEach(() => { api.defaults.adapter = original; vi.unstubAllGlobals(); });
it.each(['Paciente', 'Administrador'])('lista con ámbito %s y whitelist de filtros', async rol => {
  task = c => ok(c, pagina([obligacion()]));
  const r = await consultarPagos({ rol, ...(rol === 'Administrador' ? { id_paciente: '9' } : {}), tipo: 'obligaciones', vencida: '1', page: 1, per_page: 20, sort: 'secreto' });
  expect(calls[0].params).toEqual({ ...(rol === 'Administrador' ? { id_paciente: '9' } : {}), vencida: '1', page: 1, per_page: 20 });
  expect(r.data[0].proveedores_disponibles).toEqual(rol === 'Paciente' ? ['stripe', 'paypal'] : []);
  if (rol === 'Administrador') expect(r.data[0]).toMatchObject({ puede_iniciar_pago: false, revision_pago: null });
});
it.each([{ rol: 'Médico' }, { rol: 'Recepcionista' }, { rol: 'Administrador' }, { rol: 'Paciente', id_paciente: '9' }])('no envía ámbito incompatible %j', async p => {
  await expect(consultarPagos(p)).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it('detalla paginaciones separadas y descarta URL/secretos inesperados', async () => {
  task = c => ok(c, detalle({ secretos: 'no', intentos: pagina([intento({ url: 'https://evil.test' })]) }));
  const r = await consultarPagos({ rol: 'Paciente', tipo: 'detalle', id: O, intentos_page: 2, devoluciones_page: 3 });
  expect(calls[0].params).toEqual({ intentos_page: 2, devoluciones_page: 3 }); expect(r).not.toHaveProperty('secretos'); expect(r.intentos.data[0]).not.toHaveProperty('url');
});
it('históricos no se reconvierten usando una tasa nueva', () => {
  const historical = { ...precio, monto_usd: '10.01', centavos_usd: 1001 };
  expect(precioPago(historical)).toEqual(historical);
  expect(() => precioPago({ ...historical, centavos_usd: 1000 })).toThrow();
});
it.each([200, 201, 202])('inicio maneja %s con misma clave y sin URLs especulativas', async status => {
  task = c => ok(c, { data: operacion(status === 202 ? { accion: null, estado: 'Incierto', operacion_en_verificacion: true } : {}) }, status, { 'retry-after': '5' });
  const input = { tipo: 'iniciar', id: O, proveedor: 'stripe', revision_pago: 'revision', clave: KEY };
  const r = await operarPago(input); await operarPago(input);
  const posts = calls.filter(c => c.method === 'post');
  expect(posts).toHaveLength(2); expect(posts[0].headers['Idempotency-Key']).toBe(KEY); expect(posts[1].headers['Idempotency-Key']).toBe(KEY);
  expect(JSON.parse(posts[0].data)).toEqual({ proveedor: 'stripe', revision_pago: 'revision' }); expect(r.status).toBe(status); expect(r.retryAfter).toBe(5);
  if (status === 202) expect(r.data.accion).toBeNull();
});
it.each(['resultado', 'captura', 'cierre'])('%s es POST vacío con clave y sin parámetros externos', async tipo => {
  task = c => ok(c, { data: operacion({ proveedor: 'paypal', accion: null }) });
  await operarPago({ tipo, id: I, clave: KEY, token: 'no', session_id: 'no' });
  expect(calls[1].url).toBe(`/pagos/intentos/${I}/${tipo === 'captura' ? 'paypal/captura' : tipo}`);
  expect(JSON.parse(calls[1].data)).toEqual({}); expect(calls[1].params).toBeUndefined();
});
it.each([['https://evil.test/c', 201], ['https://checkout.stripe.com/c', 202]])('no entrega acción no corroborable %s/%s', async (url, status) => {
  task = c => ok(c, { data: operacion({ accion: { tipo: 'Redirigir', url } }) }, status);
  await expect(operarPago({ tipo: 'iniciar', id: O, clave: KEY, proveedor: 'stripe', revision_pago: 'r' })).rejects.toMatchObject({ pagoEnviado: true });
});
it('CSRF falla antes del POST, sin retry', async () => {
  api.defaults.adapter = async c => { calls.push(c); throw new AxiosError('sin red'); };
  await expect(operarPago({ tipo: 'resultado', id: I, clave: KEY })).rejects.toMatchObject({ pagoEnviado: false }); expect(calls).toHaveLength(1);
});
it('cambio de época mientras prepara CSRF impide POST', async () => {
  api.defaults.adapter = async c => { calls.push(c); avanzarSesion(); return ok(c, ''); };
  await expect(operarPago({ tipo: 'resultado', id: I, clave: KEY })).rejects.toMatchObject({ pagoEnviado: false }); expect(calls).toHaveLength(1);
});
it.each([401, 403, 404, 409, 419, 422, 429, 503])('propaga %s sin reintentos ni éxito supuesto', async status => {
  task = c => { throw new AxiosError('secreto', undefined, c, null, { status, data: { error: { code: 'ERROR', message: 'secreto' } }, headers: {} }); };
  await expect(operarPago({ tipo: 'resultado', id: I, clave: KEY })).rejects.toMatchObject({ pagoEnviado: true, response: { status } });
  expect(calls.filter(c => c.method === 'post')).toHaveLength(1);
});
it('propuesta y confirmación posterior no envían precio libre al confirmar', async () => {
  task = c => c.url.endsWith('/propuesta') ? ok(c, { data: { propuesta: 'token', precio, cita: { id_cita: '9' } } }) : ok(c, { data: obligacion() }, 201);
  const p = await proponerMonto('9', '70,00'); await confirmarMonto('9', p.propuesta);
  const posts = calls.filter(c => c.method === 'post'); expect(JSON.parse(posts[0].data)).toEqual({ monto_bob: '70.00' }); expect(JSON.parse(posts[1].data)).toEqual({ propuesta: 'token' });
});
it('reserva administrativa transporta monto exacto y conserva revisión del servidor', async () => {
  task = c => ok(c, { data: { ...propuesta, precio } });
  expect((await proponerReserva({ ...seleccion, id_paciente: '3', monto_bob: '70,00' })).precio).toEqual(precio);
  expect(JSON.parse(calls[1].data).monto_bob).toBe('70.00');
});
it('reserva propia rechaza monto antes de HTTP', async () => {
  await expect(proponerReserva({ ...seleccion, monto_bob: '70.00' })).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it('Médico descarta proyección financiera inesperada sin incorporarla a su consulta', async () => {
  task = c => ok(c, { data: { ...detalleAgenda('Médico'), situacion_precio: 'Asignado', precio: { secreto: 'no' }, obligacion_id: O } });
  const r = await consultarCita({ id: citaAgenda.id_cita, rol: 'Médico' });
  expect(r).not.toHaveProperty('situacion_precio'); expect(r).not.toHaveProperty('precio'); expect(r).not.toHaveProperty('obligacion_id');
});
it('filtro de asignación usa historial existente sin fabricar una deuda de cero', async () => {
  task = c => ok(c, paginaAgenda([{ ...citaAgenda, incorporada_finanzas: true, situacion_precio: 'PendienteAsignacion', puede_asignar_monto: true, obligacion_id: null, precio: null }],
    { paciente: citaAgenda.paciente, filtros: { periodo: 'todas', estado: null } }));
  const r = await consultarHistorialPaciente({ id: '31', situacion_precio: 'pendiente_asignacion' });
  expect(calls[0].params.situacion_precio).toBe('pendiente_asignacion'); expect(r.data[0]).toMatchObject({ situacion_precio: 'PendienteAsignacion', precio: null, obligacion_id: null });
});
