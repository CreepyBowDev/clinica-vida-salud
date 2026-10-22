import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { crearRutas } from '../../../../app/router';
import { destinoSeguro } from '../../../../constants/roles';
import { api } from '../../../../api/api';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
const root = '/administracion/bitacora';
const detail = `${root}/9223372036854775807`;
const usuario = rol => ({ id_usuario: '1', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol });
let calls;
function mount(path = root) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
  return router;
}
beforeEach(() => {
  vi.resetAllMocks(); calls = [];
  consultarSesion.mockResolvedValue(usuario('Administrador'));
  api.defaults.adapter = async config => { calls.push(config); return { config, status: 200, headers: {}, data: config.url === '/bitacora'
    ? { data: [], meta: { total: 0, current_page: 1, last_page: 1 } }
    : { data: { id_evento: '9223372036854775807', version_formato: 1, referencias: {}, cambios_seguros: {}, metadatos_seguros: {} } } }; };
});
afterEach(() => { api.defaults.adapter = original; });

describe('rutas y navegación CU-30 fase 1', () => {
  it('Administrador abre Bitácora desde el menú, con título, foco y enlace activo', async () => {
    const user = userEvent.setup(); const router = mount('/inicio');
    await user.click(await screen.findByRole('link', { name: 'Bitácora del Sistema' }));
    await screen.findByRole('heading', { name: 'Bitácora del Sistema' });
    expect(router.state.location.pathname).toBe(root);
    expect(screen.getByRole('link', { name: 'Bitácora del Sistema' })).toHaveAttribute('aria-current', 'page');
    await waitFor(() => expect(document.title).toBe('Bitácora del Sistema | Clínica Vida Salud'));
    expect(screen.getByRole('main')).toHaveFocus();
    await screen.findByRole('heading', { name: 'No hay eventos para estos filtros' });
    expect(calls).toHaveLength(1); expect(calls[0].url).toBe('/bitacora');
  });
  it('acceso directo al detalle y regreso al listado con el mismo menú activo', async () => {
    const user = userEvent.setup(); const router = mount(detail);
    await screen.findByRole('heading', { name: 'Detalle de evento' });
    expect(screen.getByRole('link', { name: 'Bitácora del Sistema' })).toHaveAttribute('aria-current', 'page');
    await user.click(screen.getByRole('link', { name: 'Volver a Bitácora' }));
    await screen.findByRole('heading', { name: 'Bitácora del Sistema' });
    expect(router.state.location.pathname).toBe(root);
    await screen.findByRole('heading', { name: 'No hay eventos para estos filtros' });
    expect(calls).toHaveLength(2); expect(calls.map(c => c.url)).toEqual(['/bitacora/9223372036854775807', '/bitacora']);
  });
  it.each(['Recepcionista', 'Médico', 'Paciente'].flatMap(rol => [root, detail].map(path => [rol, path])))('%s no accede a %s ni recibe menú', async (rol, path) => {
    consultarSesion.mockResolvedValue(usuario(rol)); mount(path);
    await screen.findByRole('heading', { name: 'Acceso no permitido' });
    expect(screen.queryByRole('link', { name: 'Bitácora del Sistema' })).not.toBeInTheDocument();
    expect(calls).toHaveLength(0);
  });
  it.each([root, detail])('espera restauración y conserva destino autorizado %s al pedir login', async path => {
    let reject; consultarSesion.mockImplementation(() => new Promise((_, fail) => { reject = fail; }));
    const router = mount(path);
    expect(screen.getByRole('status')).toHaveTextContent('Comprobando tu sesión');
    expect(screen.queryByRole('link', { name: 'Bitácora del Sistema' })).not.toBeInTheDocument();
    await act(async () => reject({ response: { status: 401 } }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(router.state.location.state.from).toBe(path); expect(destinoSeguro(path)).toBe(path);
    expect(calls).toHaveLength(0);
  });
  it('sesión restringida debe cambiar la contraseña antes de entrar', async () => {
    consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
    const router = mount(detail);
    await waitFor(() => expect(router.state.location.pathname).toBe('/clave-obligatoria'));
    expect(screen.queryByRole('link', { name: 'Bitácora del Sistema' })).not.toBeInTheDocument(); expect(calls).toHaveLength(0);
  });
  it.each(['https://ajeno.example/administracion/bitacora', '//ajeno.example', `${root}/1/editar`, `${root}/1?token=secreto`])('no admite destino de retorno ajeno o de escritura: %s', path => {
    expect(destinoSeguro(path)).toBe('/inicio');
  });
});
