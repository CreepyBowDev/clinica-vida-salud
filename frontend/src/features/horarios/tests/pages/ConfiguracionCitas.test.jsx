import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { crearRutas } from '../../../../app/router';
import { api } from '../../../../api/api';
vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter, version = '2026-10-22T12:00:00.123456Z', next = '2026-10-22T12:00:00.123457Z', root = '/administracion/horarios/configuracion';
let calls, current, impacto, write, read, previewTask;
const error = (config, status, code, details) => new AxiosError('SQL secreto', undefined, config, null, status ? { status, headers: {}, data: { error: { code, message: 'SQL secreto', details } } } : undefined);
const writes = () => calls.filter(c => c.method === 'patch');
const previews = () => calls.filter(c => c.url.endsWith('/impacto'));
function mount(path = root) { const router = createMemoryRouter(crearRutas(), { initialEntries: [path] }); render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router; }
beforeEach(() => {
  vi.resetAllMocks(); calls = []; write = null; read = null; previewTask = null;
  current = { duracion_turno_minutos: 15, zona_horaria: 'America/La_Paz', actualizado_en: version };
  impacto = { puede_confirmar: true, totales: { conflictos: 0 }, elementos: [] };
  consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Administración ficticia', correo_acceso: 'admin@example.test', rol: 'Administrador' });
  api.defaults.adapter = async config => {
    calls.push(config); const ok = data => ({ config, status: 200, headers: {}, data });
    if (config.url.includes('csrf-cookie')) return ok('');
    if (config.url === '/medicos') return ok({ data: [], meta: { total: 0, current_page: 1, last_page: 1 } });
    if (config.url.endsWith('/impacto')) return previewTask ? previewTask(config) : ok({ data: impacto, links: { next: 'https://ajeno.invalid/no-usar' }, meta: { total: impacto.totales.conflictos, current_page: JSON.parse(config.data).page, last_page: impacto.totales.conflictos ? 3 : 1 } });
    if (config.method === 'patch') {
      if (write) return write(config);
      current = { ...current, duracion_turno_minutos: JSON.parse(config.data).duracion_turno_minutos, actualizado_en: next };
    } else if (read) return read(config);
    return ok({ data: current });
  };
});
afterEach(() => { api.defaults.adapter = original; });
async function change(user, value = '20') {
  const input = await screen.findByLabelText('Duración propuesta en minutos'); await user.clear(input); await user.type(input, value); return input;
}
async function preview(user) { await user.click(screen.getByRole('button', { name: 'Consultar impacto de duración' })); await screen.findByRole('heading', { name: 'Impacto de duración global' }); }
async function confirm(user) { await user.click(screen.getByRole('button', { name: 'Revisar cambio global' })); await user.click(screen.getByRole('button', { name: 'Guardar duración global' })); }

