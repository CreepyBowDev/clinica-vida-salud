import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, it, vi } from 'vitest';
import { AuthContext } from '../../../auth/context/AuthContext';
import { avanzarSesion } from '../../../../api/api';
import DispositivosPush from '../../components/DispositivosPush';
import { listarSuscripcionesPush, revocarSuscripcionPush } from '../../services/push.service';
import { desuscribirNavegador } from '../../utils/pushBrowser';

vi.mock('../../services/push.service', async original => ({ ...await original(), listarSuscripcionesPush: vi.fn(), revocarSuscripcionPush: vi.fn() }));
vi.mock('../../utils/pushBrowser', async original => ({ ...await original(), desuscribirNavegador: vi.fn() }));
vi.mock('../../components/AltaPush', () => ({ default: ({ bloqueado, onOcupado, onAutorizado }) => <div>
  <button disabled={bloqueado} onClick={() => onOcupado(true)}>Alta simulada</button>
  <button onClick={() => { onOcupado(false); onAutorizado(); }}>Finalizar alta simulada</button>
</div> }));
const fecha = '2026-10-31T12:00:00.000001Z';
const listado = () => ({ data: [
  { id_suscripcion: '1', actual: true, activa: true, version: 1, autorizada_en: fecha, revocada_en: null },
  { id_suscripcion: '2', actual: false, activa: true, version: 3, autorizada_en: fecha, revocada_en: null },
  { id_suscripcion: '3', actual: false, activa: false, version: 2, autorizada_en: fecha, revocada_en: fecha },
], meta: { current_page: 1, per_page: 20, total: 3, last_page: 1 } });
const view = (id = '7', rol = 'Paciente', status = 'authenticated') => <AuthContext.Provider value={{ status, usuario: { id_usuario: id, rol } }}><DispositivosPush /></AuthContext.Provider>;
const baja = () => screen.getByRole('button', { name: 'Desactivar este navegador' });
const confirmar = () => screen.getByRole('button', { name: 'Confirmar revocación' });
const otro = () => screen.getByRole('button', { name: 'Revocar dispositivo 2' });
async function ready() { await screen.findByRole('heading', { name: 'Dispositivo 2' }); }
beforeEach(() => {
  vi.clearAllMocks(); listarSuscripcionesPush.mockResolvedValue(listado()); revocarSuscripcionPush.mockResolvedValue(); desuscribirNavegador.mockResolvedValue();
});
it('lista campos públicos, identifica actual y no realiza bajas al montar', async () => {
  render(view()); await ready(); expect(screen.getByText(/Dispositivo 1 · Este navegador/)).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Revocar dispositivo 3' })).toBeDisabled();
  expect(screen.getByText('Estado: Activo · Versión: 3')).toBeInTheDocument();
  expect(revocarSuscripcionPush).not.toHaveBeenCalled(); expect(desuscribirNavegador).not.toHaveBeenCalled();
});
it('exige confirmación explícita y permite conservar sin efectos', async () => {
  render(view()); await ready(); await userEvent.click(baja()); expect(revocarSuscripcionPush).not.toHaveBeenCalled();
  expect(screen.getByRole('button', { name: 'Alta simulada' })).toBeDisabled();
  await userEvent.click(screen.getByRole('button', { name: 'Conservar dispositivo' }));
  expect(screen.queryByRole('button', { name: 'Confirmar revocación' })).not.toBeInTheDocument(); expect(desuscribirNavegador).not.toHaveBeenCalled();
});
it('teclado: enfoca confirmación y devuelve el foco al disparador al conservar', async () => {
  const user = userEvent.setup(); render(view()); await ready();
  otro().focus(); await user.keyboard('{Enter}');
  expect(confirmar()).toHaveFocus();
  await user.tab(); expect(screen.getByRole('button', { name: 'Conservar dispositivo' })).toHaveFocus();
  await user.keyboard('{Enter}'); expect(otro()).toHaveFocus();
  expect(revocarSuscripcionPush).not.toHaveBeenCalled();
});
it('al confirmar baja pendiente conserva foco en el encabezado, no en un botón eliminado', async () => {
  let resolve; revocarSuscripcionPush.mockReturnValueOnce(new Promise(yes => { resolve = yes; }));
  const user = userEvent.setup(); render(view()); await ready();
  baja().focus(); await user.keyboard('{Enter}'); expect(confirmar()).toHaveFocus();
  await user.keyboard('{Enter}'); expect(screen.getByRole('heading', { name: 'Mis dispositivos' })).toHaveFocus();
  await act(async () => resolve()); await screen.findByText(/baja local confirmada en este navegador/);
});
it('revoca actual primero en servidor y después baja local, sin doble envío', async () => {
  let resolve; revocarSuscripcionPush.mockReturnValue(new Promise(yes => { resolve = yes; }));
  render(view()); await ready(); await userEvent.click(baja()); await userEvent.dblClick(confirmar());
  expect(revocarSuscripcionPush).toHaveBeenCalledWith(null, expect.any(AbortSignal), expect.any(Number));
  expect(desuscribirNavegador).not.toHaveBeenCalled(); expect(baja()).toBeDisabled();
  await act(async () => resolve()); await screen.findByText(/Revocación confirmada en el servidor y baja local confirmada/);
  expect(revocarSuscripcionPush).toHaveBeenCalledTimes(1); expect(desuscribirNavegador).toHaveBeenCalledTimes(1);
  expect(listarSuscripcionesPush).toHaveBeenCalledTimes(2);
});
it('revoca otro propio sin tocar suscripción del navegador actual', async () => {
  render(view()); await ready(); await userEvent.click(otro()); await userEvent.click(confirmar());
  await screen.findByText(/No se ha modificado el navegador del otro/);
  expect(revocarSuscripcionPush).toHaveBeenCalledWith('2', expect.any(AbortSignal), expect.any(Number)); expect(desuscribirNavegador).not.toHaveBeenCalled();
});
it('comunica servidor confirmado/local fallido y permite reintento explícito', async () => {
  desuscribirNavegador.mockRejectedValueOnce(new Error('SQL-SECRETO'));
  render(view()); await ready(); await userEvent.click(baja()); await userEvent.click(confirmar());
  await screen.findByText(/pero no se pudo confirmar la baja local/); expect(screen.queryByText(/SQL-SECRETO/)).not.toBeInTheDocument();
  await waitFor(() => expect(baja()).toBeEnabled()); await userEvent.click(baja()); await userEvent.click(confirmar());
  await screen.findByText(/baja local confirmada en este navegador/); expect(desuscribirNavegador).toHaveBeenCalledTimes(2);
});
it.each([401, 403, 404, 409, 419, 422, 429, 500, undefined])('baja HTTP %s no confirma ni toca navegador ni reintenta', async status => {
  revocarSuscripcionPush.mockRejectedValue({ response: { status, data: { error: { message: 'SQL-SECRETO' } } } });
  render(view()); await ready(); await userEvent.click(baja()); await userEvent.click(confirmar());
  await screen.findByText(/No se intentó la baja local/);
  expect(desuscribirNavegador).not.toHaveBeenCalled(); expect(revocarSuscripcionPush).toHaveBeenCalledTimes(1); expect(screen.queryByText(/SQL-SECRETO/)).not.toBeInTheDocument();
});
it('recarga vacíos y errores sin bloquear baja actual o filtrar detalles', async () => {
  listarSuscripcionesPush.mockRejectedValueOnce(new Error('SECRETO'));
  render(view()); await screen.findByRole('alert'); expect(baja()).toBeEnabled();
  listarSuscripcionesPush.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } });
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar dispositivos' })); await screen.findByText(/No hay suscripciones/);
  expect(screen.queryByText(/SECRETO/)).not.toBeInTheDocument(); expect(baja()).toBeEnabled();
});
it('pagina con API y descarta la lista anterior', async () => {
  const r = listado(); r.meta.total = 21; r.meta.last_page = 2; listarSuscripcionesPush.mockResolvedValueOnce(r);
  render(view()); await ready(); await userEvent.click(within(screen.getByRole('navigation', { name: 'Paginación de dispositivos' })).getByRole('button', { name: 'Siguiente' }));
  await waitFor(() => expect(listarSuscripcionesPush).toHaveBeenLastCalledWith(2, expect.any(AbortSignal), expect.any(Number)));
});
it.each(['Administrador', 'Médico', 'Recepcionista'])('sin gestión ni consultas para %s', rol => {
  render(view('7', rol)); expect(screen.queryByRole('heading')).not.toBeInTheDocument(); expect(listarSuscripcionesPush).not.toHaveBeenCalled();
});
it('no consulta sin sesión', () => { render(view('7', 'Paciente', 'anonymous')); expect(listarSuscripcionesPush).not.toHaveBeenCalled(); });
it('alta y baja se excluyen; alta confirmada actualiza listado', async () => {
  render(view()); await ready(); await userEvent.click(screen.getByRole('button', { name: 'Alta simulada' })); expect(baja()).toBeDisabled(); expect(otro()).toBeDisabled();
  await userEvent.click(screen.getByRole('button', { name: 'Finalizar alta simulada' })); await waitFor(() => expect(listarSuscripcionesPush).toHaveBeenCalledTimes(2));
});
it.each(['epoca', 'titular', 'salida', 'pagehide'])('respuesta de lista atrasada tras %s no revela dispositivos', async kind => {
  let resolve; listarSuscripcionesPush.mockReturnValueOnce(new Promise(yes => { resolve = yes; }));
  const rendered = render(view());
  if (kind === 'epoca') act(() => avanzarSesion());
  if (kind === 'titular') { listarSuscripcionesPush.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } }); rendered.rerender(view('8')); }
  if (kind === 'salida') rendered.unmount();
  if (kind === 'pagehide') act(() => window.dispatchEvent(new Event('pagehide')));
  await act(async () => resolve(listado())); expect(screen.queryByRole('heading', { name: 'Dispositivo 2' })).not.toBeInTheDocument();
  expect(revocarSuscripcionPush).not.toHaveBeenCalled(); expect(desuscribirNavegador).not.toHaveBeenCalled();
});
it.each(['epoca', 'titular', 'salida', 'pagehide'])('baja servidor pendiente tras %s no toca navegador de otra sesión ni publica éxito', async kind => {
  let resolve; revocarSuscripcionPush.mockReturnValue(new Promise(yes => { resolve = yes; }));
  const rendered = render(view()); await ready(); await userEvent.click(baja()); await userEvent.click(confirmar());
  const signal = revocarSuscripcionPush.mock.calls[0][1];
  if (kind === 'epoca') act(() => avanzarSesion());
  if (kind === 'titular') rendered.rerender(view('8'));
  if (kind === 'salida') rendered.unmount();
  if (kind === 'pagehide') act(() => window.dispatchEvent(new Event('pagehide')));
  await act(async () => resolve()); expect(signal.aborted).toBe(true); expect(desuscribirNavegador).not.toHaveBeenCalled();
  expect(screen.queryByText(/Revocación confirmada/)).not.toBeInTheDocument(); expect(revocarSuscripcionPush).toHaveBeenCalledTimes(1);
});
it('cambio de sesión con baja local en vuelo descarta resultado y no compensa/reautoriza', async () => {
  let resolve; desuscribirNavegador.mockReturnValue(new Promise(yes => { resolve = yes; }));
  render(view()); await ready(); await userEvent.click(baja()); await userEvent.click(confirmar());
  await waitFor(() => expect(desuscribirNavegador).toHaveBeenCalledTimes(1)); act(() => avanzarSesion());
  await act(async () => resolve()); expect(screen.queryByText(/Revocación confirmada/)).not.toBeInTheDocument(); expect(revocarSuscripcionPush).toHaveBeenCalledTimes(1);
});
it.each(['epoca', 'pagehide', 'desmontar'])('no revoca por expiración/salida %s', async kind => {
  const r = render(view()); await ready();
  if (kind === 'epoca') act(() => avanzarSesion());
  if (kind === 'pagehide') act(() => window.dispatchEvent(new Event('pagehide')));
  if (kind === 'desmontar') r.unmount();
  expect(revocarSuscripcionPush).not.toHaveBeenCalled(); expect(desuscribirNavegador).not.toHaveBeenCalled();
});
