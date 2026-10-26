import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { destinoSeguro, ROLES } from '../../../../constants/roles';
import { AuthProvider } from '../../context/AuthContext';
import { cerrarSesion, consultarSesion, iniciarSesion } from '../../services/auth.service';

vi.mock('../../services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
// Fronteras de página simuladas: esta fase verifica rutas/layout/guards reales, no los flujos de fases 2–4.
vi.mock('../../../pacientes/pages/PatientsPage', () => ({ default: () => <h1>Listado compartido</h1> }));
vi.mock('../../../pacientes/pages/NewPatientPage', () => ({ default: () => <h1>Alta compartida</h1> }));
vi.mock('../../../pacientes/pages/PatientDetailPage', () => ({ default: () => <h1>Ficha compartida</h1> }));
vi.mock('../../../pacientes/pages/EditPatientPage', () => ({ default: () => <h1>Edición compartida</h1> }));
vi.mock('../../../pacientes/pages/PatientActivationPage', () => ({ default: () => <h1>Activación de ficha compartida</h1> }));
vi.mock('../../../pacientes/pages/ActivationCodePage', () => ({ default: () => <h1>Emisión compartida</h1> }));
vi.mock('../../../citas/pages/ReservarCitaPage', () => ({ default: () => <h1>Programación compartida</h1> }));
vi.mock('../../../agenda/pages/SeleccionarPacienteHistorialPage', () => ({ default: () => <h1>Selección compartida</h1> }));
vi.mock('../../../agenda/pages/HistorialCitasPage', () => ({ default: () => <h1>Historial compartido</h1> }));
vi.mock('../../../agenda/pages/CitaDetallePage', () => ({ default: () => <h1>Detalle compartido</h1> }));

const rutas = [
  ['/recepcion/pacientes', 'Listado compartido'],
  ['/recepcion/pacientes/nuevo', 'Alta compartida'],
  ['/recepcion/pacientes/42', 'Ficha compartida'],
  ['/recepcion/pacientes/42/editar', 'Edición compartida'],
  ['/recepcion/pacientes/42/activacion', 'Activación de ficha compartida'],
  ['/recepcion/activacion-web', 'Emisión compartida'],
  ['/recepcion/citas/nueva', 'Programación compartida'],
  ['/recepcion/historial-citas', 'Selección compartida'],
  ['/recepcion/pacientes/42/citas', 'Historial compartido'],
  ['/recepcion/historial-citas/17', 'Detalle compartido'],
];
const original = api.defaults.adapter;
const sesion = rol => ({ id_usuario: '71', nombre_visible: 'Persona ficticia', correo_acceso: 'persona@example.test', rol });
let requests;
beforeEach(() => {
  vi.resetAllMocks(); requests = [];
  consultarSesion.mockResolvedValue(sesion(ROLES.ADMINISTRADOR));
  api.defaults.adapter = async config => { requests.push(config); throw new Error('No debe haber peticiones de negocio en esta prueba de navegación.'); };
});
afterEach(() => { api.defaults.adapter = original; });
function mount(path) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
  return router;
}

