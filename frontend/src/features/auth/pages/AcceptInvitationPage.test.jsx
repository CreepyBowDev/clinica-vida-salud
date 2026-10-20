import { StrictMode } from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { crearRutas } from '../../../app/router';
import { AuthProvider } from '../context/AuthContext';
import { cerrarSesion, consultarSesion, iniciarSesion } from '../services/auth.service';
import { aceptarInvitacion } from '../services/invitation.service';

vi.mock('../services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
vi.mock('../services/invitation.service', () => ({ aceptarInvitacion: vi.fn() }));
const token = 'b'.repeat(64);
const password = ' Frase ficticia del equipo 123 ';
const error = (status, code, details, headers = {}) => ({ response: { status, headers, data: { error: { code, details, message: 'SQL secreto' } } } });
function mount(envelope = { token }, strict = false) {
  const router = createMemoryRouter(crearRutas({ token: null }, envelope), { initialEntries: ['/activar-personal'] });
  const content = <AuthProvider><RouterProvider router={router} /></AuthProvider>;
  render(strict ? <StrictMode>{content}</StrictMode> : content);
  return router;
}
async function fill(user) {
  await user.type(screen.getByLabelText('Nueva contraseña'), password);
  await user.type(screen.getByLabelText('Confirmar nueva contraseña'), password);
}
beforeEach(() => { vi.resetAllMocks(); consultarSesion.mockRejectedValue(error(401)); });
afterEach(() => vi.useRealTimers());

describe('aceptación de invitación', () => {
  it('confirma solo tras 204, limpia secretos bajo StrictMode y exige nuevo login', async () => {
    aceptarInvitacion.mockResolvedValue(undefined);
    const envelope = { token };
    const user = userEvent.setup();
    const router = mount(envelope, true);
    await screen.findByRole('heading', { name: 'Activa tu acceso al equipo' });
    await waitFor(() => expect(envelope.token).toBeNull());
    expect(document.documentElement.outerHTML).not.toContain(token);
    await fill(user);
    await user.click(screen.getByRole('button', { name: 'Establecer contraseña y activar' }));
    expect(await screen.findByRole('heading', { name: 'Tu acceso está listo' })).toHaveFocus();
    expect(aceptarInvitacion).toHaveBeenCalledWith({ token, password, password_confirmation: password });
    expect(iniciarSesion).not.toHaveBeenCalled();
    expect(screen.queryByLabelText('Nueva contraseña')).not.toBeInTheDocument();
    await user.click(screen.getByRole('link', { name: 'Iniciar sesión' }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    await act(async () => router.navigate('/activar-personal'));
    await screen.findByRole('heading', { name: 'Invitación no disponible' });
  });
  it('no envía si no hay un enlace capturado válido', async () => {
    mount({ token: null });
    await screen.findByRole('heading', { name: 'Invitación no disponible' });
    expect(screen.queryByRole('form')).not.toBeInTheDocument();
    expect(aceptarInvitacion).not.toHaveBeenCalled();
  });
  it('un enlace usado/vencido retira el formulario y orienta a Administración', async () => {
    aceptarInvitacion.mockRejectedValue(error(422, 'INVITACION_INVALIDA'));
    const user = userEvent.setup();
    mount();
    await screen.findByLabelText('Nueva contraseña'); await fill(user);
    await user.click(screen.getByRole('button', { name: 'Establecer contraseña y activar' }));
    await screen.findByRole('heading', { name: 'Invitación no disponible' });
    expect(screen.getByText(/solicita otra a Administración/)).toBeInTheDocument();
    expect(screen.queryByLabelText('Nueva contraseña')).not.toBeInTheDocument();
    expect(iniciarSesion).not.toHaveBeenCalled();
  });
  it.each(['normal', 'restricted'])('exige cerrar sesión %s antes de aceptar y conserva token en memoria', async kind => {
    consultarSesion.mockResolvedValue(kind === 'restricted' ? { acceso: 'cambio_clave', cambio_clave_obligatorio: true }
      : { id_usuario: '1', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol: 'Paciente' });
    cerrarSesion.mockResolvedValue(undefined);
    const user = userEvent.setup();
    mount();
    await screen.findByRole('button', { name: 'Cerrar sesión' });
    expect(screen.queryByLabelText('Nueva contraseña')).not.toBeInTheDocument();
    expect(aceptarInvitacion).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
    await screen.findByLabelText('Nueva contraseña');
    aceptarInvitacion.mockResolvedValue(undefined);
    consultarSesion.mockRejectedValue(error(401));
    await fill(user);
    await user.click(screen.getByRole('button', { name: 'Establecer contraseña y activar' }));
    await screen.findByRole('heading', { name: 'Tu acceso está listo' });
    expect(aceptarInvitacion.mock.calls[0][0].token).toBe(token);
  });
  it('422 por campo limpia claves y enfoca el campo sin perder el enlace', async () => {
    aceptarInvitacion.mockRejectedValue(error(422, 'VALIDACION_ERROR', { password: ['La contraseña no es válida.'] }));
    const user = userEvent.setup();
    mount();
    await screen.findByLabelText('Nueva contraseña'); await fill(user);
    await user.click(screen.getByRole('button', { name: 'Establecer contraseña y activar' }));
    await waitFor(() => expect(screen.getByLabelText('Nueva contraseña')).toHaveFocus());
    expect(screen.getByLabelText('Nueva contraseña')).toHaveValue('');
    expect(screen.getByRole('alert')).not.toHaveTextContent('SQL secreto');
    expect(screen.getByRole('heading', { name: 'Activa tu acceso al equipo' })).toBeInTheDocument();
  });
  it('impide doble aceptación mientras responde el servidor', async () => {
    let resolve;
    aceptarInvitacion.mockImplementation(() => new Promise(done => { resolve = done; }));
    mount();
    await screen.findByLabelText('Nueva contraseña');
    fireEvent.change(screen.getByLabelText('Nueva contraseña'), { target: { value: password } });
    fireEvent.change(screen.getByLabelText('Confirmar nueva contraseña'), { target: { value: password } });
    fireEvent.submit(screen.getByRole('form')); fireEvent.submit(screen.getByRole('form'));
    expect(aceptarInvitacion).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Activando acceso…' })).toBeDisabled();
    await act(async () => resolve());
  });
  it.each([419, 500, undefined])('fallo %s no anuncia activación ni reintenta', async status => {
    aceptarInvitacion.mockRejectedValue(status ? error(status) : new Error('red'));
    const user = userEvent.setup();
    mount();
    await screen.findByLabelText('Nueva contraseña'); await fill(user);
    await user.click(screen.getByRole('button', { name: 'Establecer contraseña y activar' }));
    expect(await screen.findByRole('alert')).not.toHaveTextContent('SQL secreto');
    expect(screen.getByLabelText('Nueva contraseña')).toHaveValue('');
    expect(screen.queryByRole('heading', { name: 'Tu acceso está listo' })).not.toBeInTheDocument();
    expect(aceptarInvitacion).toHaveBeenCalledTimes(1);
  });
  it('429 representa Retry-After sin enviar de nuevo', async () => {
    aceptarInvitacion.mockRejectedValue(error(429, null, null, { 'retry-after': '60' }));
    mount();
    await screen.findByLabelText('Nueva contraseña');
    vi.useFakeTimers();
    fireEvent.change(screen.getByLabelText('Nueva contraseña'), { target: { value: password } });
    fireEvent.change(screen.getByLabelText('Confirmar nueva contraseña'), { target: { value: password } });
    await act(async () => fireEvent.submit(screen.getByRole('form')));
    expect(screen.getByRole('button', { name: 'Espera 60 s' })).toBeDisabled();
    await act(async () => vi.advanceTimersByTime(60000));
    expect(screen.getByRole('button', { name: 'Establecer contraseña y activar' })).toBeEnabled();
    expect(aceptarInvitacion).toHaveBeenCalledTimes(1);
  });
});
