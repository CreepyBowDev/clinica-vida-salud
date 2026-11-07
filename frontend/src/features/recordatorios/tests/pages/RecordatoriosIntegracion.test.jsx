// Integración local de componentes/servicios/interceptores reales.
// Adapter Axios y APIs del navegador simulados: NO es HTTP ni entrega push real.
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { aviso, listado, sesion } from '../utils/recordatorioFixtures';
import { pacientes } from '../utils/pacientesFixtures';

const originalAdapter = api.defaults.adapter;
const key = Uint8Array.from([4, ...Array(64).fill(1)]);
const json = { endpoint: 'https://push.example.test/ficticio', expirationTime: null,
  keys: { p256dh: 'a'.repeat(87), auth: 'b'.repeat(22) } };
const fecha = '2026-10-31T12:00:00.000001Z';
let calls, account, rows, subscriptions, handler, permission, manager, registration, unsubscribe;
const response = (config, data, status = 200) => ({ config, data, status, headers: {} });
function reject(config, status) {
  throw new AxiosError('DETALLE-PRIVADO', undefined, config, null, response(config, { error: { code: 'ERROR', message: 'DETALLE-PRIVADO' } }, status));
}
function mount() {
  const router = createMemoryRouter(crearRutas(), { initialEntries: ['/recordatorios'] });
  return { router, ...render(<AuthProvider><RouterProvider router={router} /></AuthProvider>) };
}
const writes = () => calls.filter(c => c.method !== 'get');
beforeEach(() => {
  calls = []; account = sesion(); rows = [aviso()]; subscriptions = []; handler = null;
  permission = { permission: 'default', requestPermission: vi.fn(async () => { permission.permission = 'granted'; return 'granted'; }) };
  unsubscribe = vi.fn().mockResolvedValue(true);
  const subscription = { options: { applicationServerKey: key.buffer }, toJSON: () => json, unsubscribe };
  manager = { getSubscription: vi.fn().mockResolvedValue(null), subscribe: vi.fn().mockResolvedValue(subscription) };
  registration = { scope: new URL('/push/', window.location.origin).href, active: { state: 'activated' }, pushManager: manager };
  vi.stubGlobal('isSecureContext', true); vi.stubGlobal('Notification', permission); vi.stubGlobal('PushManager', function () {});
  Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: {
    register: vi.fn().mockResolvedValue(registration), getRegistration: vi.fn().mockResolvedValue(registration),
  } });
  api.defaults.adapter = async config => {
    calls.push(config);
    if (handler) { const value = handler(config); if (value !== undefined) return value; }
    if (config.url === csrfURL) return response(config, null, 204);
    if (config.url === '/auth/me') return account ? response(config, { data: account }) : reject(config, 401);
    if (config.url === '/auth/login') { account = sesion(); return response(config, { data: account }); }
    if (config.url === '/pacientes') return response(config, pacientes());
    if (config.url === '/recordatorios/proxima-cita') return response(config, { data: { cita: null, operacion: null } });
    if (config.url === '/recordatorios') {
      const p = config.params, data = listado(rows.slice((p.page - 1) * p.per_page, p.page * p.per_page), { ...p, total: rows.length });
      if (p.id_paciente) data.meta.paciente.id_paciente = p.id_paciente;
      return response(config, data);
    }
    if (config.url === '/push/configuracion') return response(config, { data: { habilitado: true,
      clave_publica_vapid: btoa(String.fromCharCode(...key)).replace(/=/g, '') } });
    if (config.url === '/push/suscripciones') return response(config, { data: subscriptions,
      meta: { current_page: 1, per_page: 20, total: subscriptions.length, last_page: 1 } });
    if (config.method === 'put' && config.url === '/push/suscripcion') {
      subscriptions = [{ id_suscripcion: '1', actual: true, activa: true, version: 1, autorizada_en: fecha, revocada_en: null }];
      manager.getSubscription.mockResolvedValue(subscription);
      return response(config, { data: subscriptions[0] }, 201);
    }
    if (config.method === 'delete' && config.url === '/push/suscripcion') {
      subscriptions = subscriptions.map(s => ({ ...s, activa: false, revocada_en: fecha }));
      return response(config, null, 204);
    }
    throw new Error(`Petición no prevista en la simulación: ${config.method} ${config.url}`);
  };
});
afterEach(() => { api.defaults.adapter = originalAdapter; vi.unstubAllGlobals(); delete navigator.serviceWorker; vi.restoreAllMocks(); });

