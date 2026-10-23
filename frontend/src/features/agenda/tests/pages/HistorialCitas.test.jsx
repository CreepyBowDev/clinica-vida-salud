import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { cerrarSesion, consultarSesion } from '../../../auth/services/auth.service';
import { cita, detalle, pagina, sesion } from '../utils/agendaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
const patient = { ...cita.paciente, documento_identidad: null, activo: false, cuenta_vinculada: false, fecha_nacimiento: '1990-01-01',
  sexo: null, telefono: '70000000', correo_contacto: null, direccion: null, contacto_emergencia: null, recordatorios_habilitados: null, actualizado_en: '2026-10-22T12:00:00.000000Z' };
const rows = [
  ['1', 'Programada', '2026-11-01T11:55:00.000000Z', '2026-11-01T12:10:00.000000Z'],
  ['2', 'Confirmada', '2026-11-02T12:00:00.000000Z', '2026-11-02T12:15:00.000000Z'],
  ['3', 'Programada', '2000-01-01T12:00:00.000000Z', '2000-01-01T12:15:00.000000Z'],
  ['4', 'Cancelada', '2099-01-01T12:00:00.000000Z', '2099-01-01T12:15:00.000000Z'],
  ['5', 'Cancelada', '2026-10-28T12:00:00.000000Z', '2026-10-28T12:15:00.000000Z'],
  ['6', 'Atendida', '2026-10-29T12:00:00.000000Z', '2026-10-29T12:15:00.000000Z'],
  ['7', 'Inasistencia', '2026-10-30T12:00:00.000000Z', '2026-10-30T12:15:00.000000Z'],
  ['8', 'Programada', '2026-11-01T11:45:00.000000Z', '2026-11-01T12:00:00.000000Z'],
].map(([id_cita, estado, inicio_at, fin_at]) => ({ ...structuredClone(cita), id_cita, codigo_cita: `CIT-${id_cita}`, estado, inicio_at, fin_at, creada_en: '1999-01-01T12:00:00.000000Z', motivo_breve: 'NO-LISTAR', paciente: { ...patient } }));
// Respuestas del servidor ficticio predefinidas: la UI no implementa estas clasificaciones.
const orden = { proximas: ['1', '2'], anteriores: ['8', '7', '6', '5', '3'], todas: ['4', '2', '1', '8', '7', '6', '5', '3'] };
let role, calls, respond, override;
const ok = (config, data) => ({ config, status: 200, headers: {}, data });
const fail = (config, status, fields = {}) => new AxiosError('SQL-PRIVADO', undefined, config, null,
  status ? { status, headers: { 'retry-after': '1' }, data: { error: { message: 'SQL-PRIVADO', details: fields } } } : undefined);
