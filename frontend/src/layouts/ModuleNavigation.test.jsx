import { act, render, screen, within } from '@testing-library/react';
import { createContext, useContext, useEffect } from 'react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider, useBlocker } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import MainLayout from './MainLayout';
import HomePage from '../features/auth/pages/HomePage';
import { ROLES } from '../constants/roles';

const auth = vi.hoisted(() => ({ usuario: {}, logout: vi.fn() }));
vi.mock('../hooks/useAuth', () => ({ useAuth: () => useContext(auth.Context) }));
auth.Context = createContext(null);
beforeEach(() => { auth.usuario = { id_usuario: '1', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol: ROLES.ADMINISTRADOR }; });
afterEach(() => vi.unstubAllGlobals());
const nav = () => screen.getByRole('navigation', { name: 'Navegación principal' });
function mount(path = '/inicio', element = <h1>Contenido existente</h1>) {
  const router = createMemoryRouter([{ element: <MainLayout />, children: [{ path: '/inicio', element: <HomePage /> }, { path: '*', element }] }], { initialEntries: [path] });
  const content = () => <auth.Context.Provider value={{ ...auth }}><RouterProvider router={router} /></auth.Context.Provider>;
  const view = render(content());
  return { ...view, router, refresh: () => view.rerender(content()) };
}

