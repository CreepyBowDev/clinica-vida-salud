import { createContext, useContext } from 'react';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, avanzarSesion } from '../../../../api/api';
import ReportesPage from '../../pages/ReportesPage';
import { medico, especialidad, pagina, sesion } from '../utils/reporteFixtures';
import { cantidadesReporte, agendaReporte } from '../utils/cantidadesAgendaFixtures';

const auth = vi.hoisted(() => ({}));
vi.mock('../../../../hooks/useAuth', () => ({ useAuth: () => useContext(auth.Context) }));
auth.Context = createContext(null);
const original = api.defaults.adapter;
let calls, counts, total, failure, deferred, resolvePending, crossing;
const path = tipo => tipo === 'cantidades' ? '/reportes/citas/cantidades' : '/reportes/agenda-diaria';
const requests = tipo => calls.filter(c => c.url === path(tipo));
const region = () => screen.getByRole('region', { name: 'Resultados del reporte' });
function mount() {
  const content = () => <auth.Context.Provider value={{ status: 'authenticated', usuario: auth.usuario }}><ReportesPage /></auth.Context.Provider>;
  const view = render(content()); return { ...view, refresh: () => view.rerender(content()) };
}
async function tipoReporte(tipo) { await userEvent.selectOptions(screen.getByLabelText('Reporte'), tipo); }
async function seleccionarMedico() {
  await userEvent.click(screen.getByText('Médico: Todos', { selector: 'summary' }));
  await userEvent.click(await screen.findByRole('button', { name: 'Seleccionar Médica De prueba' }));
}
async function consultar() {
  fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-10-31' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' }));
}
async function preparar(tipo) {
  await tipoReporte(tipo); if (tipo === 'agenda-diaria') await seleccionarMedico();
}
beforeEach(() => {
  auth.usuario = sesion(); calls = []; counts = undefined; total = 1; failure = null; deferred = false; crossing = false; resolvePending = null;
  api.defaults.adapter = async config => {
    calls.push(config);
    if (config.url === '/agenda/medicos' || config.url === '/especialidades') return { config, status: 200, headers: {}, data: pagina([config.url === '/agenda/medicos' ? medico : especialidad], config.params) };
    if (![path('cantidades'), path('agenda-diaria')].includes(config.url)) throw new Error('Petición no prevista');
    if (failure) throw new AxiosError('SECRETO', undefined, config, null, { config, status: failure, headers: { 'retry-after': '2' }, data: { error: { message: 'SQL PRIVADO' } } });
    const data = config.url === path('cantidades') ? cantidadesReporte(config.params, counts) : agendaReporte(config.params, total);
    if (crossing && config.url === path('agenda-diaria') && data.data.length) Object.assign(data.data[0], {
      inicio_at: '2026-10-31T03:45:00.000000Z', fin_at: '2026-10-31T04:15:00.000000Z', duracion_minutos: 30, consultorio_atencion: 'Consultorio histórico conservado' });
    if (deferred) return new Promise(resolve => { resolvePending = () => resolve({ config, status: 200, headers: {}, data }); });
    return { config, status: 200, headers: {}, data };
  };
});
afterEach(() => { api.defaults.adapter = original; vi.restoreAllMocks(); });
it('cantidades muestra el agregado y su total sin estado, orden, tamaño ni paginación', async () => {
  mount(); await preparar('cantidades'); expect(requests('cantidades')).toHaveLength(0);
  expect(screen.queryByLabelText('Estado')).not.toBeInTheDocument(); expect(screen.queryByLabelText('Orden')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Citas por página')).not.toBeInTheDocument();
  expect(screen.getByText(/No son fechas reales de atención/)).toBeInTheDocument();
  await consultar(); const summary = await screen.findByRole('region', { name: 'Cantidades del período' });
  expect(summary).toHaveTextContent('Atendidas10'); expect(summary).toHaveTextContent('Canceladas20'); expect(summary).toHaveTextContent('Inasistencias7'); expect(summary).toHaveTextContent('Total del servidor37');
  expect(within(region()).queryByRole('navigation')).not.toBeInTheDocument();
  expect(requests('cantidades')[0].params).toEqual({ fecha: '2026-10-31' });
  expect(calls.filter(c => c.url.startsWith('/reportes/'))).toHaveLength(1);
});
it('cantidades conserva cuatro ceros y permite actualizar explícitamente', async () => {
  counts = { atendidas: 0, canceladas: 0, inasistencias: 0, total: 0 }; mount(); await preparar('cantidades'); await consultar();
  const summary = await screen.findByRole('region', { name: 'Cantidades del período' });
  expect(within(summary).getAllByRole('definition').map(node => node.textContent)).toEqual(['0', '0', '0', '0']);
  counts = { atendidas: 1, canceladas: 0, inasistencias: 0, total: 1 };
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' }));
  await waitFor(() => expect(within(screen.getByRole('region', { name: 'Cantidades del período' })).getAllByRole('definition').map(node => node.textContent)).toEqual(['1', '0', '0', '1']));
});
it('cantidades combina rango y catálogos históricos sin parámetros de listado ni filtro de estado', async () => {
  mount(); await preparar('cantidades'); await seleccionarMedico();
  await userEvent.click(screen.getByText('Especialidad: Todos', { selector: 'summary' }));
  await userEvent.click(await screen.findByRole('button', { name: 'Seleccionar Especialidad ficticia' }));
  await userEvent.selectOptions(screen.getByLabelText('Período'), 'rango');
  fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2026-10-01' } }); fireEvent.change(screen.getByLabelText('Hasta'), { target: { value: '2026-10-31' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); await screen.findByRole('region', { name: 'Cantidades del período' });
  expect(requests('cantidades')[0].params).toEqual({ desde: '2026-10-01', hasta: '2026-10-31', id_medico: medico.id_medico, id_especialidad: '5' });
  expect(region()).toHaveTextContent('Estados: Atendida, Cancelada e Inasistencia');
});
it('agenda exige médico/fecha, sin especialidad/rango/orden elegible y sin acciones de asistencia', async () => {
  mount(); await tipoReporte('agenda-diaria'); await consultar();
  expect(await screen.findByRole('alert')).toHaveTextContent('Selecciona un médico'); expect(requests('agenda-diaria')).toHaveLength(0);
  expect(screen.queryByLabelText('Período')).not.toBeInTheDocument(); expect(screen.queryByLabelText('Orden')).not.toBeInTheDocument();
  expect(screen.queryByText('Especialidad: Todos', { selector: 'summary' })).not.toBeInTheDocument();
  expect(screen.getByText(/Agenda por intersección/)).toBeInTheDocument(); expect(screen.getByText(/Orden fijo/)).toBeInTheDocument();
  await seleccionarMedico(); await userEvent.selectOptions(screen.getByLabelText('Estado'), 'Confirmada');
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); const table = await screen.findByRole('table');
  expect(table).toHaveTextContent('Confirmada'); expect(requests('agenda-diaria')[0].params).toEqual({ fecha: '2026-10-31', id_medico: medico.id_medico, estado: 'Confirmada', page: 1, per_page: 20 });
  expect(within(table).queryByRole('button')).not.toBeInTheDocument(); expect(within(table).queryByRole('link')).not.toBeInTheDocument();
});
it('agenda presenta intersección que inicia el día anterior, conservando fechas de sede, ubicación y duración', async () => {
  crossing = true; mount(); await preparar('agenda-diaria'); await consultar(); const table = await screen.findByRole('table');
  expect(table).toHaveTextContent('30/10/2026, 23:45'); expect(table).toHaveTextContent('31/10/2026, 00:15');
  expect(table).toHaveTextContent('30 min'); expect(table).toHaveTextContent('Consultorio histórico conservado'); expect(table).toHaveTextContent('Sede ficticia');
  expect(requests('cantidades')).toHaveLength(0);
});
it('agenda pagina con médico/fecha/estado y total real, reinicia página al aplicar filtro y muestra vacío', async () => {
  total = 21; mount(); await preparar('agenda-diaria'); await consultar(); await screen.findByRole('table');
  await userEvent.click(within(region()).getByRole('button', { name: 'Siguiente' })); await screen.findByText('CITA-21');
  expect(requests('agenda-diaria').at(-1).params).toEqual({ fecha: '2026-10-31', id_medico: medico.id_medico, page: 2, per_page: 20 });
  expect(screen.getByRole('status')).toHaveTextContent('Total del servidor: 21');
  total = 0; await userEvent.selectOptions(screen.getByLabelText('Estado'), 'Cancelada'); await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' }));
  await screen.findByText('No hay citas para estos filtros'); expect(requests('agenda-diaria').at(-1).params).toMatchObject({ page: 1, estado: 'Cancelada' });
  expect(screen.getByRole('status')).toHaveTextContent('Total del servidor: 0');
});
it.each(['cantidades', 'agenda-diaria'])('%s retira datos durante carga/error y permite reconsulta sin filtrar detalles técnicos', async tipo => {
  mount(); await preparar(tipo); await consultar();
  if (tipo === 'cantidades') await screen.findByRole('region', { name: 'Cantidades del período' }); else await screen.findByRole('table');
  deferred = true; await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' }));
  expect(region()).toHaveAttribute('aria-busy', 'true'); expect(screen.queryByRole('table')).not.toBeInTheDocument(); expect(screen.queryByRole('region', { name: 'Cantidades del período' })).not.toBeInTheDocument();
  await act(async () => resolvePending()); deferred = false; failure = 500;
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' })); await screen.findByRole('alert');
  expect(document.body).not.toHaveTextContent(/SQL PRIVADO|SECRETO/); failure = null;
  await userEvent.click(screen.getByRole('button', { name: 'Volver a consultar' }));
  if (tipo === 'cantidades') await screen.findByRole('region', { name: 'Cantidades del período' }); else await screen.findByRole('table');
});
it.each(['cantidades', 'agenda-diaria'])('%s respeta espera 429 y no reenvía al pulsar consultar', async tipo => {
  mount(); await preparar(tipo); failure = 429; await consultar(); await screen.findByRole('alert');
  expect(screen.getByRole('button', { name: 'Consultar reporte' })).toBeDisabled(); expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeDisabled();
  expect(requests(tipo)).toHaveLength(1);
});
it.each([
  ['cantidades', 'tipo'], ['cantidades', 'sesion'], ['cantidades', 'salir'],
  ['agenda-diaria', 'tipo'], ['agenda-diaria', 'sesion'], ['agenda-diaria', 'salir'],
])('%s descarta respuesta al cambiar %s', async (tipo, cambio) => {
  const view = mount(); await preparar(tipo); deferred = true; await consultar(); const request = requests(tipo)[0];
  if (cambio === 'tipo') await tipoReporte(tipo === 'cantidades' ? 'agenda-diaria' : 'cantidades');
  else if (cambio === 'sesion') act(() => avanzarSesion()); else view.unmount();
  expect(request.signal.aborted).toBe(true); await act(async () => resolvePending());
  expect(screen.queryByRole('table')).not.toBeInTheDocument(); expect(screen.queryByRole('region', { name: 'Cantidades del período' })).not.toBeInTheDocument();
});
it('cambiar entre agregado y agenda limpia filtros incompatibles y no atribuye respuestas viejas a otra fecha', async () => {
  mount(); await preparar('cantidades'); await consultar(); await screen.findByRole('region', { name: 'Cantidades del período' });
  await tipoReporte('agenda-diaria'); expect(screen.queryByRole('region', { name: 'Cantidades del período' })).not.toBeInTheDocument();
  expect(screen.getByLabelText('Fecha')).toHaveValue(''); await seleccionarMedico(); deferred = true; await consultar(); const old = resolvePending;
  deferred = false; fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-11-02' } }); total = 0;
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); await screen.findByText('No hay citas para estos filtros');
  await act(async () => old()); expect(screen.queryByRole('table')).not.toBeInTheDocument(); expect(region()).toHaveTextContent('Fecha consultada: 02/11/2026');
});
