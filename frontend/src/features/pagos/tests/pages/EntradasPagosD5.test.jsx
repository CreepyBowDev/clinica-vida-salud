import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { crearRutas } from '../../../../app/router';
import { api, csrfURL, avanzarSesion } from '../../../../api/api';
import { O, I, C, detalle, obligacion, pagina, intento, operacion } from '../utils/pagoFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
const labels = { obligaciones: 'Consultar obligaciones de pago propias', pagar: 'Pagar una cita', historial: 'Consultar historial de pagos propio' };
const path = view => `/pagos/obligaciones/${O}?vista=${view}`;
let role, calls, detail, task;
const writes = () => calls.filter(c => c.method !== 'get');
const ok = (config, data, status = 200, headers = {}) => ({ config, data, status, headers });
const nav = () => screen.getByRole('navigation', { name: 'Navegación principal' });
function mount(url = '/pagos?vista=obligaciones') {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [url] });
  return { router, ...render(<AuthProvider><RouterProvider router={router} /></AuthProvider>) };
}
async function chooseView(view) {
  await userEvent.click(within(nav()).getByRole('link', { name: labels[view] }));
}
async function continueNavigation() { await userEvent.click(await screen.findByRole('button', { name: 'Salir y consultar después' })); }
async function prepare() {
  await userEvent.selectOptions(await screen.findByLabelText('Proveedor de prueba'), 'stripe');
  await userEvent.click(screen.getByRole('button', { name: 'Revisar inicio de pago' }));
}
beforeEach(() => {
  vi.resetAllMocks(); role = 'Paciente'; calls = []; detail = detalle(); task = null;
  consultarSesion.mockImplementation(async () => ({ id_usuario: '7', nombre_visible: 'Persona ficticia D5', correo_acceso: 'd5@example.test', rol: role }));
  vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new Error('Red externa bloqueada'))));
  api.defaults.adapter = async config => {
    calls.push(config);
    if (config.url === csrfURL) return ok(config, '');
    const custom = task && await task(config); if (custom) return custom;
    if (config.url === '/pacientes') return ok(config, pagina([{ id_paciente: '9', nombres: 'Paciente', apellidos: 'Ficticio D5', codigo_paciente: 'PAC-D5', documento_identidad: null, activo: false }]));
    if (config.url === '/pagos/obligaciones') return ok(config, pagina([obligacion()]));
    if (config.url === '/pagos/historial') return ok(config, pagina([intento()]));
    if (config.url === `/pagos/obligaciones/${O}`) return ok(config, detail);
    if (config.method === 'post' && config.url.startsWith('/pagos/')) return ok(config, { data: operacion() }, 201);
    throw new Error(`Transporte no simulado: ${config.url}`);
  };
});
afterEach(() => { api.defaults.adapter = original; vi.unstubAllGlobals(); vi.restoreAllMocks(); });

