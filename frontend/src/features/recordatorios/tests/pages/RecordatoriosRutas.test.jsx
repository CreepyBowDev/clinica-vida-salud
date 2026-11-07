import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion, iniciarSesion } from '../../../auth/services/auth.service';
import { destinoSeguro } from '../../../../constants/roles';
import { listado, sesion } from '../utils/recordatorioFixtures';
import { pacientes } from '../utils/pacientesFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
let calls, fail, rows;
function mount() {
  const router = createMemoryRouter(crearRutas(), { initialEntries: ['/recordatorios'] });
  return { router, ...render(<AuthProvider><RouterProvider router={router} /></AuthProvider>) };
}
beforeEach(() => {
  vi.clearAllMocks(); calls = []; fail = false; rows = undefined; consultarSesion.mockResolvedValue(sesion());
  api.defaults.adapter = async config => {
    calls.push(config);
    if (fail) throw new AxiosError('SECRETO', undefined, config, null, { config, status: 401, headers: {}, data: {} });
    return { config, status: 200, headers: {}, data: config.url === '/push/suscripciones'
      ? { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } }
      : config.url === '/recordatorios/proxima-cita' ? { data: { cita: null, operacion: null } }
        : config.url === '/pacientes' ? pacientes() : listado(rows) };
  };
});
afterEach(() => { api.defaults.adapter = original; vi.restoreAllMocks(); });
it('Paciente tiene ruta y navegación activas con petición propia de solo lectura', async () => {
  mount(); await screen.findByText('CITA-FICTICIA');
  expect(document.title).toBe('Consultar recordatorios | Clínica Vida Salud');
  expect(screen.getByRole('link', { name: 'Consultar recordatorios' })).toHaveAttribute('aria-current', 'page');
  expect(calls).toHaveLength(2);
  expect(calls.find(c => c.url === '/recordatorios')).toMatchObject({ method: 'get', sessionBound: true, params: { page: 1, per_page: 20 } });
  expect(calls.find(c => c.url === '/push/suscripciones')).toMatchObject({ method: 'get', sessionBound: true, params: { page: 1, per_page: 20 } });
  expect(calls.every(c => !Object.hasOwn(c.params, 'id_paciente'))).toBe(true);
});
it.each(['Médico', 'Recepcionista'])('%s no accede a recordatorios ni consulta API', async rol => {
  consultarSesion.mockResolvedValue(sesion(rol)); mount(); await screen.findByRole('heading', { name: 'Acceso no permitido' });
  expect(calls).toHaveLength(0); expect(screen.queryByRole('link', { name: 'Consultar recordatorios' })).not.toBeInTheDocument();
});
it('Administrador accede desde la misma ruta y navegación; solo consulta al seleccionar paciente', async () => {
  consultarSesion.mockResolvedValue(sesion('Administrador')); mount();
  await screen.findByRole('heading', { name: 'Seleccionar paciente para consultar recordatorios' });
  expect(screen.getByRole('link', { name: 'Consultar recordatorios' })).toHaveAttribute('aria-current', 'page');
  await userEvent.click(await screen.findByRole('button', { name: 'Seleccionar paciente Paciente De prueba' }));
  await screen.findByText('CITA-FICTICIA');
  expect(calls.filter(c => c.url === '/recordatorios')).toHaveLength(1);
  expect(calls.find(c => c.url === '/recordatorios')).toMatchObject({ method: 'get', params: { id_paciente: '21' } });
  expect(calls.find(c => c.url === '/recordatorios/proxima-cita')).toMatchObject({ method: 'get', params: { id_paciente: '21' } });
  expect(calls.every(c => c.method === 'get' && ['/pacientes', '/recordatorios', '/recordatorios/proxima-cita'].includes(c.url))).toBe(true);
  expect(screen.queryByRole('button', { name: /preferencias|push|dispositivo/i })).not.toBeInTheDocument();
});
it('sin sesión va a login sin pedir avisos', async () => {
  consultarSesion.mockRejectedValue({ response: { status: 401 } }); mount();
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(calls).toHaveLength(0);
});
it('401 real del interceptor retira los avisos y devuelve a login', async () => {
  mount(); await screen.findByText('CITA-FICTICIA'); fail = true;
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar recordatorios' }));
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(screen.queryByText('CITA-FICTICIA')).not.toBeInTheDocument();
});
it.each(['Paciente', 'Administrador', 'Médico', 'Recepcionista'])('entrada desde push sin sesión y login como %s respeta retorno y rol', async rol => {
  consultarSesion.mockRejectedValue({ response: { status: 401 } }); iniciarSesion.mockResolvedValue(sesion(rol));
  const { router } = mount(); const user = userEvent.setup();
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(router.state.location.state).toEqual({ from: '/recordatorios' }); expect(calls).toHaveLength(0);
  await user.type(screen.getByLabelText('Correo electrónico'), 'ficticia@example.test');
  await user.type(screen.getByLabelText('Contraseña', { exact: true }), 'Solo-ficticia-123');
  await user.click(screen.getByRole('button', { name: 'Iniciar sesión', exact: true }));
  const autorizado = ['Paciente', 'Administrador'].includes(rol);
  await waitFor(() => expect(router.state.location.pathname).toBe(autorizado ? '/recordatorios' : '/inicio'));
  if (rol === 'Paciente') {
    await screen.findByText('CITA-FICTICIA');
    expect(calls.find(c => c.url === '/recordatorios')).toMatchObject({ method: 'get', sessionBound: true });
  } else if (rol === 'Administrador') {
    await screen.findByRole('heading', { name: 'Seleccionar paciente para consultar recordatorios' });
    expect(calls.some(c => c.url === '/recordatorios' || c.url.startsWith('/push/'))).toBe(false);
  } else expect(calls.some(c => c.url === '/recordatorios')).toBe(false);
});
it('recarga desde clic consulta sesión y API otra vez: un aviso retirado no reaparece', async () => {
  const first = mount(); await screen.findByText('CITA-FICTICIA'); first.unmount();
  rows = []; mount(); await screen.findByText('No hay recordatorios para estos filtros');
  expect(consultarSesion).toHaveBeenCalledTimes(2);
  expect(calls.filter(c => c.url === '/recordatorios')).toHaveLength(2);
  expect(calls.every(c => c.method === 'get')).toBe(true);
  expect(screen.queryByText('CITA-FICTICIA')).not.toBeInTheDocument();
});
it('recarga desde notificación antigua con sesión expirada no recupera datos ni toca dispositivos', async () => {
  const first = mount(); await screen.findByText('CITA-FICTICIA'); first.unmount(); const count = calls.length;
  consultarSesion.mockRejectedValue({ response: { status: 401 } }); mount();
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(calls).toHaveLength(count); expect(screen.queryByText('CITA-FICTICIA')).not.toBeInTheDocument();
});
it.each(['https://ajeno.example/recordatorios', '//ajeno.example/recordatorios', '/recordatorios?id_paciente=21', '/recordatorios#privado', '/recordatorios/21'])('retorno no admite variantes ni destinos arbitrarios: %s', path => {
  expect(destinoSeguro(path, 'Paciente')).toBe('/inicio');
});
