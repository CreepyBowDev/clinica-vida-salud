import { createContext, useContext } from 'react';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, avanzarSesion } from '../../../../api/api';
import ReportesPage from '../../pages/ReportesPage';
import { medico, especialidad, pagina, reporte, sesion } from '../utils/reporteFixtures';
import { pacientesReporte } from '../utils/pacientesReporteFixtures';

const auth = vi.hoisted(() => ({}));
vi.mock('../../../../hooks/useAuth', () => ({ useAuth: () => useContext(auth.Context) }));
auth.Context = createContext(null);
const original = api.defaults.adapter;
let calls, total, failure, deferred, resolvePending;
function mount() {
  const content = () => <auth.Context.Provider value={{ status: 'authenticated', usuario: auth.usuario }}><ReportesPage /></auth.Context.Provider>;
  const view = render(content()); return { ...view, refresh: () => view.rerender(content()) };
}
const requests = () => calls.filter(c => c.url === '/reportes/pacientes/registrados');
async function seleccionarReporte() { await userEvent.selectOptions(screen.getByLabelText('Reporte'), 'pacientes-registrados'); }
async function consultar() {
  fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-10-31' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' }));
}
beforeEach(() => {
  auth.usuario = sesion(); calls = []; total = 1; failure = null; deferred = false; resolvePending = null;
  api.defaults.adapter = async config => {
    calls.push(config);
    if (config.url === '/agenda/medicos' || config.url === '/especialidades') return { config, status: 200, headers: {}, data: pagina([config.url === '/agenda/medicos' ? medico : especialidad], config.params) };
    if (config.url !== '/reportes/pacientes/registrados') {
      if (config.url === '/reportes/citas/programadas') return { config, status: 200, headers: {}, data: reporte(config.params) };
      throw new Error('Petición no prevista');
    }
    if (failure) throw new AxiosError('SECRETO TECNICO', undefined, config, null, { config, status: failure, headers: { 'retry-after': '2' }, data: { error: { message: 'SQL PRIVADO', details: { activo: ['Revisa la actividad.'] } } } });
    const data = pacientesReporte(config.params, total);
    for (const row of data.data) Object.assign(row, { documento_identidad: 'CI-PRIVADO', telefono: 'CONTACTO-PRIVADO', usuario: 'CUENTA-PRIVADA', diagnostico: 'CLINICA-PRIVADA' });
    if (deferred) return new Promise(resolve => { resolvePending = () => resolve({ config, status: 200, headers: {}, data }); });
    return { config, status: 200, headers: {}, data };
  };
});
afterEach(() => { api.defaults.adapter = original; vi.restoreAllMocks(); });
it('comparte pantalla, explica creación de ficha y no ofrece filtros/operaciones de citas ni consulta listados amplios', async () => {
  mount(); await seleccionarReporte(); expect(requests()).toHaveLength(0);
  expect(screen.getByText(/El período corresponde a la creación de la ficha/)).toBeInTheDocument();
  expect(screen.queryByLabelText('Estado')).not.toBeInTheDocument(); expect(screen.queryByText('Médico: Todos', { selector: 'summary' })).not.toBeInTheDocument();
  expect(within(screen.getByLabelText('Orden')).getAllByRole('option').map(v => v.value)).toEqual(['registro_asc', 'registro_desc', 'nombre_asc']);
  await consultar(); const table = await screen.findByRole('table', { name: /^Pacientes registrados por fecha/ });
  expect(table).toHaveTextContent('Paciente ficticio'); expect(table).toHaveTextContent('Inactivo'); expect(table).toHaveTextContent('ID: 9007199254740993');
  expect(table).toHaveTextContent('31/10/2026, 22:30'); expect(within(table).getAllByRole('columnheader')).toHaveLength(5);
  for (const marker of ['CI-PRIVADO', 'CONTACTO-PRIVADO', 'CUENTA-PRIVADA', 'CLINICA-PRIVADA']) expect(document.body).not.toHaveTextContent(marker);
  expect(calls.some(c => c.url === '/pacientes' || c.url.startsWith('/pacientes/'))).toBe(false);
  expect(requests()[0]).toMatchObject({ method: 'get', sessionBound: true, params: { fecha: '2026-10-31', orden: 'registro_asc', page: 1, per_page: 20 } });
});
it.each([['0', 'Inactivo'], ['1', 'Activo']])('filtra actividad %s sin enviar filtros de citas y conserva total del servidor', async (activo, label) => {
  mount(); await seleccionarReporte(); await userEvent.selectOptions(screen.getByLabelText('Actividad'), activo);
  await userEvent.selectOptions(screen.getByLabelText('Orden'), 'nombre_asc'); await consultar(); const table = await screen.findByRole('table', { name: /^Pacientes registrados por fecha/ });
  expect(table).toHaveTextContent(label); expect(requests().at(-1).params).toEqual({ fecha: '2026-10-31', activo, orden: 'nombre_asc', page: 1, per_page: 20 });
  expect(screen.getByRole('status')).toHaveTextContent('Total del servidor: 1 pacientes registrados');
});
it('admite rango/orden/tamaño, pagina sin sumar filas y reinicia página al cambiar actividad', async () => {
  total = 51; mount(); await seleccionarReporte(); await userEvent.selectOptions(screen.getByLabelText('Período'), 'rango');
  fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2026-10-01' } }); fireEvent.change(screen.getByLabelText('Hasta'), { target: { value: '2026-10-31' } });
  await userEvent.selectOptions(screen.getByLabelText('Orden'), 'registro_desc'); await userEvent.selectOptions(screen.getByLabelText('Pacientes por página'), '50');
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); await screen.findByRole('table', { name: /^Pacientes registrados por fecha/ });
  expect(screen.getByRole('status')).toHaveTextContent('Total del servidor: 51');
  const region = screen.getByRole('region', { name: 'Resultados del reporte' }); await userEvent.click(within(region).getByRole('button', { name: 'Siguiente' })); await screen.findByText('PAC-9007199254741043');
  expect(requests().at(-1).params).toEqual({ desde: '2026-10-01', hasta: '2026-10-31', orden: 'registro_desc', per_page: 50, page: 2 });
  await userEvent.selectOptions(screen.getByLabelText('Actividad'), '1'); expect(region).toHaveTextContent('Actividad: Todas las fichas');
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); await waitFor(() => expect(requests().at(-1).params).toMatchObject({ page: 1, activo: '1' }));
});
it('vacío y página que quedó fuera del final mantienen el total real', async () => {
  total = 21; mount(); await seleccionarReporte(); await consultar(); await screen.findByRole('table', { name: /^Pacientes registrados por fecha/ });
  await userEvent.click(within(screen.getByRole('region', { name: 'Resultados del reporte' })).getByRole('button', { name: 'Siguiente' })); await screen.findByText('PAC-9007199254741013');
  total = 1; await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' }));
  await screen.findByText('Esta página no contiene filas'); expect(screen.getByRole('status')).toHaveTextContent('Total del servidor: 1');
  expect(screen.getByRole('region', { name: 'Resumen estadístico' })).toHaveTextContent('Cantidad total (fichas)1');
  await userEvent.click(within(screen.getByRole('region', { name: 'Resultados del reporte' })).getByRole('button', { name: 'Anterior' })); await screen.findByRole('table', { name: /^Pacientes registrados por fecha/ });
  total = 0; await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' }));
  await screen.findByText('No hay pacientes registrados para estos filtros'); expect(screen.getByRole('status')).toHaveTextContent('Total del servidor: 0');
});
it.each([403, 404, 419, 422, 429, 500])('HTTP %s limpia resultados, permite corregir/reconsultar y no filtra errores técnicos', async status => {
  mount(); await seleccionarReporte(); await consultar(); await screen.findByRole('table', { name: /^Pacientes registrados por fecha/ }); failure = status;
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' })); await screen.findByRole('alert');
  expect(screen.queryByRole('table')).not.toBeInTheDocument(); expect(document.body).not.toHaveTextContent(/SQL PRIVADO|SECRETO TECNICO/);
  if (status === 422) expect(screen.getByText('Revisa la actividad.')).toBeInTheDocument();
  if (status === 429) expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeDisabled();
  if (status === 500) expect(screen.getByRole('alert')).toHaveTextContent('No pudimos consultar el reporte');
});
it('fallo de conexión no conserva el listado ni afirma totales nuevos', async () => {
  mount(); await seleccionarReporte(); await consultar(); await screen.findByRole('table', { name: /^Pacientes registrados por fecha/ });
  api.defaults.adapter = async () => { throw new AxiosError('RED PRIVADA'); };
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' })); await screen.findByRole('alert');
  expect(screen.queryByRole('table')).not.toBeInTheDocument(); expect(screen.queryByRole('status')).not.toBeInTheDocument(); expect(document.body).not.toHaveTextContent('RED PRIVADA');
});
it.each(['tipo', 'sesion', 'identidad', 'salir'])('descarta respuesta de pacientes al cambiar %s', async tipo => {
  const view = mount(); await seleccionarReporte(); deferred = true; await consultar(); const request = requests().at(-1);
  if (tipo === 'tipo') await userEvent.selectOptions(screen.getByLabelText('Reporte'), 'programadas');
  else if (tipo === 'sesion') act(() => avanzarSesion());
  else if (tipo === 'identidad') { auth.usuario = sesion('Administrador', '8'); view.refresh(); }
  else view.unmount();
  expect(request.signal.aborted).toBe(true); await act(async () => resolvePending()); expect(screen.queryByText('PAC-9007199254740993')).not.toBeInTheDocument();
});
it('cambiar entre citas y pacientes limpia filtros incompatibles y usa órdenes propios', async () => {
  mount(); await userEvent.selectOptions(screen.getByLabelText('Reporte'), 'por-estado');
  await userEvent.selectOptions(screen.getByLabelText('Estado obligatorio'), 'Cancelada'); await seleccionarReporte();
  expect(screen.getByLabelText('Orden')).toHaveValue('registro_asc'); await userEvent.selectOptions(screen.getByLabelText('Actividad'), '0');
  await consultar(); await screen.findByRole('table', { name: /^Pacientes registrados por fecha/ }); await userEvent.click(screen.getByRole('button', { name: 'Limpiar filtros' }));
  expect(screen.queryByRole('table')).not.toBeInTheDocument(); expect(screen.getByLabelText('Actividad')).toHaveValue('');
  await userEvent.selectOptions(screen.getByLabelText('Reporte'), 'programadas'); expect(screen.getByLabelText('Orden')).toHaveValue('fecha_asc');
  expect(screen.queryByLabelText('Actividad')).not.toBeInTheDocument();
});
