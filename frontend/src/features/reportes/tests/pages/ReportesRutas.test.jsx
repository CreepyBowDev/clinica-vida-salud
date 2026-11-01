import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { destinoSeguro } from '../../../../constants/roles';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { medico, especialidad, pagina, reporte, sesion } from '../utils/reporteFixtures';
import { pacientesReporte } from '../utils/pacientesReporteFixtures';
import { cantidadesReporte, agendaReporte } from '../utils/cantidadesAgendaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
let calls, fail;
function mount() {
  const router = createMemoryRouter(crearRutas(), { initialEntries: ['/reportes'] });
  return render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
}
beforeEach(() => {
  vi.clearAllMocks(); calls = []; fail = false; consultarSesion.mockResolvedValue(sesion());
  api.defaults.adapter = async config => {
    calls.push(config);
    if (fail) throw new AxiosError('SECRETO', undefined, config, null, { config, status: 401, headers: {}, data: {} });
    const data = config.url === '/agenda/medicos' ? pagina([medico], config.params)
      : config.url === '/especialidades' ? pagina([especialidad], config.params)
        : config.url === '/reportes/pacientes/registrados' ? pacientesReporte(config.params)
          : config.url === '/reportes/citas/cantidades' ? cantidadesReporte(config.params)
            : config.url === '/reportes/agenda-diaria' ? agendaReporte(config.params) : reporte(config.params, 1, config.url.split('/citas/')[1]);
    return { config, status: 200, headers: {}, data };
  };
});
afterEach(() => { api.defaults.adapter = original; vi.restoreAllMocks(); });
it('Administrador usa ruta, sidebar, servicios y Axios reales con API simulada de solo lectura', async () => {
  mount(); await screen.findByRole('heading', { name: 'Reportes administrativos' });
  expect(screen.getByRole('link', { name: 'Consultar reportes' })).toHaveAttribute('aria-current', 'page');
  expect(document.title).toBe('Consultar reportes | Clínica Vida Salud');
  fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-10-31' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); await screen.findByText('CITA-1');
  expect(calls.find(c => c.url === '/reportes/citas/programadas')).toMatchObject({ method: 'get', sessionBound: true, params: { fecha: '2026-10-31', orden: 'fecha_asc', page: 1, per_page: 20 } });
  expect(calls.every(c => c.method === 'get' && ['/agenda/medicos', '/especialidades', '/reportes/citas/programadas'].includes(c.url))).toBe(true);
});
it.each(['Paciente', 'Médico', 'Recepcionista'])('%s no accede ni solicita reportes/catálogos', async rol => {
  consultarSesion.mockResolvedValue(sesion(rol)); mount(); await screen.findByRole('heading', { name: 'Acceso no permitido' });
  expect(calls).toHaveLength(0); expect(screen.queryByRole('link', { name: 'Consultar reportes' })).not.toBeInTheDocument();
  expect(destinoSeguro('/reportes', rol)).toBe('/inicio');
});
it('sin sesión redirige a login sin pedir datos', async () => {
  consultarSesion.mockRejectedValue({ response: { status: 401 } }); mount();
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(calls).toHaveLength(0);
  expect(destinoSeguro('/reportes', 'Administrador')).toBe('/reportes');
});
it('401 del interceptor limpia resultados y redirige a login', async () => {
  mount(); await screen.findByLabelText('Fecha'); fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-10-31' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); await screen.findByText('CITA-1'); fail = true;
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' }));
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(screen.queryByText('CITA-1')).not.toBeInTheDocument();
});
it.each(['por-medico', 'por-especialidad', 'por-estado'])('%s integra selección, servicio y Axios reales sin cambiar de ruta', async tipo => {
  mount(); await screen.findByLabelText('Reporte'); await userEvent.selectOptions(screen.getByLabelText('Reporte'), tipo);
  if (tipo === 'por-medico') {
    await userEvent.click(screen.getByText('Médico: Todos', { selector: 'summary' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Seleccionar Médica De prueba' }));
  } else if (tipo === 'por-especialidad') {
    await userEvent.click(screen.getByText('Especialidad: Todos', { selector: 'summary' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Seleccionar Especialidad ficticia' }));
  } else await userEvent.selectOptions(screen.getByLabelText('Estado obligatorio'), 'Confirmada');
  fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-10-31' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); await screen.findByText('CITA-1');
  expect(calls.find(c => c.url === `/reportes/citas/${tipo}`)).toMatchObject({ method: 'get', sessionBound: true, params: { fecha: '2026-10-31' } });
  expect(calls.filter(c => c.url.startsWith('/reportes/'))).toHaveLength(1);
  expect(screen.getByRole('link', { name: 'Consultar reportes' })).toHaveAttribute('aria-current', 'page');
});
it('pacientes registrados usa el endpoint específico con Auth/Router/Axios y retira datos ante 401', async () => {
  mount(); await screen.findByLabelText('Reporte'); await userEvent.selectOptions(screen.getByLabelText('Reporte'), 'pacientes-registrados');
  fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-10-31' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' })); await screen.findByText('PAC-9007199254740993');
  expect(calls.find(c => c.url === '/reportes/pacientes/registrados')).toMatchObject({ method: 'get', sessionBound: true, params: { fecha: '2026-10-31', orden: 'registro_asc', page: 1, per_page: 20 } });
  expect(calls.some(c => c.url === '/pacientes')).toBe(false); fail = true;
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' })); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(screen.queryByText('PAC-9007199254740993')).not.toBeInTheDocument();
});
it.each(['cantidades', 'agenda-diaria'])('%s integra Auth/Router/Axios con ruta administrativa y retira datos ante 401', async tipo => {
  mount(); await screen.findByLabelText('Reporte'); await userEvent.selectOptions(screen.getByLabelText('Reporte'), tipo);
  if (tipo === 'agenda-diaria') {
    await userEvent.click(screen.getByText('Médico: Todos', { selector: 'summary' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Seleccionar Médica De prueba' }));
  }
  fireEvent.change(screen.getByLabelText('Fecha'), { target: { value: '2026-10-31' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar reporte' }));
  if (tipo === 'cantidades') await screen.findByRole('region', { name: 'Cantidades del período' }); else await screen.findByText('CITA-1');
  const url = tipo === 'cantidades' ? '/reportes/citas/cantidades' : '/reportes/agenda-diaria';
  expect(calls.find(c => c.url === url)).toMatchObject({ method: 'get', sessionBound: true }); expect(calls.some(c => c.url === '/agenda')).toBe(false);
  fail = true; await userEvent.click(screen.getByRole('button', { name: 'Actualizar reporte' })); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(screen.queryByText('CITA-1')).not.toBeInTheDocument(); expect(screen.queryByRole('region', { name: 'Cantidades del período' })).not.toBeInTheDocument();
});
