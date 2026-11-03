import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, it, vi } from 'vitest';
import { AuthContext } from '../../../auth/context/AuthContext';
import { avanzarSesion } from '../../../../api/api';
import RecordatoriosPage from '../../pages/RecordatoriosPage';
import { listarRecordatorios } from '../../services/recordatorio.service';
import { aviso, listado, sesion } from '../utils/recordatorioFixtures';

vi.mock('../../services/recordatorio.service', () => ({ listarRecordatorios: vi.fn() }));
vi.mock('../../services/push.service', async original => ({ ...await original(), listarSuscripcionesPush: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } }) }));
const data = (rows = [aviso()], params) => { const r = listado(rows, params); return { data: r.data, meta: r.meta }; };
const view = (id = '7', rol = 'Paciente') => <AuthContext.Provider value={{ status: 'authenticated', usuario: sesion(rol, id) }}><RecordatoriosPage /></AuthContext.Provider>;
beforeEach(() => { vi.clearAllMocks(); listarRecordatorios.mockResolvedValue(data()); });
it('muestra cita, horario de sede (día anterior UTC), médico, especialidad, consultorio y estado sin acciones de escritura', async () => {
  render(view()); await screen.findByText('CITA-FICTICIA');
  for (const text of ['Médica De prueba', 'Especialidad ficticia', 'Sala ficticia', 'Sede ficticia', 'Programada', 'Presencial']) expect(screen.getByText(text)).toBeInTheDocument();
  expect(screen.getAllByText(/30\/10\/2026/).some(node => node.textContent.includes('22:30'))).toBe(true);
  expect(screen.queryByRole('button', { name: /leído|generar|cancelar|reprogramar|push/i })).not.toBeInTheDocument();
});
it('prioriza avisos antes de los dispositivos sin retirar sus controles', async () => {
  render(view()); await screen.findByText('CITA-FICTICIA');
  const results = screen.getByRole('region', { name: 'Resultados de recordatorios' });
  const devices = screen.getByRole('region', { name: 'Mis dispositivos push' });
  expect(results.compareDocumentPosition(devices) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  expect(screen.getByRole('heading', { name: 'Avisos vigentes' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Desactivar este navegador' })).toBeInTheDocument();
});
it('filtra, limpia y actualiza manualmente conservando filtros aplicados', async () => {
  const user = userEvent.setup(); render(view()); await screen.findByText('CITA-FICTICIA');
  await user.type(screen.getByLabelText('Desde'), '2026-10-30'); await user.type(screen.getByLabelText('Hasta'), '2026-11-01');
  await user.click(screen.getByRole('button', { name: 'Consultar', exact: true }));
  await waitFor(() => expect(listarRecordatorios.mock.calls.at(-1)[0]).toMatchObject({ fecha_desde: '2026-10-30', fecha_hasta: '2026-11-01', page: 1 }));
  await user.click(screen.getByRole('button', { name: 'Actualizar recordatorios' }));
  expect(listarRecordatorios.mock.calls.at(-1)[0].fecha_desde).toBe('2026-10-30');
  await user.click(screen.getByRole('button', { name: 'Limpiar filtros' }));
  await waitFor(() => expect(listarRecordatorios.mock.calls.at(-1)[0]).toMatchObject({ fecha_desde: '', fecha_hasta: '', page: 1 }));
});
it('valida rango antes de consultar', async () => {
  const user = userEvent.setup(); render(view()); await screen.findByText('CITA-FICTICIA');
  await user.type(screen.getByLabelText('Desde'), '2026-11-01'); await user.type(screen.getByLabelText('Hasta'), '2026-10-30');
  await user.click(screen.getByRole('button', { name: 'Consultar', exact: true }));
  expect(screen.getByRole('alert')).toHaveTextContent('La fecha final'); expect(listarRecordatorios).toHaveBeenCalledTimes(1);
});
it('retira página previa durante carga y descarta respuesta anterior de filtros', async () => {
  const user = userEvent.setup(); let resolve;
  listarRecordatorios.mockResolvedValueOnce(data([aviso()], { total: 21 }));
  render(view()); await screen.findByText('CITA-FICTICIA');
  listarRecordatorios.mockImplementationOnce(() => new Promise(yes => { resolve = yes; }));
  await user.click(screen.getByRole('button', { name: 'Siguiente' }));
  expect(screen.queryByText('CITA-FICTICIA')).not.toBeInTheDocument(); expect(screen.getByText('Consultando recordatorios')).toBeInTheDocument();
  expect(listarRecordatorios.mock.calls.at(-1)[0].page).toBe(2);
  listarRecordatorios.mockResolvedValueOnce(data([aviso('NUEVA-CONSULTA')]));
  await user.click(screen.getByRole('button', { name: 'Limpiar filtros' })); await screen.findByText('NUEVA-CONSULTA');
  await act(async () => resolve(data([aviso('RESPUESTA-ANTIGUA')])));
  expect(screen.queryByText('RESPUESTA-ANTIGUA')).not.toBeInTheDocument();
});
it('representa vacío legítimo', async () => {
  listarRecordatorios.mockResolvedValue(data([])); render(view()); await screen.findByText('No hay recordatorios para estos filtros');
});
it.each([403, 404, 419, 422, 500, undefined])('maneja error %s seguro y permite reconsulta', async status => {
  listarRecordatorios.mockRejectedValueOnce(status ? { response: { status, data: { error: { message: 'SQL-SECRETO' } } } } : new Error('SQL-SECRETO'));
  const user = userEvent.setup(); render(view()); await screen.findByRole('alert'); expect(screen.queryByText(/SQL-SECRETO/)).not.toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Volver a consultar' })); await screen.findByText('CITA-FICTICIA');
});
it('respeta Retry-After sin reintentar automáticamente', async () => {
  listarRecordatorios.mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': '60' } } });
  render(view()); await screen.findByRole('alert');
  expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Consultar', exact: true })).toBeDisabled(); expect(listarRecordatorios).toHaveBeenCalledTimes(1);
});
it('cambio de identidad desmonta resultados y descarta lectura pendiente de otro titular', async () => {
  let resolve;
  listarRecordatorios.mockImplementationOnce(() => new Promise(yes => { resolve = yes; }));
  const { rerender } = render(view());
  listarRecordatorios.mockResolvedValueOnce(data([aviso('OTRO-TITULAR')])); rerender(view('8'));
  await screen.findByText('OTRO-TITULAR');
  await act(async () => resolve(data([aviso('TITULAR-ANTERIOR')])));
  expect(screen.queryByText('TITULAR-ANTERIOR')).not.toBeInTheDocument(); expect(listarRecordatorios.mock.calls[0][1].aborted).toBe(true);
});
it('evento de cambio de sesión retira datos aun conservando identidad y exige reconsulta', async () => {
  render(view()); await screen.findByText('CITA-FICTICIA');
  act(() => avanzarSesion()); expect(screen.queryByText('CITA-FICTICIA')).not.toBeInTheDocument();
  await screen.findByText('La sesión cambió. Vuelve a consultar antes de continuar.'); expect(listarRecordatorios).toHaveBeenCalledTimes(1);
  await userEvent.click(screen.getByRole('button', { name: 'Volver a consultar' })); await screen.findByText('CITA-FICTICIA');
});
it('descarta pendiente al cambiar época y al desmontar, incluso si el servicio ignora abort', async () => {
  let resolve;
  listarRecordatorios.mockImplementationOnce(() => new Promise(yes => { resolve = yes; }));
  const { unmount } = render(view()); act(() => avanzarSesion());
  await act(async () => resolve(data([aviso('ANTIGUA')]))); expect(screen.queryByText('ANTIGUA')).not.toBeInTheDocument();
  unmount(); expect(listarRecordatorios.mock.calls[0][1].aborted).toBe(true);
});
it('oculta al salir de página y reconsulta al restaurar BFCache', async () => {
  render(view()); await screen.findByText('CITA-FICTICIA');
  act(() => window.dispatchEvent(new Event('pagehide'))); expect(screen.queryByText('CITA-FICTICIA')).not.toBeInTheDocument();
  listarRecordatorios.mockResolvedValueOnce(data([])); act(() => window.dispatchEvent(new Event('pageshow')));
  await screen.findByText('No hay recordatorios para estos filtros');
});
