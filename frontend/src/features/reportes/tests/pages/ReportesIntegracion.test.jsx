import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it } from 'vitest';
import { api } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { medico, especialidad, pagina, reporte, sesion } from '../utils/reporteFixtures';
import { pacientesReporte } from '../utils/pacientesReporteFixtures';
import { cantidadesReporte, agendaReporte } from '../utils/cantidadesAgendaFixtures';

const original = api.defaults.adapter;
let account, nextLogin, calls, failure, defer, resolvePending;
const reportCalls = () => calls.filter(c => c.url.startsWith('/reportes/'));
function mount() {
  const router = createMemoryRouter(crearRutas(), { initialEntries: ['/reportes'] });
  return { router, ...render(<AuthProvider><RouterProvider router={router} /></AuthProvider>) };
}
function httpError(config, status) {
  return new AxiosError('CONTENIDO TECNICO PRIVADO', undefined, config, null, { config, status, headers: { 'retry-after': '2' },
    data: { success: false, error: { code: 'ERROR_SIMULADO', message: 'NO MOSTRAR SQL', details: { fecha: ['Revisa la fecha.'] } } } });
}
beforeEach(() => {
  account = sesion(); nextLogin = sesion(); calls = []; failure = null; defer = false; resolvePending = null;
  api.defaults.adapter = async config => {
    calls.push(config);
    let data, status = 200;
    if (config.url.endsWith('/sanctum/csrf-cookie')) status = 204;
    else if (config.url === '/auth/me') { if (!account) throw httpError(config, 401); data = { data: account }; }
    else if (config.url === '/auth/login') { account = nextLogin; data = { data: account }; }
    else if (config.url === '/auth/logout') { account = null; status = 204; }
    else if (config.url === '/agenda/medicos') data = pagina([medico], config.params);
    else if (config.url === '/especialidades') data = pagina([especialidad], config.params);
    else if (config.url.startsWith('/reportes/')) {
      if (failure === 'red') throw new AxiosError('RED PRIVADA', 'ERR_NETWORK', config);
      if (failure) throw httpError(config, failure);
      data = config.url === '/reportes/citas/cantidades' ? cantidadesReporte(config.params)
        : config.url === '/reportes/agenda-diaria' ? agendaReporte(config.params)
          : config.url === '/reportes/pacientes/registrados' ? pacientesReporte(config.params) : reporte(config.params, 1, config.url.split('/citas/')[1]);
      if (defer) {
        defer = false;
        return new Promise((resolve, reject) => { resolvePending = status => status ? reject(httpError(config, status)) : resolve({ config, status: 200, headers: {}, data }); });
      }
    } else throw new Error('Petición fuera del recorrido previsto');
    return { config, status, headers: {}, data };
  };
});
afterEach(() => { api.defaults.adapter = original; });
async function preparar(tipo) {
  if (tipo !== 'programadas') {
    await userEvent.selectOptions(screen.getByLabelText('Reporte'), tipo);
    expect(screen.getByLabelText('Reporte')).toHaveFocus();
  }
  if (tipo === 'por-medico' || tipo === 'agenda-diaria') {
    await userEvent.click(screen.getByText('Médico: Todos', { selector: 'summary' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Seleccionar Médica De prueba' }));
  } else if (tipo === 'por-especialidad') {
    await userEvent.click(screen.getByText('Especialidad: Todos', { selector: 'summary' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Seleccionar Especialidad ficticia' }));
  } else if (tipo === 'por-estado') await userEvent.selectOptions(screen.getByLabelText('Estado obligatorio'), 'Confirmada');
  fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-10-31' } });
}
async function consultar() { await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); }
async function login() {
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  await userEvent.type(screen.getByLabelText('Correo electrónico'), 'ficticia@example.test');
  await userEvent.type(screen.getByLabelText('Contraseña', { exact: true }), 'Solo-pruebas-123');
  await userEvent.click(screen.getByRole('button', { name: 'Iniciar sesión', exact: true }));
  await screen.findByLabelText('Reporte');
}
it('recorre los siete tipos con Auth/Router/servicios/Axios reales, mantiene foco y consulta solo cada recurso específico', async () => {
  const { router } = mount(); await screen.findByLabelText('Reporte');
  expect(calls.filter(c => c.url === '/auth/me')).toHaveLength(1);
  expect(screen.getByRole('link', { name: 'Generar reportes administrativos' })).toHaveAttribute('aria-current', 'page');
  const types = ['programadas', 'por-medico', 'por-especialidad', 'por-estado', 'pacientes-registrados', 'cantidades', 'agenda-diaria'];
  for (const tipo of types) {
    await preparar(tipo); expect(screen.queryByRole('table')).not.toBeInTheDocument(); await consultar();
    if (tipo === 'cantidades') {
      const region = await screen.findByRole('region', { name: 'Cantidades del período' }); expect(region).toHaveAttribute('aria-live', 'polite');
    } else {
      const table = await screen.findByRole('table', { name: name => !name.startsWith('Cantidades por') }); expect(table).toHaveTextContent('31/10/2026, 22:30');
    }
    expect(screen.getByRole('region', { name: 'Resultados del reporte' })).toHaveFocus(); expect(router.state.location.pathname).toBe('/reportes');
    expect(screen.getByRole('region', { name: 'Resumen estadístico' })).toHaveTextContent('Cantidad total');
  }
  expect(reportCalls().map(c => c.url)).toEqual(['/reportes/citas/programadas', '/reportes/citas/por-medico', '/reportes/citas/por-especialidad',
    '/reportes/citas/por-estado', '/reportes/pacientes/registrados', '/reportes/citas/cantidades', '/reportes/agenda-diaria']);
  expect(calls.every(c => c.method === 'get')).toBe(true); expect(calls.some(c => ['/pacientes', '/agenda'].includes(c.url))).toBe(false);
});
it('login conserva /reportes, logout retira datos y una respuesta 401 de la sesión antigua no invalida al nuevo Administrador', async () => {
  account = null; const { router } = mount(); await login();
  await preparar('pacientes-registrados'); defer = true; await consultar();
  await waitFor(() => expect(resolvePending).toBeTypeOf('function')); const old = resolvePending;
  await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' })); nextLogin = sesion('Administrador', '8'); await login();
  await preparar('programadas'); await consultar(); await screen.findByText('CITA-1');
  await act(async () => old(401)); expect(router.state.location.pathname).toBe('/reportes');
  expect(screen.getByText('CITA-1')).toBeInTheDocument(); expect(screen.queryByText('PAC-9007199254740993')).not.toBeInTheDocument();
  expect(calls.filter(c => c.url.endsWith('/sanctum/csrf-cookie'))).toHaveLength(3);
  expect(calls.filter(c => c.method === 'post').map(c => c.url)).toEqual(['/auth/login', '/auth/logout', '/auth/login']);
});
it.each([
  ['programadas', 403], ['por-medico', 404], ['por-especialidad', 419], ['por-estado', 422],
  ['pacientes-registrados', 429], ['cantidades', 'red'], ['agenda-diaria', 500],
])('%s: error %s conserva sesión/selección sin reenvíos automáticos ni mensajes técnicos', async (tipo, status) => {
  mount(); await screen.findByLabelText('Reporte'); await preparar(tipo); failure = status; await consultar(); await screen.findByRole('alert');
  expect(screen.getByLabelText('Reporte')).toHaveValue(tipo); expect(screen.getByLabelText('Fecha')).toHaveValue('2026-10-31');
  expect(screen.getByRole('link', { name: 'Generar reportes administrativos' })).toBeInTheDocument(); expect(reportCalls()).toHaveLength(1);
  expect(screen.queryByRole('table')).not.toBeInTheDocument(); expect(document.body).not.toHaveTextContent(/NO MOSTRAR SQL|CONTENIDO TECNICO PRIVADO|RED PRIVADA/);
  if (status === 429) expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeDisabled();
  if (status === 419) expect(screen.getByRole('alert')).toHaveTextContent('ha caducado');
});
it('revalidar sesión con rol distinto retira el reporte y descarta la respuesta todavía pendiente', async () => {
  mount(); await screen.findByLabelText('Reporte'); await preparar('programadas'); defer = true; await consultar();
  await waitFor(() => expect(resolvePending).toBeTypeOf('function')); const old = resolvePending;
  account = sesion('Médico', '9'); act(() => window.dispatchEvent(new Event('focus')));
  await screen.findByRole('heading', { name: 'Acceso no permitido' }); await act(async () => old());
  expect(screen.queryByText('CITA-1')).not.toBeInTheDocument(); expect(reportCalls()).toHaveLength(1);
});
it('teclado conserva el foco del selector al cambiar tipo y enfoca validación y resultados explícitos', async () => {
  mount(); await screen.findByLabelText('Reporte'); await preparar('por-medico');
  // Quitar la selección permite comprobar el error de obligatoriedad sin una petición.
  await userEvent.click(screen.getByRole('button', { name: 'Quitar filtro de médico' }));
  const submit = screen.getByRole('button', { name: 'Consultar reporte' }); submit.focus(); await userEvent.keyboard('{Enter}');
  expect(await screen.findByRole('alert')).toHaveFocus(); expect(reportCalls()).toHaveLength(0);
  await userEvent.click(screen.getByRole('button', { name: 'Seleccionar Médica De prueba' }));
  submit.focus(); await userEvent.keyboard('{Enter}'); await screen.findByRole('table', { name: name => !name.startsWith('Cantidades por') });
  expect(screen.getByRole('region', { name: 'Resultados del reporte' })).toHaveFocus();
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' })); await waitFor(() => expect(reportCalls()).toHaveLength(2));
  expect(screen.getByRole('region', { name: 'Resultados del reporte' })).toHaveFocus();
});