it('Paciente: filtros/paginación/actualización con servicios reales, horario de sede y foco por teclado', async () => {
  rows = Array.from({ length: 21 }, (_, i) => aviso(`CITA-${i}`, String(i + 1)));
  const user = userEvent.setup(); mount(); await screen.findByText('CITA-0');
  expect(screen.getAllByText(/30\/10\/2026/).some(n => n.textContent.includes('22:30'))).toBe(true);
  const next = within(screen.getByRole('navigation', { name: 'Paginación de recordatorios' })).getByRole('button', { name: 'Siguiente' });
  next.focus(); await user.keyboard('{Enter}'); await screen.findByText('CITA-20');
  expect(screen.queryByText('CITA-0')).not.toBeInTheDocument();
  expect(screen.getByRole('region', { name: 'Resultados de recordatorios' })).toHaveFocus();
  await user.type(screen.getByLabelText('Desde'), '2026-10-30'); await user.type(screen.getByLabelText('Hasta'), '2026-11-01');
  await user.click(screen.getByRole('button', { name: 'Consultar', exact: true })); await screen.findByText('CITA-0');
  expect(calls.filter(c => c.url === '/recordatorios').at(-1).params).toEqual({ page: 1, per_page: 20, fecha_desde: '2026-10-30', fecha_hasta: '2026-11-01' });
  rows = []; await user.click(screen.getByRole('button', { name: 'Actualizar recordatorios' }));
  await screen.findByText('No hay recordatorios para estos filtros');
  expect(writes()).toEqual([]); expect(permission.requestPermission).not.toHaveBeenCalled();
  expect(calls.filter(c => c.url === '/recordatorios').every(c => c.sessionBound && !c.params.id_paciente)).toBe(true);
});

it('Administrador: cambio de paciente descarta respuesta atrasada y no accede a gestión push', async () => {
  account = sesion('Administrador'); let finish;
  handler = c => c.url === '/recordatorios' && c.params.id_paciente === '21'
    ? new Promise(resolve => { finish = () => resolve(response(c, listado([aviso('AJENO-ATRASADO')]))); }) : undefined;
  mount(); await userEvent.click(await screen.findByRole('button', { name: 'Seleccionar paciente Paciente De prueba' }));
  await waitFor(() => expect(finish).toBeTypeOf('function'));
  await userEvent.click(screen.getByRole('button', { name: 'Cambiar paciente' }));
  await userEvent.click(await screen.findByRole('button', { name: 'Seleccionar paciente Otra persona De prueba' }));
  await screen.findByText('CITA-FICTICIA'); await act(async () => finish());
  expect(screen.queryByText('AJENO-ATRASADO')).not.toBeInTheDocument();
  expect(calls.filter(c => c.url === '/recordatorios').map(c => c.params.id_paciente)).toEqual(['21', '22']);
  expect(calls.some(c => c.url.startsWith('/push/'))).toBe(false); expect(writes()).toEqual([]);
});

it('alta/baja explícitas: CSRF, proyección del consentimiento y baja parcial sin reintento automático', async () => {
  const user = userEvent.setup(); mount(); await screen.findByText('CITA-FICTICIA');
  const alta = screen.getByRole('button', { name: 'Autorizar notificaciones en este dispositivo' });
  await waitFor(() => expect(alta).toBeEnabled()); expect(writes()).toEqual([]);
  await user.click(alta); await screen.findByText(/confirmada para esta sesión y dispositivo/);
  expect(JSON.parse(writes()[0].data)).toEqual({ permiso_otorgado: true, suscripcion: json });
  expect(calls[calls.indexOf(writes()[0]) - 1].url).toBe(csrfURL);
  expect(permission.requestPermission).toHaveBeenCalledTimes(1);
  unsubscribe.mockResolvedValue(false);
  await user.click(screen.getByRole('button', { name: 'Desactivar este navegador' }));
  expect(writes()).toHaveLength(1);
  await user.click(screen.getByRole('button', { name: 'Confirmar revocación' }));
  await screen.findByText(/pero no se pudo confirmar la baja local/);
  expect(writes().map(c => [c.method, c.url])).toEqual([['put', '/push/suscripcion'], ['delete', '/push/suscripcion']]);
  expect(writes()[1].data).toBeUndefined(); expect(unsubscribe).toHaveBeenCalledTimes(1);
  expect(screen.getByText('CITA-FICTICIA')).toBeInTheDocument();
  expect(screen.queryByText(/se incorporarán en una fase posterior/)).not.toBeInTheDocument();
});