describe('rutas administrativas compartidas, fase 1', () => {
  it.each(rutas.flatMap(([path, heading]) => [ROLES.ADMINISTRADOR, ROLES.RECEPCIONISTA].map(role => [role, path, heading])))('%s entra en %s sin duplicar la página ni cambiar identidad', async (role, path, heading) => {
    consultarSesion.mockResolvedValue(sesion(role));
    const router = mount(path);
    await screen.findByRole('heading', { name: heading });
    expect(router.state.location.pathname).toBe(path);
    await waitFor(() => expect(screen.getByRole('main')).toHaveFocus());
    expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).toHaveTextContent(role === ROLES.ADMINISTRADOR ? 'Administración' : 'Recepción');
    expect(screen.queryByRole('heading', { name: 'Acceso no permitido' })).not.toBeInTheDocument();
    expect(requests).toHaveLength(0);
    expect(cerrarSesion).not.toHaveBeenCalled();
  });

  it.each(rutas.flatMap(([path]) => [ROLES.MEDICO, ROLES.PACIENTE].map(role => [role, path])))('%s no gana acceso a %s', async (role, path) => {
    consultarSesion.mockResolvedValue(sesion(role));
    const router = mount(path);
    await screen.findByRole('heading', { name: 'Acceso no permitido' });
    expect(router.state.location.pathname).toBe(path);
    expect(requests).toHaveLength(0);
    expect(cerrarSesion).not.toHaveBeenCalled();
  });

  it.each(['/mi-perfil', '/mi-perfil/correo', '/reservar-cita', '/mis-citas', '/mis-citas/17', '/paciente', '/medico', '/recepcion'])('Administrador no recibe acceso personal o área ajena %s', async path => {
    const router = mount(path);
    await screen.findByRole('heading', { name: 'Acceso no permitido' });
    expect(router.state.location.pathname).toBe(path);
    expect(requests).toHaveLength(0);
    expect(cerrarSesion).not.toHaveBeenCalled();
  });

  it.each(rutas)('visitante en %s conserva destino y no renderiza datos privados', async (path, heading) => {
    consultarSesion.mockRejectedValue({ response: { status: 401 } });
    const router = mount(path);
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(router.state.location.pathname).toBe('/login');
    expect(router.state.location.state.from).toBe(path);
    expect(destinoSeguro(path)).toBe(path);
    expect(screen.queryByRole('heading', { name: heading })).not.toBeInTheDocument();
    expect(requests).toHaveLength(0);
  });

  it('login administrativo restaura historial solicitado sin redirigir al área de Recepción', async () => {
    consultarSesion.mockRejectedValueOnce({ response: { status: 401 } }).mockResolvedValue(sesion(ROLES.ADMINISTRADOR));
    iniciarSesion.mockResolvedValue(sesion(ROLES.ADMINISTRADOR));
    const router = mount('/recepcion/pacientes/42/citas');
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    const user = userEvent.setup();
    await user.type(screen.getByLabelText('Correo electrónico'), 'persona@example.test');
    await user.type(screen.getByLabelText('Contraseña'), 'Frase ficticia 123!');
    await user.click(screen.getByRole('button', { name: 'Iniciar sesión' }));
    await screen.findByRole('heading', { name: 'Historial compartido' });
    expect(router.state.location.pathname).toBe('/recepcion/pacientes/42/citas');
    expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).toHaveTextContent('Administración');
  });

  it.each(rutas)('sesión restringida en %s no abre el flujo ordinario', async (path, heading) => {
    consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
    const router = mount(path);
    await screen.findByRole('heading', { name: 'Cambia tu contraseña' });
    expect(router.state.location.pathname).toBe('/clave-obligatoria');
    expect(screen.queryByRole('heading', { name: heading })).not.toBeInTheDocument();
    expect(requests).toHaveLength(0);
  });

  it('espera restauración antes de mostrar el flujo compartido', async () => {
    let resolve;
    consultarSesion.mockImplementation(() => new Promise(done => { resolve = done; }));
    mount('/recepcion/pacientes');
    expect(screen.getByText('Comprobando tu sesión…')).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Listado compartido' })).not.toBeInTheDocument();
    await act(async () => resolve(sesion(ROLES.ADMINISTRADOR)));
    await screen.findByRole('heading', { name: 'Listado compartido' });
  });

  it.each(['https://ajeno.example.test', '//ajeno.example.test', '/recepcion/pacientes/42/citas?token=privado', '/recepcion/historial-citas/17#privado', '/recepcion/pacientes/01/citas', '/recepcion/consultas', undefined])('destino ajeno o no canónico %s vuelve a Inicio', path => {
    expect(destinoSeguro(path)).toBe('/inicio');
  });
});
