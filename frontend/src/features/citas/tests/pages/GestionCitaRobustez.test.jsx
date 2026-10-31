import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { cerrarSesion, consultarSesion } from '../../../auth/services/auth.service';
import { alternativas, cancelada, cita, detalle, gestion, reprogramada, sesion } from '../utils/gestionFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const adapter = api.defaults.adapter;
const matriz = ['Administrador', 'Recepcionista'].flatMap(rol => ['cancelacion', 'reprogramacion'].map(accion => [rol, accion]));
const privado = 'MOTIVO-PRIVADO-FASE5';
let rol, calls, respond;
const ok = (config, data, status = 200) => ({ config, status, headers: {}, data });
const fallo = (config, status, seconds = '1') => new AxiosError('SQL-PRIVADO', undefined, config, null, status ? {
  status, headers: { 'retry-after': seconds }, data: { error: { code: 'DESCONOCIDO', message: 'SQL-PRIVADO' } } } : undefined);
function server(config) {
  if (config.url === csrfURL) return ok(config, {}, 204);
  if (config.method === 'patch') return ok(config, { data: config.url.endsWith('/cancelacion') ? cancelada() : reprogramada() });
  if (config.url.endsWith('/disponibilidad-reprogramacion')) return ok(config, alternativas(gestion(), config.params));
  if (config.url.endsWith('/gestion')) return ok(config, { data: gestion() });
  return ok(config, { data: detalle(rol) });
}
function mount() {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [`/citas/${cita.id_cita}/gestion`] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
async function revisar(accion) {
  if (accion === 'cancelacion') {
    await userEvent.click(await screen.findByRole('button', { name: 'Preparar cancelación' }));
    fireEvent.change(screen.getByLabelText('Motivo de cancelación (opcional)'), { target: { value: privado } });
    await userEvent.click(screen.getByRole('button', { name: 'Revisar cancelación' }));
    return screen.getByRole('button', { name: 'Confirmar cancelación' });
  }
  await screen.findByLabelText('Nueva fecha de atención');
  fireEvent.change(screen.getByLabelText('Nueva fecha de atención'), { target: { value: '2026-11-02' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar alternativas' }));
  await userEvent.click(await screen.findByRole('radio', { name: '08:00:00' }));
  await userEvent.click(screen.getByRole('button', { name: 'Revisar reprogramación' }));
  return screen.getByRole('button', { name: 'Confirmar reprogramación' });
}
beforeEach(() => {
  vi.resetAllMocks(); rol = 'Administrador'; calls = []; respond = server;
  consultarSesion.mockImplementation(async () => sesion(rol));
  api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = adapter; });

it.each(matriz)('%s %s: pagehide antes de terminar CSRF impide PATCH tardío', async (role, accion) => {
  rol = role; let complete, configCsrf;
  respond = config => config.url === csrfURL ? new Promise(resolve => { configCsrf = config; complete = () => resolve(ok(config, {}, 204)); }) : server(config);
  mount(); await userEvent.click(await revisar(accion)); await waitFor(() => expect(complete).toBeTypeOf('function'));
  await act(async () => window.dispatchEvent(new Event('pagehide'))); expect(configCsrf.signal.aborted).toBe(true);
  await act(async () => complete()); expect(calls.some(c => c.method === 'patch')).toBe(false);
  expect(screen.queryByDisplayValue(privado)).not.toBeInTheDocument();
  await act(async () => window.dispatchEvent(new Event('pageshow')));
  await screen.findByRole('button', { name: 'Consultar estado actual' }); expect(calls.some(c => c.method === 'patch')).toBe(false);
});
it.each(matriz)('%s %s: cambio de identidad durante CSRF aborta intención previa y no confirma bajo otro usuario', async (role, accion) => {
  rol = role; let complete, csrf;
  respond = config => config.url === csrfURL ? new Promise(resolve => { csrf = config; complete = () => resolve(ok(config, {}, 204)); }) : server(config);
  mount(); await userEvent.click(await revisar(accion)); await waitFor(() => expect(complete).toBeTypeOf('function'));
  consultarSesion.mockResolvedValue(sesion(role, '2')); await act(async () => window.dispatchEvent(new Event('focus')));
  await waitFor(() => expect(csrf.signal.aborted).toBe(true)); await act(async () => complete());
  await screen.findByRole('region', { name: 'Condiciones de gestión' }); expect(calls.some(c => c.method === 'patch')).toBe(false);
  expect(screen.queryByDisplayValue(privado)).not.toBeInTheDocument();
});
it.each(matriz)('%s %s: rol pasa a restringido durante revisión, limpia borrador sin escribir', async (role, accion) => {
  rol = role; mount(); await revisar(accion);
  consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
  await act(async () => window.dispatchEvent(new Event('focus'))); await screen.findByRole('heading', { name: 'Cambia tu contraseña' });
  expect(calls.every(c => c.method === 'get')).toBe(true); expect(screen.queryByDisplayValue(privado)).not.toBeInTheDocument();
});
it.each(matriz)('%s %s: sesión pasa a restringida durante CSRF, no se envía escritura tardía', async (role, accion) => {
  rol = role; let complete, csrf;
  respond = config => config.url === csrfURL ? new Promise(resolve => { csrf = config; complete = () => resolve(ok(config, {}, 204)); }) : server(config);
  mount(); await userEvent.click(await revisar(accion)); await waitFor(() => expect(complete).toBeTypeOf('function'));
  consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
  await act(async () => window.dispatchEvent(new Event('focus'))); await screen.findByRole('heading', { name: 'Cambia tu contraseña' });
  expect(csrf.signal.aborted).toBe(true); await act(async () => complete()); expect(calls.some(c => c.method === 'patch')).toBe(false);
  expect(screen.queryByDisplayValue(privado)).not.toBeInTheDocument();
});
it.each(matriz)('%s %s: respuesta PATCH después de pagehide nunca restaura éxito ni datos antiguos', async (role, accion) => {
  rol = role; let complete, patch;
  respond = config => config.method === 'patch' ? new Promise(resolve => { patch = config; complete = () => resolve(server(config)); }) : server(config);
  mount(); await userEvent.click(await revisar(accion)); await waitFor(() => expect(complete).toBeTypeOf('function'));
  await act(async () => window.dispatchEvent(new Event('pagehide'))); expect(patch.signal.aborted).toBe(true);
  await act(async () => complete()); await act(async () => window.dispatchEvent(new Event('pageshow')));
  await screen.findByRole('button', { name: 'Consultar estado actual' }); expect(screen.getByRole('main')).not.toHaveTextContent(privado);
  expect(screen.queryByText(/guardada\. La (misma )?cita/)).not.toBeInTheDocument(); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each(matriz)('%s %s: 408 tras PATCH exige GET y no reenvía al consultar estado sin cambio', async (role, accion) => {
  rol = role; respond = config => { if (config.method === 'patch') throw fallo(config, 408); return server(config); };
  mount(); await userEvent.click(await revisar(accion)); await screen.findByRole('button', { name: 'Consultar estado actual' });
  expect(screen.getByRole('main')).not.toHaveTextContent('SQL-PRIVADO');
  await userEvent.click(screen.getByRole('button', { name: 'Consultar estado actual' })); await screen.findByText(/Estado actual consultado. No demuestra/);
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1); expect(screen.getByRole('main')).not.toHaveTextContent(privado);
});
it.each(matriz)('%s %s: logout fallido durante PATCH no conserva busy ni acepta su respuesta de época anterior', async (role, accion) => {
  rol = role; let complete;
  respond = config => config.method === 'patch' ? new Promise(resolve => { complete = () => resolve(server(config)); }) : server(config);
  mount(); await userEvent.click(await revisar(accion)); await waitFor(() => expect(complete).toBeTypeOf('function'));
  cerrarSesion.mockRejectedValue(new Error('RED-LOGOUT')); await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
  await screen.findByText('No pudimos confirmar el cierre. Tu sesión aún podría estar activa. Vuelve a intentarlo.');
  await act(async () => complete()); await screen.findByRole('button', { name: 'Consultar estado actual' });
  expect(screen.queryByText('Guardando cancelación…')).not.toBeInTheDocument(); expect(screen.queryByText('Guardando reprogramación…')).not.toBeInTheDocument();
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each(['Administrador', 'Recepcionista'])('%s: logout fallido durante GET gestión sale de carga y permite reconsulta explícita', async role => {
  rol = role; const pending = [];
  respond = config => new Promise(resolve => pending.push(() => resolve(server(config))));
  mount(); await waitFor(() => expect(pending).toHaveLength(2));
  cerrarSesion.mockRejectedValue(new Error('RED-LOGOUT')); await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
  await screen.findByText('No pudimos confirmar el cierre. Tu sesión aún podría estar activa. Vuelve a intentarlo.');
  await act(async () => pending.forEach(done => done())); await screen.findByRole('button', { name: 'Volver a consultar' });
  expect(screen.queryByText('Consultando gestión de la cita…')).not.toBeInTheDocument();
  respond = server; await userEvent.click(screen.getByRole('button', { name: 'Volver a consultar' })); await screen.findByRole('region', { name: 'Condiciones de gestión' });
  expect(calls.every(c => c.method === 'get')).toBe(true);
});
it.each(['Administrador', 'Recepcionista'])('%s: logout fallido durante alternativas tampoco deja carga permanente', async role => {
  rol = role; let complete;
  respond = config => config.url.endsWith('/disponibilidad-reprogramacion') ? new Promise(resolve => { complete = () => resolve(server(config)); }) : server(config);
  mount(); await screen.findByLabelText('Nueva fecha de atención'); fireEvent.change(screen.getByLabelText('Nueva fecha de atención'), { target: { value: '2026-11-02' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar alternativas' })); await waitFor(() => expect(complete).toBeTypeOf('function'));
  cerrarSesion.mockRejectedValue(new Error('RED-LOGOUT')); await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
  await screen.findByText('No pudimos confirmar el cierre. Tu sesión aún podría estar activa. Vuelve a intentarlo.');
  await act(async () => complete()); await screen.findByRole('button', { name: 'Volver a consultar alternativas' });
  expect(screen.queryByText('Consultando alternativas…')).not.toBeInTheDocument(); respond = server;
  await userEvent.click(screen.getByRole('button', { name: 'Volver a consultar alternativas' })); await screen.findByRole('radio', { name: '08:00:00' });
  expect(calls.every(c => c.method === 'get')).toBe(true);
});
it.each(matriz)('%s %s: pagehide tras 429 mantiene espera visible y no deja otra operación habilitada', async (role, accion) => {
  rol = role; respond = config => { if (config.method === 'patch') throw fallo(config, 429, '2'); return server(config); };
  mount(); await userEvent.click(await revisar(accion)); await screen.findByRole('button', { name: 'Consultar gestión y comenzar una nueva revisión' });
  await act(async () => window.dispatchEvent(new Event('pagehide'))); await act(async () => window.dispatchEvent(new Event('pageshow')));
  await screen.findByText(/segundos antes de iniciar otra gestión/); expect(screen.queryByLabelText('Nueva fecha de atención')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Preparar cancelación' })).toBeDisabled();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Preparar cancelación' })).toBeEnabled(), { timeout: 4000 });
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1); expect(screen.queryByDisplayValue(privado)).not.toBeInTheDocument();
});
it.each(['Administrador', 'Recepcionista'])('%s: editar fecha tras 429 no evade Retry-After ni consulta automáticamente al terminar', async role => {
  rol = role; respond = config => { if (config.url.endsWith('/disponibilidad-reprogramacion')) throw fallo(config, 429, '2'); return server(config); };
  mount(); await screen.findByLabelText('Nueva fecha de atención');
  fireEvent.change(screen.getByLabelText('Nueva fecha de atención'), { target: { value: '2026-11-02' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar alternativas' })); await screen.findByRole('button', { name: 'Volver a consultar alternativas' });
  fireEvent.change(screen.getByLabelText('Nueva fecha de atención'), { target: { value: '2026-11-03' } });
  expect(screen.getByRole('button', { name: 'Consultar alternativas' })).toBeDisabled();
  fireEvent.submit(screen.getByRole('form', { name: 'Consultar alternativas de reprogramación' }));
  expect(calls.filter(c => c.url.endsWith('/disponibilidad-reprogramacion'))).toHaveLength(1);
  await waitFor(() => expect(screen.getByRole('button', { name: 'Consultar alternativas' })).toBeEnabled(), { timeout: 4000 });
  expect(calls.filter(c => c.url.endsWith('/disponibilidad-reprogramacion'))).toHaveLength(1);
  respond = server; await userEvent.click(screen.getByRole('button', { name: 'Consultar alternativas' })); await screen.findByRole('radio', { name: '08:00:00' });
  expect(calls.filter(c => c.url.endsWith('/disponibilidad-reprogramacion'))).toHaveLength(2);
});
it.each(['Administrador', 'Recepcionista'])('%s: respuesta tardía de fecha anterior se aborta y no habilita revisar un turno ajeno', async role => {
  rol = role; let complete, old;
  respond = config => config.url.endsWith('/disponibilidad-reprogramacion') && config.params.fecha === '2026-11-02'
    ? new Promise(resolve => { old = config; complete = () => resolve(server(config)); }) : server(config);
  mount(); await screen.findByLabelText('Nueva fecha de atención'); fireEvent.change(screen.getByLabelText('Nueva fecha de atención'), { target: { value: '2026-11-02' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar alternativas' })); await waitFor(() => expect(complete).toBeTypeOf('function'));
  fireEvent.change(screen.getByLabelText('Nueva fecha de atención'), { target: { value: '2026-11-03' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar alternativas' })); await screen.findByRole('radio', { name: '08:00:00' });
  expect(old.signal.aborted).toBe(true); await act(async () => complete());
  expect(screen.getByRole('radio', { name: '08:00:00' })).toHaveAttribute('value', '2026-11-03T12:00:00.000000Z');
  expect(screen.queryByRole('button', { name: 'Confirmar reprogramación' })).not.toBeInTheDocument();
});
