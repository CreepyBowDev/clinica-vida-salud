import { StrictMode } from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthProvider } from '../../context/AuthContext';
import { crearRutas } from '../../../../app/router';
import { consultarSesion, iniciarSesion } from '../../services/auth.service';
import { cambiarClave, solicitarRecuperacion, restablecerClave } from '../../services/password.service';

vi.mock('../../services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
vi.mock('../../services/password.service', () => ({ cambiarClave: vi.fn(), solicitarRecuperacion: vi.fn(), restablecerClave: vi.fn() }));
const person = rol => ({ id_usuario: '25', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol });
const error = (status, code, details, headers = {}) => ({ response: { status, headers, data: { error: { code, message: 'SQL privado', details } } } });
const token = 'a'.repeat(64);
const password = '  Nueva frase ficticia 123!  ';

function mount(path, link = { token }, strict = false) {
  const router = createMemoryRouter(crearRutas(link), { initialEntries: [path] });
  const content = <AuthProvider><RouterProvider router={router} /></AuthProvider>;
  render(strict ? <StrictMode>{content}</StrictMode> : content);
  return router;
}

async function fillNew(user, confirmation = password) {
  await user.type(screen.getByLabelText('Nueva contraseña'), password);
  await user.type(screen.getByLabelText('Confirmar nueva contraseña'), confirmation);
}

beforeEach(() => { vi.resetAllMocks(); consultarSesion.mockRejectedValue(error(401)); });
afterEach(() => { vi.useRealTimers(); });

describe('cambio ordinario', () => {
  it('separa comprobación de identidad y nueva contraseña sin enviar al mostrarlas', async () => {
    consultarSesion.mockResolvedValue(person('Paciente')); mount('/mi-cuenta/contrasena');
    await screen.findByLabelText('Contraseña actual');
    expect(screen.getByRole('group', { name: 'Confirma tu identidad' })).toContainElement(screen.getByLabelText('Contraseña actual'));
    expect(screen.getByRole('group', { name: 'Tu nueva contraseña' })).toContainElement(screen.getByLabelText('Confirmar nueva contraseña'));
    expect(cambiarClave).not.toHaveBeenCalled();
  });
  it.each(['Administrador', 'Recepcionista', 'Médico', 'Paciente'])('cambia como %s y revalida conservando sesión normal', async rol => {
    consultarSesion.mockResolvedValue(person(rol));
    cambiarClave.mockResolvedValue(undefined);
    const user = userEvent.setup();
    mount('/mi-cuenta/contrasena');
    await screen.findByRole('heading', { name: 'Cambiar contraseña' });
    await user.type(screen.getByLabelText('Contraseña actual'), ' Clave actual ficticia 123 ');
    await fillNew(user);
    await user.click(screen.getByRole('button', { name: 'Guardar contraseña' }));
    expect(await screen.findByRole('status')).toHaveTextContent('Contraseña actualizada');
    expect(cambiarClave).toHaveBeenCalledWith({ current_password: ' Clave actual ficticia 123 ', password, password_confirmation: password });
    expect(consultarSesion).toHaveBeenCalledTimes(2);
    expect(screen.getByLabelText('Contraseña actual')).toHaveValue('');
    expect(screen.getByLabelText('Nueva contraseña')).toHaveValue('');
    expect(screen.getByRole('navigation', { name: 'Navegación principal' })).toBeInTheDocument();
  });

  it('no permite al contexto restringido usar el cambio ordinario', async () => {
    consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
    const router = mount('/mi-cuenta/contrasena');
    await screen.findByRole('heading', { name: 'Cambia tu contraseña' });
    expect(router.state.location.pathname).toBe('/clave-obligatoria');
    expect(cambiarClave).not.toHaveBeenCalled();
  });

  it('valida coincidencia y enfoca el campo sin enviar', async () => {
    consultarSesion.mockResolvedValue(person('Paciente'));
    const user = userEvent.setup();
    mount('/mi-cuenta/contrasena');
    await screen.findByRole('heading', { name: 'Cambiar contraseña' });
    await user.type(screen.getByLabelText('Contraseña actual'), 'actual');
    await fillNew(user, 'Otra contraseña ficticia');
    await user.click(screen.getByRole('button', { name: 'Guardar contraseña' }));
    expect(cambiarClave).not.toHaveBeenCalled();
    await waitFor(() => expect(screen.getByLabelText('Confirmar nueva contraseña')).toHaveFocus());
    expect(screen.getByLabelText('Confirmar nueva contraseña')).toHaveAccessibleDescription('La confirmación de la contraseña no coincide.');
  });

  it('traduce contraseña actual incorrecta sin exponer detalles y limpia secretos', async () => {
    consultarSesion.mockResolvedValue(person('Paciente'));
    cambiarClave.mockRejectedValue(error(422, 'AUTH_CLAVE_ACTUAL_INVALIDA'));
    const user = userEvent.setup();
    mount('/mi-cuenta/contrasena');
    await screen.findByRole('heading', { name: 'Cambiar contraseña' });
    await user.type(screen.getByLabelText('Contraseña actual'), 'actual');
    await fillNew(user);
    await user.click(screen.getByRole('button', { name: 'Guardar contraseña' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('La contraseña actual no es correcta');
    expect(screen.getByRole('alert')).not.toHaveTextContent('SQL privado');
    expect(screen.getByLabelText('Nueva contraseña')).toHaveValue('');
  });

  it('una comprobación anterior al cambio no sustituye la sesión renovada', async () => {
    let resolveOld;
    consultarSesion.mockResolvedValueOnce(person('Paciente'))
      .mockImplementationOnce(() => new Promise(done => { resolveOld = done; }))
      .mockResolvedValue(person('Paciente'));
    cambiarClave.mockResolvedValue(undefined);
    const user = userEvent.setup();
    mount('/mi-cuenta/contrasena');
    await screen.findByRole('heading', { name: 'Cambiar contraseña' });
    fireEvent(window, new Event('focus'));
    await user.type(screen.getByLabelText('Contraseña actual'), 'actual');
    await fillNew(user);
    await user.click(screen.getByRole('button', { name: 'Guardar contraseña' }));
    await screen.findByRole('status');
    await act(async () => resolveOld({ acceso: 'cambio_clave', cambio_clave_obligatorio: true }));
    expect(screen.getByRole('heading', { name: 'Cambiar contraseña' })).toBeInTheDocument();
    expect(screen.getByRole('navigation', { name: 'Navegación principal' })).toBeInTheDocument();
  });
});

describe('solicitud de recuperación', () => {
  it('202 no afirma existencia ni entrega y no autentica', async () => {
    solicitarRecuperacion.mockResolvedValue(undefined);
    const user = userEvent.setup();
    mount('/olvide-mi-contrasena');
    await user.type(screen.getByLabelText('Correo electrónico'), 'persona@example.test');
    await user.click(screen.getByRole('button', { name: 'Solicitar enlace' }));
    expect(await screen.findByRole('heading', { name: 'Solicitud recibida' })).toHaveFocus();
    expect(screen.getByText('Si corresponde, recibirás un enlace para recuperar tu contraseña.')).toBeInTheDocument();
    expect(iniciarSesion).not.toHaveBeenCalled();
    expect(solicitarRecuperacion).toHaveBeenCalledWith({ correo_acceso: 'persona@example.test' });
  });

  it('impide solicitudes duplicadas y conserva el estado de carga', async () => {
    let resolve;
    solicitarRecuperacion.mockImplementation(() => new Promise(done => { resolve = done; }));
    mount('/olvide-mi-contrasena');
    fireEvent.change(screen.getByLabelText('Correo electrónico'), { target: { value: 'persona@example.test' } });
    fireEvent.submit(screen.getByRole('form'));
    fireEvent.submit(screen.getByRole('form'));
    expect(solicitarRecuperacion).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Procesando solicitud…' })).toBeDisabled();
    await act(async () => resolve());
  });

  it('respeta Retry-After y no reenvía solicitudes', async () => {
    solicitarRecuperacion.mockRejectedValue(error(429, null, null, { 'retry-after': '60' }));
    mount('/olvide-mi-contrasena');
    await act(async () => {});
    vi.useFakeTimers();
    fireEvent.change(screen.getByLabelText('Correo electrónico'), { target: { value: 'persona@example.test' } });
    await act(async () => fireEvent.submit(screen.getByRole('form')));
    expect(screen.getByRole('button', { name: 'Espera 60 s' })).toBeDisabled();
    await act(async () => vi.advanceTimersByTime(60000));
    expect(screen.getByRole('button', { name: 'Solicitar enlace' })).toBeEnabled();
    expect(solicitarRecuperacion).toHaveBeenCalledTimes(1);
  });

  it.each([419, 500, undefined])('maneja HTTP/red %s sin anunciar envío', async status => {
    solicitarRecuperacion.mockRejectedValue(status ? error(status) : new Error('red'));
    const user = userEvent.setup();
    mount('/olvide-mi-contrasena');
    await user.type(screen.getByLabelText('Correo electrónico'), 'persona@example.test');
    await user.click(screen.getByRole('button', { name: 'Solicitar enlace' }));
    expect(await screen.findByRole('alert')).not.toHaveTextContent('SQL privado');
    expect(screen.queryByRole('heading', { name: 'Solicitud recibida' })).not.toBeInTheDocument();
    expect(solicitarRecuperacion).toHaveBeenCalledTimes(1);
  });
});

describe('restablecer por enlace', () => {
  it('un enlace ausente no muestra formulario ni envía', async () => {
    mount('/recuperar-clave', { token: null });
    await screen.findByRole('heading', { name: 'Enlace no disponible' });
    expect(screen.queryByLabelText('Nueva contraseña')).not.toBeInTheDocument();
    expect(restablecerClave).not.toHaveBeenCalled();
  });

  it('mantiene el token local bajo StrictMode, lo retira de la entrega y exige login tras completar', async () => {
    const envelope = { token };
    restablecerClave.mockResolvedValue(undefined);
    const user = userEvent.setup();
    const router = mount('/recuperar-clave', envelope, true);
    await screen.findByRole('heading', { name: 'Elige una nueva contraseña' });
    expect(envelope.token).toBeNull();
    expect(document.body.textContent).not.toContain(token);
    expect(document.querySelector(`input[value="${token}"]`)).toBeNull();
    await fillNew(user);
    await user.click(screen.getByRole('button', { name: 'Guardar nueva contraseña' }));
    expect(await screen.findByRole('heading', { name: 'Contraseña actualizada' })).toHaveFocus();
    expect(restablecerClave).toHaveBeenCalledWith({ token, password, password_confirmation: password });
    expect(iniciarSesion).not.toHaveBeenCalled();
    expect(router.state.location.pathname).toBe('/recuperar-clave');
    await user.click(screen.getByRole('link', { name: 'Iniciar sesión' }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    await act(async () => router.navigate('/recuperar-clave'));
    expect(await screen.findByRole('heading', { name: 'Enlace no disponible' })).toBeInTheDocument();
  });

  it('un enlace vencido/usado no confirma éxito ni permite reutilizarlo', async () => {
    restablecerClave.mockRejectedValue(error(422, 'RECUPERACION_INVALIDA'));
    const user = userEvent.setup();
    mount('/recuperar-clave');
    await screen.findByRole('heading', { name: 'Elige una nueva contraseña' });
    await fillNew(user);
    await user.click(screen.getByRole('button', { name: 'Guardar nueva contraseña' }));
    await screen.findByRole('heading', { name: 'Enlace no disponible' });
    expect(screen.queryByLabelText('Nueva contraseña')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Solicitar otro enlace' })).toHaveAttribute('href', '/olvide-mi-contrasena');
    expect(restablecerClave).toHaveBeenCalledTimes(1);
  });

  it('no cierra ni transforma una sesión de otra cuenta que el servidor conserva', async () => {
    consultarSesion.mockResolvedValue(person('Médico'));
    restablecerClave.mockResolvedValue(undefined);
    const user = userEvent.setup();
    mount('/recuperar-clave');
    await screen.findByRole('heading', { name: 'Elige una nueva contraseña' });
    await fillNew(user);
    await user.click(screen.getByRole('button', { name: 'Guardar nueva contraseña' }));
    await screen.findByRole('heading', { name: 'Contraseña actualizada' });
    expect(screen.getByRole('status')).toHaveTextContent('Hay otra sesión activa');
    expect(screen.getByRole('link', { name: 'Volver a mi sesión' })).toHaveAttribute('href', '/inicio');
    expect(iniciarSesion).not.toHaveBeenCalled();
  });

  it('conserva el enlace tras error de campos, limpia secretos y permite corrección', async () => {
    restablecerClave.mockRejectedValue(error(422, 'VALIDACION_ERROR', { password: ['La contraseña no es válida.'] }));
    const user = userEvent.setup();
    mount('/recuperar-clave');
    await screen.findByRole('heading', { name: 'Elige una nueva contraseña' });
    await fillNew(user);
    await user.click(screen.getByRole('button', { name: 'Guardar nueva contraseña' }));
    const field = screen.getByLabelText('Nueva contraseña');
    await waitFor(() => expect(field).toHaveFocus());
    expect(field).toHaveValue('');
    expect(field).toHaveAccessibleDescription(/Los espacios no cuentan como carácter especial\. La contraseña no es válida\./);
    expect(screen.queryByRole('heading', { name: 'Enlace no disponible' })).not.toBeInTheDocument();
  });

  it('una clave corta no se envía aunque se fuerce submit desde código', async () => {
    mount('/recuperar-clave');
    await screen.findByRole('heading', { name: 'Elige una nueva contraseña' });
    fireEvent.change(screen.getByLabelText('Nueva contraseña'), { target: { value: 'corta' } });
    fireEvent.change(screen.getByLabelText('Confirmar nueva contraseña'), { target: { value: 'corta' } });
    fireEvent.submit(screen.getByRole('form'));
    expect(await screen.findByRole('alert')).toHaveTextContent('Revisa los campos');
    expect(screen.getByLabelText('Nueva contraseña')).toHaveAccessibleDescription(/Los espacios no cuentan como carácter especial\. La contraseña debe tener al menos 12 caracteres\./);
    expect(restablecerClave).not.toHaveBeenCalled();
  });

  it.each([419, undefined])('un reset con fallo %s limpia contraseñas y no confirma ni reintenta', async status => {
    restablecerClave.mockRejectedValue(status ? error(status) : new Error('timeout'));
    const user = userEvent.setup();
    mount('/recuperar-clave');
    await screen.findByRole('heading', { name: 'Elige una nueva contraseña' });
    await fillNew(user);
    await user.click(screen.getByRole('button', { name: 'Guardar nueva contraseña' }));
    expect(await screen.findByRole('alert')).not.toHaveTextContent('SQL privado');
    expect(screen.getByLabelText('Nueva contraseña')).toHaveValue('');
    expect(screen.queryByRole('heading', { name: 'Contraseña actualizada' })).not.toBeInTheDocument();
    expect(restablecerClave).toHaveBeenCalledTimes(1);
  });
});