describe('sidebar modular y orientación', () => {
  it.each([
    [ROLES.ADMINISTRADOR, 'Médicos y especialidades', 'Gestionar médicos', 'Reservar cita'],
    [ROLES.RECEPCIONISTA, 'Pacientes y perfil personal', 'Gestionar pacientes', 'Administrar cuentas'],
    [ROLES.PACIENTE, 'Búsqueda y reserva de citas', 'Reservar cita', 'Programar cita'],
    [ROLES.MEDICO, 'Usuarios y autenticación', 'Cambiar contraseña', 'Gestionar pacientes'],
  ])('%s puede desplegar sus módulos sin CU visibles ni funciones ajenas', async (role, module, label, forbidden) => {
    auth.usuario.rol = role; mount('/otra'); const user = userEvent.setup();
    const button = within(nav()).getByRole('button', { name: module });
    expect(button).toHaveAttribute('aria-expanded', 'false');
    expect(within(nav()).queryByRole('link', { name: label })).not.toBeInTheDocument();
    await user.click(button);
    expect(button).toHaveAttribute('aria-expanded', 'true');
    expect(within(nav()).getByRole('link', { name: label })).toBeVisible();
    expect(nav()).not.toHaveTextContent(/CU-\d/);
    expect(within(nav()).queryByText(forbidden)).not.toBeInTheDocument();
    expect(within(nav()).queryByRole('link', { name: /iniciar sesión|registrar cuenta|cerrar sesión/i })).not.toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Cerrar sesión' })).toHaveLength(1);
  });
  it('ruta profunda de asignación abre consultorios, no médicos, y orienta sin IDs', () => {
    mount('/administracion/medicos/42/consultorio');
    expect(within(nav()).getByRole('button', { name: 'Consultorios y horarios' })).toHaveAttribute('aria-expanded', 'true');
    expect(within(nav()).getByRole('button', { name: 'Médicos y especialidades' })).toHaveAttribute('aria-expanded', 'false');
    expect(within(nav()).getByRole('link', { name: 'Gestionar consultorios' })).toHaveAttribute('aria-current', 'page');
    expect(document.title).toBe('Asignar consultorio | Clínica Vida Salud');
    expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).toHaveTextContent('Asignar consultorio');
    expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).not.toHaveTextContent('42');
  });
  it('permite varios abiertos y contracción por Enter/Espacio sin desplazar foco al contenido', async () => {
    mount('/administracion/cuentas'); const user = userEvent.setup();
    const button = within(nav()).getByRole('button', { name: 'Usuarios y autenticación' }); button.focus();
    await user.keyboard('{Enter}'); expect(button).toHaveAttribute('aria-expanded', 'false'); expect(button).toHaveFocus();
    await user.keyboard(' '); expect(button).toHaveAttribute('aria-expanded', 'true');
    await user.click(within(nav()).getByRole('button', { name: 'Consultorios y horarios' }));
    expect(button).toHaveAttribute('aria-expanded', 'true');
    expect(within(nav()).getByRole('link', { name: 'Administrar cuentas' })).toHaveAttribute('aria-current', 'page');
  });
  it('reabre al cambiar ruta, incluso atrás/adelante, y conserva otras expansiones', async () => {
    const { router } = mount('/administracion/cuentas'); const user = userEvent.setup();
    const button = within(nav()).getByRole('button', { name: 'Usuarios y autenticación' });
    await user.click(button); expect(button).toHaveAttribute('aria-expanded', 'false');
    await act(async () => router.navigate('/administracion/cuentas/42'));
    expect(button).toHaveAttribute('aria-expanded', 'true');
    await user.click(within(nav()).getByRole('button', { name: 'Consultorios y horarios' }));
    await act(async () => router.navigate('/administracion/horarios'));
    await user.click(button);
    await act(async () => router.navigate(-1)); expect(button).toHaveAttribute('aria-expanded', 'true');
    await act(async () => router.navigate(1));
    expect(within(nav()).getByRole('link', { name: 'Configurar horarios' })).toHaveAttribute('aria-current', 'page');
  });
  it('los pendientes se pueden leer pero nunca navegar', async () => {
    auth.usuario.rol = ROLES.PACIENTE; mount('/otra'); const user = userEvent.setup();
    await user.click(within(nav()).getByRole('button', { name: /Recordatorios internos/ }));
    expect(within(nav()).getByText('Consultar recordatorios')).toBeVisible();
    expect(within(nav()).queryByRole('link', { name: /recordatorios/i })).not.toBeInTheDocument();
    expect(within(nav()).queryByText(/cancelar cita|reprogramar cita/i)).not.toBeInTheDocument();
  });
  it('reinicia expansión al cambiar identidad o rol sin persistir estado', async () => {
    const { refresh } = mount('/otra'); const user = userEvent.setup();
    await user.click(within(nav()).getByRole('button', { name: 'Consultorios y horarios' }));
    auth.usuario = { ...auth.usuario, id_usuario: '2' }; refresh();
    expect(within(nav()).getByRole('button', { name: 'Consultorios y horarios' })).toHaveAttribute('aria-expanded', 'false');
    auth.usuario = { ...auth.usuario, rol: ROLES.PACIENTE }; refresh();
    expect(within(nav()).queryByRole('button', { name: 'Consultorios y horarios' })).not.toBeInTheDocument();
    expect(within(nav()).getByRole('button', { name: 'Usuarios y autenticación' })).toHaveAttribute('aria-expanded', 'false');
  });
  it('Escape cierra el menú móvil y devuelve foco; navegar también lo cierra', async () => {
    const { router } = mount('/otra'); const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Abrir menú' }));
    const module = within(nav()).getByRole('button', { name: 'Usuarios y autenticación' });
    module.focus(); await user.keyboard('{Escape}');
    expect(screen.getByRole('button', { name: 'Abrir menú' })).toHaveFocus();
    expect(nav()).not.toHaveClass('is-open');
    await user.click(screen.getByRole('button', { name: 'Abrir menú' }));
    await act(async () => router.navigate('/mi-cuenta/contrasena'));
    expect(nav()).not.toHaveClass('is-open'); expect(screen.getByRole('main')).toHaveFocus();
  });
  it('seleccionar la misma ruta cierra el menú y no deja foco en un enlace oculto', async () => {
    mount('/mi-cuenta/contrasena'); const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Abrir menú' }));
    await user.click(within(nav()).getByRole('link', { name: 'Cambiar contraseña' }));
    expect(nav()).not.toHaveClass('is-open'); expect(screen.getByRole('main')).toHaveFocus();
  });
  it('desplegar no desmonta el contenido ni dispara el blocker de una reserva', async () => {
    let mounts = 0;
    function Reserva() { useBlocker(true); useEffect(() => { mounts++; }, []); return <input aria-label="Borrador de reserva" defaultValue="" />; }
    auth.usuario.rol = ROLES.PACIENTE; const { router } = mount('/reservar-cita', <Reserva />); const user = userEvent.setup();
    await user.type(screen.getByLabelText('Borrador de reserva'), 'Texto ficticio'); const previous = mounts;
    await user.click(within(nav()).getByRole('button', { name: 'Búsqueda y reserva de citas' }));
    await user.click(within(nav()).getByRole('button', { name: 'Usuarios y autenticación' }));
    expect(screen.getByLabelText('Borrador de reserva')).toHaveValue('Texto ficticio'); expect(mounts).toBe(previous);
    expect([...router.state.blockers.values()].every(blocker => blocker.state === 'unblocked')).toBe(true);
    expect(router.state.location.pathname).toBe('/reservar-cita');
  });
  it.each([true, false])('al pasar a escritorio, foco del botón móvil va al enlace visible (ruta actual: %s)', async active => {
    let onChange; const media = { matches: false, addEventListener: vi.fn((_name, handler) => { onChange = handler; }), removeEventListener: vi.fn() };
    vi.stubGlobal('matchMedia', vi.fn(() => media));
    const { unmount } = mount(active ? '/mi-cuenta/contrasena' : '/otra');
    screen.getByRole('button', { name: 'Abrir menú' }).focus();
    // Chromium puede retirar el foco antes de notificar el cambio de media query.
    media.matches = true; screen.getByRole('button', { name: 'Abrir menú' }).blur();
    act(() => onChange());
    expect(within(nav()).getByRole('link', { name: active ? 'Cambiar contraseña' : 'Inicio' })).toHaveFocus();
    expect(nav()).not.toHaveClass('is-open'); unmount(); expect(media.removeEventListener).toHaveBeenCalledWith('change', onChange);
  });
  it('cambiar a escritorio no roba foco del formulario', () => {
    let onChange; const media = { matches: false, addEventListener: (_name, handler) => { onChange = handler; }, removeEventListener: vi.fn() };
    vi.stubGlobal('matchMedia', vi.fn(() => media)); mount('/otra', <input aria-label="Campo de trabajo" />);
    screen.getByRole('button', { name: 'Abrir menú' }).focus(); screen.getByLabelText('Campo de trabajo').focus();
    media.matches = true; act(() => onChange()); expect(screen.getByLabelText('Campo de trabajo')).toHaveFocus();
  });
  it('al pasar a escritorio omite el enlace actual si su módulo está contraído', async () => {
    let onChange; const media = { matches: false, addEventListener: (_name, handler) => { onChange = handler; }, removeEventListener: vi.fn() };
    vi.stubGlobal('matchMedia', vi.fn(() => media)); mount('/mi-cuenta/contrasena'); const user = userEvent.setup();
    await user.click(within(nav()).getByRole('button', { name: 'Usuarios y autenticación' }));
    screen.getByRole('button', { name: 'Abrir menú' }).focus(); media.matches = true; act(() => onChange());
    expect(within(nav()).getByRole('link', { name: 'Inicio' })).toHaveFocus();
  });
});
