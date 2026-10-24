import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, describe, expect, it } from 'vitest';
import { AuthProvider } from '../../context/AuthContext';
import { crearRutas } from '../../../../app/router';
import { api, csrfURL } from '../../../../api/api';

// Integración del cliente: páginas/contexto/servicios/interceptores reales.
// Solo se sustituye el transporte HTTP. No acredita cookies/CSRF ni reglas de Laravel.
const originalAdapter = api.defaults.adapter;
afterEach(() => { api.defaults.adapter = originalAdapter; });
const password = 'Frase ficticia de prueba 123!';
const persona = rol => ({ id_usuario: '17', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol });
const restricted = { acceso: 'cambio_clave', cambio_clave_obligatorio: true };
function transport(handler) {
  const calls = [];
  api.defaults.adapter = async config => {
    const body = config.data ? JSON.parse(config.data) : undefined;
    calls.push({ url: config.url, method: config.method, body, sessionBound: config.sessionBound });
    const result = config.url === csrfURL ? { status: 204 } : await handler(config, body);
    if (!result) throw new Error(`Petición no prevista: ${config.method} ${config.url}`);
    const response = { config, status: result.status ?? 200, data: result.data, headers: result.headers || {} };
    if (response.status >= 400) throw new AxiosError('Error HTTP simulado', 'ERR_BAD_RESPONSE', config, null, response);
    return response;
  };
  return calls;
}
const anonymous = { status: 401, data: { error: { code: 'AUTH_NO_AUTENTICADO' } } };
function mount(path = '/login', recovery = { token: null }, invitation = { token: null }) {
  const router = createMemoryRouter(crearRutas(recovery, invitation), { initialEntries: [path] });
  const view = render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
  return { router, ...view };
}
function field(label, value) { fireEvent.change(screen.getByLabelText(label), { target: { value } }); }
async function login() {
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  field('Correo electrónico', 'persona@example.test'); field('Contraseña', password);
  fireEvent.submit(screen.getByRole('form', { name: 'Iniciar sesión' }));
}

