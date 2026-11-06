import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { crearRutas } from '../../../../app/router';
import { api, csrfURL } from '../../../../api/api';
import { O, I, C, at, detalle, intento, pagina } from '../utils/pagoFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
let data, calls, role, lost, reject;
const posts = () => calls.filter(c => c.method === 'post');
function mount(path = `/pagos/obligaciones/${O}/pagar`) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  return { router, ...render(<AuthProvider><RouterProvider router={router} /></AuthProvider>) };
}
const click = async name => userEvent.click(await screen.findByRole('button', { name, exact: true }));
async function start(modalidad = 'stripe', escenario = 'completado') {
  await screen.findByLabelText('Modalidad');
  await userEvent.selectOptions(screen.getByLabelText('Modalidad'), modalidad);
  await userEvent.selectOptions(screen.getByLabelText('Escenario académico'), escenario);
  await click('Revisar inicio simulado');
  expect(posts()).toHaveLength(0);
  await click('Iniciar pago académico');
  await screen.findByRole('heading', { name: 'Salida simulada' });
}
beforeEach(() => {
  vi.resetAllMocks(); role = 'Paciente'; calls = []; lost = false; reject = false;
  data = detalle({ puede_iniciar_pago: false, revision_pago: null, proveedores_disponibles: [],
    simulacion: { habilitada: true, puede_iniciar: true, revision: 'revision-academica' } });
  consultarSesion.mockImplementation(async () => ({ id_usuario: '7', nombre_visible: 'Persona ficticia', correo_acceso: 'p@example.test', rol: role }));
  vi.stubGlobal('fetch', vi.fn(() => { throw new Error('Proveedor bloqueado'); }));
  api.defaults.adapter = async config => {
    calls.push(config);
    const ok = (v, status = 200) => ({ config, data: structuredClone(v), status, headers: {} });
    if (config.url === csrfURL) return ok('');
    if (config.method === 'get' && config.url === `/pagos/obligaciones/${O}`) return ok(data);
    if (config.method !== 'post' || !config.url.startsWith('/pagos/')) throw new Error('Petición no permitida');
    if (reject) throw new AxiosError('Conflicto', '409', config, {}, { status: 409, data: { error: { code: 'PAGO_OPERACION_ACTIVA', message: 'Conflicto' } }, headers: {} });
    const body = JSON.parse(config.data), o = data.data;
    if (config.url.endsWith('/simulacion')) {
      o.intento_actual = { id: I, proveedor: 'simulado', estado: 'RequiereAccion', modalidad: body.modalidad, escenario: body.escenario, aprobada: false };
      o.simulacion.puede_iniciar = false; o.simulacion.revision = null; o.operacion_en_verificacion = true;
    } else if (config.url.endsWith('/aprobar')) o.intento_actual.aprobada = true;
    else if (config.url.endsWith('/cerrar')) { o.intento_actual = null; o.operacion_en_verificacion = false; o.simulacion.puede_iniciar = true; o.simulacion.revision = 'nueva'; }
    else if (/\/(confirmar|capturar)$/.test(config.url) && o.intento_actual?.escenario === 'completado') {
      o.liquidada = true; o.origen_liquidacion = 'Simulado'; o.operacion_en_verificacion = false;
      o.intentos = pagina([intento({ proveedor: 'simulado', origen: 'Simulado', modalidad: o.intento_actual.modalidad,
        cobros: [{ id: C, aplicado: true, incidencia: null, centavos_usd: 1000, confirmado_at: at, referencia: `SIM-${C}`, devolucion: null }] })]);
      o.intento_actual = null;
    }
    const i = o.intento_actual;
    if (lost) { lost = false; throw new AxiosError('Respuesta perdida', 'ERR_NETWORK', config); }
    return ok({ data: { id: I, obligacion_id: O, origen: 'Simulado', estado: o.liquidada ? 'Exitoso' : i?.estado ?? 'Cerrado',
      modalidad: i?.modalidad ?? 'stripe', escenario: i?.escenario ?? 'completado', aprobada: i?.aprobada ?? false, liquidada: o.liquidada, repetida: false } }, config.url.endsWith('/simulacion') ? 201 : 200);
  };
});
afterEach(() => { api.defaults.adapter = original; vi.unstubAllGlobals(); });

