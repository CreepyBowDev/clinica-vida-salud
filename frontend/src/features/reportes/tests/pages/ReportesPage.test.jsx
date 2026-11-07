import { createContext, useContext } from 'react';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, it, vi } from 'vitest';
import { avanzarSesion } from '../../../../api/api';
import ReportesPage from '../../pages/ReportesPage';
import { consultarCitasProgramadas, consultarCitasPorMedico, consultarCitasPorEspecialidad, consultarCitasPorEstado } from '../../services/reporte.service';
import { listarMedicosAgenda } from '../../../agenda/services/agenda.service';
import { listarEspecialidades } from '../../../especialidades/services/especialidad.service';
import { medico, especialidad, pagina, reporte, sesion } from '../utils/reporteFixtures';

const auth = vi.hoisted(() => ({}));
vi.mock('../../../../hooks/useAuth', () => ({ useAuth: () => useContext(auth.Context) }));
vi.mock('../../services/reporte.service', () => ({ consultarCitasProgramadas: vi.fn(), consultarCitasPorMedico: vi.fn(), consultarCitasPorEspecialidad: vi.fn(), consultarCitasPorEstado: vi.fn(), consultarPacientesRegistrados: vi.fn(), consultarCantidadesCitas: vi.fn(), consultarAgendaDiaria: vi.fn() }));
vi.mock('../../../agenda/services/agenda.service', () => ({ listarMedicosAgenda: vi.fn() }));
vi.mock('../../../especialidades/services/especialidad.service', () => ({ listarEspecialidades: vi.fn() }));
auth.Context = createContext(null);
function mount() {
  const content = () => <auth.Context.Provider value={{ status: 'authenticated', usuario: auth.usuario }}><ReportesPage /></auth.Context.Provider>;
  const view = render(content()); return { ...view, refresh: () => view.rerender(content()) };
}
async function consultar() {
  fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-10-31' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' }));
}
beforeEach(() => {
  vi.clearAllMocks(); auth.usuario = sesion();
  listarMedicosAgenda.mockImplementation(async p => pagina([medico], p));
  listarEspecialidades.mockImplementation(async p => pagina([especialidad], p));
  consultarCitasProgramadas.mockImplementation(async p => reporte(p));
  consultarCitasPorMedico.mockImplementation(async p => reporte(p, 1, 'por-medico'));
  consultarCitasPorEspecialidad.mockImplementation(async p => reporte(p, 1, 'por-especialidad'));
  consultarCitasPorEstado.mockImplementation(async p => reporte(p, 1, 'por-estado'));
});
it('requiere fecha y no pide reporte al entrar; usa catálogos históricos sin filtro de actividad', async () => {
  mount(); await waitFor(() => expect(listarEspecialidades).toHaveBeenCalled());
  expect(consultarCitasProgramadas).not.toHaveBeenCalled();
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); expect(consultarCitasProgramadas).not.toHaveBeenCalled();
  expect(listarEspecialidades.mock.calls[0][0]).toEqual({ page: 1, per_page: 5 });
  expect(listarMedicosAgenda.mock.calls[0][0]).toEqual({ page: 1, per_page: 5 });
});
it('mantiene resultados identificables y distingue el período aplicado del borrador', async () => {
  mount();
  const region = screen.getByRole('region', { name: 'Resultados del reporte' });
  expect(within(region).getByRole('heading', { name: 'Resultados del reporte' })).toBeInTheDocument();
  expect(within(region).queryByRole('status')).not.toBeInTheDocument();
  await consultar(); await screen.findByText('CITA-1');
  expect(region).toHaveFocus();
  fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-11-02' } });
  expect(region).toHaveTextContent('Fecha consultada: 31/10/2026');
  expect(region).not.toHaveTextContent('02/11/2026');
  expect(within(region).getByRole('status')).toHaveTextContent('Total del servidor: 1');
});
it('combina selecciones inactivas, fecha, orden y tamaño; lista ambas personas y ubicación histórica en hora de sede', async () => {
  mount(); const user = userEvent.setup();
  await user.click(screen.getByText('Médico: Todos', { selector: 'summary' }));
  await user.click(await screen.findByRole('button', { name: 'Seleccionar Médica De prueba' }));
  await user.click(screen.getByText('Especialidad: Todos', { selector: 'summary' }));
  await user.click(await screen.findByRole('button', { name: 'Seleccionar Especialidad ficticia' }));
  await user.selectOptions(screen.getByLabelText('Orden'), 'fecha_desc'); await user.selectOptions(screen.getByLabelText('Citas por página'), '50');
  await consultar(); const table = await screen.findByRole('table', { name: name => !name.startsWith('Cantidades por') });
  expect(consultarCitasProgramadas.mock.calls.at(-1)[0]).toEqual({ fecha: '2026-10-31', id_medico: medico.id_medico, id_especialidad: '5', orden: 'fecha_desc', page: 1, per_page: 50 });
  expect(table).toHaveTextContent('Paciente De prueba'); expect(table).toHaveTextContent('Médica De prueba'); expect(table).toHaveTextContent('Sala histórica');
  expect(table).toHaveTextContent('31/10/2026, 22:30'); expect(table).not.toHaveTextContent('01/11/2026'); expect(table).not.toHaveTextContent('Confirmada');
  expect(screen.getByRole('status')).toHaveTextContent('Total del servidor: 1');
  expect(screen.queryByRole('button', { name: /cancelar|reprogramar|atendida|publicar/i })).not.toBeInTheDocument();
});
it('pagina con total del servidor, conserva filtros aplicados y al consultar de nuevo vuelve a página 1', async () => {
  consultarCitasProgramadas.mockImplementation(async p => reporte(p, 21)); mount(); await consultar(); await screen.findByRole('table', { name: name => !name.startsWith('Cantidades por') });
  const region = screen.getByRole('region', { name: 'Resultados del reporte' });
  expect(within(region).getByRole('status')).toHaveTextContent('21');
  await userEvent.click(within(region).getByRole('button', { name: 'Siguiente' })); await screen.findByText('CITA-21');
  expect(consultarCitasProgramadas.mock.calls.at(-1)[0].page).toBe(2);
  fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-11-02' } });
  expect(region).toHaveTextContent('Fecha consultada: 31/10/2026');
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' }));
  await waitFor(() => expect(consultarCitasProgramadas.mock.calls.at(-1)[0]).toMatchObject({ fecha: '2026-11-02', page: 1 }));
});
it('retira resultados durante actualización, muestra vacío y total cero confirmado', async () => {
  mount(); await consultar(); await screen.findByText('CITA-1');
  let resolve; consultarCitasProgramadas.mockImplementation(p => new Promise(r => { resolve = () => r(reporte(p, 0)); }));
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' }));
  expect(screen.queryByText('CITA-1')).not.toBeInTheDocument(); expect(screen.getByRole('region')).toHaveAttribute('aria-busy', 'true');
  await act(async () => resolve()); await screen.findByText('No hay citas Programadas para estos filtros'); expect(screen.getByRole('status')).toHaveTextContent('Total del servidor: 0');
});
it.each([403, 404, 419, 422, 429, 500, undefined])('maneja error %s sin mostrar cuerpo técnico ni datos previos', async status => {
  mount(); await consultar(); await screen.findByText('CITA-1');
  consultarCitasProgramadas.mockRejectedValue({ ...(status ? { response: { status, headers: { 'retry-after': '2' }, data: { error: { message: 'SQL SECRETO' } } } } : {}) });
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' })); await screen.findByRole('alert');
  expect(screen.queryByText('CITA-1')).not.toBeInTheDocument(); expect(document.body).not.toHaveTextContent('SQL SECRETO');
  if (status === 429) expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeDisabled();
});
it('descarta respuesta atrasada al cambiar filtros y al limpiar', async () => {
  let resolve; consultarCitasProgramadas.mockImplementationOnce(p => new Promise(r => { resolve = () => r(reporte(p)); }));
  mount(); await consultar(); fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-11-02' } });
  consultarCitasProgramadas.mockImplementation(async p => reporte(p, 0));
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); await screen.findByText('No hay citas Programadas para estos filtros');
  await act(async () => resolve()); expect(screen.queryByText('CITA-1')).not.toBeInTheDocument();
  await userEvent.click(screen.getByRole('button', { name: 'Limpiar filtros' })); expect(screen.queryByRole('status')).not.toBeInTheDocument();
});
it.each(['identidad', 'epoca', 'salir'])('aborta y descarta respuesta al cambiar %s', async tipo => {
  let resolve, signal;
  consultarCitasProgramadas.mockImplementation((p, s) => new Promise(r => { signal = s; resolve = () => r(reporte(p)); }));
  const view = mount(); await consultar();
  if (tipo === 'identidad') { auth.usuario = sesion('Administrador', '8'); view.refresh(); }
  else if (tipo === 'epoca') act(() => avanzarSesion()); else view.unmount();
  expect(signal.aborted).toBe(true); await act(async () => resolve()); expect(screen.queryByText('CITA-1')).not.toBeInTheDocument();
});
it('limpia resultados ya visibles al cambiar época, sin nuevas peticiones hasta acción explícita', async () => {
  mount(); await consultar(); await screen.findByText('CITA-1'); const count = consultarCitasProgramadas.mock.calls.length;
  act(() => avanzarSesion()); expect(screen.queryByText('CITA-1')).not.toBeInTheDocument();
  expect(screen.getByRole('alert')).toHaveTextContent('La sesión cambió'); expect(consultarCitasProgramadas).toHaveBeenCalledTimes(count);
});

