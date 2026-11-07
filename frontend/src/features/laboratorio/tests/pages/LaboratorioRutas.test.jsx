import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { disponible, listado, solicitud } from '../utils/laboratorioFixtures';
import { detalle, sesion } from '../../../historia-clinica/tests/utils/clinicaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
let rol, calls, respond;
const response = (config, data) => ({ config, data, status: 200, headers: {} });
function mount(path) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.clearAllMocks(); rol = 'Paciente'; calls = [];
  consultarSesion.mockImplementation(async () => sesion(rol));
  respond = config => response(config, config.url === '/solicitudes-laboratorio' ? listado([solicitud()]) : { data: disponible() });
  api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = original; vi.restoreAllMocks(); });
it.each([
  ['Recepcionista', '/laboratorio'], ['Recepcionista', '/laboratorio/consultas/44'], ['Recepcionista', '/laboratorio/solicitudes/71'],
  ['Recepcionista', '/laboratorio/pacientes/21'], ['Paciente', '/laboratorio/pacientes/21'], ['Médico', '/laboratorio/pacientes/21'],
])('%s no consulta laboratorio por ruta ajena %s', async (role, path) => {
  rol = role; mount(path); await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
});
it.each(['Administrador', 'Médico', 'Paciente'])('%s llega al detalle común y tiene título/sidebar coherentes', async role => {
  rol = role; mount('/laboratorio/solicitudes/71'); await screen.findByRole('heading', { name: 'Hemograma de prueba' });
  expect(document.title).toBe('Solicitud de laboratorio | Clínica Vida Salud');
  expect(screen.getByRole('link', { name: 'Consultar solicitudes y descargar resultados autorizados' })).toHaveAttribute('aria-current', 'page');
  expect(calls.every(c => c.method === 'get' && c.sessionBound)).toBe(true);
});
it('enlace desde consulta usa id_consulta 31, no id_cita 41', async () => {
  respond = config => response(config, detalle()); mount('/citas/41/consulta');
  const link = await screen.findByRole('link', { name: 'Ver estudios de esta consulta' }); expect(link).toHaveAttribute('href', '/laboratorio/consultas/31');
  expect(screen.getByRole('link', { name: 'Ver solicitud de laboratorio' })).toHaveAttribute('href', '/laboratorio/solicitudes/61');
});
it('401 de descarga Blob pasa por interceptor vigente y retira expediente al login', async () => {
  mount('/laboratorio/solicitudes/71'); await screen.findByRole('heading', { name: 'Hemograma de prueba' });
  respond = config => Promise.reject(new AxiosError('NO MOSTRAR', undefined, config, null, { config, status: 401, headers: {}, data: new Blob(['{}'], { type: 'application/json' }) }));
  await userEvent.click(screen.getByRole('button', { name: 'Descargar resultado' })); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(screen.queryByRole('heading', { name: 'Hemograma de prueba' })).not.toBeInTheDocument();
});
it('paginación retira lista anterior hasta obtener página siguiente', async () => {
  respond = config => response(config, { data: config.params.page === 1 ? Array.from({ length: 20 }, (_, i) => solicitud({ id_solicitud: String(71 + i) })) : [solicitud({ id_solicitud: '100', estudio_solicitado: 'Otro estudio ficticio' })],
    meta: { current_page: config.params.page, per_page: 20, total: 21, last_page: 2, zona_horaria: 'America/La_Paz' } });
  mount('/laboratorio'); await screen.findAllByText('Hemograma de prueba');
  await userEvent.click(screen.getByRole('button', { name: 'Siguiente' })); await screen.findByText('Otro estudio ficticio');
  expect(screen.queryByText('Hemograma de prueba')).not.toBeInTheDocument(); expect(calls.at(-1).params.page).toBe(2);
});
it('navegar cancela descarga anterior e ignora su 401 tardío', async () => {
  let reject;
  const router = mount('/laboratorio/solicitudes/71'); await screen.findByRole('heading', { name: 'Hemograma de prueba' });
  respond = config => new Promise((_, no) => { reject = () => no(new AxiosError('TARDIO', undefined, config, null, { config, status: 401, headers: {}, data: new Blob() })); });
  await userEvent.click(screen.getByRole('button', { name: 'Descargar resultado' })); await waitFor(() => expect(reject).toBeDefined());
  await act(async () => router.navigate('/inicio'));
  await act(async () => reject()); expect(screen.queryByRole('heading', { name: 'Bienvenido de nuevo' })).not.toBeInTheDocument();
});