function server(config) {
  if (config.url === '/pacientes') return ok(config, pagina([patient]));
  if (/^\/pacientes\/\d+$/.test(config.url)) return ok(config, { data: patient });
  if (config.url.startsWith('/citas/')) return ok(config, { data: { ...detalle(role), id_cita: config.url.split('/').at(-1) } });
  const p = config.params, periodo = p.periodo ?? (role === 'Paciente' ? 'proximas' : 'todas');
  const id = config.url === '/mis-citas' ? '31' : config.url.split('/')[2];
  const context = { ...cita.paciente, id_paciente: id, nombres: id === '31' ? 'Paciente' : 'Otra ficha' };
  let data = override ?? orden[periodo].map(key => rows.find(row => row.id_cita === key));
  if (p.estado) data = data.filter(row => row.estado === p.estado);
  return ok(config, pagina(data.slice((p.page - 1) * p.per_page, p.page * p.per_page).map(row => ({ ...row, paciente: context })), {
    total: data.length, current_page: p.page, per_page: p.per_page, last_page: Math.max(1, Math.ceil(data.length / p.per_page)), paciente: context,
    filtros: { periodo, estado: p.estado ?? null },
  }));
}
function mount(path = '/mis-citas') {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
const historyCalls = () => calls.filter(config => config.url === '/mis-citas' || /\/pacientes\/\d+\/citas$/.test(config.url));
async function apply(periodo, estado = '') {
  const user = userEvent.setup(); await user.selectOptions(screen.getByLabelText('Período de citas'), periodo);
  await user.selectOptions(screen.getByLabelText('Estado de cita'), estado);
  await user.click(screen.getByRole('button', { name: 'Consultar historial', exact: true }));
  await waitFor(() => expect(screen.queryByText('Consultando historial de citas…')).not.toBeInTheDocument());
}
beforeEach(() => {
  vi.resetAllMocks(); role = 'Paciente'; calls = []; override = undefined;
  consultarSesion.mockImplementation(async () => sesion(role)); respond = server;
  api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = original; });

it('Mis citas inicia Próximas por contrato, sin consultar perfil/actividad ni enviar ficha', async () => {
  mount(); const table = await screen.findByRole('table');
  expect(calls).toHaveLength(1); expect(calls[0]).toMatchObject({ url: '/mis-citas', params: { page: 1, per_page: 20 }, method: 'get', sessionBound: true });
  expect(screen.getByLabelText('Período de citas')).toHaveValue('proximas');
  expect(within(table).getAllByRole('link').map(link => link.textContent)).toEqual(['CIT-1', 'CIT-2']);
  expect(table).toHaveTextContent('Profesional Ficticio'); expect(table).toHaveTextContent('Registro:');
  expect(table).not.toHaveTextContent('NO-LISTAR'); expect(table).not.toHaveTextContent('70000000');
  expect(screen.getByRole('link', { name: 'Mis citas' })).toHaveAttribute('aria-current', 'page');
});
it('Recepción inicia Todas descendente, con contexto mínimo aunque la ficha esté inactiva/sin CI/cuenta', async () => {
  role = 'Recepcionista'; mount('/recepcion/pacientes/31/citas'); const table = await screen.findByRole('table');
  expect(calls).toHaveLength(1); expect(calls[0]).toMatchObject({ url: '/pacientes/31/citas', params: { page: 1, per_page: 20 } });
  expect(screen.getByLabelText('Período de citas')).toHaveValue('todas');
  expect(within(table).getAllByRole('link').map(link => link.textContent)).toEqual(orden.todas.map(id => `CIT-${id}`));
  expect(screen.getByRole('region', { name: 'Paciente del historial' })).toHaveTextContent('PAC-FICTICIO');
  expect(screen.getByRole('link', { name: 'Volver a la ficha del paciente' })).toHaveAttribute('href', '/recepcion/pacientes/31');
});
it('entrada de sidebar reutiliza búsqueda → ficha → historial sin crear historial global', async () => {
  role = 'Recepcionista'; mount('/recepcion/historial-citas'); const user = userEvent.setup();
  await screen.findByRole('heading', { name: 'Seleccionar paciente para consultar historial' });
  expect(screen.queryByRole('link', { name: 'Registrar paciente' })).not.toBeInTheDocument();
  await user.click(await screen.findByRole('link', { name: 'Ver ficha de Paciente De prueba' }));
  await screen.findByRole('heading', { name: 'Ficha administrativa' });
  const link = within(screen.getByRole('main')).getByRole('link', { name: 'Consultar historial de citas' });
  expect(link).toHaveAttribute('href', '/recepcion/pacientes/31/citas'); await user.click(link);
  await screen.findByRole('heading', { name: 'Historial de citas' }); await screen.findByRole('table');
  expect(calls.map(call => call.url)).toEqual(['/pacientes', '/pacientes/31', '/pacientes/31/citas']);
  expect(calls.every(call => call.method === 'get')).toBe(true);
});
it.each(['proximas', 'anteriores', 'todas'])('período %s usa orden/filas devueltos sin alterar estados', async periodo => {
  mount(); await screen.findByRole('table'); await apply(periodo);
  expect(within(screen.getByRole('table')).getAllByRole('link').map(link => link.textContent)).toEqual(orden[periodo].map(id => `CIT-${id}`));
  if (periodo === 'anteriores') {
    const pasada = screen.getByRole('link', { name: 'Ver cita CIT-3' }).closest('tr'); expect(pasada).toHaveTextContent('Programada');
    expect(screen.getByRole('link', { name: 'Ver cita CIT-8' })).toBeInTheDocument();
  }
});
it('Cancelada futura solo aparece en Todas y Próximas/Cancelada queda vacío sin cambiar período', async () => {
  mount(); await screen.findByRole('table'); await apply('todas', 'Cancelada');
  expect(within(screen.getByRole('table')).getAllByRole('link').map(link => link.textContent)).toEqual(['CIT-4', 'CIT-5']);
  await apply('proximas', 'Cancelada'); await screen.findByRole('heading', { name: 'No hay citas para este período y estado' });
  expect(screen.getByLabelText('Período de citas')).toHaveValue('proximas'); expect(screen.getByLabelText('Estado de cita')).toHaveValue('Cancelada');
  expect(screen.getByRole('region', { name: 'Paciente del historial' })).toHaveTextContent('PAC-FICTICIO');
});
it.each(['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'])('estado %s se combina con el período y reinicia página', async estado => {
  mount(); await screen.findByRole('table'); await apply('todas', estado);
  expect(historyCalls().at(-1).params).toEqual({ page: 1, per_page: 20, periodo: 'todas', estado });
  expect(within(screen.getByRole('table')).getAllByRole('row').slice(1).every(row => row.textContent.includes(estado))).toBe(true);
});
it.each(['Paciente', 'Recepcionista'])('%s pagina y vuelve del detalle conservando criterios, sin guardar citas', async rol => {
  role = rol; override = Array.from({ length: 21 }, (_, i) => ({ ...rows[0], id_cita: String(i + 1), codigo_cita: `PAG-${i + 1}` }));
  mount(role === 'Paciente' ? '/mis-citas' : '/recepcion/pacientes/31/citas'); const user = userEvent.setup();
  await screen.findByRole('table'); await user.click(within(screen.getByRole('navigation', { name: 'Paginación de citas' })).getByRole('button', { name: 'Siguiente' }));
  const link = await screen.findByRole('link', { name: 'Ver cita PAG-21' });
  expect(link).toHaveAttribute('href', role === 'Paciente' ? '/mis-citas/21' : '/recepcion/historial-citas/21');
  await user.click(link); await screen.findByRole('region', { name: `Cita ${cita.codigo_cita}` });
  expect(screen.getByRole('main')).toHaveFocus();
  const back = screen.getByRole('link', { name: role === 'Paciente' ? 'Volver a Mis citas' : 'Volver al historial del paciente' });
  await user.click(back); await screen.findByRole('link', { name: 'Ver cita PAG-21' });
  expect(historyCalls()).toHaveLength(3); expect(historyCalls().at(-1).params.page).toBe(2);
  expect(historyCalls().at(-1).params.periodo).toBe(role === 'Paciente' ? 'proximas' : 'todas');
  expect(screen.getByRole('main')).not.toHaveTextContent('MOTIVO-FICTICIO'); expect(localStorage.length + sessionStorage.length).toBe(0);
});
it.each(['Paciente', 'Recepcionista'])('%s aplica filtros/cantidad solo al enviar y restablece su período inicial', async rol => {
  role = rol; mount(role === 'Paciente' ? '/mis-citas' : '/recepcion/pacientes/31/citas'); await screen.findByRole('table'); const user = userEvent.setup();
  await user.selectOptions(screen.getByLabelText('Período de citas'), 'anteriores'); await user.selectOptions(screen.getByLabelText('Citas por página'), '10');
  expect(historyCalls()).toHaveLength(1); await user.click(screen.getByRole('button', { name: 'Consultar historial', exact: true }));
  await waitFor(() => expect(historyCalls().at(-1).params).toMatchObject({ periodo: 'anteriores', per_page: 10, page: 1 }));
  await user.click(screen.getByRole('button', { name: 'Restablecer filtros' }));
  await waitFor(() => expect(historyCalls().at(-1).params).toEqual({ page: 1, per_page: 20 }));
  expect(screen.getByLabelText('Período de citas')).toHaveValue(rol === 'Paciente' ? 'proximas' : 'todas');
});
it.each([
  ['Administrador', '/mis-citas'], ['Médico', '/mis-citas'], ['Recepcionista', '/mis-citas'],
  ['Administrador', '/recepcion/pacientes/31/citas'], ['Médico', '/recepcion/pacientes/31/citas'], ['Paciente', '/recepcion/pacientes/31/citas'],
  ['Administrador', '/recepcion/historial-citas'], ['Paciente', '/recepcion/historial-citas/1'], ['Recepcionista', '/mis-citas/1'],
])('%s no consulta ruta ajena %s', async (rol, path) => {
  role = rol; mount(path); await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
});
it.each(['0', '01', '9223372036854775808', 'texto'])('ficha %s inválida no genera HTTP', async id => {
  role = 'Recepcionista'; mount(`/recepcion/pacientes/${id}/citas`); await screen.findByRole('heading', { name: 'Historial no disponible' }); expect(calls).toHaveLength(0);
});
it('cambiar de ficha descarta solicitud/filas anteriores y reinicia sus criterios', async () => {
  role = 'Recepcionista'; let complete, signal;
  respond = config => config.url === '/pacientes/31/citas' ? new Promise(resolve => { signal = config.signal; complete = () => resolve(server(config)); }) : server(config);
  const router = mount('/recepcion/pacientes/31/citas'); await waitFor(() => expect(complete).toBeTypeOf('function'));
  await act(async () => router.navigate('/recepcion/pacientes/99/citas'));
  await screen.findByRole('region', { name: 'Paciente del historial' }); expect(signal.aborted).toBe(true);
  await act(async () => complete()); expect(screen.getByRole('region', { name: 'Paciente del historial' })).toHaveTextContent('Otra ficha');
  expect(historyCalls().at(-1)).toMatchObject({ url: '/pacientes/99/citas', params: { page: 1, per_page: 20 } });
});
it.each([403, 404, 419, 422, 500, undefined])('fallo %s retira datos y no expone errores técnicos', async status => {
  respond = config => Promise.reject(fail(config, status, { periodo: ['Selecciona un período válido.'], password: ['NO-MOSTRAR'] })); mount();
  const alert = await screen.findByRole('alert'); expect(alert).not.toHaveTextContent('SQL-PRIVADO'); expect(screen.getByRole('main')).not.toHaveTextContent('NO-MOSTRAR');
  expect(screen.queryByRole('table')).not.toBeInTheDocument(); expect(calls).toHaveLength(1);
  if (status === 422) expect(screen.getByLabelText('Período de citas')).toHaveFocus();
});
it('401 invalida sesión; huésped no llega a solicitar historial', async () => {
  respond = config => Promise.reject(fail(config, 401)); mount(); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(screen.queryByRole('table')).not.toBeInTheDocument();
});
it.each(['guest', 'restricted'])('%s no consulta historial', async type => {
  if (type === 'guest') consultarSesion.mockRejectedValue({ response: { status: 401 } });
  else consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
  mount(); await screen.findByRole('heading', { name: type === 'guest' ? 'Bienvenido de nuevo' : 'Cambia tu contraseña' }); expect(calls).toHaveLength(0);
});
it('429 respeta espera sin reintento automático ni cambio implícito de filtros', async () => {
  respond = config => Promise.reject(fail(config, 429)); mount(); await screen.findByRole('alert');
  expect(screen.getByRole('button', { name: 'Consultar historial', exact: true })).toBeDisabled();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeEnabled(), { timeout: 3000 });
  expect(calls).toHaveLength(1); respond = server; await userEvent.click(screen.getByRole('button', { name: 'Volver a consultar' })); await screen.findByRole('table'); expect(calls).toHaveLength(2);
});
it('logout cancela lectura pendiente y no restaura datos al llegar tarde', async () => {
  let complete, signal; respond = config => new Promise(resolve => { signal = config.signal; complete = () => resolve(server(config)); });
  mount(); await waitFor(() => expect(complete).toBeTypeOf('function')); cerrarSesion.mockResolvedValue(undefined);
  await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' })); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(signal.aborted).toBe(true); await act(async () => complete()); expect(screen.queryByRole('table')).not.toBeInTheDocument();
});
it('pagehide limpia filtros/filas y pageshow reconsulta el período predeterminado', async () => {
  mount(); await screen.findByRole('table'); await apply('todas', 'Cancelada');
  await act(async () => window.dispatchEvent(new Event('pagehide'))); expect(screen.queryByRole('table')).not.toBeInTheDocument();
  await act(async () => window.dispatchEvent(new Event('pageshow'))); await screen.findByRole('table');
  expect(historyCalls().at(-1).params).toEqual({ page: 1, per_page: 20 }); expect(screen.getByLabelText('Período de citas')).toHaveValue('proximas');
});
it('otra identidad empieza con filtros limpios y sin datos del usuario anterior', async () => {
  mount(); await screen.findByRole('table'); await apply('todas', 'Cancelada');
  consultarSesion.mockResolvedValue(sesion('Paciente', '2')); await act(async () => window.dispatchEvent(new Event('focus')));
  await waitFor(() => expect(historyCalls().at(-1).params).toEqual({ page: 1, per_page: 20 }));
  expect(screen.getByLabelText('Estado de cita')).toHaveValue('');
});
it('respuesta vinculada a otra ficha o malformada no se presenta como historial válido', async () => {
  role = 'Recepcionista'; respond = config => { const result = server(config); result.data.meta.paciente.id_paciente = '999'; return result; };
  mount('/recepcion/pacientes/31/citas'); await screen.findByRole('alert'); expect(screen.queryByRole('table')).not.toBeInTheDocument();
});

