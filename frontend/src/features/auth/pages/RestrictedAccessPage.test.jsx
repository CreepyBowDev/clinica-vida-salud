import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { crearRutas } from '../../../app/router';
import { AuthProvider } from '../context/AuthContext';
import { cerrarSesion, consultarSesion, iniciarSesion } from '../services/auth.service';
import { completarClaveTemporal } from '../services/password.service';

vi.mock('../services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
vi.mock('../services/password.service', () => ({ completarClaveTemporal: vi.fn(), cambiarClave: vi.fn(), restablecerClave: vi.fn(), solicitarRecuperacion: vi.fn() }));
const restricted = { acceso: 'cambio_clave', cambio_clave_obligatorio: true };
const password = ' Nueva definitiva ficticia 123 ';
const error = (status, details, headers = {}) => ({ response: { status, headers, data: { error: { code: 'VALIDACION_ERROR', details, message: 'SQL secreto' } } } });
function mount(path = '/clave-obligatoria') {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
  return router;
}
async function fill(user) {
  await user.type(screen.getByLabelText('Nueva contraseña'), password);
  await user.type(screen.getByLabelText('Confirmar nueva contraseña'), password);
}
beforeEach(() => { vi.resetAllMocks(); consultarSesion.mockResolvedValue(restricted); });
afterEach(() => vi.useRealTimers());

describe('cambio obligatorio temporal', () => {
  it('204 cierra el contexto restringido y exige nuevo login, sin autenticar automáticamente', async () => {
    completarClaveTemporal.mockResolvedValue(undefined);
    const user = userEvent.setup();
    const router = mount();
    await screen.findByRole('heading', { name: 'Cambia tu contraseña' });
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Contraseña actual')).not.toBeInTheDocument();
    await fill(user);
    await user.click(screen.getByRole('button', { name: 'Establecer contraseña definitiva' }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(screen.getByRole('status')).toHaveTextContent('Contraseña definitiva establecida');
    expect(completarClaveTemporal).toHaveBeenCalledWith({ password, password_confirmation: password });
    expect(iniciarSesion).not.toHaveBeenCalled();
    expect(router.state.location.pathname).toBe('/login');
    expect(screen.getByLabelText('Contraseña')).toHaveValue('');
  });
  it('una sesión normal no obtiene el formulario restringido', async () => {
    consultarSesion.mockResolvedValue({ id_usuario: '17', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol: 'Paciente' });
    const router = mount();
    await screen.findByRole('heading', { name: 'Hola, Persona Ficticia' });
    expect(router.state.location.pathname).toBe('/inicio');
    expect(completarClaveTemporal).not.toHaveBeenCalled();
  });
  it('no permite abrir el área administrativa con sesión restringida', async () => {
    const router = mount('/administracion/cuentas');
    await screen.findByRole('heading', { name: 'Cambia tu contraseña' });
    expect(router.state.location.pathname).toBe('/clave-obligatoria');
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });
  it('valida confirmación y enfoca el campo sin enviar', async () => {
    const user = userEvent.setup();
    mount();
    await screen.findByLabelText('Nueva contraseña');
    await user.type(screen.getByLabelText('Nueva contraseña'), password);
    await user.type(screen.getByLabelText('Confirmar nueva contraseña'), 'Otra frase ficticia');
    await user.click(screen.getByRole('button', { name: 'Establecer contraseña definitiva' }));
    await waitFor(() => expect(screen.getByLabelText('Confirmar nueva contraseña')).toHaveFocus());
    expect(completarClaveTemporal).not.toHaveBeenCalled();
  });
  it('422 limpia secretos y permite corregir, sin asumir sesión normal', async () => {
    completarClaveTemporal.mockRejectedValue(error(422, { password: ['La contraseña no es válida.'] }));
    const user = userEvent.setup();
    mount();
    await screen.findByLabelText('Nueva contraseña'); await fill(user);
    await user.click(screen.getByRole('button', { name: 'Establecer contraseña definitiva' }));
    await waitFor(() => expect(screen.getByLabelText('Nueva contraseña')).toHaveFocus());
    expect(screen.getByLabelText('Nueva contraseña')).toHaveValue('');
    expect(screen.getByRole('alert')).not.toHaveTextContent('SQL secreto');
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });
  it('419 permite comprobar vigencia y retira el formulario si la sesión ya venció', async () => {
    completarClaveTemporal.mockRejectedValue(error(419));
    const user = userEvent.setup();
    mount();
    await screen.findByLabelText('Nueva contraseña'); await fill(user);
    await user.click(screen.getByRole('button', { name: 'Establecer contraseña definitiva' }));
    await screen.findByRole('alert');
    consultarSesion.mockRejectedValue(error(401));
    await user.click(screen.getByRole('button', { name: 'Comprobar vigencia de sesión' }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(completarClaveTemporal).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
  });
  it('cierre explícito informa invalidación de temporal antes de confirmarse en servidor', async () => {
    cerrarSesion.mockResolvedValue(undefined);
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: 'Cerrar sin completar el cambio' }));
    expect(screen.getByRole('status')).toHaveTextContent('invalida la credencial temporal');
    expect(cerrarSesion).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(cerrarSesion).toHaveBeenCalledTimes(1);
    expect(completarClaveTemporal).not.toHaveBeenCalled();
  });
  it('no repite PUT mientras se completa', async () => {
    let resolve;
    completarClaveTemporal.mockImplementation(() => new Promise(done => { resolve = done; }));
    mount();
    await screen.findByLabelText('Nueva contraseña');
    fireEvent.change(screen.getByLabelText('Nueva contraseña'), { target: { value: password } });
    fireEvent.change(screen.getByLabelText('Confirmar nueva contraseña'), { target: { value: password } });
    fireEvent.submit(screen.getByRole('form')); fireEvent.submit(screen.getByRole('form'));
    expect(completarClaveTemporal).toHaveBeenCalledTimes(1);
    await act(async () => resolve());
  });
  it.each([403, 500, undefined])('error %s conserva restricción, limpia claves y no anuncia éxito', async status => {
    completarClaveTemporal.mockRejectedValue(status ? error(status) : new Error('timeout'));
    const user = userEvent.setup();
    mount();
    await screen.findByLabelText('Nueva contraseña'); await fill(user);
    await user.click(screen.getByRole('button', { name: 'Establecer contraseña definitiva' }));
    expect(await screen.findByRole('alert')).not.toHaveTextContent('SQL secreto');
    expect(screen.getByLabelText('Nueva contraseña')).toHaveValue('');
    expect(screen.getByRole('heading', { name: 'Cambia tu contraseña' })).toBeInTheDocument();
    expect(completarClaveTemporal).toHaveBeenCalledTimes(1);
  });
  it('429 respeta espera del servidor sin reenvío automático', async () => {
    completarClaveTemporal.mockRejectedValue(error(429, null, { 'retry-after': '60' }));
    mount();
    await screen.findByLabelText('Nueva contraseña');
    vi.useFakeTimers();
    fireEvent.change(screen.getByLabelText('Nueva contraseña'), { target: { value: password } });
    fireEvent.change(screen.getByLabelText('Confirmar nueva contraseña'), { target: { value: password } });
    await act(async () => fireEvent.submit(screen.getByRole('form')));
    expect(screen.getByRole('button', { name: 'Espera 60 s' })).toBeDisabled();
    await act(async () => vi.advanceTimersByTime(60000));
    expect(screen.getByRole('button', { name: 'Establecer contraseña definitiva' })).toBeEnabled();
    expect(completarClaveTemporal).toHaveBeenCalledTimes(1);
  });
});