it('Stripe revisa el precio real de API y solo muestra Pagada tras confirmación; recarga y Admin comparten consulta', async () => {
  const view = mount(); await start();
  expect(data.data.liquidada).toBe(false); expect(posts()).toHaveLength(1);
  expect(screen.queryByText(/Pagada —/)).not.toBeInTheDocument();
  await click('Volver al portal'); expect(posts()).toHaveLength(1);
  await click('Confirmar simulación y consultar resultado');
  await screen.findByText('Pagada — simulación académica');
  expect(posts()).toHaveLength(2); expect(data.data.cita.estado).toBe('Programada');
  view.unmount(); const again = mount(); await screen.findByText('Pagada — simulación académica'); again.unmount();
  role = 'Administrador'; mount(`/pagos/pacientes/9/obligaciones/${O}`);
  await screen.findByText('Pagada — simulación académica');
  expect(screen.getByText(/Origen simulado/)).toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Pagar', exact: true })).not.toBeInTheDocument();
  expect(posts()).toHaveLength(2); expect(fetch).not.toHaveBeenCalled();
  expect(posts().every(c => !/tarjeta|titular|correo|clave-demo|seguridad/.test(c.data))).toBe(true);
});
it('PayPal aprobar no paga, retorno no captura y la captura exige revisión humana', async () => {
  mount(); await start('paypal'); await click('Volver al portal');
  await click('Aprobar operación académica');
  await screen.findByText('Aprobación registrada, sin captura.', { exact: false });
  expect(data.data.liquidada).toBe(false);
  await click('Revisar captura PayPal');
  expect(posts().filter(c => c.url.endsWith('/capturar'))).toHaveLength(0);
  await click('Capturar pago académico'); await screen.findByText('Pagada — simulación académica');
  expect(posts().filter(c => c.url.endsWith('/capturar'))).toHaveLength(1);
});
it('pendiente persistido no hace polling, se recupera al remontar y solo permite cerrar sin cobro', async () => {
  const view = mount(); await start('stripe', 'pendiente'); await click('Volver al portal');
  await screen.findByText(/Resultado pendiente/); const n = calls.length;
  await new Promise(resolve => setTimeout(resolve, 50)); expect(calls).toHaveLength(n);
  view.unmount(); mount(); await screen.findByText(/Resultado pendiente/);
  expect(screen.queryByRole('button', { name: 'Confirmar simulación y consultar resultado' })).not.toBeInTheDocument();
  await click('Revisar cierre sin cobro'); await click('Cerrar sin cobro');
  await screen.findByLabelText('Titular'); expect(data.data.liquidada).toBe(false); expect(data.data.intentos.data).toHaveLength(0);
});
it('formatos obligatorios y foco antes de revisar; los campos no se envían', async () => {
  mount(); await screen.findByLabelText('Titular');
  await userEvent.clear(screen.getByLabelText('Titular')); await click('Revisar inicio simulado');
  expect(screen.getByLabelText('Titular')).toHaveFocus(); expect(posts()).toHaveLength(0);
  await userEvent.type(screen.getByLabelText('Titular'), 'Persona Ficticia');
  await click('Revisar inicio simulado'); expect(screen.getByRole('heading', { name: 'Revisar importe' })).toHaveFocus();
  await click('Iniciar pago académico'); await screen.findByRole('heading', { name: 'Salida simulada' });
  expect(JSON.parse(posts()[0].data)).toEqual({ modalidad: 'stripe', escenario: 'completado', revision: 'revision-academica' });
});
it.each(['Administrador', 'Recepcionista', 'Médico'])('%s no accede a Pagar ni solicita datos financieros desde esa ruta', async r => {
  role = r; mount(); await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
});
it.each([{ simulacion: { habilitada: false, puede_iniciar: false, revision: null } }, { liquidada: true }, { exigible: false },
  { operacion_en_verificacion: true, simulacion: { habilitada: true, puede_iniciar: false, revision: null } }])('no ofrece inicio en estados no elegibles %j', async extra => {
  Object.assign(data.data, extra); mount(); await screen.findByText('Bs 70,00');
  expect(screen.queryByLabelText('Titular')).not.toBeInTheDocument(); expect(posts()).toHaveLength(0);
});
it('respuesta perdida consulta persistencia sin reenviar ni declarar fallo; recupera la misma clave', async () => {
  mount(); await start(); await click('Volver al portal'); lost = true;
  await click('Confirmar simulación y consultar resultado');
  await screen.findByText(/Resultado incierto/); await screen.findByText('Pagada — simulación académica');
  const first = posts().at(-1); await click('Recuperar la misma operación');
  await waitFor(() => expect(posts()).toHaveLength(3));
  expect(posts().at(-1).headers['Idempotency-Key']).toBe(first.headers['Idempotency-Key']);
});
it('conflicto no muestra éxito visual y exige estado vigente', async () => {
  mount(); await start(); await click('Volver al portal'); reject = true;
  await click('Confirmar simulación y consultar resultado');
  await screen.findByText(/Las condiciones cambiaron/); expect(data.data.liquidada).toBe(false);
  expect(screen.queryByText('Pagada — simulación académica')).not.toBeInTheDocument();
});
