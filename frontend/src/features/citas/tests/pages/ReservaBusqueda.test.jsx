import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion, cerrarSesion } from '../../../auth/services/auth.service';
import { crearRutas } from '../../../../app/router';
import { api } from '../../../../api/api';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
const id = '9223372036854775807';
let calls, role, userId, patients, specialties, doctors, slots, reservable, request, selectedPage;
const specialty = { id_especialidad: '1', nombre: 'General ficticia' };
const doctor = { id_medico: '9007199254740993', nombres: 'Médico', apellidos: 'Ficticio', codigo_medico: 'MED-1', consultorio: { id_consultorio: '3', codigo_consultorio: 'SALA-1', nombre: 'Sala ficticia' } };
const slot = { inicio_at: '2026-11-01T12:00:01.000000Z', fin_at: '2026-11-01T12:15:01.000000Z', hora_inicio: '08:00:01', revision_turno: 'a'.repeat(64) };
const error = (config, status, code = 'VALIDACION', details) => {
  const e = new AxiosError('SQL secreto', undefined, config, null, status ? { status, headers: {}, data: { error: { code, message: 'SQL secreto', details } } } : undefined);
  if (status === 429) e.response.headers['retry-after'] = '60';
  return e;
};
function session(rol = role) { return { id_usuario: userId, nombre_visible: 'Persona ficticia', correo_acceso: 'persona@example.test', rol }; }
const meta = (total, page = 1) => ({ total, current_page: page, last_page: selectedPage ? 2 : 1, per_page: 10 });
function response(config) {
  const page = config.params?.page ?? 1;
  const ok = data => ({ config, status: 200, headers: {}, data });
  if (config.url === '/pacientes') return ok({ data: patients, meta: meta(patients.length, page) });
  if (config.url === '/reservas/especialidades') return ok({ data: specialties, meta: meta(specialties.length, page) });
  if (config.url === '/reservas/medicos') return ok({ data: doctors, meta: meta(doctors.length, page) });
  if (config.url === '/reservas/disponibilidad') return ok({ data: { fecha: config.params.fecha, zona_horaria: 'America/La_Paz',
    duracion_minutos: 15, paciente_reservable: reservable, medico: doctor, especialidad: specialty, consultorio: doctor.consultorio,
    turnos: slots }, meta: meta(slots.length, page), links: { next: 'https://ajeno.invalid/no-seguir' } });
  throw new Error('Petición inesperada');
}
function mount(path) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path ?? (role === 'Recepcionista' ? '/recepcion/citas/nueva' : '/reservar-cita')] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks(); calls = []; role = 'Paciente'; userId = '7'; reservable = true; request = null; selectedPage = false;
  specialties = [specialty]; doctors = [doctor]; slots = [slot];
  patients = [{ id_paciente: id, nombres: 'Paciente', apellidos: 'Sin documento', codigo_paciente: 'PAC-1', documento_identidad: null, activo: true }];
  consultarSesion.mockImplementation(async () => session()); cerrarSesion.mockResolvedValue();
  api.defaults.adapter = async config => { calls.push(config); return request ? request(config) : response(config); };
});
afterEach(() => { api.defaults.adapter = original; });
async function select(user) {
  if (['Administrador', 'Recepcionista'].includes(role)) await user.click(await screen.findByRole('button', { name: /Paciente Sin documento · PAC-1/ }));
  await user.click(await screen.findByRole('button', { name: 'General ficticia' }));
  await user.click(await screen.findByRole('button', { name: /Médico Ficticio · MED-1/ }));
}
async function search(user, date = '2026-11-01') {
  await select(user);
  fireEvent.change(screen.getByLabelText('Fecha de atención'), { target: { value: date } });
  await user.click(screen.getByRole('button', { name: 'Consultar turnos' }));
  await screen.findByRole('heading', { name: 'Turnos disponibles' });
}
it.each(['Médico'])('deniega ambas rutas a %s sin peticiones de reserva', async rol => {
  role = rol; const router = mount('/reservar-cita'); await screen.findByRole('heading', { name: 'Acceso no permitido' });
  await act(() => router.navigate('/recepcion/citas/nueva')); await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
});
it.each([['Paciente', '/recepcion/citas/nueva'], ['Recepcionista', '/reservar-cita'], ['Administrador', '/reservar-cita']])('deniega %s en ruta %s', async (rol, path) => {
  role = rol; mount(path); await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
});
it('solo ofrece Reservar cita al Paciente y consulta disponibilidad propia con segundos', async () => {
  const user = userEvent.setup(); mount(); await search(user);
  const radio = await screen.findByRole('radio', { name: '08:00:01' }); await user.click(radio);
  expect(radio).toBeChecked(); expect(screen.getByText(/Turno seleccionado: 01\/11\/2026, 08:00:01/)).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Reservar cita' })).toBeInTheDocument(); expect(screen.queryByRole('link', { name: 'Programar cita' })).not.toBeInTheDocument();
  expect(screen.queryByLabelText('CI (si existe)')).not.toBeInTheDocument(); expect(screen.getByText(/Hora de la sede: America\/La_Paz/)).toBeInTheDocument();
  expect(calls.find(c => c.url.endsWith('/disponibilidad')).params).toEqual({ id_especialidad: '1', id_medico: doctor.id_medico, fecha: '2026-11-01', page: 1, per_page: 12 });
  expect(calls.every(c => c.method === 'get')).toBe(true); expect(document.title).toBe('Reservar cita | Clínica Vida Salud');
  expect(screen.getByRole('button', { name: 'Revisar cita' })).toBeEnabled();
});
it.each(['Administrador', 'Recepcionista'])('%s selecciona ficha sin CI y no necesita cuenta web', async operador => {
  role = operador; const user = userEvent.setup(); mount('/recepcion/citas/nueva'); await search(user);
  await screen.findByRole('radio', { name: '08:00:01' });
  expect(screen.getByRole('link', { name: 'Programar cita' })).toBeInTheDocument();
  expect(screen.getByText(/Paciente seleccionado: Paciente Sin documento/)).toBeInTheDocument();
  expect(calls.find(c => c.url.endsWith('/disponibilidad')).params.id_paciente).toBe(id);
  expect(document.title).toBe('Programar cita | Clínica Vida Salud');
});
it.each(['Administrador', 'Recepcionista'])('%s no consulta especialidades ni turnos hasta seleccionar ficha', async operador => {
  role = operador; mount('/recepcion/citas/nueva'); await screen.findByRole('button', { name: /Paciente Sin documento/ });
  expect(calls.map(c => c.url)).toEqual(['/pacientes']); expect(screen.queryByLabelText('Fecha de atención')).not.toBeInTheDocument();
});
it('ficha inactiva permite consultar pero no seleccionar turno', async () => {
  reservable = false; const user = userEvent.setup(); mount(); await search(user);
  expect(await screen.findByRole('radio', { name: '08:00:01' })).toBeDisabled(); expect(screen.getByText(/La ficha del Paciente está inactiva/)).toBeInTheDocument();
});
it('datos de ocupantes y revisión opaca nunca se imprimen en pantalla', async () => {
  slots = [{ ...slot, paciente: 'OCUPANTE-PRIVADO', motivo: 'MOTIVO-PRIVADO' }];
  const user = userEvent.setup(); mount(); await search(user); await screen.findByRole('radio');
  for (const text of ['OCUPANTE-PRIVADO', 'MOTIVO-PRIVADO', slot.revision_turno]) expect(screen.queryByText(text)).not.toBeInTheDocument();
});
it('cambiar fecha retira resultados y turno elegido sin reconsultar automáticamente', async () => {
  const user = userEvent.setup(); mount(); await search(user); await user.click(await screen.findByRole('radio'));
  fireEvent.change(screen.getByLabelText('Fecha de atención'), { target: { value: '2026-11-02' } });
  expect(screen.queryByRole('radio')).not.toBeInTheDocument(); expect(screen.queryByText(/Turno seleccionado:/)).not.toBeInTheDocument();
  expect(calls.filter(c => c.url.endsWith('/disponibilidad'))).toHaveLength(1);
});
it('editar filtro de Médicos invalida Médico/fecha consultada inmediatamente', async () => {
  const user = userEvent.setup(); mount(); await search(user); await screen.findByRole('radio');
  await user.click(screen.getByRole('button', { name: 'Cambiar médico' }));
  fireEvent.change(screen.getByLabelText('Nombre del Médico'), { target: { value: 'Otro' } });
  expect(screen.queryByRole('radio')).not.toBeInTheDocument(); expect(screen.queryByLabelText('Fecha de atención')).not.toBeInTheDocument();
});
it('editar especialidad invalida los catálogos dependientes', async () => {
  const user = userEvent.setup(); mount(); await search(user); await screen.findByRole('radio');
  await user.click(screen.getByRole('button', { name: 'Cambiar especialidad' }));
  fireEvent.change(screen.getByLabelText('Nombre de especialidad'), { target: { value: 'Otra' } });
  expect(screen.queryByLabelText('Nombre del Médico')).not.toBeInTheDocument(); expect(screen.queryByRole('radio')).not.toBeInTheDocument();
});
it.each(['Administrador', 'Recepcionista'])('%s: editar ficha invalida especialidad y Médico sin persistir selección', async operador => {
  role = operador; const user = userEvent.setup(); mount('/recepcion/citas/nueva'); await search(user); await screen.findByRole('radio');
  await user.click(screen.getByRole('button', { name: 'Cambiar paciente' }));
  fireEvent.change(screen.getByLabelText('Nombres del Paciente'), { target: { value: 'Otra ficha' } });
  expect(screen.queryByText(/Paciente seleccionado:/)).not.toBeInTheDocument(); expect(screen.queryByLabelText('Nombre de especialidad')).not.toBeInTheDocument();
});
it('fecha vacía señala campo y foco sin HTTP de disponibilidad', async () => {
  const user = userEvent.setup(); mount(); await select(user); await user.click(screen.getByRole('button', { name: 'Consultar turnos' }));
  expect(screen.getByLabelText('Fecha de atención')).toHaveAttribute('aria-invalid', 'true'); expect(screen.getByLabelText('Fecha de atención')).toHaveFocus();
  expect(calls.some(c => c.url.endsWith('/disponibilidad'))).toBe(false);
});
it('fecha muy futura no tiene máximo funcional y se envía sin desplazamiento UTC', async () => {
  const user = userEvent.setup(); mount(); await search(user, '9999-12-31'); await screen.findByRole('radio');
  expect(calls.find(c => c.url.endsWith('/disponibilidad')).params.fecha).toBe('9999-12-31'); expect(screen.getByLabelText('Fecha de atención')).not.toHaveAttribute('max');
});
it.each(['especialidades', 'médicos', 'turnos'])('estado vacío de %s no inventa selección', async kind => {
  const user = userEvent.setup();
  if (kind === 'especialidades') specialties = [];
  if (kind === 'médicos') doctors = [];
  if (kind === 'turnos') slots = [];
  mount();
  if (kind !== 'especialidades') await user.click(await screen.findByRole('button', { name: 'General ficticia' }));
  if (kind === 'turnos') {
    await user.click(await screen.findByRole('button', { name: /Médico Ficticio/ }));
    fireEvent.change(screen.getByLabelText('Fecha de atención'), { target: { value: '2026-11-01' } }); await user.click(screen.getByRole('button', { name: 'Consultar turnos' }));
  }
  await screen.findByRole('heading', { name: kind === 'turnos' ? 'No hay turnos disponibles para esta fecha' : `No hay ${kind} para estos criterios` }); expect(screen.queryByRole('radio')).not.toBeInTheDocument();
});
it('paginación conserva selección de consulta pero elimina turno y no sigue URLs recibidas', async () => {
  selectedPage = true; const user = userEvent.setup(); mount(); await search(user); await user.click(await screen.findByRole('radio'));
  await user.click(within(screen.getByRole('navigation', { name: 'Paginación de turnos' })).getByRole('button', { name: 'Siguiente' }));
  await waitFor(() => expect(calls.filter(c => c.url.endsWith('/disponibilidad'))).toHaveLength(2));
  expect(calls.filter(c => c.url.endsWith('/disponibilidad'))[1].params.page).toBe(2); expect(screen.queryByText(/Turno seleccionado:/)).not.toBeInTheDocument();
  expect(calls.some(c => c.url.includes('ajeno.invalid'))).toBe(false);
});
it('actualizar disponibilidad retira elección y consulta explícitamente', async () => {
  const user = userEvent.setup(); mount(); await search(user); await user.click(await screen.findByRole('radio')); await user.click(screen.getByRole('button', { name: 'Actualizar disponibilidad' }));
  await waitFor(() => expect(calls.filter(c => c.url.endsWith('/disponibilidad'))).toHaveLength(2));
  expect(screen.queryByText(/Turno seleccionado:/)).not.toBeInTheDocument();
});
it.each([403, 404, 409, 419, 422, 429, 500, undefined])('fallo de disponibilidad %s usa mensajes seguros sin reintentar', async status => {
  request = c => { if (c.url.endsWith('/disponibilidad')) throw error(c, status); return response(c); };
  const user = userEvent.setup(); mount(); await search(user); await screen.findByRole('alert');
  expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument(); expect(screen.queryByRole('radio')).not.toBeInTheDocument();
  expect(calls.filter(c => c.url.endsWith('/disponibilidad'))).toHaveLength(1);
  if (status === 429) expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeDisabled();
});
it('401 limpia y dirige al login', async () => {
  request = c => { if (c.url.endsWith('/disponibilidad')) throw error(c, 401); return response(c); };
  const user = userEvent.setup(); mount(); await select(user); fireEvent.change(screen.getByLabelText('Fecha de atención'), { target: { value: '2026-11-01' } });
  await user.click(screen.getByRole('button', { name: 'Consultar turnos' })); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(screen.queryByText('General ficticia')).not.toBeInTheDocument();
});
it('descarta respuesta tardía tras editar fecha', async () => {
  let resolve; request = c => c.url.endsWith('/disponibilidad') ? new Promise(done => { resolve = () => done(response(c)); }) : response(c);
  const user = userEvent.setup(); mount(); await search(user); await waitFor(() => expect(resolve).toBeTypeOf('function'));
  fireEvent.change(screen.getByLabelText('Fecha de atención'), { target: { value: '2026-11-02' } }); await act(async () => resolve());
  expect(screen.queryByRole('radio')).not.toBeInTheDocument();
});
it.each(['Administrador', 'Recepcionista'])('%s: salir y volver no conserva ficha, criterios ni resultados en history o Web Storage', async operador => {
  const storage = vi.spyOn(Storage.prototype, 'setItem'); role = operador;
  const user = userEvent.setup(); const router = mount('/recepcion/citas/nueva'); await search(user); await user.click(await screen.findByRole('radio'));
  expect(router.state.location.state).toBeNull(); expect(router.state.location.search).toBe('');
  await act(() => router.navigate('/inicio')); await act(() => router.navigate('/recepcion/citas/nueva')); await screen.findByLabelText('CI (si existe)');
  expect(screen.queryByText(/Paciente seleccionado:/)).not.toBeInTheDocument(); expect(screen.queryByRole('radio')).not.toBeInTheDocument(); expect(storage).not.toHaveBeenCalled(); storage.mockRestore();
});
it.each(['Administrador', 'Recepcionista'])('%s: pagehide limpia selección y formularios privados', async operador => {
  role = operador; const user = userEvent.setup(); mount('/recepcion/citas/nueva'); await search(user); await screen.findByRole('radio');
  act(() => window.dispatchEvent(new Event('pagehide'))); expect(screen.queryByRole('radio')).not.toBeInTheDocument(); expect(screen.getByLabelText('Nombres del Paciente')).toHaveValue('');
});
it('consulta filtrada de Médicos usa nombre/consultorio y no endpoints administrativos', async () => {
  const user = userEvent.setup(); mount(); await user.click(await screen.findByRole('button', { name: 'General ficticia' }));
  fireEvent.change(screen.getByLabelText('Nombre del Médico'), { target: { value: ' Ficticio ' } });
  fireEvent.change(screen.getByLabelText('Código o nombre del consultorio'), { target: { value: ' SALA_% ' } });
  await user.click(screen.getByRole('button', { name: 'Buscar médicos' }));
  await waitFor(() => expect(calls.filter(c => c.url === '/reservas/medicos').at(-1).params.nombre).toBe('Ficticio'));
  expect(calls.filter(c => c.url === '/reservas/medicos').at(-1).params.consultorio).toBe('SALA_%');
  expect(calls.some(c => ['/medicos', '/especialidades', '/consultorios'].includes(c.url))).toBe(false);
});
it('página de especialidades invalida Médico seleccionado y reinicia dependencias', async () => {
  selectedPage = true; const user = userEvent.setup(); mount(); await search(user); await screen.findByRole('radio');
  await user.click(screen.getByRole('button', { name: 'Cambiar especialidad' }));
  await user.click(within(screen.getByRole('navigation', { name: 'Paginación de especialidades' })).getByRole('button', { name: 'Siguiente' }));
  await waitFor(() => expect(calls.filter(c => c.url === '/reservas/especialidades').at(-1).params.page).toBe(2));
  expect(screen.queryByLabelText('Nombre del Médico')).not.toBeInTheDocument(); expect(screen.queryByRole('radio')).not.toBeInTheDocument();
});
it('resume selecciones y permite reabrir por teclado sin perder el turno ni volver a consultar', async () => {
  const user = userEvent.setup(); mount(); await search(user);
  await user.click(await screen.findByRole('radio', { name: '08:00:01' }));
  const change = screen.getByRole('button', { name: 'Cambiar médico' });
  expect(change).toHaveAttribute('aria-expanded', 'false');
  expect(screen.queryByRole('form', { name: 'Buscar médicos' })).not.toBeInTheDocument();
  const count = calls.length; change.focus(); await user.keyboard('{Enter}');
  expect(screen.getByRole('button', { name: 'Cerrar búsqueda' })).toHaveAttribute('aria-expanded', 'true');
  expect(screen.getByRole('form', { name: 'Buscar médicos' })).toBeInTheDocument();
  expect(screen.getByRole('radio', { name: '08:00:01' })).toBeChecked(); expect(calls).toHaveLength(count);
  await user.click(screen.getByRole('button', { name: 'Cerrar búsqueda' }));
  expect(screen.getByRole('radio', { name: '08:00:01' })).toBeChecked(); expect(calls).toHaveLength(count);
});
it('seleccionar un catálogo devuelve foco al título visible, no deja foco en la opción oculta', async () => {
  const user = userEvent.setup(); mount(); await user.click(await screen.findByRole('button', { name: 'General ficticia' }));
  expect(screen.getByRole('heading', { name: 'Especialidad', exact: true })).toHaveFocus();
  expect(screen.getByRole('button', { name: 'Cambiar especialidad' })).toHaveAttribute('aria-expanded', 'false');
  expect(screen.queryByRole('button', { name: 'General ficticia' })).not.toBeInTheDocument();
  await user.click(await screen.findByRole('button', { name: /Médico Ficticio · MED-1/ }));
  expect(screen.getByRole('heading', { name: 'Médico', exact: true })).toHaveFocus();
});
it('los turnos conservan segundos y explican su carácter orientativo al grupo accesible', async () => {
  const user = userEvent.setup(); mount(); await search(user); await screen.findByRole('radio');
  const group = screen.getByRole('group', { name: 'Selecciona un turno del 01/11/2026' });
  expect(group).toHaveAccessibleDescription('La disponibilidad es orientativa. Seleccionar un turno no registra ni garantiza una cita.');
  expect(within(group).getByRole('radio', { name: '08:00:01' })).toHaveAttribute('value', slot.inicio_at);
});
it('422 muestra solo campo de fecha permitido y permite corregir selección', async () => {
  request = c => { if (c.url.endsWith('/disponibilidad')) throw error(c, 422, 'VALIDACION', { fecha: ['Selecciona una fecha no pasada.'], password: ['NO-MOSTRAR'] }); return response(c); };
  const user = userEvent.setup(); mount(); await search(user); await screen.findByText(/Fecha de atención: Selecciona una fecha no pasada/);
  expect(screen.queryByText('NO-MOSTRAR')).not.toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Fecha de atención'), { target: { value: '2026-11-02' } });
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});
it('sesión anónima no consulta catálogos', async () => {
  consultarSesion.mockRejectedValue({ response: { status: 401 } }); mount();
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(calls).toHaveLength(0);
});
it('sesión restringida no accede a reserva', async () => {
  consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true }); const router = mount();
  await waitFor(() => expect(router.state.location.pathname).toBe('/clave-obligatoria')); expect(calls).toHaveLength(0);
});
it('logout descarta una disponibilidad pendiente y no muestra la respuesta en otra sesión', async () => {
  let resolve; request = c => c.url.endsWith('/disponibilidad') ? new Promise(done => { resolve = () => done(response(c)); }) : response(c);
  const user = userEvent.setup(); mount(); await search(user); await waitFor(() => expect(resolve).toBeTypeOf('function'));
  await user.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
  // LogoutButton exige una confirmación explícita.
  const dialog = screen.queryByRole('dialog');
  if (dialog) await user.click(within(dialog).getByRole('button', { name: /Cerrar sesión/ }));
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  await act(async () => resolve()); expect(screen.queryByRole('radio')).not.toBeInTheDocument();
});
it('cambio de rol al recuperar foco elimina el flujo privado', async () => {
  const user = userEvent.setup(); mount(); await search(user); await user.click(await screen.findByRole('radio'));
  role = 'Administrador'; act(() => window.dispatchEvent(new Event('focus')));
  await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(screen.queryByRole('radio')).not.toBeInTheDocument();
});
it.each(['rol', 'identidad'])('cambio administrativo de %s no hereda ficha ni disponibilidad de la sesión anterior', async cambio => {
  role = 'Recepcionista'; const user = userEvent.setup(); mount('/recepcion/citas/nueva');
  await search(user); await user.click(await screen.findByRole('radio'));
  if (cambio === 'rol') role = 'Administrador'; else userId = '8';
  act(() => window.dispatchEvent(new Event('focus')));
  await waitFor(() => expect(screen.queryByText(/Paciente seleccionado:/)).not.toBeInTheDocument());
  await screen.findByRole('button', { name: /Paciente Sin documento/ });
  expect(screen.queryByRole('radio')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Nombre de especialidad')).not.toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'Programar cita' })).toBeInTheDocument();
  expect(calls.filter(c => c.url.endsWith('/disponibilidad'))).toHaveLength(1);
});
it.each(['Administrador', 'Recepcionista'])('%s muestra advertencia de ficha inactiva y no permite turno si servidor la rechaza', async operador => {
  role = operador; patients = patients.map(p => ({ ...p, activo: false })); reservable = false;
  const user = userEvent.setup(); mount('/recepcion/citas/nueva'); await search(user);
  expect(screen.getByText(/La ficha seleccionada está inactiva/)).toBeInTheDocument();
  expect(await screen.findByRole('radio')).toBeDisabled();
  expect(screen.queryByText(/La reserva es únicamente para tu propia ficha/)).not.toBeInTheDocument();
  expect(calls.find(c => c.url.endsWith('/disponibilidad')).params.id_paciente).toBe(id);
});
