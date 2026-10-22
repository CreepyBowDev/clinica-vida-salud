import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion, iniciarSesion, cerrarSesion } from '../../../auth/services/auth.service';
import { crearRutas } from '../../../../app/router';
import { api } from '../../../../api/api';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
const root = '/administracion/bitacora';
const id = '9223372036854775807';
const usuario = rol => ({ id_usuario: '1', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol });
const evento = { id_evento: id, tipo_evento: 'EVENTO_FICTICIO_PRIVADO', modulo: 'PRUEBA', operacion: 'CONSULTAR', tipo_actor: 'SISTEMA',
  id_usuario_responsable: null, rol_responsable: null, id_usuario_objetivo: null, rol_objetivo: null, resultado: 'DENEGADO',
  ocurrido_en: '2026-10-22T02:30:00.123456Z', registrado_en: '2026-10-22T02:30:00.123456Z', version_formato: 1,
  referencias: {}, cambios_seguros: {}, metadatos_seguros: {} };
const response = config => ({ config, status: 200, headers: {}, data: config.url === '/bitacora'
  ? { data: [evento], meta: { total: 1, current_page: 1, last_page: 1 } } : { data: evento } });
let calls;
function mount(path = root) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks(); calls = [];
  consultarSesion.mockResolvedValue(usuario('Administrador'));
  api.defaults.adapter = async config => { calls.push(config); return response(config); };
});
afterEach(() => { api.defaults.adapter = original; });

describe('seguridad de interfaz y teclado CU-30 fase 4', () => {
  it.each([root, `${root}/${id}`])('sesión revocada en %s limpia contenido y filtros antes de otra sesión', async path => {
    const router = mount(path); await screen.findByText(evento.tipo_evento);
    if (path === root) await userEvent.type(screen.getByLabelText('Módulo'), 'FILTRO_PRIVADO');
    consultarSesion.mockRejectedValueOnce({ response: { status: 401 } });
    await act(async () => window.dispatchEvent(new Event('focus')));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(screen.queryByText(evento.tipo_evento)).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Bitácora del Sistema' })).not.toBeInTheDocument();
    iniciarSesion.mockResolvedValue(usuario('Administrador'));
    const user = userEvent.setup();
    await user.type(screen.getByLabelText('Correo electrónico'), 'persona@example.test');
    await user.type(screen.getByLabelText('Contraseña'), 'Clave ficticia privada 123');
    await user.click(screen.getByRole('button', { name: 'Iniciar sesión', exact: true }));
    await screen.findByText(evento.tipo_evento);
    if (path !== root) await act(async () => router.navigate(root));
    await screen.findByRole('table'); expect(screen.getByLabelText('Módulo')).toHaveValue('');
  });
  it.each(['Recepcionista', 'Médico', 'Paciente'])('revalidar como %s retira detalle y menú sin nuevas lecturas de auditoría', async rol => {
    mount(`${root}/${id}`); await screen.findByText(evento.tipo_evento);
    consultarSesion.mockResolvedValue(usuario(rol));
    await act(async () => window.dispatchEvent(new Event('focus')));
    await screen.findByRole('heading', { name: 'Acceso no permitido' });
    expect(screen.queryByText(evento.tipo_evento)).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Bitácora del Sistema' })).not.toBeInTheDocument(); expect(calls).toHaveLength(1);
  });
  it('una revalidación sin conexión oculta el detalle hasta comprobar la sesión', async () => {
    mount(`${root}/${id}`); await screen.findByText(evento.tipo_evento);
    consultarSesion.mockRejectedValueOnce(new Error('Fallo técnico privado'));
    await act(async () => window.dispatchEvent(new Event('focus')));
    await screen.findByRole('heading', { name: 'No podemos comprobar tu acceso' });
    expect(screen.queryByText(evento.tipo_evento)).not.toBeInTheDocument();
    expect(screen.queryByText('Fallo técnico privado')).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Volver a comprobar' }));
    await screen.findByText(evento.tipo_evento); expect(calls).toHaveLength(2);
  });
  it('logout confirmado retira el evento y cancela una consulta de auditoría pendiente', async () => {
    let complete, signal;
    api.defaults.adapter = config => new Promise(resolve => { signal = config.signal; complete = () => resolve(response(config)); });
    mount(`${root}/${id}`); await screen.findByText('Consultando evento…');
    cerrarSesion.mockResolvedValue(undefined);
    await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(signal.aborted).toBe(true);
    await act(async () => complete()); expect(screen.queryByText(evento.tipo_evento)).not.toBeInTheDocument();
  });
  it('recorre filtros avanzados por teclado y permite cerrarlos después de un 422 sin robar foco', async () => {
    mount(); const user = userEvent.setup(); await screen.findByRole('table');
    const summary = screen.getByText('Filtros avanzados', { selector: 'summary' }); summary.focus();
    await user.click(summary); expect(summary.parentElement.open).toBe(true);
    await user.tab(); expect(screen.getByLabelText('ID de usuario responsable')).toHaveFocus();
    await user.tab(); expect(screen.getByLabelText('ID de cuenta objetivo')).toHaveFocus();
    api.defaults.adapter = async config => { throw new AxiosError('Error técnico privado', undefined, config, null,
      { status: 422, data: { error: { details: { registro_referido: ['Selecciona el tipo de recurso.'] } } } }); };
    await user.click(screen.getByRole('button', { name: 'Aplicar filtros' }));
    await waitFor(() => expect(screen.getByLabelText('ID del recurso')).toHaveFocus());
    expect(screen.getByLabelText('ID del recurso')).toHaveAccessibleDescription(/Selecciona el tipo de recurso/);
    await user.click(summary);
    expect(summary.parentElement.open).toBe(false); expect(summary).toHaveFocus();
    await user.selectOptions(screen.getByLabelText('Eventos por página'), '50');
    expect(summary.parentElement.open).toBe(false); expect(screen.getByLabelText('Eventos por página')).toHaveFocus();
  });
  it('al ir al detalle por teclado mueve foco al contenido y expone estructura de solo lectura', async () => {
    mount(); const user = userEvent.setup(); await screen.findByRole('table');
    const link = screen.getByRole('link', { name: `Ver evento ${id}` }); link.focus(); await user.keyboard('{Enter}');
    await screen.findByRole('region', { name: `Evento #${id}` });
    expect(screen.getByRole('main')).toHaveFocus();
    expect(within(screen.getByRole('main')).queryByRole('button')).not.toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Recursos referidos' })).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Cambios registrados' })).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Metadatos del evento' })).toBeInTheDocument();
    expect(calls.every(call => call.method === 'get')).toBe(true);
  });
});
