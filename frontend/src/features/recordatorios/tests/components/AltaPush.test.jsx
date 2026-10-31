import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AuthContext } from '../../../auth/context/AuthContext';
import { avanzarSesion } from '../../../../api/api';
import AltaPush from '../../components/AltaPush';
import RecordatoriosPage from '../../pages/RecordatoriosPage';
import { listarRecordatorios } from '../../services/recordatorio.service';
import { listado } from '../utils/recordatorioFixtures';
import { autorizarPush, consultarConfiguracionPush } from '../../services/push.service';

vi.mock('../../services/push.service', async importOriginal => ({ ...await importOriginal(), autorizarPush: vi.fn(), consultarConfiguracionPush: vi.fn(), listarSuscripcionesPush: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } }) }));
vi.mock('../../services/recordatorio.service', () => ({ listarRecordatorios: vi.fn() }));
const key = Uint8Array.from([4, ...Array(64).fill(1)]);
const config = { habilitado: true, clave_publica_vapid: btoa(String.fromCharCode(...key)).replace(/=/g, '') };
const json = { endpoint: 'https://push.example.test/ficticio', expirationTime: null, keys: { p256dh: 'a'.repeat(87), auth: 'b'.repeat(22) } };
let notification, manager, register, subscription;
const view = (id = '7', rol = 'Paciente', status = 'authenticated') => <AuthContext.Provider value={{ status, usuario: { id_usuario: id, rol } }}><AltaPush /></AuthContext.Provider>;
const button = () => screen.getByRole('button', { name: 'Autorizar notificaciones en este dispositivo' });
async function ready() { await waitFor(() => expect(button()).toBeEnabled()); }
beforeEach(() => {
  vi.clearAllMocks();
  notification = { permission: 'default', requestPermission: vi.fn(async () => { notification.permission = 'granted'; return 'granted'; }) };
  subscription = { options: { applicationServerKey: key.buffer }, toJSON: () => json };
  manager = { getSubscription: vi.fn().mockResolvedValue(null), subscribe: vi.fn().mockResolvedValue(subscription) };
  register = vi.fn().mockResolvedValue({ active: { state: 'activated' }, pushManager: manager });
  vi.stubGlobal('isSecureContext', true); vi.stubGlobal('Notification', notification); vi.stubGlobal('PushManager', function () {});
  Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: { register } });
  consultarConfiguracionPush.mockResolvedValue(config); autorizarPush.mockResolvedValue({ activa: true });
});
afterEach(() => { vi.unstubAllGlobals(); delete navigator.serviceWorker; });
it('solo lee configuración al montar; permiso, SW y alta únicamente al pulsar explícitamente', async () => {
  render(view()); await ready();
  expect(notification.requestPermission).not.toHaveBeenCalled(); expect(register).not.toHaveBeenCalled(); expect(autorizarPush).not.toHaveBeenCalled();
  await userEvent.click(button()); await screen.findByText(/confirmada para esta sesión/);
  expect(notification.requestPermission).toHaveBeenCalledTimes(1);
  expect(register).toHaveBeenCalledWith('/push/sw.js', { scope: '/push/', updateViaCache: 'none' });
  expect(manager.subscribe).toHaveBeenCalledWith({ userVisibleOnly: true, applicationServerKey: key });
  expect(autorizarPush).toHaveBeenCalledWith(json, expect.any(AbortSignal), expect.any(Number));
  expect(button()).toBeDisabled();
});
it('permiso concedido no implica autorización del servidor; reutiliza suscripción solo por acción explícita', async () => {
  notification.permission = 'granted'; manager.getSubscription.mockResolvedValue(subscription);
  render(view()); await ready(); expect(screen.getByRole('status')).toHaveTextContent('no comprobada');
  expect(autorizarPush).not.toHaveBeenCalled(); await userEvent.click(button()); await screen.findByText(/confirmada para esta sesión/);
  expect(manager.subscribe).not.toHaveBeenCalled();
});
it.each(['denied', 'default'])('permiso resultante %s no registra SW ni alta', async permission => {
  notification.requestPermission.mockImplementation(async () => { notification.permission = permission; return permission; });
  render(view()); await ready(); await userEvent.click(button());
  await screen.findByText(permission === 'denied' ? /Permiso denegado\./ : /No concediste permiso/);
  expect(register).not.toHaveBeenCalled(); expect(autorizarPush).not.toHaveBeenCalled();
});
it('permiso denegado previo no solicita permiso de nuevo', async () => {
  notification.permission = 'denied'; render(view()); await screen.findByText(/Revisa el permiso/);
  expect(button()).toBeDisabled(); expect(notification.requestPermission).not.toHaveBeenCalled();
});
it.each(['inseguro', 'sinSW', 'sinPush', 'sinNotification'])('incompatibilidad %s conserva mensaje sin API ni permiso', async kind => {
  if (kind === 'inseguro') vi.stubGlobal('isSecureContext', false);
  if (kind === 'sinSW') delete navigator.serviceWorker;
  if (kind === 'sinPush') delete window.PushManager;
  if (kind === 'sinNotification') delete window.Notification;
  render(view()); expect(screen.getByText(kind === 'inseguro' ? /contexto seguro/ : /no admite Web Push/)).toBeInTheDocument();
  expect(consultarConfiguracionPush).not.toHaveBeenCalled(); expect(notification.requestPermission).not.toHaveBeenCalled();
});
it('canal deshabilitado nunca pide permiso', async () => {
  consultarConfiguracionPush.mockResolvedValue({ habilitado: false, clave_publica_vapid: null });
  render(view()); await screen.findByText(/canal de notificaciones.*deshabilitado/); expect(button()).toBeDisabled(); expect(register).not.toHaveBeenCalled();
});
it.each(['Administrador', 'Médico', 'Recepcionista'])('no muestra ni consulta para %s', rol => {
  render(view('7', rol)); expect(screen.queryByRole('heading')).not.toBeInTheDocument(); expect(consultarConfiguracionPush).not.toHaveBeenCalled();
});
it('no consulta ni autoriza sin autenticación', () => {
  render(view('7', 'Paciente', 'guest')); expect(consultarConfiguracionPush).not.toHaveBeenCalled(); expect(register).not.toHaveBeenCalled();
});
it('fallo de configuración permite reconsulta explícita sin solicitar permiso', async () => {
  consultarConfiguracionPush.mockRejectedValueOnce(new Error('SQL-SECRETO')); render(view()); await screen.findByText(/No pudimos consultar la configuración/);
  await userEvent.click(screen.getByRole('button', { name: 'Consultar configuración del dispositivo' })); await ready();
  expect(notification.requestPermission).not.toHaveBeenCalled(); expect(screen.queryByText(/SQL-SECRETO/)).not.toBeInTheDocument();
});
it.each([401, 403, 409, 419, 422, 429, 500, undefined, 503])('fallo de alta %s no confirma ni reintenta ni expone errores técnicos', async status => {
  autorizarPush.mockRejectedValue({ response: status ? { status, data: { error: { code: status === 503 ? 'PUSH_NO_CONFIGURADO' : 'ERROR', message: 'SQL-SECRETO' } } } : undefined });
  render(view()); await ready(); await userEvent.click(button());
  await waitFor(() => expect(autorizarPush).toHaveBeenCalledTimes(1)); await waitFor(() => expect(button()).toBeEnabled());
  expect(screen.getAllByRole('status')[0]).toHaveTextContent('no comprobada'); expect(screen.queryByText(/SQL-SECRETO/)).not.toBeInTheDocument();
  expect(manager.subscribe).toHaveBeenCalledTimes(1);
});
it.each(['SW', 'subscribe', 'clave'])('fallo de navegador %s no llama al alta', async kind => {
  if (kind === 'SW') register.mockRejectedValue(new Error('SECRETO'));
  if (kind === 'subscribe') manager.subscribe.mockRejectedValue(new Error('SECRETO'));
  if (kind === 'clave') manager.getSubscription.mockResolvedValue({ ...subscription, options: { applicationServerKey: new Uint8Array(65).buffer } });
  render(view()); await ready(); await userEvent.click(button()); await screen.findByText(/No se pudo confirmar el alta/);
  expect(autorizarPush).not.toHaveBeenCalled(); expect(screen.queryByText(/SECRETO/)).not.toBeInTheDocument();
});
it.each(['permiso', 'SW', 'suscripcion', 'API'])('cambio de época durante %s descarta pendientes y evita siguientes efectos', async stage => {
  let resolve;
  const pending = new Promise(yes => { resolve = yes; });
  if (stage === 'permiso') notification.requestPermission.mockReturnValue(pending);
  if (stage === 'SW') register.mockReturnValue(pending);
  if (stage === 'suscripcion') manager.subscribe.mockReturnValue(pending);
  if (stage === 'API') autorizarPush.mockReturnValue(pending);
  render(view()); await ready(); await userEvent.click(button());
  const operation = stage === 'permiso' ? notification.requestPermission : stage === 'SW' ? register : stage === 'suscripcion' ? manager.subscribe : autorizarPush;
  await waitFor(() => expect(operation).toHaveBeenCalled()); act(() => avanzarSesion());
  await act(async () => resolve(stage === 'permiso' ? 'granted' : stage === 'SW' ? { active: { state: 'activated' }, pushManager: manager } : stage === 'suscripcion' ? subscription : { activa: true }));
  expect(screen.queryByText(/confirmada para esta sesión/)).not.toBeInTheDocument();
  if (stage !== 'API') expect(autorizarPush).not.toHaveBeenCalled();
  if (stage === 'permiso') expect(register).not.toHaveBeenCalled();
});
it('cambio de titular descarta permiso anterior y nueva cuenta no se suscribe automáticamente', async () => {
  let resolve; notification.requestPermission.mockReturnValueOnce(new Promise(yes => { resolve = yes; }));
  const { rerender } = render(view()); await ready(); await userEvent.click(button()); rerender(view('8')); await ready();
  await act(async () => resolve('granted')); expect(register).not.toHaveBeenCalled(); expect(autorizarPush).not.toHaveBeenCalled();
});
it('cambio de titular aborta el alta en vuelo y descarta su respuesta sin revocar la cuenta nueva', async () => {
  let resolve; autorizarPush.mockReturnValueOnce(new Promise(yes => { resolve = yes; }));
  const { rerender } = render(view()); await ready(); await userEvent.click(button());
  await waitFor(() => expect(autorizarPush).toHaveBeenCalledTimes(1));
  const signal = autorizarPush.mock.calls[0][1]; rerender(view('8')); await ready();
  expect(signal.aborted).toBe(true); await act(async () => resolve({ activa: true }));
  expect(screen.queryByText(/confirmada para esta sesión/)).not.toBeInTheDocument(); expect(autorizarPush).toHaveBeenCalledTimes(1);
});
it.each(['salida', 'desmontaje'])('interrumpe pendientes al producirse %s', async kind => {
  let resolve; notification.requestPermission.mockReturnValueOnce(new Promise(yes => { resolve = yes; }));
  const { unmount } = render(view()); await ready(); await userEvent.click(button());
  if (kind === 'salida') act(() => window.dispatchEvent(new Event('pagehide'))); else unmount();
  await act(async () => resolve('granted')); expect(register).not.toHaveBeenCalled(); expect(autorizarPush).not.toHaveBeenCalled();
});
it('doble pulsación no inicia dos permisos/altas', async () => {
  let resolve; notification.requestPermission.mockReturnValue(new Promise(yes => { resolve = yes; }));
  render(view()); await ready(); await userEvent.dblClick(button()); expect(notification.requestPermission).toHaveBeenCalledTimes(1);
  await act(async () => resolve('default'));
});
it('fallo de alta no bloquea consulta ni actualización de los avisos internos', async () => {
  listarRecordatorios.mockResolvedValue(listado()); autorizarPush.mockRejectedValue(new Error('red'));
  render(<AuthContext.Provider value={{ status: 'authenticated', usuario: { id_usuario: '7', rol: 'Paciente' } }}><RecordatoriosPage /></AuthContext.Provider>);
  await screen.findByText('CITA-FICTICIA'); await ready(); await userEvent.click(button());
  await screen.findByText(/No se pudo confirmar el alta/); expect(screen.getByText('CITA-FICTICIA')).toBeInTheDocument();
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar recordatorios' }));
  await waitFor(() => expect(listarRecordatorios).toHaveBeenCalledTimes(2)); await screen.findByText('CITA-FICTICIA');
});