it.each(['Recepcionista', 'Médico', 'Paciente'])('impide lectura y edición a %s sin HTTP de configuración', async rol => {
  consultarSesion.mockResolvedValue({ id_usuario: '2', nombre_visible: 'Persona', correo_acceso: 'persona@example.test', rol }); mount();
  await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
});
it('acceso desde horarios muestra duración global real y zona sin pedir Médico', async () => {
  const user = userEvent.setup(); mount('/administracion/horarios'); await user.click(await screen.findByRole('link', { name: 'Configurar duración global' }));
  await screen.findByText('15 minutos'); expect(screen.getByText('America/La_Paz')).toBeInTheDocument(); expect(screen.queryByLabelText('Médico')).not.toBeInTheDocument();
  expect(screen.getByText(/común a todos los Médicos y especialidades/)).toBeInTheDocument();
});
it('impacto compatible exige confirmación, guarda versión global y reconsulta duración vigente', async () => {
  const user = userEvent.setup(); mount(); await change(user); await preview(user); expect(writes()).toHaveLength(0);
  await user.click(screen.getByRole('button', { name: 'Revisar cambio global' })); await user.click(screen.getByRole('button', { name: 'Volver a revisar' })); expect(writes()).toHaveLength(0);
  await confirm(user); await screen.findByText(/Duración global guardada/); await screen.findByText('20 minutos');
  expect(JSON.parse(writes()[0].data)).toEqual({ duracion_turno_minutos: 20, actualizado_en_original: version });
  expect(calls.filter(c => c.method === 'get' && c.url === '/configuracion-citas')).toHaveLength(2);
  await change(user, '25'); await preview(user); expect(JSON.parse(previews().at(-1).data).actualizado_en_original).toBe(next);
  expect(screen.getByText(/Las citas ya registradas conservarán su duración almacenada/)).toBeInTheDocument();
});
it('no-op no genera POST ni PATCH', async () => {
  const user = userEvent.setup(); mount(); await screen.findByLabelText('Duración propuesta en minutos'); await user.click(screen.getByRole('button', { name: 'Consultar impacto de duración' }));
  await screen.findByText('No hay cambios para guardar.'); expect(previews()).toHaveLength(0); expect(writes()).toHaveLength(0);
});
it.each(['0', '-1', '1.5', '2147483648', '1e2', 'abc'])('rechaza duración inválida %s sin consultar ni escribir', async value => {
  const user = userEvent.setup(); mount(); const input = await change(user, value); await user.click(screen.getByRole('button', { name: 'Consultar impacto de duración' }));
  expect(input).toHaveAttribute('aria-invalid', 'true'); expect(previews()).toHaveLength(0); expect(writes()).toHaveLength(0);
});
it.each(['1', '2147483647'])('acepta límite INTEGER %s sin máximo funcional añadido', async value => {
  const user = userEvent.setup(); mount(); await change(user, value); await preview(user);
  expect(JSON.parse(previews()[0].data).duracion_turno_minutos).toBe(Number(value));
});
it('impacto incompatible muestra referencias/intervalos completos y no permite confirmar', async () => {
  impacto = { puede_confirmar: false, totales: { conflictos: 42 }, elementos: [{ codigo: 'DURACION_INCOMPATIBLE', id_horario: '9223372036854775807', id_medico: '9007199254740993', dia_semana: 7, hora_inicio: '08:00:01', hora_fin: '08:15:01', paciente: 'Privado', diagnostico: 'Secreto' }] };
  const user = userEvent.setup(); mount(); await change(user, '30'); await preview(user);
  expect(screen.getByText('42 conflictos en la propuesta completa.')).toBeInTheDocument(); expect(screen.getByText('Domingo')).toBeInTheDocument(); expect(screen.getByText('08:15:01')).toBeInTheDocument();
  expect(screen.queryByText('Privado')).not.toBeInTheDocument(); expect(screen.queryByText('Secreto')).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Revisar cambio global' })).not.toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Impacto siguiente' })); await screen.findByText(/página 2 de 3/);
  expect(JSON.parse(previews()[1].data)).toEqual({ duracion_turno_minutos: 30, actualizado_en_original: version, page: 2, per_page: 20 });
  expect(calls.some(c => c.url.includes('ajeno.invalid'))).toBe(false); expect(writes()).toHaveLength(0);
});
it('cambiar duración descarta diagnóstico y confirmación', async () => {
  const user = userEvent.setup(); mount(); await change(user); await preview(user); await user.click(screen.getByRole('button', { name: 'Revisar cambio global' }));
  fireEvent.change(screen.getByLabelText('Duración propuesta en minutos'), { target: { value: '25' } });
  expect(screen.queryByRole('heading', { name: 'Impacto de duración global' })).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Guardar duración global' })).not.toBeInTheDocument();
});
it('respuesta de impacto tardía se descarta después de editar', async () => {
  let resolve; previewTask = config => new Promise(done => { resolve = () => done({ config, status: 200, headers: {}, data: { data: impacto, meta: { total: 0, current_page: 1, last_page: 1 } } }); });
  const user = userEvent.setup(); mount(); await change(user); await user.click(screen.getByRole('button', { name: 'Consultar impacto de duración' })); await waitFor(() => expect(resolve).toBeTypeOf('function'));
  fireEvent.change(screen.getByLabelText('Duración propuesta en minutos'), { target: { value: '25' } }); await act(async () => resolve());
  expect(screen.queryByRole('heading', { name: 'Impacto de duración global' })).not.toBeInTheDocument();
});
it('versión obsoleta conserva propuesta bloqueada hasta descarte y consulta explícitos', async () => {
  write = config => { current = { ...current, duracion_turno_minutos: 25, actualizado_en: next }; throw error(config, 409, 'CONFIGURACION_EDICION_OBSOLETA'); };
  const user = userEvent.setup(); mount(); const input = await change(user); await preview(user); await confirm(user); await screen.findByText(/La configuración cambió/);
  expect(input).toHaveValue('20'); expect(input).toBeDisabled(); expect(writes()).toHaveLength(1);
  await user.click(screen.getByRole('button', { name: 'Descartar propuesta y consultar configuración actual' })); await screen.findByText('25 minutos');
  expect(screen.getByLabelText('Duración propuesta en minutos')).toHaveValue('25');
});
it('409 por incompatibilidad tras vista previa conserva borrador y exige otro impacto', async () => {
  write = config => { throw error(config, 409, 'DURACION_INCOMPATIBLE'); }; const user = userEvent.setup(); mount(); await change(user); await preview(user); await confirm(user);
  await screen.findByText(/no cabe en todos los horarios activos, incluidos los de Médicos inactivos/);
  expect(screen.getByLabelText('Duración propuesta en minutos')).toBeEnabled(); expect(screen.getByLabelText('Duración propuesta en minutos')).toHaveValue('20');
  expect(screen.queryByRole('button', { name: 'Guardar duración global' })).not.toBeInTheDocument(); expect(writes()).toHaveLength(1);
});
it.each([undefined, 500])('resultado incierto %s no se reenvía y exige reconsulta', async status => {
  write = config => { current = { ...current, duracion_turno_minutos: 20, actualizado_en: next }; throw error(config, status); };
  const user = userEvent.setup(); mount(); await change(user); await preview(user); await confirm(user); await screen.findByText(/Puede haberse guardado/);
  expect(screen.getByLabelText('Duración propuesta en minutos')).toBeDisabled(); expect(writes()).toHaveLength(1); expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Descartar propuesta y consultar configuración actual' })); await screen.findByText('20 minutos'); expect(writes()).toHaveLength(1);
});
it.each([419, 422, 429, 403, 404])('escritura HTTP %s mantiene mensajes seguros sin reintento', async status => {
  write = config => { const e = error(config, status, 'VALIDACION', { duracion_turno_minutos: ['Revisa la duración.'], id_medico: ['No mostrar'] }); if (status === 429) e.response.headers['retry-after'] = '60'; throw e; };
  const user = userEvent.setup(); mount(); await change(user); await preview(user); await confirm(user); await screen.findByRole('alert');
  expect(writes()).toHaveLength(1); expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument(); expect(screen.queryByText('No mostrar')).not.toBeInTheDocument();
  if (status === 429) expect(screen.getByRole('button', { name: 'Consultar impacto de duración' })).toBeDisabled();
  if (status === 422) expect(screen.getByLabelText('Duración propuesta en minutos')).toHaveAttribute('aria-invalid', 'true');
});
it('401 limpia propuesta y redirige al login', async () => {
  write = config => { throw error(config, 401); }; const user = userEvent.setup(); mount(); await change(user); await preview(user); await confirm(user);
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(screen.queryByLabelText('Duración propuesta en minutos')).not.toBeInTheDocument();
});
it('doble submit no repite PATCH', async () => {
  let resolve; write = config => new Promise(done => { resolve = () => done({ config, status: 200, headers: {}, data: { data: current } }); });
  const user = userEvent.setup(); mount(); await change(user); await preview(user); await user.click(screen.getByRole('button', { name: 'Revisar cambio global' }));
  const form = screen.getByRole('form', { name: 'Configurar duración global' }); fireEvent.submit(form); fireEvent.submit(form); await waitFor(() => expect(writes()).toHaveLength(1));
  await act(async () => resolve()); await screen.findByText(/Duración global guardada/);
});
it('salir de la ruta descarta impacto pendiente y no lo publica al volver', async () => {
  let resolve; previewTask = config => new Promise(done => { resolve = () => done({ config, status: 200, headers: {}, data: { data: impacto, meta: { total: 0, current_page: 1, last_page: 1 } } }); });
  const user = userEvent.setup(); const router = mount(); await change(user); await user.click(screen.getByRole('button', { name: 'Consultar impacto de duración' })); await waitFor(() => expect(resolve).toBeTypeOf('function'));
  await act(() => router.navigate('/administracion/horarios')); await act(async () => resolve()); await act(() => router.navigate(root)); await screen.findByLabelText('Duración propuesta en minutos');
  expect(screen.queryByRole('heading', { name: 'Impacto de duración global' })).not.toBeInTheDocument(); expect(screen.getByLabelText('Duración propuesta en minutos')).toHaveValue('15');
});
it.each([404, 500])('fallo inicial HTTP %s permite consulta manual sin inventar configuración', async status => {
  read = config => { throw error(config, status); }; mount(); await screen.findByRole('alert'); expect(screen.queryByLabelText('Duración propuesta en minutos')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Volver a consultar configuración' })).toBeInTheDocument(); expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument();
});