it('baja rechazada por 419 conserva suscripción local y avisos, sin replay ni detalles privados', async () => {
  handler = c => c.method === 'delete' ? reject(c, 419) : undefined;
  mount(); await screen.findByText('CITA-FICTICIA');
  const baja = screen.getByRole('button', { name: 'Desactivar este navegador' });
  await waitFor(() => expect(baja).toBeEnabled()); await userEvent.click(baja);
  await userEvent.click(screen.getByRole('button', { name: 'Confirmar revocación' }));
  await screen.findByText(/No se intentó la baja local/);
  expect(unsubscribe).not.toHaveBeenCalled(); expect(writes()).toHaveLength(1);
  expect(screen.queryByText(/DETALLE-PRIVADO/)).not.toBeInTheDocument(); expect(screen.getByText('CITA-FICTICIA')).toBeInTheDocument();
});

it('notificación antigua → login real del frontend → API vigente vacía; no registra dispositivos', async () => {
  account = null; rows = []; const { router } = mount(); const user = userEvent.setup();
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(calls.map(c => c.url)).toEqual(['/auth/me']);
  await user.type(screen.getByLabelText('Correo electrónico'), 'ficticia@example.test');
  await user.type(screen.getByLabelText('Contraseña', { exact: true }), 'Solo-ficticia-123');
  await user.click(screen.getByRole('button', { name: 'Iniciar sesión', exact: true }));
  await screen.findByText('No hay recordatorios para estos filtros'); expect(router.state.location.pathname).toBe('/recordatorios');
  expect(writes().map(c => c.url)).toEqual(['/auth/login']); expect(permission.requestPermission).not.toHaveBeenCalled();
});

it('401 del listado de dispositivos limpia también avisos: notificación no mantiene sesión ni acceso', async () => {
  mount(); await screen.findByText('CITA-FICTICIA');
  handler = c => c.url === '/push/suscripciones' ? reject(c, 401) : undefined;
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar dispositivos' }));
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(screen.queryByText('CITA-FICTICIA')).not.toBeInTheDocument(); expect(writes()).toEqual([]);
  expect(unsubscribe).not.toHaveBeenCalled();
});

it('cambio de cuenta comprobado por /me descarta incluso un 401 atrasado del titular anterior', async () => {
  let failOld;
  handler = c => c.url === '/recordatorios' ? new Promise((_resolve, rejectOld) => {
    failOld = () => rejectOld(new AxiosError('PRIVADO', undefined, c, null, response(c, {}, 401)));
  }) : undefined;
  mount(); await waitFor(() => expect(failOld).toBeTypeOf('function'));
  const old = calls.find(c => c.url === '/recordatorios');
  account = sesion('Paciente', '8'); rows = [aviso('TITULAR-ACTUAL')]; handler = null;
  act(() => window.dispatchEvent(new Event('focus')));
  await screen.findByText('TITULAR-ACTUAL'); expect(old.signal.aborted).toBe(true);
  await act(async () => failOld());
  expect(screen.getByText('TITULAR-ACTUAL')).toBeInTheDocument();
  expect(screen.queryByRole('heading', { name: 'Bienvenido de nuevo' })).not.toBeInTheDocument();
  expect(calls.filter(c => c.url === '/auth/me')).toHaveLength(2);
  expect(writes()).toEqual([]); expect(unsubscribe).not.toHaveBeenCalled();
  expect(permission.requestPermission).not.toHaveBeenCalled();
});
