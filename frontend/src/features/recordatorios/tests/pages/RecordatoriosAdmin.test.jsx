import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, it, vi } from 'vitest';
import { AuthContext } from '../../../auth/context/AuthContext';
import { avanzarSesion } from '../../../../api/api';
import RecordatoriosPage from '../../pages/RecordatoriosPage';
import { listarRecordatorios } from '../../services/recordatorio.service';
import { listarPacientes } from '../../../pacientes/services/paciente.service';
import { aviso, listado, sesion } from '../utils/recordatorioFixtures';
import { pacientes } from '../utils/pacientesFixtures';

vi.mock('../../services/recordatorio.service', () => ({ listarRecordatorios: vi.fn() }));
vi.mock('../../services/push.service', async original => ({ ...await original(), listarSuscripcionesPush: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } }) }));
vi.mock('../../../pacientes/services/paciente.service', () => ({ listarPacientes: vi.fn() }));
const view = (id = '1', rol = 'Administrador') => <AuthContext.Provider value={{ status: 'authenticated', usuario: sesion(rol, id) }}><RecordatoriosPage /></AuthContext.Provider>;
const select = async (name = 'Paciente') => userEvent.click(await screen.findByRole('button', { name: `Seleccionar paciente ${name} De prueba` }));
beforeEach(() => {
  vi.clearAllMocks(); listarPacientes.mockResolvedValue(pacientes()); listarRecordatorios.mockResolvedValue(listado());
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
