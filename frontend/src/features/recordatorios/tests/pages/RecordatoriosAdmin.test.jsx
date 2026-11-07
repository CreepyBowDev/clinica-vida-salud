import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, afterAll, beforeEach, expect, it, vi } from 'vitest';
import { AuthContext } from '../../../auth/context/AuthContext';
import { avanzarSesion } from '../../../../api/api';
import RecordatoriosPage from '../../pages/RecordatoriosPage';
import { listarRecordatorios, consultarProximaCita, enviarRecordatorioManual } from '../../services/recordatorio.service';
import { listarPacientes } from '../../../pacientes/services/paciente.service';
import { aviso, listado, sesion } from '../utils/recordatorioFixtures';
import { pacientes } from '../utils/pacientesFixtures';

vi.mock('../../services/recordatorio.service', () => ({ listarRecordatorios: vi.fn(), consultarProximaCita: vi.fn(), enviarRecordatorioManual: vi.fn() }));
vi.mock('../../services/push.service', async original => ({ ...await original(), listarSuscripcionesPush: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } }) }));
vi.mock('../../../pacientes/services/paciente.service', () => ({ listarPacientes: vi.fn() }));
const view = (id = '1', rol = 'Administrador') => <AuthContext.Provider value={{ status: 'authenticated', usuario: sesion(rol, id) }}><RecordatoriosPage /></AuthContext.Provider>;
const select = async (name = 'Paciente') => userEvent.click(await screen.findByRole('button', { name: `Seleccionar paciente ${name} De prueba` }));
const proxima = () => ({ cita: { id_cita: '71', revision: 'a'.repeat(64), inicio_at: '2026-11-10T14:30:00.000000Z',
  medico: 'Profesional de prueba', consultorio: 'Consultorio 3', manual_generado: false, preferencia_habilitada: true }, operacion: null });
