import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { cerrarSesion, consultarSesion } from '../../../auth/services/auth.service';
import { asistencia, ausenciaElegible, atencionElegible, llegada, inasistencia, atencion, cita, detalle, sesion } from '../utils/asistenciaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const adapter = api.defaults.adapter;
const operaciones = [
  ['llegada', asistencia, llegada, 'Confirmar llegada', null],
  ['inasistencia', ausenciaElegible, inasistencia, 'Registrar inasistencia', 'Preparar inasistencia'],
  ['atención', atencionElegible, atencion, 'Marcar como Atendida', 'Preparar atención'],
];
const matriz = operaciones.flatMap(op => (op[0] === 'atención' ? ['Administrador', 'Médico'] : ['Administrador', 'Recepcionista']).map(rol => [rol, ...op]));
let rol, op, calls, respond, idActual;
const ok = (config, data, status = 200) => ({ config, status, headers: {}, data });
const fallo = (config, status, seconds = '1') => new AxiosError('SQL-PRIVADO', undefined, config, null,
  status ? { status, headers: { 'retry-after': seconds }, data: { error: { code: 'DESCONOCIDO', message: 'NO-SALIR' } } } : undefined);
function server(config) {
  if (config.url === csrfURL) return ok(config, {}, 204);
  const original = { ...op[1](rol), id_cita: idActual };
  if (config.method === 'patch') return ok(config, { data: op[2](original) });
  return ok(config, { data: config.url.endsWith('/asistencia') ? original : { ...detalle(rol), id_cita: idActual } });
}
function mount() {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [`/citas/${cita.id_cita}/asistencia`] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
async function revisar() {
  if (op[4]) await userEvent.click(await screen.findByRole('button', { name: op[4] }));
  const checkbox = await screen.findByRole('checkbox'); await userEvent.click(checkbox);
  await userEvent.click(screen.getByRole('button', { name: `Revisar ${op[0]}` }));
  return screen.getByRole('button', { name: op[3] });
}
function configurar(role, nombre) { rol = role; op = operaciones.find(o => o[0] === nombre); }
beforeEach(() => {
  vi.resetAllMocks(); rol = 'Administrador'; op = operaciones[0]; idActual = cita.id_cita; calls = []; respond = server;
  consultarSesion.mockImplementation(async () => sesion(rol)); api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = adapter; });

it.each(matriz)('%s %s: pagehide antes de completar CSRF impide PATCH tardío', async (role, nombre) => {
  configurar(role, nombre); let complete, csrf;
  respond = config => config.url === csrfURL ? new Promise(resolve => { csrf = config; complete = () => resolve(server(config)); }) : server(config);
  mount(); await userEvent.click(await revisar()); await waitFor(() => expect(complete).toBeTypeOf('function'));
  await act(async () => window.dispatchEvent(new Event('pagehide'))); expect(csrf.signal.aborted).toBe(true);
  await act(async () => complete()); await act(async () => window.dispatchEvent(new Event('pageshow')));
  await screen.findByRole('button', { name: 'Consultar estado actual' }); expect(calls.some(c => c.method === 'patch')).toBe(false);
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});
it.each(matriz)('%s %s: cambio de usuario durante CSRF aborta intención anterior', async (role, nombre) => {
  configurar(role, nombre); let complete, csrf;
  respond = config => config.url === csrfURL ? new Promise(resolve => { csrf = config; complete = () => resolve(server(config)); }) : server(config);
  mount(); await userEvent.click(await revisar()); await waitFor(() => expect(complete).toBeTypeOf('function'));
  consultarSesion.mockResolvedValue(sesion(role, '2')); await act(async () => window.dispatchEvent(new Event('focus')));
  await waitFor(() => expect(csrf.signal.aborted).toBe(true)); await act(async () => complete());
  await screen.findByRole('region', { name: 'Condiciones de asistencia' }); expect(calls.some(c => c.method === 'patch')).toBe(false);
  expect(screen.queryByRole('button', { name: op[3] })).not.toBeInTheDocument();
});
it.each(matriz)('%s %s: sesión restringida durante revisión borra declaración', async (role, nombre) => {
  configurar(role, nombre); mount(); await revisar(); consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
  await act(async () => window.dispatchEvent(new Event('focus'))); await screen.findByRole('heading', { name: 'Cambia tu contraseña' });
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument(); expect(calls.every(c => c.method === 'get')).toBe(true);
});
it.each(matriz)('%s %s: sesión revocada durante CSRF no envía operación tardía', async (role, nombre) => {
  configurar(role, nombre); let complete, csrf;
  respond = config => config.url === csrfURL ? new Promise(resolve => { csrf = config; complete = () => resolve(server(config)); }) : server(config);
  mount(); await userEvent.click(await revisar()); await waitFor(() => expect(complete).toBeTypeOf('function'));
  consultarSesion.mockRejectedValue({ response: { status: 401 } }); await act(async () => window.dispatchEvent(new Event('focus')));
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(csrf.signal.aborted).toBe(true);
  await act(async () => complete()); expect(calls.some(c => c.method === 'patch')).toBe(false);
});
it.each(matriz)('%s %s: PATCH tardío tras pagehide no restaura éxito', async (role, nombre) => {
  configurar(role, nombre); let complete, patch;
  respond = config => config.method === 'patch' ? new Promise(resolve => { patch = config; complete = () => resolve(server(config)); }) : server(config);
  mount(); await userEvent.click(await revisar()); await waitFor(() => expect(complete).toBeTypeOf('function'));
  await act(async () => window.dispatchEvent(new Event('pagehide'))); expect(patch.signal.aborted).toBe(true);
  await act(async () => complete()); await act(async () => window.dispatchEvent(new Event('pageshow')));
  await screen.findByRole('button', { name: 'Consultar estado actual' }); expect(screen.queryByText(/Llegada guardada|Inasistencia guardada|Atención guardada/)).not.toBeInTheDocument();
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each(matriz)('%s %s: 408 se recupera por GET sin cambio, sin replay ni declaración retenida', async (role, nombre) => {
  configurar(role, nombre); respond = config => { if (config.method === 'patch') throw fallo(config, 408); return server(config); };
  mount(); await userEvent.click(await revisar()); await screen.findByRole('button', { name: 'Consultar estado actual' });
  expect(screen.getByRole('main')).not.toHaveTextContent(/SQL-PRIVADO|NO-SALIR/);
  await userEvent.click(screen.getByRole('button', { name: 'Consultar estado actual' })); await screen.findByText(/Estado actual consultado. No demuestra/);
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument(); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
  await userEvent.click(screen.getByRole('button', { name: 'Consultar asistencia y comenzar una nueva revisión' }));
  if (op[4]) await userEvent.click(await screen.findByRole('button', { name: op[4] }));
  expect(await screen.findByRole('checkbox')).not.toBeChecked();
});
it.each(matriz)('%s %s: logout fallido durante PATCH sale de busy a incierto', async (role, nombre) => {
  configurar(role, nombre); let complete;
  respond = config => config.method === 'patch' ? new Promise(resolve => { complete = () => resolve(server(config)); }) : server(config);
  mount(); await userEvent.click(await revisar()); await waitFor(() => expect(complete).toBeTypeOf('function'));
  cerrarSesion.mockRejectedValue(new Error('RED-LOGOUT')); await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
  await screen.findByText('No pudimos confirmar el cierre. Tu sesión aún podría estar activa. Vuelve a intentarlo.');
  await act(async () => complete()); await screen.findByRole('button', { name: 'Consultar estado actual' });
  expect(screen.queryByText(`Guardando ${nombre}…`)).not.toBeInTheDocument(); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each(matriz)('%s %s: logout fallido durante CSRF termina sin PATCH ni reenvío', async (role, nombre) => {
  configurar(role, nombre); let complete;
  respond = config => config.url === csrfURL ? new Promise(resolve => { complete = () => resolve(server(config)); }) : server(config);
  mount(); await userEvent.click(await revisar()); await waitFor(() => expect(complete).toBeTypeOf('function'));
  cerrarSesion.mockRejectedValue(new Error('RED-LOGOUT')); await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
  await screen.findByText('No pudimos confirmar el cierre. Tu sesión aún podría estar activa. Vuelve a intentarlo.'); await act(async () => complete());
  await screen.findByText(new RegExp(`No se envió la ${nombre}`)); expect(calls.some(c => c.method === 'patch')).toBe(false);
});
it.each(['Administrador', 'Recepcionista', 'Médico'])('%s: logout fallido durante GET asistencia no deja carga permanente', async role => {
  rol = role; const pending = []; respond = config => new Promise(resolve => pending.push(() => resolve(server(config))));
  mount(); await waitFor(() => expect(pending).toHaveLength(2));
  cerrarSesion.mockRejectedValue(new Error('RED-LOGOUT')); await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
  await screen.findByText('No pudimos confirmar el cierre. Tu sesión aún podría estar activa. Vuelve a intentarlo.');
  await act(async () => pending.forEach(done => done())); await screen.findByRole('button', { name: 'Volver a consultar' });
  expect(screen.queryByText('Consultando asistencia…')).not.toBeInTheDocument(); respond = server;
  await userEvent.click(screen.getByRole('button', { name: 'Volver a consultar' })); await screen.findByRole('region', { name: 'Condiciones de asistencia' });
  expect(calls.every(c => c.method === 'get')).toBe(true);
});
it.each(matriz)('%s %s: 429/pagehide conserva espera y no prepara otra escritura', async (role, nombre) => {
  configurar(role, nombre); respond = config => { if (config.method === 'patch') throw fallo(config, 429, '2'); return server(config); };
  mount(); await userEvent.click(await revisar()); await screen.findByRole('button', { name: 'Consultar asistencia y comenzar una nueva revisión' });
  await act(async () => window.dispatchEvent(new Event('pagehide'))); await act(async () => window.dispatchEvent(new Event('pageshow')));
  await screen.findByText(/segundos antes de preparar otra operación/); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /Preparar atención|Preparar inasistencia/ })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Actualizar asistencia' })).toBeDisabled();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Actualizar asistencia' })).toBeEnabled(), { timeout: 4000 });
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each(matriz)('%s %s: cambio de cita durante revisión limpia declaración y originales', async (role, nombre) => {
  configurar(role, nombre); const router = mount(); await revisar(); idActual = '777';
  await act(async () => router.navigate('/citas/777/asistencia')); await screen.findByRole('region', { name: 'Condiciones de asistencia' });
  if (op[4]) await userEvent.click(screen.getByRole('button', { name: op[4] }));
  expect(screen.getByRole('checkbox')).not.toBeChecked(); expect(screen.queryByRole('button', { name: op[3] })).not.toBeInTheDocument();
  expect(calls.every(c => c.method === 'get')).toBe(true);
});
it.each(['Administrador', 'Recepcionista', 'Médico'])('%s: GET de cita anterior se aborta y no sustituye nueva consulta', async role => {
  rol = role; let complete; const previous = [];
  respond = config => new Promise(resolve => { const value = server(config); previous.push(config); (complete ??= []).push(() => resolve(value)); });
  const router = mount(); await waitFor(() => expect(previous).toHaveLength(2));
  idActual = '777'; respond = server; await act(async () => router.navigate('/citas/777/asistencia'));
  await screen.findByRole('region', { name: 'Condiciones de asistencia' }); expect(previous.every(c => c.signal.aborted)).toBe(true);
  await act(async () => complete.forEach(done => done()));
  expect(calls.filter(c => c.url.includes('/777/'))).toHaveLength(1);
  // La etiqueta coincide, pero no se conserva la declaración ni el recurso de la ruta previa.
  expect(screen.queryByRole('button', { name: 'Confirmar llegada' })).not.toBeInTheDocument();
});
it.each(matriz)('%s %s: navegación durante PATCH advierte y salir aborta sin afirmar rollback', async (role, nombre) => {
  configurar(role, nombre); let complete, patch;
  respond = config => config.method === 'patch' ? new Promise(resolve => { patch = config; complete = () => resolve(server(config)); }) : server(config);
  mount(); const confirm = await revisar(); fireEvent.click(confirm); fireEvent.click(confirm);
  await waitFor(() => expect(complete).toBeTypeOf('function')); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
  await userEvent.click(screen.getByRole('link', { name: 'Volver al detalle de la cita' })); await screen.findByText(/Salir no revierte la operación/);
  await userEvent.click(screen.getByRole('button', { name: 'Permanecer y consultar' })); expect(patch.signal.aborted).toBe(false);
  await userEvent.click(screen.getByRole('link', { name: 'Volver al detalle de la cita' }));
  await userEvent.click(screen.getByRole('button', { name: 'Salir sin conservar la comprobación' })); expect(patch.signal.aborted).toBe(true);
  await act(async () => complete()); expect(screen.queryByRole('region', { name: `Resultado de ${nombre}` })).not.toBeInTheDocument();
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1); expect(localStorage.length + sessionStorage.length).toBe(0);
});

