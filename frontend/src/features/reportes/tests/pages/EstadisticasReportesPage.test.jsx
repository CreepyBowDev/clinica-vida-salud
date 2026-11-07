import { createContext, useContext } from 'react';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, avanzarSesion } from '../../../../api/api';
import ReportesPage from '../../pages/ReportesPage';
import { medico, especialidad, pagina, reporte, sesion } from '../utils/reporteFixtures';

const auth = vi.hoisted(() => ({}));
vi.mock('../../../../hooks/useAuth', () => ({ useAuth: () => useContext(auth.Context) }));
auth.Context = createContext(null);
const original = api.defaults.adapter;
let total, calls, pendiente, resolver, corrupto, fallo;
const summary = () => screen.queryByRole('region', { name: 'Resumen estadístico' });
const results = () => screen.getByRole('region', { name: 'Resultados del reporte' });
function mount() {
  const content = () => <auth.Context.Provider value={{ status: 'authenticated', usuario: auth.usuario }}><ReportesPage /></auth.Context.Provider>;
  const view = render(content()); return { ...view, refresh: () => view.rerender(content()) };
}
async function consultar(fecha = '2026-10-31') {
  fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: fecha } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' }));
}
beforeEach(() => {
  total = 21; calls = []; pendiente = false; resolver = null; corrupto = false; fallo = null; auth.usuario = sesion();
  // Adapter simulado: servicios, validadores y hook reales; sin Laravel/SQL.
  api.defaults.adapter = async config => {
    if (['/agenda/medicos', '/especialidades'].includes(config.url)) return { config, status: 200, headers: {}, data: pagina([config.url === '/agenda/medicos' ? medico : especialidad], config.params) };
    if (config.url !== '/reportes/citas/programadas') throw new Error('Petición no prevista');
    calls.push(config);
    if (fallo) throw new AxiosError('PRIVADO', undefined, config, null, { config, status: fallo, headers: {}, data: { error: { message: 'SQL PRIVADO' } } });
    const data = reporte(config.params, total);
    if (corrupto) data.meta.estadisticas.series[0].grafico.maximo++;
    if (pendiente) return new Promise(resolve => { resolver = () => resolve({ config, status: 200, headers: {}, data }); });
    return { config, status: 200, headers: {}, data };
  };
});
afterEach(() => { api.defaults.adapter = original; });
it('resumen → tabla estadística → detalle; borrador/selectores no alteran resultado ni actualizar', async () => {
  mount(); await consultar(); await screen.findByText('CITA-1');
  const estadistica = screen.getByRole('table', { name: /Cantidades por médico/ });
  const detalle = screen.getByRole('table', { name: 'Citas Programadas para una fecha' });
  expect(summary().compareDocumentPosition(detalle) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  expect(estadistica.compareDocumentPosition(detalle) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  expect(summary()).toHaveTextContent('Cantidad total (citas)21');
  fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-11-02' } });
  await userEvent.click(screen.getByText('Médico: Todos', { selector: 'summary' }));
  await userEvent.click(await screen.findByRole('button', { name: 'Seleccionar Médica De prueba' }));
  expect(summary()).toHaveTextContent('Cantidad total (citas)21'); expect(results()).toHaveTextContent('Fecha consultada: 31/10/2026');
  total = 7; await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' })); await screen.findByText('CITA-1');
  expect(calls.at(-1).params.fecha).toBe('2026-10-31'); expect(calls.at(-1).params).not.toHaveProperty('id_medico');
  expect(summary()).toHaveTextContent('Cantidad total (citas)7');
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); await screen.findByText('CITA-1');
  expect(calls.at(-1).params).toMatchObject({ fecha: '2026-11-02', id_medico: medico.id_medico });
  expect(results()).toHaveFocus();
  await userEvent.click(screen.getByRole('button', { name: 'Limpiar filtros' })); expect(summary()).toBeNull();
});
it('página 2 y fuera de rango conservan estadísticas positivas y distinguen vacío real', async () => {
  mount(); await consultar(); await screen.findByText('CITA-1');
  await userEvent.click(within(results()).getByRole('button', { name: 'Siguiente' })); await screen.findByText('CITA-21');
  expect(summary()).toHaveTextContent('Cantidad total (citas)21');
  total = 4; await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' })); await screen.findByText('Esta página no contiene filas');
  expect(summary()).toHaveTextContent('Cantidad total (citas)4'); expect(summary()).not.toHaveTextContent('Sin resultados');
  total = 0; await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' })); await screen.findByText('No hay citas Programadas para estos filtros');
  expect(summary()).toHaveTextContent('Sin resultados para los filtros aplicados.');
});
it('carga, cancelación pagehide y respuesta obsoleta nunca mezclan estadísticas/filas', async () => {
  mount(); await consultar(); await screen.findByText('CITA-1');
  pendiente = true; await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' }));
  await waitFor(() => expect(resolver).toBeTypeOf('function')); const old = resolver;
  expect(summary()).toBeNull(); expect(screen.queryByText('CITA-1')).toBeNull(); expect(results()).toHaveAttribute('aria-busy', 'true');
  act(() => window.dispatchEvent(new Event('pagehide'))); expect(calls.at(-1).signal.aborted).toBe(true);
  pendiente = false; total = 3; act(() => window.dispatchEvent(new Event('pageshow'))); await screen.findByText('CITA-1');
  await act(async () => old()); expect(summary()).toHaveTextContent('Cantidad total (citas)3');
});
it.each([403, 419, 422, 429, 500, 'invalido'])('error %s retira todas las estadísticas sin exponer respuesta ni fallback', async status => {
  mount(); await consultar(); await screen.findByText('CITA-1');
  if (status === 'invalido') corrupto = true; else fallo = status;
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' })); await screen.findByRole('alert');
  expect(summary()).toBeNull(); expect(screen.queryByText('CITA-1')).toBeNull(); expect(screen.queryByRole('button', { name: 'Descargar PDF' })).toBeNull();
  expect(document.body).not.toHaveTextContent('PRIVADO');
});
it.each(['sesion', 'identidad', 'tipo', 'limpiar'])('descarta agregados pendientes al cambiar %s', async cambio => {
  const view = mount(); pendiente = true; await consultar(); await waitFor(() => expect(resolver).toBeTypeOf('function'));
  if (cambio === 'sesion') act(() => avanzarSesion());
  if (cambio === 'identidad') { auth.usuario = sesion('Administrador', '8'); view.refresh(); }
  if (cambio === 'tipo') await userEvent.selectOptions(screen.getByLabelText('Reporte'), 'cantidades');
  if (cambio === 'limpiar') await userEvent.click(screen.getByRole('button', { name: 'Limpiar filtros' }));
  expect(calls[0].signal.aborted).toBe(true); await act(async () => resolver()); expect(summary()).toBeNull();
});