it.each(['Paciente', 'Recepcionista'])('%s atrás/adelante reconsulta listado y detalle sin restaurar objetos privados de history', async rol => {
  role = rol; const root = role === 'Paciente' ? '/mis-citas' : '/recepcion/pacientes/31/citas';
  const router = mount(root); await screen.findByRole('table'); await apply('todas', 'Cancelada');
  await userEvent.click(screen.getByRole('link', { name: 'Ver cita CIT-4' }));
  await screen.findByRole('region', { name: 'Motivo breve' });
  expect(router.state.location.state).toBeNull(); expect(router.state.location.search).toBe('');
  await act(async () => router.navigate(-1)); await screen.findByRole('table');
  expect(screen.getByLabelText('Estado de cita')).toHaveValue('Cancelada');
  expect(screen.getByRole('main')).not.toHaveTextContent('MOTIVO-FICTICIO');
  await act(async () => router.navigate(1)); await screen.findByRole('region', { name: 'Motivo breve' });
  expect(calls.filter(call => call.url === '/citas/4')).toHaveLength(2);
  expect(historyCalls()).toHaveLength(3); expect(calls.every(call => call.method === 'get')).toBe(true);
});
it.each(['/mis-citas/1', '/recepcion/historial-citas/1'])('detalle directo %s ofrece retorno real sin exigir navegación previa', async path => {
  role = path.startsWith('/recepcion') ? 'Recepcionista' : 'Paciente'; mount(path);
  await screen.findByRole('region', { name: 'Paciente' });
  const back = screen.getByRole('link', { name: role === 'Paciente' ? 'Volver a Mis citas' : 'Volver al historial del paciente' });
  expect(back).toHaveAttribute('href', role === 'Paciente' ? '/mis-citas' : '/recepcion/pacientes/31/citas');
  await userEvent.click(back); await screen.findByRole('table');
  expect(historyCalls()[0].params).toEqual({ page: 1, per_page: 20 });
});
it('404 de detalle administrativo directo no revela ficha y vuelve a selección segura', async () => {
  role = 'Recepcionista'; respond = config => config.url.startsWith('/citas/') ? Promise.reject(fail(config, 404)) : server(config);
  mount('/recepcion/historial-citas/1'); await screen.findByRole('alert');
  expect(screen.getByRole('link', { name: 'Volver a seleccionar paciente' })).toHaveAttribute('href', '/recepcion/historial-citas');
  expect(screen.getByRole('main')).not.toHaveTextContent('PAC-FICTICIO');
});
it('cambio rápido de período no permite que un 403 tardío sustituya el resultado actual', async () => {
  let rejectOld, signal;
  respond = config => !config.params?.periodo ? new Promise((_, reject) => { signal = config.signal; rejectOld = () => reject(fail(config, 403)); }) : server(config);
  mount(); await waitFor(() => expect(rejectOld).toBeTypeOf('function'));
  await apply('todas', 'Cancelada'); await screen.findByRole('table'); expect(signal.aborted).toBe(true);
  await act(async () => rejectOld());
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Ver cita CIT-4' })).toBeInTheDocument();
});
it('si el total cambia y la página queda vacía conserva contexto y permite volver a la anterior', async () => {
  override = Array.from({ length: 21 }, (_, i) => ({ ...rows[0], id_cita: String(i + 1), codigo_cita: `PAG-${i + 1}` }));
  mount(); await screen.findByRole('table');
  override = [rows[0]];
  await userEvent.click(within(screen.getByRole('navigation', { name: 'Paginación de citas' })).getByRole('button', { name: 'Siguiente' }));
  await screen.findByRole('heading', { name: 'No hay citas para este período y estado' });
  expect(screen.getByRole('region', { name: 'Paciente del historial' })).toHaveTextContent('PAC-FICTICIO');
  const navigation = screen.getByRole('navigation', { name: 'Paginación de citas' });
  expect(within(navigation).getByRole('button', { name: 'Anterior' })).toBeEnabled();
  await userEvent.click(within(navigation).getByRole('button', { name: 'Anterior' })); await screen.findByRole('table');
  expect(historyCalls().at(-1).params.page).toBe(1);
});
it('respuesta rechazada al reconsultar retira la tabla anterior sin afirmar que el historial esté vacío', async () => {
  mount(); await screen.findByRole('table');
  respond = config => Promise.reject(fail(config, 500));
  await userEvent.click(screen.getByRole('button', { name: 'Consultar historial', exact: true }));
  await screen.findByRole('alert'); expect(screen.queryByRole('table')).not.toBeInTheDocument();
  expect(screen.queryByRole('region', { name: 'Paciente del historial' })).not.toBeInTheDocument();
  expect(screen.queryByRole('heading', { name: 'No hay citas para este período y estado' })).not.toBeInTheDocument();
});
it('selector de historial cancela y oculta fichas al abandonar página, restaura con consulta nueva', async () => {
  role = 'Recepcionista'; let complete, signal;
  respond = config => new Promise(resolve => { signal = config.signal; complete = () => resolve(server(config)); });
  mount('/recepcion/historial-citas'); await waitFor(() => expect(complete).toBeTypeOf('function'));
  const old = complete;
  await act(async () => window.dispatchEvent(new Event('pagehide'))); expect(signal.aborted).toBe(true);
  await act(async () => old()); expect(screen.queryByRole('table')).not.toBeInTheDocument();
  respond = server; await act(async () => window.dispatchEvent(new Event('pageshow')));
  await screen.findByRole('table'); expect(calls).toHaveLength(2);
});