async function tipoReporte(tipo) { await userEvent.selectOptions(screen.getByLabelText('Reporte'), tipo); }
async function seleccionarMedico() {
  await userEvent.click(screen.getByText('Médico: Todos', { selector: 'summary' }));
  await userEvent.click(await screen.findByRole('button', { name: 'Seleccionar Médica De prueba' }));
}
async function seleccionarEspecialidad() {
  await userEvent.click(screen.getByText('Especialidad: Todos', { selector: 'summary' }));
  await userEvent.click(await screen.findByRole('button', { name: 'Seleccionar Especialidad ficticia' }));
}
it.each([
  ['por-medico', consultarCitasPorMedico, seleccionarMedico, 'Selecciona un médico'],
  ['por-especialidad', consultarCitasPorEspecialidad, seleccionarEspecialidad, 'Selecciona una especialidad'],
])('%s exige selección e incluye entidad inactiva y estados opcionales', async (tipo, load, seleccionar, mensaje) => {
  mount(); await tipoReporte(tipo); await consultar();
  expect(await screen.findByRole('alert')).toHaveTextContent(mensaje); expect(load).not.toHaveBeenCalled();
  await seleccionar(); await userEvent.selectOptions(screen.getByLabelText('Estado'), 'Cancelada');
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); const table = await screen.findByRole('table', { name: name => !name.startsWith('Cantidades por') });
  expect(table).toHaveTextContent('Cancelada'); expect(load.mock.calls.at(-1)[0]).toMatchObject({ fecha: '2026-10-31', estado: 'Cancelada' });
  expect(load.mock.calls.at(-1)[0][tipo === 'por-medico' ? 'id_medico' : 'id_especialidad']).toBe(tipo === 'por-medico' ? medico.id_medico : '5');
});
it('por-estado ofrece los cinco estados, exige selección y combina filtros opcionales', async () => {
  mount(); await tipoReporte('por-estado'); const select = screen.getByLabelText('Estado obligatorio');
  expect(within(select).getAllByRole('option').map(v => v.value)).toEqual(['', 'Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia']);
  await consultar(); expect(consultarCitasPorEstado).not.toHaveBeenCalled();
  await seleccionarMedico(); await seleccionarEspecialidad(); await userEvent.selectOptions(select, 'Inasistencia');
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); await screen.findByRole('table', { name: name => !name.startsWith('Cantidades por') });
  expect(consultarCitasPorEstado.mock.calls.at(-1)[0]).toMatchObject({ estado: 'Inasistencia', id_medico: medico.id_medico, id_especialidad: '5' });
});
it('fecha y rango son excluyentes; valida inversión y 367 días, acepta 366 sin convertir fechas civiles', async () => {
  mount(); await tipoReporte('por-estado'); await userEvent.selectOptions(screen.getByLabelText('Estado obligatorio'), 'Confirmada');
  fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-10-31' } });
  await userEvent.selectOptions(screen.getByLabelText('Período'), 'rango'); expect(screen.queryByLabelText('Fecha')).not.toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2026-01-01' } });
  fireEvent.change(screen.getByLabelText('Hasta'), { target: { value: '2025-12-31' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); expect(screen.getByRole('alert')).toHaveTextContent('366 días');
  fireEvent.change(screen.getByLabelText('Hasta'), { target: { value: '2027-01-02' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); expect(consultarCitasPorEstado).not.toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText('Hasta'), { target: { value: '2027-01-01' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); await screen.findByRole('table', { name: name => !name.startsWith('Cantidades por') });
  expect(consultarCitasPorEstado.mock.calls.at(-1)[0]).toEqual({ desde: '2026-01-01', hasta: '2027-01-01', estado: 'Confirmada', id_medico: '', id_especialidad: '', page: 1, per_page: 20, orden: 'fecha_asc' });
  expect(screen.getByRole('region', { name: 'Resultados del reporte' })).toHaveTextContent('Período consultado: 01/01/2026 al 01/01/2027');
  await userEvent.selectOptions(screen.getByLabelText('Período'), 'fecha'); expect(screen.queryByLabelText('Desde')).not.toBeInTheDocument();
  await consultar(); await waitFor(() => expect(consultarCitasPorEstado.mock.calls.at(-1)[0]).toMatchObject({ fecha: '2026-10-31' }));
  expect(consultarCitasPorEstado.mock.calls.at(-1)[0]).not.toHaveProperty('desde');
});
it('cambiar tipo aborta la consulta, limpia resultados/filtros y descarta una respuesta atrasada de otro reporte', async () => {
  let resolve, signal;
  consultarCitasProgramadas.mockImplementation((p, s) => new Promise(r => { signal = s; resolve = () => r(reporte(p)); }));
  mount(); await seleccionarMedico(); await consultar(); await tipoReporte('por-estado');
  expect(signal.aborted).toBe(true); expect(screen.getByLabelText('Fecha')).toHaveValue(''); expect(screen.getByLabelText('Estado obligatorio')).toHaveValue('');
  expect(screen.getByText('Médico: Todos', { selector: 'summary' })).toBeInTheDocument();
  await act(async () => resolve()); expect(screen.queryByRole('table')).not.toBeInTheDocument();
  await userEvent.selectOptions(screen.getByLabelText('Estado obligatorio'), 'Atendida'); await consultar(); await screen.findByRole('table', { name: name => !name.startsWith('Cantidades por') });
  await tipoReporte('programadas'); expect(screen.queryByRole('table')).not.toBeInTheDocument(); expect(screen.queryByLabelText('Estado')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Período')).not.toBeInTheDocument();
});
it('los reportes nuevos conservan rango/selecciones al paginar y reinician página al aplicar cambios', async () => {
  consultarCitasPorMedico.mockImplementation(async p => reporte(p, 21, 'por-medico'));
  mount(); await tipoReporte('por-medico'); await seleccionarMedico(); await seleccionarEspecialidad();
  await userEvent.selectOptions(screen.getByLabelText('Período'), 'rango');
  fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2026-10-01' } }); fireEvent.change(screen.getByLabelText('Hasta'), { target: { value: '2026-10-31' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); await screen.findByRole('table', { name: name => !name.startsWith('Cantidades por') });
  await userEvent.click(within(screen.getByRole('region', { name: 'Resultados del reporte' })).getByRole('button', { name: 'Siguiente' })); await screen.findByText('CITA-21');
  expect(consultarCitasPorMedico.mock.calls.at(-1)[0]).toMatchObject({ desde: '2026-10-01', hasta: '2026-10-31', id_medico: medico.id_medico, id_especialidad: '5', page: 2 });
  await userEvent.selectOptions(screen.getByLabelText('Estado'), 'Cancelada'); await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' }));
  await waitFor(() => expect(consultarCitasPorMedico.mock.calls.at(-1)[0]).toMatchObject({ estado: 'Cancelada', page: 1 }));
});
it.each(['por-medico', 'por-especialidad', 'por-estado'])('%s descarta respuesta al cambiar sesión', async tipo => {
  const load = { 'por-medico': consultarCitasPorMedico, 'por-especialidad': consultarCitasPorEspecialidad, 'por-estado': consultarCitasPorEstado }[tipo];
  let resolve, signal; load.mockImplementation((p, s) => new Promise(r => { signal = s; resolve = () => r(reporte(p, 1, tipo)); }));
  mount(); await tipoReporte(tipo);
  if (tipo === 'por-medico') await seleccionarMedico();
  else if (tipo === 'por-especialidad') await seleccionarEspecialidad();
  else await userEvent.selectOptions(screen.getByLabelText('Estado obligatorio'), 'Confirmada');
  await consultar(); act(() => avanzarSesion()); expect(signal.aborted).toBe(true);
  await act(async () => resolve()); expect(screen.queryByText('CITA-1')).not.toBeInTheDocument();
});
