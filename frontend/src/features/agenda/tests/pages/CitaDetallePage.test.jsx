import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { cerrarSesion, consultarSesion } from '../../../auth/services/auth.service';
import { cita, detalle, sesion } from '../utils/agendaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
let rol, calls, respond;
const response = (config, data = detalle(rol)) => ({ config, status: 200, headers: {}, data: { data } });
const fail = (config, status, seconds = '1') => new AxiosError('SQL-RUTA-PRIVADA', undefined, config, null,
  status ? { status, headers: { 'retry-after': seconds }, data: { error: { message: 'SQL-RUTA-PRIVADA', details: { id_objetivo: ['NO-SALIR'] } } } } : undefined);
function mount(path = `/citas/${cita.id_cita}`) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks(); rol = 'Paciente'; calls = [];
  consultarSesion.mockImplementation(async () => sesion(rol));
  respond = config => response(config);
  api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = original; vi.useRealTimers(); });

it.each(['Administrador', 'Recepcionista', 'Médico', 'Paciente'])('%s ve solo datos autorizados y contexto real sin nuevos enlaces de sidebar', async role => {
  rol = role;
  // Defensa adicional: extras no autorizados ni siquiera se conservan en la proyección del Service.
  respond = config => response(config, { ...detalle(rol), motivo_breve: '0', diagnostico: 'NO-SALIR',
    paciente: { ...cita.paciente, documento_identidad: 'CI-FICTICIO', telefono: 'TEL-FICTICIO', fecha_nacimiento: '1990-01-01', alergias: 'NO-SALIR' } });
  mount(); const region = await screen.findByRole('region', { name: `Cita ${cita.codigo_cita}` });
  const main = screen.getByRole('main');
  expect(region).toHaveTextContent('Sala conservada ficticia'); expect(region).toHaveTextContent('Programada');
  expect(region).toHaveTextContent('31/10/2026'); expect(region).toHaveTextContent('22:30');
  expect(screen.getByRole('region', { name: 'Paciente' })).toHaveTextContent('Paciente De prueba');
  expect(main.textContent.includes('CI-FICTICIO')).toBe(rol === 'Recepcionista');
  expect(main.textContent.includes('TEL-FICTICIO')).toBe(rol === 'Recepcionista');
  expect(main.textContent.includes('01/01/1990')).toBe(rol === 'Médico');
  expect(screen.queryByRole('region', { name: 'Motivo breve' }) !== null).toBe(rol !== 'Administrador');
  if (rol !== 'Administrador') expect(screen.getByRole('region', { name: 'Motivo breve' })).toHaveTextContent('0');
  expect(main).not.toHaveTextContent('NO-SALIR'); expect(within(main).queryByRole('button')).not.toBeInTheDocument();
  expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).toHaveTextContent('Agenda e historial');
  expect(document.title).toBe('Detalle de cita | Clínica Vida Salud'); expect(main).toHaveFocus();
  expect(screen.queryByRole('link', { name: 'Consultar agenda médica' }) !== null).toBe(rol !== 'Paciente');
  expect(screen.queryByRole('link', { name: 'Consultar historial de citas' }) !== null).toBe(rol === 'Recepcionista');
  expect(calls).toHaveLength(1); expect(calls[0]).toMatchObject({ method: 'get', sessionBound: true, url: `/citas/${cita.id_cita}` });
  expect(localStorage.length).toBe(0); expect(sessionStorage.length).toBe(0);
});
it.each(['Recepcionista', 'Médico', 'Paciente'])('%s distingue valores autorizados ausentes de claves prohibidas', async role => {
  rol = role; const data = detalle(rol); data.motivo_breve = null;
  if (rol === 'Médico') data.paciente.fecha_nacimiento = null;
  respond = config => response(config, data); mount();
  await screen.findByText('Sin motivo registrado');
  if (rol === 'Recepcionista') expect(screen.getByText('Sin CI registrado')).toBeInTheDocument();
  if (rol === 'Médico') expect(screen.getByText('No registrado')).toBeInTheDocument();
});
it.each(['0', '01', 'invalido', '9223372036854775808'])('identificador %s inválido no genera HTTP', async id => {
  mount(`/citas/${id}`); await screen.findByRole('heading', { name: 'Cita no disponible' }); expect(calls).toHaveLength(0);
});
it.each([403, 404, 422])('%s muestra error seguro, sin datos ni reintento improcedente', async status => {
  respond = config => Promise.reject(fail(config, status)); mount();
  const alert = await screen.findByRole('alert'); expect(alert).not.toHaveTextContent('SQL-RUTA-PRIVADA');
  expect(alert).not.toHaveTextContent('NO-SALIR'); expect(screen.queryByRole('region', { name: 'Paciente' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Volver a consultar' })).not.toBeInTheDocument(); expect(calls).toHaveLength(1);
});
it.each([419, 500, undefined])('%s permite reconsulta manual sin reenviar escrituras ni exponer técnicos', async status => {
  respond = config => Promise.reject(fail(config, status)); mount(); await screen.findByRole('alert');
  expect(screen.getByRole('alert')).not.toHaveTextContent('SQL-RUTA-PRIVADA');
  respond = config => response(config); await userEvent.click(screen.getByRole('button', { name: 'Volver a consultar' }));
  await screen.findByRole('region', { name: 'Paciente' }); expect(calls).toHaveLength(2); expect(calls.every(call => call.method === 'get')).toBe(true);
});
it('401 retira la consulta y dirige al login existente', async () => {
  respond = config => Promise.reject(fail(config, 401)); mount();
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(screen.queryByRole('region', { name: 'Paciente' })).not.toBeInTheDocument();
});
it('huésped y sesión restringida no llaman al endpoint de detalle', async () => {
  consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true }); mount();
  await screen.findByRole('heading', { name: 'Cambia tu contraseña' }); expect(calls).toHaveLength(0);
});
it('sesión ausente no consulta cita', async () => {
  consultarSesion.mockRejectedValue({ response: { status: 401 } }); mount();
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(calls).toHaveLength(0);
});
it('una respuesta 200 malformada se trata como fallo seguro y no como cita vacía', async () => {
  respond = config => response(config, { id_cita: cita.id_cita, motivo_breve: 'NO-SALIR' }); mount();
  await screen.findByRole('alert'); expect(screen.getByRole('alert')).toHaveTextContent('No pudimos consultar');
  expect(screen.queryByText('NO-SALIR')).not.toBeInTheDocument();
});
it('cambiar de cita cancela la respuesta previa y no deja datos antiguos durante la carga', async () => {
  const pending = [];
  respond = config => new Promise(resolve => pending.push({ config, complete: data => resolve(response(config, data)) }));
  const router = mount(); await waitFor(() => expect(pending).toHaveLength(1));
  await act(async () => router.navigate('/citas/22')); await waitFor(() => expect(pending).toHaveLength(2));
  expect(pending[0].config.signal.aborted).toBe(true);
  await act(async () => pending[1].complete({ ...detalle(rol), id_cita: '22', codigo_cita: 'CITA-NUEVA' }));
  await screen.findByRole('region', { name: 'Cita CITA-NUEVA' });
  await act(async () => pending[0].complete(detalle(rol)));
  expect(screen.queryByRole('region', { name: `Cita ${cita.codigo_cita}` })).not.toBeInTheDocument();
});
it('cambio de usuario/rol elimina proyección privada anterior aunque se mantenga URL', async () => {
  rol = 'Recepcionista'; mount(); await screen.findByText('70000000');
  let complete;
  respond = config => new Promise(resolve => { complete = () => resolve(response(config)); });
  rol = 'Administrador'; consultarSesion.mockResolvedValue(sesion(rol, '2'));
  await act(async () => window.dispatchEvent(new Event('focus')));
  await waitFor(() => expect(complete).toBeTypeOf('function'));
  expect(screen.queryByText('70000000')).not.toBeInTheDocument(); expect(screen.queryByText('MOTIVO-FICTICIO')).not.toBeInTheDocument();
  await act(async () => complete()); await screen.findByRole('region', { name: 'Paciente' });
  expect(screen.queryByRole('region', { name: 'Motivo breve' })).not.toBeInTheDocument();
});
it('logout cancela una lectura pendiente y la respuesta tardía no restaura datos', async () => {
  let complete, signal;
  respond = config => new Promise(resolve => { signal = config.signal; complete = () => resolve(response(config)); });
  mount(); await screen.findByText('Consultando cita…'); await waitFor(() => expect(complete).toBeTypeOf('function'));
  cerrarSesion.mockResolvedValue(undefined); await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(signal.aborted).toBe(true);
  await act(async () => complete()); expect(screen.queryByRole('region', { name: 'Paciente' })).not.toBeInTheDocument();
});
it('pagehide oculta datos y pageshow vuelve a consultar sin almacenamiento persistente', async () => {
  mount(); await screen.findByRole('region', { name: 'Paciente' });
  await act(async () => window.dispatchEvent(new Event('pagehide')));
  expect(screen.queryByRole('region', { name: 'Paciente' })).not.toBeInTheDocument();
  await act(async () => window.dispatchEvent(new Event('pageshow')));
  await screen.findByRole('region', { name: 'Paciente' }); expect(calls).toHaveLength(2);
});
it('429 respeta espera y no consulta automáticamente cuando termina', async () => {
  respond = config => Promise.reject(fail(config, 429)); mount(); await screen.findByRole('alert');
  expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeDisabled();
  expect(calls).toHaveLength(1);
  await waitFor(() => expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeEnabled(), { timeout: 2500 });
  expect(calls).toHaveLength(1); respond = config => response(config);
  await userEvent.click(screen.getByRole('button', { name: 'Volver a consultar' })); await screen.findByRole('region', { name: 'Paciente' }); expect(calls).toHaveLength(2);
});