const generado = { id_recordatorio: '91', id_cita: '71', aviso_interno: 'Generado', push: 'Sin_dispositivos' };
const dialogMethods = ['showModal', 'close'].map(name => [name, Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, name)]);
beforeAll(() => {
  Object.defineProperty(HTMLDialogElement.prototype, 'showModal', { configurable: true, value() { this.setAttribute('open', ''); this.querySelector('button')?.focus(); } });
  Object.defineProperty(HTMLDialogElement.prototype, 'close', { configurable: true, value() { this.removeAttribute('open'); } });
});
afterAll(() => { for (const [name, descriptor] of dialogMethods) { if (descriptor) Object.defineProperty(HTMLDialogElement.prototype, name, descriptor); else delete HTMLDialogElement.prototype[name]; } });
beforeEach(() => {
  vi.clearAllMocks(); listarPacientes.mockResolvedValue(pacientes()); listarRecordatorios.mockResolvedValue(listado());
  consultarProximaCita.mockReset().mockResolvedValue({ cita: null, operacion: null }); enviarRecordatorioManual.mockReset().mockResolvedValue(generado);
});
it('reutiliza buscador con fichas inactivas/sin CI, exige selección y conserva selector al filtrar/paginar/actualizar', async () => {
  render(view()); await screen.findByText('PAC-21');
  expect(listarRecordatorios).not.toHaveBeenCalled();
  await userEvent.type(screen.getByLabelText('Nombres'), 'Paciente');
  await userEvent.click(screen.getByRole('button', { name: 'Buscar' }));
  await waitFor(() => expect(listarPacientes.mock.calls.at(-1)[0].nombres).toBe('Paciente'));
  listarRecordatorios.mockResolvedValue(listado([aviso()], { total: 21 }));
  await select(); await screen.findByText('CITA-FICTICIA');
  expect(screen.getByRole('heading', { name: 'Recordatorios del paciente' })).toBeInTheDocument();
  await userEvent.click(screen.getByRole('button', { name: 'Siguiente' }));
  await waitFor(() => expect(listarRecordatorios.mock.calls.at(-1)[0].page).toBe(2));
  await userEvent.type(screen.getByLabelText('Desde'), '2026-10-30');
  await userEvent.click(screen.getByRole('button', { name: 'Consultar', exact: true }));
  await waitFor(() => expect(listarRecordatorios.mock.calls.at(-1)[0]).toMatchObject({ fecha_desde: '2026-10-30', page: 1 }));
  await screen.findByText('CITA-FICTICIA');
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar recordatorios' }));
  expect(listarRecordatorios.mock.calls.every(([p]) => p.id_paciente === '21' && p.rol === 'Administrador')).toBe(true);
  expect(screen.queryByRole('button', { name: /preferencias|push|dispositivo|generar/i })).not.toBeInTheDocument();
});
it('cambiar paciente retira datos y aborta/descarta la respuesta anterior aunque ignore abort', async () => {
  let resolve;
  render(view()); await select(); await screen.findByText('CITA-FICTICIA');
  listarRecordatorios.mockImplementationOnce(() => new Promise(yes => { resolve = yes; }));
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar recordatorios' }));
  const signal = listarRecordatorios.mock.calls.at(-1)[1];
  await userEvent.click(screen.getByRole('button', { name: 'Cambiar paciente' }));
  expect(screen.queryByText('CITA-FICTICIA')).not.toBeInTheDocument(); expect(signal.aborted).toBe(true);
  listarRecordatorios.mockResolvedValueOnce(listado([aviso('OTRO-PACIENTE')]));
  await select('Otra persona'); await screen.findByText('OTRO-PACIENTE');
  expect(listarRecordatorios.mock.calls.at(-1)[0]).toMatchObject({ id_paciente: '22', page: 1, fecha_desde: '' });
  await act(async () => resolve(listado([aviso('ATRASADA')])));
  expect(screen.queryByText('ATRASADA')).not.toBeInTheDocument();
});
it.each(['identidad', 'época', 'salida'])('limpia selección y respuesta pendiente al cambiar %s', async kind => {
  let resolve;
  listarRecordatorios.mockImplementationOnce(() => new Promise(yes => { resolve = yes; }));
  const { rerender } = render(view()); await select();
  if (kind === 'identidad') rerender(view('2'));
  else act(() => kind === 'época' ? avanzarSesion() : window.dispatchEvent(new Event('pagehide')));
  await screen.findByRole('heading', { name: 'Seleccionar paciente para consultar recordatorios' });
  await act(async () => resolve(listado([aviso('ATRASADA')])));
  expect(screen.queryByText('ATRASADA')).not.toBeInTheDocument();
  expect(listarRecordatorios.mock.calls[0][1].aborted).toBe(true); expect(listarRecordatorios).toHaveBeenCalledTimes(1);
});
it('cambio de sesión descarta también la búsqueda de pacientes anterior', async () => {
  let resolve;
  listarPacientes.mockImplementationOnce(() => new Promise(yes => { resolve = yes; }));
  render(view()); act(() => avanzarSesion());
  await screen.findByText('PAC-21');
  await act(async () => resolve({ ...pacientes(), data: [{ ...pacientes().data[0], nombres: 'ANTERIOR' }] }));
  expect(screen.queryByText(/ANTERIOR/)).not.toBeInTheDocument();
  expect(listarPacientes.mock.calls[0][1].aborted).toBe(true);
});
it('pasar de Administrador a Paciente retira contexto ajeno y usa consulta propia sin selector', async () => {
  const { rerender } = render(view()); await select(); await screen.findByText('CITA-FICTICIA');
  listarRecordatorios.mockResolvedValueOnce(listado([aviso('PROPIA')])); rerender(view('7', 'Paciente'));
  await screen.findByText('PROPIA'); expect(screen.queryByText('Paciente seleccionado:')).not.toBeInTheDocument();
  expect(listarRecordatorios.mock.calls.at(-1)[0]).not.toHaveProperty('id_paciente');
});

