import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { crearRutas } from '../../../../app/router';
import { api, csrfURL, avanzarSesion } from '../../../../api/api';
import { I, O, C, at, precio, pagina, obligacion, detalle, operacion, intento } from '../utils/pagoFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
const ok = (config, data, status = 200, headers = {}) => ({ config, data, status, headers });
let role, calls, detail, operation, task, list;
const posts = () => calls.filter(c => c.method === 'post');
function mount(path = `/pagos/obligaciones/${O}`) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks(); role = 'Paciente'; calls = []; detail = detalle(); operation = operacion(); task = null; list = pagina([obligacion()]);
  consultarSesion.mockImplementation(async () => ({ id_usuario: '7', nombre_visible: 'Persona ficticia', correo_acceso: 'p@example.test', rol: role }));
  vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new Error('Red externa bloqueada'))));
  api.defaults.adapter = async config => {
    calls.push(config);
    if (config.url === csrfURL) return ok(config, '');
    if (task) { const r = await task(config); if (r) return r; }
    if (config.url === '/pagos/obligaciones') return ok(config, list);
    if (config.url === `/pagos/obligaciones/${O}`) return ok(config, detail);
    if (config.url === '/pagos/historial') return ok(config, pagina([intento()]));
    if (config.method === 'post' && config.url.startsWith('/pagos/')) return ok(config, { data: operation }, config.url.endsWith('/intentos') ? 201 : 200);
    throw new Error(`Petición no simulada bloqueada: ${config.url}`);
  };
});
afterEach(() => { api.defaults.adapter = original; vi.unstubAllGlobals(); });
async function start(proveedor = 'stripe') {
  await screen.findByRole('heading', { name: 'Cita CITA-FIN-FICTICIA' });
  await userEvent.selectOptions(screen.getByLabelText('Proveedor de prueba'), proveedor);
  await userEvent.click(screen.getByRole('button', { name: 'Revisar inicio de pago' }));
  await userEvent.click(screen.getByRole('button', { name: 'Iniciar pago de prueba' }));
}
it('revisa importes y Stripe devuelve solo enlace del servidor sin navegar automáticamente', async () => {
  mount(); await screen.findByText('Bs 70,00'); expect(screen.getByText('USD 10,00')).toBeInTheDocument(); expect(posts()).toHaveLength(0);
  await start(); const link = await screen.findByRole('link', { name: 'Continuar en Stripe Checkout test' });
  expect(link).toHaveAttribute('href', operation.accion.url); expect(link).toHaveAttribute('referrerpolicy', 'no-referrer');
  expect(posts()).toHaveLength(1); expect(screen.queryByRole('button', { name: /cancelar cita|reprogramar/i })).not.toBeInTheDocument();
});
it('PayPal exige consulta de aprobación y captura humana explícita con revisión', async () => {
  operation = operacion({ proveedor: 'paypal', accion: { tipo: 'Redirigir', url: 'https://www.sandbox.paypal.com/checkoutnow?token=FICTICIO' } });
  mount(); await start('paypal'); await screen.findByRole('link', { name: 'Continuar en PayPal Sandbox' });
  expect(posts()).toHaveLength(1); expect(screen.queryByRole('button', { name: 'Revisar captura PayPal' })).not.toBeInTheDocument();
  operation = operacion({ proveedor: 'paypal', accion: null });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar resultado' }));
  await userEvent.click(await screen.findByRole('button', { name: 'Revisar captura PayPal' }));
  expect(posts().filter(c => c.url.endsWith('/captura'))).toHaveLength(0);
  operation = operacion({ proveedor: 'paypal', accion: null, estado: 'Exitoso', aplicado: true, obligacion: { id: O, exigible: true, liquidada: true } });
  await userEvent.click(screen.getByRole('button', { name: 'Capturar pago con PayPal' }));
  await screen.findByText('Cobro verificado y aplicado a esta obligación.');
  expect(posts().filter(c => c.url.endsWith('/paypal/captura'))).toHaveLength(1);
});
it('el retorno no consulta ni captura en montaje y no acredita pago por token externo', async () => {
  mount(`/pagos/retorno/paypal/${I}?token=EXTERNO&PayerID=NO`);
  await screen.findByRole('heading', { name: 'Retorno al portal' }); expect(posts()).toHaveLength(0);
  operation = operacion({ proveedor: 'paypal', accion: null });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar resultado' }));
  await screen.findByRole('link', { name: 'Revisar importe y obligación' });
  expect(posts()).toHaveLength(1); expect(posts()[0].url).toBe(`/pagos/intentos/${I}/resultado`); expect(JSON.parse(posts()[0].data)).toEqual({});
  expect(screen.queryByRole('button', { name: /Capturar pago|Revisar captura/ })).not.toBeInTheDocument();
  expect(screen.queryByText('Cobro verificado y aplicado a esta obligación.')).not.toBeInTheDocument();
});
it.each(['Recepcionista', 'Médico'])('%s no carga pagos ni obtiene enlaces financieros', async r => {
  role = r; mount('/pagos'); await screen.findByRole('heading', { name: 'Acceso no permitido' });
  expect(calls).toHaveLength(0); expect(screen.queryByRole('button', { name: 'Pagos' })).not.toBeInTheDocument();
});
it('Administrador exige paciente y consulta detalle sin acciones del titular', async () => {
  role = 'Administrador'; mount(`/pagos/pacientes/9/obligaciones/${O}`);
  await screen.findByRole('heading', { name: 'Cita CITA-FIN-FICTICIA' });
  expect(calls[0].params.id_paciente).toBe('9'); expect(screen.queryByLabelText('Proveedor de prueba')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Solicitar cierre del intento' })).not.toBeInTheDocument(); expect(posts()).toHaveLength(0);
});
it('Administrador reutiliza selección de ficha inactiva/sin CI antes de consultar y puede cambiarla', async () => {
  role = 'Administrador';
  task = c => c.url === '/pacientes' ? ok(c, pagina([{ id_paciente: '9', nombres: 'Paciente', apellidos: 'De prueba', codigo_paciente: 'PAC-FICTICIO', documento_identidad: null, activo: false }])) : null;
  mount('/pagos'); await screen.findByText('PAC-FICTICIO');
  expect(calls.some(c => c.url.startsWith('/pagos'))).toBe(false);
  await userEvent.click(screen.getByRole('button', { name: 'Seleccionar paciente Paciente De prueba' }));
  await screen.findByText('CITA-FIN-FICTICIA'); expect(calls.at(-1).params.id_paciente).toBe('9');
  await userEvent.click(screen.getByRole('link', { name: 'Cambiar paciente' }));
  await screen.findByRole('heading', { name: 'Seleccionar paciente para consultar pagos' });
  expect(screen.queryByText('CITA-FIN-FICTICIA')).not.toBeInTheDocument();
});
it('sesión vencida conserva retorno local para login sin consumir operaciones', async () => {
  consultarSesion.mockRejectedValue({ response: { status: 401 } });
  const router = mount(`/pagos/retorno/paypal/${I}`);
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(router.state.location.state.from).toBe(`/pagos/retorno/paypal/${I}`); expect(posts()).toHaveLength(0);
});
it('flags apagados conservan detalle sin acciones de inicio', async () => {
  detail = detalle({ puede_iniciar_pago: false, revision_pago: null, proveedores_disponibles: [] }); mount();
  await screen.findByText('Bs 70,00'); expect(screen.queryByLabelText('Proveedor de prueba')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Revisar inicio de pago' })).not.toBeInTheDocument(); expect(posts()).toHaveLength(0);
});
it('solo ofrece el proveedor que el servidor declara disponible', async () => {
  detail = detalle({ proveedores_disponibles: ['paypal'] }); mount();
  await screen.findByLabelText('Proveedor de prueba'); expect(screen.queryByRole('option', { name: 'Stripe Checkout test' })).not.toBeInTheDocument();
});
it('202 conserva verificación, respeta espera y no hace polling', async () => {
  task = c => c.method === 'post' ? ok(c, { data: operacion({ accion: null, estado: 'Incierto', operacion_en_verificacion: true }) }, 202, { 'retry-after': '30' }) : null;
  mount(); await start(); await screen.findByText(/Verificación pendiente\. No equivale/);
  expect(screen.queryByRole('link', { name: /Continuar en/ })).not.toBeInTheDocument(); expect(screen.getByRole('button', { name: 'Consultar resultado' })).toBeDisabled();
  expect(screen.queryByRole('button', { name: 'Revisar inicio de pago' })).not.toBeInTheDocument(); expect(posts()).toHaveLength(1);
});
it.each(['stripe', 'paypal'])('cierre %s no habilita otro proveedor sin consultar elegibilidad vigente', async proveedor => {
  detail = detalle({ puede_iniciar_pago: false, revision_pago: null, proveedores_disponibles: [], intento_actual: { id: I, proveedor, estado: 'RequiereAccion' } });
  operation = operacion({ proveedor, estado: 'Cerrado', accion: null, puede_reintentar: true });
  mount(); await screen.findByText('Bs 70,00');
  await userEvent.click(screen.getByRole('button', { name: 'Solicitar cierre del intento' })); expect(posts()).toHaveLength(0);
  await userEvent.click(screen.getByRole('button', { name: 'Solicitar cierre', exact: true })); await screen.findByText(/El cierre del intento no cancela la cita/);
  expect(screen.queryByLabelText('Proveedor de prueba')).not.toBeInTheDocument();
  detail = detalle({ proveedores_disponibles: [proveedor === 'stripe' ? 'paypal' : 'stripe'] });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar obligación vigente' }));
  await screen.findByLabelText('Proveedor de prueba'); expect(posts()).toHaveLength(1);
});
it('cierre pendiente no equivale a cita cancelada ni a permiso para cambiar proveedor', async () => {
  detail = detalle({ puede_iniciar_pago: false, revision_pago: null, proveedores_disponibles: [], intento_actual: { id: I, proveedor: 'paypal', estado: 'Incierto' } });
  task = c => c.method === 'post' ? ok(c, { data: operacion({ proveedor: 'paypal', accion: null, estado: 'Incierto', operacion_en_verificacion: true }) }, 202, { 'retry-after': '5' }) : null;
  mount(); await screen.findByText('Bs 70,00'); await userEvent.click(screen.getByRole('button', { name: 'Solicitar cierre del intento' }));
  await userEvent.click(screen.getByRole('button', { name: 'Solicitar cierre', exact: true })); await screen.findByText(/Verificación pendiente\. No equivale/);
  expect(screen.queryByLabelText('Proveedor de prueba')).not.toBeInTheDocument(); expect(screen.queryByText('Cancelada')).not.toBeInTheDocument();
});
it('doble clic queda excluido y actualizar detalle no abandona envío pendiente', async () => {
  let resolve;
  task = c => c.method === 'post' ? new Promise(yes => { resolve = () => yes(ok(c, { data: operacion() }, 201)); }) : null;
  mount(); await screen.findByLabelText('Proveedor de prueba'); await userEvent.selectOptions(screen.getByLabelText('Proveedor de prueba'), 'stripe');
  await userEvent.click(screen.getByRole('button', { name: 'Revisar inicio de pago' }));
  const confirm = screen.getByRole('button', { name: 'Iniciar pago de prueba' }); fireEvent.click(confirm); fireEvent.click(confirm);
  await waitFor(() => expect(posts()).toHaveLength(1)); expect(screen.getByRole('button', { name: 'Actualizar detalle' })).toBeDisabled();
  await act(async () => resolve()); await screen.findByRole('link', { name: /Continuar en Stripe/ });
});
it('sesión cambiada retira datos y descarta una respuesta atrasada', async () => {
  let resolve;
  task = c => c.url === `/pagos/obligaciones/${O}` ? new Promise(yes => { resolve = () => yes(ok(c, detail)); }) : null;
  mount(); await waitFor(() => expect(calls).toHaveLength(1)); act(() => avanzarSesion());
  await screen.findByRole('heading', { name: 'El contexto cambió' }); await act(async () => resolve());
  expect(screen.queryByText('Bs 70,00')).not.toBeInTheDocument(); expect(screen.queryByLabelText('Proveedor de prueba')).not.toBeInTheDocument();
});
it('cambiar obligación descarta una respuesta anterior aunque llegue después de la nueva', async () => {
  let resolve;
  task = c => c.url === `/pagos/obligaciones/${O}` ? new Promise(yes => { resolve = () => yes(ok(c, detail)); })
    : c.url === `/pagos/obligaciones/${C}` ? ok(c, detalle({ id: C, cita: { ...obligacion().cita, codigo_cita: 'OTRA-CITA-FICTICIA' } })) : null;
  const router = mount(); await waitFor(() => expect(calls).toHaveLength(1));
  await act(async () => router.navigate(`/pagos/obligaciones/${C}`)); await screen.findByRole('heading', { name: 'Cita OTRA-CITA-FICTICIA' });
  await act(async () => resolve()); expect(screen.queryByRole('heading', { name: 'Cita CITA-FIN-FICTICIA' })).not.toBeInTheDocument();
});
it('historial mantiene cobro no aplicado, cancelación y devolución pendiente sin resolverlos', async () => {
  detail = detalle({ exigible: false, liquidada: true, puede_iniciar_pago: false, revision_pago: null, proveedores_disponibles: [],
    cita: { ...obligacion().cita, estado: 'Cancelada' }, intentos: pagina([intento({ cobros: [{ id: C, aplicado: false, incidencia: 'COBRO_ADICIONAL_NO_APLICADO', centavos_usd: 1000, confirmado_at: at, referencia: 'REFERENCIA-FICTICIA', devolucion: null }] })]),
    devoluciones: pagina([{ id: C, estado: 'Pendiente', centavos_usd: 1000, moneda: 'USD' }]) });
  mount(); await screen.findByText('No exigible'); expect(screen.getByText(/Cobro no aplicado/)).toBeInTheDocument();
  expect(screen.getByText(/Incidencia pendiente de revisión/)).toBeInTheDocument(); expect(screen.queryByRole('button', { name: /resolver|compensar|reintentar devolución/i })).not.toBeInTheDocument();
});
it('filtros/paginación consultan servidor sin crear obligaciones', async () => {
  list = pagina([obligacion()], { total: 21, last_page: 2 }); mount('/pagos'); await screen.findByText('CITA-FIN-FICTICIA');
  await userEvent.selectOptions(screen.getByLabelText('Vencida regularizable'), '1'); await userEvent.click(screen.getByRole('button', { name: 'Consultar pagos' }));
  await waitFor(() => expect(calls.at(-1).params.vencida).toBe('1'));
  await userEvent.click(screen.getByRole('button', { name: 'Siguiente' })); await waitFor(() => expect(calls.at(-1).params.page).toBe(2)); expect(posts()).toHaveLength(0);
});
it.each([403, 404, 409, 419, 422, 429, 503])('error %s no expone cuerpos ni éxito', async status => {
  task = c => { throw new AxiosError('SQL secreto', undefined, c, null, { status, data: { error: { code: 'ERROR', message: 'SQL secreto' } }, headers: {} }); };
  mount(); await screen.findByRole('alert'); expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument(); expect(screen.queryByLabelText('Proveedor de prueba')).not.toBeInTheDocument();
});
