import { StrictMode } from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthProvider } from '../../context/AuthContext';
import { appRoutes } from '../../../../app/router';
import { api } from '../../../../api/api';
import { useAuth } from '../../../../hooks/useAuth';
import { consultarSesion, iniciarSesion, cerrarSesion } from '../../services/auth.service';

vi.mock('../../services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const userData = (rol = 'Paciente') => ({ id_usuario: '17', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol });
const rejected = (status) => ({ response: { status } });
const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((done, fail) => { resolve = done; reject = fail; });
  return { promise, resolve, reject };
};
const originalAdapter = api.defaults.adapter;

function Probe() {
  const { refreshSession, status } = useAuth();
  return <div><span data-testid="status">{status}</span><button onClick={refreshSession}>Revalidar en prueba</button></div>;
}

function mount(path = '/inicio', { strict = false, probe = false } = {}) {
  const router = createMemoryRouter(appRoutes, { initialEntries: [path] });
  const content = <AuthProvider><RouterProvider router={router} />{probe && <Probe />}</AuthProvider>;
  render(strict ? <StrictMode>{content}</StrictMode> : content);
  return router;
}

beforeEach(() => { vi.resetAllMocks(); });
afterEach(() => { api.defaults.adapter = originalAdapter; });

describe('sesión y rutas', () => {
  it('espera la restauración sin redirigir ni mostrar datos y deduplica StrictMode', async () => {
    const pending = deferred();
    consultarSesion.mockReturnValue(pending.promise);
    const router = mount('/paciente', { strict: true });
    expect(screen.getByRole('status')).toHaveTextContent('Comprobando');
    expect(router.state.location.pathname).toBe('/paciente');
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    await act(async () => pending.resolve(userData()));
    expect(await screen.findByRole('heading', { name: 'Mi atención' })).toBeInTheDocument();
    expect(consultarSesion).toHaveBeenCalledTimes(1);
  });

  it('redirige anónimo y conserva solo el destino interno', async () => {
    consultarSesion.mockRejectedValue(rejected(401));
    const router = mount('/medico');
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(router.state.location.pathname).toBe('/login');
    expect(router.state.location.state.from).toBe('/medico');
  });

  it.each([
    ['Administrador', '/administracion', 'Administración'],
    ['Recepcionista', '/recepcion', 'Recepción'],
    ['Médico', '/medico', 'Área médica'],
    ['Paciente', '/paciente', 'Mi atención'],
  ])('restaura %s y solo muestra su navegación', async (rol, path, label) => {
    consultarSesion.mockResolvedValue(userData(rol));
    mount(path);
    await screen.findByRole('heading', { name: label, exact: true });
    const links = screen.getAllByRole('link');
    expect(links.find((link) => link.getAttribute('href') === path)).toHaveAttribute('aria-current', 'page');
    expect(links.filter((link) => ['/administracion', '/recepcion', '/medico', '/paciente'].includes(link.getAttribute('href')))).toHaveLength(1);
  });

  it.each([
    ['Administrador', '/paciente'], ['Recepcionista', '/administracion'], ['Médico', '/recepcion'], ['Paciente', '/medico'],
  ])('deniega navegación manipulada de %s a %s sin cerrar sesión', async (rol, path) => {
    consultarSesion.mockResolvedValue(userData(rol));
    const router = mount(path);
    expect(await screen.findByRole('heading', { name: 'Acceso no permitido' })).toBeInTheDocument();
    expect(router.state.location.pathname).toBe(path);
    expect(screen.queryByLabelText('Contraseña')).not.toBeInTheDocument();
  });

  it('restaura la sesión restringida sin identidad/navegación ordinaria', async () => {
    consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
    const router = mount('/administracion');
    await screen.findByRole('heading', { name: 'Cambia tu contraseña' });
    expect(router.state.location.pathname).toBe('/clave-obligatoria');
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    expect(screen.queryByText('Persona Ficticia')).not.toBeInTheDocument();
  });

  it.each([403, 419, 500, undefined])('bloquea el área ante fallo de restauración %s y permite comprobar de nuevo', async (status) => {
    consultarSesion.mockRejectedValueOnce(status ? rejected(status) : new Error('offline')).mockResolvedValue(userData());
    const user = userEvent.setup();
    const router = mount('/inicio');
    await screen.findByRole('heading', { name: 'No podemos comprobar tu acceso' });
    expect(screen.queryByText('Persona Ficticia')).not.toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/inicio');
    await user.click(screen.getByRole('button', { name: 'Volver a comprobar' }));
    expect(await screen.findByRole('heading', { name: 'Hola, Persona Ficticia' })).toBeInTheDocument();
  });

  it('rechaza un rol o payload desconocidos sin conceder acceso', async () => {
    consultarSesion.mockResolvedValue(userData('Superusuario'));
    mount('/inicio');
    await screen.findByRole('heading', { name: 'No podemos comprobar tu acceso' });
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });

  it('una nueva carga restaura desde el servidor y no desde almacenamiento web', async () => {
    localStorage.setItem('usuario', JSON.stringify(userData('Administrador')));
    consultarSesion.mockRejectedValue(rejected(401));
    mount('/administracion');
    expect(await screen.findByRole('heading', { name: 'Bienvenido de nuevo' })).toBeInTheDocument();
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    localStorage.clear();
  });

  it('revalida al volver a la ventana y retira la información si la sesión fue revocada', async () => {
    consultarSesion.mockResolvedValueOnce(userData()).mockRejectedValueOnce(rejected(401));
    const router = mount();
    await screen.findByRole('heading', { name: 'Hola, Persona Ficticia' });
    fireEvent(window, new Event('focus'));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(router.state.location.pathname).toBe('/login');
    expect(screen.queryByText('persona@example.test')).not.toBeInTheDocument();
  });

  it('un 401 de una petición protegida invalida globalmente la sesión', async () => {
    consultarSesion.mockResolvedValue(userData());
    mount();
    await screen.findByRole('heading', { name: 'Hola, Persona Ficticia' });
    api.defaults.adapter = async (config) => { throw new AxiosError('unauthorized', undefined, config, null, { status: 401 }); };
    await act(async () => { await api.get('/solo-prueba', { sessionBound: true }).catch(() => {}); });
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(screen.getByRole('alert')).toHaveTextContent('Tu sesión ha finalizado');
  });

  it('un 403 protegido no cierra una sesión autenticada', async () => {
    consultarSesion.mockResolvedValue(userData());
    mount();
    await screen.findByRole('heading', { name: 'Hola, Persona Ficticia' });
    api.defaults.adapter = async (config) => { throw new AxiosError('forbidden', undefined, config, null, { status: 403 }); };
    await act(async () => { await api.get('/solo-prueba', { sessionBound: true }).catch(() => {}); });
    expect(screen.getByRole('heading', { name: 'Hola, Persona Ficticia' })).toBeInTheDocument();
  });

  it('una restauración tardía no resucita la sesión tras logout', async () => {
    const late = deferred();
    consultarSesion.mockResolvedValueOnce(userData()).mockReturnValueOnce(late.promise);
    cerrarSesion.mockResolvedValue(undefined);
    const user = userEvent.setup();
    mount('/inicio', { probe: true });
    await screen.findByRole('heading', { name: 'Hola, Persona Ficticia' });
    fireEvent.click(screen.getByRole('button', { name: 'Revalidar en prueba' }));
    await user.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    await act(async () => late.resolve(userData()));
    expect(screen.getByTestId('status')).toHaveTextContent('anonymous');
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });

  it('descarta un 401 tardío del contexto anterior después de un nuevo login', async () => {
    const late = deferred();
    consultarSesion.mockRejectedValue(rejected(401));
    iniciarSesion.mockResolvedValue(userData());
    const user = userEvent.setup();
    mount('/login');
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    api.defaults.adapter = async (config) => { await late.promise; throw new AxiosError('old', undefined, config, null, { status: 401 }); };
    const oldRequest = api.get('/solo-prueba', { sessionBound: true }).catch(() => {});
    await user.type(screen.getByLabelText('Correo electrónico'), 'persona@example.test');
    await user.type(screen.getByLabelText('Contraseña'), 'ficticia');
    await user.click(screen.getByRole('button', { name: 'Iniciar sesión' }));
    await screen.findByRole('heading', { name: 'Hola, Persona Ficticia' });
    await act(async () => { late.resolve(); await oldRequest; });
    expect(screen.getByRole('heading', { name: 'Hola, Persona Ficticia' })).toBeInTheDocument();
  });

  it('descarta resultados exitosos de peticiones de una sesión anterior', async () => {
    const late = deferred();
    consultarSesion.mockResolvedValue(userData());
    cerrarSesion.mockResolvedValue(undefined);
    const user = userEvent.setup();
    mount();
    await screen.findByRole('heading', { name: 'Hola, Persona Ficticia' });
    api.defaults.adapter = async (config) => { await late.promise; return { config, status: 200, data: 'anterior', headers: {} }; };
    const oldRequest = api.get('/solo-prueba', { sessionBound: true }).catch((error) => error.code);
    await user.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    late.resolve();
    expect(await oldRequest).toBe('SESSION_SUPERSEDED');
  });

  it('nunca redirige a un origen externo enviado como destino', async () => {
    consultarSesion.mockRejectedValue(rejected(401));
    iniciarSesion.mockResolvedValue(userData());
    const user = userEvent.setup();
    const router = mount({ pathname: '/login', state: { from: '//externo.example' } });
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    await user.type(screen.getByLabelText('Correo electrónico'), 'persona@example.test');
    await user.type(screen.getByLabelText('Contraseña'), 'ficticia');
    await user.click(screen.getByRole('button', { name: 'Iniciar sesión' }));
    await waitFor(() => expect(router.state.location.pathname).toBe('/inicio'));
  });
});
