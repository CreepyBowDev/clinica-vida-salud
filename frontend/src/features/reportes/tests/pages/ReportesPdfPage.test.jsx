import { createContext, useContext } from 'react';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, avanzarSesion } from '../../../../api/api';
import ReportesPage from '../../pages/ReportesPage';
import { medico, especialidad, pagina, reporte, sesion } from '../utils/reporteFixtures';
import { pacientesReporte } from '../utils/pacientesReporteFixtures';
import { cantidadesReporte, agendaReporte } from '../utils/cantidadesAgendaFixtures';

const auth = vi.hoisted(() => ({}));
vi.mock('../../../../hooks/useAuth', () => ({ useAuth: () => useContext(auth.Context) }));
auth.Context = createContext(null);
const original = api.defaults.adapter;
let calls, total, failure, deferred, resolvePending, click;
const pdfCalls = () => calls.filter(c => c.url.endsWith('/pdf'));
function mount() {
  const content = () => <auth.Context.Provider value={auth.value}><ReportesPage /></auth.Context.Provider>;
  const view = render(content()); return { ...view, refresh: () => view.rerender(content()) };
}
beforeEach(() => {
  auth.value = { status: 'authenticated', usuario: sesion() };
  calls = []; total = 1; failure = null; deferred = false; resolvePending = null;
  vi.stubGlobal('URL', class extends URL {
    static createObjectURL = vi.fn(() => 'blob:reporte');
    static revokeObjectURL = vi.fn();
  });
  click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
  api.defaults.adapter = async config => {
    calls.push(config);
    let data;
    if (config.url.endsWith('/pdf')) {
      const response = { config, status: 200, headers: { 'content-type': 'application/pdf',
        'content-disposition': 'attachment; filename="reporte-citas-programadas-20261107-123000.pdf"' },
      data: new Blob(['%PDF-1.7\nDocumento simulado, no PDF Laravel\n%%EOF'], { type: 'application/pdf' }) };
      if (failure) throw new AxiosError('SECRETO', undefined, config, null, { config, status: failure, headers: { 'retry-after': '2' },
        data: new Blob([JSON.stringify({ error: { code: 'VALIDACION_ERROR', message: 'SQL PRIVADO', details: { exportacion:
          ['El reporte supera el límite de 500 filas. Acota los filtros. No se generó un PDF parcial.'] } } })], { type: 'application/json' }) });
      if (deferred) return new Promise(resolve => { resolvePending = () => resolve(response); });
      return response;
    }
    if (config.url === '/agenda/medicos') data = pagina([medico], config.params);
    else if (config.url === '/especialidades') data = pagina([especialidad], config.params);
    else if (config.url === '/reportes/pacientes/registrados') data = pacientesReporte(config.params, total);
    else if (config.url === '/reportes/citas/cantidades') data = cantidadesReporte(config.params, { atendidas: total, canceladas: 0, inasistencias: 0, total });
    else if (config.url === '/reportes/agenda-diaria') data = agendaReporte(config.params, total);
    else data = reporte(config.params, total, config.url.split('/citas/')[1]);
    return { config, status: 200, headers: {}, data };
  };
});
afterEach(() => { cleanup(); api.defaults.adapter = original; vi.restoreAllMocks(); vi.unstubAllGlobals(); });
async function preparar(tipo = 'programadas', rango = false) {
  if (tipo !== 'programadas') await userEvent.selectOptions(screen.getByLabelText('Reporte'), tipo);
  if (tipo !== 'pacientes-registrados') {
    await userEvent.click(screen.getByText('Médico: Todos', { selector: 'summary' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Seleccionar Médica De prueba' }));
    if (tipo !== 'agenda-diaria') {
      await userEvent.click(screen.getByText('Especialidad: Todos', { selector: 'summary' }));
      await userEvent.click(await screen.findByRole('button', { name: 'Seleccionar Especialidad ficticia' }));
    }
  } else await userEvent.selectOptions(screen.getByLabelText('Actividad'), '0');
  if (!['programadas', 'cantidades', 'pacientes-registrados'].includes(tipo)) {
    await userEvent.selectOptions(screen.getByLabelText(tipo === 'por-estado' ? 'Estado obligatorio' : 'Estado'), 'Confirmada');
  }
  if (rango) {
    await userEvent.selectOptions(screen.getByLabelText('Período'), 'rango');
    fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2026-10-01' } });
    fireEvent.change(screen.getByLabelText('Hasta'), { target: { value: '2026-10-31' } });
  } else fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-10-31' } });
  if (screen.queryByLabelText('Orden')) await userEvent.selectOptions(screen.getByLabelText('Orden'), tipo === 'pacientes-registrados' ? 'nombre_asc' : 'fecha_desc');
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' }));
  return screen.findByRole('button', { name: 'Descargar PDF' });
}
it.each(['programadas', 'por-medico', 'por-especialidad', 'por-estado', 'pacientes-registrados', 'cantidades', 'agenda-diaria'])('%s exporta filtros aplicados (no borrador), sin paginación, sin alterar resultados', async tipo => {
  total = tipo === 'cantidades' ? 504 : 21;
  mount(); expect(screen.queryByRole('button', { name: 'Descargar PDF' })).toBeNull();
  await preparar(tipo, !['programadas', 'agenda-diaria'].includes(tipo));
  if (tipo !== 'cantidades') {
    await userEvent.click(within(screen.getByRole('region', { name: 'Resultados del reporte' })).getByRole('button', { name: 'Siguiente' }));
    await screen.findByRole('button', { name: 'Descargar PDF' });
  }
  const applied = calls.filter(c => c.url.startsWith('/reportes/')).at(-1);
  const input = screen.queryByLabelText('Fecha') ?? screen.getByLabelText('Desde');
  fireEvent.change(input, { target: { value: '2026-11-02' } });
  if (screen.queryByLabelText('Orden')) await userEvent.selectOptions(screen.getByLabelText('Orden'), tipo === 'pacientes-registrados' ? 'registro_asc' : 'fecha_asc');
  if (screen.queryByLabelText('Actividad')) await userEvent.selectOptions(screen.getByLabelText('Actividad'), '1');
  await userEvent.click(screen.getByRole('button', { name: 'Descargar PDF' }));
  await waitFor(() => expect(click).toHaveBeenCalledOnce());
  expect(pdfCalls()).toHaveLength(1); expect(pdfCalls()[0].url).toBe(`${applied.url}/pdf`);
  expect(pdfCalls()[0].params).toEqual(Object.fromEntries(Object.entries(applied.params).filter(([key]) => !['page', 'per_page'].includes(key))));
  expect(screen.getByRole('region', { name: 'Resultados del reporte' })).toHaveTextContent(tipo === 'cantidades' ? '504' : '21');
  expect(pdfCalls()[0]).toMatchObject({ method: 'get', responseType: 'blob', sessionBound: true });
});
it.each(['programadas', 'cantidades'])('vacío válido %s permite descargar, no exige filas', async tipo => {
  total = 0; mount(); const button = await preparar(tipo); await userEvent.click(button);
  await waitFor(() => expect(click).toHaveBeenCalledOnce());
});
it.each([403, 419, 422, 429, 500])('error PDF Blob %s preserva la consulta, informa sin descargar ni filtrar SQL', async status => {
  mount(); await preparar(); failure = status;
  await userEvent.click(screen.getByRole('button', { name: 'Descargar PDF' }));
  const alert = await screen.findByRole('alert');
  expect(screen.getByRole('table')).toBeInTheDocument(); expect(URL.createObjectURL).not.toHaveBeenCalled();
  expect(document.body).not.toHaveTextContent('SQL PRIVADO'); expect(pdfCalls()).toHaveLength(1);
  if (status === 422) expect(alert).toHaveTextContent('No se generó un PDF parcial');
  if (status === 429) expect(screen.getByRole('button', { name: 'Descargar PDF' })).toBeDisabled();
});
it('401 real del interceptor retira resultados y descarga sin reintentar', async () => {
  mount(); await preparar(); failure = 401;
  await userEvent.click(screen.getByRole('button', { name: 'Descargar PDF' }));
  expect(await screen.findByRole('alert')).toHaveTextContent('La sesión cambió');
  expect(screen.queryByRole('table')).toBeNull(); expect(URL.createObjectURL).not.toHaveBeenCalled();
});
it.each(['tipo', 'consulta', 'actualizar', 'limpiar', 'identidad', 'rol', 'estado', 'epoca', 'salir'])('%s cancela PDF y descarta respuesta obsoleta', async mode => {
  const view = mount(); await preparar(); deferred = true;
  await userEvent.click(screen.getByRole('button', { name: 'Descargar PDF' }));
  await waitFor(() => expect(resolvePending).toBeTypeOf('function'));
  const signal = pdfCalls()[0].signal;
  if (mode === 'tipo') await userEvent.selectOptions(screen.getByLabelText('Reporte'), 'cantidades');
  else if (mode === 'consulta') {
    fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-11-02' } });
    await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' }));
  } else if (mode === 'actualizar') await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' }));
  else if (mode === 'limpiar') await userEvent.click(screen.getByRole('button', { name: 'Limpiar filtros' }));
  else if (mode === 'epoca') act(() => avanzarSesion());
  else if (mode === 'salir') view.unmount();
  else {
    auth.value = mode === 'estado' ? { status: 'anonymous', usuario: null } : { status: 'authenticated', usuario: sesion(mode === 'rol' ? 'Médico' : 'Administrador', '8') };
    view.refresh();
  }
  expect(signal.aborted).toBe(true); await act(async () => resolvePending());
  expect(URL.createObjectURL).not.toHaveBeenCalled(); expect(click).not.toHaveBeenCalled();
});
