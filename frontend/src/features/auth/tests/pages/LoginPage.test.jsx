import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AuthProvider } from '../../context/AuthContext';
import { appRoutes } from '../../../../app/router';
import { cerrarSesion, consultarSesion, iniciarSesion } from '../../services/auth.service';

vi.mock('../../services/auth.service', () => ({ iniciarSesion: vi.fn(), consultarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const normal = { id_usuario: '11', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol: 'Paciente' };
const httpError = (status, details, headers = {}) => ({ response: { status, headers, data: { error: { message: 'SQL secreto', details } } } });

async function fill(user) {
  await user.type(screen.getByLabelText('Correo electrónico'), 'persona@example.test');
  await user.type(screen.getByLabelText('Contraseña'), '  Contraseña ficticia 123  ');
}

async function renderLogin() {
  const router = createMemoryRouter(appRoutes, { initialEntries: ['/login'] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  return router;
}

beforeEach(() => { vi.resetAllMocks(); consultarSesion.mockRejectedValue(httpError(401)); });
afterEach(() => { vi.useRealTimers(); });

describe('inicio de sesión', () => {
  it('requiere campos y permite mostrar la contraseña mediante un control accesible', async () => {
    const user = userEvent.setup();
    await renderLogin();
    await user.click(screen.getByRole('button', { name: 'Iniciar sesión' }));
    expect(iniciarSesion).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Correo electrónico')).toBeInvalid();
    await user.click(screen.getByRole('button', { name: 'Mostrar contraseña' }));
    expect(screen.getByLabelText('Contraseña')).toHaveAttribute('type', 'text');
    expect(screen.getByRole('button', { name: 'Ocultar contraseña' })).toHaveAttribute('aria-pressed', 'true');
  });

  it.each(['Administrador', 'Recepcionista', 'Médico', 'Paciente'])('confirma únicamente el rol devuelto por el servidor: %s', async (rol) => {
    iniciarSesion.mockResolvedValue({ ...normal, rol });
    const user = userEvent.setup();
    await renderLogin();
    await fill(user);
    await user.click(screen.getByRole('button', { name: 'Iniciar sesión' }));
    expect(await screen.findByRole('heading', { name: 'Hola, Persona Ficticia' })).toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('main')).toHaveFocus());
    expect(screen.getAllByText(rol).length).toBeGreaterThan(0);
    expect(iniciarSesion).toHaveBeenCalledWith({ correo_acceso: normal.correo_acceso, password: '  Contraseña ficticia 123  ' });
    expect(screen.queryByLabelText('Contraseña')).not.toBeInTheDocument();
  });

  it('no permite envíos duplicados mientras el servidor responde', async () => {
    let resolve;
    iniciarSesion.mockImplementation(() => new Promise((done) => { resolve = done; }));
    const user = userEvent.setup();
    await renderLogin();
    await fill(user);
    fireEvent.submit(screen.getByRole('form'));
    fireEvent.submit(screen.getByRole('form'));
    expect(iniciarSesion).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Comprobando acceso…' })).toBeDisabled();
    await act(async () => resolve(normal));
  });

  it.each([401, 403, 419, 500])('muestra un error seguro para HTTP %s y limpia la contraseña', async (status) => {
    iniciarSesion.mockRejectedValue(httpError(status));
    const user = userEvent.setup();
    await renderLogin();
    await fill(user);
    await user.click(screen.getByRole('button', { name: 'Iniciar sesión' }));
    expect(await screen.findByRole('alert')).not.toHaveTextContent('SQL secreto');
    expect(screen.getByLabelText('Contraseña')).toHaveValue('');
    expect(screen.getByLabelText('Correo electrónico')).toHaveValue(normal.correo_acceso);
    expect(iniciarSesion).toHaveBeenCalledTimes(1);
    if (status === 419) expect(screen.getByRole('alert')).toHaveTextContent('caducado');
  });

  it('asocia y enfoca los errores de campo 422', async () => {
    iniciarSesion.mockRejectedValue(httpError(422, { correo_acceso: ['Introduce un correo válido.'] }));
    const user = userEvent.setup();
    await renderLogin();
    await fill(user);
    await user.click(screen.getByRole('button', { name: 'Iniciar sesión' }));
    const field = screen.getByLabelText('Correo electrónico');
    await waitFor(() => expect(field).toHaveFocus());
    expect(field).toHaveAttribute('aria-invalid', 'true');
    expect(field).toHaveAccessibleDescription('Introduce un correo válido.');
  });

  it('respeta Retry-After sin inventar el bloqueo de la cuenta', async () => {
    iniciarSesion.mockRejectedValue(httpError(429, null, { 'retry-after': '60' }));
    await renderLogin();
    vi.useFakeTimers();
    fireEvent.change(screen.getByLabelText('Correo electrónico'), { target: { value: normal.correo_acceso } });
    fireEvent.change(screen.getByLabelText('Contraseña'), { target: { value: 'ficticia' } });
    await act(async () => fireEvent.submit(screen.getByRole('form')));
    expect(screen.getByRole('button', { name: 'Espera 60 s' })).toBeDisabled();
    await act(async () => vi.advanceTimersByTime(60000));
    expect(screen.getByRole('button', { name: 'Iniciar sesión' })).toBeEnabled();
    expect(iniciarSesion).toHaveBeenCalledTimes(1);
  });

  it('consulta el estado tras resultado incierto sin reenviar el login', async () => {
    iniciarSesion.mockRejectedValue(new Error('timeout sensible'));
    consultarSesion.mockRejectedValueOnce(httpError(401)).mockResolvedValue(normal);
    const user = userEvent.setup();
    await renderLogin();
    await fill(user);
    await user.click(screen.getByRole('button', { name: 'Iniciar sesión' }));
    await user.click(await screen.findByRole('button', { name: 'Comprobar mi sesión' }));
    expect(await screen.findByRole('heading', { name: 'Hola, Persona Ficticia' })).toBeInTheDocument();
    expect(iniciarSesion).toHaveBeenCalledTimes(1);
    expect(consultarSesion).toHaveBeenCalledTimes(2);
  });

  it('mantiene el acceso temporal restringido y no supone usuario o rol ordinarios', async () => {
    iniciarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
    const user = userEvent.setup();
    await renderLogin();
    await fill(user);
    await user.click(screen.getByRole('button', { name: 'Iniciar sesión' }));
    expect(await screen.findByRole('heading', { name: 'Cambia tu contraseña' })).toBeInTheDocument();
    expect(screen.queryByText('Cuenta verificada por el servidor')).not.toBeInTheDocument();
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });

  it('mantiene la confirmación si falla logout y solo la limpia al confirmarlo', async () => {
    iniciarSesion.mockResolvedValue(normal);
    cerrarSesion.mockRejectedValueOnce(httpError(500)).mockResolvedValueOnce(undefined);
    const user = userEvent.setup();
    await renderLogin();
    await fill(user);
    await user.click(screen.getByRole('button', { name: 'Iniciar sesión' }));
    await user.click(await screen.findByRole('button', { name: 'Cerrar sesión' }));
    expect(screen.getByRole('heading', { name: 'Hola, Persona Ficticia' })).toBeInTheDocument();
    expect(screen.getByRole('alert')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
    expect(await screen.findByRole('heading', { name: 'Bienvenido de nuevo' })).toBeInTheDocument();
  });
});
