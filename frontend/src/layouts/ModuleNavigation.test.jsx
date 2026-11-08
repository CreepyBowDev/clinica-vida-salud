import { act, render, screen, within } from '@testing-library/react';
import { createContext, useContext, useEffect } from 'react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider, useBlocker } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import MainLayout from './MainLayout';
import HomePage from '../features/auth/pages/HomePage';
import { ROLES } from '../constants/roles';
import { navigationForRole } from '../constants/navigation';

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
  it.each(Object.values(ROLES))('D6 Inicio y sidebar de %s comparten orden, nombres completos y excepciones', async role => {
    auth.usuario.rol = role; mount('/inicio'); const user = userEvent.setup();
    const modules = navigationForRole(role);
    for (const module of modules) await user.click(within(nav()).getByRole('button', { name: module.label }));
    const links = within(nav()).getAllByRole('link').slice(2);
    const home = within(screen.getByRole('region', { name: 'Funciones disponibles' })).getAllByRole('link');
    expect(links.map(link => [link.textContent, link.getAttribute('href')])).toEqual(home.map(link => [link.textContent, link.getAttribute('href')]));
    expect(links.map(link => link.textContent)).toEqual(modules.flatMap(module => module.items.map(item => item.label)));
    expect(within(nav()).queryAllByText('Acceso auxiliar')).toHaveLength(modules.flatMap(module => module.items).filter(item => item.auxiliary).length);
    expect(nav()).not.toHaveTextContent(/CU-\d/);
    expect(screen.getByRole('button', { name: 'Cerrar sesión' })).toBeInTheDocument();
  });
  it.each([
    [ROLES.MEDICO, '/citas/42/consulta/editar?recorrido=consulta', 'Registrar o actualizar una consulta médica'],
    [ROLES.MEDICO, '/citas/42/consulta/solicitar-estudio?recorrido=solicitar-estudio', 'Solicitar estudios de laboratorio'],
    [ROLES.ADMINISTRADOR, '/pagos/pacientes/9/obligaciones/42?vista=historial', 'Consultar historial financiero de un Paciente'],
  ])('D6 enlace activo %s/%s conserva proceso; misma URL espera blocker sin desmontaje', async (role, path, label) => {
    let blocker, mounts = 0;
    function ProtectedCycle() { blocker = useBlocker(true); useEffect(() => { mounts++; }, []); return <input aria-label="Borrador ficticio D6" defaultValue="Revisión conservada" />; }
    auth.usuario.rol = role; const { router } = mount(path, <ProtectedCycle />); const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Abrir menú' }));
    const link = within(nav()).getByRole('link', { name: label }); expect(link).toHaveAttribute('href', path);
    await user.click(link);
    expect(blocker.state).toBe('blocked'); expect(link).toHaveFocus(); expect(nav()).toHaveClass('is-open');
    const previousTitle = document.title, previousKey = router.state.location.key;
    const breadcrumb = screen.getByRole('navigation', { name: 'Ubicación actual' }).textContent;
    act(() => blocker.reset());
    expect(link).toHaveFocus(); expect(document.title).toBe(previousTitle);
    expect(router.state.location.key).toBe(previousKey); expect(nav()).toHaveClass('is-open');
    await user.click(link); await act(async () => blocker.proceed());
    expect(router.state.location.pathname + router.state.location.search).toBe(path);
    expect(router.state.location.key).not.toBe(previousKey); expect(mounts).toBe(1);
    expect(screen.getByLabelText('Borrador ficticio D6')).toHaveValue('Revisión conservada');
    expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).toHaveTextContent(breadcrumb);
    expect(document.title).toBe(previousTitle); expect(link).toHaveAttribute('aria-current', 'page');
    expect(nav()).not.toHaveClass('is-open'); expect(screen.getByRole('main')).toHaveFocus();
  });
  it.each([
    ['obligaciones', 'Consultar obligaciones de pago propias'],
    ['pagar', 'Pagar una cita'],
    ['historial', 'Consultar historial de pagos propio'],
  ])('D5 %s conserva obligación, opción activa y paquete expandido', (view, label) => {
    auth.usuario.rol = ROLES.PACIENTE;
    mount(`/pagos/obligaciones/42?vista=${view}`);
    const button = within(nav()).getByRole('button', { name: 'Pagos' });
    expect(button).toHaveAttribute('aria-expanded', 'true'); expect(button).toHaveAttribute('aria-disabled', 'true');
    const link = within(nav()).getByRole('link', { name: label });
    expect(link).toHaveAttribute('href', `/pagos/obligaciones/42?vista=${view}`); expect(link).toHaveAttribute('aria-current', 'page');
    expect(document.title).toBe(`${label} | Clínica Vida Salud`);
  });
  it('D5 detalle histórico financiero es neutral, sin seleccionar un CU propio por defecto', () => {
    auth.usuario.rol = ROLES.PACIENTE; mount('/pagos/obligaciones/42');
    expect(nav().querySelector('[aria-current="page"]')).toBeNull();
    expect(within(nav()).getByRole('button', { name: 'Pagos' })).toHaveAttribute('aria-expanded', 'true');
    expect(document.title).toBe('Detalle de obligación | Clínica Vida Salud');
  });
  it.each(['Cargar o corregir documentos de laboratorio', 'Revisar y autorizar la publicación de resultados'])('D4 cambio a %s espera navegación confirmada incluso misma URL', async label => {
    function Pending() { const blocker = useBlocker(true); return blocker.state === 'blocked' && <><button onClick={() => blocker.reset()}>Permanecer</button><button onClick={() => blocker.proceed()}>Continuar</button></>; }
    const { router } = mount('/laboratorio/solicitudes/71?operacion=documentos', <Pending />);
    const user = userEvent.setup(); await user.click(screen.getByRole('button', { name: 'Abrir menú' }));
    const link = within(nav()).getByRole('link', { name: label }); await user.click(link);
    expect(router.state.location.search).toBe('?operacion=documentos'); expect(link).toHaveFocus();
    expect(screen.getByRole('button', { name: 'Cerrar menú' })).toHaveAttribute('aria-expanded', 'true');
    expect(document.title).toBe('Cargar o corregir documentos de laboratorio | Clínica Vida Salud');
    await user.click(screen.getByRole('button', { name: 'Permanecer' })); await user.click(link); await user.click(screen.getByRole('button', { name: 'Continuar' }));
    expect(router.state.location.pathname).toBe('/laboratorio/solicitudes/71');
    expect(link).toHaveAttribute('aria-current', 'page'); expect(document.title).toBe(`${label} | Clínica Vida Salud`);
    expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).toHaveTextContent(label);
    expect(screen.getByRole('main')).toHaveFocus(); expect(screen.getByRole('button', { name: 'Abrir menú' })).toHaveAttribute('aria-expanded', 'false');
  });
  it('D4 detalle histórico expande laboratorio sin atribuir una operación por su modo local', () => {
    mount('/laboratorio/solicitudes/71'); expect(nav().querySelector('[aria-current="page"]')).toBeNull();
    expect(within(nav()).getByRole('button', { name: 'Laboratorio digital' })).toHaveAttribute('aria-expanded', 'true');
    expect(document.title).toBe('Solicitud de laboratorio | Clínica Vida Salud');
  });
  it.each([
    ['/citas/42/gestion?operacion=cancelar', 'Reprogramar una cita', '/citas/42/gestion?operacion=reprogramar'],
    ['/recepcion/pacientes/31/citas?operacion=reprogramar', 'Cancelar una cita', '/recepcion/pacientes/31/citas?operacion=cancelar'],
    ['/citas/42/asistencia?operacion=llegada', 'Marcar una cita como Atendida', '/citas/42/asistencia?operacion=atendida'],
  ])('D3 sidebar en %s conserva montaje y contexto al cambiar operación', async (path, label, destination) => {
    let mounts = 0;
    function ExistingCycle() { useEffect(() => { mounts += 1; }, []); return <h1>Ciclo existente</h1>; }
    const { router } = mount(path, <ExistingCycle />);
    const link = within(nav()).getByRole('link', { name: label });
    expect(link).toHaveAttribute('href', destination);
    await userEvent.click(link);
    expect(router.state.location.pathname + router.state.location.search).toBe(destination);
    expect(mounts).toBe(1);
    expect(link).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('main')).toHaveFocus();
  });
  it.each(['Confirmar la llegada del Paciente', 'Registrar la inasistencia del Paciente'])('D3 navegación a %s solo actualiza menú y foco después del blocker, incluso misma URL', async label => {
    function Pending() {
      const blocker = useBlocker(true);
      return blocker.state === 'blocked' && <><button onClick={() => blocker.reset()}>Permanecer</button><button onClick={() => blocker.proceed()}>Continuar</button></>;
    }
    const { router } = mount('/asistencia?operacion=llegada', <Pending />);
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Abrir menú' }));
    const link = within(nav()).getByRole('link', { name: label });
    await user.click(link);
    expect(router.state.location.search).toBe('?operacion=llegada');
    expect(screen.getByRole('button', { name: 'Cerrar menú' })).toHaveAttribute('aria-expanded', 'true');
    expect(link).toHaveFocus();
    expect(document.title).toBe('Confirmar la llegada del Paciente | Clínica Vida Salud');
    await user.click(screen.getByRole('button', { name: 'Permanecer' }));
    await user.click(link); await user.click(screen.getByRole('button', { name: 'Continuar' }));
    expect(router.state.location.search).toBe(label === 'Confirmar la llegada del Paciente' ? '?operacion=llegada' : '?operacion=inasistencia');
    expect(link).toHaveAttribute('aria-current', 'page');
    expect(document.title).toBe(`${label} | Clínica Vida Salud`);
    expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).toHaveTextContent(label);
    expect(screen.getByRole('main')).toHaveFocus();
    expect(screen.getByRole('button', { name: 'Abrir menú' })).toHaveAttribute('aria-expanded', 'false');
  });
  it.each(['/asistencia', '/citas/42/asistencia', '/recepcion/citas/gestion', '/citas/42/gestion'])('D3 URL histórica %s mantiene paquete abierto sin seleccionar una operación falsa', path => {
    mount(path);
    expect(nav().querySelector('[aria-current="page"]')).toBeNull();
    expect(nav().querySelector('.nav-module.is-current .nav-module-toggle')).toHaveAttribute('aria-expanded', 'true');
  });
  it.each([
    ['/recepcion/pacientes/42/editar', 'Pacientes y perfil personal', 'Gestionar el registro administrativo de pacientes', 'Editar paciente'],
    ['/recepcion/activacion-web', 'Pacientes y perfil personal', 'Activación web', 'Activación web'],
    ['/consultar-disponibilidad', 'Registro y reserva de citas médicas', 'Buscar médicos y consultar disponibilidad', 'Buscar médicos y consultar disponibilidad'],
    ['/recepcion/citas/nueva', 'Registro y reserva de citas médicas', 'Programar o reservar una cita presencial', 'Programar o reservar una cita presencial'],
    ['/recepcion/pacientes/42/citas', 'Agenda e historial de citas', 'Consultar el historial y seguimiento de citas', 'Historial de citas'],
    ['/recepcion/citas/gestion?operacion=reprogramar', 'Reprogramación y cancelación', 'Reprogramar una cita', 'Reprogramar una cita'],
    ['/citas/42/gestion?operacion=cancelar', 'Reprogramación y cancelación', 'Cancelar una cita', 'Cancelar una cita'],
    ['/asistencia?operacion=llegada', 'Estados de citas y control de asistencia', 'Confirmar la llegada del Paciente', 'Confirmar la llegada del Paciente'],
    ['/citas/42/asistencia?operacion=atendida', 'Estados de citas y control de asistencia', 'Marcar una cita como Atendida', 'Marcar una cita como Atendida'],
    ['/laboratorio/solicitudes/71?operacion=consulta', 'Laboratorio digital', 'Consultar solicitudes y descargar resultados autorizados', 'Consultar solicitudes y descargar resultados autorizados'],
    ['/laboratorio/pacientes/21', 'Laboratorio digital', 'Consultar solicitudes y descargar resultados autorizados', 'Estudios del paciente'],
    ['/citas/41/consulta/solicitar-estudio', 'Laboratorio digital', 'Solicitar estudios de laboratorio', 'Solicitar estudio de laboratorio'],
  ])('Administrador reconoce %s como flujo compartido manteniendo su área y foco', (path, module, link, title) => {
    mount(path);
    expect(within(nav()).getByRole('button', { name: module })).toHaveAttribute('aria-expanded', 'true');
    expect(within(nav()).getByRole('link', { name: link })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).toHaveTextContent('Administración');
    expect(document.title).toBe(`${title} | Clínica Vida Salud`);
    expect(screen.getByRole('main')).toHaveFocus();
  });
  it.each([
    [ROLES.ADMINISTRADOR, 'Médicos y especialidades', 'Gestionar médicos y sus especialidades', 'Actualizar el perfil personal'],
    [ROLES.RECEPCIONISTA, 'Pacientes y perfil personal', 'Gestionar el registro administrativo de pacientes', 'Administrar cuentas de usuario y su acceso'],
    [ROLES.PACIENTE, 'Registro y reserva de citas médicas', 'Programar o reservar una cita presencial', 'Gestionar el registro administrativo de pacientes'],
    [ROLES.MEDICO, 'Usuarios y autenticación', 'Cambiar contraseña propia', 'Gestionar el registro administrativo de pacientes'],
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
  it('ruta profunda abre consultorios, no médicos, sin IDs visibles', () => {
    mount('/administracion/medicos/42/consultorio');
    expect(within(nav()).getByRole('button', { name: 'Consultorios, horarios y disponibilidad' })).toHaveAttribute('aria-expanded', 'true');
    expect(within(nav()).getByRole('button', { name: 'Médicos y especialidades' })).toHaveAttribute('aria-expanded', 'false');
    expect(within(nav()).getByRole('link', { name: 'Gestionar consultorios y su asignación fija' })).toHaveAttribute('aria-current', 'page');
    expect(document.title).toBe('Asignar consultorio | Clínica Vida Salud');
    expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).toHaveTextContent('Asignar consultorio');
    expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).not.toHaveTextContent('42');
  });
  it('mantiene el activo abierto con clic, Enter y Espacio, sin perder foco ni su enlace por Tab', async () => {
    mount('/administracion/cuentas'); const user = userEvent.setup();
    const button = within(nav()).getByRole('button', { name: 'Usuarios y autenticación' }); button.focus();
    expect(button).toHaveAttribute('aria-disabled', 'true');
    for (const key of ['{Enter}', ' ']) {
      await user.keyboard(key);
      expect(button).toHaveAttribute('aria-expanded', 'true'); expect(button).toHaveFocus();
    }
    await user.click(button);
    expect(button).toHaveAttribute('aria-expanded', 'true');
    const link = within(nav()).getByRole('link', { name: 'Administrar cuentas de usuario y su acceso' });
    expect(link).toHaveAttribute('aria-current', 'page');
    expect(document.getElementById(button.getAttribute('aria-controls'))).not.toHaveAttribute('hidden');
    await user.tab(); expect(link).toHaveFocus();
  });
  it('permite varios abiertos y contraer los no activos con Enter/Espacio conservando el foco', async () => {
    mount('/administracion/cuentas'); const user = userEvent.setup();
    const doctors = within(nav()).getByRole('button', { name: 'Médicos y especialidades' });
    const rooms = within(nav()).getByRole('button', { name: 'Consultorios, horarios y disponibilidad' });
    await user.click(rooms); doctors.focus();
    expect(doctors).not.toHaveAttribute('aria-disabled');
    await user.keyboard('{Enter}'); expect(doctors).toHaveAttribute('aria-expanded', 'true'); expect(doctors).toHaveFocus();
    await user.keyboard(' '); expect(doctors).toHaveAttribute('aria-expanded', 'false'); expect(doctors).toHaveFocus();
    await user.click(doctors); expect(doctors).toHaveAttribute('aria-expanded', 'true');
    expect(rooms).toHaveAttribute('aria-expanded', 'true');
    expect(within(nav()).getByRole('button', { name: 'Usuarios y autenticación' })).toHaveAttribute('aria-expanded', 'true');
  });
  it('reabre al cambiar ruta, incluso atrás/adelante, y conserva otras expansiones', async () => {
    const { router } = mount('/administracion/cuentas'); const user = userEvent.setup();
    const button = within(nav()).getByRole('button', { name: 'Usuarios y autenticación' });
    await user.click(button); expect(button).toHaveAttribute('aria-expanded', 'true');
    await act(async () => router.navigate('/administracion/cuentas/42'));
    expect(button).toHaveAttribute('aria-expanded', 'true');
    await user.click(within(nav()).getByRole('button', { name: 'Agenda e historial de citas' }));
    await act(async () => router.navigate('/agenda'));
    await user.click(button);
    expect(button).toHaveAttribute('aria-expanded', 'false');
    expect(button).not.toHaveAttribute('aria-disabled');
    await act(async () => router.navigate(-1)); expect(button).toHaveAttribute('aria-expanded', 'true');
    await act(async () => router.navigate(1));
    expect(within(nav()).getByRole('link', { name: 'Consultar la agenda médica' })).toHaveAttribute('aria-current', 'page');
  });
  it('Administrador tiene consulta de recordatorios operativa sin acciones de gestión de citas en ese módulo', async () => {
    auth.usuario.rol = ROLES.ADMINISTRADOR; mount('/otra'); const user = userEvent.setup();
    await user.click(within(nav()).getByRole('button', { name: 'Recordatorios automáticos' }));
    expect(within(nav()).getByText('Consultar recordatorios de próximas citas')).toBeVisible();
    expect(within(nav()).getByRole('link', { name: /recordatorios/i })).toHaveAttribute('href', '/recordatorios');
    expect(within(nav()).queryByText(/cancelar cita|reprogramar cita/i)).not.toBeInTheDocument();
  });
  it('reinicia expansión al cambiar identidad o rol sin persistir estado', async () => {
    const { refresh } = mount('/otra'); const user = userEvent.setup();
    await user.click(within(nav()).getByRole('button', { name: 'Médicos y especialidades' }));
    auth.usuario = { ...auth.usuario, id_usuario: '2' }; refresh();
    expect(within(nav()).getByRole('button', { name: 'Médicos y especialidades' })).toHaveAttribute('aria-expanded', 'false');
    auth.usuario = { ...auth.usuario, rol: ROLES.PACIENTE }; refresh();
    expect(within(nav()).queryByRole('button', { name: 'Médicos y especialidades' })).not.toBeInTheDocument();
    expect(within(nav()).getByRole('button', { name: 'Usuarios y autenticación' })).toHaveAttribute('aria-expanded', 'false');
  });
  it('cambiar identidad y rol conserva el paquete activo profundo, sin enlaces ajenos ni selección falsa', async () => {
    const { refresh } = mount('/citas/42'); const user = userEvent.setup();
    await user.click(within(nav()).getByRole('button', { name: 'Médicos y especialidades' }));
    auth.usuario = { ...auth.usuario, id_usuario: '2' }; refresh();
    expect(within(nav()).getByRole('button', { name: 'Médicos y especialidades' })).toHaveAttribute('aria-expanded', 'false');
    auth.usuario = { ...auth.usuario, rol: ROLES.PACIENTE }; refresh();
    const active = within(nav()).getByRole('button', { name: 'Agenda e historial de citas' });
    expect(active).toHaveAttribute('aria-expanded', 'true'); expect(active).toHaveAttribute('aria-disabled', 'true');
    expect(within(nav()).getByRole('link', { name: 'Consultar el historial y seguimiento de citas' })).toBeVisible();
    expect(nav().querySelector('[aria-current="page"]')).toBeNull();
    expect(within(nav()).queryByRole('link', { name: 'Consultar la agenda médica' })).not.toBeInTheDocument();
    expect(within(nav()).queryByRole('button', { name: 'Médicos y especialidades' })).not.toBeInTheDocument();
    expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).toHaveTextContent('Detalle de cita');
  });
  it.each([
    [ROLES.MEDICO, '/citas/42/consulta/editar', 'Historia clínica y consultas médicas', 'Registrar o actualizar una consulta médica'],
    [ROLES.PACIENTE, '/citas/42/consulta', 'Historia clínica y consultas médicas', 'Consultar la historia clínica autorizada'],
    [ROLES.MEDICO, '/laboratorio/solicitudes/42?operacion=consulta', 'Laboratorio digital', 'Consultar solicitudes y descargar resultados autorizados'],
    [ROLES.PACIENTE, '/laboratorio/solicitudes/42?operacion=consulta', 'Laboratorio digital', 'Consultar solicitudes y descargar resultados autorizados'],
    [ROLES.ADMINISTRADOR, '/pagos/pacientes/42/obligaciones/17', 'Pagos', 'Consultar historial financiero de un Paciente'],
    [ROLES.PACIENTE, '/pagos/obligaciones/17/pagar', 'Pagos', 'Pagar una cita'],
  ])('%s abre y mantiene seleccionado el paquete de %s', async (role, path, module, label) => {
    auth.usuario.rol = role; mount(path); const user = userEvent.setup();
    const button = within(nav()).getByRole('button', { name: module });
    await user.click(button);
    expect(button).toHaveAttribute('aria-expanded', 'true'); expect(button).toHaveAttribute('aria-disabled', 'true');
    expect(within(nav()).getByRole('link', { name: label })).toHaveAttribute('aria-current', 'page');
  });
  it.each([
    [ROLES.RECEPCIONISTA, '/historia-clinica'],
    [ROLES.PACIENTE, '/citas/42/consulta/editar'],
    [ROLES.PACIENTE, '/citas/42/consulta/versiones'],
    [ROLES.PACIENTE, '/laboratorio/pacientes/42'],
    [ROLES.MEDICO, '/laboratorio/pacientes/42'],
    [ROLES.ADMINISTRADOR, '/pagos/obligaciones/17/pagar'],
    [ROLES.PACIENTE, '/pagos/pacientes/42'],
    [ROLES.MEDICO, '/pagos'],
  ])('%s no selecciona ni expande paquetes por una URL restringida %s', (role, path) => {
    auth.usuario.rol = role; mount(path);
    expect(nav().querySelector('.is-current, [aria-current="page"], [aria-expanded="true"]')).toBeNull();
    expect(document.title).toBe('Acceso no permitido | Clínica Vida Salud');
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
    await user.click(within(nav()).getByRole('link', { name: 'Cambiar contraseña propia' }));
    expect(nav()).not.toHaveClass('is-open'); expect(screen.getByRole('main')).toHaveFocus();
  });
  it('desplegar no desmonta el contenido ni dispara el blocker de una reserva', async () => {
    let mounts = 0;
    function Reserva() { useBlocker(true); useEffect(() => { mounts++; }, []); return <input aria-label="Borrador de reserva" defaultValue="" />; }
    auth.usuario.rol = ROLES.PACIENTE; const { router } = mount('/reservar-cita', <Reserva />); const user = userEvent.setup();
    await user.type(screen.getByLabelText('Borrador de reserva'), 'Texto ficticio'); const previous = mounts;
    await user.click(within(nav()).getByRole('button', { name: 'Registro y reserva de citas médicas' }));
    await user.click(within(nav()).getByRole('button', { name: 'Usuarios y autenticación' }));
    expect(screen.getByLabelText('Borrador de reserva')).toHaveValue('Texto ficticio'); expect(mounts).toBe(previous);
    expect([...router.state.blockers.values()].every(blocker => blocker.state === 'unblocked')).toBe(true);
    expect(router.state.location.pathname).toBe('/reservar-cita');
  });
  it('una navegación bloqueada conserva ruta, expansión y foco del menú hasta resolverla', async () => {
    let blocker;
    function Reserva() { blocker = useBlocker(true); return <input aria-label="Borrador de reserva" defaultValue="Texto ficticio" />; }
    auth.usuario.rol = ROLES.PACIENTE; const { router } = mount('/reservar-cita', <Reserva />); const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Abrir menú' }));
    await user.click(within(nav()).getByRole('button', { name: 'Usuarios y autenticación' }));
    const link = within(nav()).getByRole('link', { name: 'Cambiar contraseña propia' });
    await user.click(link);
    expect(blocker.state).toBe('blocked'); expect(router.state.location.pathname).toBe('/reservar-cita');
    expect(nav()).toHaveClass('is-open'); expect(link).toHaveFocus();
    expect(within(nav()).getByRole('button', { name: 'Registro y reserva de citas médicas' })).toHaveAttribute('aria-expanded', 'true');
    expect(link).not.toHaveAttribute('aria-current');
    act(() => blocker.reset());
    expect(screen.getByLabelText('Borrador de reserva')).toHaveValue('Texto ficticio');
    expect(nav()).toHaveClass('is-open'); expect(link).toHaveFocus();
    await user.click(link); await act(async () => blocker.proceed());
    expect(router.state.location.pathname).toBe('/mi-cuenta/contrasena');
    expect(nav()).not.toHaveClass('is-open'); expect(screen.getByRole('main')).toHaveFocus();
    expect(link).toHaveAttribute('aria-current', 'page');
    expect(within(nav()).getByRole('button', { name: 'Usuarios y autenticación' })).toHaveAttribute('aria-expanded', 'true');
  });
  it.each([true, false])('al pasar a escritorio, foco del botón móvil va al enlace visible (ruta actual: %s)', async active => {
    let onChange; const media = { matches: false, addEventListener: vi.fn((_name, handler) => { onChange = handler; }), removeEventListener: vi.fn() };
    vi.stubGlobal('matchMedia', vi.fn(() => media));
    const { unmount } = mount(active ? '/mi-cuenta/contrasena' : '/otra');
    screen.getByRole('button', { name: 'Abrir menú' }).focus();
    // Chromium puede retirar el foco antes de notificar el cambio de media query.
    media.matches = true; screen.getByRole('button', { name: 'Abrir menú' }).blur();
    act(() => onChange());
    expect(within(nav()).getByRole('link', { name: active ? 'Cambiar contraseña propia' : 'Inicio' })).toHaveFocus();
    expect(nav()).not.toHaveClass('is-open'); unmount(); expect(media.removeEventListener).toHaveBeenCalledWith('change', onChange);
  });
  it('cambiar a escritorio no roba foco del formulario', () => {
    let onChange; const media = { matches: false, addEventListener: (_name, handler) => { onChange = handler; }, removeEventListener: vi.fn() };
    vi.stubGlobal('matchMedia', vi.fn(() => media)); mount('/otra', <input aria-label="Campo de trabajo" />);
    screen.getByRole('button', { name: 'Abrir menú' }).focus(); screen.getByLabelText('Campo de trabajo').focus();
    media.matches = true; act(() => onChange()); expect(screen.getByLabelText('Campo de trabajo')).toHaveFocus();
  });
  it('al pasar a móvil recupera foco del nav que Chromium ya ocultó', () => {
    let onChange; const media = { matches: true, addEventListener: (_name, handler) => { onChange = handler; }, removeEventListener: vi.fn() };
    vi.stubGlobal('matchMedia', vi.fn(() => media)); mount('/mi-cuenta/contrasena');
    const navigation = nav();
    within(navigation).getByRole('link', { name: 'Cambiar contraseña propia' }).focus();
    // Simula CSS aplicado antes de que llegue el evento de media query.
    media.matches = false; navigation.style.display = 'none'; document.activeElement.blur();
    act(() => onChange()); expect(screen.getByRole('button', { name: 'Abrir menú' })).toHaveFocus();
    expect(navigation).not.toHaveClass('is-open');
  });
  it('al pasar a móvil no roba foco si se salió del nav hacia el formulario', () => {
    let onChange; const media = { matches: true, addEventListener: (_name, handler) => { onChange = handler; }, removeEventListener: vi.fn() };
    vi.stubGlobal('matchMedia', vi.fn(() => media)); mount('/otra', <input aria-label="Campo de trabajo" />);
    within(nav()).getByRole('link', { name: 'Inicio' }).focus(); screen.getByLabelText('Campo de trabajo').focus();
    media.matches = false; nav().style.display = 'none'; act(() => onChange());
    expect(screen.getByLabelText('Campo de trabajo')).toHaveFocus();
  });
  it('al pasar a escritorio el activo sigue accesible aunque se haya intentado contraerlo', async () => {
    let onChange; const media = { matches: false, addEventListener: (_name, handler) => { onChange = handler; }, removeEventListener: vi.fn() };
    vi.stubGlobal('matchMedia', vi.fn(() => media)); mount('/mi-cuenta/contrasena'); const user = userEvent.setup();
    await user.click(within(nav()).getByRole('button', { name: 'Usuarios y autenticación' }));
    screen.getByRole('button', { name: 'Abrir menú' }).focus(); media.matches = true; act(() => onChange());
    expect(within(nav()).getByRole('link', { name: 'Cambiar contraseña propia' })).toHaveFocus();
  });
});