it.each(Object.keys(labels))('D5 entrada %s diferenciada, selección explícita y detalle con misma vista', async view => {
  mount(`/pagos?vista=${view}`);
  await screen.findByRole('heading', { name: labels[view], level: 1 });
  const link = view === 'historial' ? await screen.findByRole('link', { name: 'Ver obligación' })
    : await screen.findByRole('link', { name: view === 'pagar' ? 'Revisar para pagar: CITA-FIN-FICTICIA' : 'CITA-FIN-FICTICIA' });
  expect(link).toHaveAttribute('href', path(view));
  expect(screen.queryByLabelText('Operaciones del titular')).not.toBeInTheDocument();
  expect(calls.some(c => c.url === (view === 'historial' ? '/pagos/historial' : '/pagos/obligaciones'))).toBe(true);
  expect(calls.every(c => !Object.hasOwn(c.params ?? {}, 'vista') && !Object.hasOwn(c.params ?? {}, 'id_paciente'))).toBe(true);
  await userEvent.click(link); await screen.findByRole('heading', { name: 'Cita CITA-FIN-FICTICIA' });
  if (view === 'pagar') await screen.findByLabelText('Proveedor de prueba');
  else expect(screen.queryByLabelText('Operaciones del titular')).not.toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Volver a obligaciones e historial' })).toHaveAttribute('href', `/pagos?vista=${view}`);
  expect(writes()).toHaveLength(0); expect(fetch).not.toHaveBeenCalled();
});
it('D5 Inicio expone tres opciones y la entrada histórica conserva obligaciones', async () => {
  const { router } = mount('/inicio');
  const region = await screen.findByRole('region', { name: 'Funciones disponibles' });
  for (const [view, label] of Object.entries(labels)) expect(within(region).getByRole('link', { name: label })).toHaveAttribute('href', `/pagos?vista=${view}`);
  await act(() => router.navigate('/pagos')); await screen.findByRole('heading', { name: 'Mis pagos' });
  const legacy = await screen.findByRole('link', { name: 'CITA-FIN-FICTICIA' });
  expect(legacy).toHaveAttribute('href', `/pagos/obligaciones/${O}`);
  await userEvent.click(legacy); await screen.findByLabelText('Proveedor de prueba');
  expect(writes()).toHaveLength(0);
});
it('D5 Administrador selecciona Paciente real, propaga historial y nunca monta operaciones del titular', async () => {
  role = 'Administrador'; mount('/pagos?vista=historial');
  await screen.findByText('PAC-D5'); expect(calls.some(c => c.url.startsWith('/pagos'))).toBe(false);
  expect(within(nav()).queryByRole('link', { name: labels.historial })).not.toBeInTheDocument();
  expect(within(nav()).getByRole('link', { name: 'Consultar historial financiero de un Paciente' })).toHaveAttribute('aria-current', 'page');
  await userEvent.click(screen.getByRole('button', { name: 'Seleccionar paciente Paciente Ficticio D5' }));
  const link = await screen.findByRole('link', { name: 'Ver obligación' });
  expect(link).toHaveAttribute('href', `/pagos/pacientes/9/obligaciones/${O}?vista=historial`);
  expect(calls.at(-1).params.id_paciente).toBe('9');
  await userEvent.click(link); await screen.findByText('Ficha seleccionada: 9');
  expect(screen.queryByLabelText('Operaciones del titular')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Paso actual')).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Pagar', exact: true })).not.toBeInTheDocument();
  expect(document.title).toBe('Consultar historial financiero de un Paciente | Clínica Vida Salud');
  expect(writes()).toHaveLength(0);
});
it.each(['Médico', 'Recepcionista'])('D5 %s excluido de las tres entradas y pago contextual', async actor => {
  role = actor; const { router } = mount('/pagos?vista=pagar');
  await screen.findByRole('heading', { name: 'Acceso no permitido' });
  for (const url of ['/pagos?vista=historial', '/pagos?vista=obligaciones', path('pagar')]) {
    await act(() => router.navigate(url)); expect(screen.getByRole('heading', { name: 'Acceso no permitido' })).toBeVisible();
  }
  expect(calls).toHaveLength(0);
});
it('D5 Paciente no accede a ámbito ajeno ni lo transforma en selección propia', async () => {
  mount('/pagos/pacientes/9?vista=historial');
  await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
});
it.each(['', 'desconocida', 'toString', 'pagar&vista=pagar', 'historial&vista=obligaciones'])('D5 rechaza vista inválida %s sin elegir otra ni montar operaciones', async value => {
  mount(`${path(value)}`); await screen.findByRole('heading', { name: 'Vista de pagos no disponible' });
  expect(screen.queryByLabelText('Operaciones del titular')).not.toBeInTheDocument();
  expect(screen.queryByText('Bs 70,00')).not.toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Volver a pagos' })).toHaveAttribute('href', '/pagos');
  expect(writes()).toHaveLength(0);
});
it.each([
  ['Administrador', '/pagos?vista=pagar'],
  ['Administrador', '/pagos/pacientes/9?vista=pagar'],
  ['Paciente', `/pagos/obligaciones/${O}/pagar?vista=historial`],
  ['Paciente', `/pagos/retorno/paypal/${I}?vista=obligaciones`],
  ['Paciente', '/pagos/demostracion?vista=pagar'],
])('D5 %s rechaza vista incompatible en %s', async (actor, url) => {
  role = actor; mount(url);
  if (url.includes('/retorno/')) await screen.findByRole('alert');
  else await screen.findByRole('heading', { name: 'Vista de pagos no disponible' });
  expect(screen.queryByLabelText('Operaciones del titular')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Modalidad')).not.toBeInTheDocument(); expect(writes()).toHaveLength(0);
});
it.each([
  { puede_iniciar_pago: false, revision_pago: null, proveedores_disponibles: [] },
  { proveedores_disponibles: [] },
  { revision_pago: null },
])('D5 capacidades ausentes o apagadas no se infieren del precio/estado/reloj: %j', async state => {
  detail = detalle(state); mount(path('pagar'));
  await screen.findByText('Bs 70,00'); await screen.findByText(/No hay una acción de pago disponible/);
  expect(screen.queryByLabelText('Operaciones del titular')).not.toBeInTheDocument(); expect(writes()).toHaveLength(0);
});
it('D5 pasar de consulta a pago reconsulta capacidades, no usa la capacidad del listado o del detalle anterior', async () => {
  mount(path('obligaciones')); await screen.findByText('Bs 70,00');
  detail = detalle({ puede_iniciar_pago: false, revision_pago: null, proveedores_disponibles: [] });
  await userEvent.click(within(screen.getByRole('main')).getByRole('link', { name: 'Pagar una cita', exact: true }));
  await screen.findByText(/No hay una acción de pago disponible/);
  expect(calls.filter(c => c.url === `/pagos/obligaciones/${O}`).length).toBeGreaterThan(1);
  expect(screen.queryByLabelText('Proveedor de prueba')).not.toBeInTheDocument(); expect(writes()).toHaveLength(0);
});
it('D5 montaje, recarga simulada, atrás/adelante y retornos no inician ni verifican intentos', async () => {
  detail = detalle({ puede_iniciar_pago: false, intento_actual: { id: I, proveedor: 'paypal', estado: 'RequiereAccion' } });
  const first = mount(path('pagar')); await screen.findByRole('button', { name: 'Consultar resultado' });
  await chooseView('historial'); await screen.findByRole('heading', { name: labels.historial, level: 1 });
  await act(() => first.router.navigate(-1)); await screen.findByRole('button', { name: 'Consultar resultado' });
  await act(() => first.router.navigate(1)); await screen.findByRole('heading', { name: labels.historial, level: 1 });
  first.unmount(); const again = mount(path('historial')); await screen.findByText('Bs 70,00');
  await act(() => again.router.navigate(`/pagos/retorno/paypal/${I}?vista=pagar`));
  await screen.findByRole('button', { name: 'Consultar resultado' });
  expect(writes()).toHaveLength(0); expect(fetch).not.toHaveBeenCalled();
});
it.each(['preparacion', 'revision'])('D5 conserva %s al cambiar solo vista y al navegar a la misma URL', async stage => {
  const { router } = mount(path('pagar')); await userEvent.selectOptions(await screen.findByLabelText('Proveedor de prueba'), 'paypal');
  if (stage === 'revision') await userEvent.click(screen.getByRole('button', { name: 'Revisar inicio de pago' }));
  await userEvent.click(screen.getByRole('button', { name: 'Abrir menú' }));
  const link = within(nav()).getByRole('link', { name: labels.historial });
  await userEvent.click(link);
  expect(link).toHaveFocus(); expect(router.state.location.search).toBe('?vista=pagar');
  expect(document.title).toBe('Pagar una cita | Clínica Vida Salud');
  expect(screen.getByRole('button', { name: 'Cerrar menú' })).toHaveAttribute('aria-expanded', 'true');
  await userEvent.click(screen.getByRole('button', { name: 'Permanecer aquí' }));
  await userEvent.click(link); await continueNavigation();
  expect(router.state.location.pathname).toBe(`/pagos/obligaciones/${O}`);
  expect(screen.getByRole('main')).toHaveFocus(); expect(screen.getByRole('button', { name: 'Abrir menú' })).toHaveAttribute('aria-expanded', 'false');
  expect(screen.queryByLabelText('Proveedor de prueba')).not.toBeVisible();
  expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).toHaveTextContent(labels.historial);
  await chooseView('pagar'); await continueNavigation();
  expect(await screen.findByLabelText('Proveedor de prueba')).toHaveValue('paypal');
  if (stage === 'revision') expect(screen.getByRole('heading', { name: 'Confirmar inicio de pago' })).toBeVisible();
  await chooseView('pagar'); expect(router.state.blockers.size).toBe(1); await continueNavigation();
  expect(screen.getByLabelText('Proveedor de prueba')).toHaveValue('paypal'); expect(writes()).toHaveLength(0);
});
it('D5 envío pendiente conserva el hook, referencia y una sola escritura al navegar', async () => {
  let resolve;
  task = c => c.method === 'post' ? new Promise(yes => { resolve = () => yes(ok(c, { data: operacion() }, 201)); }) : null;
  mount(path('pagar')); await prepare(); await userEvent.click(screen.getByRole('button', { name: 'Iniciar pago de prueba' }));
  await waitFor(() => expect(writes()).toHaveLength(1));
  await chooseView('historial'); await continueNavigation();
  expect(screen.getByText(/Hay un proceso de pago conservado/)).toBeVisible();
  await act(async () => resolve());
  await chooseView('pagar'); await continueNavigation();
  expect(await screen.findByRole('link', { name: 'Continuar en Stripe Checkout test' })).toBeVisible();
  expect(screen.getByText(I)).toBeVisible(); expect(writes()).toHaveLength(1);
});
it('D5 incertidumbre conserva la misma solicitud/clave, espera y recuperación exclusivamente explícita', async () => {
  task = c => { if (c.method === 'post') throw new AxiosError('Respuesta perdida', 'ERR_NETWORK', c); };
  mount(path('pagar')); await prepare(); await userEvent.click(screen.getByRole('button', { name: 'Iniciar pago de prueba' }));
  await screen.findByRole('button', { name: 'Recuperar misma solicitud' });
  const first = writes()[0], key = first.headers['Idempotency-Key'];
  await chooseView('obligaciones'); await continueNavigation();
  await chooseView('pagar'); await continueNavigation();
  const recover = screen.getByRole('button', { name: 'Recuperar misma solicitud' }); expect(recover).toBeDisabled();
  expect(writes()).toHaveLength(1);
  vi.spyOn(Date, 'now').mockReturnValue(Date.now() + 10000);
  await waitFor(() => expect(recover).toBeEnabled(), { timeout: 3000 }); task = null;
  await userEvent.click(recover); await screen.findByRole('link', { name: 'Continuar en Stripe Checkout test' });
  expect(writes()).toHaveLength(2); expect(writes()[1].headers['Idempotency-Key']).toBe(key);
  expect(writes()[1].url).toBe(first.url); expect(writes()[1].data).toBe(first.data);
});
it.each([409, 429])('D5 conflicto/revisión obsoleta o espera %s conserva bloqueo, sin replay ni otra operación', async status => {
  task = c => { if (c.method === 'post') throw new AxiosError('Fallo ficticio', undefined, c, null, { status, data: { error: { code: 'PAGO_REVISION_CAMBIO' } }, headers: { 'retry-after': '30' } }); };
  mount(path('pagar')); await prepare(); await userEvent.click(screen.getByRole('button', { name: 'Iniciar pago de prueba' }));
  await screen.findByRole('alert'); await chooseView('historial'); await continueNavigation();
  await chooseView('pagar'); await continueNavigation();
  expect(screen.queryByRole('button', { name: 'Iniciar pago de prueba' })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Consultar obligación vigente' })).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Actualizar detalle' })).toBeDisabled(); expect(writes()).toHaveLength(1);
});
it('D5 cambio de sesión retira preparación y no acepta respuestas financieras atrasadas', async () => {
  mount(path('pagar')); await prepare(); act(() => avanzarSesion());
  await screen.findByRole('heading', { name: 'El contexto cambió' });
  expect(screen.queryByLabelText('Proveedor de prueba')).not.toBeInTheDocument(); expect(writes()).toHaveLength(0);
});
it('D5 ciclo académico conserva revisión al cambiar a consulta, sin montar otro escritor ni simular', async () => {
  detail = detalle({ simulacion: { habilitada: true, puede_iniciar: true, revision: 'academica-d5' } });
  const { router } = mount(`/pagos/obligaciones/${O}/pagar?vista=pagar`);
  await userEvent.click(await screen.findByRole('button', { name: 'Revisar inicio simulado' }));
  await screen.findByRole('heading', { name: 'Confirmar importe' });
  await chooseView('historial'); await continueNavigation();
  expect(router.state.location.pathname).toBe(`/pagos/obligaciones/${O}`);
  expect(screen.queryByLabelText('Operaciones del titular')).not.toBeInTheDocument();
  await userEvent.click(screen.getByRole('link', { name: 'Volver al proceso conservado' })); await continueNavigation();
  expect(await screen.findByRole('heading', { name: 'Confirmar importe' })).toBeVisible();
  expect(writes()).toHaveLength(0); expect(fetch).not.toHaveBeenCalled();
});
it('D5 selección académica protegida conserva modalidad y escenario sin empezar un intento', async () => {
  detail = detalle({ simulacion: { habilitada: true, puede_iniciar: true, revision: 'academica-d5' } });
  mount(`/pagos/obligaciones/${O}/pagar?vista=pagar`);
  await userEvent.selectOptions(await screen.findByLabelText('Modalidad'), 'paypal');
  await userEvent.selectOptions(screen.getByLabelText('Escenario académico'), 'pendiente');
  await chooseView('pagar'); await continueNavigation();
  expect(screen.queryByLabelText('Operaciones del titular')).not.toBeInTheDocument();
  expect(screen.getByLabelText('Modalidad')).toBeDisabled();
  await userEvent.click(screen.getByRole('link', { name: 'Volver al proceso conservado' })); await continueNavigation();
  expect(await screen.findByLabelText('Modalidad')).toHaveValue('paypal');
  expect(screen.getByLabelText('Escenario académico')).toHaveValue('pendiente'); expect(writes()).toHaveLength(0);
});
it('D5 recurso ajeno rechazado no permite selección ni crea un intento', async () => {
  task = c => { if (c.url === `/pagos/obligaciones/${C}`) throw new AxiosError('No autorizado', undefined, c, null, { status: 403, data: {}, headers: {} }); };
  mount(`/pagos/obligaciones/${C}?vista=pagar`); await screen.findByRole('alert');
  expect(screen.queryByLabelText('Operaciones del titular')).not.toBeInTheDocument(); expect(writes()).toHaveLength(0);
});