it.each(matriz)('%s %s: cambio a rol Paciente durante PATCH limpia datos y descarta resultado previo', async (role, nombre) => {
  configurar(role, nombre); let complete, patch;
  respond = config => config.method === 'patch' ? new Promise(resolve => { patch = config; complete = () => resolve(server(config)); }) : server(config);
  mount(); await userEvent.click(await revisar()); await waitFor(() => expect(complete).toBeTypeOf('function'));
  consultarSesion.mockResolvedValue(sesion('Paciente')); await act(async () => window.dispatchEvent(new Event('focus')));
  await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(patch.signal.aborted).toBe(true);
  await act(async () => complete()); expect(screen.queryByRole('region', { name: `Resultado de ${nombre}` })).not.toBeInTheDocument();
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument(); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each(matriz)('%s %s: recuperación rechazada conserva bloqueo y permite solo otra consulta explícita', async (role, nombre) => {
  configurar(role, nombre); let recuperar = false;
  respond = config => { if (config.method === 'patch') throw fallo(config, 500); if (recuperar) throw fallo(config, 404); return server(config); };
  mount(); await userEvent.click(await revisar()); await screen.findByRole('button', { name: 'Consultar estado actual' }); recuperar = true;
  await userEvent.click(screen.getByRole('button', { name: 'Consultar estado actual' }));
  await waitFor(() => expect(screen.getByRole('region', { name: `Resultado de ${nombre}` })).not.toHaveAttribute('aria-busy', 'true'));
  expect(screen.getByRole('button', { name: 'Consultar estado actual' })).toBeEnabled();
  expect(screen.queryByRole('button', { name: 'Consultar asistencia y comenzar una nueva revisión' })).not.toBeInTheDocument();
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  expect(screen.queryByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).not.toBeInTheDocument();
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1); expect(screen.getByRole('main')).not.toHaveTextContent(/SQL-PRIVADO|NO-SALIR/);
});