describe('integración HTTP del frontend de autenticación', () => {
  it.each([
    ['Administrador', '/administracion', 'Administración'],
    ['Recepcionista', '/recepcion', 'Recepción'],
    ['Médico', '/medico', 'Área médica'],
    ['Paciente', '/paciente', 'Mi atención'],
  ])('%s: login → área → remontaje/me → logout confirmado', async (rol, route, heading) => {
    let session = null;
    const calls = transport((config, body) => {
      if (config.url === '/auth/me') return session ? { data: { data: session } } : anonymous;
      if (config.url === '/auth/login') { expect(body).toEqual({ correo_acceso: 'persona@example.test', password }); session = persona(rol); return { data: { data: session } }; }
      if (config.url === '/auth/logout') { session = null; return { status: 204 }; }
    });
    const first = mount(route);
    await login();
    await screen.findByRole('heading', { name: heading, exact: true });
    expect(first.router.state.location.pathname).toBe(route);
    expect(calls[calls.findIndex(c => c.url === '/auth/login') - 1].url).toBe(csrfURL);
    first.unmount();
    const second = mount(route);
    await screen.findByRole('heading', { name: heading, exact: true });
    expect(calls.filter(c => c.url === '/auth/login')).toHaveLength(1);
    expect(calls.filter(c => c.url === '/auth/me')).toHaveLength(2);
    await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(second.router.state.location.pathname).toBe('/login');
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    expect(calls.find(c => c.url === '/auth/logout').sessionBound).toBe(true);
    expect(localStorage.length + sessionStorage.length).toBe(0);
  });

  it.each([false, true])('CU-04 %s → verificar → login → cambiar contraseña → logout', async linking => {
    let session = null;
    let activated = false;
    const calls = transport((config, body) => {
      if (config.url === '/auth/me') return session ? { data: { data: session } } : anonymous;
      if (config.url === (linking ? '/auth/registro/vincular' : '/auth/registro')) return { status: 202, data: { data: { message: 'Solicitud recibida.' } } };
      if (config.url === '/auth/registro/verificar') { expect(body).toEqual({ codigo: '001234' }); activated = true; return { status: 204 }; }
      if (config.url === '/auth/login') { expect(activated).toBe(true); session = persona('Paciente'); return { data: { data: session } }; }
      if (config.url === '/auth/password') { expect(body).toEqual({ current_password: password, password: 'Nueva frase ficticia 456!', password_confirmation: 'Nueva frase ficticia 456!' }); return { status: 204 }; }
      if (config.url === '/auth/logout') { session = null; return { status: 204 }; }
    });
    const { router } = mount(linking ? '/vincular-ficha' : '/registro');
    await screen.findByLabelText('CI'); field('CI', '000-A lp'); field('Correo de acceso', 'persona@example.test'); field('Contraseña', password); field('Confirmar contraseña', password);
    if (linking) field('Código de Recepción', 'Ab1234567890');
    else { field('Nombres', 'Persona'); field('Apellidos', 'Ficticia'); field('Fecha de nacimiento', '1990-01-01'); field('Teléfono', '70000000'); }
    fireEvent.submit(screen.getByRole('form'));
    await screen.findByRole('heading', { name: 'Verifica tu correo' });
    expect(screen.getByRole('status')).toHaveTextContent('no confirma el envío');
    field('Código del correo', '001234'); fireEvent.submit(screen.getByRole('form'));
    await screen.findByRole('heading', { name: 'Tu cuenta está activada' });
    expect(calls.some(c => c.url === '/auth/login')).toBe(false);
    await userEvent.click(screen.getByRole('link', { name: 'Iniciar sesión' }));
    await login(); await screen.findByRole('heading', { name: 'Hola, Persona Ficticia' });
    await userEvent.click(screen.getByRole('link', { name: 'Cambiar contraseña' }));
    await screen.findByLabelText('Contraseña actual');
    field('Contraseña actual', password); field('Nueva contraseña', 'Nueva frase ficticia 456!'); field('Confirmar nueva contraseña', 'Nueva frase ficticia 456!');
    fireEvent.submit(screen.getByRole('form'));
    expect(await screen.findByRole('status')).toHaveTextContent('Contraseña actualizada');
    expect(screen.getByLabelText('Nueva contraseña')).toHaveValue('');
    await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(router.state.location.pathname).toBe('/login');
    const writes = calls.filter(c => ['post', 'put'].includes(c.method));
    for (const request of writes) expect(calls[calls.indexOf(request) - 1].url).toBe(csrfURL);
  });

  it('temporal → formulario restringido → 204 → nuevo login normal, con transporte real del cliente', async () => {
    let session = null, completed = false;
    const calls = transport((config, body) => {
      if (config.url === '/auth/me') return session ? { data: { data: session } } : anonymous;
      if (config.url === '/auth/login') { session = completed ? persona('Recepcionista') : restricted; return { data: { data: session } }; }
      if (config.url === '/auth/password/temporal') { expect(body).toEqual({ password, password_confirmation: password }); completed = true; session = null; return { status: 204 }; }
    });
    mount(); await login(); await screen.findByRole('heading', { name: 'Cambia tu contraseña' });
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    field('Nueva contraseña', password); field('Confirmar nueva contraseña', password); fireEvent.submit(screen.getByRole('form'));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(screen.getByRole('status')).toHaveTextContent('Contraseña definitiva establecida');
    expect(calls.filter(c => c.url === '/auth/login')).toHaveLength(1);
    await login(); await screen.findByRole('heading', { name: 'Hola, Persona Ficticia' });
    expect(screen.getByRole('navigation', { name: 'Navegación principal' })).toBeInTheDocument();
  });

  it('403 de cuentas conserva sesión; 401 posterior retira todo el área mediante el interceptor real', async () => {
    let status = 403;
    const calls = transport(config => {
      if (config.url === '/auth/me') return { data: { data: persona('Administrador') } };
      if (config.url === '/usuarios') return { status, data: { error: { code: status === 403 ? 'ACCESO_DENEGADO' : 'AUTH_NO_AUTENTICADO' } } };
    });
    const { router } = mount('/administracion/cuentas');
    await screen.findByRole('alert');
    expect(screen.getByRole('navigation', { name: 'Navegación principal' })).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/administracion/cuentas');
    status = 401;
    await act(async () => { await router.navigate('/inicio'); });
    await act(async () => { await router.navigate('/administracion/cuentas'); });
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    expect(calls.filter(c => c.url === '/usuarios').every(c => c.sessionBound)).toBe(true);
    await waitFor(() => expect(router.state.location.pathname).toBe('/login'));
  });

  it('Administrador: alta → detalle → reenvío conserva la cuenta y distingue envío simulado', async () => {
    const account = { ...persona('Recepcionista'), estado_cuenta: 'Pendiente', correo_verificado_en: null, id_medico: null };
    const calls = transport((config, body) => {
      if (config.url === '/auth/me') return { data: { data: persona('Administrador') } };
      if (config.url === '/personal') {
        expect(body).toEqual({ rol: 'Recepcionista', correo_acceso: 'persona@example.test', nombres_personal: 'Persona', apellidos_personal: 'Ficticia' });
        return { status: 201, data: { data: account, meta: { invitacion: { envio: 'simulado' } } } };
      }
      if (config.url === '/usuarios/17') return { data: { data: account } };
      if (config.url === '/personal/17/invitacion') return { data: { data: account, meta: { invitacion: { envio: 'simulado' } } } };
    });
    mount('/administracion/cuentas/nueva');
    await screen.findByLabelText('Correo de acceso'); field('Correo de acceso', 'persona@example.test'); field('Nombres', 'Persona'); field('Apellidos', 'Ficticia');
    fireEvent.submit(screen.getByRole('form'));
    await screen.findByRole('heading', { name: 'Cuenta del personal creada' });
    expect(screen.getByRole('status')).toHaveTextContent('simulad');
    await userEvent.click(screen.getByRole('link', { name: 'Ver cuenta creada' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Reenviar invitación' }));
    expect(calls.some(c => c.url === '/personal/17/invitacion')).toBe(false);
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar nuevo envío' }));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('simulad'));
    await waitFor(() => expect(calls.filter(c => c.url === '/usuarios/17')).toHaveLength(2));
    expect(calls.filter(c => c.url === '/personal')).toHaveLength(1);
  });

  it('emisión propia: respuesta HTTP confirmada sobrevive al 401 de me sin conservar el área privada', async () => {
    let session = persona('Administrador');
    const account = { ...session, estado_cuenta: 'Activa', correo_verificado_en: '2026-10-20T00:00:00Z' };
    transport((config, body) => {
      if (config.url === '/auth/me') return session ? { data: { data: session } } : anonymous;
      if (config.url === '/usuarios/17') return { data: { data: account } };
      if (config.url === '/usuarios/17/credencial-temporal') {
        expect(body).toEqual({ identidad_verificada: true }); session = null;
        return { status: 201, data: { data: { id_usuario: '17', credencial_temporal: 'TemporalFicticia1234', expira_en: '2026-10-20T20:30:00Z' } } };
      }
    });
    mount('/administracion/cuentas/17');
    await userEvent.click(await screen.findByRole('button', { name: 'Emitir credencial temporal' }));
    await userEvent.click(screen.getByRole('checkbox'));
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar emisión' }));
    await screen.findByRole('heading', { name: 'Credencial temporal emitida' });
    expect(screen.getByLabelText('Credencial temporal para entrega presencial')).toHaveTextContent('TemporalFicticia1234');
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Ya comuniqué la credencial; ocultar' }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(screen.queryByText('TemporalFicticia1234')).not.toBeInTheDocument();
  });

  it('invitación: aceptación anónima con 204 → login del personal, sin reutilizar el secreto', async () => {
    let session = null;
    const invitation = { token: 'a'.repeat(64) };
    const calls = transport((config, body) => {
      if (config.url === '/auth/me') return session ? { data: { data: session } } : anonymous;
      if (config.url === '/auth/invitaciones/aceptar') { expect(body).toEqual({ token: 'a'.repeat(64), password, password_confirmation: password }); return { status: 204 }; }
      if (config.url === '/auth/login') { session = persona('Médico'); return { data: { data: session } }; }
    });
    mount('/activar-personal', { token: null }, invitation);
    await screen.findByLabelText('Nueva contraseña'); field('Nueva contraseña', password); field('Confirmar nueva contraseña', password); fireEvent.submit(screen.getByRole('form'));
    await screen.findByRole('heading', { name: 'Tu acceso está listo' });
    expect(invitation.token).toBeNull();
    expect(document.documentElement.outerHTML).not.toContain('a'.repeat(64));
    expect(calls.some(c => c.url === '/auth/login')).toBe(false);
    await userEvent.click(screen.getByRole('link', { name: 'Iniciar sesión' }));
    await login(); await screen.findByRole('heading', { name: 'Hola, Persona Ficticia' });
  });

  it('CU-06: solicitud genérica → enlace → reset → nuevo login sin autenticar tras 204', async () => {
    let session = null;
    const recovery = { token: 'b'.repeat(64) };
    const calls = transport((config, body) => {
      if (config.url === '/auth/me') return session ? { data: { data: session } } : anonymous;
      if (config.url === '/auth/password/forgot') { expect(body).toEqual({ correo_acceso: 'persona@example.test' }); return { status: 202 }; }
      if (config.url === '/auth/password/reset') { expect(body).toEqual({ token: 'b'.repeat(64), password, password_confirmation: password }); return { status: 204 }; }
      if (config.url === '/auth/login') { session = persona('Paciente'); return { data: { data: session } }; }
    });
    const { router } = mount('/olvide-mi-contrasena', recovery);
    await screen.findByLabelText('Correo electrónico'); field('Correo electrónico', 'persona@example.test'); fireEvent.submit(screen.getByRole('form'));
    await screen.findByRole('heading', { name: 'Solicitud recibida' });
    expect(screen.getByText('Si corresponde, recibirás un enlace para recuperar tu contraseña.')).toBeInTheDocument();
    await act(async () => router.navigate('/recuperar-clave'));
    await screen.findByLabelText('Nueva contraseña'); field('Nueva contraseña', password); field('Confirmar nueva contraseña', password); fireEvent.submit(screen.getByRole('form'));
    await screen.findByRole('heading', { name: 'Contraseña actualizada' });
    expect(recovery.token).toBeNull();
    expect(calls.some(c => c.url === '/auth/login')).toBe(false);
    await userEvent.click(screen.getByRole('link', { name: 'Iniciar sesión' }));
    await login(); await screen.findByRole('heading', { name: 'Hola, Persona Ficticia' });
  });
});