it('próxima cita independiente del listado, confirmación por teclado y un solo envío aunque se pulse dos veces', async () => {
  consultarProximaCita.mockResolvedValue(proxima()); listarRecordatorios.mockResolvedValue(listado([]));
  let finish; enviarRecordatorioManual.mockImplementation(() => new Promise(resolve => { finish = resolve; }));
  render(view()); await select();
  const campana = await screen.findByRole('button', { name: 'Enviar recordatorio', exact: true });
  expect(screen.getByRole('heading', { name: 'Próxima cita' }).compareDocumentPosition(screen.getByRole('form', { name: 'Filtrar recordatorios' })) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  campana.focus(); await userEvent.keyboard('{Enter}');
  const dialog = screen.getByRole('dialog', { name: 'Enviar recordatorio' });
  expect(within(dialog).getByText('Profesional de prueba')).toBeInTheDocument();
  expect(within(dialog).getByText(/10\/11\/2026.*10:30/)).toBeInTheDocument();
  await userEvent.click(within(dialog).getByRole('button', { name: 'Confirmar envío' }));
  await userEvent.keyboard('{Enter}'); expect(enviarRecordatorioManual).toHaveBeenCalledTimes(1);
  expect(enviarRecordatorioManual.mock.calls[0].slice(0, 2)).toEqual(['21', proxima().cita]);
  await act(async () => finish(generado));
  await screen.findByText(/Aviso interno generado.*No hay dispositivos autorizados/);
  expect(campana).toBeDisabled(); expect(listarRecordatorios).toHaveBeenCalledTimes(2);
});

it('conflicto exige actualizar y confirmar otra vez, sin enviar silenciosamente a otra cita', async () => {
  consultarProximaCita.mockResolvedValue(proxima());
  enviarRecordatorioManual.mockRejectedValueOnce({ response: { status: 409 } });
  render(view()); await select(); await userEvent.click(await screen.findByRole('button', { name: 'Enviar recordatorio', exact: true }));
  await userEvent.click(screen.getByRole('button', { name: 'Confirmar envío' }));
  await screen.findByRole('alert'); expect(screen.getByRole('button', { name: 'Enviar recordatorio', exact: true })).toBeDisabled();
  consultarProximaCita.mockResolvedValue({ ...proxima(), cita: { ...proxima().cita, id_cita: '72', medico: 'Otra profesional' } });
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar próxima cita' }));
  await screen.findByText('Otra profesional'); expect(enviarRecordatorioManual).toHaveBeenCalledTimes(1);
  await userEvent.click(screen.getByRole('button', { name: 'Enviar recordatorio', exact: true }));
  expect(within(screen.getByRole('dialog')).getByText('Otra profesional')).toBeInTheDocument();
  await userEvent.keyboard('{Escape}');
});

it.each([undefined, 408, 503])('resultado incierto %s solo consulta estado, sin repetir la escritura y sin afirmar entrega', async status => {
  consultarProximaCita.mockResolvedValue(proxima()); enviarRecordatorioManual.mockRejectedValueOnce(status ? { response: { status } } : new Error('timeout'));
  render(view()); await select(); await userEvent.click(await screen.findByRole('button', { name: 'Enviar recordatorio', exact: true }));
  await userEvent.click(screen.getByRole('button', { name: 'Confirmar envío' }));
  await screen.findByText(/El resultado del envío es incierto/);
  expect(screen.getByRole('button', { name: 'Enviar recordatorio', exact: true })).toBeDisabled();
  consultarProximaCita.mockResolvedValue({ ...proxima(), operacion: { ...generado, push: 'Pendiente' } });
  await userEvent.click(screen.getByRole('button', { name: 'Comprobar resultado' }));
  await screen.findByText(/Aviso interno generado.*no acredita entrega/);
  expect(consultarProximaCita.mock.calls.at(-1)[1]).toBe(enviarRecordatorioManual.mock.calls[0][2]);
  expect(enviarRecordatorioManual).toHaveBeenCalledTimes(1);
});

it.each([{ manual_generado: true }, { preferencia_habilitada: false }])('no habilita envío si el servidor lo impide: %j', async extra => {
  consultarProximaCita.mockResolvedValue({ ...proxima(), cita: { ...proxima().cita, ...extra } });
  render(view()); await select(); expect(await screen.findByRole('button', { name: 'Enviar recordatorio', exact: true })).toBeDisabled();
  expect(enviarRecordatorioManual).not.toHaveBeenCalled();
});

it('cambiar Paciente aborta próxima cita anterior y descarta respuestas tardías', async () => {
  let finish; consultarProximaCita.mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
  render(view()); await select(); await screen.findByText('Consultando próxima cita…');
  const signal = consultarProximaCita.mock.calls[0][2];
  await userEvent.click(screen.getByRole('button', { name: 'Cambiar paciente' }));
  await select('Otra persona'); await screen.findByText('No hay próximas citas futuras vigentes para este Paciente.');
  await act(async () => finish(proxima())); expect(signal.aborted).toBe(true);
  expect(screen.queryByText('Profesional de prueba')).not.toBeInTheDocument();
});

it('un close nativo tardío no descarta una confirmación ya reabierta', async () => {
  consultarProximaCita.mockResolvedValue(proxima()); render(view()); await select();
  const bell = await screen.findByRole('button', { name: 'Enviar recordatorio', exact: true });
  await userEvent.click(bell);
  await userEvent.click(screen.getByRole('button', { name: 'Volver' }));
  await userEvent.click(bell);
  const dialog = screen.getByRole('dialog', { name: 'Enviar recordatorio' });
  fireEvent(dialog, new Event('close'));
  expect(screen.getByRole('dialog', { name: 'Enviar recordatorio' })).toHaveAttribute('open');
  expect(screen.getByRole('button', { name: 'Confirmar envío' })).toBeEnabled();
  expect(enviarRecordatorioManual).not.toHaveBeenCalled();
});
